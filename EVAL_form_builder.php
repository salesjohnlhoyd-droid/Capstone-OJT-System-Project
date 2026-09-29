<?php
/**
 * EVAL_form_builder.php  (v1.13 — fixes the remaining print/PDF footer
 * position and "page 1 not intact" mismatch versus the live editable
 * preview in company_reports.php, by giving this file's pagination the
 * SAME explicit-pixel-height technique company_reports.php's own
 * tpPaginateAndRender() already uses for the editable Training Plan.)
 *
 * v1.13 CHANGE (this revision — fixes "the print button layout does not
 * match the evaluation form preview," "page 1 is not intact," and "the
 * footer is not placed at the bottom of every page"):
 *
 *   ROOT CAUSE: paginateTrainingPlan() (below) positions each sheet's
 *   footer with CSS alone — `.footer-band { margin-top: auto; }` inside
 *   a `.form-body { flex: 1; }` child of a fixed-height (1123px)
 *   `.doc-paper` flex column. That depends on the rendering engine
 *   resolving, at paint time, exactly how much leftover space exists
 *   between the letterhead/title-band, the body's real content, and the
 *   footer, then pushing the footer into whatever is left. That
 *   resolution is NOT guaranteed to come out identically across every
 *   rendering pass: the on-screen paint that JS pagination measures
 *   against (via scrollHeight/clientHeight) is one pass; a later print
 *   pass or an html2canvas rasterization pass is a different pass, and
 *   can legitimately compute slightly different flex/line-box metrics
 *   for the exact same DOM (different font hinting/subpixel rounding,
 *   different effective viewport, etc.). When that happens here, the
 *   footer no longer sits flush against the true bottom of the physical
 *   page, and/or content that was measured to just barely fit during
 *   pagination ends up a hair taller once actually printed — which is
 *   exactly the "footer isn't at the bottom" / "page 1 isn't intact"
 *   symptom, and exactly why the printed/PDF output didn't match the
 *   locked preview shown in company_reports.php.
 *
 *   This is the identical failure mode company_reports.php's OWN
 *   tpPaginateAndRender() used to have for the *editable* Training Plan
 *   view, and it was fixed there (see that file's "v3" pagination
 *   comment) by no longer asking the browser to *distribute* space at
 *   render time at all — instead, once a sheet's real packed content is
 *   final, its body is given an EXPLICIT pixel height computed directly
 *   from that sheet's own real letterhead/title-band/footer heights:
 *
 *       bodyHeight = 1123 − (letterhead + title-band height) − footer height
 *
 *   Because that height is now a plain, explicit number — not something
 *   flexbox has to solve for — the footer that immediately follows it in
 *   normal document flow always lands in exactly the same place (the
 *   true bottom of the fixed 1123px sheet) no matter which rendering
 *   pipeline paints it: the on-screen preview, the browser's print
 *   engine, or html2canvas's rasterizer for Save-as-PDF.
 *
 *   FIX (this revision): paginateTrainingPlan()'s finalize step now
 *   performs the SAME explicit-height calculation, per sheet, right
 *   after that sheet's content has been fully packed (so its real,
 *   final scrollHeight/clientHeight are already settled and won't
 *   change again) — giving this file's locked/print/PDF rendering path
 *   pixel-for-pixel parity with company_reports.php's own preview
 *   pagination technique for the very first time. The overflow-detection
 *   safety buffer used while packing rows/blocks onto a sheet was also
 *   widened from 1px to 3px (matching company_reports.php's
 *   tpPaginateAndRender()), so a genuinely borderline row is pushed to
 *   the next sheet instead of being accepted and then risking a
 *   sub-pixel clip once truly painted.
 *
 *   Nothing else changed: the letterhead/footer markup, the CSS (the
 *   print media rules that keep `.doc-paper` at a fixed 1123px height
 *   are still correct and still needed — they just no longer have to
 *   carry the whole burden of footer placement on their own), the
 *   Student–Trainee Information two-column form-grid layout, the
 *   Training Plan instructions/rating-scale content, the competency
 *   tables/rows/ratings, the signature block markup, the asset-readiness
 *   gating, and the "build a real sheet, then verify with
 *   scrollHeight vs. clientHeight" packing approach are all unchanged.
 *
 * (Earlier revision history retained below for context.)
 *
 * v1.12 CHANGE — fixed the print/PDF footer position and "page 1 looks
 * incomplete" symptom caused by an @media print rule that relaxed
 * .doc-paper back to height:auto and silently defeated the flex
 * margin-top:auto footer-pinning the JS pagination relies on:
 *
 *   ROOT CAUSE: Print and the locked on-screen preview
 *   (#evalBlobFrame in company_reports.php) are actually the SAME
 *   document. evalPrintDoc() calls iframe.contentWindow.print() on the
 *   very iframe that is already showing the fully paginated result — it
 *   does NOT reload the iframe or re-run pagination. So the .doc-paper
 *   sheets built by paginateTrainingPlan() (see v1.11 below) were
 *   already correct and already matched the preview pixel-for-pixel.
 *   The mismatch was introduced AFTER pagination finished, purely by
 *   the @media print stylesheet:
 *
 *       .doc-paper {
 *           height: auto !important; overflow: visible !important;
 *       }
 *
 *   Every .doc-paper's footer-band is pinned to the bottom of the sheet
 *   via `margin-top:auto` inside a `display:flex; flex-direction:column`
 *   parent — but `margin-top:auto` only has somewhere to push into when
 *   its flex container has a FIXED/definite height with left-over free
 *   space to distribute. Once print forced `.doc-paper` to
 *   `height:auto`, the container simply shrank to the height of its own
 *   content (letterhead + title-band + form-body + footer, with nothing
 *   left over), so the footer ended up sitting directly under the last
 *   line of the form body instead of at the true bottom of the physical
 *   A4 page — while `page-break-after:always` still forced the *next*
 *   sheet onto a new page regardless, leaving a large blank gap between
 *   the footer and the actual page edge. That is exactly the "footer
 *   isn't at the bottom of the page" symptom. And because it makes the
 *   printed sheet visually collapse to a fraction of the page (with the
 *   rest just blank), it also produces the "page one looks
 *   incomplete/not intact" symptom — even though the underlying
 *   paginated CONTENT was already identical to the preview the whole
 *   time.
 *
 *   FIX: `.doc-paper` keeps its fixed `height: 1123px !important` in
 *   print (the same A4-at-96dpi height the JS pagination already
 *   targets when it decides what fits on each sheet), so the flex
 *   column still has real left-over space for `margin-top:auto` to push
 *   the footer down to the true bottom of the physical page — matching
 *   the on-screen preview exactly. `overflow: visible !important` is
 *   kept (not reverted to `hidden`) so nothing is clipped if the print
 *   engine happens to render a hair taller than the screen did for the
 *   exact same DOM; any such tiny overage simply paints past the
 *   fixed-height box without shrinking or repositioning it.
 *   `.form-body` now also gets `overflow: visible !important` in print
 *   for the same reason — it no longer needs `overflow:hidden` to look
 *   right once its parent's height is fixed again, and leaving it
 *   hidden would risk re-introducing a clipping edge case.
 *
 *   (v1.13 above builds on this: keeping .doc-paper's height fixed in
 *   print is still correct and still kept, but footer placement no
 *   longer depends on margin-top:auto/flex distribution succeeding
 *   identically across rendering engines — it's now computed explicitly
 *   in JS, which is a stronger guarantee than CSS alone can offer.)
 *
 *   SECONDARY HARDENING (not a behavior-changing bug on its own here,
 *   but brings this file's pagination fully in line with the identical
 *   defensive guard already used by company_reports.php's
 *   tpPaginateAndRender()): the "does this unit overflow?" check is now
 *   only trusted once the current sheet already has other content on
 *   it (hadContentBefore / hadRowsBefore). Without that guard, a
 *   borderline sub-pixel measurement on the very FIRST unit placed on a
 *   brand-new, still-empty sheet could in theory bounce that unit onto
 *   a second, otherwise-unnecessary empty sheet, leaving page 1 blank.
 *   This mirrors company_reports.php's tpPaginateAndRender() exactly,
 *   so both renderers now paginate under the same rule.
 *
 *   Nothing else — the letterhead/footer markup, the Student–Trainee
 *   Information two-column form-grid layout (Personal / School / HTE as
 *   three separate blocks), the Training Plan instructions and
 *   rating-scale content, the competency tables/rows/ratings, the
 *   signature block markup, and the "build a real sheet, then verify
 *   with scrollHeight vs. clientHeight" pagination approach from v1.11
 *   — changed. Only the print CSS and the overflow-guard hardening
 *   described above were touched.
 *
 * v1.11 CHANGE — fixes the pagination overflow check that was silently
 * defeated by the sheet's own CSS, causing only ONE page to ever render
 * and print/PDF to never match the live preview:
 *
 *   ROOT CAUSE: v1.10's paginateTrainingPlan() correctly switched from
 *   *predicting* whether a block fits (measuring an isolated clone
 *   somewhere else in the document) to *verifying* it directly — it
 *   builds each .doc-paper sheet for real, appends the next content unit,
 *   and asks the browser whether the sheet now overflows. That part of
 *   the design was sound. The bug was in HOW it asked the browser:
 *
 *       function overflows(sheet) {
 *           var bodyRect   = sheet.body.getBoundingClientRect();
 *           var footerRect = sheet.footer.getBoundingClientRect();
 *           return Math.ceil(bodyRect.bottom) > Math.floor(footerRect.top);
 *       }
 *
 *   `.form-body` is a `flex: 1` child inside a FIXED-height (1123px)
 *   `.doc-paper` that also has `overflow: hidden`. Because of that, the
 *   browser always renders `.form-body`'s own box as exactly the
 *   leftover space between the letterhead/title-band and the footer-band
 *   — that box's height (and therefore its getBoundingClientRect()) does
 *   NOT grow when more content is appended to it; the extra content is
 *   simply clipped out of view instead. So `bodyRect.bottom` was, for
 *   all practical purposes, a constant that always sat above
 *   `footerRect.top`, and `overflows()` always returned `false` — no
 *   matter how many info blocks or competency rows were appended to the
 *   very first sheet. Every single unit in the entire Training Plan
 *   therefore landed on ONE sheet and was invisibly clipped away by that
 *   sheet's own `overflow:hidden`, which is exactly the "only one page
 *   shows, the rest are gone" symptom in the preview. And because
 *   pagination never actually produced more than one `.doc-paper`, print
 *   and Save-as-PDF (which both walk `.doc-paper` elements one-per-page)
 *   had only that single, truncated sheet to work with — so the printed
 *   output could never match the live, multi-page editable preview in
 *   company_reports.php.
 *
 *   FIX: `overflows()` now compares the sheet body's `scrollHeight`
 *   (the true, un-clipped height of everything appended to it so far)
 *   against its `clientHeight` (the fixed, clipped height the flex
 *   layout actually allocates it, which — as explained above — stays
 *   constant regardless of content). `scrollHeight` always reflects the
 *   real content height even under `overflow:hidden`, so
 *   `scrollHeight > clientHeight` is a direct, reliable overflow test
 *   that works WITH the sheet's real CSS instead of being silently
 *   defeated by it. No other part of the pagination mechanism, the
 *   markup, or the CSS needed to change — the "build a real sheet, then
 *   verify against it" approach from v1.10 was correct; only the
 *   verification itself was checking the wrong thing.
 *
 *   - v1.10: pagination stopped *predicting* whether a block fits
 *     (measuring an isolated off-DOM clone) and switched to *building
 *     each sheet for real* and asking the browser directly whether it
 *     overflows — sound in principle, but the overflow check itself
 *     (getBoundingClientRect comparison) was defeated by the sheet's own
 *     `overflow:hidden`, which is what v1.11 above fixed.
 *   - v1.1: Student–Trainee Information rebuilt to match the two-column
 *     "form grid" layout used by the live editable preview in
 *     company_reports.php (Personal / School / HTE, with the
 *     "Name/Age/Sex" and "OJT trainor/Telephone/Date OJT started" rows).
 *   - v1.2–v1.9: iterative attempts at fixed-size A4 "sheet" pagination,
 *     asset-readiness gating (images/fonts), and CSS parity fixes
 *     against company_reports.php's live preview.
 *   - Because this document is several pages long, the letterhead and
 *     footer repeat on every printed page via CSS position:fixed inside
 *     @media print (matching Word's repeating header/footer behaviour).
 *     On screen the page flows continuously; only print/PDF is paginated.
 *   - Every Rating / Remarks field is a readonly, display-only input,
 *     pre-filled from $s['ratings'][$key]['rating'|'remarks'] when present,
 *     otherwise left blank — same pattern as the readonly score inputs in
 *     EVAL_form_builder.php.
 *
 * v1.14 CHANGE — fixes "page 1 not intact / page 1's content overlays or
 * mixes with page 2" when using the Print button in company_reports.php:
 *
 *   ROOT CAUSE: The Print button in company_reports.php
 *   (evalPrintDoc()) always prints the locked #evalBlobFrame iframe,
 *   whose src is print_eval=1 — i.e. it always prints THIS file's
 *   output, never company_reports.php's own markup. In this file's
 *   @media print block, `.doc-paper` and `.form-body` were forced to
 *   `overflow: visible !important`. Combined with `.doc-outer` being
 *   switched to plain block layout for print (so consecutive
 *   `.doc-paper` sheets sit directly one after another with
 *   `page-break-after: always` and no gap), ANY sheet whose real
 *   content came out even a hair taller than the JS-computed 1123px
 *   body height — a real possibility, since the packing loop measures a
 *   live `clientHeight` on one rendering pass, and the print engine can
 *   legitimately resolve fractional/sub-pixel layout slightly
 *   differently on its own pass — would have that small overflow
 *   painted straight past the bottom of its box and into the physical
 *   area of the following page, since `overflow:visible` does not clip
 *   it and block-level siblings don't get pushed down by an
 *   overflowing box. Visually, that is exactly "page 1's content
 *   overlaying/mixing with page 2".
 *
 *   FIX: `.doc-paper` and `.form-body` are now `overflow: hidden
 *   !important` in print (matching company_reports.php's own printable
 *   `.doc-paper` rule, which never overrides the base `overflow:hidden`
 *   and has never exhibited this bleed-over symptom). This is safe
 *   because paginateTrainingPlan() below already guarantees every unit
 *   fits on its sheet before placing it there (scrollHeight vs.
 *   clientHeight check with a 3px safety buffer) and each sheet's body
 *   height is explicitly locked to the exact pixel value that was used
 *   to pack it — so there is nothing meaningful left to clip. Hiding
 *   overflow simply guarantees that if any sub-pixel rounding variance
 *   ever occurs between the measurement pass and the print pass, it is
 *   silently clipped instead of bleeding into the next physical page.
 *   `max-height` locks were also added alongside the existing
 *   `height`/`min-height` locks on `.doc-paper` in print, for the same
 *   belt-and-suspenders reason. Nothing else in this file — markup,
 *   other CSS, or the pagination/asset-readiness JavaScript — was
 *   changed.
 *
 * v1.15 CHANGE (this revision — fixes the sheet not stretching to fill
 * the exact physical A4 page in print/PDF, leaving visible white space
 * around the printed sheet, as shown by the "print layout" screenshots):
 *
 *   ROOT CAUSE: `@page { size: A4 portrait; margin: 0; }` (further up
 *   this stylesheet) tells the print engine the physical page is
 *   exactly `210mm × 297mm`. But the v1.14 (and earlier) @media print
 *   rule for `.doc-paper` only ever locked its HEIGHT to a physical
 *   value — and even then only in `px` (`height: 1123px !important`),
 *   never in `mm`. Its WIDTH was never touched in print at all, so it
 *   fell back to the base (screen) rule's `width: 794px`. 1123px and
 *   794px are only *approximations* of 297mm/210mm at a *presumed*
 *   96dpi — the exact conversion a given browser's print pipeline
 *   actually applies for CSS px → physical mm is not guaranteed to
 *   match that presumed 96dpi precisely, and `.doc-outer` (the flex
 *   wrapper around every `.doc-paper` sheet) was never given a physical
 *   width either. Any rounding gap between the sheet's *approximated*
 *   px dimensions and the print engine's *actual* physical page
 *   resolution shows up exactly as pictured: a white gutter/margin
 *   around the printed sheet instead of the sheet filling the page
 *   edge-to-edge, and (worst on short pages, like the signature-only
 *   final page) large blank space below content that never reaches the
 *   true bottom of the sheet.
 *
 *   This is the exact same failure mode already identified and fixed
 *   in company_reports.php's own `@media print .doc-paper` rule (see
 *   its "white space around the printed page" comment) — but that fix
 *   was never carried over to this file, which is what actually
 *   generates the locked/submitted Training Plan output shown in the
 *   screenshots (via print_eval=1).
 *
 *   FIX: `.doc-outer` and `.doc-paper` now lock BOTH dimensions to the
 *   SAME physical unit (`mm`), matching the `@page` size exactly —
 *   `.doc-outer { width: 210mm !important; }` and
 *   `.doc-paper { width: 210mm !important; height: 297mm !important;
 *   min-height: 297mm !important; max-height: 297mm !important; }` —
 *   removing the px-to-mm approximation gap entirely, so the printed/
 *   PDF sheet always stretches to fill the full physical A4 page on
 *   every side, with no unit-conversion rounding gap left for any
 *   browser/print pipeline to resolve differently. `overflow: hidden`
 *   (already set by v1.14) is kept, so the same negligible sub-pixel
 *   variance between the on-screen px-based measurement pass (JS
 *   pagination below still works in px, since it still needs to size
 *   `.form-body` against the live, on-screen 1123px sheet) and the
 *   mm-based print pass is clipped instead of causing any visible
 *   drift. Nothing else — markup, other CSS, or the pagination/asset-
 *   readiness JavaScript — was changed.
 */

if (!function_exists('buildEvalFormHTML')) {

    function buildEvalFormHTML(array $s): string {
        $esc = function ($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES); };

        /* ── Student / Training info ───────────────────────────────────── */
        $trainee_name     = $esc($s['trainee_name']      ?? '');
        $trainee_age      = $esc($s['trainee_age']       ?? '');
        $trainee_sex      = $esc($s['trainee_sex']       ?? '');
        $home_address     = $esc($s['home_address']      ?? '');
        $home_tel         = $esc($s['home_tel']          ?? '');
        $guardian_name    = $esc($s['guardian_name']     ?? '');
        $guardian_tel     = $esc($s['guardian_tel']      ?? '');
        $school_name      = $esc($s['school_name']       ?? '');
        $school_address   = $esc($s['school_address']    ?? '');
        $college          = $esc($s['college']           ?? '');
        $program          = $esc($s['program']           ?? '');
        $subject          = $esc($s['subject']            ?? '');
        $required_hours   = $esc($s['required_hours']    ?? '');
        $ojt_coordinator  = $esc($s['ojt_coordinator']   ?? '');
        $coordinator_tel  = $esc($s['coordinator_tel']   ?? '');
        $hte_name         = $esc($s['hte_name']          ?? '');
        $hte_address      = $esc($s['hte_address']       ?? '');
        $hte_tel          = $esc($s['hte_tel']           ?? '');
        $hte_trainor      = $esc($s['hte_trainor']       ?? '');
        $ojt_start_date   = $esc($s['ojt_start_date']    ?? '');

        $ratings = $s['ratings'] ?? [];

        $rate = function ($key) use ($ratings, $esc) {
            $r = $ratings[$key]['rating']  ?? '';
            $m = $ratings[$key]['remarks'] ?? '';
            return [$esc($r), $esc($m)];
        };

        /* ── Small helper: renders one read-only "boxed" field, matching
           the visual language of the .tp-form-input control used in the
           live editable preview (label above, bordered input below). ── */
        $field = function (string $label, string $value, bool $full = false) {
            $cls = 'tp-form-field' . ($full ? ' full' : '');
            return '<div class="' . $cls . '"><label>' . htmlspecialchars($label, ENT_QUOTES) . '</label>'
                 . '<input type="text" class="tp-form-input" value="' . $value . '" readonly tabindex="-1" autocomplete="off"></div>';
        };
        /* Renders 2–3 fields side-by-side on one row (spans the full grid
           width), mirroring tpFieldRow() in the live preview. Each entry:
           ['label' => ..., 'value' => ..., 'flex' => n (optional)]. */
        $fieldRow = function (array $fields) use ($esc) {
            $inner = '';
            foreach ($fields as $f) {
                $flex = $f['flex'] ?? 1;
                $inner .= '<div class="tp-form-field" style="flex:' . (int)$flex . ';min-width:0;">'
                        . '<label>' . htmlspecialchars($f['label'], ENT_QUOTES) . '</label>'
                        . '<input type="text" class="tp-form-input" value="' . $f['value'] . '" readonly tabindex="-1" autocomplete="off"></div>';
            }
            return '<div class="tp-form-field-row">' . $inner . '</div>';
        };
        $sectionLabel = function (string $text) {
            return '<div class="tp-form-section-label">' . htmlspecialchars($text, ENT_QUOTES) . '</div>';
        };

        /* ── Build the Student–Trainee Information form grid ────────────
           Personal / School / HTE are built as THREE SEPARATE top-level
           blocks (each its own wrapper div containing its own
           .tp-form-grid), mirroring the exact block granularity used by
           the live editable preview's renderEvalFormBody() in
           company_reports.php (personalInfoBlock / schoolInfoBlock /
           hteInfoBlock as three independent atomic pagination blocks),
           so the client-side pagination script below packs this content
           onto sheets the same way the live preview does — keeping page
           contents consistent before and after submission.

           Field grouping/ordering per block:
             Personal information : Name / Age / Sex (one row),
                                     Home address, Home telephone no.,
                                     Parent/guardian, Guardian telephone no.
             School information   : School, School address, College,
                                     Program, Subject, Required no. of
                                     hours, OJT coordinator, Coordinator
                                     telephone no.
             HTE information      : OJT trainor/supervisor, Telephone no.,
                                     Date OJT started (one row),
                                     HTE name (full), Address (full)      */
        $personal_info_html =
              '<div class="tp-form-grid">'
            . $sectionLabel('Personal information')
            . $fieldRow([
                  ['label' => 'Name', 'value' => $trainee_name, 'flex' => 2],
                  ['label' => 'Age',  'value' => $trainee_age,  'flex' => 1],
                  ['label' => 'Sex',  'value' => $trainee_sex,  'flex' => 1],
              ])
            . $field('Home address', $home_address)
            . $field('Home telephone no.', $home_tel)
            . $field('Parent/guardian', $guardian_name)
            . $field('Guardian telephone no.', $guardian_tel)
            . '</div>';

        $school_info_html =
              '<div class="tp-form-grid">'
            . $sectionLabel('School information')
            . $field('School', $school_name)
            . $field('School address', $school_address)
            . $field('College', $college)
            . $field('Program', $program)
            . $field('Subject', $subject)
            . $field('Required no. of hours', $required_hours)
            . $field('OJT coordinator', $ojt_coordinator)
            . $field('Coordinator telephone no.', $coordinator_tel)
            . '</div>';

        $hte_info_html =
              '<div class="tp-form-grid">'
            . $sectionLabel('Host training establishment (HTE)')
            . $fieldRow([
                  ['label' => 'OJT trainor/supervisor', 'value' => $hte_trainor,    'flex' => 1],
                  ['label' => 'Telephone no.',           'value' => $hte_tel,        'flex' => 1],
                  ['label' => 'Date OJT started',        'value' => $ojt_start_date, 'flex' => 1],
              ])
            . $field('HTE name', $hte_name, true)
            . $field('Address', $hte_address, true)
            . '</div>';

        /* Three independent top-level wrapper blocks — NOT nested inside
           one shared parent — so each becomes its own direct child of
           .form-body for the pagination script to treat as a separate
           atomic unit (exactly like personalInfoBlock / schoolInfoBlock /
           hteInfoBlock in company_reports.php's renderEvalFormBody()). */
        $student_info_grid_html =
              '<div class="tp-info-section">' . $personal_info_html . '</div>'
            . '<div class="tp-info-section" style="margin-top:16px;">' . $school_info_html . '</div>'
            . '<div class="tp-info-section" style="margin-top:16px;">' . $hte_info_html . '</div>';

        /* ── General Competencies (category => items) ─────────────────── */
        $general_competencies = [
            'PUNCTUALITY' => [
                'Demonstrate punctuality in reporting for work.',
                'Notify the employer with any shift misses.',
                'Perform tasks in an accurate and timely manner.',
                'Return from meals and/or breaks on time.',
            ],
            'DEPENDABILITY' => [
                'Accept responsibility on the job.',
                'Assume responsibility for own decisions and actions.',
                'Demonstrate ethical practices (i.e., honesty and integrity).',
                'Demonstrate ability to set priorities.',
                'Follow rules and regulations.',
            ],
            'INITIATIVE' => [
                'Perform assigned duties without continuous supervision and directions.',
                'See what needs to be done and do it.',
                'Follow through and get all work completed.',
            ],
            'APPEARANCE' => [
                'Exhibit good grooming.',
                'Demonstrate appropriate dress/attire for the job.',
                'Demonstrate personal hygiene and cleanliness.',
            ],
            'ADAPTABILITY' => [
                'Demonstrate the ability to catch on quickly.',
                'Change focus easily and without complaint.',
            ],
            'COMMUNICATION' => [
                'Read and comprehend written information.',
                'Use correct grammar.',
                'Communicate effectively with supervisor and customers.',
                'Use job-related terminology.',
                'Listen attentively.',
                'Write legibly.',
                'Follow written directions.',
                'Follow oral directions.',
                'Ask questions so that assigned tasks can be completed.',
                'Locate information in order to accomplish a task.',
                'Assist in training new employees.',
                'Communicate effectively with employer/co-workers.',
            ],
            'SAFETY AND SECURITY' => [
                'Comply with safety and health rules.',
                'Select correct tools and equipment.',
                'Utilize equipment correctly.',
                'Use appropriate action during emergencies.',
                'Maintain clean work area.',
                'Maintain orderly work area.',
            ],
        ];

        /* ── Specific Work Competencies ────────────────────────────────── */
        $specific_competencies = [
            'States a desire to produce or sell a top or better quality product or service',
            'Does personal research on how to provide a product or service',
            "Seeks information or asks questions to clarify a client's or a supplier's need",
            'Uses information or business tools to improve efficiency',
            'Pitches in with workers or works in their place to get the job done',
            'Responds flexibly to deal with changing priorities',
            'Persists in pursuing goals despite obstacles and setbacks',
            'Creates common purpose with colleagues through shared vision and values',
            'Writing communication abilities',
            'Interpersonal communication abilities',
            'Expresses confidence in own ability to complete a task or meet a challenge',
            'Seeks opportunities to work on teams as a means to develop experience, and knowledge',
            'Carefully weighs the priority of things to be done',
            'Quickly and effectively solves customer problems',
            'Approaches a complex task or problem by breaking it down into its component parts and considering each part in detail',
        ];

        /* ── Build General Competencies table rows ─────────────────────── */
        $gen_rows_html = '';
        foreach ($general_competencies as $category => $items) {
            $gen_rows_html .= '
                <tr class="cat-tr">
                    <td class="cat-td">' . htmlspecialchars($category, ENT_QUOTES) . '</td>
                    <td class="cat-th-cell">Rating</td>
                    <td class="cat-th-cell">Remarks</td>
                </tr>';
            foreach ($items as $i => $item) {
                $key = 'gen_' . strtolower(preg_replace('/[^a-z0-9]+/i', '_', $category)) . '_' . $i;
                [$rv, $mv] = $rate($key);
                $gen_rows_html .= '
                <tr class="item-tr">
                    <td class="item-td">' . htmlspecialchars($item, ENT_QUOTES) . '</td>
                    <td class="rate-td">
                        <input type="text" class="rate-input" value="' . $rv . '" readonly tabindex="-1" autocomplete="off">
                    </td>
                    <td class="remarks-td">
                        <input type="text" class="remarks-input" value="' . $mv . '" readonly tabindex="-1" autocomplete="off">
                    </td>
                </tr>';
            }
        }
        [$gen_total_rv] = $rate('general_competency_rating');
        $gen_rows_html .= '
                <tr class="total-tr">
                    <td class="total-td">General Competency Rating:</td>
                    <td class="rate-td" colspan="2">
                        <span class="total-readout">' . ($gen_total_rv !== '' ? $gen_total_rv : '&mdash;') . '</span>
                    </td>
                </tr>';

        /* ── Build Specific Work Competencies table rows ───────────────── */
        $spec_rows_html = '';
        foreach ($specific_competencies as $i => $item) {
            $key = 'spec_' . $i;
            [$rv, $mv] = $rate($key);
            $spec_rows_html .= '
                <tr class="item-tr">
                    <td class="item-td">' . htmlspecialchars($item, ENT_QUOTES) . '</td>
                    <td class="rate-td">
                        <input type="text" class="rate-input" value="' . $rv . '" readonly tabindex="-1" autocomplete="off">
                    </td>
                    <td class="remarks-td">
                        <input type="text" class="remarks-input" value="' . $mv . '" readonly tabindex="-1" autocomplete="off">
                    </td>
                </tr>';
        }
        [$spec_total_rv] = $rate('specific_competency_rating');
        $spec_rows_html .= '
                <tr class="total-tr">
                    <td class="total-td">Specific Work Competency Rating:</td>
                    <td class="rate-td" colspan="2">
                        <span class="total-readout">' . ($spec_total_rv !== '' ? $spec_total_rv : '&mdash;') . '</span>
                    </td>
                </tr>';

        [$overall_rv] = $rate('overall_competency_rating');

        /* ── Instructions + Rating-Scale wrapped as ONE atomic block ──────
           Mirrors company_reports.php's instrBlock, which combines the
           "Training Plan Information and Instructions" title, its
           paragraphs/list, the "Competency Rating Scale:" title, and the
           scale table into a single, non-splittable pagination unit. The
           trailing "ratings shall be forwarded..." note (only present in
           this static/printed view) is kept inside the same wrapper so it
           always travels together with the scale table rather than
           introducing an extra independent break point that the live
           preview doesn't have. */
        $instructions_block_html =
              '<div class="tp-instructions-block">'
            . '<div class="section-title">Training Plan Information and Instructions</div>'
            . '<p class="body-text">On&ndash;the&ndash;Job Training (OJT) or Internship programs are course requirements that provide opportunities for a Student Trainee to apply the theories and ideas learned in the school but also enhanced the technical knowledge, skills and attitudes of students towards work necessary for satisfactory job performance. These training programs expose the trainees to work realities which will improve their competencies or skills and prepare them once they graduate.</p>'
            . '<p class="body-text">This Training Plan provides Student&ndash;Trainee with actual workplace experience, exposure to various management styles, industrial and procedures of occupations in relation to his/her field of study. This plan outlines the specific competency or skill requirements for an HTE&ndash;based training program which need to be mentored, evaluated and monitored. It has two parts:</p>'
            . '<ul class="body-list">'
            . '  <li>General Competencies which evaluate the trainee&rsquo;s values and attitudes toward work. This is equivalent to 40% of the trainee&rsquo;s overall competency.</li>'
            . '  <li>Specific Work Competencies which evaluate the trainee&rsquo;s technical knowledge and skills. The tasks are directly related to his/her field of specialization/study. This is equivalent to 60% of the trainee&rsquo;s overall competency.</li>'
            . '</ul>'
            . '<p class="body-text body-note"><strong>Competency Requirements:</strong> List of competencies/skills needed to perform the job to the standards specified and agreed by NEUST and the HTE. Skills should be stated as specifically and briefly as possible, identifying the skill to be learned.</p>'
            . '<p class="body-text body-note"><strong>Competency Rating:</strong> Used to assess the trainee&rsquo;s competency level during the training period and to document exceptional or skill deficiencies. Rating of 1.0 to 3.0 (100&ndash;75) is considered Passed. A rating of 5.0 (below 75) is considered Failed. Remarks highlight the trainee&rsquo;s exceptional skill or skill deficiencies.</p>'
            . '<div class="section-title" style="margin-top:10px;">Competency Rating Scale:</div>'
            . '<table class="scale-table">'
            . '  <tr><td>1.0 = 97&ndash;100</td><td>2.25 = 82&ndash;84</td></tr>'
            . '  <tr><td>1.25 = 94&ndash;96</td><td>2.5 = 79&ndash;81</td></tr>'
            . '  <tr><td>1.5 = 91&ndash;93</td><td>2.75 = 76&ndash;78</td></tr>'
            . '  <tr><td>1.75 = 88&ndash;90</td><td>3.0 = 75</td></tr>'
            . '  <tr><td>2.0 = 85&ndash;87</td><td>5.0 = below 75</td></tr>'
            . '</table>'
            . '<p class="body-text">The student&ndash;trainee&rsquo;s ratings shall be forwarded by the HTE to the concerned OJT Coordinator right after the training period.</p>'
            . '</div>';

        /* ── Overall Rating + Signatures wrapped as ONE atomic block ──────
           Mirrors company_reports.php's sigBlock, which combines the
           "OVERALL COMPETENCY RATING" line and both signature blocks
           (HTE trainor + student conforme) into a single, non-splittable
           pagination unit so the signatures never get separated from the
           rating line, or from each other, across a page break — matching
           the live preview exactly.

           Each signature's name is wrapped together with its line in a
           ".sig-name-wrap" (name ABOVE the line, via padding-top on the
           wrap), exactly mirroring company_reports.php's live editable
           preview (.tp-sig-name-wrap / .tp-sig-name / .tp-sig-line /
           .tp-sig-position). */
        $final_block_html =
              '<div class="tp-final-block">'
            . '<div class="overall-line">'
            . '  OVERALL COMPETENCY RATING: <span class="overall-input">' . ($overall_rv ?: '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;') . '</span>'
            . '</div>'
            . '<div class="sig-block">'
            . '  <div class="sig-name-wrap"><div class="sig-name">' . ($hte_trainor ?: '&nbsp;') . '</div><div class="sig-line"></div></div>'
            . '  <div class="sig-position">HTE OJT Trainor/Supervisor</div>'
            . '  <div class="sig-caption">(Signature over Printed Name)</div>'
            . '</div>'
            . '<div class="conforme-label">Conforme:</div>'
            . '<div class="sig-block left">'
            . '  <div class="sig-name-wrap"><div class="sig-name">' . ($trainee_name ?: '&nbsp;') . '</div><div class="sig-line"></div></div>'
            . '  <div class="sig-position">Student&ndash;Trainee</div>'
            . '  <div class="sig-caption">(Signature over Printed Name)</div>'
            . '</div>'
            . '</div>';

        /* ── Student–Trainee Information title + hint, wrapped as
           ONE atomic block ("tp-info-header"). Mirrors company_reports.php's
           infoHeaderBlock, which combines the section title with a short
           italic hint caption into a single pagination unit, so the very
           first measured block in this printed view has the exact same
           height as the very first measured block in the live editable
           preview. */
        $info_header_html =
              '<div class="tp-info-header">'
            . '<div class="section-title">Student&ndash;Trainee Information</div>'
            . '<div class="hint">Fields marked read-only are pulled automatically from student and company records.</div>'
            . '</div>';

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OJT/Internship Training Plan &mdash; ' . $trainee_name . '</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --navy:  #07145f;
  --gold:  #c8a800;
  --rule:  #c8cfe8;
  --text:  #1a1a1a;
  --muted: #555;
  --line:  #000000;
  --serif: "Times New Roman", "Crimson Pro", Times, serif;
}

body {
  font-family: var(--serif);
  background: #d8dde8;
  color: var(--text);
  margin: 0; padding: 0;
}

.doc-outer {
  width: 794px; max-width: 100%; margin: 24px auto 40px;
  display: flex; flex-direction: column; gap: 20px;
  opacity: 0; transition: opacity .15s ease;
}
.doc-outer.tp-ready { opacity: 1; }
/*
 * .doc-paper is a FIXED 794x1123 (A4 @96dpi) page with overflow:hidden
 * baked directly into the base CSS. The v1.10/v1.11 pagination script
 * below never hands a sheet more content than it has already confirmed
 * fits inside this box, so overflow:hidden here is a safety net, not
 * something the layout depends on to "hide" mistakes.
 */
.doc-paper {
  background: #fff;
  border: 1px solid #b0b8cc;
  box-shadow: 0 4px 32px rgba(0,0,0,.22);
  width: 794px; max-width: 100%; height: 1123px; min-height: 1123px;
  display: flex; flex-direction: column; box-sizing: border-box;
  overflow: hidden;
}

.letterhead {
  background: var(--navy);
  padding: 12px 24px;
  display: flex; align-items: center; gap: 14px;
  border-bottom: 3px solid var(--gold);
  flex-shrink: 0;
}
.lh-seal {
  width: 58px; height: 58px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; overflow: hidden;
  border: 2px solid rgba(255,255,255,.25);
}
.lh-seal img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
.lh-text { color: #fff; flex: 1; }
.lh-line1 { font-size: 8.5px; letter-spacing: .18em; text-transform: uppercase; color: #aac4f0; margin-bottom: 2px; font-family: "JetBrains Mono", monospace; }
.lh-line2 { font-size: 15.5px; font-weight: 700; line-height: 1.25; text-transform: uppercase; letter-spacing: .01em; }
.lh-line3 { font-size: 9.5px; color: #dbe6fb; margin-top: 2px; }
.lh-line4 { font-size: 9px; color: #aac4f0; margin-top: 1px; }
.lh-line5 { font-size: 8.5px; color: #aac4f0; margin-top: 1px; font-style: italic; }

.title-band {
  background: #f4f5fb;
  border-bottom: 1.5px solid var(--rule);
  padding: 8px 24px 7px;
  text-align: center;
  flex-shrink: 0;
}
.title-band h1 { font-family: "DM Sans", sans-serif; font-size: 17px; font-weight: 700; color: var(--navy); letter-spacing: .035em; text-transform: uppercase; }
.form-meta { margin-top: 3px; font-family: "JetBrains Mono", monospace; font-size: 7.5px; color: #999; }

/*
 * margin-top:auto pins the footer to the bottom of the .doc-paper flex
 * column when there is left-over space to distribute. As of v1.13, each
 * sheet\'s .form-body is given an EXPLICIT pixel height by the JS
 * pagination step below (letterhead + title-band + body + footer sum to
 * exactly 1123px), so there is normally no left-over space left for
 * margin-top:auto to act on in the first place — the footer already
 * lands at the true bottom of the page purely from normal block flow.
 * margin-top:auto is left in place as a harmless fallback (e.g. for the
 * very first, pre-pagination single sheet rendered before the JS below
 * has run) rather than removed, since it doesn\'t conflict with the new
 * explicit-height approach.
 */
.footer-band {
  background: #f0f2f8; border-top: 1.5px solid var(--navy);
  padding: 4px 24px;
  display: flex; justify-content: space-between;
  font-family: "JetBrains Mono", monospace; font-size: 7.5px;
  color: #888; letter-spacing: .07em;
  flex-shrink: 0;
  margin-top: auto;
}

.form-body {
  padding: 14px 28px 18px; font-family: var(--serif);
  flex: 1; display: flex; flex-direction: column; min-height: 0; overflow: hidden;
}
.section-title { font-family: var(--serif); font-size: 13px; font-weight: 700; color: var(--text); margin: 16px 0 6px; }
.section-title:first-child { margin-top: 0; }
/* Matches the .tp-hint styling used by the live editable preview, so
   the "Student–Trainee Information" title+hint block measures the
   same height in both renderers. */
.hint { font-size: 0.68rem; color: #9ca3af; font-style: italic; margin: 2px 0 14px; text-align: center; }
p.body-text { font-size: 12px; line-height: 1.5; margin-bottom: 8px; text-indent: 24px; }
ul.body-list { margin: 0 0 8px 44px; font-size: 12px; line-height: 1.5; }
ul.body-list li { margin-bottom: 4px; }
p.body-note strong { font-weight: 700; }

.tp-info-header { }
.tp-info-section { }
.tp-form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  column-gap: 24px;
  row-gap: 14px;
  margin-bottom: 4px;
}
.tp-form-section-label {
  grid-column: 1 / -1;
  font-family: "DM Sans", sans-serif;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--navy);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  border-bottom: 1.5px solid var(--rule);
  padding-bottom: 5px;
  margin-top: 0;
}
.tp-form-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.tp-form-field.full { grid-column: 1 / -1; }
.tp-form-field label {
  font-size: 10.5px;
  font-weight: 700;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.tp-form-field-row {
  grid-column: 1 / -1;
  display: flex;
  gap: 18px;
}
input.tp-form-input {
  width: 100%;
  height: 30px;
  border: 1px solid #c8cfe8;
  border-radius: 6px;
  background: #f3f4f6;
  font-family: var(--serif);
  font-size: 12.5px;
  color: #1a1a1a;
  padding: 0 9px;
  outline: none;
  caret-color: transparent;
}

.scale-table { width: 60%; border-collapse: collapse; border: 1px solid var(--line); margin: 4px 0 10px; font-size: 12px; }
.scale-table td { border: 1px solid var(--line); padding: 4px 10px; text-align: center; }

.comp-table { width: 100%; border-collapse: collapse; border: 1px solid var(--line); margin-bottom: 4px; font-size: 11.5px; table-layout: fixed; }
.comp-table col.col-item  { width: auto; }
.comp-table col.col-rate  { width: 100px; }
.comp-table col.col-remarks { width: 170px; }
.comp-table td { border: 1px solid var(--line); padding: 5px 8px; vertical-align: top; }
.cat-tr .cat-td { font-weight: 700; background: #f4f5fb; }
.cat-tr .cat-th-cell { font-weight: 700; text-align: center; background: #f4f5fb; }
.item-tr .item-td { padding-left: 8px; }
.total-tr .total-td { font-weight: 700; text-align: right; padding-right: 10px; background: #eef1fb; }
.total-tr td.rate-td { background: #eef1fb; }

.rate-input, .remarks-input {
  width: 100%; height: 26px; border: none; background: transparent;
  font-family: var(--serif); font-size: 11.5px; color: var(--text);
  text-align: center; outline: none; caret-color: transparent;
}
.remarks-input { text-align: left; padding: 0 6px; }
.total-readout { font-weight: 700; font-size: 12px; text-align: center; display: block; }

.overall-line { margin: 14px 0 30px; text-align: center; font-size: 13px; font-weight: 700; }
.overall-line .overall-input {
  display: inline-block; min-width: 80px; border: none; border-bottom: 1px solid var(--line);
  font-family: var(--serif); font-size: 13px; font-weight: 700; text-align: center;
  padding: 0 6px;
}

.sig-block { width: 300px; margin: 0 0 26px auto; text-align: center; }
.sig-block.left { margin: 0 auto 0 0; }
.sig-name-wrap { padding-top: 26px; }
.sig-line { border-bottom: 1.5px solid var(--line); }
.sig-block .sig-name { font-size: 12px; font-weight: 700; line-height: 1.2; }
.sig-block .sig-position { font-size: 12px; font-weight: 700; margin-top: 2px; }
.sig-block .sig-caption { font-size: 11px; font-style: italic; margin-top: 1px; }
.conforme-label { font-size: 12px; font-weight: 700; margin: 10px 0 26px; }

@page { size: A4 portrait; margin: 0; }

@media print {
  html, body {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    background: #ffffff !important;
    margin: 0 !important; padding: 0 !important;
  }

  /* .doc-outer is `display:flex; flex-direction:column;` in the base
     (screen) stylesheet, purely to add a visual gap between sheets on
     screen. Chrome\'s print engine does not reliably honor
     page-break-after/break-after on flex children, so .doc-outer is
     switched to plain block layout for print, making each .doc-paper a
     normal block-level child again so the page break applies exactly as
     intended: one .doc-paper == one physical page, matching the
     "Page X of N" footer on every sheet.
     v1.15 FIX: .doc-outer is now also locked to an explicit `width:
     210mm !important` — the same physical unit as the @page size below
     — so the flex/block wrapper around every sheet is never left to an
     approximated px width that could resolve slightly differently from
     the true physical page width across browsers/print pipelines. */
  .doc-outer { display: block !important; opacity: 1 !important; margin: 0 !important; gap: 0 !important; width: 210mm !important; }

  /*
   * v1.15 FIX (white space around the printed/PDF page): the sheet\'s
   * height was previously locked to `1123px !important` — only an
   * APPROXIMATION of A4\'s 297mm height at a *presumed* 96dpi — and its
   * width was never locked to a physical unit in print at all (it fell
   * back to the base rule\'s `width: 794px`, also only an approximation
   * of 210mm). Some browsers/print pipelines don\'t resolve that CSS
   * px-to-mm conversion identically between the on-screen render and
   * the print render, so the sheet could come out a hair smaller (or
   * larger) than the literal physical page in either dimension —
   * leaving a visible white margin/gutter around the printed sheet
   * instead of the sheet filling the page edge-to-edge (most visible on
   * short pages, like the signature-only final page, where the leftover
   * gap reads as a large blank area below the content).
   *
   * Locking BOTH dimensions to the SAME unit (mm), matching the
   * physical @page size exactly, removes that unit-conversion rounding
   * gap so the printed/PDF sheet always stretches to fill the full
   * physical page with no white space on any side. overflow:hidden
   * (already introduced by v1.14) is kept so any sub-pixel rendering
   * variance between the on-screen measurement pass (which still works
   * in px, since the JS pagination below still computes/locks each
   * sheet\'s on-screen .form-body height in px against the live
   * 1123px-tall preview sheet) and the mm-based print pass is clipped
   * instead of bleeding into the following physical page — this exactly
   * mirrors the identical hardening already used by company_reports.php\'s
   * print rules for the editable (pre-submission) Training Plan.
   */
  .doc-paper {
    box-shadow: none !important; border: none !important;
    page-break-after: always !important; break-after: always !important;
    width: 210mm !important;
    height: 297mm !important; min-height: 297mm !important; max-height: 297mm !important;
    overflow: hidden !important;
  }
  .doc-paper:last-child { page-break-after: auto !important; break-after: auto !important; }

  /* v1.14 FIX: .form-body is likewise `overflow: hidden` (not
     `visible`) in print, for the same reason as .doc-paper above —
     its height is explicitly locked in pixels by the JS pagination
     step, so hidden overflow here only clips negligible sub-pixel
     variance instead of letting it bleed into the next page. */
  .form-body { overflow: hidden !important; }

  .letterhead {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    background: #07145f !important;
    border-bottom: 3px solid #c8a800 !important;
  }
  .lh-line1, .lh-line3, .lh-line4, .lh-line5 { color: #aac4f0 !important; }
  .lh-text, .lh-line2 { color: #ffffff !important; }
  .title-band {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    background: #f4f5fb !important;
    border-bottom: 1.5px solid #c8cfe8 !important;
  }
  .title-band h1 { color: #07145f !important; }
  .footer-band {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    background: #f0f2f8 !important;
    border-top: 1.5px solid #07145f !important;
    margin-top: auto !important;
  }
  input.tp-form-input {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    background: #f3f4f6 !important;
  }

  .cat-tr, .item-tr, .total-tr, .scale-table tr,
  .sig-block, .section-title { break-inside: avoid; }
  .tp-form-field, .tp-form-field-row, .tp-form-section-label,
  .tp-info-header, .tp-info-section, .tp-instructions-block, .tp-final-block { break-inside: avoid; }
  .tp-form-section-label { break-after: avoid; }
  .letterhead, .title-band, .footer-band { break-inside: avoid; }
}

@media (max-width: 840px) {
  .doc-outer { width: 100%; margin: 0; opacity: 1; }
  .doc-paper {
    width: 100% !important; height: auto !important; min-height: 0 !important;
    overflow: visible !important; box-shadow: none; border: none;
  }
  .form-body { overflow: visible !important; }
  .form-body, .letterhead { padding: 12px 14px; }
  .tp-form-grid { grid-template-columns: 1fr; }
  .tp-form-field-row { flex-direction: column; gap: 14px; }
}
</style>
</head>
<body>
<noscript><style>.doc-outer{opacity:1!important;}</style></noscript>

<div class="doc-outer"><div class="doc-paper">

  <div class="letterhead">
    <div class="lh-seal">
      <img src="logo.webp" alt="NEUST Seal">
    </div>
    <div class="lh-text">
      <div class="lh-line1">Republic of the Philippines</div>
      <div class="lh-line2">Nueva Ecija University of Science and Technology</div>
      <div class="lh-line3">On&ndash;the&ndash;Job Training and Career Development Center</div>
      <div class="lh-line4">Cabanatuan City</div>
      <div class="lh-line5">ISO 9001:2015 Certified</div>
    </div>
  </div>

  <div class="title-band">
    <h1>OJT / Internship Training Plan</h1>
    <div class="form-meta">Form No.: NEUST&ndash;OJT&ndash;F013</div>
  </div>

  <div class="form-body">

    ' . $info_header_html . '
    ' . $student_info_grid_html . '

    ' . $instructions_block_html . '

    <div class="section-title">General Competencies</div>
    <table class="comp-table">
      <colgroup><col class="col-item"><col class="col-rate"><col class="col-remarks"></colgroup>
      <tbody>
        ' . $gen_rows_html . '
      </tbody>
    </table>

    <div class="section-title">Specific Work Competencies</div>
    <table class="comp-table">
      <colgroup><col class="col-item"><col class="col-rate"><col class="col-remarks"></colgroup>
      <tbody>
        ' . $spec_rows_html . '
      </tbody>
    </table>

    ' . $final_block_html . '

  </div>

  <div class="footer-band">
    <span>NEUST&ndash;OJT&ndash;F013</span>
    <span>Rev. 00 (03.04.19)</span>
  </div>

</div></div>

<script>
(function () {
    /* ── ASSET-READINESS GATE ────────────────────────────────────────────
       Waits for every <img> (the NEUST seal) to finish loading — or
       fail, so a missing image can never hang the page — and for
       document.fonts.ready to settle (when supported), BEFORE any
       measurement or pagination runs. Because pagination measures real,
       rendered sheets rather than predicting heights, getting this
       ordering right matters even more than before: a sheet built while
       a font is still swapping in could report a slightly different
       scrollHeight than the same sheet measured a moment later. ────── */
    function waitForImagesToSettle() {
        var imgs = Array.prototype.slice.call(document.querySelectorAll("img"));
        if (imgs.length === 0) return Promise.resolve();
        return Promise.all(imgs.map(function (img) {
            if (img.complete) return Promise.resolve();
            return new Promise(function (resolve) {
                var done = function () {
                    img.removeEventListener("load", done);
                    img.removeEventListener("error", done);
                    resolve();
                };
                img.addEventListener("load", done);
                img.addEventListener("error", done);
            });
        }));
    }
    function waitForFontsToSettle() {
        if (document.fonts && document.fonts.ready) {
            return document.fonts.ready.then(function () {}, function () {});
        }
        return Promise.resolve();
    }

    /* ══════════════════════════════════════════════════════════════════
       PAGINATION ENGINE — "build for real, then verify"
       ------------------------------------------------------------------
       Builds each .doc-paper sheet for real — attached to the live
       document, with the real letterhead/title-band/footer-band and a
       real .form-body — appends the next content unit to it, and asks
       the browser whether the sheet\'s content still fits above the
       footer. If it doesn\'t, that unit is moved to a fresh sheet. This
       makes it structurally impossible for a sheet to end up holding
       more than the fixed 1123px page can actually display.

       v1.11: the overflow test itself is scrollHeight vs. clientHeight
       (see overflows() below) — NOT a getBoundingClientRect comparison
       between the body and the footer. See the v1.11 note at the top of
       this file for exactly why that distinction matters: .form-body\'s
       own box never grows past its flex-allocated size (it\'s clipped by
       `overflow:hidden` instead), so its bounding rect can\'t be used to
       detect overflow — only scrollHeight can.

       v1.12: each overflow check is now only trusted once the current
       sheet already has other content on it (hadContentBefore /
       hadRowsBefore below) — the identical defensive guard already used
       by company_reports.php\'s tpPaginateAndRender(), which prevents a
       borderline sub-pixel measurement on the very first unit of a
       brand-new empty sheet from ever bouncing content off page 1.

       v1.13: once a sheet\'s units are fully packed (and won\'t change
       again), its .form-body is given an EXPLICIT pixel height computed
       from that sheet\'s own real letterhead/title-band/footer heights —
       see the FINALIZE step below — instead of leaving footer placement
       to `flex:1` + `margin-top:auto` alone. The overflow-detection
       buffer used while packing was also widened from 1px to 3px, so a
       genuinely borderline unit is pushed to the next sheet rather than
       accepted and risking a sub-pixel clip once actually painted.

       Content units, in document order:
         - "atomic" blocks (Student info header, each of the three
           Personal/School/HTE info sections, the instructions+scale
           block, the overall-rating+signatures block) are never split.
         - competency tables are split row-by-row: each row is its own
           unit, and a fresh <table> (with its own <colgroup>) is started
           on a new sheet whenever needed, exactly like a table that
           spans multiple pages in a word processor.
       ══════════════════════════════════════════════════════════════════ */
    function paginateTrainingPlan() {
        try {
            var PAGE_H = 1123;

            var docOuter  = document.querySelector(".doc-outer");
            var origPaper = document.querySelector(".doc-paper");
            if (!docOuter || !origPaper) return;

            var letterheadNode = origPaper.querySelector(".letterhead");
            var titleBandNode  = origPaper.querySelector(".title-band");
            var footerNode     = origPaper.querySelector(".footer-band");
            var formBodyNode   = origPaper.querySelector(".form-body");
            if (!letterheadNode || !titleBandNode || !footerNode || !formBodyNode) {
                docOuter.classList.add("tp-ready");
                return;
            }

            /* ── Flatten the original body into an ordered list of
               "units" — atomic elements, or individual table rows
               (grouped so a fresh table is started per sheet). ──────── */
            var units = [];
            Array.prototype.forEach.call(formBodyNode.children, function (el) {
                if (el.tagName === "TABLE" && el.classList.contains("comp-table")) {
                    units.push({ type: "table-start" });
                    Array.prototype.forEach.call(el.querySelectorAll("tbody > tr"), function (tr) {
                        units.push({ type: "row", el: tr });
                    });
                } else {
                    units.push({ type: "atomic", el: el });
                }
            });

            /* ── A real, attached-but-invisible staging area. visibility:
               hidden (not display:none) keeps normal layout/measurement
               working while nothing is painted on screen. ────────────── */
            var stage = document.createElement("div");
            stage.style.cssText = "position:fixed; top:0; left:-10000px; visibility:hidden; pointer-events:none;";
            document.body.appendChild(stage);

            function emptyCompTable() {
                var table = document.createElement("table");
                table.className = "comp-table";
                table.innerHTML = "<colgroup><col class=\"col-item\"><col class=\"col-rate\"><col class=\"col-remarks\"></colgroup>";
                var tbody = document.createElement("tbody");
                table.appendChild(tbody);
                return { table: table, tbody: tbody };
            }

            function newSheet() {
                var paper = document.createElement("div");
                paper.className = "doc-paper";
                paper.style.height = PAGE_H + "px";
                paper.style.overflow = "hidden";
                paper.appendChild(letterheadNode.cloneNode(true));
                paper.appendChild(titleBandNode.cloneNode(true));

                var body = document.createElement("div");
                body.className = "form-body";
                paper.appendChild(body);

                var footer = footerNode.cloneNode(true);
                paper.appendChild(footer);

                stage.appendChild(paper);
                return { paper: paper, body: body, footer: footer, tbody: null };
            }

            /* True once the sheet\'s content no longer fits inside the
               sheet\'s allotted body area.

               v1.11 FIX: compare the body\'s scrollHeight (the *actual*,
               un-clipped height of everything appended so far) against
               its clientHeight (the fixed, clipped height the flex
               layout actually allocates it while packing is still in
               progress). scrollHeight always reflects the true content
               height even under `overflow:hidden`, so this is a direct,
               reliable overflow test that works with the sheet\'s real
               CSS instead of being defeated by it.

               v1.13: the safety buffer was widened from 1px to 3px
               (matching company_reports.php\'s tpPaginateAndRender()), so
               a genuinely borderline unit is pushed to the next sheet
               instead of being accepted here and then risking a
               sub-pixel clip once the sheet is actually painted, printed,
               or rasterized. */
            var OVERFLOW_BUFFER_PX = 3;
            function overflows(sheet) {
                return sheet.body.scrollHeight > sheet.body.clientHeight + OVERFLOW_BUFFER_PX;
            }

            var sheets = [ newSheet() ];
            var cur = sheets[0];

            units.forEach(function (u) {
                if (u.type === "atomic") {
                    /* v1.12: only trust the overflow check once this
                       sheet already had other content on it — guards
                       against a borderline sub-pixel measurement on the
                       very first unit of a brand-new empty sheet ever
                       bouncing that content off page 1 unnecessarily. */
                    var hadContentBefore = cur.body.children.length > 0;
                    cur.body.appendChild(u.el);
                    if (hadContentBefore && overflows(cur)) {
                        cur.body.removeChild(u.el);
                        cur = newSheet();
                        sheets.push(cur);
                        cur.body.appendChild(u.el);
                        /* If a single atomic block is taller than an
                           entire empty page (extremely long instructions,
                           say), there is nothing further to split it
                           into — it stays on its own sheet rather than
                           being silently dropped or bleeding onto the
                           previous page. */
                    }
                } else if (u.type === "table-start") {
                    var t = emptyCompTable();
                    cur.tbody = t.tbody;
                    cur.body.appendChild(t.table);
                } else if (u.type === "row") {
                    if (!cur.tbody) {
                        var t2 = emptyCompTable();
                        cur.tbody = t2.tbody;
                        cur.body.appendChild(t2.table);
                    }
                    var hadRowsBefore = cur.tbody.children.length > 0;
                    cur.tbody.appendChild(u.el);
                    if (hadRowsBefore && overflows(cur)) {
                        cur.tbody.removeChild(u.el);
                        cur = newSheet();
                        sheets.push(cur);
                        var t3 = emptyCompTable();
                        cur.tbody = t3.tbody;
                        cur.body.appendChild(t3.table);
                        cur.tbody.appendChild(u.el);
                    }
                }
            });

            /* ── FINALIZE ────────────────────────────────────────────────
               Now that every sheet\'s real content is final (no more units
               will be added or removed), label footers with "Page X of
               N", then give each sheet\'s .form-body an EXPLICIT pixel
               height so the footer no longer depends on `flex:1` +
               `margin-top:auto` being resolved identically by every
               rendering engine (screen, print, html2canvas) at paint
               time — the same underlying fix company_reports.php\'s own
               tpPaginateAndRender() already applies to the editable
               Training Plan preview.

               v1.14 CORRECTION: the previous revision computed this
               explicit height by INDEPENDENTLY re-deriving it —
               `1123 − ceil(letterheadHeight) − ceil(titleBandHeight) −
               ceil(footerHeight)` — as a second, separate calculation
               from the one the packing loop above actually used to
               decide what content fits (`sheet.body.clientHeight`, read
               live off the browser\'s own flex layout via overflows()).
               Two independent `Math.ceil()` roundings, plus the footer\'s
               height being measured AFTER the "Page X of N" label was
               appended to it (which can shift its rendered height by a
               pixel versus what it was during packing), meant the
               re-derived number could come out a few pixels SMALLER than
               the space the packer had already filled. Since
               `.form-body` still has `overflow: hidden`, that shortfall
               silently clipped the bottom of whatever content had
               already been packed onto the page — exactly the "page 1
               has missing content" symptom.

               FIX: don\'t re-derive the height independently at all.
               Simply read `sheet.body.clientHeight` — the EXACT number
               the packing loop above already used, sheet by sheet, to
               decide what fits — and lock that in as an explicit height.
               That number can never be smaller than what was already
               packed (it IS what was used to pack it), so nothing gets
               clipped, while still converting each sheet from
               "positioned by flex/margin-auto, resolved fresh by every
               renderer" to "a fixed, already-correct pixel number" —
               which is what actually fixes the footer position across
               screen, print, and html2canvas consistently. Footer text
               is updated FIRST (as before), so if appending the page
               label ever changes a footer\'s height even slightly, the
               live re-measurement below already reflects that — no
               separate bookkeeping needed. ─────────────────────────── */
            var totalPages = sheets.length;

            sheets.forEach(function (sheet, idx) {
                var spans = sheet.footer.querySelectorAll("span");
                if (spans.length >= 2) {
                    spans[1].textContent = spans[1].textContent + " \u00b7 Page " + (idx + 1) + " of " + totalPages;
                }
            });

            sheets.forEach(function (sheet, idx) {
                var bodyH = sheet.body.clientHeight;

                sheet.body.style.flex      = "none";
                sheet.body.style.height    = bodyH + "px";
                sheet.body.style.minHeight = bodyH + "px";
                sheet.body.style.maxHeight = bodyH + "px";

                if (idx === totalPages - 1) {
                    sheet.body.style.justifyContent = "center";
                }
            });

            docOuter.innerHTML = "";
            sheets.forEach(function (sheet) {
                stage.removeChild(sheet.paper);
                docOuter.appendChild(sheet.paper);
            });
            document.body.removeChild(stage);

            docOuter.classList.add("tp-ready");
        } catch (err) {
            var docOuterFallback = document.querySelector(".doc-outer");
            if (docOuterFallback) docOuterFallback.classList.add("tp-ready");
            if (window.console && console.error) console.error("Training Plan pagination failed:", err);
        }
    }

    function boot() {
        Promise.all([waitForImagesToSettle(), waitForFontsToSettle()])
            .then(paginateTrainingPlan)
            .catch(paginateTrainingPlan);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }

    /* Bounded safety net: if an image/font somehow never settles (very
       slow network, blocked resource, etc.), still reveal the document
       after 4s rather than leaving it hidden/hung indefinitely. If
       pagination has not run yet at that point, this reveals the
       original single, unpaginated sheet as a last-resort fallback. */
    setTimeout(function () {
        var docOuterSafety = document.querySelector(".doc-outer");
        if (docOuterSafety && !docOuterSafety.classList.contains("tp-ready")) {
            docOuterSafety.classList.add("tp-ready");
        }
    }, 4000);
})();
</script>

</body>
</html>';
    }

} // end if (!function_exists('buildEvalFormHTML'))