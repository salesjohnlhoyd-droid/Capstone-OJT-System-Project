<?php
/**
 * export_attendance_xlsx.php
 * Drop-in XLSX export for attendance_management.php
 * Linked via: href="export_attendance_xlsx.php?month=YYYY-MM"
 *
 * Filename format: {CompanyName}_{MonAbbrev}{Year}.xlsx
 *   e.g.  AcmeCorp_Jan2025.xlsx
 *
 * All existing logic in attendance_management.php is UNCHANGED.
 * Only the export block is replaced – call this file instead.
 */

session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

$company_id = (int)$_SESSION['user_id'];

// ── Fetch company name ────────────────────────────────────────────────────────
$co_stmt = $conn->prepare("
    SELECT ci.company, u.first_name, u.last_name
    FROM users u
    LEFT JOIN company_information ci ON ci.user_id = u.id
    WHERE u.id = ? LIMIT 1
");
$co_stmt->bind_param("i", $company_id);
$co_stmt->execute();
$co_row = $co_stmt->get_result()->fetch_assoc();
$company_name_raw = !empty($co_row['company'])
    ? $co_row['company']
    : trim(($co_row['first_name'] ?? '') . ' ' . ($co_row['last_name'] ?? ''));
if (!$company_name_raw) $company_name_raw = 'Company';
// Sanitise for filename (remove chars that aren't word chars / spaces / hyphens)
$company_name_safe = preg_replace('/[^\w\s\-]/', '', $company_name_raw);
$company_name_safe = preg_replace('/\s+/', '_', trim($company_name_safe));

// ── Month & date range ────────────────────────────────────────────────────────
$exp_month = $_GET['month'] ?? date('Y-m');

$s2 = $conn->prepare("SELECT MIN(date) as sd FROM attendance_settings WHERE company_id=?");
$s2->bind_param("i", $company_id);
$s2->execute();
$sd_row = $s2->get_result()->fetch_assoc();
$sd = $sd_row['sd'] ?? date('Y-m-d');

if (date('Y-m', strtotime($sd)) === $exp_month) {
    $exp_start = $sd;
} else {
    $exp_start = $exp_month . '-01';
}
$exp_end = date('Y-m-t', strtotime($exp_month . '-01'));

// ── Filename: CompanyName_MonYear.xlsx ────────────────────────────────────────
$month_abbrev = date('M', strtotime($exp_month . '-01'));   // e.g. "Jan"
$year_4       = date('Y', strtotime($exp_month . '-01'));   // e.g. "2025"
$filename     = $company_name_safe . '_' . $month_abbrev . $year_4 . '.xlsx';

// ── Students ──────────────────────────────────────────────────────────────────
$stud_res = $conn->query("
    SELECT u.id, u.first_name, u.last_name
    FROM ojt_assignments oa
    JOIN users u ON oa.student_id = u.id
    WHERE oa.company_id = $company_id
    ORDER BY u.first_name ASC
");
$exp_students = [];
while ($sr = $stud_res->fetch_assoc()) {
    $exp_students[$sr['id']] = $sr;
}

// ── Logs ──────────────────────────────────────────────────────────────────────
$log_res = $conn->query("
    SELECT user_id, date, am_time_in, am_time_out, pm_time_in, pm_time_out
    FROM attendance_logs
    WHERE company_id = $company_id
      AND date BETWEEN '$exp_start' AND '$exp_end'
");
$exp_logs = [];
$exp_has_entry = []; // real times only (a "missed"-only row is not an entry)
while ($lr = $log_res->fetch_assoc()) {
    foreach (['am_time_in','am_time_out','pm_time_in','pm_time_out'] as $c_) {
        if ($lr[$c_] !== null && $lr[$c_] !== '' && $lr[$c_] !== 'missed') { $exp_has_entry[$lr['user_id']][$lr['date']] = true; break; }
    }
    $dow   = (int)date('w', strtotime($lr['date']));
    $wknd  = ($dow === 0 || $dow === 6);
    $isMissed = fn($v) => ($v === 'missed');
    $hasVal   = fn($v) => ($v !== null && $v !== '' && $v !== 'missed');
    if ($wknd) {
        $st = 'OFF';
    } elseif ($hasVal($lr['am_time_in']) && $hasVal($lr['am_time_out'])
           && $hasVal($lr['pm_time_in']) && $hasVal($lr['pm_time_out'])) {
        $st = 'PRESENT';
    } elseif ($hasVal($lr['am_time_in']) || $hasVal($lr['pm_time_in'])
           || $isMissed($lr['am_time_in']) || $isMissed($lr['am_time_out'])
           || $isMissed($lr['pm_time_in']) || $isMissed($lr['pm_time_out'])) {
        $st = 'INCOMPLETE';
    } else {
        $st = 'ABSENT';
    }
    $exp_logs[$lr['user_id']][$lr['date']] = $st;
}

/* ════════════════════════════════════════════════════════════════════
   NEW (student schedule): attendance follows each student's own training
   schedule (Day / Evening Schedule set on AccomForm.php and changed by the
   company supervisor on add_ojt_student.php; stored in student_information
   as Mon–Fri acronyms such as "MWF", "TTh" or "None").
   A weekday the student is NOT scheduled on, with no real attendance entry,
   is never counted as Absent / Missed / Incomplete — it is shown as
   "Not scheduled" instead. A day with a real entry is always judged normally.
   Schedule changes are dated (student_schedule_changes), so past days keep the
   schedule that was in force back then. A student whose schedule is empty or
   unreadable is treated as scheduled every weekday (nothing changes for them).
   ════════════════════════════════════════════════════════════════════ */
if (!function_exists('attsch_parse_days')) {
    // "MWF" -> [1,3,5] (date('w') numbers); "None" / empty / unreadable -> []
    function attsch_parse_days($value): array {
        $rest = trim((string)$value);
        if ($rest === '' || strcasecmp($rest, 'None') === 0) return [];
        $codes = ['Th' => 4, 'M' => 1, 'T' => 2, 'W' => 3, 'F' => 5];
        $found = [];
        while ($rest !== '') {
            $hit = false;
            foreach ($codes as $code => $n) {
                if (stripos($rest, $code) === 0) { $found[$n] = true; $rest = substr($rest, strlen($code)); $hit = true; break; }
            }
            if (!$hit) return [];
        }
        $days = array_keys($found);
        sort($days);
        return $days;
    }
}
if (!function_exists('attsch_days')) {
    // Day + Evening schedule combined; null = no usable schedule (scheduled every weekday)
    function attsch_days($day, $evening): ?array {
        $all = array_values(array_unique(array_merge(attsch_parse_days($day), attsch_parse_days($evening))));
        if (empty($all)) return null;
        sort($all);
        return $all;
    }
}
if (!function_exists('attsch_load')) {
    // [student_id => ['cur' => days|null, 'changes' => [['d' => 'Y-m-d', 'old' => days|null], ...oldest first]]]
    function attsch_load($conn, array $ids): array {
        static $cache = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);
        if (empty($ids)) return [];
        $key = implode(',', $ids);
        if (isset($cache[$key])) return $cache[$key];
        $map = [];
        foreach ($ids as $i) $map[$i] = ['cur' => null, 'changes' => []];
        try {
            $r = $conn->query("SELECT user_id, day_sched, evening_sched FROM student_information WHERE user_id IN ($key)");
            if ($r) { while ($row = $r->fetch_assoc()) $map[(int)$row['user_id']]['cur'] = attsch_days($row['day_sched'], $row['evening_sched']); }
        } catch (\Throwable $e) {}
        try {
            $t = $conn->query("SHOW TABLES LIKE 'student_schedule_changes'");
            if ($t && $t->num_rows > 0) {
                $r = $conn->query("SELECT student_id, old_day_sched, old_evening_sched, DATE(created_at) AS d
                                   FROM student_schedule_changes WHERE student_id IN ($key) ORDER BY created_at ASC, id ASC");
                if ($r) {
                    while ($row = $r->fetch_assoc()) {
                        $map[(int)$row['student_id']]['changes'][] = ['d' => $row['d'], 'old' => attsch_days($row['old_day_sched'], $row['old_evening_sched'])];
                    }
                }
            }
        } catch (\Throwable $e) {}
        return $cache[$key] = $map;
    }
}
if (!function_exists('attsch_is_scheduled')) {
    // true when the student is scheduled to report on $day (weekdays only; unknown student = scheduled)
    function attsch_is_scheduled(array $map, $studentId, string $day): bool {
        $s = $map[(int)$studentId] ?? null;
        if ($s === null) return true;
        $days = $s['cur'];
        foreach ($s['changes'] as $c) {            // a change applies from its own date onward
            if ($c['d'] > $day) { $days = $c['old']; break; }
        }
        if ($days === null) return true;
        return in_array((int)date('w', strtotime($day)), $days, true);
    }
}
if (!function_exists('attsch_has_real_entry')) {
    // true when the log row holds at least one real time (a "missed"-only row is not an entry)
    function attsch_has_real_entry($row): bool {
        if (!is_array($row)) return false;
        foreach (['am_time_in', 'am_time_out', 'pm_time_in', 'pm_time_out'] as $c) {
            $v = $row[$c] ?? null;
            if ($v !== null && $v !== '' && $v !== 'missed') return true;
        }
        return false;
    }
}
if (!function_exists('attsch_js_map')) {
    // compact form for the page script: {id: {c: days|null, h: [[date, days|null], ...]}}
    function attsch_js_map(array $map): object {
        $o = [];
        foreach ($map as $id => $s) {
            $h = [];
            foreach ($s['changes'] as $c) $h[] = [$c['d'], $c['old']];
            $o[(string)$id] = ['c' => $s['cur'], 'h' => $h];
        }
        return (object)$o;
    }
}

$exp_sched = attsch_load($conn, array_keys($exp_students)); // NEW (student schedule)

// ── Date columns ──────────────────────────────────────────────────────────────
$exp_dates = [];
for ($d = strtotime($exp_start); $d <= strtotime($exp_end); $d = strtotime('+1 day', $d)) {
    $exp_dates[] = date('Y-m-d', $d);
}

// ─────────────────────────────────────────────────────────────────────────────
// Build the data array (header + rows)
// ─────────────────────────────────────────────────────────────────────────────
$today_str = date('Y-m-d');

$header_row = ['Name'];
foreach ($exp_dates as $d) {
    $dow = (int)date('w', strtotime($d));
    $label = date('D d', strtotime($d));
    if ($dow === 0 || $dow === 6) $label .= ' (Off)';
    $header_row[] = $label;
}

$data_rows = [];
foreach ($exp_students as $sid => $stu) {
    $row = [$stu['first_name'] . ' ' . $stu['last_name']];
    foreach ($exp_dates as $d) {
        $dow = (int)date('w', strtotime($d));
        if ($dow === 0 || $dow === 6) {
            $row[] = 'OFF';
        } elseif ($d > $today_str) {
            $row[] = '';
        } elseif (empty($exp_has_entry[$sid][$d]) && !attsch_is_scheduled($exp_sched, $sid, $d)) {
            $row[] = 'NOT SCHEDULED'; // NEW (student schedule): not a duty day for this student — never absent
        } elseif ($d === $today_str && empty($exp_has_entry[$sid][$d])) {
            $row[] = ''; // today with no attendance entry yet: blank until the day has passed
        } else {
            $row[] = $exp_logs[$sid][$d] ?? 'ABSENT';
        }
    }
    $data_rows[] = $row;
}

// ─────────────────────────────────────────────────────────────────────────────
// XLSX generation (pure PHP, no external library required)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Minimal pure-PHP XLSX writer.
 * Supports: cell values, bold header row, column auto-width, cell fill colours.
 */
function build_xlsx(array $header, array $rows, string $sheet_title,
                    string $company_display, string $month_label): string
{
    // ── Colour map for status values ──────────────────────────────────────────
    $status_fills = [
        'PRESENT'    => 'C6EFCE',   // green tint
        'ABSENT'     => 'FFC7CE',   // red tint
        'INCOMPLETE' => 'FFEB9C',   // amber tint
        'OFF'        => 'E4DFEC',   // purple tint (Day Off)
        'OFF'        => 'E4DFEC',
    ];

    // ── Shared strings ────────────────────────────────────────────────────────
    $sst    = [];    // index => string
    $sstMap = [];    // string => index

    $si = function(string $v) use (&$sst, &$sstMap): int {
        if (!isset($sstMap[$v])) {
            $sstMap[$v] = count($sst);
            $sst[]      = $v;
        }
        return $sstMap[$v];
    };

    // ── Figure out column widths (max char length per column) ─────────────────
    $col_widths = [];
    foreach ($header as $ci => $h) {
        $col_widths[$ci] = mb_strlen((string)$h);
    }
    foreach ($rows as $row) {
        foreach ($row as $ci => $cell) {
            $len = mb_strlen((string)$cell);
            if (!isset($col_widths[$ci]) || $len > $col_widths[$ci]) {
                $col_widths[$ci] = $len;
            }
        }
    }
    // Add padding; cap at 40; Name column wider
    foreach ($col_widths as $ci => &$w) {
        $w = min(40, max(9, $w + 4));
    }
    unset($w);
    $col_widths[0] = min(40, max(20, $col_widths[0] ?? 20)); // Name col

    // ── Build sheet XML ───────────────────────────────────────────────────────
    // Style indices (defined in styles.xml below):
    //   0 = default, 1 = bold header, 2 = present, 3 = absent, 4 = incomplete, 5 = off
    $style_map = [
        'PRESENT'    => 2,
        'ABSENT'     => 3,
        'INCOMPLETE' => 4,
        'OFF'        => 5,
    ];

    $col_letter = function(int $n): string {
        $s = '';
        $n++;   // 0-indexed → 1-indexed
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    };

    $xml_sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_sheet .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    // column widths
    $xml_sheet .= '<cols>';
    foreach ($col_widths as $ci => $w) {
        $c1 = $c2 = $ci + 1;
        $xml_sheet .= '<col min="'.$c1.'" max="'.$c2.'" width="'.$w.'" customWidth="1"/>';
    }
    $xml_sheet .= '</cols>';

    $xml_sheet .= '<sheetData>';

    // Title row (row 1) — merged later via mergeCell; put text in A1
    $title_text = $company_display . ' — Attendance Summary — ' . $month_label;
    $xml_sheet .= '<row r="1"><c r="A1" t="s" s="6"><v>'.$si($title_text).'</v></c></row>';

    // Header row (row 2)
    $xml_sheet .= '<row r="2">';
    foreach ($header as $ci => $h) {
        $col = $col_letter($ci);
        $xml_sheet .= '<c r="'.$col.'2" t="s" s="1"><v>'.$si((string)$h).'</v></c>';
    }
    $xml_sheet .= '</row>';

    // Data rows (start at row 3)
    $excel_row = 3;
    foreach ($rows as $row) {
        $xml_sheet .= '<row r="'.$excel_row.'">';
        foreach ($row as $ci => $cell) {
            $col   = $col_letter($ci);
            $ref   = $col . $excel_row;
            $upper = strtoupper(trim((string)$cell));
            $s_idx = isset($style_map[$upper]) ? $style_map[$upper] : 0;
            if ($cell === '') {
                $xml_sheet .= '<c r="'.$ref.'" s="'.$s_idx.'"/>';
            } else {
                $xml_sheet .= '<c r="'.$ref.'" t="s" s="'.$s_idx.'"><v>'.$si((string)$cell).'</v></c>';
            }
        }
        $xml_sheet .= '</row>';
        $excel_row++;
    }

    $xml_sheet .= '</sheetData>';

    // Merge title row across all columns
    $total_cols  = count($header);
    $last_col    = $col_letter($total_cols - 1);
    $xml_sheet .= '<mergeCells><mergeCell ref="A1:'.$last_col.'1"/></mergeCells>';

    $xml_sheet .= '</worksheet>';

    // ── Shared strings XML ────────────────────────────────────────────────────
    $xml_sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_sst .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($sst).'" uniqueCount="'.count($sst).'">';
    foreach ($sst as $sv) {
        $xml_sst .= '<si><t xml:space="preserve">'.htmlspecialchars($sv, ENT_XML1, 'UTF-8').'</t></si>';
    }
    $xml_sst .= '</sst>';

    // ── Styles XML ────────────────────────────────────────────────────────────
    // Fill indices: 0=none,1=gray(reserved),2=present(green),3=absent(red),4=incomplete(amber),5=off(purple),6=header(dark blue),7=title(navy)
    $xml_styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    // fonts: 0=default, 1=bold, 2=bold+white(for header), 3=bold+dark(for status), 4=bold+white+larger(title)
    $xml_styles .= '<fonts count="5">';
    $xml_styles .= '<font><sz val="11"/><name val="Arial"/></font>';                                                         // 0 default
    $xml_styles .= '<font><b/><sz val="11"/><name val="Arial"/></font>';                                                     // 1 bold
    $xml_styles .= '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>';                              // 2 bold white (header bg)
    $xml_styles .= '<font><b/><sz val="10"/><name val="Arial"/></font>';                                                     // 3 bold dark (status cells)
    $xml_styles .= '<font><b/><sz val="13"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>';                              // 4 bold white large (title)
    $xml_styles .= '</fonts>';

    // fills: 0=none,1=gray,2=present,3=absent,4=incomplete,5=off,6=header,7=title
    $xml_styles .= '<fills count="8">';
    $xml_styles .= '<fill><patternFill patternType="none"/></fill>';
    $xml_styles .= '<fill><patternFill patternType="gray125"/></fill>';
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FFC6EFCE"/></patternFill></fill>';   // 2 present
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FFFFC7CE"/></patternFill></fill>';   // 3 absent
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FFFFEB9C"/></patternFill></fill>';   // 4 incomplete
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FFE4DFEC"/></patternFill></fill>';   // 5 off
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FF1565C0"/></patternFill></fill>';   // 6 header dark blue
    $xml_styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FF0D2B6B"/></patternFill></fill>';   // 7 title navy
    $xml_styles .= '</fills>';

    // borders
    $border_thin = '<border><left style="thin"><color rgb="FFD0D0D0"/></left><right style="thin"><color rgb="FFD0D0D0"/></right><top style="thin"><color rgb="FFD0D0D0"/></top><bottom style="thin"><color rgb="FFD0D0D0"/></bottom></border>';
    $xml_styles .= '<borders count="2">';
    $xml_styles .= '<border/>';
    $xml_styles .= $border_thin;
    $xml_styles .= '</borders>';

    // cellStyleXfs (required)
    $xml_styles .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';

    // cellXfs:
    // 0 = default (data)
    // 1 = header  (bold white text, dark-blue fill, thin border, centered)
    // 2 = present (bold, green fill, border, centered)
    // 3 = absent  (bold, red fill, border, centered)
    // 4 = incomplete (bold, amber fill, border, centered)
    // 5 = off     (bold, purple fill, border, centered)
    // 6 = title   (bold white, navy fill, larger, wrap, centered)
    $center  = '<alignment horizontal="center" vertical="center"/>';
    $wrap_c  = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
    $xml_styles .= '<cellXfs count="7">';
    $xml_styles .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment vertical="center"/></xf>'; // 0
    $xml_styles .= '<xf numFmtId="0" fontId="2" fillId="6" borderId="1" xfId="0">'.$center.'</xf>';  // 1 header
    $xml_styles .= '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0">'.$center.'</xf>';  // 2 present
    $xml_styles .= '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0">'.$center.'</xf>';  // 3 absent
    $xml_styles .= '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0">'.$center.'</xf>';  // 4 incomplete
    $xml_styles .= '<xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0">'.$center.'</xf>';  // 5 off
    $xml_styles .= '<xf numFmtId="0" fontId="4" fillId="7" borderId="1" xfId="0">'.$wrap_c.'</xf>'; // 6 title
    $xml_styles .= '</cellXfs>';

    $xml_styles .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
    $xml_styles .= '</styleSheet>';

    // ── Workbook XML ──────────────────────────────────────────────────────────
    $xml_workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_workbook .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml_workbook .= '<sheets><sheet name="'.htmlspecialchars($sheet_title, ENT_XML1).'" sheetId="1" r:id="rId1"/></sheets>';
    $xml_workbook .= '</workbook>';

    // ── Relationships ─────────────────────────────────────────────────────────
    $xml_wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_wb_rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $xml_wb_rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>';
    $xml_wb_rels .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    $xml_wb_rels .= '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $xml_wb_rels .= '</Relationships>';

    $xml_root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_root_rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $xml_root_rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
    $xml_root_rels .= '</Relationships>';

    $xml_ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml_ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $xml_ct .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $xml_ct .= '<Default Extension="xml" ContentType="application/xml"/>';
    $xml_ct .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $xml_ct .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $xml_ct .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
    $xml_ct .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $xml_ct .= '</Types>';

    // ── ZIP it into XLSX ──────────────────────────────────────────────────────
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
    @unlink($tmp);
    $tmp .= '.xlsx';

    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',       $xml_ct);
    $zip->addFromString('_rels/.rels',               $xml_root_rels);
    $zip->addFromString('xl/workbook.xml',           $xml_workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels',$xml_wb_rels);
    $zip->addFromString('xl/worksheets/sheet1.xml',  $xml_sheet);
    $zip->addFromString('xl/sharedStrings.xml',      $xml_sst);
    $zip->addFromString('xl/styles.xml',             $xml_styles);
    $zip->close();

    $blob = file_get_contents($tmp);
    @unlink($tmp);
    return $blob;
}

// ─────────────────────────────────────────────────────────────────────────────
// Generate & stream
// ─────────────────────────────────────────────────────────────────────────────
$sheet_title     = 'Attendance ' . $month_abbrev . $year_4;
$month_label_fmt = date('F Y', strtotime($exp_month . '-01'));

$xlsx_blob = build_xlsx(
    $header_row,
    $data_rows,
    $sheet_title,
    $company_name_raw,
    $month_label_fmt
);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($xlsx_blob));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo $xlsx_blob;
exit;
