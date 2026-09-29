<?php
/**
 * cs_form48_builder.php
 *
 * Provides buildCSForm48HTML(array $d): string
 *
 * Generates a Civil Service Form No. 48 — Daily Time Record
 * matching the official format, with NEUST letterhead/footer
 * consistent with weekly_report_form_builder.php.
 *
 * Expected $d keys:
 * employee_name   string  — Full name of the employee/student
 * month           string  — e.g. "JANUARY 2023"
 * year            int     — e.g. 2023
 * month_num       int     — 1-12
 * official_hours  string  — e.g. "10:00AM-07:00PM"
 * days            array   — keyed by day number (1-31)
 * Each entry: ['am_in'=>'', 'am_out'=>'', 'pm_in'=>'', 'pm_out'=>'', 'remark'=>'']
 * Remark values: '' | 'HOLIDAY' | 'SATURDAY' | 'SUNDAY' | 'OB' | etc.
 *
 * The "Total" row automatically shows the total hours rendered for the month,
 * computed from each day's A.M. (arrival→departure) and P.M. (arrival→departure)
 * entries. HOLIDAY / SATURDAY / SUNDAY rows are not counted.
 */

if (!function_exists('buildCSForm48HTML')) {

    function buildCSForm48HTML(array $d): string
    {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

        // Helper function to safely convert military time formats to regular 12-hour format
        $formatTime = function($timeStr) use ($esc) {
            $trimmed = trim((string)$timeStr);
            if ($trimmed === '') {
                return '';
            }
            // If it already has AM/PM suffix markers, bypass conversion and escape it safely
            if (preg_match('/[a-zA-Z]/', $trimmed)) {
                return $esc($trimmed);
            }
            // Parse timestamp format and format to standard 'h:i A'
            $timestamp = strtotime($trimmed);
            if ($timestamp === false) {
                return $esc($trimmed);
            }
            return htmlspecialchars(date('h:i A', $timestamp), ENT_QUOTES);
        };

        // Helper: convert a raw time value (e.g. "08:00", "13:30", "8:00 AM") into minutes since midnight
        $toMinutes = function($timeStr) {
            $trimmed = trim((string)$timeStr);
            if ($trimmed === '') {
                return null;
            }
            $timestamp = strtotime('1970-01-01 ' . $trimmed);
            if ($timestamp === false) {
                $timestamp = strtotime($trimmed);
            }
            if ($timestamp === false) {
                return null;
            }
            return ((int)date('G', $timestamp) * 60) + (int)date('i', $timestamp);
        };

        // Helper: minutes between an arrival and a departure (0 when incomplete or invalid)
        $pairMinutes = function($inStr, $outStr) use ($toMinutes) {
            $in  = $toMinutes($inStr);
            $out = $toMinutes($outStr);
            if ($in === null || $out === null) {
                return 0;
            }
            $diff = $out - $in;
            // Handle 12-hour values entered without AM/PM (e.g. in "08:00", out "05:00")
            if ($diff < 0 && ($diff + 720) > 0) {
                $diff += 720;
            }
            return $diff > 0 ? $diff : 0;
        };

        /* ── header values ── */
        $employee_name  = $esc($d['employee_name']  ?? '');
        $month_label    = $esc($d['month']          ?? '');
        $official_hours = $esc($d['official_hours'] ?? '');
        $year           = (int)($d['year']           ?? date('Y'));
        $month_num      = (int)($d['month_num']      ?? date('n'));

        /* ── days data ── */
        $days_data = $d['days'] ?? [];

        /* ── figure out total days in month ── */
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);

        /* ── build rows ── */
        $rows_html = '';
        $total_minutes = 0; // running total of rendered minutes for the month
        for ($day = 1; $day <= $days_in_month; $day++) {
            $info    = $days_data[$day] ?? [];
            $remark  = trim($info['remark'] ?? '');
            
            // Format time inputs into traditional 12-hour settings
            $am_in   = $formatTime($info['am_in']   ?? '');
            $am_out  = $formatTime($info['am_out']  ?? '');
            $pm_in   = $formatTime($info['pm_in']   ?? '');
            $pm_out  = $formatTime($info['pm_out']  ?? '');

            /* Determine if this is a special day (spans all columns) */
            $special_labels = ['HOLIDAY', 'SATURDAY', 'SUNDAY'];
            $is_special = in_array(strtoupper($remark), $special_labels);

            if ($is_special) {
                $rows_html .= "
        <tr class=\"dtr-row\">
            <td class=\"col-day\"><strong>{$day}</strong></td>
            <td colspan=\"4\" class=\"col-special\">" . strtoupper($remark) . "</td>
            <td class=\"col-ut-h\"></td>
            <td class=\"col-ut-m\"></td>
        </tr>";
            } else {
                /* ── compute rendered minutes for this day ── */
                $raw_am_in  = $info['am_in']  ?? '';
                $raw_am_out = $info['am_out'] ?? '';
                $raw_pm_in  = $info['pm_in']  ?? '';
                $raw_pm_out = $info['pm_out'] ?? '';

                $am_minutes = $pairMinutes($raw_am_in, $raw_am_out);
                $pm_minutes = $pairMinutes($raw_pm_in, $raw_pm_out);
                $day_minutes = $am_minutes + $pm_minutes;

                // Continuous duty (only A.M. arrival and P.M. departure recorded, no lunch punches)
                if ($day_minutes === 0
                    && trim((string)$raw_am_out) === '' && trim((string)$raw_pm_in) === '') {
                    $day_minutes = $pairMinutes($raw_am_in, $raw_pm_out);
                }
                $total_minutes += $day_minutes;

                /* Check for inline special notes (e.g. OB on day 31) */
                $ut_note = '';
                if (!empty($remark) && !in_array(strtoupper($remark), ['HOLIDAY','SATURDAY','SUNDAY'])) {
                    $ut_note = $esc($remark);
                }
                $rows_html .= "
        <tr class=\"dtr-row\">
            <td class=\"col-day\"><strong>{$day}</strong></td>
            <td class=\"col-time\">{$am_in}</td>
            <td class=\"col-time\">{$am_out}</td>
            <td class=\"col-time\">{$pm_in}</td>
            <td class=\"col-time\">{$pm_out}</td>
            <td class=\"col-ut-h\">{$ut_note}</td>
            <td class=\"col-ut-m\"></td>
        </tr>";
            }
        }

        /* ── total hours display ── */
        $total_hours_part   = intdiv($total_minutes, 60);
        $total_minutes_part = $total_minutes % 60;
        $total_display = $total_minutes > 0
            ? $total_hours_part . ' hrs' . ($total_minutes_part > 0 ? ' ' . $total_minutes_part . ' mins' : '')
            : '';

        /* ── display name ── */
        $name_display = $employee_name !== '' ? $employee_name : '____________________________';

        /* ── JSON Metadata Strings ── */
        $fname_json = json_encode(($d['employee_name'] ?? 'Employee') . ' — DTR ' . ($d['month'] ?? '') . '.pdf');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CS Form 48 — Daily Time Record — {$employee_name}</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ════════════════════════════════════════════════
   RESET & VARIABLES
════════════════════════════════════════════════ */
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --navy:   #0d2545;
  --blue:   #1a4a8a;
  --accent: #2563eb;
  --gold:   #b8860b;
  --light:  #f0f4fb;
  --border: #c8d0e0;
  --text:   #1a2035;
  --muted:  #5a6a8a;
  --white:  #ffffff;
}

@page { size: A4 portrait; margin: 0; }

body {
  font-family: 'DM Sans', sans-serif;
  background: #d8dde8;
  color: var(--text);
  margin: 0; padding: 0;
}

/* ════════════════════════════════════════════════
   SCREEN CHROME
════════════════════════════════════════════════ */
.wkr-toolbar {
  background: var(--navy);
  padding: .5rem 1.5rem;
  display: flex; align-items: center; justify-content: center;
  position: sticky; top: 0; z-index: 100;
  box-shadow: 0 2px 12px rgba(0,0,0,.3);
  gap: 1.2rem; flex-wrap: wrap;
}
.tbtn {
  display: inline-flex; align-items: center; gap: .4rem;
  padding: 5px 18px; border-radius: 5px;
  font-size: .7rem; font-weight: 600;
  cursor: pointer; border: none;
  font-family: 'DM Sans', sans-serif;
  transition: all .15s; text-decoration: none;
}
.tbtn-primary { background: #2563eb; color: #fff; }
.tbtn-primary:hover { background: #1d4ed8; }

/* ════════════════════════════════════════════════
   PAGE ARCHITECTURE
════════════════════════════════════════════════ */
.doc-outer {
  width: 794px;
  margin: 20px auto 36px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.doc-paper {
  background: var(--white);
  border: 1px solid #b0b8cc;
  box-shadow: 0 4px 32px rgba(0,0,0,.15);
  width: 794px;
  height: 1123px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-sizing: border-box;
}

/* ════════════════════════════════════════════════
   LETTERHEAD
════════════════════════════════════════════════ */
.letterhead {
  background: var(--navy);
  padding: 6pt 14pt;
  display: flex; align-items: center; gap: 9pt;
  border-bottom: 2pt solid var(--gold);
  flex-shrink: 0;
}
.lh-seal {
  width: 36pt; height: 36pt; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; overflow: hidden;
  border: 1.5pt solid rgba(255,255,255,.25);
}
.lh-seal img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
.lh-text { color: #fff; flex: 1; }
.lh-republic {
  font-size: 5.5pt; letter-spacing: .18em; text-transform: uppercase;
  color: #aac4f0; margin-bottom: 1.5pt;
  font-family: 'JetBrains Mono', monospace;
}
.lh-uni {
  font-family: 'DM Sans', sans-serif;
  font-size: 10.5pt; font-weight: 700; line-height: 1.2;
  letter-spacing: .01em; text-transform: uppercase; color: #fff;
}
.lh-addr { font-size: 6.5pt; color: #aac4f0; margin-top: 1pt; font-style: italic; }

/* ════════════════════════════════════════════════
   CS FORM 48 BODY
════════════════════════════════════════════════ */
.form48-body {
  padding: 8pt 18pt 8pt;
  flex: 1;
  display: flex;
  flex-direction: column;
}

.cs-form-ref {
  font-family: 'Crimson Pro', serif;
  font-size: 8pt;
  font-style: italic;
  color: var(--text);
  margin-bottom: 3pt;
}
.cs-title {
  font-family: 'DM Sans', sans-serif;
  font-size: 14pt;
  font-weight: 700;
  text-align: center;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: var(--text);
  line-height: 1.2;
}
.cs-subtitle {
  text-align: center;
  font-family: 'Crimson Pro', serif;
  font-size: 9pt;
  color: var(--muted);
  margin-bottom: 4pt;
  letter-spacing: .05em;
}
.cs-name-block {
  text-align: center;
  margin-bottom: 4pt;
}
.cs-name-line {
  display: inline-block;
  min-width: 220pt;
  border-bottom: 1pt solid var(--text);
  font-family: 'Crimson Pro', serif;
  font-size: 10pt;
  font-weight: 600;
  color: var(--text);
  text-align: center;
  padding-bottom: 1pt;
}
.cs-name-caption {
  display: block;
  font-family: 'DM Sans', sans-serif;
  font-size: 6.5pt;
  color: var(--muted);
  text-align: center;
  margin-top: 1pt;
  letter-spacing: .05em;
}

.cs-meta-row {
  display: flex;
  align-items: baseline;
  gap: 0;
  margin-bottom: 4pt;
  flex-wrap: wrap;
}
.cs-meta-label {
  font-family: 'Crimson Pro', serif;
  font-size: 8.5pt;
  font-style: italic;
  color: var(--text);
  white-space: nowrap;
  margin-right: 6pt;
}
.cs-meta-val {
  font-family: 'DM Sans', sans-serif;
  font-size: 9pt;
  font-weight: 700;
  color: var(--text);
  margin-right: 24pt;
  letter-spacing: .03em;
  white-space: nowrap;
}
.cs-hours-block {
  display: flex;
  flex-direction: column;
  gap: 1pt;
  margin-left: auto;
}
.cs-hours-row {
  display: flex;
  align-items: baseline;
  gap: 6pt;
}
.cs-hours-label {
  font-family: 'Crimson Pro', serif;
  font-size: 7.5pt;
  font-style: italic;
  color: var(--text);
  white-space: nowrap;
}
.cs-hours-val {
  font-family: 'JetBrains Mono', monospace;
  font-size: 7.5pt;
  color: var(--text);
  border-bottom: 1pt solid #999;
  min-width: 100pt;
  padding-bottom: 1pt;
}

/* ════════════════════════════════════════════════
   DTR TABLE
════════════════════════════════════════════════ */
.dtr-table {
  width: 100%;
  border-collapse: collapse;
  border: 1.5pt solid #000;
  font-family: 'DM Sans', sans-serif;
  font-size: 8pt;
  flex-shrink: 0;
}
.dtr-table th,
.dtr-table td {
  border: 1pt solid #555;
  text-align: center;
  vertical-align: middle;
  padding: 2pt 3pt;
}
.dtr-table .th-group {
  background: #f0f0f0;
  font-weight: 700;
  font-size: 7.5pt;
  text-transform: uppercase;
  letter-spacing: .03em;
  color: var(--navy);
}
.dtr-table .th-group-am { border-right: 1.5pt solid #333; }
.dtr-table .th-group-pm { border-right: 1.5pt solid #333; }
.dtr-table .th-sub {
  background: #f8f8f8;
  font-size: 6.5pt;
  font-weight: 600;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .04em;
}
.col-day   { width: 28pt; }
.col-time  { width: 46pt; }
.col-ut-h  { width: 38pt; }
.col-ut-m  { width: 36pt; }

.dtr-table .dtr-row td {
  height: 12.5pt;
  font-family: 'Crimson Pro', serif;
  font-size: 8.5pt;
  color: var(--text);
  line-height: 1;
}
.dtr-table .dtr-row td.col-day {
  font-weight: 700;
  font-size: 8pt;
  color: var(--navy);
  background: #fafafa;
}
.dtr-table .col-special {
  font-family: 'DM Sans', sans-serif;
  font-size: 7.5pt;
  font-weight: 700;
  color: var(--muted);
  letter-spacing: .06em;
  background: #f5f5f5;
}
.dtr-table .total-row td {
  background: #ebebeb;
  font-family: 'DM Sans', sans-serif;
  font-size: 8pt;
  font-weight: 700;
  padding: 3pt 4pt;
  text-align: right;
  color: var(--navy);
}
.dtr-table .total-row td:last-child { text-align: center; }
.dtr-table .total-row td.total-value { white-space: nowrap; }

/* ════════════════════════════════════════════════
   CERTIFICATION / SIGNATURE SECTION
════════════════════════════════════════════════ */
.cert-section {
  margin-top: 8pt;
  flex-shrink: 0;
}
.cert-text {
  font-family: 'Crimson Pro', serif;
  font-size: 8pt;
  font-style: italic;
  color: var(--text);
  line-height: 1.55;
  margin-bottom: 10pt;
}
.cert-sig-row {
  display: flex;
  align-items: flex-end;
  margin-bottom: 8pt;
}
.cert-sig-name {
  font-family: 'Crimson Pro', serif;
  font-size: 9pt;
  font-weight: 700;
  color: var(--navy);
  border-bottom: 1.5pt solid var(--text);
  padding-bottom: 2pt;
  display: inline-block;
  min-width: 180pt;
  text-align: center;
}
.cert-sig-caption {
  font-family: 'DM Sans', sans-serif;
  font-size: 6.5pt;
  color: var(--muted);
  margin-top: 2pt;
  display: block;
  text-align: center;
}
.verified-label {
  font-family: 'Crimson Pro', serif;
  font-size: 8pt;
  font-style: italic;
  color: var(--text);
  margin-bottom: 18pt;
}
.in-charge-block {
  display: flex;
  flex-direction: column;
  align-items: center;
}
.in-charge-line {
  width: 200pt;
  border-bottom: 2pt solid var(--navy);
  margin-bottom: 2pt;
}
.in-charge-caption {
  font-family: 'DM Sans', sans-serif;
  font-size: 7pt;
  color: var(--muted);
  text-align: center;
  letter-spacing: .05em;
}

/* ════════════════════════════════════════════════
   FOOTER BAND
════════════════════════════════════════════════ */
.footer-band {
  background: #f0f2f8;
  border-top: 1pt solid var(--navy);
  padding: 2.5pt 14pt;
  display: flex; justify-content: space-between;
  font-family: 'JetBrains Mono', monospace;
  font-size: 5.5pt; color: #888; letter-spacing: .07em;
  flex-shrink: 0;
  margin-top: auto;
}

/* Blueprints configuration repositories */
#raw-source-data,
#header-clone,
#footer-clone {
  display: none;
  position: fixed;
  top: 0; left: -9999px;
  pointer-events: none;
}

/* ════════════════════════════════════════════════
   PRINT ENGINE
════════════════════════════════════════════════ */
@media print {
  html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  .wkr-toolbar { display: none !important; }
  .doc-outer { margin: 0 !important; gap: 0 !important; }
  .doc-paper { border: none !important; box-shadow: none !important; page-break-after: always !important; break-after: always !important; }
}
</style>
</head>
<body>

<div class="wkr-toolbar">
  <button class="tbtn tbtn-primary" onclick="window.print()">&#128438; Print</button>
  <button class="tbtn tbtn-primary" id="btn-save-pdf" onclick="savePDF()">&#128196; Save as PDF</button>
</div>

<div id="header-clone">
  <div class="letterhead">
    <div class="lh-seal">
      <img src="logo.webp" alt="NEUST Logo">
    </div>
    <div class="lh-text">
      <div class="lh-republic">Republic of the Philippines</div>
      <div class="lh-uni">NUEVA ECIJA UNIVERSITY OF SCIENCE AND TECHNOLOGY</div>
      <div class="lh-addr">Cabanatuan City, Nueva Ecija</div>
    </div>
  </div>
</div>

<div id="footer-clone">
  <div class="footer-band" style="margin-top:0;">
    <span>CS Form No. 48</span>
    <span>Rev. 01 — NEUST OJT System</span>
  </div>
</div>

<div id="raw-source-data">
  <div class="form48-body">
    <div class="cs-form-ref">Civil Service Form No. 48</div>
    <div class="cs-title">DAILY TIME RECORD</div>
    <div class="cs-subtitle">----o0o----</div>

    <div class="cs-name-block">
      <span class="cs-name-line">{$name_display}</span>
      <span class="cs-name-caption">(Name)</span>
    </div>

    <div class="cs-meta-row">
      <span class="cs-meta-label">For the month of</span>
      <span class="cs-meta-val">{$month_label}</span>
      <div class="cs-hours-block">
        <div class="cs-hours-row">
          <span class="cs-hours-label">Official hours for<br>arrival and departure</span>
          <div style="display:flex;flex-direction:column;gap:2pt;">
            <div style="display:flex;align-items:baseline;gap:4pt;">
              <span style="font-family:'Crimson Pro',serif;font-size:7.5pt;font-style:italic;">Regular days</span>
              <span class="cs-hours-val">{$official_hours}</span>
            </div>
            <div style="display:flex;align-items:baseline;gap:4pt;">
              <span style="font-family:'Crimson Pro',serif;font-size:7.5pt;font-style:italic;">Saturdays</span>
              <span class="cs-hours-val" style="min-width:80pt;"></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <table class="dtr-table">
      <thead>
        <tr>
          <th class="th-group col-day" rowspan="2">Day</th>
          <th class="th-group th-group-am" colspan="2">A.M.</th>
          <th class="th-group th-group-pm" colspan="2">P.M.</th>
          <th class="th-group" colspan="2">Undertime</th>
        </tr>
        <tr>
          <th class="th-sub col-time">Arrival</th>
          <th class="th-sub col-time th-group-am">Departure</th>
          <th class="th-sub col-time">Arrival</th>
          <th class="th-sub col-time th-group-pm">Departure</th>
          <th class="th-sub col-ut-h">Hours</th>
          <th class="th-sub col-ut-m">Minutes</th>
        </tr>
      </thead>
      <tbody>
        {$rows_html}
        <tr class="total-row">
          <td colspan="6" style="text-align:right;padding-right:8pt;">Total</td>
          <td class="total-value">{$total_display}</td>
        </tr>
      </tbody>
    </table>

    <div class="cert-section">
      <div class="cert-text">
        I certify on my honor that the above is a true and correct report of<br>
        the hours of work performed, record of which was made daily at the<br>
        time of arrival and departure from office.
      </div>
      <div class="cert-sig-row">
        <div>
          <span class="cert-sig-name">{$name_display}</span>
          <span class="cert-sig-caption">Signature over Printed Name</span>
        </div>
      </div>
      <div class="verified-label">VERIFIED as to the prescribed office hours:</div>
      <div class="in-charge-block">
        <div class="in-charge-line"></div>
        <div class="in-charge-caption">In Charge</div>
      </div>
    </div>
  </div>
</div>

<div class="doc-outer" id="rendering-preview-root"></div>

<script>
function renderLiveSynchronizedPreview() {
    var root = document.getElementById('rendering-preview-root');
    root.innerHTML = '';

    var paperPage = document.createElement('div');
    paperPage.className = 'doc-paper';

    var hDiv = document.createElement('div');
    hDiv.innerHTML = document.getElementById('header-clone').innerHTML;
    paperPage.appendChild(hDiv);

    var bDiv = document.createElement('div');
    bDiv.className = 'form48-body';
    bDiv.innerHTML = document.querySelector('#raw-source-data .form48-body').innerHTML;
    paperPage.appendChild(bDiv);

    var fDiv = document.createElement('div');
    fDiv.innerHTML = document.getElementById('footer-clone').innerHTML;
    paperPage.appendChild(fDiv);

    root.appendChild(paperPage);
}

async function savePDF() {
    var btn = document.getElementById('btn-save-pdf');
    if (btn) { btn.disabled = true; btn.textContent = 'Generating\u2026'; }
    try {
        await document.fonts.ready;
        var { jsPDF } = window.jspdf;
        var pdf = new jsPDF('p', 'mm', 'a4');
        var pages = document.querySelectorAll('#rendering-preview-root .doc-paper');
        
        for (var p = 0; p < pages.length; p++) {
            var canvas = await html2canvas(pages[p], { 
                scale: 2, 
                useCORS: true, 
                allowTaint: true, 
                logging: false, 
                backgroundColor: '#ffffff',
                width: 794,
                height: 1123
            });
            if (p > 0) pdf.addPage();
            pdf.addImage(canvas.toDataURL('image/jpeg', 0.98), 'JPEG', 0, 0, 210, 297);
        }
        var fname = {$fname_json};
        pdf.save(fname);
    } catch(err) { 
        console.error('PDF processing exception:', err); 
    } finally { 
        if (btn) { btn.disabled = false; btn.innerHTML = '&#128196; Save as PDF'; } 
    }
}

window.addEventListener('DOMContentLoaded', function() {
    document.fonts.ready.then(renderLiveSynchronizedPreview);
});
</script>
</body>
</html>
HTML;
    }

} // end if (!function_exists('buildCSForm48HTML'))