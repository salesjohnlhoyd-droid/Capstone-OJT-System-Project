<?php
/**
 * weekly_report_form_builder.php
 *
 * Provides buildWeeklyReportHTML(array $d): string
 *
 * CHANGES IN THIS VERSION
 * ──────────────────────────────────────────────────────────────────────────
 * FIXED: Synchronized Live Preview, Print, and PDF layouts.
 * The live on-screen view now calculates page limits immediately on load,
 * displaying an exact pixel-perfect preview of how the document splits.
 *
 * REPORT PREVIEW ACTIONS UPDATE (previous revision):
 *   - The in-document toolbar (.wkr-toolbar) that used to show its own
 *     "Print" and "Save as PDF" buttons at the top of this generated
 *     document was hidden (display:none) whenever the document is
 *     viewed on screen. This document is only ever displayed inside the
 *     Report History preview iframe in student_report.php, and the
 *     equivalent Print / Save as PDF buttons are rendered there
 *     instead — centered, directly under the report's "Submitted <date>"
 *     line — calling straight into this document's own window.print()
 *     and savePDF() functions via the iframe's contentWindow.
 *
 * THIS REVISION:
 *   - The .wkr-toolbar markup (and its Print / Save as PDF buttons) has
 *     been removed from this document entirely, since the controls are
 *     already rendered by the parent page (student_report.php) and were
 *     duplicated here. The window.print() and savePDF() functions
 *     themselves are completely UNCHANGED and still fully callable from
 *     the parent page via the iframe's contentWindow — nothing about
 *     that integration was touched.
 *   - INFO TABLE: Added a dedicated "Company" field showing the
 *     student's company (company_name), and split what used to be a
 *     single 4-column row into two rows:
 *       Row 1: Name of Student | Company
 *       Row 2: Course & Section | Training Station | Date
 *     "Training Station" is now its own independent field (training_station)
 *     instead of being fed by company_name — previously the "Training
 *     Station" label was incorrectly displaying the company name.
 */

if (!function_exists('buildWeeklyReportHTML')) {

    function buildWeeklyReportHTML(array $d): string
    {
        /* ── helpers ── */
        $esc  = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

        $fmtH = function($h) {
            if ($h === '' || $h === null) return '';
            $h = (float)$h;
            if ($h <= 0) return '';
            $total_minutes = (int)round($h * 60);
            $hrs  = (int)floor($total_minutes / 60);
            $mins = $total_minutes % 60;
            return "{$hrs}h {$mins}m";
        };

        /* ── header data ── */
        $student_name      = $esc($d['student_name']      ?? '');
        $course            = $esc($d['course']             ?? '');
        $company_name      = $esc($d['company_name']       ?? '');
        $training_station  = $esc($d['training_station']   ?? '');
        $supervisor_name   = $esc($d['supervisor_name']    ?? '');
        $coordinator       = $esc($d['coordinator_name']   ?? '');
        $week_start        = $d['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
        $week_end          = $d['week_end']   ?? date('Y-m-d', strtotime('friday this week'));
        $total_hours_val   = $fmtH($d['total_hours'] ?? 0);

        $date_range = date('M d, Y', strtotime($week_start))
                    . ' – '
                    . date('M d, Y', strtotime($week_end));

        /* ── day rows ── */
        $weekday_labels = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $days_data      = $d['days'] ?? [];

        $date_map = [];
        foreach ($days_data as $date => $info) {
            $date_map[$date] = $info;
        }

        $week_dates = [];
        for ($i = 0; $i < 5; $i++) {
            $week_dates[] = date('Y-m-d', strtotime($week_start . " +{$i} days"));
        }

        $day_rows_html = '';
        foreach ($weekday_labels as $idx => $label) {
            $date      = $week_dates[$idx] ?? null;
            $info      = ($date && isset($date_map[$date])) ? $date_map[$date] : null;
            $present   = $info['present'] ?? false;
            $tasks     = $esc($info['tasks'] ?? '');
            $hours_txt = $fmtH($info['hours'] ?? 0);
            $tasks_txt = $present ? $tasks : '';

            $day_rows_html .= "
            <tr class=\"day-row\" data-day=\"" . strtolower($label) . "\">
                <td class=\"col-day\">{$label}</td>
                <td class=\"col-tasks\">" . nl2br($tasks_txt) . "</td>
                <td class=\"col-hours\">{$hours_txt}</td>
            </tr>";
        }

        /* ── signature display values ── */
        $student_display = $student_name !== '' ? $student_name : '&lt;NAME OF STUDENT&gt;';
        $super_display   = $supervisor_name !== '' ? $supervisor_name : '&lt;NAME OF TRAINER/SUPERVISOR&gt;';
        $coord_display   = $coordinator !== '' ? $coordinator : '&lt;NAME OF COORDINATOR&gt;';

        /* ── JS-safe names ── */
        $student_json = json_encode($student_name ?: 'Student');

        /* ── empty-state helpers ── */
        $e = fn($v) => ($v === '') ? ' empty' : '';

        $cls_student = $e($student_name);
        $cls_company = $e($company_name);
        $cls_station = $e($training_station);
        $cls_course  = $e($course);
        $cls_super   = $e($supervisor_name);
        $cls_coord   = $e($coordinator);

        $val_company = $company_name     !== '' ? $company_name     : '—';
        $val_station = $training_station !== '' ? $training_station : '—';
        $val_course  = $course           !== '' ? $course           : '—';
        $val_super   = $supervisor_name  !== '' ? $supervisor_name  : '—';
        $val_coord   = $coordinator      !== '' ? $coordinator      : '—';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Weekly Report — {$student_name}</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ════════════════════════════════════════════════════════════════
   RESET & VARIABLES
   ════════════════════════════════════════════════════════════════ */
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

@page {
  size: A4 portrait;
  margin: 0;
}

body {
  font-family: 'DM Sans', sans-serif;
  background: #d8dde8;
  color: var(--text);
  margin: 0; padding: 0;
}

/* ════════════════════════════════════════════════════════════════
   UNIFIED PAGE ARCHITECTURE (Screen Preview & Print)
   ════════════════════════════════════════════════════════════════ */
.doc-outer {
  width: 794px;
  margin: 20px auto 36px;
  display: flex;
  flex-direction: column;
  gap: 20px; /* Space between pages in preview mode */
}

.doc-paper {
  background: var(--white);
  border: 1px solid #b0b8cc;
  box-shadow: 0 4px 32px rgba(0,0,0,.15);
  width: 794px;
  height: 1123px; /* Rigid constraint matching physical paper */
  display: flex; 
  flex-direction: column;
  overflow: hidden;
  box-sizing: border-box;
}

/* ════════════════════════════════════════════════════════════════
   LETTERHEAD
   ════════════════════════════════════════════════════════════════ */
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

/* ════════════════════════════════════════════════════════════════
   TITLE BAND
   ════════════════════════════════════════════════════════════════ */
.title-band {
  background: #f5f6fa;
  border-bottom: 1pt solid #d0d5e8;
  padding: 4pt 14pt 3.5pt;
  text-align: center; flex-shrink: 0;
}
.dept-tag {
  font-family: 'JetBrains Mono', monospace;
  font-size: 5.5pt; letter-spacing: .14em;
  text-transform: uppercase; color: var(--muted); margin-bottom: 1pt;
}
.title-band h1 {
  font-family: 'DM Sans', sans-serif;
  font-size: 11pt; font-weight: 700;
  color: var(--navy); letter-spacing: .03em; text-transform: uppercase;
}
.form-meta {
  display: flex; justify-content: center; gap: 18pt; margin-top: 1.5pt;
  font-family: 'JetBrains Mono', monospace; font-size: 5.5pt; color: #888;
}

/* ════════════════════════════════════════════════════════════════
   FORM BODY
   ════════════════════════════════════════════════════════════════ */
.form-body {
  padding: 5pt 14pt 6pt;
  flex: 1;
  display: flex; flex-direction: column;
  justify-content: flex-start;
}

/* Applied only to a page whose ONLY content is the signature section
   (i.e. it split onto its own page with nothing else on it). Pages that
   share the signature section with the info table / report table keep
   the default top-aligned (flex-start) layout above and are untouched. */
.form-body--center {
  justify-content: center;
}

/* ════════════════════════════════════════════════════════════════
   INFO TABLE
   ════════════════════════════════════════════════════════════════ */
.info-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 6pt;
  border: 1.5pt solid #000;
  table-layout: fixed;
  flex-shrink: 0;
}
.info-table td {
  border: 1pt solid #888;
  padding: 5pt 7pt;
  vertical-align: top;
}

.info-lbl {
  font-family: 'JetBrains Mono', monospace;
  font-size: 6pt; color: var(--muted);
  text-transform: uppercase; letter-spacing: .08em;
  display: block; margin-bottom: 2pt;
  white-space: nowrap;
}
.info-val {
  font-family: 'Crimson Pro', serif;
  font-size: 9pt; font-weight: 600;
  color: var(--text);
  display: block;
  word-break: break-word;
  line-height: 1.3;
}
.info-val.empty { color: #aaa; font-style: italic; font-weight: 400; }

.info-date-val {
  font-family: 'Crimson Pro', serif;
  font-size: 9pt; font-weight: 600;
  color: var(--text);
  display: block;
  white-space: normal;
  line-height: 1.4;
}

/* ════════════════════════════════════════════════════════════════
   MAIN DATA TABLE
   ════════════════════════════════════════════════════════════════ */
.report-table-wrap {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  margin-bottom: 0;
}
.report-table {
  width: 100%;
  border-collapse: collapse;
  border: 2pt solid #000;
}
.report-table th,
.report-table td {
  border: 1pt solid #000;
  padding: 4pt 6pt;
  vertical-align: top;
}
.report-table thead th {
  background: #d9d9d9;
  font-family: 'DM Sans', sans-serif;
  font-size: 7.5pt; font-weight: 700;
  text-align: center;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: var(--navy);
  padding: 5pt 6pt;
}
.report-table .col-day   { width: 70pt;  text-align: center; }
.report-table .col-tasks { width: auto; }
.report-table .col-hours { width: 46pt;  text-align: center; }

.report-table .day-row td {
  font-family: 'Crimson Pro', serif;
  font-size: 9pt; line-height: 1.5;
  background: #ffffff;
  height: 82pt;
}
.report-table .day-row .col-day {
  font-weight: 700;
  font-size: 8.5pt;
  color: var(--navy);
  text-align: center;
}

.report-table .total-row td {
  background: #f5f5f5;
  font-family: 'DM Sans', sans-serif;
  font-size: 8.5pt; font-weight: 700;
  padding: 5pt 6pt;
}
.report-table .total-row .col-tasks { text-align: right; }
.report-table .total-row .col-hours { text-align: center; }

/* ════════════════════════════════════════════════════════════════
   SIGNATURE SECTION
   ════════════════════════════════════════════════════════════════ */
.sig-section {
  margin-top: 12pt;
  display: flex;
  flex-direction: column;
  gap: 6pt;
  flex-shrink: 0;
}
.sig-row-wrap {
  display: flex; gap: 0; width: 100%;
}
.sig-half {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}
.sig-label {
  font-family: 'DM Sans', sans-serif;
  font-size: 8pt; color: var(--text);
  margin-bottom: 14pt;
  width: 100%;
}
.sig-name {
  font-family: 'Crimson Pro', serif;
  font-size: 9.5pt; font-weight: 700;
  color: var(--navy);
  border-bottom: 1.5pt solid var(--text);
  padding-bottom: 2pt; display: inline-block;
  min-width: 140pt;
  text-align: center;
}
.sig-name.empty { color: #aaa; font-style: italic; font-weight: 400; border-color: #aaa; }
.sig-caption {
  font-family: 'DM Sans', sans-serif;
  font-size: 7pt; color: var(--muted);
  margin-top: 2pt; display: block;
  text-align: center;
}
.noted-section {
  margin-top: 4pt;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

/* ════════════════════════════════════════════════════════════════
   FOOTER BAND
   ════════════════════════════════════════════════════════════════ */
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

/* Data Repository Schemas (Hidden blueprints) */
#raw-source-data,
#header-clone,
#footer-clone {
  display: none;
  position: fixed;
  top: 0; left: -9999px;
  pointer-events: none;
}

/* ════════════════════════════════════════════════════════════════
   PRINT ENGINE RULES
   ════════════════════════════════════════════════════════════════ */
@media print {
  html, body {
    background: #fff !important;
    margin: 0 !important; padding: 0 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  .doc-outer {
    margin: 0 !important;
    gap: 0 !important;
  }
  .doc-paper {
    border: none !important;
    box-shadow: none !important;
    page-break-after: always !important;
    break-after: always !important;
  }
  .doc-paper:last-child {
    page-break-after: auto !important;
    break-after: auto !important;
  }
}
</style>
</head>
<body>

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
  <div class="title-band">
    <div class="dept-tag">On-the-Job Training and Career Development Center</div>
    <h1>STUDENT ON-THE-JOB-TRAINING WEEKLY REPORT</h1>
    <div class="form-meta">
      <span>Form No.: NEUST-OJT-F011</span>
      <span>Effectivity: 01.08.2025</span>
    </div>
  </div>
</div>

<div id="footer-clone">
  <div class="footer-band" style="margin-top:0;">
    <span>NEUST-OJT-F011</span>
    <span>Rev. 01 (01.08.2025)</span>
  </div>
</div>

<div id="raw-source-data">
  <table class="info-table">
    <colgroup>
      <col style="width:16.666%">
      <col style="width:16.666%">
      <col style="width:16.666%">
      <col style="width:16.666%">
      <col style="width:16.666%">
      <col style="width:16.666%">
    </colgroup>
    <tbody>
      <tr>
        <td colspan="3">
          <span class="info-lbl">Name of Student:</span>
          <span class="info-val{$cls_student}">{$student_name}</span>
        </td>
        <td colspan="3">
          <span class="info-lbl">Company:</span>
          <span class="info-val{$cls_company}">{$val_company}</span>
        </td>
      </tr>
      <tr>
        <td colspan="2">
          <span class="info-lbl">Course &amp; Section:</span>
          <span class="info-val{$cls_course}">{$val_course}</span>
        </td>
        <td colspan="2">
          <span class="info-lbl">Training Station:</span>
          <span class="info-val{$cls_station}">{$val_station}</span>
        </td>
        <td colspan="2">
          <span class="info-lbl">Date:</span>
          <span class="info-date-val">{$date_range}</span>
        </td>
      </tr>
    </tbody>
  </table>

  <table class="report-table">
    <thead>
      <tr>
        <th class="col-day">DAYS</th>
        <th class="col-tasks">SUMMARY OF WORK, DUTIES AND RESPONSIBILITIES</th>
        <th class="col-hours">HOURS</th>
      </tr>
    </thead>
    <tbody>
      {$day_rows_html}
      <tr class="total-row">
        <td class="col-day"></td>
        <td class="col-tasks">TOTAL TRAINING HOURS PER WEEK</td>
        <td class="col-hours">{$total_hours_val}</td>
      </tr>
    </tbody>
  </table>

  <div class="sig-section">
    <div class="sig-row-wrap">
      <div class="sig-half">
        <div class="sig-label">Prepared by:</div>
        <span class="sig-name{$cls_student}">{$student_display}</span>
        <span class="sig-caption">On-the-Job Trainee</span>
      </div>
      <div class="sig-half">
        <div class="sig-label">Checked by:</div>
        <span class="sig-name{$cls_super}">{$super_display}</span>
        <span class="sig-caption">Trainer/Supervisor</span>
      </div>
    </div>
    <div class="noted-section">
      <div class="sig-label">Noted by:</div>
      <span class="sig-name{$cls_coord}">{$coord_display}</span>
      <span class="sig-caption">OJT Coordinator</span>
    </div>
  </div>
</div>

<div class="doc-outer" id="rendering-preview-root">
  </div>

<script>
/**
 * Unified Layout Partition Core Engine
 * Computes exact physical page layout segmentation constraints.
 */
function partitionDocumentContent() {
    var PAGE_W = 794;
    var PAGE_H = 1123;
    
    var hdrSource = document.getElementById('header-clone');
    var ftrSource = document.getElementById('footer-clone');

    function getSegmentHeight(element) {
        var sandbox = document.createElement('div');
        sandbox.style.cssText = 'position:fixed;top:0;left:-9999px;width:' + PAGE_W + 'px;visibility:hidden;background:#fff;padding:5pt 14pt;';
        sandbox.appendChild(element.cloneNode(true));
        document.body.appendChild(sandbox);
        var h = sandbox.scrollHeight;
        document.body.removeChild(sandbox);
        return h;
    }

    var hdrMeasure = hdrSource.cloneNode(true);
    hdrMeasure.style.cssText = 'display:block;position:fixed;top:0;left:-9999px;width:'+PAGE_W+'px;visibility:hidden;';
    document.body.appendChild(hdrMeasure);
    var HDR_H = hdrMeasure.scrollHeight || 115;
    document.body.removeChild(hdrMeasure);

    var ftrMeasure = ftrSource.cloneNode(true);
    ftrMeasure.style.cssText = 'display:block;position:fixed;top:0;left:-9999px;width:'+PAGE_W+'px;visibility:hidden;';
    document.body.appendChild(ftrMeasure);
    var FTR_H = ftrMeasure.scrollHeight || 25;
    document.body.removeChild(ftrMeasure);

    var USABLE_H = PAGE_H - HDR_H - FTR_H - 25;

    var originalInfoTable = document.querySelector('#raw-source-data .info-table');
    var originalRows      = document.querySelectorAll('#raw-source-data .report-table tbody tr');
    var originalTableHeader = document.querySelector('#raw-source-data .report-table thead');
    var originalSigSection = document.querySelector('#raw-source-data .sig-section');

    var pagesContent = [[]];
    var currentPageIdx = 0;
    var currentAccumulatedHeight = 0;

    if (originalInfoTable) {
        var infoH = getSegmentHeight(originalInfoTable);
        pagesContent[currentPageIdx].push(originalInfoTable.cloneNode(true));
        currentAccumulatedHeight += infoH;
    }

    if (originalRows.length > 0) {
        var tableHeaderHeight = originalTableHeader ? getSegmentHeight(originalTableHeader) : 35;
        
        var createTableContainer = function() {
            var w = document.createElement('div');
            w.className = 'report-table-wrap';
            var t = document.createElement('table');
            t.className = 'report-table';
            if(originalTableHeader) t.appendChild(originalTableHeader.cloneNode(true));
            var b = document.createElement('tbody');
            t.appendChild(b);
            w.appendChild(t);
            return { wrap: w, tbody: b };
        };

        var currentTableObj = createTableContainer();
        currentAccumulatedHeight += tableHeaderHeight;

        for (var r = 0; r < originalRows.length; r++) {
            var rowClone = originalRows[r].cloneNode(true);
            var tempTable = createTableContainer();
            tempTable.tbody.appendChild(rowClone.cloneNode(true));
            var rowH = getSegmentHeight(tempTable.wrap) - tableHeaderHeight;

            if (currentAccumulatedHeight + rowH > USABLE_H) {
                // Only spill a new page if the current fragment actually has
                // data rows in it. Without this guard, an overflow detected
                // on the very first row of a page would push an empty,
                // header-only table fragment onto the page before moving on,
                // producing a near-blank page.
                if (currentTableObj.tbody.children.length > 0) {
                    pagesContent[currentPageIdx].push(currentTableObj.wrap);
                    currentPageIdx++;
                }
                if (!pagesContent[currentPageIdx]) pagesContent[currentPageIdx] = [];
                currentAccumulatedHeight = tableHeaderHeight;
                currentTableObj = createTableContainer();
            }

            currentTableObj.tbody.appendChild(rowClone);
            currentAccumulatedHeight += rowH;
        }
        if (currentTableObj.tbody.children.length > 0) {
            pagesContent[currentPageIdx].push(currentTableObj.wrap);
        }
    }

    if (originalSigSection) {
        var sigH = getSegmentHeight(originalSigSection);
        if (currentAccumulatedHeight + sigH > USABLE_H) {
            currentPageIdx++;
            if (!pagesContent[currentPageIdx]) pagesContent[currentPageIdx] = [];
        }
        pagesContent[currentPageIdx].push(originalSigSection.cloneNode(true));
    }

    // Safety net: drop any page that ended up with no content at all
    // (e.g. from a boundary case in the measurements above) so a truly
    // blank page never reaches the preview, print, or PDF output.
    pagesContent = pagesContent.filter(function (page) {
        return page && page.length > 0;
    });
    if (pagesContent.length === 0) pagesContent = [[]];

    return pagesContent;
}

/**
 * Builds the accurate preview layout on load
 */
function renderLiveSynchronizedPreview() {
    var root = document.getElementById('rendering-preview-root');
    root.innerHTML = ''; // Clear target layout frame

    var hdrSource = document.getElementById('header-clone');
    var ftrSource = document.getElementById('footer-clone');
    
    var pagesContent = partitionDocumentContent();

    for (var p = 0; p < pagesContent.length; p++) {
        var paperPage = document.createElement('div');
        paperPage.className = 'doc-paper';

        var hDiv = document.createElement('div');
        hDiv.innerHTML = hdrSource.innerHTML;
        paperPage.appendChild(hDiv);

        var bDiv = document.createElement('div');
        bDiv.className = 'form-body';
        for (var i = 0; i < pagesContent[p].length; i++) {
            bDiv.appendChild(pagesContent[p][i].cloneNode(true));
        }
        paperPage.appendChild(bDiv);

        var fDiv = document.createElement('div');
        fDiv.innerHTML = ftrSource.innerHTML;
        paperPage.appendChild(fDiv);

        root.appendChild(paperPage);
    }
}

/**
 * PDF Engine Canvas Target Handler
 * NOTE: This function is intentionally left fully intact. It is no longer
 * triggered by an on-page button (removed per this revision), but it is
 * still called externally by student_report.php via this document's
 * iframe.contentWindow.savePDF().
 */
async function savePDF() {
    var studentName = {$student_json};
    var dateRange   = '{$date_range}';
    var filename    = studentName + ' \u2014 Weekly OJT Report (' + dateRange + ').pdf';

    var btn = document.getElementById('btn-save-pdf');
    if (btn) { btn.disabled = true; btn.textContent = 'Generating\u2026'; }

    var PAGE_W_MM = 210;
    var PAGE_H_MM = 297;

    try {
        await document.fonts.ready;
        
        var { jsPDF } = window.jspdf;
        var pdf = new jsPDF('p', 'mm', 'a4');
        
        // Target the processed pages visible in your live preview
        var previewPages = document.querySelectorAll('#rendering-preview-root .doc-paper');

        for (var p = 0; p < previewPages.length; p++) {
            var canvas = await html2canvas(previewPages[p], {
                scale: 2,
                useCORS: true,
                allowTaint: true,
                logging: false,
                backgroundColor: '#ffffff',
                width: 794,
                height: 1123
            });

            if (p > 0) pdf.addPage();
            pdf.addImage(canvas.toDataURL('image/jpeg', 0.98), 'JPEG', 0, 0, PAGE_W_MM, PAGE_H_MM);
        }

        pdf.save(filename);
    } catch (err) {
        console.error('PDF processing exception:', err);
    } finally {
        if (btn) {
            btn.disabled    = false;
            btn.textContent = '\uD83D\uDCC4 Save as PDF';
        }
    }
}

// Instantiate view generation tracking triggers when documents become accessible
window.addEventListener('DOMContentLoaded', function() {
    document.fonts.ready.then(renderLiveSynchronizedPreview);
});
</script>
</body>
</html>
HTML;
    }

} // end if (!function_exists('buildWeeklyReportHTML'))