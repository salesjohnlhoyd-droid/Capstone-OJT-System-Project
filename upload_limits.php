<?php
/* ============================================================
   upload_limits.php — one place that decides how big an uploaded file may be.

   Used by AccomForm.php (page + JavaScript validation) and
   submit_requirements.php (server-side check) so both always agree.

   The limit is the SMALLEST of:
     1. UPLOAD_MAX_FILE_MB below (the system's own ceiling — 15 MB keeps every
        file inside a MEDIUMBLOB column, 16 MB, even if a column was created
        that way),
     2. PHP's upload_max_filesize,
     3. PHP's post_max_size,
     4. MySQL's max_allowed_packet (a file bigger than this cannot be saved).
   So the page never promises more than the server can really accept; raise
   the server settings (see .htaccess / .user.ini) and the limit grows
   automatically up to the ceiling.
   ============================================================ */
if (!defined('UPLOAD_MAX_FILE_MB')) define('UPLOAD_MAX_FILE_MB', 15);

if (!function_exists('ulIniBytes')) {
    /** "8M" / "2G" / "512K" / "1048576" (php.ini shorthand) -> bytes; 0 or less = no limit (PHP_INT_MAX) */
    function ulIniBytes($value): int
    {
        $v = trim((string)$value);
        if ($v === '' || !preg_match('/^(-?\d+(?:\.\d+)?)\s*([kmg]?)b?$/i', $v, $m)) return PHP_INT_MAX;
        $n = (float)$m[1];
        switch (strtolower($m[2])) {
            case 'g': $n *= 1024;
            case 'm': $n *= 1024;
            case 'k': $n *= 1024;
        }
        return $n > 0 ? (int)min($n, PHP_INT_MAX) : PHP_INT_MAX;
    }
}

if (!function_exists('ulMaxRequestBytes')) {
    /** the most one whole form submission (all selected files together) may weigh */
    function ulMaxRequestBytes(): int
    {
        return ulIniBytes(ini_get('post_max_size'));
    }
}

if (!function_exists('ulMaxFileBytes')) {
    /** the biggest single file the server will accept right now (never below 1 MB, never above the ceiling) */
    function ulMaxFileBytes($conn = null): int
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $limit = UPLOAD_MAX_FILE_MB * 1024 * 1024;
        $limit = min($limit, ulIniBytes(ini_get('upload_max_filesize')));
        $limit = min($limit, ulMaxRequestBytes());
        if ($conn instanceof mysqli) {
            try {
                if ($res = $conn->query("SELECT @@max_allowed_packet AS map")) {
                    $row = $res->fetch_assoc();
                    $res->free();
                    if ($row && (int)$row['map'] > 0) {
                        $limit = min($limit, (int)floor((int)$row['map'] * 0.9)); // headroom for the query itself
                    }
                }
            } catch (Throwable $e) { /* keep the PHP-side limit */ }
        }
        return $cache = max(1024 * 1024, (int)$limit);
    }
}

if (!function_exists('ulFormatMB')) {
    /** 15728640 -> "15 MB", 1572864 -> "1.5 MB" */
    function ulFormatMB(int $bytes): string
    {
        $mb = $bytes / (1024 * 1024);
        return (abs($mb - round($mb)) < 0.05 ? (string)(int)round($mb) : number_format($mb, 1, '.', '')) . ' MB';
    }
}

if (!function_exists('ulUploadErrorMessage')) {
    /** friendly text for PHP's own upload errors (file larger than upload_max_filesize etc.); null when fine */
    function ulUploadErrorMessage(int $code, $conn = null): ?string
    {
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            return 'File too large. Maximum ' . ulFormatMB(ulMaxFileBytes($conn)) . ' per file.';
        }
        if ($code === UPLOAD_ERR_PARTIAL) return 'The file was only partly uploaded. Please try again.';
        if ($code === UPLOAD_ERR_NO_TMP_DIR || $code === UPLOAD_ERR_CANT_WRITE) {
            return 'The server could not store the uploaded file. Please try again later.';
        }
        return null;
    }
}
