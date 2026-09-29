<?php
/**
 * CONTRACT_form_builder.php
 *
 * Provides buildCONTRACTFormHTML(array $s): string
 *
 * $s keys expected:
 *   first_name, middle_name, last_name,
 *   company,
 *   signed_day, signed_month, signed_year, signed_place,
 *   res_cert_no, issued_at, issued_on,
 *   mother_first, mother_middle, mother_last,
 *   father_first, father_middle, father_last,
 *   guardian_type, guardian_other,
 *   school_representative (full name of OJT coordinator)
 *
 * ── PDF SAVING APPROACH ──
 * "Save as PDF" uses html2canvas + jsPDF (same as ACCOM_form_builder.php).
 * "Print" still calls window.print() for the browser print dialog.
 */

if (!function_exists('buildCONTRACTFormHTML')) {

    function buildCONTRACTFormHTML(array $s): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

        /* ── Core student / contract fields ── */
        $first_name  = $esc($s['first_name']  ?? '');
        $middle_name = $esc($s['middle_name'] ?? '');
        $last_name   = $esc($s['last_name']   ?? '');
        $fullname    = trim($first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name);

        $company        = $esc($s['company']        ?? '');
        $signed_day     = $esc($s['signed_day']     ?? '');
        $signed_month   = $esc($s['signed_month']   ?? '');
        $signed_year    = $esc($s['signed_year']    ?? '');
        $signed_place   = $esc($s['signed_place']   ?? '');
        $res_cert_no    = $esc($s['res_cert_no']    ?? '');
        $issued_at      = $esc($s['issued_at']      ?? '');
        $issued_on      = $esc($s['issued_on']      ?? '');
        $school_rep     = $esc($s['school_representative'] ?? '');

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

        $today = date('F d, Y');

        /* ── Standard fill-span builder (keeps underlines — used for signing line fields) ── */
        $fill = function(string $value, string $width = '12em') use ($esc): string {
            if ($value !== '') {
                return '<span class="fill-val">' . $value . '</span>';
            }
            return '<span class="fill-blank" style="min-width:' . $width . '"></span>';
        };

        /* ── No-underline fill builder (used for name and company only) ── */
        $fill_plain = function(string $value, string $width = '12em') use ($esc): string {
            if ($value !== '') {
                return '<span class="fill-val fill-val-plain">' . $value . '</span>';
            }
            return '<span class="fill-blank fill-blank-plain" style="min-width:' . $width . '"></span>';
        };

        $f_name    = $fill_plain($fullname,  '18em');
        $f_company = $fill_plain($company,   '16em');
        $f_day     = $fill($signed_day,   '5em');
        $f_month   = $fill($signed_month, '9em');
        $f_year    = $fill($signed_year,  '6em');
        $f_place   = $fill($signed_place, '12em');

        $f_res_cert  = $fill($res_cert_no, '12em');
        $f_issued_at = $fill($issued_at,   '12em');
        $f_issued_on = $fill($issued_on,   '12em');

        /* ── Signature line classes & content ── */
        $sig_guardian_class   = ($guardian_full !== '') ? 'sig-name filled' : 'sig-name';
        $sig_guardian_content = ($guardian_full !== '') ? htmlspecialchars($guardian_full, ENT_QUOTES) : '&nbsp;';

        $sig_rep_class   = ($school_rep !== '') ? 'sig-name filled' : 'sig-name';
        $sig_rep_content = ($school_rep !== '') ? $school_rep : '&nbsp;';

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Student/University Contract &mdash; ' . $fullname . '</title>
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

.contract-para {
  font-family: "DM Sans", sans-serif;
  font-size: 12.5px; line-height: 1.75;
  color: var(--text); text-align: justify;
  margin-bottom: 6px; word-break: break-word;
}
.contract-para.indent { text-indent: 2.4em; }

/* Default fill-val: bold + underline (used for signing line fields) */
.fill-val {
  font-weight: 700; text-decoration: underline;
  text-underline-offset: 2px; color: var(--text);
}
/* Default fill-blank: border-bottom underline (used for signing line empty fields) */
.fill-blank {
  display: inline-block; border-bottom: 1.5px solid #555;
  vertical-align: baseline; height: 1.1em; margin-bottom: -0.15em;
}

/* Plain overrides: no underline — applied to name and company only */
.fill-val-plain {
  text-decoration: none !important;
  text-underline-offset: unset !important;
}
.fill-blank-plain {
  border-bottom: none !important;
}

/* ── Bullet Terms ── */
.terms-list {
  list-style: none;
  margin: 6px 0 8px 0;
  padding: 0;
}
.terms-list li {
  font-family: "DM Sans", sans-serif;
  font-size: 12.5px; line-height: 1.72;
  color: var(--text); text-align: justify;
  padding-left: 1.4em;
  position: relative;
  margin-bottom: 4px;
}
.terms-list li::before {
  content: "\2013";
  position: absolute; left: 0;
  font-weight: 600; color: var(--navy);
}

/* ── Signing line ── */
.signing-row {
  margin: 6px 0 4px;
  font-family: "DM Sans", sans-serif;
  font-size: 12.5px; line-height: 1.75;
  color: var(--text);
}

/* ── Applicant signature block (above Res. Cert) ── */
.applicant-sig-block {
  margin: 0 0 6px;
  font-family: "DM Sans", sans-serif;
}

/* ── Sig + Cert right-aligned row ── */
.sig-cert-row {
  display: flex;
  flex-direction: row;
  align-items: flex-start;
  margin: 14px 0 0;
}
.sig-cert-spacer { flex: 1; }
.sig-cert-right {
  width: 220px;
  flex-shrink: 0;
}
.sig-field-narrow {
  width: 100% !important;
}

/* ── Printed-name label sitting below a sig-line ── */
.sig-printed-name {
  font-family: "DM Sans", sans-serif;
  font-size: 12px; font-weight: 700;
  color: var(--navy);
  text-align: center;
  display: block;
  margin-top: 2px;
  min-height: 16px;
  letter-spacing: .01em;
}
.sig-printed-name.empty {
  color: transparent; /* keep vertical space even when blank */
}

/* ── Resident Cert block ── */
.cert-block {
  margin-top: 6px;
  font-family: "DM Sans", sans-serif;
  font-size: 12px; color: var(--text);
  line-height: 1.9;
}
.cert-block .cert-row {
  display: flex; align-items: baseline; gap: 4px;
}
.cert-label {
  font-weight: 600; white-space: nowrap; flex-shrink: 0;
}
/* stretch the fill value / blank underline to the right edge */
.cert-block .cert-row .fill-val,
.cert-block .cert-row .fill-blank {
  flex: 1;
  min-width: 0;
  border-bottom: 1.5px solid #555;
  display: block;
}
.cert-block .cert-row .fill-val {
  font-weight: 700; text-decoration: none;
}

.body-spacer { flex: 1; min-height: 0; max-height: 28px; }

/* ══════════════════════════════════════════════════════════
   CONSENT / SIGNATURE SECTION
   Two columns side-by-side:
     Left  → Parent / Guardian
     Right → School Representative
══════════════════════════════════════════════════════════ */
.consent-section {
  border-top: 2px solid var(--navy);
  padding-top: 10px; margin-top: 10px; flex-shrink: 0;
}
.consent-title {
  font-family: "JetBrains Mono", monospace;
  font-size: 8.5px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .12em;
  color: var(--navy); margin-bottom: 10px;
}

.consent-cols {
  display: flex; flex-direction: row;
  gap: 28px; align-items: flex-end;
}
.consent-col {
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
.sig-sublabel {
  font-family: "DM Sans", sans-serif;
  font-size: 11px; color: var(--muted); text-align: center;
  margin-top: 3px; font-style: italic; display: block;
}
.sig-line {
  border-bottom: 1.5px solid #999;
  min-height: 26px; padding: 2px 3px;
  width: 100%; display: block; margin-bottom: 2px;
}

/* ── Signature field: line with label centered inside it ── */
.sig-field {
  position: relative;
  border-bottom: 1.5px solid #999;
  min-height: 32px;
  width: 100%;
  display: block;
  margin-bottom: 2px;
}
.sig-field-label {
  font-family: "JetBrains Mono", monospace;
  font-size: 8px; font-weight: 500;
  text-transform: uppercase; letter-spacing: .09em;
  color: #bbb;
  position: absolute;
  bottom: 4px;
  left: 0; right: 0;
  text-align: center;
  white-space: nowrap;
  pointer-events: none;
}

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

  .letterhead {
    padding: 10pt 18pt !important; gap: 11pt !important;
    border-bottom-width: 2.5pt !important; flex-shrink: 0 !important;
  }
  .lh-seal     { width: 44pt !important; height: 44pt !important; }
  .lh-uni      { font-size: 12.5pt !important; }
  .lh-republic { font-size: 6pt !important; margin-bottom: 3pt !important; }
  .lh-addr     { font-size: 7.5pt !important; margin-top: 2pt !important; }

  .title-band    { padding: 7pt 18pt 6pt !important; border-bottom-width: 1pt !important; }
  .dept-tag      { font-size: 6.5pt !important; margin-bottom: 2pt !important; }
  .title-band h1 { font-size: 15pt !important; }
  .form-meta     { font-size: 6.5pt !important; gap: 20pt !important; margin-top: 3pt !important; }

  .date-line { padding: 3.5pt 18pt !important; font-size: 9pt !important; }

  .form-body {
    padding: 13pt 22pt 10pt !important;
    flex: 1 !important;
    display: flex !important; flex-direction: column !important;
    overflow: hidden !important; min-height: 0 !important;
  }

  .contract-para {
    font-size: 9.5pt !important;
    line-height: 1.7 !important;
    margin-bottom: 5pt !important;
  }
  .terms-list li {
    font-size: 9.5pt !important;
    line-height: 1.68 !important;
    margin-bottom: 3pt !important;
  }
  .fill-val    { text-underline-offset: 1.5pt !important; }
  .fill-blank  { height: 1em !important; margin-bottom: -0.1em !important; }

  .signing-row { font-size: 9.5pt !important; margin: 5pt 0 3pt !important; }

  .applicant-sig-block { margin: 0 0 5pt !important; }

  .sig-cert-row   { display: flex !important; flex-direction: row !important; margin: 10pt 0 0 !important; }
  .sig-cert-spacer{ flex: 1 !important; }
  .sig-cert-right { width: 165pt !important; flex-shrink: 0 !important; }

  .sig-printed-name { font-size: 9pt !important; margin-top: 1.5pt !important; min-height: 12pt !important; }

  .cert-block  { font-size: 9pt !important; }
  .cert-block .cert-row .fill-val,
  .cert-block .cert-row .fill-blank { flex: 1 !important; min-width: 0 !important; border-bottom-width: 0.75pt !important; display: block !important; }

  .body-spacer { flex: 1 !important; min-height: 0 !important; max-height: 20pt !important; }

  .consent-section {
    border-top-width: 1.5pt !important;
    padding-top: 8pt !important; margin-top: 8pt !important;
    flex-shrink: 0 !important;
    page-break-inside: avoid !important; break-inside: avoid !important;
  }
  .consent-title { font-size: 6.5pt !important; margin-bottom: 8pt !important; }

  .consent-cols {
    display: flex !important; flex-direction: row !important;
    align-items: flex-end !important; gap: 22pt !important;
  }
  .consent-col {
    display: flex !important; flex-direction: column !important;
    justify-content: flex-end !important; flex: 1 1 0 !important;
  }

  .sig-label      { font-size: 6pt !important; margin-bottom: 3pt !important; display: block !important; }
  .sig-name       { font-size: 10pt !important; min-height: 20pt !important; padding: 1pt 2pt 2pt !important; border-bottom-width: 0.75pt !important; width: 100% !important; display: block !important; }
  .sig-sublabel   { font-size: 8pt !important; margin-top: 2pt !important; }
  .sig-line       { min-height: 20pt !important; border-bottom-width: 0.75pt !important; width: 100% !important; display: block !important; }
  .sig-field      { min-height: 26pt !important; border-bottom-width: 0.75pt !important; width: 100% !important; display: block !important; }
  .sig-field-label{ font-size: 6pt !important; bottom: 3pt !important; }

  .footer-band {
    padding: 4pt 18pt !important; font-size: 6.5pt !important;
    border-top-width: 1pt !important; flex-shrink: 0 !important;
  }
}

/* ══ RESPONSIVE ══ */
@media (max-width: 840px) {
  .doc-outer  { width: 100%; height: auto; margin: 0; overflow: visible; }
  .doc-paper  { height: auto; overflow: visible; }
  .consent-cols { flex-direction: column; gap: 12px; }
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
    <h1>Student / University Contract</h1>
    <div class="form-meta">
      <span>Form No.: NEUST-OJT-F004</span>
      <span>Effectivity: 01.08.2025</span>
    </div>
  </div>

  <!-- ══ DATE LINE ══ -->
  <div class="date-line">Date: <span>' . $today . '</span></div>

  <!-- ══ FORM BODY ══ -->
  <div class="form-body">

    <p class="contract-para indent">
      I, ' . $f_name . ', a student&ndash;trainee, hereby agree to undergo On&ndash;the&ndash;Job Training, which is an academic requirement for graduation, at ' . $f_company . ' under the following terms and conditions:
    </p>

    <ul class="terms-list">
      <li>That I shall abide by the corporation&rsquo;s rules and regulations and comply with those imposed for the program; otherwise, I shall be excluded from further participation;</li>
      <li>That there is no employer&ndash;employee relationship between me and the Company/Local Government Unit;</li>
      <li>That I shall exercise care and diligence in any task assigned to me;</li>
      <li>That I renounce and waive any claim against the Nueva Ecija University of Science and Technology for any injury that I may sustain or loss that I suffer, personal or pecuniary; in the performance of my duties or functions while under training; and,</li>
      <li>That I shall be made answerable for any and all liabilities for damage to property or injury to third person, which may be occasioned, by my intentional or negligent acts while in the course of my training.</li>
    </ul>

    <p class="signing-row">
      Signed on this ' . $f_day . ' of ' . $f_month . ', ' . $f_year . ' at ' . $f_place . ', Philippines.
    </p>

    <!-- ══ SIGNATURE + CERT BLOCK (right-aligned) ══ -->
    <div class="sig-cert-row">
      <div class="sig-cert-spacer"></div>
      <div class="sig-cert-right">
        <div class="applicant-sig-block">
          <div class="sig-field sig-field-narrow">
            <span class="sig-field-label">Signature of Applicant</span>
          </div>
        </div>
        <div class="cert-block">
          <div class="cert-row">
            <span class="cert-label">Res. Cert No.:</span>
            ' . $f_res_cert . '
          </div>
          <div class="cert-row">
            <span class="cert-label">Issued at:</span>
            ' . $f_issued_at . '
          </div>
          <div class="cert-row">
            <span class="cert-label">Issued on:</span>
            ' . $f_issued_on . '
          </div>
        </div>
      </div>
    </div>

    <div class="body-spacer"></div>

    <!-- ══ CONSENT / SIGNATURE SECTION ══ -->
    <div class="consent-section">
      <div class="consent-title">With Our Consent and Approval:</div>

      <div class="consent-cols">

        <!-- Left: Signature of Parent/Guardian (label centered on line) → printed name below -->
        <div class="consent-col">
          <div>
            <div class="sig-field">
              <span class="sig-field-label">Signature of Parent or Guardian</span>
            </div>
            <span class="sig-printed-name' . ($guardian_full !== '' ? '' : ' empty') . '">' . $sig_guardian_content . '</span>
            <span class="sig-label" style="display:block;text-align:center;margin-top:1px;">Name of Parent or Guardian</span>
          </div>
        </div>

        <!-- Right: Signature of School Representative (label centered on line) → printed name below -->
        <div class="consent-col">
          <div>
            <div class="sig-field">
              <span class="sig-field-label">Signature of School Representative</span>
            </div>
            <span class="sig-printed-name' . ($school_rep !== '' ? '' : ' empty') . '">' . $sig_rep_content . '</span>
            <span class="sig-label" style="display:block;text-align:center;margin-top:1px;">Name of School Representative</span>
          </div>
        </div>

      </div>
    </div>

  </div><!-- end .form-body -->

  <!-- ══ FOOTER ══ -->
  <div class="footer-band">
    <span>NEUST-OJT-F004</span>
    <span>Rev. 02 (01.08.2025)</span>
  </div>

</div></div>

<script>
function savePDF() {
    var studentName = ' . json_encode($fullname ?: 'Student') . ';
    var filename    = studentName + \' - Student University Contract.pdf\';
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

} // end if (!function_exists('buildCONTRACTFormHTML'))