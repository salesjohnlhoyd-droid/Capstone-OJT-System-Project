<?php
/**
 * WAIVER_form_builder.php
 *
 * Provides buildWAIVERFormHTML(array $s): string
 *
 * $waiver_data keys expected:
 *   first_name, middle_name, last_name,
 *   ojt_hours, ojt_start, ojt_end,
 *   company, course, major,
 *   home_address, telephone, mobile,
 *   mother_first, mother_middle, mother_last,
 *   father_first, father_middle, father_last,
 *   guardian_type, guardian_other
 *
 * ── PDF SAVING APPROACH ──
 * "Save as PDF" uses html2canvas + jsPDF (same as SIT_form_builder.php).
 * "Print" still calls window.print() for the browser print dialog.
 */

if (!function_exists('buildWAIVERFormHTML')) {

    function buildWAIVERFormHTML(array $s): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

        /* ── Core student / OJT fields ── */
        $fullname  = $esc(trim($s['first_name'].' '.($s['middle_name'] ? $s['middle_name'].' ' : '').$s['last_name']));
        $hours     = $esc($s['ojt_hours']    ?? '');
        $start     = $esc($s['ojt_start']    ?? '');
        $end       = $esc($s['ojt_end']      ?? '');
        $company   = $esc($s['company']      ?? '');
        $course    = $esc($s['course']       ?? '');
        $major     = $esc($s['major']        ?? '');
        $address   = $esc($s['home_address'] ?? '');
        $telephone = $esc($s['telephone']    ?? '');
        $mobile    = $esc($s['mobile']       ?? '');
        $today     = date('F d, Y');

        /* ── Parent / Guardian name parts ── */
        $mother_first   = $esc($s['mother_first']   ?? '');
        $mother_middle  = $esc($s['mother_middle']  ?? '');
        $mother_last    = $esc($s['mother_last']    ?? '');
        $father_first   = $esc($s['father_first']   ?? '');
        $father_middle  = $esc($s['father_middle']  ?? '');
        $father_last    = $esc($s['father_last']    ?? '');
        $guardian_type  = $esc($s['guardian_type']  ?? '');
        $guardian_other = $esc($s['guardian_other'] ?? '');

        $build_name = function(string $f, string $m, string $l): string {
            $parts = array_filter([$f, $m ?: null, $l]);
            return implode(' ', $parts);
        };

        $mother_full  = $build_name($mother_first,  $mother_middle, $mother_last);
        $father_full  = $build_name($father_first,  $father_middle, $father_last);

        switch ($guardian_type) {
            case 'Mother': $guardian_full = $mother_full;    break;
            case 'Father': $guardian_full = $father_full;    break;
            case 'Other':  $guardian_full = $guardian_other; break;
            default:       $guardian_full = '';
        }

        /* ── Inline fill-span builder ── */
        $fill = function(string $value, string $width = '12em') use ($esc): string {
            if ($value !== '') {
                return '<span class="fill-val">' . $value . '</span>';
            }
            return '<span class="fill-blank" style="min-width:' . $width . '"></span>';
        };

        /* Shorthand fill spans */
        $f_name    = $fill($fullname,  '16em');
        $f_hours   = $fill($hours,     '6em');
        $f_start   = $fill($start,     '10em');
        $f_end     = $fill($end,       '10em');
        $f_company = $fill($company,   '18em');
        $f_course  = $fill($course,    '14em');
        $f_major   = $fill($major,     '10em');

        /* Company with inline note */
        $company_note        = '<span class="company-note">(insert name of the institution or company where the On&ndash;the&ndash;Job Training will be conducted)</span>';
        $f_company_with_note = $f_company . ' ' . $company_note;

        /* Acknowledgement display */
        $ack_name    = ($fullname  !== '') ? $fullname  : '&mdash;';
        $ack_address = ($address   !== '') ? $address   : '&mdash;';
        $ack_tel     = ($telephone !== '') ? $telephone : '&mdash;';
        $ack_mobile  = ($mobile    !== '') ? $mobile    : '&mdash;';

        $ack_cls_name    = ($fullname  !== '') ? 'ack-val' : 'ack-val empty';
        $ack_cls_address = ($address   !== '') ? 'ack-val' : 'ack-val empty';
        $ack_cls_tel     = ($telephone !== '') ? 'ack-val' : 'ack-val empty';
        $ack_cls_mobile  = ($mobile    !== '') ? 'ack-val' : 'ack-val empty';

        /* Signature line classes & content */
        $sig_mother_class   = ($mother_full  !== '') ? 'sig-name filled' : 'sig-name';
        $sig_mother_content = ($mother_full  !== '') ? htmlspecialchars($mother_full,  ENT_QUOTES) : '&nbsp;';

        $sig_father_class   = ($father_full  !== '') ? 'sig-name filled' : 'sig-name';
        $sig_father_content = ($father_full  !== '') ? htmlspecialchars($father_full,  ENT_QUOTES) : '&nbsp;';

        $sig_guardian_class   = ($guardian_full !== '') ? 'sig-name filled' : 'sig-name';
        $sig_guardian_content = ($guardian_full !== '') ? htmlspecialchars($guardian_full, ENT_QUOTES) : '&nbsp;';

        /* ── JSON-encode fullname for safe JS injection ── */
        $fullname_json = json_encode($fullname ?: 'Student');

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Waiver and Permission Form &mdash; ' . $fullname . '</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════════════════
   RESET
══════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --navy: #07145f;
  --gold: #c8a800;
  --text: #1a1a1a;
  --muted: #555;
  --white: #ffffff;
}

/* ══════════════════════════════════════════════════════════
   SCREEN BODY
══════════════════════════════════════════════════════════ */
body {
  font-family: "DM Sans", sans-serif;
  background: #d8dde8;
  color: var(--text);
  margin: 0; padding: 0;
}

/* ── TOOLBAR ── */
.sit-toolbar {
  background: var(--navy);
  padding: .6rem 1.5rem;
  display: flex; align-items: center; justify-content: center;
  position: sticky; top: 0; z-index: 100;
  box-shadow: 0 2px 10px rgba(0,0,0,.35);
  gap: 1.2rem; flex-wrap: wrap;
}
.tbtn {
  display: inline-flex; align-items: center; gap: .4rem;
  padding: 6px 20px; border-radius: 5px; font-size: .75rem;
  font-weight: 600; cursor: pointer; border: none;
  font-family: "DM Sans", sans-serif; transition: all .15s;
}
.tbtn-primary { background: #2563eb; color: #fff; }
.tbtn-primary:hover { background: #1d4ed8; }

/* ══════════════════════════════════════════════════════════
   SCREEN: A4 simulation  794 x 1123 px (96 dpi)
══════════════════════════════════════════════════════════ */
.doc-outer {
  width: 794px; height: 1123px;
  margin: 24px auto 40px; overflow: hidden;
}
.doc-paper {
  background: var(--white);
  border: 1px solid #b0b8cc;
  box-shadow: 0 4px 32px rgba(0,0,0,.22);
  width: 100%; height: 100%;
  display: flex; flex-direction: column; overflow: hidden;
}

/* ══════════════════════════════════════════════════════════
   LETTERHEAD
══════════════════════════════════════════════════════════ */
.letterhead {
  background: var(--navy);
  padding: 13px 24px;
  display: flex; align-items: center; gap: 14px;
  border-bottom: 3px solid var(--gold); flex-shrink: 0;
}
.lh-seal {
  width: 60px; height: 60px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; overflow: hidden;
  border: 2px solid rgba(255,255,255,.25);
}
.lh-seal img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
.lh-text { color: #fff; flex: 1; }
.lh-republic {
  font-size: 8px; letter-spacing: .2em; text-transform: uppercase;
  color: #aac4f0; margin-bottom: 4px; font-family: "JetBrains Mono", monospace;
}
.lh-uni {
  font-family: "DM Sans", sans-serif; font-size: 17px;
  font-weight: 700; line-height: 1.2; letter-spacing: .01em; text-transform: uppercase;
}
.lh-addr { font-size: 10px; color: #aac4f0; margin-top: 3px; font-style: italic; }

/* ══════════════════════════════════════════════════════════
   TITLE BAND
══════════════════════════════════════════════════════════ */
.title-band {
  background: #f5f6fa;
  border-bottom: 1.5px solid #d0d5e8;
  padding: 9px 24px 8px;
  text-align: center; flex-shrink: 0;
}
.dept-tag {
  font-family: "JetBrains Mono", monospace; font-size: 8px;
  letter-spacing: .16em; text-transform: uppercase;
  color: var(--muted); margin-bottom: 3px;
}
.title-band h1 {
  font-family: "DM Sans", sans-serif; font-size: 20px;
  font-weight: 700; color: var(--navy);
  letter-spacing: .04em; text-transform: uppercase;
}
.form-meta {
  display: flex; justify-content: center; gap: 28px; margin-top: 4px;
  font-family: "JetBrains Mono", monospace; font-size: 8px; color: #888;
}

/* ── DATE LINE ── */
.date-line {
  padding: 5px 24px; text-align: right;
  font-size: 12px; color: var(--muted);
  border-bottom: 1px solid #e2e6f0; flex-shrink: 0;
}
.date-line span { font-weight: 600; color: var(--text); }

/* ══════════════════════════════════════════════════════════
   FORM BODY
══════════════════════════════════════════════════════════ */
.form-body {
  padding: 18px 32px 14px;
  flex: 1; display: flex; flex-direction: column;
  overflow: hidden; min-height: 0;
}

.waiver-para {
  font-family: "DM Sans", sans-serif;
  font-size: 13px; line-height: 1.8;
  color: var(--text); text-align: justify;
  text-indent: 2.4em; margin-bottom: 8px; word-break: break-word;
}
.fill-val {
  font-weight: 700; text-decoration: underline;
  text-underline-offset: 2px; color: var(--text);
}
.fill-blank {
  display: inline-block; border-bottom: 1.5px solid #555;
  vertical-align: baseline; height: 1.1em; margin-bottom: -0.15em;
}
.company-note { font-size: 10px; color: var(--muted); font-style: italic; font-weight: 400; }

.body-spacer { flex: 1; min-height: 0; }

/* ══════════════════════════════════════════════════════════
   SIGNATURE SECTION
   Three rows (Mother / Father / Guardian).
   Each row: [Name 44%] [Signature 32%] [Date rest]
   align-items:flex-end keeps underlines on the same baseline.
   margin-bottom:16px separates the rows with proper breathing room.
══════════════════════════════════════════════════════════ */
.sig-section {
  border-top: 2px solid var(--navy);
  padding-top: 10px; margin-top: 8px; flex-shrink: 0;
}
.sig-section-title {
  font-family: "JetBrains Mono", monospace;
  font-size: 8.5px; font-weight: 600;
  text-transform: uppercase; letter-spacing: .12em;
  color: var(--navy); margin-bottom: 10px;
}

.sig-row {
  display: flex; flex-direction: row;
  align-items: flex-end; gap: 16px;
  margin-bottom: 16px;
}
.sig-row:last-child { margin-bottom: 0; }

.sig-cell-name {
  flex: 0 0 44%;
  display: flex; flex-direction: column; justify-content: flex-end;
}
.sig-cell-sig {
  flex: 0 0 32%;
  display: flex; flex-direction: column; justify-content: flex-end;
}
.sig-cell-date {
  flex: 1 1 0;
  display: flex; flex-direction: column; justify-content: flex-end;
}

.sig-label {
  font-family: "JetBrains Mono", monospace;
  font-size: 8px; font-weight: 500;
  text-transform: uppercase; letter-spacing: .09em;
  color: #888; display: block; margin-bottom: 4px; white-space: nowrap;
}
.sig-name {
  font-family: "DM Sans", sans-serif;
  font-size: 13px; font-weight: 400; color: var(--text);
  border-bottom: 1.5px solid #999;
  min-height: 26px; padding: 2px 3px 3px;
  width: 100%; display: block;
}
.sig-name.filled { font-weight: 700; color: var(--navy); border-bottom-color: var(--navy); }
.sig-line {
  border-bottom: 1.5px solid #999;
  min-height: 26px; padding: 2px 3px;
  width: 100%; display: block;
}

/* ══════════════════════════════════════════════════════════
   ACKNOWLEDGEMENT
   Row 1: [Name of Student]  [Home Address]
   Row 2: [Telephone No.]    [Mobile No.]
══════════════════════════════════════════════════════════ */
.ack-block {
  border-top: 2px solid var(--navy);
  padding-top: 10px; margin-top: 10px; flex-shrink: 0;
}
.ack-title {
  font-family: "JetBrains Mono", monospace;
  font-size: 10px; font-weight: 700; color: var(--navy);
  margin-bottom: 9px; text-transform: uppercase; letter-spacing: .1em;
}
.ack-row {
  display: flex; flex-direction: row;
  align-items: flex-end; gap: 24px; margin-bottom: 10px;
}
.ack-row:last-child { margin-bottom: 0; }
.ack-field {
  flex: 1 1 0;
  display: flex; flex-direction: column; justify-content: flex-end;
}
.ack-label {
  font-family: "JetBrains Mono", monospace;
  font-size: 8px; font-weight: 600;
  text-transform: uppercase; letter-spacing: .09em;
  color: #888; margin-bottom: 2px; display: block; white-space: nowrap;
}
.ack-val {
  font-family: "DM Sans", sans-serif;
  font-size: 13px; color: var(--text);
  border-bottom: 1.5px solid #bbb;
  width: 100%; padding: 2px 3px; min-height: 24px; display: block;
}
.ack-val.empty { color: #aaa; font-style: italic; }

/* ── FOOTER ── */
.footer-band {
  background: #f0f2f8; border-top: 1.5px solid var(--navy);
  padding: 5px 24px;
  display: flex; justify-content: space-between;
  font-family: "JetBrains Mono", monospace;
  font-size: 8px; color: #888; letter-spacing: .07em;
  flex-shrink: 0;
}

/* ══════════════════════════════════════════════════════════
   PRINT
   @page margin:0 lets .doc-paper fill the full A4 sheet.
   height:100vh on .doc-paper fills the entire page height.
   All flex declarations use !important so the browser print
   engine cannot reset them to block layout.
══════════════════════════════════════════════════════════ */
@media print {
  @page {
    size: A4 portrait;
    margin: 0;
  }

  html, body {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    background: #fff !important;
    margin: 0 !important; padding: 0 !important;
    width: 100% !important; height: 100% !important;
  }

  .sit-toolbar { display: none !important; }

  .doc-outer {
    width: 100% !important; height: 100% !important;
    margin: 0 !important; padding: 0 !important;
    overflow: visible !important;
  }
  .doc-paper {
    box-shadow: none !important; border: none !important;
    width: 100% !important;
    height: 100vh !important;
    display: flex !important; flex-direction: column !important;
    overflow: hidden !important;
    page-break-after: avoid; break-after: avoid;
  }

  /* Letterhead */
  .letterhead {
    padding: 10pt 18pt !important; gap: 11pt !important;
    border-bottom-width: 2.5pt !important; flex-shrink: 0 !important;
  }
  .lh-seal     { width: 44pt !important; height: 44pt !important; }
  .lh-uni      { font-size: 12.5pt !important; }
  .lh-republic { font-size: 6pt !important; margin-bottom: 3pt !important; }
  .lh-addr     { font-size: 7.5pt !important; margin-top: 2pt !important; }

  /* Title band */
  .title-band    { padding: 7pt 18pt 6pt !important; border-bottom-width: 1pt !important; }
  .dept-tag      { font-size: 6.5pt !important; margin-bottom: 2pt !important; }
  .title-band h1 { font-size: 15pt !important; }
  .form-meta     { font-size: 6.5pt !important; gap: 20pt !important; margin-top: 3pt !important; }

  /* Date line */
  .date-line { padding: 3.5pt 18pt !important; font-size: 9pt !important; }

  /* Form body */
  .form-body {
    padding: 13pt 22pt 10pt !important;
    flex: 1 !important;
    display: flex !important; flex-direction: column !important;
    overflow: hidden !important; min-height: 0 !important;
  }

  /* Paragraphs */
  .waiver-para {
    font-size: 10pt !important;
    line-height: 1.75 !important;
    margin-bottom: 6pt !important;
    text-indent: 2em !important;
  }
  .fill-val    { text-underline-offset: 1.5pt !important; }
  .fill-blank  { height: 1em !important; margin-bottom: -0.1em !important; }
  .company-note { font-size: 7.5pt !important; }

  /* Spacer still flexible */
  .body-spacer { flex: 1 !important; min-height: 0 !important; }

  /* ── Signature section ── */
  .sig-section {
    border-top-width: 1.5pt !important;
    padding-top: 8pt !important; margin-top: 6pt !important;
    flex-shrink: 0 !important;
    page-break-inside: avoid !important; break-inside: avoid !important;
  }
  .sig-section-title { font-size: 6.5pt !important; margin-bottom: 8pt !important; }

  .sig-row {
    display: flex !important; flex-direction: row !important;
    align-items: flex-end !important;
    gap: 12pt !important;
    margin-bottom: 14pt !important;
  }
  .sig-row:last-child { margin-bottom: 0 !important; }

  .sig-cell-name {
    display: flex !important; flex-direction: column !important;
    justify-content: flex-end !important; flex: 0 0 44% !important;
  }
  .sig-cell-sig {
    display: flex !important; flex-direction: column !important;
    justify-content: flex-end !important; flex: 0 0 32% !important;
  }
  .sig-cell-date {
    display: flex !important; flex-direction: column !important;
    justify-content: flex-end !important; flex: 1 1 0 !important;
  }

  .sig-label {
    font-size: 6pt !important; margin-bottom: 3pt !important;
    display: block !important; white-space: nowrap !important;
  }
  .sig-name {
    font-size: 10pt !important; min-height: 20pt !important;
    padding: 1pt 2pt 2pt !important; border-bottom-width: 0.75pt !important;
    width: 100% !important; display: block !important;
  }
  .sig-line {
    min-height: 20pt !important; border-bottom-width: 0.75pt !important;
    width: 100% !important; display: block !important;
  }

  /* ── Acknowledgement ── */
  .ack-block {
    border-top-width: 1.5pt !important;
    padding-top: 8pt !important; margin-top: 8pt !important;
    flex-shrink: 0 !important;
    page-break-inside: avoid !important; break-inside: avoid !important;
  }
  .ack-title { font-size: 8pt !important; margin-bottom: 7pt !important; }

  .ack-row {
    display: flex !important; flex-direction: row !important;
    align-items: flex-end !important;
    gap: 18pt !important; margin-bottom: 8pt !important;
  }
  .ack-row:last-child { margin-bottom: 0 !important; }
  .ack-field {
    display: flex !important; flex-direction: column !important;
    justify-content: flex-end !important; flex: 1 1 0 !important;
  }
  .ack-label {
    font-size: 6pt !important; margin-bottom: 1.5pt !important;
    display: block !important; white-space: nowrap !important;
  }
  .ack-val {
    font-size: 10pt !important; min-height: 19pt !important;
    padding: 1pt 2pt !important; border-bottom-width: 0.75pt !important;
    width: 100% !important; display: block !important;
  }

  /* Footer */
  .footer-band {
    padding: 4pt 18pt !important; font-size: 6.5pt !important;
    border-top-width: 1pt !important; flex-shrink: 0 !important;
  }
}

/* ══════════════════════════════════════════════════════════
   RESPONSIVE — narrow screens stack cells vertically
══════════════════════════════════════════════════════════ */
@media (max-width: 840px) {
  .doc-outer { width: 100%; height: auto; margin: 0; overflow: visible; }
  .doc-paper { height: auto; overflow: visible; }
  .sig-row   { flex-direction: column; align-items: stretch; gap: 8px; }
  .sig-cell-name,
  .sig-cell-sig,
  .sig-cell-date { flex: none; width: 100%; }
  .ack-row   { flex-direction: column; align-items: stretch; gap: 8px; }
  .form-body, .letterhead { padding: 12px 14px; }
}
</style>
</head>
<body>

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
      <div class="lh-uni">Nueva Ecija University of Science and Technology</div>
      <div class="lh-addr">Cabanatuan City, Nueva Ecija</div>
    </div>
  </div>

  <!-- ══ TITLE BAND ══ -->
  <div class="title-band">
    <div class="dept-tag">On-the-Job Training and Career Development Center</div>
    <h1>Waiver and Permission Form</h1>
    <div class="form-meta">
      <span>Form No.: NEUST-OJT-F003</span>
      <span>Effectivity: 01.08.2025</span>
    </div>
  </div>

  <!-- ══ DATE LINE ══ -->
  <div class="date-line">Date: <span>' . $today . '</span></div>

  <!-- ══ FORM BODY ══ -->
  <div class="form-body">

    <p class="waiver-para">
      This is to certify that I am permitting ' . $f_name . ' to undergo an On&ndash;the&ndash;Job Training program for a total of ' . $f_hours . ' hours starting on ' . $f_start . ' until ' . $f_end . ', at ' . $f_company_with_note . ', in partial fulfillment of the requirements for the degree of Bachelor of ' . $f_course . ' in ' . $f_major . '.
    </p>

    <p class="waiver-para">
      I/We further understand that he/she should strictly observe the rules and regulations of ' . $f_company . ' ' . $company_note . ' and NEUST, On&ndash;the&ndash;Job Training and Career Development Center, in relation to the said training.
    </p>

    <p class="waiver-para">
      I/We hereby agree to waive the responsibility on the part of the Nueva Ecija University of Science and Technology in relation to any loss, damage, death, injury or accident that may happen to him/her during the said On&ndash;the&ndash;Job Training, unless such loss, damage, injury, accident or death resulted from the fault or gross negligence of NEUST On&ndash;the&ndash;Job Training and Career Development Center.
    </p>

    <p class="waiver-para">
      I/We also hereby agree to hold render NEUST On&ndash;the&ndash;Job Training and Career Development Center free and harmless, including its officers, employees or agents, from any liability, suit or claim filed or made by any party for any injury (including death) or damage to property that my son/daughter may cause due to his/her willful acts, fault or negligence, whether or not the same arises from or is related to his/her On&ndash;the&ndash;Job Training Program.
    </p>

    <p class="waiver-para">
      I/We have likewise read the On&ndash;the&ndash;Job Training Program Waiver Form signed by my son/daughter and is fully agreeable with all the things stated thereon.
    </p>

    <!-- Flexible spacer — pushes signature + ack to the bottom -->
    <div class="body-spacer"></div>

    <!-- ══ SIGNATURE SECTION ══
         Three rows: Mother / Father / Guardian
         Each row: [Name 44%] [Signature 32%] [Date rest]
         align-items:flex-end  all underlines share one baseline
         margin-bottom:16px (14pt in print) between rows
    -->
    <div class="sig-section">
      <div class="sig-section-title">Parent / Guardian Signatures</div>

      <!-- Mother row -->
      <div class="sig-row">
        <div class="sig-cell-name">
          <span class="sig-label">Name of Mother / Guardian</span>
          <div class="' . $sig_mother_class . '">' . $sig_mother_content . '</div>
        </div>
        <div class="sig-cell-sig">
          <span class="sig-label">Signature</span>
          <div class="sig-line"></div>
        </div>
        <div class="sig-cell-date">
          <span class="sig-label">Date</span>
          <div class="sig-line"></div>
        </div>
      </div>

      <!-- Father row -->
      <div class="sig-row">
        <div class="sig-cell-name">
          <span class="sig-label">Name of Father / Guardian</span>
          <div class="' . $sig_father_class . '">' . $sig_father_content . '</div>
        </div>
        <div class="sig-cell-sig">
          <span class="sig-label">Signature</span>
          <div class="sig-line"></div>
        </div>
        <div class="sig-cell-date">
          <span class="sig-label">Date</span>
          <div class="sig-line"></div>
        </div>
      </div>

      <!-- Guardian row -->
      <div class="sig-row">
        <div class="sig-cell-name">
          <span class="sig-label">Name of Guardian</span>
          <div class="' . $sig_guardian_class . '">' . $sig_guardian_content . '</div>
        </div>
        <div class="sig-cell-sig">
          <span class="sig-label">Signature</span>
          <div class="sig-line"></div>
        </div>
        <div class="sig-cell-date">
          <span class="sig-label">Date</span>
          <div class="sig-line"></div>
        </div>
      </div>
    </div>

    <!-- ══ ACKNOWLEDGEMENT ══
         Row 1: [Name of Student] [Home Address]
         Row 2: [Telephone No.]   [Mobile No.]
    -->
    <div class="ack-block">
      <div class="ack-title">ACKNOWLEDGEMENT</div>

      <div class="ack-row">
        <div class="ack-field">
          <span class="ack-label">Name of Student:</span>
          <span class="' . $ack_cls_name . '">' . $ack_name . '</span>
        </div>
        <div class="ack-field">
          <span class="ack-label">Home Address:</span>
          <span class="' . $ack_cls_address . '">' . $ack_address . '</span>
        </div>
      </div>

      <div class="ack-row">
        <div class="ack-field">
          <span class="ack-label">Telephone No.:</span>
          <span class="' . $ack_cls_tel . '">' . $ack_tel . '</span>
        </div>
        <div class="ack-field">
          <span class="ack-label">Mobile No.:</span>
          <span class="' . $ack_cls_mobile . '">' . $ack_mobile . '</span>
        </div>
      </div>
    </div>

  </div><!-- end .form-body -->

  <!-- ══ FOOTER ══ -->
  <div class="footer-band">
    <span>NEUST-OJT-F003</span>
    <span>Rev. 02 (01.08.2025)</span>
  </div>

</div></div>

<script>
function savePDF() {
    var studentName = ' . $fullname_json . ';
    var filename    = studentName + \' - Waiver and Permission Form.pdf\';
    var btn         = document.querySelector(\'.tbtn-primary[onclick="savePDF()"]\');

    if (btn) {
        btn.disabled = true;
        btn.textContent = \'Generating...\';
    }

    var paper = document.querySelector(\'.doc-paper\');

    var options = {
        scale: 2,
        useCORS: true,
        allowTaint: true,
        logging: false,
        backgroundColor: \'#ffffff\'
    };

    html2canvas(paper, options).then(function(canvas) {
        var imgData = canvas.toDataURL(\'image/jpeg\', 0.98);

        var { jsPDF } = window.jspdf;
        var pdf = new jsPDF(\'p\', \'mm\', \'a4\');
        var imgWidth = 210;
        var imgHeight = (canvas.height * imgWidth) / canvas.width;

        pdf.addImage(imgData, \'JPEG\', 0, 0, imgWidth, imgHeight);
        pdf.save(filename);

        if (btn) {
            btn.disabled = false;
            btn.textContent = \'📄 Save as PDF\';
        }
    }).catch(function(error) {
        console.error(\'PDF Generation Error:\', error);
        if (btn) {
            btn.disabled = false;
            btn.textContent = \'📄 Save as PDF\';
        }
    });
}
</script>
</body>
</html>';
    }

} // end if (!function_exists('buildWAIVERFormHTML'))