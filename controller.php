<?php

    /*
    *  Codiad-Download - streams any file the user can see in the file tree.
    *
    *  Required because core's components/filemanager/download.php only handles
    *  paths that are relative to the Codiad workspace: it rejects ':' and '\'
    *  (every absolute Windows path) and prefixes what is left with WORKSPACE.
    *  Core hides its own Download entry for absolute ("external") project
    *  roots, which is why this endpoint exists.
    *
    *  GET action=download (optional)
    *      path=<path as shown in the file tree>
    *
    *  $path is absolute for external projects and workspace relative for
    *  projects that live inside the Codiad workspace - both are supported.
    */

    // A notice, warning or stray debug line printed here would be appended to
    // the file being downloaded, so keep the output channel clean.
    error_reporting(0);
    @ini_set('display_errors', '0');
    @set_time_limit(0);

    require_once('../../common.php');

    //////////////////////////////////////////////////////////////////
    // Verify Session or Key
    //////////////////////////////////////////////////////////////////

    checkSession();

    //////////////////////////////////////////////////////////////////
    // Helpers
    //////////////////////////////////////////////////////////////////

    // Absolute path? unix '/', windows 'C:/', UNC '//server/share'
    function cdx_dl_is_abs($path) {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || $path === null) { return false; }
        if ($path[0] === '/') { return true; }
        return (bool) preg_match('#^[A-Za-z]:/#', $path);
    }

    // Path of the active project. A plain string in this build, an array in
    // some forks - accept both and always return it absolute with '/' slashes.
    function cdx_dl_root() {
        $root = isset($_SESSION['project']) ? $_SESSION['project'] : '';
        if (is_array($root)) {
            $root = isset($root['path']) ? $root['path'] : '';
        }
        $root = str_replace('\\', '/', (string) $root);
        if ($root === '') { return ''; }
        if (!cdx_dl_is_abs($root)) {
            // Relative projects live inside the Codiad workspace
            $root = str_replace('\\', '/', WORKSPACE) . '/' . trim($root, '/');
        }
        return rtrim($root, '/');
    }

    // Is $path inside $root? Case insensitive on Windows only - on unix
    // 'Proj' and 'proj' are different directories.
    function cdx_dl_inside($path, $root) {
        if (stripos(PHP_OS, 'win') === false) {
            return strpos($path, $root . '/') === 0;
        }
        return stripos($path, $root . '/') === 0;
    }

    // Same guard the filemanager uses (any configured project, or the user's
    // own {user}_acl.php list), plus a Windows-safe retry: realpath() may
    // return a differently cased drive/directory name than projects.php holds.
    function cdx_dl_authorised($file, $path) {
        if (checkPath($file) || checkPath($path)) { return true; }
        if (stripos(PHP_OS, 'win') === false) { return false; }

        $projects = array();
        if (file_exists(DATA . '/' . $_SESSION['user'] . '_acl.php')) {
            foreach (getJSON($_SESSION['user'] . '_acl.php') as $data) {
                $projects[] = $data;
            }
        } else {
            foreach (getJSON('projects.php') as $data) {
                if (isset($data['path'])) { $projects[] = $data['path']; }
            }
        }
        foreach ($projects as $data) {
            $data = str_replace('\\', '/', (string) $data);
            if ($data === '') { continue; }
            if (stripos($file, $data) === 0 || stripos($path, $data) === 0) {
                return true;
            }
        }
        return false;
    }

    // The request comes from the hidden #download iframe, so a plain error
    // body would never be seen - report through the parent window like core.
    function cdx_dl_fail($message, $code) {
        if (!headers_sent()) {
            if (function_exists('http_response_code')) {
                http_response_code($code);
            }
            header('Content-Type: text/html; charset=utf-8');
        }
        $json = json_encode($message);
        if ($json === false) { $json = '""'; }
        echo '<!doctype html><meta charset="utf-8"><script>'
            . 'try{parent.codiad.message.error(' . $json . ');}catch(e){}'
            . '</script>';
        exit;
    }

    function cdx_dl_disposition($filename) {
        // ASCII fallback plus the RFC 5987 form browsers actually honour, so
        // spaces and non-ASCII names come through intact.
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        $ascii = str_replace(array('"', '\\'), array("'", '_'), $ascii);
        $value = 'attachment; filename="' . $ascii . '"';
        if ($ascii !== $filename) {
            $value .= "; filename*=UTF-8''" . rawurlencode($filename);
        }
        return $value;
    }

    //////////////////////////////////////////////////////////////////
    // Read the Request
    //////////////////////////////////////////////////////////////////

    if (isset($_GET['action']) && $_GET['action'] !== 'download') {
        cdx_dl_fail('Unknown action', 400);
    }

    $requested = isset($_GET['path']) ? trim($_GET['path']) : '';
    if ($requested === '') { cdx_dl_fail('No file selected', 400); }

    // The tree can hand back backslashes: PHP accepts both separators, the
    // checks below compare forward slashes.
    $path = str_replace('\\', '/', $requested);

    // Never climb out of the tree
    if (preg_match('#(^|/)\.\.(/|$)#', $path)) { cdx_dl_fail('Invalid path', 403); }

    if (!cdx_dl_is_abs($path)) {
        $root = cdx_dl_root();
        if ($root === '') { cdx_dl_fail('No project loaded', 409); }
        $path = $root . '/' . ltrim($path, '/');
    }

    // Resolve symlinks, '.' and '..': what we check is what we send
    $file = @realpath($path);
    if ($file === false) { cdx_dl_fail('File not found', 404); }
    $file = str_replace('\\', '/', $file);
    if (is_dir($file)) { cdx_dl_fail('Directories cannot be downloaded', 400); }
    if (!is_file($file)) { cdx_dl_fail('File not found', 404); }

    //////////////////////////////////////////////////////////////////
    // Authorise
    //////////////////////////////////////////////////////////////////

    if (!cdx_dl_authorised($file, $path)) {
        cdx_dl_fail('Not allowed', 403);
    }

    // ... and, with a project loaded, inside that project
    $root = cdx_dl_root();
    if ($root !== '') {
        $rootReal = @realpath($root);
        if ($rootReal !== false) {
            $rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');
            if (!cdx_dl_inside($file, $rootReal)) {
                cdx_dl_fail('Not allowed', 403);
            }
        }
    }

    //////////////////////////////////////////////////////////////////
    // Send the File
    //////////////////////////////////////////////////////////////////

    // Open before sending headers, so a failure can still answer cleanly
    $handle = @fopen($file, 'rb');
    if ($handle === false) { cdx_dl_fail('File cannot be read', 403); }

    $stat = @fstat($handle);
    $size = ($stat && isset($stat['size'])) ? $stat['size'] : @filesize($file);

    // Discard whatever the includes above buffered
    while (ob_get_level() > 0) { ob_end_clean(); }
    @ini_set('zlib.output_compression', 'Off');

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: ' . cdx_dl_disposition(basename($file)));
    header('Content-Transfer-Encoding: binary');
    if ($size !== false) { header('Content-Length: ' . (int) $size); }
    header('Expires: 0');
    header('Cache-Control: private, must-revalidate, max-age=0');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    fpassthru($handle);
    fclose($handle);
