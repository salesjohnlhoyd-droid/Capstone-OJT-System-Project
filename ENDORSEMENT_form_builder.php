<?php
/**
 * ENDORSEMENT_form_builder.php
 *
 * Provides buildEndorsementFormHTML(array $s): string
 *
 * Call this file with:
 *   require_once 'ENDORSEMENT_form_builder.php';
 *
 * Then call:
 *   echo buildEndorsementFormHTML($endorsement_data);
 *
 * $endorsement_data keys expected:
 *   department_name      – College / department issuing the letter
 *                          (e.g. "College of Information and Communications Technology")
 *   letter_date          – Date of the letter (free text, e.g. "July 15, 2026")
 *   recipient_name       – Name of the addressee (e.g. "Mr. Juan A. Dela Cruz")
 *   recipient_position   – (optional) Position of the addressee (e.g. "HR Manager")
 *   company_name         – (optional) Company / office of the addressee
 *   recipient_address    – Address of the addressee / company
 *   salutation_name      – (optional) Name used after "Dear". Defaults to recipient_name.
 *   program              – Program / course of the students (e.g. "BS Information Technology")
 *   required_hours       – Required number of OJT hours (e.g. "486")
 *   start_date           – Planned start of training (e.g. "August 2026")
 *   end_date             – Expected end of training (e.g. "November 2026")
 *   students             – Array of student names, OR a newline / semicolon separated string
 *   adviser_name         – OJT Adviser (auto-converted to ALL CAPS)
 *   dean_name            – Dean or Director (auto-converted to ALL CAPS)
 *   dean_title           – (optional) Title under the dean's name. Defaults to "Dean".
 *   director_name        – (optional) OJT-CDC Director under "Noted".
 *                          Defaults to "RANDY M. BAÑEZ, J.D.".
 *   form_no              – (optional) Form number printed in header/footer.
 *   effectivity          – (optional) Effectivity date. Defaults to "09.01.2026".
 *   revision             – (optional) Revision label. Defaults to "Rev. 02 (09.01.2026)".
 *
 * ── LAYOUT / ENGINE ──
 * Same Unified Layout Partition Core Engine and design system as
 * MOA_form_builder.php: rigid 794×1123px (A4) pages, cloned letterhead +
 * title band on every page, "Page X of Y" footer band. Content segments are
 * measured and distributed across pages, so a long student list flows onto
 * page 2 automatically while the signature block is kept together.
 *
 * No other dependencies — safe to include from any page.
 */

if (!function_exists('buildEndorsementFormHTML')) {

    function buildEndorsementFormHTML(array $s): string {
        $esc = fn($v) => htmlspecialchars(trim((string)($v ?? '')), ENT_QUOTES);
        $up  = fn($v) => function_exists('mb_strtoupper') ? mb_strtoupper((string)$v, 'UTF-8') : strtoupper((string)$v);

        /* ── Robust key resolution (same approach as the MOA builder) ── */
        $pick = function(array $keys) use ($s) {
            foreach ($keys as $k) {
                if (!array_key_exists($k, $s) || is_array($s[$k])) continue;
                $v = trim((string)($s[$k] ?? ''));
                if ($v === '' || strcasecmp($v, 'null') === 0) continue;
                return $v;
            }
            return '';
        };

        /* ── Raw values ── */
        $dept        = $esc($pick(['department_name', 'department', 'college', 'college_name']));
        $letter_date = $esc($pick(['letter_date', 'date', 'endorsement_date']));
        $rec_name    = $esc($pick(['recipient_name', 'contact_name', 'addressee']));
        $rec_pos     = $esc($pick(['recipient_position', 'representative_position', 'position']));
        $company     = $esc($pick(['company_name', 'company', 'hte_name']));
        $rec_addr    = $esc($pick(['recipient_address', 'company_address', 'address']));
        $sal_name    = $esc($pick(['salutation_name', 'salutation'])) ?: $rec_name;
        $program     = $esc($pick(['program', 'course', 'program_name']));
        $hours       = $esc($pick(['required_hours', 'hours', 'no_of_hours']));
        $start_date  = $esc($pick(['start_date', 'training_start']));
        $end_date    = $esc($pick(['end_date', 'training_end']));
        $adviser     = $esc($up($pick(['adviser_name', 'ojt_adviser', 'adviser'])));
        $dean        = $esc($up($pick(['dean_name', 'dean', 'director'])));
        $dean_title  = $esc($pick(['dean_title']) ?: 'Dean');
        $director    = $esc($pick(['director_name', 'witness_name']) ?: 'RANDY M. BAÑEZ, J.D.');
        $form_no     = $esc($pick(['form_no']) ?: 'NEUST-OJT-F___');
        $effectivity = $esc($pick(['effectivity']) ?: '09.01.2026');
        $revision    = $esc($pick(['revision']) ?: 'Rev. 02 (09.01.2026)');

        /* ── Students: accept array or delimited string ── */
        $rawStudents = $s['students'] ?? ($s['student_names'] ?? []);
        if (!is_array($rawStudents)) {
            $rawStudents = preg_split('/\r\n|\r|\n|;/', (string)$rawStudents);
        }
        $students = [];
        foreach ($rawStudents as $st) {
            $st = trim((string)$st);
            if ($st !== '' && strcasecmp($st, 'null') !== 0) $students[] = $esc($up($st));
        }

        /* ── Empty-state helpers (identical to MOA builder) ── */
        $e = fn($v) => ($v === '') ? ' empty' : '';
        $d = fn($v, $fallback = '—') => ($v !== '') ? $v : $fallback;
        $en = fn($v) => ($v === '') ? ' empty-name' : '';

        /* ── Pre-built markup pieces ── */
        $rec_pos_line = $rec_pos !== '' ? "<div>{$rec_pos}</div>" : '';
        $company_line = $company !== '' ? "<div><strong>{$company}</strong></div>" : '';

        $student_segments = '';
        if (count($students) === 0) {
            $student_segments = '<div class="end-seg" data-seg="student"><p class="student-name">'
                              . '<span class="fill empty">[NAME OF STUDENT/S]</span></p></div>';
        } else {
            foreach ($students as $i => $st) {
                $n = $i + 1;
                $student_segments .= "<div class=\"end-seg\" data-seg=\"student\"><p class=\"student-name\">"
                                   . "<span class=\"stu-no\">{$n}.</span> {$st}</p></div>\n";
            }
        }

        $students_label = count($students) > 1 ? 'students' : 'student';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Endorsement Letter — {$company}</title>
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════════
   RESET  (same design tokens as MOA_form_builder.php)
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

body {
  font-family: 'DM Sans', sans-serif;
  background: #d8dde8;
  color: var(--text);
  margin: 0; padding: 0;
}

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
.dept-tag.empty { color: #bbb; font-style: italic; }
.title-band h1 {
  font-family: 'DM Sans', sans-serif;
  font-size: 12pt; font-weight: 700;
  color: var(--navy); letter-spacing: .03em; text-transform: uppercase;
}
.form-meta {
  display: flex; justify-content: center; gap: 18pt; margin-top: 2pt;
  font-family: 'JetBrains Mono', monospace; font-size: 5.5pt; color: #888;
}

/* ── Doc body ── */
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

/* ── Letter parts ── */
.letter-date { font-weight: 700; margin: 4pt 0 10pt; }
.inside-address { line-height: 1.45; margin-bottom: 12pt; text-align: left; }
.inside-address .rec-name { font-weight: 700; }
.salutation { font-weight: 700; margin-bottom: 6pt; }
.para { margin-bottom: 6pt; text-indent: 24pt; }
.greet { margin-bottom: 6pt; }

.students-head {
  font-family: 'DM Sans', sans-serif;
  font-size: 8pt; font-weight: 700; letter-spacing: .06em;
  text-transform: uppercase; text-align: center;
  color: var(--navy); margin: 4pt 0 3pt;
}
.student-name {
  text-align: center; font-weight: 700; color: var(--navy);
  line-height: 1.5;
}
.stu-no { color: var(--muted); font-weight: 600; margin-right: 2pt; }
.students-gap { height: 6pt; }

/* ── Signatories (2 × 2 grid) ── */
.sign-block { margin-top: 12pt; }
.sign-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  column-gap: 30pt;
  row-gap: 16pt;
}
.sign-cell { display: flex; flex-direction: column; }
.sign-lead { margin-bottom: 22pt; text-align: left; }
.sign-line {
  border-bottom: 1.5px solid var(--text);
  width: 85%;
  margin-bottom: 3pt;
}
.sign-name {
  font-weight: 700; font-size: 9.5pt; color: var(--navy);
  text-transform: uppercase; width: 85%; text-align: center;
}
.sign-name.empty-name { color: #bbb; font-weight: 400; font-style: italic; text-transform: none; }
.sign-title {
  font-size: 8.5pt; color: var(--muted); line-height: 1.35;
  width: 85%; text-align: center;
}
.sign-title em { font-style: italic; }

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

/* ── Hidden blueprints ── */
#raw-source-data,
#header-clone,
#footer-clone {
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
  .doc-outer { margin: 0 !important; gap: 0 !important; }
  .doc-paper {
    box-shadow: none !important;
    border: none !important;
    page-break-after: always !important;
    break-after: always !important;
  }
}

@media (max-width: 640px) {
  .doc-outer { width: 100%; }
  .sign-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ══ HIDDEN HEADER CLONE ══ -->
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
    <div class="dept-tag{$e($dept)}">{$d($dept, '[NAME OF DEPARTMENT]')}</div>
    <h1>Endorsement Letter</h1>
    <div class="form-meta">
      <span>Form No.: {$form_no}</span>
      <span>Effectivity: {$effectivity}</span>
    </div>
  </div>
</div>

<!-- ══ HIDDEN FOOTER CLONE ══ -->
<div id="footer-clone">
  <div class="footer-band" style="margin-top:0;">
    <span>{$form_no}</span>
    <span class="pg-num">Page <span class="pg-cur">1</span> of <span class="pg-total">1</span></span>
    <span>{$revision}</span>
  </div>
</div>

<!-- ══ RAW SOURCE DATA ══ -->
<div id="raw-source-data">

  <!-- SEGMENT: date + inside address + salutation + body paragraph -->
  <div class="end-seg" data-seg="intro">
    <p class="letter-date"><span class="fill{$e($letter_date)}">{$d($letter_date, '[DATE]')}</span></p>
    <div class="inside-address">
      <div class="rec-name"><span class="fill{$e($rec_name)}">{$d($rec_name, '[NAME]')}</span></div>
      {$rec_pos_line}
      {$company_line}
      <div><span class="fill{$e($rec_addr)}" style="font-weight:400;">{$d($rec_addr, '[ADDRESS]')}</span></div>
    </div>
    <p class="salutation">Dear <span class="fill{$e($sal_name)}">{$d($sal_name, '[NAME]')}</span>,</p>
    <p class="greet">Greetings!</p>
    <p class="para">
      We take this opportunity to formally endorse our {$students_label} listed below to undergo
      On-the-Job Training to fulfill the requirements in the program
      <span class="fill{$e($program)}"><em>{$d($program, '[COURSE]')}</em></span>
      for a period of
      <span class="fill{$e($hours)}"><em>{$d($hours, '[NO. OF HOURS]')}</em></span> hours.
      Their training is planned to start on
      <span class="fill{$e($start_date)}">{$d($start_date, '[START MONTH YEAR]')}</span>
      and expected to end on
      <span class="fill{$e($end_date)}">{$d($end_date, '[END MONTH YEAR]')}</span>.
    </p>
    <p class="students-head">Name of Student/s</p>
  </div>

  <!-- SEGMENTS: one per student (lets long lists flow to the next page) -->
  {$student_segments}

  <!-- SEGMENT: closing paragraphs -->
  <div class="end-seg" data-seg="closing">
    <div class="students-gap"></div>
    <p class="para">
      In view of the foregoing, we are enlisting your support and cooperation for the accommodation
      of the said applicants in your office where they will undergo their training. Please rest assured
      that our students will observe and comply with the company's policies and at our level, we are
      prepared to assume whatever responsibility may be deemed necessary.
    </p>
    <p class="para">Thank you very much for your anticipated support and cooperation.</p>
  </div>

  <!-- SEGMENT: signatories (kept together on one page) -->
  <div class="end-seg" data-seg="signing">
    <div class="sign-block">
      <div class="sign-grid">
        <div class="sign-cell">
          <div class="sign-lead">Very truly yours,</div>
          <div class="sign-line"></div>
          <div class="sign-name{$en($adviser)}">{$d($adviser, '[OJT ADVISER]')}</div>
          <div class="sign-title">OJT Adviser</div>
        </div>
        <div class="sign-cell">
          <div class="sign-lead">Recommending Approval:</div>
          <div class="sign-line"></div>
          <div class="sign-name{$en($dean)}">{$d($dean, '[DEAN OR DIRECTOR]')}</div>
          <div class="sign-title">{$dean_title}</div>
        </div>
        <div class="sign-cell">
          <div class="sign-lead">Noted:</div>
          <div class="sign-line"></div>
          <div class="sign-name">{$director}</div>
          <div class="sign-title">Director, On-the-Job Training and<br>Career Development Center</div>
        </div>
        <div class="sign-cell">
          <div class="sign-lead">Received by:</div>
          <div class="sign-line"></div>
          <div class="sign-name" style="text-transform:none;font-weight:600;">Company Representative</div>
          <div class="sign-title"><em>(Signature over printed name)</em></div>
        </div>
      </div>
    </div>
  </div>

</div><!-- end #raw-source-data -->

<!-- ══ LIVE RENDERING TARGET ══ -->
<div class="doc-outer" id="rendering-preview-root"></div>

<script>
/**
 * Unified Layout Partition Core Engine
 * Same architecture as MOA_form_builder.php.
 */
function partitionDocumentContent() {
    var PAGE_W = 794;
    var PAGE_H = 1123;

    function measureBlock(src) {
        var m = src.cloneNode(true);
        m.style.cssText = 'display:block;position:fixed;top:0;left:-9999px;width:'+PAGE_W+'px;visibility:hidden;';
        document.body.appendChild(m);
        var h = m.scrollHeight;
        document.body.removeChild(m);
        return h;
    }

    var HDR_H = measureBlock(document.getElementById('header-clone')) || 105;
    var FTR_H = measureBlock(document.getElementById('footer-clone')) || 25;

    var BODY_PADDING_V = 28;
    var USABLE_H = PAGE_H - HDR_H - FTR_H - BODY_PADDING_V;

    function measureElement(el) {
        var sandbox = document.createElement('div');
        sandbox.style.cssText = [
            'position:fixed','top:0','left:-9999px',
            'width:' + (PAGE_W - 58) + 'px',   /* 22pt ≈ 29px each side */
            'visibility:hidden','background:#fff',
            'font-family:\'Crimson Pro\',serif',
            'font-size:9.5pt','line-height:1.65',
            'color:#1a2035','text-align:justify'
        ].join(';');
        sandbox.appendChild(el.cloneNode(true));
        document.body.appendChild(sandbox);
        var h = sandbox.scrollHeight;
        document.body.removeChild(sandbox);
        return h;
    }

    var allSegments = document.querySelectorAll('#raw-source-data .end-seg');
    var pagesContent = [[]];
    var currentPageIdx = 0;
    var currentHeight = 0;

    for (var i = 0; i < allSegments.length; i++) {
        var seg  = allSegments[i];
        var segH = measureElement(seg);
        var forceBreak = seg.getAttribute('data-force-break') === 'true';

        if ((forceBreak && currentHeight > 0) || (currentHeight + segH > USABLE_H && currentHeight > 0)) {
            currentPageIdx++;
            pagesContent[currentPageIdx] = [];
            currentHeight = 0;
        }
        pagesContent[currentPageIdx].push(seg.cloneNode(true));
        currentHeight += segH;
    }
    return pagesContent;
}

function renderLiveSynchronizedPreview() {
    var root = document.getElementById('rendering-preview-root');
    root.innerHTML = '';

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
        bDiv.className = 'doc-body';
        for (var i = 0; i < pagesContent[p].length; i++) {
            bDiv.appendChild(pagesContent[p][i].cloneNode(true));
        }
        paperPage.appendChild(bDiv);

        var fDiv = document.createElement('div');
        fDiv.innerHTML = ftrSource.innerHTML;
        var cur = fDiv.querySelector('.pg-cur');
        var tot = fDiv.querySelector('.pg-total');
        if (cur) cur.textContent = String(p + 1);
        if (tot) tot.textContent = String(pagesContent.length);
        paperPage.appendChild(fDiv);

        root.appendChild(paperPage);
    }
}

window.addEventListener('DOMContentLoaded', function() {
    document.fonts.ready.then(renderLiveSynchronizedPreview);
});
</script>
</body></html>
HTML;
    }

} // end if (!function_exists('buildEndorsementFormHTML'))