<?php
/**
 * MOA_form_builder.php
 *
 * Provides buildMOAFormHTML(array $s): string
 *
 * Call this file with:
 *   require_once 'MOA_form_builder.php';
 *
 * Then call:
 *   echo buildMOAFormHTML($moa_data);
 *
 * $moa_data keys expected:
 *   moa_number          – MOA number (e.g. "001")
 *   moa_year            – Year (e.g. "2025")
 *   company_name        – Name of the training institution / company
 *   company_description – (DEPRECATED — accepted but no longer printed; the
 *                         official NEUST MOA template has no company-profile
 *                         clause. Kept so existing callers do not break.)
 *   company_address     – Principal office address
 *   contact_first_name   – First name of the company's representative/contact
 *   contact_middle_name  – Middle name of the company's representative/contact
 *   contact_last_name    – Last name of the company's representative/contact
 *   representative_position – Position/title of the representative (e.g. "Manager")
 *   signing_date        – Date the agreement is signed (free text, e.g. "June 19, 2025")
 *   signing_place       – Place of signing (e.g. "Cabanatuan City")
 *   notary_city          – City for acknowledgment section
 *   company_id           – ID number of company representative
 *   neust_id             – (optional) NEUST ID No. of the University President
 *                          in the Acknowledgment. Defaults to "228" per the
 *                          official template.
 *   witness_name         – (optional) Name of the OJT-CDC Director printed under
 *                          "Signed in the presence of". Defaults to
 *                          "RANDY M. BAÑEZ, J.D.".
 *
 * ── ALIGNMENT WITH OFFICIAL TEMPLATE (Rev. 02, 09.01.2026) ──
 *   • Company-profile clause removed from the first-party paragraph.
 *   • Acknowledgment page count is written in words + figures and is
 *     auto-synced by the JS engine to the actual number of rendered pages
 *     (defaults to "four (4)").
 *   • NEUST ID No. filled (228); notarial "Series of" left blank for the notary.
 *   • Signature line added above the witness (OJT-CDC Director).
 *   • Footer: "Page X of Y" + Rev. 02 (09.01.2026); page 1 carries the
 *     "Director, OJT-CDC" initial line.
 *
 * No other dependencies — safe to include from any page.
 *
 * ── PDF SAVING APPROACH ──
 * Uses the Unified Layout Partition Core Engine (same as weekly_report_form_builder.php):
 * content is measured and distributed across rigid 794×1123px page divs.
 * Each page is individually captured by html2canvas → assembled into jsPDF.
 * "Print" calls window.print() which uses CSS @page rules.
 *
 * ── LAYOUT STRATEGY ──
 * All sizing is in pt. A4 = 595pt × 842pt → 794px × 1123px at 96dpi.
 * One layout for both screen and print. Pages are rigid containers.
 */

if (!function_exists('buildMOAFormHTML')) {

    function buildMOAFormHTML(array $s): string {
        $esc = fn($v) => htmlspecialchars(trim((string)($v ?? '')), ENT_QUOTES);

        /* ── Raw values ── */
        $moa_number   = $esc($s['moa_number']   ?? '___');
        $moa_year     = $esc($s['moa_year']      ?? '202___');
        $company      = $esc($s['company_name']  ?? '');
        $company_desc = $esc($s['company_description'] ?? '');
        $company_addr = $esc($s['company_address'] ?? '');
        /* ── Robust key resolution for the contact/representative name parts ──
           Checks several common key-name variants (in order) and takes the
           first one that is actually present and non-blank. This protects
           against a naming mismatch upstream (e.g. camelCase vs snake_case,
           or an older key name) silently causing a field to look "missing"
           when it actually has a value. Each of the three parts below is
           resolved and flagged independently of the other two. */
        $pick = function(array $keys) use ($s) {
            foreach ($keys as $k) {
                if (!array_key_exists($k, $s)) continue;
                $v = trim((string)($s[$k] ?? ''));
                if ($v === '' || strcasecmp($v, 'null') === 0) continue;
                return $v;
            }
            return '';
        };

        $rep_first  = $esc($pick(['contact_first_name', 'contactFirstName', 'first_name', 'firstname', 'representative_first_name']));
        $rep_middle = $esc($pick(['contact_middle_name', 'contactMiddleName', 'middle_name', 'middlename', 'representative_middle_name']));
        $rep_last   = $esc($pick(['contact_last_name', 'contactLastName', 'last_name', 'lastname', 'representative_last_name']));
        $rep_pos      = $esc($s['representative_position'] ?? 'Manager/Head/Director');
        $sign_date    = $esc($s['signing_date']  ?? '');
        $sign_place   = $esc($s['signing_place'] ?? '');
        $notary_city  = $esc($s['notary_city']   ?? '');
        $company_id   = $esc($s['company_id']    ?? '');
        $neust_id     = $esc(($s['neust_id'] ?? '') !== '' ? $s['neust_id'] : '228');
        $witness_name = $esc(($s['witness_name'] ?? '') !== '' ? $s['witness_name'] : 'RANDY M. BAÑEZ, J.D.');

        /* ── Empty-state helpers ── */
        $e = fn($v) => ($v === '' || $v === '___' || $v === '202___') ? ' empty' : '';
        $d = fn($v, $fallback = '—') => ($v !== '' && $v !== '___' && $v !== '202___') ? $v : $fallback;
        /* UPDATED: blank "______" placeholders already draw their own line,
           so they get " blank" (no extra border-bottom) to avoid a double line. */
        $bl = fn($v) => ($v === '' || $v === '___' || $v === '202___') ? ' blank' : '';

        /* ── MOA No. display ── */
        $moa_no_display = 'MOA No. ' . $d($moa_number, '___') . ', s.' . $d($moa_year, '202___');

        /* ── Reusable contact-name markup (first / middle / last) ──
           Each part follows the exact same empty-state pattern used for
           company_name: a bracketed placeholder in the ".fill.empty" style
           when the value is missing, instead of being hidden/removed. */
        $rep_first_markup  = '<span class="fill' . $e($rep_first)  . '">' . $d($rep_first,  '[FIRST NAME]')  . '</span>';
        $rep_middle_markup = '<span class="fill' . $e($rep_middle) . '">' . $d($rep_middle, '[MIDDLE NAME]') . '</span>';
        $rep_last_markup   = '<span class="fill' . $e($rep_last)   . '">' . $d($rep_last,   '[LAST NAME]')   . '</span>';
        $rep_full_markup   = $rep_first_markup . ' ' . $rep_middle_markup . ' ' . $rep_last_markup;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MOA — {$company}</title>
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

@page { size: A4 portrait; margin: 0; }

/* ── Screen Chrome ── */
body {
  font-family: 'DM Sans', sans-serif;
  background: #d8dde8;
  color: var(--text);
  margin: 0; padding: 0;
}

/* ── Document Wrapper ── */
.doc-outer {
  width: 794px;
  margin: 20px auto 36px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* ── Each page is a rigid physical container ── */
.doc-paper {
  background: var(--white);
  border: 1px solid #b0b8cc;
  box-shadow: 0 4px 32px rgba(0,0,0,.22);
  width: 794px;
  height: 1123px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-sizing: border-box;
}

/* ── Letterhead ── */
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

/* ── Title Band ── */
.title-band {
  background: #f5f6fa;
  border-bottom: 1pt solid #d0d5e8;
  padding: 5pt 14pt 4pt;
  text-align: center; flex-shrink: 0;
}
.dept-tag {
  font-family: 'JetBrains Mono', monospace;
  font-size: 5.5pt; letter-spacing: .14em;
  text-transform: uppercase; color: var(--muted); margin-bottom: 1.5pt;
}
.title-band h1 {
  font-family: 'DM Sans', sans-serif;
  font-size: 12pt; font-weight: 700;
  color: var(--navy); letter-spacing: .03em; text-transform: uppercase;
}
.moa-no {
  font-family: 'JetBrains Mono', monospace;
  font-size: 7.5pt; font-weight: 600;
  color: var(--blue); margin-top: 2pt;
}
.form-meta {
  display: flex; justify-content: center; gap: 18pt; margin-top: 2pt;
  font-family: 'JetBrains Mono', monospace; font-size: 5.5pt; color: #888;
}

/* ── Doc body (scrollable content area within each page) ── */
.doc-body {
  padding: 10pt 22pt 8pt;
  flex: 1;
  font-family: 'Crimson Pro', serif;
  font-size: 9.5pt;
  line-height: 1.65;
  color: var(--text);
  text-align: justify;
  overflow: hidden;
}

/* ── Opening paragraph ── */
.known-all {
  font-weight: 700;
  margin-bottom: 8pt;
  font-size: 9.5pt;
}

/* ── Party blocks ── */
.party-block {
  margin: 6pt 0 6pt 18pt;
  text-indent: -18pt;
  padding-left: 18pt;
}

/* ── Inline fill values ── */
.fill {
  font-family: 'Crimson Pro', serif;
  font-weight: 700;
  color: var(--navy);
  font-style: normal;
  border-bottom: 1px solid var(--navy);
  padding: 0 2pt;
}
.fill.empty {
  color: #bbb;
  border-bottom-color: #ddd;
  font-weight: 400;
  font-style: italic;
}
/* UPDATED: underscore placeholders ("________") — no second underline */
.fill.blank {
  border-bottom: none;
  padding: 0;
  color: var(--text);      /* same as the plain "Doc. No.____" lines */
  font-weight: 400; font-style: normal;
}

/* ── Section titles ── */
.sec-title {
  font-family: 'DM Sans', sans-serif;
  font-size: 9.5pt; font-weight: 700;
  text-align: center; text-decoration: underline;
  text-transform: uppercase; letter-spacing: .04em;
  margin: 10pt 0 5pt;
  color: var(--navy);
}

/* ── Numbered lists ── */
.obligations-list {
  margin: 0 0 4pt 0;
  padding-left: 0;
}
.obligations-list li {
  margin: 3pt 0 3pt 0;
  list-style: none;
  display: flex; gap: 6pt;
  align-items: flex-start;
}
.obligations-list li .num {
  font-weight: 700; flex-shrink: 0;
  min-width: 14pt; color: var(--navy);
}
.obligations-list li .txt { flex: 1; text-align: justify; }

/* ── Party label ── */
.party-label {
  font-weight: 700; font-style: italic;
}

/* ── Witnesseth ── */
.witnesseth {
  text-align: center;
  font-weight: 700;
  font-style: italic;
  margin: 8pt 0 4pt;
  font-size: 9.5pt;
}

/* ── Sub-section ── */
.sub-sec {
  font-weight: 700;
  margin: 7pt 0 4pt;
  font-size: 9.5pt;
}

/* ── Signing block ── */
.sign-block {
  margin-top: 12pt;
}
.sign-intro {
  margin-bottom: 8pt;
}
.sign-columns {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10pt;
  margin-top: 6pt;
}
.sign-col { display: flex; flex-direction: column; align-items: center; }
.sign-col-label {
  font-family: 'DM Sans', sans-serif;
  font-size: 8.5pt; font-weight: 700;
  text-transform: uppercase; letter-spacing: .04em;
  color: var(--navy);
  margin-bottom: 20pt;
  text-align: center;   /* UPDATED: center "FOR THE:" over the second line, like the PDF */
}
.sign-line {
  border-bottom: 1.5px solid var(--text);
  width: 90%;
  margin-bottom: 3pt;
}
.sign-name {
  font-weight: 700; text-align: center;
  font-size: 9.5pt; color: var(--navy);
  text-decoration: underline;
}
.sign-title {
  font-size: 8.5pt; text-align: center;
  color: var(--muted); margin-top: 1pt;
}

.witness-block {
  margin-top: 10pt; text-align: center;
}
.witness-label {
  font-family: 'DM Sans', sans-serif;
  font-size: 8.5pt; font-weight: 600;
  margin-bottom: 8pt;
}
.witness-name {
  font-weight: 700; font-size: 9.5pt;
  text-decoration: underline;
  color: var(--navy);
}
.witness-title {
  font-size: 8.5pt; color: var(--muted);
}

/* ── Acknowledgment ── */
.ack-header {
  font-family: 'DM Sans', sans-serif;
  font-size: 10pt; font-weight: 700;
  text-align: center; text-decoration: underline;
  margin: 6pt 0 8pt;
  letter-spacing: .04em; color: var(--navy);
}
.ack-juris {
  font-size: 9pt; margin-bottom: 2pt;
}

/* ── Footer ── */
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

/* ── Page-1 "Director, OJT-CDC" initial line (raised above the footer band) ──
 *  --initials-lift controls how high the block sits above the footer.
 *  Increase it to move the block higher, decrease it to move it lower.
 *  The pagination engine measures this block, so body text never overlaps it. */
.initials-block {
  --initials-lift: 240pt;
  --initials-right: 55pt;   /* distance from the right edge; increase to move left */
  display: flex; justify-content: flex-end;
  padding: 0 var(--initials-right) var(--initials-lift) 22pt;
  flex-shrink: 0;
}
.initials-inner { text-align: center; }
.initials-line {
  border-bottom: 1px solid var(--text);
  width: 150pt; height: 14pt;   /* initial/signature line length (was 120pt) */
  margin-bottom: 2pt;
}
.initials-label {
  font-family: 'DM Sans', sans-serif;
  font-size: 6.5pt; color: var(--muted);
}

/* ── Witness signature line ── */
.witness-sign-line {
  border-bottom: 1.5px solid var(--text);
  width: 45%;
  margin: 14pt auto 3pt;
}

/* ── Hidden blueprints (source data / header / footer clones) ── */
#raw-source-data,
#header-clone,
#footer-clone,
#initials-clone {
  display: none;
  position: fixed;
  top: 0; left: -9999px;
  pointer-events: none;
}

/* ── Print ── */
@media print {
  html, body {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    background: #fff !important;
    margin: 0 !important; padding: 0 !important;
  }
  .doc-outer {
    margin: 0 !important;
    gap: 0 !important;
  }
  .doc-paper {
    box-shadow: none !important;
    border: none !important;
    page-break-after: always !important;
    break-after: always !important;
  }
}

/* ── Responsive ── */
@media (max-width: 640px) {
  .doc-outer { width: 100%; }
  .sign-columns { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ══ HIDDEN HEADER CLONE (blueprint for every page header) ══ -->
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
    <h1>Memorandum of Agreement</h1>
    <div class="moa-no">{$moa_no_display}</div>
    <div class="form-meta">
      <span>Form No.: NEUST-OJT-F005</span>
      <span>Effectivity: 09.01.2026</span>
    </div>
  </div>
</div>

<!-- ══ HIDDEN FOOTER CLONE (blueprint for every page footer) ══ -->
<div id="footer-clone">
  <div class="footer-band" style="margin-top:0;">
    <span>NEUST-OJT-F005</span>
    <span class="pg-num">Page <span class="pg-cur">1</span> of <span class="pg-total">1</span></span>
    <span>Rev. 02 (09.01.2026)</span>
  </div>
</div>

<!-- ══ HIDDEN PAGE-1 INITIALS CLONE ("Director, OJT-CDC" initial line, first page only) ══ -->
<div id="initials-clone">
  <div class="initials-block">
    <div class="initials-inner">
      <div class="initials-line"></div>
      <div class="initials-label">Director, OJT-CDC</div>
    </div>
  </div>
</div>

<!-- ══ RAW SOURCE DATA (all content segments, measured then distributed) ══ -->
<div id="raw-source-data">

  <!-- SEGMENT: known-all + intro -->
  <div class="moa-seg" data-seg="intro">
    <p class="known-all">KNOWN ALL MEN BY THESE PRESENTS:</p>
    <p style="margin-bottom:6pt;">
      This <strong>Memorandum of Agreement</strong> made and entered by and between:
    </p>
    <div class="party-block">
      <span class="fill{$e($company)}">{$d($company, '[COMPANY NAME]')}</span>,
      an entity duly licensed and registered establishment under the laws of the Philippines,
      with principal office address at
      <span class="fill{$e($company_addr)}">{$d($company_addr, '[COMPANY ADDRESS]')}</span>
      herein represented by its {$rep_pos}
      {$rep_full_markup}
      hereinafter referred to as the <span class="party-label">"TRAINING INSTITUTION"</span>;
    </div>
    <p style="text-align:center; font-weight:700; margin: 5pt 0;">–AND–</p>
    <div class="party-block">
      <strong>NUEVA ECIJA UNIVERSITY OF SCIENCE AND TECHNOLOGY (NEUST)</strong>,
      a chartered state university in accordance with R.A. 8612, with office address at
      General Tinio Street, Cabanatuan City, Nueva Ecija 3100, represented by its
      University President, <strong>DR. RHODORA R. JUGO</strong>,
      hereinafter referred to as the <span class="party-label">"UNIVERSITY"</span>
    </div>
    <p class="witnesseth">–WITNESSETH: That–</p>
    <p style="margin-bottom:5pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;The UNIVERSITY has requested the TRAINING INSTITUTION to
      accommodate its students in the different field of discipline as TRAINEES under the
      On–the–Job Training (OJT) Program as required in the Board-approved curriculum
      they are enrolled in; and
    </p>
    <p style="margin-bottom:5pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;The TRAINING INSTITUTION has agreed to accommodate the TRAINEES
      for their On–the–Job Training (OJT), subject to the terms and conditions specified
      hereunder.
    </p>
    <p style="margin-bottom:8pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;NOW THEREFORE, for and in consideration of the foregoing premises and
      the mutual covenants set forth herein, the parties agree as follows:
    </p>
  </div>

  <!-- SEGMENT: Term -->
  <div class="moa-seg" data-seg="term">
    <p class="sec-title">Term</p>
    <p style="margin-bottom:6pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;This Memorandum of Agreement shall take effect upon signing of both
      parties and shall continue to remain in full force and effect unless sooner revised or
      terminated by either party giving notice to the other at least six (6) months prior to
      the intended date of revision or termination. Such notice of termination will not interfere
      with the cooperative program currently underway. Such programs will be allowed to continue
      until their conclusion.
    </p>
  </div>

  <!-- SEGMENT: University obligations (force-break: always starts on a new page) -->
  <div class="moa-seg" data-seg="university-obligations" data-force-break="true">
    <p class="sec-title">Duties and Obligations</p>
    <p class="sub-sec">A. The UNIVERSITY</p>
    <ol class="obligations-list">
      <li><span class="num">1.</span><span class="txt">To formulate local school practicum policies and guidelines on selection, placement, monitoring and assessment of the <strong>TRAINEES</strong>;</span></li>
      <li><span class="num">2.</span><span class="txt">To pre–qualify the <strong>TRAINEES</strong> in accordance with the school off campus training policies and requirements as specified in CMO No. 25, Series of 2015 and the requirements from the <strong>TRAINING INSTITUTION</strong>;</span></li>
      <li><span class="num">3.</span><span class="txt">To set the criteria on the selection of a Faculty Practicum who is academically qualified and will be responsible as Faculty SIPP Coordinator per program for all the aspects of the student internship programs including program implementation, monitoring and evaluation;</span></li>
      <li><span class="num">4.</span><span class="txt">To monitor, jointly with the <strong>TRAINING INSTITUTION</strong> and evaluate the performance of the TRAINEES based on the prescribed CMO No. 25, Series of 2015;</span></li>
      <li><span class="num">6.</span><span class="txt">To conduct general orientation for the <strong>TRAINEES</strong> and their parents/guardians;</span></li>
      <li><span class="num">7.</span><span class="txt">To conduct initial and regular visit of the <strong>TRAINING INSTITUTION</strong> premises to ensure the safety of the <strong>TRAINEES</strong>;</span></li>
      <li><span class="num">8.</span><span class="txt">To subject the student <strong>TRAINEE</strong> to institutional disciplinary policies for any violations of the guidelines set forth under CMO No. 23 series of 2009 after due investigations conducted in connection thereto; and</span></li>
      <li><span class="num">9.</span><span class="txt">To issue final grade to the student trainee based on the <strong>TRAINEE'S</strong> performance evaluation upon completion of requirements on prescribed period and the concomitant Certificate of Appreciation of the completion of training of the student with the <strong>TRAINING INSTITUTION.</strong></span></li>
    </ol>
  </div>

  <!-- SEGMENT: Training Institution obligations -->
  <div class="moa-seg" data-seg="institution-obligations">
    <p class="sub-sec">B. The TRAINING INSTITUTION</p>
    <ol class="obligations-list">
      <li><span class="num">1.</span><span class="txt">To facilitate the processing of the On-the-Job Training-related documents of the student trainees/interns in coordination with the <strong>UNIVERSITY;</strong></span></li>
      <li><span class="num">2.</span><span class="txt">To provide Supervised Applied Learning Experiences for the student trainees in accordance with agreed Training Manual/Plan and schedule of activities;</span></li>
      <li><span class="num">3.</span><span class="txt">To assign a competent Training Supervisor responsible for the implementation of the relevant phases of the Training Plan;</span></li>
      <li><span class="num">4.</span><span class="txt">To provide safe and conducive working environment/venue for the Trainees which is free from any hazard and will bolster their confidence and develop their skills during the training;</span></li>
      <li><span class="num">5.</span><span class="txt">To allow the University through its duly authorized representative to visit the site where the training program will be held and regularly visit and monitor the same upon prior notice to the concerned office of the <strong>TRAINING INSTITUTION</strong> conducting the training;</span></li>
      <li><span class="num">6.</span><span class="txt">To comply with the specific provisions of the Labor Code of the Philippines and other pertinent laws in accepting Trainees under the OJT Program and afford the Trainees their respective rights under the said laws;</span></li>
      <li><span class="num">7.</span><span class="txt">To immediately inform the University through its authorized representative of any incident during the training program which would expose the Trainees of any harm or injury or violation of their rights;</span></li>
      <li><span class="num">8.</span><span class="txt">To immediately act on the complaints of the Trainees concerning the improper demeanor of their Trainor/s or any complaint concerning violation/s of their rights;</span></li>
      <li><span class="num">9.</span><span class="txt">To comply with the existing rules and regulations involving health protocols implemented by the University, National Government, IATF, Department of Health, CHED, Local Government Unit in the area and provide sufficient health facilities in favor of the TRAINEE. Any violation of such rules and regulations which compromise the safety of the TRAINEE shall be a ground for the termination of the On–the–job Training (OJT) Program;</span></li>
      <li><span class="num">10.</span><span class="txt">To conduct post training review and evaluation of the program and the performance of Trainees together with the <strong>UNIVERSITY</strong>; and</span></li>
      <li><span class="num">11.</span><span class="txt">To issue a <strong>Certificate of Completion</strong> of the <strong>TRAINEE</strong> one (1) week after the completion of the training.</span></li>
    </ol>
  </div>

  <!-- SEGMENT: Adjustments -->
  <div class="moa-seg" data-seg="adjustments">
    <p class="sec-title">Adjustment/s</p>
    <p style="margin-bottom:6pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;Both the <strong>TRAINING INSTITUTION</strong> and the <strong>UNIVERSITY</strong>
      can make the necessary amendments or changes on their duties and obligation to suit the
      prevailing condition and community quarantine in the area/s of trainee's assignment.
    </p>
  </div>

  <!-- SEGMENT: Obligation of the Trainee -->
  <div class="moa-seg" data-seg="trainee-obligation">
    <p class="sec-title">Obligation of the Trainee</p>
    <p style="margin-bottom:5pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;The <strong>TRAINEE</strong> shall be liable to any damages he may cause
      through his fault or negligence such as breakage of <strong>TRAINING INSTITUTION</strong>
      properties after due notice and hearing in accordance with <strong>TRAINING INSTITUTION</strong>
      rules. The University shall ensure that the liable trainee shall fulfill its obligation. Otherwise,
      the University shall cover the unsettled liability of the trainee.
    </p>
    <p style="margin-bottom:5pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;The TRAINEES shall complete the Training Program within the Required OJT Hours.
    </p>
    <p style="margin-bottom:6pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;The <strong>TRAINEE</strong> shall strictly comply with the existing rules
      and regulations involving health protocols implemented by the <strong>TRAINING INSTITUTION</strong>,
      the University, National Government, IATF, Department of Health, CHED, Local Government Unit in
      the area. The trainee shall immediately report to the Company and the University any instance of
      violation or non-compliance with such rules and regulations. Any violation of such rules and
      regulations on the part of the Trainee shall be a ground for the termination of his/her
      On–the–job Training Program.
    </p>
  </div>

  <!-- SEGMENT: Schedule -->
  <div class="moa-seg" data-seg="schedule">
    <p class="sec-title">Schedule</p>
    <p style="margin-bottom:5pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;The TRAINEE shall be observing the following training schedule:
    </p>
    <p style="margin-bottom:5pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;Mondays to Fridays Time: 8:00–5:00pm, without prejudice to a flexible and
      suitable schedule to be agreed upon by the <strong>TRAINING INSTITUTION</strong> and the
      <strong>UNIVERSITY</strong> which may include Saturdays and Sundays;
    </p>
    <p style="margin-bottom:5pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;The training hours shall not exceed eight (8) hours per day.
    </p>
    <p style="margin-bottom:6pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;The <strong>TRAINING INSTITUTION</strong> shall not require the TRAINEE to
      render overtime work or to report for training on legal (regular) or special holidays.
    </p>
  </div>

  <!-- SEGMENT: Monitoring and Evaluation -->
  <div class="moa-seg" data-seg="monitoring">
    <p class="sec-title">Monitoring and Evaluation</p>
    <p style="margin-bottom:6pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;During the conduct of the Training Program, the faculty SIPP Coordinator
      and/or Director of the On–the–Job Training (OJT) and Career Development Centre of the UNIVERSITY
      shall monitor and evaluate the Trainees and will utilize standard procedures, instruments and
      methodologies such as observations, monthly reports, and interviews or conferences with the students.
    </p>
  </div>

  <!-- SEGMENT: Pre-Termination -->
  <div class="moa-seg" data-seg="pretermination">
    <p class="sec-title">Pre-Termination</p>
    <p style="margin-bottom:8pt;">
      &nbsp;&nbsp;&nbsp;&nbsp;Either of the parties upon written notice may pre-terminate the foregoing
      agreement in case of violation of either party of any provision of the foregoing Agreement. In case
      the violation is on the part of the <strong>TRAINING INSTITUTION</strong>, corresponding
      Certification shall be issued in favor of the Trainees despite the termination in accordance with
      the extent of the training undergone by them.
    </p>
  </div>

  <!-- SEGMENT: Signing block -->
  <div class="moa-seg" data-seg="signing">
    <div class="sign-block">
      <p class="sign-intro">
        &nbsp;&nbsp;&nbsp;&nbsp;<strong>IN WITNESS WHEREOF,</strong> the parties have carefully read, fully
        understood and voluntarily agree, to the terms and conditions of this agreement, and have caused
        this agreement to be signed by their duty authorized representatives this
        <span class="fill{$e($sign_date)}{$bl($sign_date)}">{$d($sign_date, '_____________________')}</span>
        hereat
        <span class="fill{$e($sign_place)}{$bl($sign_place)}">{$d($sign_place, '_____________________')}</span>.
      </p>
      <div class="sign-columns">
        <div class="sign-col">
          <div class="sign-col-label">For the:<br>Training Institution</div>
          <div class="sign-line"></div>
          <div class="sign-name">{$rep_full_markup}</div>
          <!-- UPDATED: single line "Company Name (Position)"; the extra line between position and company removed -->
          <div class="sign-title">{$d($company, '[Company Name]')} ({$d($rep_pos, 'Manager/Head/Director')})</div>
        </div>
        <div class="sign-col">
          <div class="sign-col-label">For the:<br>University</div>
          <div class="sign-line"></div>
          <div class="sign-name">RHODORA R. JUGO, EdD</div>
          <div class="sign-title">University President<br>NEUST</div>
        </div>
      </div>
      <div class="witness-block">
        <div class="witness-label">Signed in the presence of:</div>
        <div class="witness-sign-line"></div>
        <div class="witness-name">{$witness_name}</div>
        <div class="witness-title">Director, On-the-Job Training and Career Development Centre<br>
          Nueva Ecija University of Science and Technology</div>
      </div>
    </div>
  </div>

  <!-- SEGMENT: Acknowledgment -->
  <div class="moa-seg" data-seg="acknowledgment">
    <p class="ack-header">Acknowledgment:</p>
    <p class="ack-juris">REPUBLIC OF THE PHILIPPINES)</p>
    <p class="ack-juris">
      <span class="fill{$e($notary_city)}{$bl($notary_city)}">{$d($notary_city, '_____________________________')}</span>
      &nbsp;&nbsp;&nbsp;&nbsp;) S.S.
    </p>
    <p style="margin:3pt 0 6pt 0;">x----------------------------x</p>
    <p style="margin-bottom:5pt;">
      <strong>BEFORE ME,</strong> a notary public duly authorized in the city named above, personally appeared:
    </p>
    <table style="width:100%; border-collapse:collapse; margin-bottom:6pt; font-size:9.5pt;">
      <tr>
        <td style="padding:2pt 4pt; width:55%;">
          {$rep_full_markup}
        </td>
        <td style="padding:2pt 4pt;">
          - ID No. <span class="fill{$e($company_id)}{$bl($company_id)}">{$d($company_id, '________________')}</span>
        </td>
      </tr>
      <tr>
        <td style="padding:2pt 4pt;">
          <strong>RHODORA R. JUGO, EdD</strong>
        </td>
        <td style="padding:2pt 4pt;">
          - NEUST ID No. <span class="fill">{$neust_id}</span>
        </td>
      </tr>
    </table>
    <p style="margin-bottom:5pt;">
      Who are personally known to me, through their competent evidence of identity as above-stated,
      to be the same persons described in the foregoing instrument consisting of <span class="ack-page-count">four (4)</span> pages
      including the page where this acknowledgement is written, who acknowledgment before me that
      their respective signatures on the instrument were voluntarily affixed by them for the purpose
      stated therein, and who declared to me that they have executed the instrument as their free and
      voluntary act and deed.
    </p>
    <p style="margin-bottom:10pt;">
      <strong>WITNESS MY HAND AND SEAL</strong> this
      <span class="fill{$e($sign_date)}{$bl($sign_date)}">{$d($sign_date, '_____________')}</span>,
      hereat
      <span class="fill{$e($sign_place)}{$bl($sign_place)}">{$d($sign_place, '_________________')}</span>.
    </p>
    <div style="display:flex; gap:30pt; font-size:9pt; margin-top:8pt;">
      <div>
        Doc. No._____<br>
        Page No._____<br>
        Book No.______<br>
        Series of ______
      </div>
    </div>
  </div>

</div><!-- end #raw-source-data -->

<!-- ══ LIVE RENDERING TARGET ══ -->
<div class="doc-outer" id="rendering-preview-root"></div>

<script>
/**
 * Unified Layout Partition Core Engine
 * Identical in architecture to weekly_report_form_builder.php.
 * Measures each MOA content segment and distributes them across
 * rigid 794×1123px page divs.
 */
function partitionDocumentContent() {
    var PAGE_W = 794;
    var PAGE_H = 1123;

    var hdrSource = document.getElementById('header-clone');
    var ftrSource = document.getElementById('footer-clone');
    var iniSource = document.getElementById('initials-clone');

    /* ── Measure header height ── */
    var hdrMeasure = hdrSource.cloneNode(true);
    hdrMeasure.style.cssText = 'display:block;position:fixed;top:0;left:-9999px;width:'+PAGE_W+'px;visibility:hidden;';
    document.body.appendChild(hdrMeasure);
    var HDR_H = hdrMeasure.scrollHeight || 115;
    document.body.removeChild(hdrMeasure);

    /* ── Measure footer height ── */
    var ftrMeasure = ftrSource.cloneNode(true);
    ftrMeasure.style.cssText = 'display:block;position:fixed;top:0;left:-9999px;width:'+PAGE_W+'px;visibility:hidden;';
    document.body.appendChild(ftrMeasure);
    var FTR_H = ftrMeasure.scrollHeight || 25;
    document.body.removeChild(ftrMeasure);

    /* ── Measure page-1 initials block height ── */
    var iniMeasure = iniSource.cloneNode(true);
    iniMeasure.style.cssText = 'display:block;position:fixed;top:0;left:-9999px;width:'+PAGE_W+'px;visibility:hidden;';
    document.body.appendChild(iniMeasure);
    var INI_H = iniMeasure.scrollHeight || 30;
    document.body.removeChild(iniMeasure);

    /* ── Usable body height per page (with padding allowance) ── */
    var BODY_PADDING_V = 28; /* approx 10pt top + 8pt bottom in px */
    var USABLE_H = PAGE_H - HDR_H - FTR_H - BODY_PADDING_V;
    /* Page 1 also carries the "Director, OJT-CDC" initial line */
    var USABLE_H_FIRST = USABLE_H - INI_H;

    /* ── Helper: measure a single element ── */
    function measureElement(el) {
        var sandbox = document.createElement('div');
        sandbox.style.cssText = [
            'position:fixed',
            'top:0',
            'left:-9999px',
            'width:' + (PAGE_W - 44) + 'px', /* match doc-body padding: 22pt each side */
            'visibility:hidden',
            'background:#fff',
            'font-family:\'Crimson Pro\',serif',
            'font-size:9.5pt',
            'line-height:1.65',
            'color:#1a2035',
            'text-align:justify'
        ].join(';');
        sandbox.appendChild(el.cloneNode(true));
        document.body.appendChild(sandbox);
        var h = sandbox.scrollHeight;
        document.body.removeChild(sandbox);
        return h;
    }

    /* ── Collect all segments ── */
    var allSegments = document.querySelectorAll('#raw-source-data .moa-seg');

    var pagesContent    = [[]];
    var currentPageIdx  = 0;
    var currentHeight   = 0;

    for (var i = 0; i < allSegments.length; i++) {
        var seg        = allSegments[i];
        var segH       = measureElement(seg);
        var forceBreak = seg.getAttribute('data-force-break') === 'true';

        var pageLimit  = (currentPageIdx === 0) ? USABLE_H_FIRST : USABLE_H;

        /* Force a new page if flagged, OR if segment doesn't fit */
        if ((forceBreak && currentHeight > 0) || (currentHeight + segH > pageLimit && currentHeight > 0)) {
            currentPageIdx++;
            pagesContent[currentPageIdx] = [];
            currentHeight = 0;
        }

        pagesContent[currentPageIdx].push(seg.cloneNode(true));
        currentHeight += segH;
    }

    return pagesContent;
}

/**
 * Builds the accurate live preview on load.
 * One .doc-paper per logical page, with cloned header + footer on each.
 */
function renderLiveSynchronizedPreview() {
    var root = document.getElementById('rendering-preview-root');
    root.innerHTML = '';

    var hdrSource = document.getElementById('header-clone');
    var ftrSource = document.getElementById('footer-clone');

    var pagesContent = partitionDocumentContent();

    for (var p = 0; p < pagesContent.length; p++) {
        var paperPage = document.createElement('div');
        paperPage.className = 'doc-paper';

        /* Header */
        var hDiv = document.createElement('div');
        hDiv.innerHTML = hdrSource.innerHTML;
        paperPage.appendChild(hDiv);

        /* Body content */
        var bDiv = document.createElement('div');
        bDiv.className = 'doc-body';
        for (var i = 0; i < pagesContent[p].length; i++) {
            bDiv.appendChild(pagesContent[p][i].cloneNode(true));
        }
        paperPage.appendChild(bDiv);

        /* Page-1 initials line ("Director, OJT-CDC") */
        if (p === 0) {
            var iDiv = document.createElement('div');
            iDiv.innerHTML = document.getElementById('initials-clone').innerHTML;
            paperPage.appendChild(iDiv);
        }

        /* Footer (with "Page X of Y") */
        var fDiv = document.createElement('div');
        fDiv.innerHTML = ftrSource.innerHTML;
        var cur = fDiv.querySelector('.pg-cur');
        var tot = fDiv.querySelector('.pg-total');
        if (cur) cur.textContent = String(p + 1);
        if (tot) tot.textContent = String(pagesContent.length);
        paperPage.appendChild(fDiv);

        root.appendChild(paperPage);
    }

    /* Sync the Acknowledgment page count with the pages actually rendered */
    var countText = pageCountInWords(pagesContent.length);
    var countEls = root.querySelectorAll('.ack-page-count');
    for (var c = 0; c < countEls.length; c++) {
        countEls[c].textContent = countText;
    }
}

/**
 * Returns the page count written the notarial way, e.g. "four (4)".
 */
function pageCountInWords(n) {
    var words = ['zero','one','two','three','four','five','six','seven','eight','nine','ten',
                 'eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen',
                 'eighteen','nineteen','twenty'];
    var w = (n >= 0 && n < words.length) ? words[n] : String(n);
    return w + ' (' + n + ')';
}

/* Trigger preview render after fonts are loaded */
window.addEventListener('DOMContentLoaded', function() {
    document.fonts.ready.then(renderLiveSynchronizedPreview);
});
</script>
</body></html>
HTML;
    }

} // end if (!function_exists('buildMOAFormHTML'))