<?php
/**
 * ACCOM_form_builder.php
 *
 * Provides:
 * buildACCOMFormHTML(array $s): string
 * accom_modal_css(): string
 * accom_preview_button(): string
 * accom_modal_html(array $accom_data): string
 *
 * -- REQUIRED AccomForm.php change --
 * The DB fetch for each requirement MUST select status as well:
 * SELECT uploaded_at, remark, status
 * FROM requirements
 * WHERE user_id=? AND requirement_type=? LIMIT 1
 *
 * Each requirement block in $accom_data must pass all three subkeys:
 * 'req_cert_registration' => [
 * 'uploaded_at' => $_accom_rows['req_cert_registration']['uploaded_at'] ?? '',
 * 'remark'      => $_accom_rows['req_cert_registration']['remark']      ?? '',
 * 'status'      => $_accom_rows['req_cert_registration']['status']      ?? '',
 * ],
 *
 * -- CRITICAL: $_accom_req_map in AccomForm.php must use the exact
 *    requirement_type strings stored in the DB (matching the $reqs array
 *    used in the upload form). Correct mapping:
 *
 *    'req_cert_registration'  => 'cert_registration',
 *    'req_cert_participation' => 'certificate_pdos',   // NOT 'cert_participation'
 *    'req_ojt_program'        => 'ojt_sheet',          // NOT 'ojt_program'
 *    'req_application_sit'    => 'application_sit',
 *    'req_waiver'             => 'waiver_form',
 *    'req_contract'           => 'student_contract',
 *    'req_psych'              => 'psych_result',
 *    'req_medical'            => 'medical_result',
 *
 * $accom_data keys expected by buildACCOMFormHTML():
 * -- Student --
 * first_name, middle_name, last_name, course, year_section,
 *
 * -- Company --
 * company, company_address, company_telephone,
 * pre_contact_person_first, pre_contact_person_middle, pre_contact_person_last,
 * contact_position,
 *
 * -- Requirements (each: 'uploaded_at', 'remark', 'status' subkeys) --
 * req_cert_registration, req_cert_participation, req_ojt_program,
 * req_application_sit, req_waiver, req_contract, req_psych, req_medical
 *
 * -- Signatures --
 * general_remarks,
 * ojt_coordinator_first, ojt_coordinator_middle, ojt_coordinator_last,
 * date_signed,
 *
 * -- Optional 2x2 photo (base64 data-URI or relative URL) --
 * photo_src
 *
 * ============================================================
 * MODAL/PREVIEW RESTYLE NOTE (Accomplishment Form preview)
 * ------------------------------------------------------------
 * The outer preview "wrapper" around the Accomplishment Form
 * (previously a centered pop-up box with its own toolbar/CSS) has
 * been rebuilt to match the exact full-screen document-preview
 * pattern already used for the Application SIT, Waiver, and
 * Student/University Contract previews on AccomForm.php: a
 * full-width toolbar pinned to the top (icon + title/subtitle on
 * the left, a single "Close" action on the right) with the document
 * filling the rest of the screen underneath, using the SAME CSS
 * classes AccomForm.php already defines for those three previews
 * (.fullscreen-doc-overlay, .fullscreen-doc-toolbar,
 * .fullscreen-doc-toolbar-left/right, .fdt-icon,
 * .fullscreen-doc-title-group/-title/-subtitle, .fs-tbtn,
 * .fs-tbtn-close, .fullscreen-doc-body). Because those classes are
 * already declared in AccomForm.php's main <style> block, no
 * additional/duplicate CSS is required from this file anymore --
 * accom_modal_css() is kept (so nothing that calls it breaks) but
 * now intentionally returns an empty string.
 *
 * Per the same "Close only" convention already adopted for the SIT
 * / Waiver / Contract toolbars (their Print buttons were removed),
 * the outer Accomplishment Form toolbar now also only has a Close
 * action. This does NOT affect the Print / Save as PDF buttons that
 * live *inside* the generated document itself (buildACCOMFormHTML()'s
 * own .sit-toolbar with window.print()/savePDF()) -- those are part
 * of the document content shown inside the iframe and are completely
 * unchanged.
 *
 * The public function names/signatures (accom_modal_css(),
 * accom_preview_button(), accom_modal_html()) and everything else in
 * this file -- including buildACCOMFormHTML() and all of its layout,
 * data handling, and PDF/print logic -- are unchanged.
 * ============================================================
 */

/* ===========================================================================
   accom_modal_css()
   ------------------------------------------------------------
   No longer needed: the Accomplishment Form preview now reuses the
   shared .fullscreen-doc-overlay / .fullscreen-doc-toolbar / .fs-tbtn
   styles that AccomForm.php's main <style> block already declares for
   the SIT / Waiver / Contract previews, so there is nothing left for
   this file to add. Kept as a function (returning an empty string) so
   the existing `<?php echo accom_modal_css(); ?>` call in AccomForm.php
   keeps working without any changes there.
=========================================================================== */
if (!function_exists('accom_modal_css')) {
    function accom_modal_css(): string {
        return '';
    }
}


/* ===========================================================================
   accom_preview_button()
=========================================================================== */
if (!function_exists('accom_preview_button')) {
    function accom_preview_button(): string {
        return '
<button class="switch-page-btn" type="button" onclick="accomOpenPreview()" style="margin-left:auto;">
    <i class="fas fa-clipboard-check"></i> Accomplishment Form
</button>
';
    }
}


/* ===========================================================================
   accom_modal_html(array $accom_data)
   ------------------------------------------------------------
   Rebuilt to match the exact full-screen preview markup pattern used
   for #sitPreviewModal / #waiverPreviewModal / #contractPreviewModal
   in AccomForm.php: a .fullscreen-doc-overlay containing a
   .fullscreen-doc-toolbar (icon + title/subtitle + a single Close
   button) and a .fullscreen-doc-body that holds the iframe. The
   open/close JS functions keep their original names
   (accomOpenPreview / accomClosePreview) and the same lazy-load
   iframe behavior (data-loaded guard, same accom_preview=1 URL) so
   the existing "Accomplishment Form" button in AccomForm.php
   (accom_preview_button_custom(), which calls accomOpenPreview())
   and the existing Escape-key handler (which calls
   accomClosePreview() if it exists) keep working unchanged.
=========================================================================== */
if (!function_exists('accom_modal_html')) {
    function accom_modal_html(array $accom_data): string {
        return '
<div id="accomPreviewModal" class="fullscreen-doc-overlay">
    <div class="fullscreen-doc-toolbar">
        <div class="fullscreen-doc-toolbar-left">
            <i class="fas fa-clipboard-list fdt-icon"></i>
            <div class="fullscreen-doc-title-group">
                <div class="fullscreen-doc-title">Accomplishment Form</div>
                <div class="fullscreen-doc-subtitle">Preview</div>
            </div>
        </div>
        <div class="fullscreen-doc-toolbar-right">
            <button class="fs-tbtn fs-tbtn-close" onclick="accomClosePreview()"><i class="fas fa-times"></i> <span>Close</span></button>
        </div>
    </div>
    <div class="fullscreen-doc-body">
        <iframe id="accomPreviewIframe" src="" title="Accomplishment Form"></iframe>
    </div>
</div>

<script>
var ACCOM_PREVIEW_URL = \'AccomForm.php?accom_preview=1\';

function accomOpenPreview() {
    var modal  = document.getElementById(\'accomPreviewModal\');
    var iframe = document.getElementById(\'accomPreviewIframe\');
    if (!iframe.dataset.loaded) {
        iframe.src = ACCOM_PREVIEW_URL;
        iframe.dataset.loaded = \'1\';
    }
    modal.classList.add(\'open\');
    document.body.style.overflow = \'hidden\';
}

function accomClosePreview() {
    var modal = document.getElementById(\'accomPreviewModal\');
    if (modal) {
        modal.classList.remove(\'open\');
        document.body.style.overflow = \'\';
    }
}

document.addEventListener(\'DOMContentLoaded\', function() {
    var modal = document.getElementById(\'accomPreviewModal\');
    if (modal) {
        modal.addEventListener(\'click\', function(e) {
            if (e.target === modal) accomClosePreview();
        });
    }
});
</script>
';
    }
}


/* ===========================================================================
   buildACCOMFormHTML(array $s): string
   ------------------------------------------------------------
   UNCHANGED. This builds the actual Accomplishment Form document
   (the content loaded into the preview iframe via
   AccomForm.php?accom_preview=1), including its own internal
   toolbar (Print / Save as PDF) and all layout/CSS -- none of that
   is part of this adjustment and nothing below this point was
   modified.
=========================================================================== */
if (!function_exists('buildACCOMFormHTML')) {

    function buildACCOMFormHTML(array $s): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

        /* -- Student -- */
        $first    = $esc($s['first_name']  ?? '');
        $middle   = $esc($s['middle_name'] ?? '');
        $last     = $esc($s['last_name']   ?? '');
        $fullname = trim($first . ' ' . ($middle ? $middle . ' ' : '') . $last);
        $course_yr = $esc($s['course'] ?? '') . ($s['year_section'] ? ' -- ' . $esc($s['year_section']) : '');

        /* -- Company -- */
        $company    = $esc($s['company']           ?? '');
        $co_address = $esc($s['company_address']   ?? '');
        $co_tel     = $esc($s['company_telephone'] ?? '');

        /* -- Contact person full name from three atomic parts -- */
        $cp_first_raw  = trim((string)($s['pre_contact_person_first']  ?? ''));
        $cp_middle_raw = trim((string)($s['pre_contact_person_middle'] ?? ''));
        $cp_last_raw   = trim((string)($s['pre_contact_person_last']   ?? ''));
        $cp_parts = [];
        if ($cp_first_raw !== '')                                           $cp_parts[] = $cp_first_raw;
        if ($cp_middle_raw !== '' && strtoupper($cp_middle_raw) !== 'N/A') $cp_parts[] = $cp_middle_raw;
        if ($cp_last_raw   !== '')                                          $cp_parts[] = $cp_last_raw;
        $contact_person = $esc(implode(' ', $cp_parts));
        $co_position    = $esc($s['contact_position'] ?? '');

        /* -- OJT Coordinator full name from three atomic parts -- */
        $ojt_first_raw  = trim((string)($s['ojt_coordinator_first']  ?? ''));
        $ojt_middle_raw = trim((string)($s['ojt_coordinator_middle'] ?? ''));
        $ojt_last_raw   = trim((string)($s['ojt_coordinator_last']   ?? ''));
        $ojt_parts = [];
        if ($ojt_first_raw  !== '')                                         $ojt_parts[] = $ojt_first_raw;
        if ($ojt_middle_raw !== '' && strtoupper($ojt_middle_raw) !== 'N/A') $ojt_parts[] = $ojt_middle_raw;
        if ($ojt_last_raw   !== '')                                         $ojt_parts[] = $ojt_last_raw;
        $ojt_coord = $esc(implode(' ', $ojt_parts));

        /* -- Misc -- */
        $general_remarks = $esc($s['general_remarks'] ?? '');
        $date_signed     = $esc($s['date_signed']     ?? '');
        $photo_src       = $s['photo_src'] ?? '';
        $today           = date('F d, Y');

        /* =====================================================================
           Requirements table rows.

           IMPORTANT: The keys here (req_cert_registration, req_cert_participation,
           etc.) must exactly match the keys passed in from AccomForm.php's
           $accom_data array, which in turn are populated by $_accom_req_map.

           $_accom_req_map in AccomForm.php MUST be:
               'req_cert_registration'  => 'cert_registration',
               'req_cert_participation' => 'certificate_pdos',   <- was wrong
               'req_ojt_program'        => 'ojt_sheet',          <- was wrong
               'req_application_sit'    => 'application_sit',
               'req_waiver'             => 'waiver_form',
               'req_contract'           => 'student_contract',
               'req_psych'              => 'psych_result',
               'req_medical'            => 'medical_result',

           The builder itself reads whatever is passed in $s[$key]; it does not
           query the DB. The fix for the empty rows is therefore in AccomForm.php's
           $_accom_req_map (see above), not here.  However, the builder is written
           defensively so that missing/null subkeys always produce empty cells
           rather than warnings.
        ===================================================================== */
        $requirements = [
            ['label' => 'Certificate of Registration',                                   'key' => 'req_cert_registration'],
            ['label' => 'Certificate of Participation (PDOS)',                           'key' => 'req_cert_participation'],
            ['label' => 'On&ndash;the&ndash;Job Training Program and Information Sheet', 'key' => 'req_ojt_program'],
            ['label' => 'Application for Supervised Industrial Training',                'key' => 'req_application_sit'],
            ['label' => 'Waiver and Permission Form',                                   'key' => 'req_waiver'],
            ['label' => 'Student/University Contract',                                   'key' => 'req_contract'],
            ['label' => 'Psych Test Results',                                            'key' => 'req_psych'],
            ['label' => 'Medical Results',                                               'key' => 'req_medical'],
        ];

        $req_rows_html = '';
        foreach ($requirements as $i => $req) {
            $key = $req['key'];

            /*
             * $s[$key] is expected to be a nested subarray, e.g.:
             * ['uploaded_at' => '2025-06-15 09:00:00', 'remark' => 'OK', 'status' => 'Verified']
             *
             * Defensive guard: accept any of these shapes without warnings:
             *   - correct array with all three subkeys
             *   - array missing some subkeys
             *   - null / missing key entirely (happens when $_accom_req_map had wrong DB key)
             *   - non-array scalar (safety net)
             */
            $raw = $s[$key] ?? null;
            $data = (is_array($raw) && !empty($raw)) ? $raw : [];

            /* -- uploaded_at ------------------------------------------------ */
            $raw_uploaded = '';
            if (isset($data['uploaded_at']) && $data['uploaded_at'] !== null && $data['uploaded_at'] !== '') {
                $raw_uploaded = trim((string)$data['uploaded_at']);
            }

            /* -- remark text (accept both 'remark' and 'remarks' spelling) -- */
            $raw_remark = '';
            if (isset($data['remark']) && (string)$data['remark'] !== '') {
                $raw_remark = trim((string)$data['remark']);
            } elseif (isset($data['remarks']) && (string)$data['remarks'] !== '') {
                $raw_remark = trim((string)$data['remarks']);
            }

            /* -- status ----------------------------------------------------- */
            $raw_status = '';
            if (isset($data['status']) && (string)$data['status'] !== '') {
                $raw_status = trim((string)$data['status']);
            }

            /* -- Format uploaded_at: strip time, display as "Mon DD, YYYY" -- */
            $uploaded_display = '';
            if ($raw_uploaded !== '') {
                /* Split on space or T to isolate the date part only */
                $date_only = preg_split('/[\sT]/', $raw_uploaded)[0] ?? '';
                if ($date_only !== '') {
                    $ts = strtotime($date_only);
                    $uploaded_display = $esc($ts !== false ? date('M d, Y', $ts) : $date_only);
                }
            }

            $rmk    = $esc($raw_remark);
            $stripe = ($i % 2 === 0) ? 'tr-even' : 'tr-odd';

            /* -- Status badge colour -- */
            $status_lower = strtolower($raw_status);
            if (in_array($status_lower, ['verified', 'approved'])) {
                $status_cls = 'sbadge-green';
            } elseif (in_array($status_lower, ['denied', 'rejected'])) {
                $status_cls = 'sbadge-red';
            } elseif ($status_lower === 'pending') {
                $status_cls = 'sbadge-amber';
            } elseif ($raw_status !== '') {
                $status_cls = 'sbadge-grey';
            } else {
                $status_cls = '';
            }

            /*
             * Remarks/Status cell layout:
             * Line 1 -- remark text  (shown only when non-empty)
             * Line 2 -- status badge centred via .badge-center (shown only when non-empty)
             * If both are empty, show a blank placeholder.
             */
            $rmk_cell = '';
            if ($rmk !== '') {
                $rmk_cell .= '<span class="cell-val rmk-text">' . $rmk . '</span>';
            }
            if ($raw_status !== '') {
                $rmk_cell .= '<div class="badge-center"><span class="req-status-badge ' . $status_cls . '">' . $esc($raw_status) . '</span></div>';
            }
            if ($rmk_cell === '') {
                $rmk_cell = '<span class="cell-blank">&nbsp;</span>';
            }

            $req_rows_html .= '
      <tr class="' . $stripe . '">
        <td class="td-req">' . $req['label'] . '</td>
        <td class="td-date"><span class="' . ($uploaded_display !== '' ? 'cell-val' : 'cell-blank') . '">'
            . ($uploaded_display !== '' ? $uploaded_display : '&nbsp;') . '</span></td>
        <td class="td-rmk">' . $rmk_cell . '</td>
      </tr>';
        }

        /* -- Photo block -- */
        $photo_html = $photo_src
            ? '<img src="' . htmlspecialchars($photo_src, ENT_QUOTES) . '" alt="Student Photo" class="photo-img">'
            : '<div class="photo-placeholder"><span class="photo-label">2&times;2<br>Photo</span></div>';

        /* -- Field fill helper -- */
        $fill = function(string $value, string $width = '14em'): string {
            if ($value !== '') return '<span class="fill-val">' . $value . '</span>';
            return '<span class="fill-blank" style="min-width:' . $width . '"></span>';
        };

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Accomplishment Form &mdash; ' . ($fullname ?: 'Student') . '</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* ========================= UNIFIED LAYOUT (preview, print & PDF) =========================
   The form uses real mm/pt units. .doc-paper is sized to exactly A4 (210mm x 297mm).
   The toolbar is hidden during print via @media print. No extra resizing or font
   adjustments are needed – screen and print output are identical.
======================================================================================= */

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --navy: #07145f;
  --gold: #c8a800;
  --text: #1a1a1a;
  --muted: #555;
  --white: #ffffff;
  --row-even: #f5f7fc;
  --row-odd:  #ffffff;
}

body {
  font-family: "DM Sans", sans-serif;
  background: #d8dde8;
  color: var(--text);
  margin: 0; padding: 0;
}

/* TOOLBAR (visible only on screen) */
.sit-toolbar {
  background: var(--navy);
  padding: .5rem 1.5rem;
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

/* A4 PAPER – exact size for screen and print */
.doc-outer {
  width: 210mm;
  margin: 20px auto 40px;
}
.doc-paper {
  background: var(--white);
  width: 210mm;
  min-height: 297mm;
  display: flex;
  flex-direction: column;
  border: 1px solid #b0b8cc;
  box-shadow: 0 4px 32px rgba(0,0,0,.22);
}

/* LETTERHEAD */
.letterhead {
  background: var(--navy);
  padding: 5mm 8mm;
  display: flex; align-items: center; gap: 4mm;
  border-bottom: 1mm solid var(--gold); flex-shrink: 0;
}
.lh-seal {
  width: 16mm; height: 16mm; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; overflow: hidden;
  border: 0.5mm solid rgba(255,255,255,.25);
}
.lh-seal img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
.lh-text { color: #fff; flex: 1; }
.lh-republic {
  font-size: 6pt; letter-spacing: .2em; text-transform: uppercase;
  color: #aac4f0; margin-bottom: 1mm; font-family: "JetBrains Mono", monospace;
}
.lh-uni {
  font-family: "DM Sans", sans-serif; font-size: 13pt;
  font-weight: 700; line-height: 1.2; letter-spacing: .01em; text-transform: uppercase;
}
.lh-addr { font-size: 7.5pt; color: #aac4f0; margin-top: 1mm; font-style: italic; }

/* TITLE BAND */
.title-band {
  background: #f5f6fa;
  border-bottom: 0.5mm solid #d0d5e8;
  padding: 3mm 8mm;
  text-align: center; flex-shrink: 0;
}
.dept-tag {
  font-family: "JetBrains Mono", monospace; font-size: 6.5pt;
  letter-spacing: .16em; text-transform: uppercase;
  color: var(--muted); margin-bottom: 1mm;
}
.title-band h1 {
  font-family: "DM Sans", sans-serif; font-size: 15pt;
  font-weight: 700; color: var(--navy);
  letter-spacing: .04em; text-transform: uppercase;
}
.form-meta {
  display: flex; justify-content: center; gap: 8mm; margin-top: 1mm;
  font-family: "JetBrains Mono", monospace; font-size: 6.5pt; color: #888;
}

/* DATE LINE */
.date-line {
  padding: 1.5mm 8mm; text-align: right;
  font-size: 9pt; color: var(--muted);
  border-bottom: 0.3mm solid #e2e6f0; flex-shrink: 0;
}
.date-line span { font-weight: 600; color: var(--text); }

/* FORM BODY */
.form-body {
  padding: 4mm 7mm 3mm;
  flex: 1; display: flex; flex-direction: column;
}

/* Section header */
.section-hdr {
  font-family: "JetBrains Mono", monospace; font-size: 6.5pt;
  font-weight: 700; text-transform: uppercase; letter-spacing: .12em;
  color: var(--navy); border-bottom: 0.4mm solid var(--navy);
  padding-bottom: 0.8mm; margin: 3mm 0 2mm;
}
.section-hdr:first-of-type { margin-top: 0; }

/*
 * SECTION A+B – photo spans both sections
 * Left column: Student + Company info. Right column: 2x2 photo.
 * Strictly locked to horizontal flex structure across screen, preview, and print.
 */
.section-ab-row {
  display: flex !important;
  flex-direction: row !important;
  align-items: flex-start;
  gap: 5mm;
  margin-bottom: 1mm;
}
.info-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.info-col .section-hdr:first-of-type { margin-top: 0; }
.photo-col { flex-shrink: 0; }

.student-fields { display: flex; flex-direction: column; gap: 1.5mm; }
.company-fields { display: flex; flex-direction: column; gap: 1.5mm; }

/* Field line */
.field-line {
  display: flex; align-items: baseline; gap: 2mm;
  font-size: 9.5pt; font-family: "DM Sans", sans-serif;
}
.field-lbl {
  font-weight: 400; color: var(--text); white-space: nowrap; flex-shrink: 0;
}
.fill-val {
  font-weight: 700;
  color: var(--text); flex: 1;
}
.fill-blank {
  display: inline-block;
  vertical-align: baseline; height: 1.1em; margin-bottom: -0.15em; flex: 1;
}

.two-col { 
  display: flex !important; 
  flex-direction: row !important;
  gap: 4mm; 
}
.two-col .field-line { flex: 1; }

/* 2x2 photo = 51mm x 51mm */
.photo-img {
  width: 51mm; height: 51mm; object-fit: cover;
  border: 0.4mm solid #bbb; display: block;
}
.photo-placeholder {
  width: 51mm; height: 51mm;
  border: 0.4mm dashed #aaa; background: #f4f4f8;
  display: flex; align-items: center; justify-content: center;
}
.photo-label { font-size: 7pt; color: #aaa; text-align: center; line-height: 1.5; }

/* REQUIREMENTS TABLE */
.req-table {
  width: 100%; border-collapse: collapse; margin-top: 2mm;
  font-family: "DM Sans", sans-serif; font-size: 8.5pt;
}
.req-table thead tr { background: var(--navy); color: #fff; }
.req-table thead th {
  padding: 2mm 2.5mm; text-align: left;
  font-family: "JetBrains Mono", monospace; font-size: 6pt;
  font-weight: 600; letter-spacing: .09em; text-transform: uppercase;
  white-space: nowrap;
}
.tr-even { background: var(--row-even); }
.tr-odd  { background: var(--row-odd);  }
.td-req  { padding: 1.5mm 2.5mm; border-bottom: 0.3mm solid #dde2f0; width: 55%; vertical-align: top; }
.td-date { padding: 1.5mm 2.5mm; border-bottom: 0.3mm solid #dde2f0; width: 20%; vertical-align: top; }
.td-rmk  { padding: 1.5mm 2.5mm; border-bottom: 0.3mm solid #dde2f0; width: 25%; vertical-align: middle; text-align: center; }
.cell-val    { font-weight: 600; color: var(--text); display: block; }
.cell-blank { color: #ccc; font-style: italic; display: block; }
.rmk-text    { display: block; margin-bottom: 1mm; }

.badge-center {
  display: flex;
  justify-content: center;
  align-items: center;
  margin-top: 0.5mm;
}

.req-status-badge {
  display: inline-block;
  font-family: "JetBrains Mono", monospace;
  font-size: 5.5pt; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em;
  padding: 0.5mm 1.5mm; border-radius: 0.8mm; line-height: 1.4;
  white-space: nowrap;
}
.sbadge-green { background: #d1fae5; color: #065f46; border: 0.3mm solid #6ee7b7; }
.sbadge-red   { background: #fee2e2; color: #991b1b; border: 0.3mm solid #fca5a5; }
.sbadge-amber { background: #fef3c7; color: #92400e; border: 0.3mm solid #fcd34d; }
.sbadge-grey  { background: #f1f5f9; color: #475569; border: 0.3mm solid #cbd5e1; }

/* GENERAL REMARKS */
.remarks-label { font-size: 9pt; font-weight: 600; color: var(--text); margin-top: 2.5mm; }
.remarks-box {
  width: 100%; min-height: 22mm;
  margin-top: 1mm; font-size: 9pt; padding: 0.5mm 1mm; color: var(--text);
  word-break: break-word;
}

.body-spacer { flex: 1; min-height: 0; }

/* SIGNATURES */
.sig-section {
  padding-top: 2mm; margin-top: -8mm; flex-shrink: 0;
}
.sig-two-col { 
  display: flex !important; 
  flex-direction: row !important;
  gap: 8mm; 
}
.sig-block { flex: 1; display: flex; flex-direction: column; gap: 1mm; }
.sig-role-tag {
  font-family: "JetBrains Mono", monospace; font-size: 6pt;
  font-weight: 600; text-transform: uppercase; letter-spacing: .1em;
  color: var(--navy); margin-bottom: 1mm; display: block;
  text-align: center;
}
.sig-name-line {
  font-family: "DM Sans", sans-serif; font-size: 10pt;
  font-weight: 400; color: var(--text);
  border-bottom: 0.4mm solid #999;
  min-height: 6mm; padding: 0.5mm 1mm;
  display: block; width: 100%;
  text-align: center;
}
.sig-name-line.filled { font-weight: 700; color: var(--navy); border-bottom-color: var(--navy); }
.sig-cap {
  font-family: "JetBrains Mono", monospace; font-size: 6pt;
  color: #888; text-align: center; margin-top: 1mm;
  text-transform: uppercase; letter-spacing: .07em;
}
.date-signed-row {
  display: flex; align-items: baseline; gap: 2mm;
  margin-top: 2.5mm; font-size: 9.5pt;
}
.date-signed-lbl { font-weight: 600; white-space: nowrap; }
.date-signed-val {
  border-bottom: 0.4mm solid #999; min-width: 40mm;
  min-height: 5mm; padding: 0.5mm 1mm; font-size: 9.5pt;
  display: inline-block;
}
.date-signed-val.filled { font-weight: 700; border-bottom-color: var(--navy); }

/* FOOTER */
.footer-band {
  background: #f0f2f8; border-top: 0.4mm solid var(--navy);
  padding: 1.5mm 8mm;
  display: flex; justify-content: space-between;
  font-family: "JetBrains Mono", monospace;
  font-size: 6.5pt; color: #888; letter-spacing: .07em;
  flex-shrink: 0;
}

/*
 * PRINT & SAVE AS PDF
 * Hide toolbar, match @page size to A4, keep everything else identical.
 */
@media print {
  @page {
    size: 210mm 297mm;
    margin: 0;
  }

  html, body {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    background: #fff !important;
    margin: 0 !important; padding: 0 !important;
  }

  .sit-toolbar { display: none !important; }

  .doc-outer {
    margin: 0 !important;
  }

  .doc-paper {
    box-shadow: none !important;
    border: none !important;
    min-height: 297mm !important;
    page-break-after: avoid;
    break-after: avoid;
  }
}
</style>
</head>
<body>

<div class="sit-toolbar">
  <button class="tbtn tbtn-primary" onclick="window.print()">&#128438; Print</button>
  <button class="tbtn tbtn-primary" onclick="savePDF()">&#128196; Save as PDF</button>
</div>

<div class="doc-outer"><div class="doc-paper">

  <div class="letterhead">
    <div class="lh-seal"><img src="logo.webp" alt="NEUST Logo"></div>
    <div class="lh-text">
      <div class="lh-republic">Republic of the Philippines</div>
      <div class="lh-uni">Nueva Ecija University of Science and Technology</div>
      <div class="lh-addr">Cabanatuan City, Nueva Ecija</div>
    </div>
  </div>

  <div class="title-band">
    <div class="dept-tag">On-the-Job Training and Career Development Center</div>
    <h1>Accomplishment Form</h1>
    <div class="form-meta">
      <span>Form No.: NEUST-OJT-F010</span>
      <span>Effectivity: 01.08.2025</span>
    </div>
  </div>

  <div class="date-line">Date: <span>' . $today . '</span></div>

  <div class="form-body">

    <div class="section-ab-row">

      <div class="info-col">

        <div class="section-hdr">A. Student Information</div>
        <div class="student-fields">
          <div class="field-line">
            <span class="field-lbl">Name:</span>
            ' . $fill($fullname, '100%') . '
          </div>
          <div class="field-line">
            <span class="field-lbl">Course&nbsp;/&nbsp;Yr.&nbsp;/&nbsp;Sec.:</span>
            ' . $fill($course_yr, '100%') . '
          </div>
        </div>

        <div class="section-hdr">B. Company Information</div>
        <div class="company-fields">
          <div class="field-line">
            <span class="field-lbl">Company:</span>
            ' . $fill($company, '100%') . '
          </div>
          <div class="field-line">
            <span class="field-lbl">Address:</span>
            ' . $fill($co_address, '100%') . '
          </div>
          <div class="field-line">
            <span class="field-lbl">Telephone&nbsp;Number:</span>
            ' . $fill($co_tel, '100%') . '
          </div>
          <div class="two-col">
            <div class="field-line">
              <span class="field-lbl">Contact&nbsp;Person:</span>
              ' . $fill($contact_person, '100%') . '
            </div>
            <div class="field-line">
              <span class="field-lbl">Position:</span>
              ' . $fill($co_position, '100%') . '
            </div>
          </div>
        </div>

      </div><div class="photo-col">' . $photo_html . '</div>

    </div><div class="section-hdr">C. Requirements Accomplished</div>

    <table class="req-table">
      <thead>
        <tr>
          <th class="td-req">Requirements</th>
          <th class="td-date">Submitted Date</th>
          <th class="td-rmk" style="text-align:center;">Status</th>
        </tr>
      </thead>
      <tbody>' . $req_rows_html . '
      </tbody>
    </table>

    <div class="remarks-label">General Remarks:</div>
    <div class="remarks-box">
      ' . ($general_remarks ?: '&nbsp;') . '
      <div style="border-bottom:0.4mm solid #aaa; margin-top:5mm;">&nbsp;</div>
      <div style="border-bottom:0.4mm solid #aaa; margin-top:5mm;">&nbsp;</div>
      <div style="border-bottom:0.4mm solid #aaa; margin-top:5mm;">&nbsp;</div>
    </div>

    <div class="body-spacer"></div>

    <div class="sig-section">
      <div class="sig-two-col">

        <div class="sig-block">
          <span class="sig-role-tag">Received and Verified by</span>
          <div class="sig-name-line' . ($ojt_coord ? ' filled' : '') . '">' . ($ojt_coord ?: '&nbsp;') . '</div>
          <div class="sig-cap">Signature over Printed Name of the OJT Coordinator</div>
        </div>

        <div class="sig-block" style="margin-top:-18mm;">
          <span class="sig-role-tag" style="margin-top:-6mm; display:block;">Prepared by</span>
          <div class="sig-name-line' . ($fullname ? ' filled' : '') . '">' . ($fullname ?: '&nbsp;') . '</div>
          <div class="sig-cap">Signature over Printed Name of the Student</div>
        </div>

      </div>
      <div class="date-signed-row">
        <span class="date-signed-lbl">Date Signed:</span>
        <span class="date-signed-val' . ($date_signed ? ' filled' : '') . '">' . ($date_signed ?: '&nbsp;') . '</span>
      </div>
    </div>

  </div><div class="footer-band">
    <span>NEUST-OJT-F010</span>
    <span>Rev. 01 (01.08.2025)</span>
  </div>

</div></div>

<script>
function savePDF() {
    var studentName = ' . json_encode($fullname ?: 'Student') . ';
    var filename    = studentName + \' - Accomplishment Form.pdf\';
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
}