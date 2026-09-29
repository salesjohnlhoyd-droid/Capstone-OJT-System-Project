<?php
/**
 * SIT_form_builder.php
 *
 * Provides buildSITFormHTML(array $s): string
 *
 * Call this file with:
 *   require_once 'SIT_form_builder.php';
 *
 * Then call:
 *   echo buildSITFormHTML($sit_data);
 *
 * $sit_data keys expected:
 *   last_name, first_name, middle_name, age, sex, civil_status,
 *   religion, home_address, mobile_no,
 *   course, major, year_section,
 *   day_sched, evening_sched,
 *   ojt_coordinator_first, ojt_coordinator_middle, ojt_coordinator_last,
 *   company, company_address, telephone, contact_person, position
 *
 * No other dependencies — safe to include from any page.
 *
 * ── PDF SAVING APPROACH ──
 * "Save as PDF" uses html2canvas + jsPDF (same as ACCOM_form_builder.php).
 * "Print" still calls window.print() for the browser print dialog.
 *
 * ── LAYOUT STRATEGY ──
 * All sizing is done in pt (points) — the unit both screen and print renderers
 * interpret identically at 1pt = 1/72 inch. A4 = 595pt × 842pt.
 * The @media print block ONLY hides chrome (toolbar, shadow, background).
 * There is NO separate print layout — one layout serves both.
 */

if (!function_exists('buildSITFormHTML')) {

    function buildSITFormHTML(array $s): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

        $name     = $esc(trim($s['last_name'].', '.$s['first_name'].($s['middle_name'] ? ' '.$s['middle_name'] : '')));
        $fullname = $esc(trim($s['first_name'].' '.($s['middle_name'] ? $s['middle_name'].' ' : '').$s['last_name']));
        $age      = $esc($s['age']);
        $sex      = $esc($s['sex']);
        $status   = $esc($s['civil_status']);
        $religion = $esc($s['religion']);
        $address  = $esc($s['home_address']);
        $mobile   = $esc($s['mobile_no'] ?? '');

        $course   = $esc($s['course']);
        $major    = $esc($s['major']);
        $yrsec    = $esc($s['year_section']);
        $day      = $esc($s['day_sched']);
        $evening  = $esc($s['evening_sched']);

        /* ── Assemble OJT coordinator full name from atomic parts ── */
        $ojt_first  = trim((string)($s['ojt_coordinator_first']  ?? ''));
        $ojt_middle = trim((string)($s['ojt_coordinator_middle'] ?? ''));
        $ojt_last   = trim((string)($s['ojt_coordinator_last']   ?? ''));
        $ojt_full   = trim(
            ($ojt_first  !== '' ? $ojt_first  . ' ' : '') .
            ($ojt_middle !== '' ? $ojt_middle . ' ' : '') .
            $ojt_last
        );
        $ojt = $esc($ojt_full);

        $company  = $esc($s['company']);
        $caddr    = $esc($s['company_address']);
        $tel      = $esc($s['telephone']);
        $contact  = $esc($s['contact_person']);
        $position = $esc($s['position']);
        $today    = date('F d, Y');

        /* ── JSON-encode fullname for safe JS injection ── */
        $fullname_json = json_encode($fullname ?: 'Student');

        /* ── Empty-state CSS classes ── */
        $cls_name    = ($name    === '') ? ' empty' : '';
        $cls_age     = ($age     === '') ? ' empty' : '';
        $cls_sex     = ($sex     === '') ? ' empty' : '';
        $cls_status  = ($status  === '') ? ' empty' : '';
        $cls_rel     = ($religion=== '') ? ' empty' : '';
        $cls_addr    = ($address === '') ? ' empty' : '';
        $cls_mobile  = ($mobile  === '') ? ' empty' : '';
        $cls_course  = ($course  === '') ? ' empty' : '';
        $cls_major   = ($major   === '') ? ' empty' : '';
        $cls_yrsec   = ($yrsec   === '') ? ' empty' : '';
        $cls_day     = ($day     === '') ? ' empty' : '';
        $cls_eve     = ($evening === '') ? ' empty' : '';
        $cls_ojt     = ($ojt     === '') ? ' empty' : '';
        $cls_company = ($company === '') ? ' empty' : '';
        $cls_caddr   = ($caddr   === '') ? ' empty' : '';
        $cls_tel     = ($tel     === '') ? ' empty' : '';
        $cls_contact = ($contact === '') ? ' empty' : '';
        $cls_pos     = ($position=== '') ? ' empty' : '';

        /* ── Display values ── */
        $val_name    = ($name    !== '') ? $name     : 'Not provided';
        $val_age     = ($age     !== '') ? $age      : '—';
        $val_sex     = ($sex     !== '') ? $sex      : '—';
        $val_status  = ($status  !== '') ? $status   : '—';
        $val_rel     = ($religion!== '') ? $religion : '—';
        $val_addr    = ($address !== '') ? $address  : '—';
        $val_mobile  = ($mobile  !== '') ? $mobile   : '—';
        $val_course  = ($course  !== '') ? $course   : '—';
        $val_major   = ($major   !== '') ? $major    : '—';
        $val_yrsec   = ($yrsec   !== '') ? $yrsec    : '—';
        $val_day     = ($day     !== '') ? $day      : '—';
        $val_eve     = ($evening !== '') ? $evening  : '—';
        $val_ojt     = ($ojt     !== '') ? $ojt      : '—';
        $val_company = ($company !== '') ? $company  : '—';
        $val_caddr   = ($caddr   !== '') ? $caddr    : '—';
        $val_tel     = ($tel     !== '') ? $tel      : '—';
        $val_contact = ($contact !== '') ? $contact  : '—';
        $val_pos     = ($position!== '') ? $position : '—';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIT Application — {$name}</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════════
   RESET
   ═══════════════════════════════════════════════════════════════ */
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --navy:  #0d2545;
  --blue:  #1a4a8a;
  --accent:#2563eb;
  --gold:  #b8860b;
  --light: #f0f4fb;
  --border:#c8d0e0;
  --text:  #1a2035;
  --muted: #5a6a8a;
  --white: #ffffff;
}

/* ═══════════════════════════════════════════════════════════════
   @PAGE — defines the A4 canvas used by BOTH screen preview and print
   ═══════════════════════════════════════════════════════════════ */
@page {
  size: A4 portrait;   /* 595pt × 842pt */
  margin: 0;
}

/* ═══════════════════════════════════════════════════════════════
   SCREEN CHROME
   ═══════════════════════════════════════════════════════════════ */
body {
  font-family: 'DM Sans', sans-serif;
  background: #d8dde8;
  color: var(--text);
  margin: 0; padding: 0;
}

/* Toolbar — hidden in print */
.sit-toolbar {
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

/* ═══════════════════════════════════════════════════════════════
   DOCUMENT WRAPPER
   Screen: centred card with shadow.
   Print:  wrapper/paper disappear; content fills the @page canvas.
   All inner sizing is in pt so it renders identically both ways.
   ═══════════════════════════════════════════════════════════════ */
.doc-outer {
  width: 595pt;
  margin: 20px auto 36px;
}
.doc-paper {
  background: var(--white);
  border: 1px solid #b0b8cc;
  box-shadow: 0 4px 32px rgba(0,0,0,.22);
  width: 100%;
  display: flex; flex-direction: column;
}

/* ═══════════════════════════════════════════════════════════════
   LETTERHEAD
   ═══════════════════════════════════════════════════════════════ */
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
  letter-spacing: .01em; text-transform: uppercase;
  color: #fff;
}
.lh-addr { font-size: 6.5pt; color: #aac4f0; margin-top: 1pt; font-style: italic; }

/* ═══════════════════════════════════════════════════════════════
   TITLE BAND
   ═══════════════════════════════════════════════════════════════ */
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

/* ═══════════════════════════════════════════════════════════════
   DATE / ADDRESSEE / INTRO
   ═══════════════════════════════════════════════════════════════ */
.date-line {
  padding: 2pt 14pt; text-align: right;
  font-size: 8pt; color: var(--muted);
  border-bottom: 0.75pt solid #e2e6f0; flex-shrink: 0;
}
.date-line span { font-weight: 600; color: var(--text); }

.addressee {
  padding: 4pt 18pt 3pt;
  font-size: 8.5pt; line-height: 1.45; flex-shrink: 0;
}
.addressee .to  { font-weight: 700; color: var(--navy); }
.addressee .salutation { margin-top: 2pt; font-style: italic; }

.intro-text {
  padding: 2pt 18pt 4pt;
  font-size: 8.5pt; line-height: 1.55;
  border-bottom: 0.75pt solid #e2e6f0; flex-shrink: 0;
}

/* ═══════════════════════════════════════════════════════════════
   FORM BODY
   ═══════════════════════════════════════════════════════════════ */
.form-body {
  padding: 5pt 14pt 6pt;
  flex: 1;
  display: flex; flex-direction: column;
}

/* ── Section header ── */
.sec { margin-top: 6pt; }
.sec-title {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 5pt; padding-bottom: 3pt;
  border-bottom: 2px solid var(--navy);
}
.sec-num {
  font-family: 'Crimson Pro', serif;
  background: var(--navy); color: #fff;
  width: 18pt; height: 18pt; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 7.5pt; flex-shrink: 0;
}
.sec-label {
  font-family: 'JetBrains Mono', monospace;
  font-size: 6.5pt; font-weight: 500;
  text-transform: uppercase; letter-spacing: .09em;
  color: var(--navy);
}

/* ── Grid system ── */
.fgrid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4pt 12pt;
}
.fcol-full  { grid-column: 1 / -1; }
.fcol-half  { grid-column: span 1; }

/* ── Field ── */
.field { display: flex; flex-direction: column; gap: 2px; }
.field label {
  font-family: 'JetBrains Mono', monospace;
  font-size: 6pt; font-weight: 500;
  text-transform: uppercase; letter-spacing: .08em;
  color: var(--muted);
}
.fval {
  font-family: 'Crimson Pro', serif;
  font-size: 9pt; color: var(--text);
  border-bottom: 1.5px solid var(--border);
  padding: 3pt 2pt 1.5pt; min-height: 18pt; line-height: 1.3;
}
.fval.area {
  border: 1.5px solid var(--border);
  border-radius: 4px;
  padding: 4pt 6pt;
  min-height: 32pt;
  white-space: pre-wrap;
}
.fval.empty { color: #bbb; font-style: italic; }

/* ── PERSONAL DATA: Horizontal 5-column grid for Age/Sex/Religion/Civil Status/Mobile ── */
.horizontal-personal-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 4pt 12pt;
  margin-bottom: 5pt;
}
.horizontal-personal-grid .field { margin-bottom: 0; }

/* ════════════════════════════════════════════════════════════
   APPROVAL BLOCK — table-based layout, all grid lines hidden
   Left:  Director name/title at top, "Approved:" label + date below
   Right: Student printed name at top, signature line below,
          then caption (signature above printed name)
   ════════════════════════════════════════════════════════════ */
.approval-block {
  margin-top: 10pt;
  border: none;
  display: table;
  width: 100%;
  border-collapse: collapse;
}
.appr-row  { display: table-row; }
.appr-cell {
  display: table-cell;
  border: none;
  vertical-align: middle;
  padding: 0;
}

.appr-cell.left  { width: 50%; }
.appr-cell.right { width: 50%; }

.appr-row.sig-row   .appr-cell { height: 28pt; }
.appr-row.label-row .appr-cell { height: auto; padding: 3pt 8pt; }
.appr-row.name-row  .appr-cell { height: auto; padding: 3pt 8pt; }

.appr-cell.right.no-border {
  border-color: transparent;
  background: transparent;
}

/* Left-side labels */
.cell-label {
  font-family: 'DM Sans', sans-serif;
  font-size: 8pt; font-weight: 600;
  color: var(--text);
}
.cell-dir-name {
  font-family: 'Crimson Pro', serif;
  font-size: 9pt; font-weight: 700;
  color: var(--navy); text-align: center; display: block;
}
.cell-dir-title {
  font-size: 7pt; color: var(--muted);
  line-height: 1.35; text-align: center;
  display: block; margin-top: 1.5pt;
}
.cell-date-row {
  display: flex; align-items: center;
  gap: 5pt; padding: 3pt 8pt; min-height: 22pt;
}
.cell-date-label {
  font-family: 'JetBrains Mono', monospace;
  font-size: 6.5pt; text-transform: uppercase;
  letter-spacing: .06em; color: var(--muted);
  white-space: nowrap; flex-shrink: 0;
}
.cell-date-line {
  flex: 1; border-bottom: 1px solid var(--border);
  height: 1px; align-self: flex-end; margin-bottom: 3px;
}

/* Right-side: student name → sig line → caption */
.cell-student-name {
  font-family: 'Crimson Pro', serif;
  font-size: 9pt; font-weight: 600;
  color: var(--navy); text-align: center;
  display: block; padding: 5pt 10pt 1pt;
}
.cell-sig-line-wrap {
  display: block; padding: 0 14pt 2pt;
}
.cell-sig-line {
  display: block;
  border-bottom: 1.5px solid var(--text);
  width: 100%; height: 1px;
}
.cell-sig-caption {
  font-family: 'DM Sans', sans-serif;
  font-size: 7pt; color: var(--muted);
  font-style: italic; text-align: center;
  display: block; padding: 2pt 8pt 5pt;
}

/* ═══════════════════════════════════════════════════════════════
   FOOTER
   ═══════════════════════════════════════════════════════════════ */
.footer-band {
  background: #f0f2f8;
  border-top: 1pt solid var(--navy);
  padding: 2.5pt 14pt;
  display: flex; justify-content: space-between;
  font-family: 'JetBrains Mono', monospace;
  font-size: 5.5pt; color: #888; letter-spacing: .07em;
  flex-shrink: 0;
}

/* ═══════════════════════════════════════════════════════════════
   PRINT — ONLY hides screen chrome. No layout changes.
   ═══════════════════════════════════════════════════════════════ */
@media print {
  html, body {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    background: #fff !important;
    margin: 0 !important; padding: 0 !important;
  }
  .sit-toolbar { display: none !important; }
  .doc-outer   { margin: 0 !important; width: 100% !important; }
  .doc-paper   { box-shadow: none !important; border: none !important; }
  .sec, .approval-block, .footer-band {
    page-break-inside: avoid;
    break-inside: avoid;
  }
}

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE — narrow screens only
   ═══════════════════════════════════════════════════════════════ */
@media (max-width: 640px) {
  .doc-outer { width: 100%; }
  .fgrid { grid-template-columns: 1fr; }
  .horizontal-personal-grid { grid-template-columns: 1fr; gap: 0.75rem; }
  .fcol-full, .fcol-half { grid-column: auto; }
  .approval-block { display: block; }
  .appr-row  { display: block; border-bottom: none; }
  .appr-cell { display: block; width: 100% !important; border: none; padding: 5pt 8pt; }
}
</style>
</head>
<body>

<!--
  TOOLBAR: visible on screen only. @media print hides it.
-->
<div class="sit-toolbar">
  <button class="tbtn tbtn-primary" onclick="window.print()">&#128438; Print</button>
  <button class="tbtn tbtn-primary" onclick="savePDF()">&#128196; Save as PDF</button>
</div>

<div class="doc-outer"><div class="doc-paper">

  <!-- ══ LETTERHEAD ══ -->
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

  <!-- ══ TITLE BAND ══ -->
  <div class="title-band">
    <div class="dept-tag">On-the-Job Training and Career Development Center</div>
    <h1>APPLICATION FOR SUPERVISED INDUSTRIAL TRAINING</h1>
    <div class="form-meta">
      <span>Form No.: NEUST-OJT-F002</span>
      <span>Effectivity: 01.08.2025</span>
    </div>
  </div>

  <!-- ══ DATE ══ -->
  <div class="date-line">Date: <span>{$today}</span></div>

  <!-- ══ ADDRESSEE ══ -->
  <div class="addressee">
    <div>The Director</div>
    <div>On-the-Job Training and Career Development Office</div>
    <div class="to">NUEVA ECIJA UNIVERSITY OF SCIENCE AND TECHNOLOGY</div>
    <div>Sumacab Campus, Sumacab Este, Cabanatuan City</div>
    <div class="salutation">Sir/Ma'am:</div>
  </div>

  <!-- ══ INTRO ══ -->
  <div class="intro-text">
    &nbsp;&nbsp;&nbsp;&nbsp;May I apply for placement under the On-the-Job Training Program. The following are my particulars and preference for your information and guidance.
  </div>

  <!-- ══ FORM BODY ══ -->
  <div class="form-body">

    <!-- ══ SECTION I — PERSONAL DATA ══ -->
    <div class="sec">
      <div class="sec-title">
        <div class="sec-num">I</div>
        <div class="sec-label">Personal Data</div>
      </div>

      <!-- Full name — spans full width -->
      <div class="fgrid" style="margin-bottom:5pt;">
        <div class="field fcol-full">
          <label>Name (Last Name, First Name, Middle Name)</label>
          <div class="fval{$cls_name}">{$val_name}</div>
        </div>
      </div>

      <!-- HORIZONTAL LAYOUT: Age, Sex, Religion, Civil Status, Mobile No. -->
      <div class="horizontal-personal-grid">
        <div class="field">
          <label>Age</label>
          <div class="fval{$cls_age}">{$val_age}</div>
        </div>
        <div class="field">
          <label>Sex</label>
          <div class="fval{$cls_sex}">{$val_sex}</div>
        </div>
        <div class="field">
          <label>Religion</label>
          <div class="fval{$cls_rel}">{$val_rel}</div>
        </div>
        <div class="field">
          <label>Civil Status</label>
          <div class="fval{$cls_status}">{$val_status}</div>
        </div>
        <div class="field">
          <label>Mobile No.</label>
          <div class="fval{$cls_mobile}">{$val_mobile}</div>
        </div>
      </div>

      <!-- Home Address -->
      <div class="fgrid">
        <div class="field fcol-full">
          <label>Home Address</label>
          <div class="fval area{$cls_addr}">{$val_addr}</div>
        </div>
      </div>
    </div>

    <!-- ══ SECTION II — ACADEMIC DATA ══ -->
    <div class="sec">
      <div class="sec-title">
        <div class="sec-num">II</div>
        <div class="sec-label">Academic Data</div>
      </div>
      <div class="fgrid">
        <div class="field fcol-half">
          <label>Course</label>
          <div class="fval{$cls_course}">{$val_course}</div>
        </div>
        <div class="field fcol-half">
          <label>Major</label>
          <div class="fval{$cls_major}">{$val_major}</div>
        </div>
        <div class="field fcol-half">
          <label>Year and Section</label>
          <div class="fval{$cls_yrsec}">{$val_yrsec}</div>
        </div>
        <div class="field fcol-half" style="display:grid;grid-template-columns:1fr 1fr;gap:4pt 10pt;">
          <div class="field">
            <label>Day</label>
            <div class="fval{$cls_day}">{$val_day}</div>
          </div>
          <div class="field">
            <label>Evening</label>
            <div class="fval{$cls_eve}">{$val_eve}</div>
          </div>
        </div>
        <div class="field fcol-full">
          <label>Name of OJT Coordinator</label>
          <div class="fval{$cls_ojt}">{$val_ojt}</div>
        </div>
      </div>
    </div>

    <!-- ══ SECTION III — PREFERENCE FOR PLACEMENT ══ -->
    <div class="sec">
      <div class="sec-title">
        <div class="sec-num">III</div>
        <div class="sec-label">Preference for Placement</div>
      </div>
      <div class="fgrid">
        <div class="field fcol-full">
          <label>Company Name</label>
          <div class="fval{$cls_company}">{$val_company}</div>
        </div>
        <div class="field fcol-full">
          <label>Address</label>
          <div class="fval area{$cls_caddr}">{$val_caddr}</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:4pt 12pt;margin-top:4pt;">
        <div class="field">
          <label>Telephone Number</label>
          <div class="fval{$cls_tel}">{$val_tel}</div>
        </div>
        <div class="field">
          <label>Contact Person</label>
          <div class="fval{$cls_contact}">{$val_contact}</div>
        </div>
        <div class="field">
          <label>Position / Department</label>
          <div class="fval{$cls_pos}">{$val_pos}</div>
        </div>
      </div>
      </div>
    </div>

    <!-- ══ APPROVAL BLOCK ══ -->
    <div class="approval-block">

      <!-- Row 0: blank space (above director name) | Student name + signature line stacked -->
      <div class="appr-row sig-row">
        <div class="appr-cell left"></div>
        <div class="appr-cell right" style="padding:0; vertical-align:bottom;">
          <span class="cell-student-name">{$fullname}</span>
          <span class="cell-sig-line-wrap"><span class="cell-sig-line"></span></span>
        </div>
      </div>

      <!-- Row 1: blank space | Signature caption -->
      <div class="appr-row sig-row">
        <div class="appr-cell left"></div>
        <div class="appr-cell right" style="padding:0; vertical-align:top;">
          <span class="cell-sig-caption">(Signature of Student over Printed Name)</span>
        </div>
      </div>

      <!-- Row 2: blank space | empty — no border -->
      <div class="appr-row sig-row">
        <div class="appr-cell left"></div>
        <div class="appr-cell right no-border"></div>
      </div>

      <!-- Row 3: "Approved:" label only | empty — no border -->
      <div class="appr-row label-row">
        <div class="appr-cell left" style="padding:3pt 8pt 1pt 8pt; text-align:left; padding-left:8%;">
          <span class="cell-label">Approved:</span>
        </div>
        <div class="appr-cell right no-border"></div>
      </div>

      <!-- Row 4: Director name | empty — no border -->
      <div class="appr-row name-row">
        <div class="appr-cell left" style="padding:3pt 8pt; text-align:center;">
          <span class="cell-dir-name">RANDY M. BAÑEZ, J.D.</span>
        </div>
        <div class="appr-cell right no-border"></div>
      </div>

      <!-- Row 5: Director title | empty — no border -->
      <div class="appr-row name-row">
        <div class="appr-cell left" style="padding:2pt 8pt 3pt; text-align:center;">
          <span class="cell-dir-title">Director<br>On-the-Job Training and Career Development Center</span>
        </div>
        <div class="appr-cell right no-border"></div>
      </div>

      <!-- Row 6: Date Signed | empty — no border -->
      <div class="appr-row label-row">
        <div class="appr-cell left">
          <div class="cell-date-row">
            <span class="cell-date-label">Date Signed:</span>
            <span class="cell-date-line"></span>
          </div>
        </div>
        <div class="appr-cell right no-border"></div>
      </div>

    </div><!-- end .approval-block -->

  </div><!-- end .form-body -->

  <!-- ══ FOOTER ══ -->
  <div class="footer-band">
    <span>NEUST-OJT-F002</span>
    <span>Rev. 02 (01.08.2025)</span>
  </div>

</div></div>

<script>
function savePDF() {
    var studentName = {$fullname_json};
    var filename    = studentName + ' - Application for Supervised Industrial Training.pdf';
    var btn         = document.querySelector('.tbtn-primary[onclick="savePDF()"]');

    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Generating...';
    }

    var paper = document.querySelector('.doc-paper');

    var options = {
        scale: 2,
        useCORS: true,
        allowTaint: true,
        logging: false,
        backgroundColor: '#ffffff'
    };

    html2canvas(paper, options).then(function(canvas) {
        var imgData = canvas.toDataURL('image/jpeg', 0.98);

        var { jsPDF } = window.jspdf;
        var pdf = new jsPDF('p', 'mm', 'a4');
        var imgWidth = 210;
        var imgHeight = (canvas.height * imgWidth) / canvas.width;

        pdf.addImage(imgData, 'JPEG', 0, 0, imgWidth, imgHeight);
        pdf.save(filename);

        if (btn) {
            btn.disabled = false;
            btn.textContent = '📄 Save as PDF';
        }
    }).catch(function(error) {
        console.error('PDF Generation Error:', error);
        if (btn) {
            btn.disabled = false;
            btn.textContent = '📄 Save as PDF';
        }
    });
}
</script>
</body></html>
HTML;
    }

} // end if (!function_exists('buildSITFormHTML'))