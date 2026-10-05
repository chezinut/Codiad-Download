<?php

    /*
    *  Codiad-Download - streams any file or folder the user can see in the file tree.
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
    *
    *  A directory is packed into a temporary zip named after the folder
    *  ("<dirname>.zip"), streamed, and deleted again.
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
    // Zip a Directory
    //
    // Entries live under a single top level folder named after the
    // directory, so unpacking produces "<dirname>/..." instead of scattering
    // the contents into the current folder.
    //////////////////////////////////////////////////////////////////

    // Writable scratch space for the generated archive.
    function cdx_dl_tmpdir() {
        $dirs = array(DATA . '/cache', DATA);
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            if (is_dir($dir) && is_writable($dir)) { return $dir; }
        }
        $tmp = @sys_get_temp_dir();
        if ($tmp !== '' && is_writable($tmp)) { return $tmp; }
        return '';
    }

    function cdx_dl_zip_error($message) {
        cdx_dl_fail('Could not create the archive: ' . $message, 500);
    }

    // ZipArchive-only walk. $base is the directory being zipped, already
    // realpath()ed with '/' separators; entries are named below $prefix.
    function cdx_dl_zip_add($zip, $base, $prefix, &$added) {
        $handle = @opendir($base);
        if ($handle === false) { return false; }
        $ok = true;
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') { continue; }
            $source = $base . '/' . $entry;
            // is_link() first: a symlinked directory would otherwise be
            // descended into, and a link cycle would never terminate
            if (is_link($source)) { continue; }
            if (is_dir($source)) {
                if (!$zip->addEmptyDir($prefix . $entry)) { $ok = false; break; }
                if (!cdx_dl_zip_add($zip, $source, $prefix . $entry . '/', $added)) { $ok = false; break; }
            } elseif (is_file($source)) {
                if (!$zip->addFile($source, $prefix . $entry)) { $ok = false; break; }
                $added++;
            }
        }
        closedir($handle);
        return $ok;
    }

    // Returns the path of the generated archive, or null after reporting.
    //
    // ZipArchive when the extension is present, the bundled pure-PHP writer
    // otherwise - this PHP build ships an empty ext/ folder, so the fallback
    // is the only path that actually runs here.
    function cdx_dl_zip($dir) {
        $tmpdir = cdx_dl_tmpdir();
        if ($tmpdir === '') {
            cdx_dl_zip_error('no writable temp directory');
            return null;
        }
        $name = basename(rtrim($dir, '/'));
        if ($name === '' || $name === '.' || $name === '..') { $name = 'archive'; }
        // Unique so two downloads never collide, and so a stale archive from a
        // crashed request cannot be served later.
        $target = rtrim($tmpdir, '/') . '/cdx-download-' . getmypid() . '-' .
            str_replace('%2F', '', rawurlencode($name)) . '-' . uniqid('', false) . '.zip';
        // Make sure nothing is left behind even if we die mid-zip
        register_shutdown_function('cdx_dl_cleanup', $target);

        if (!class_exists('ZipArchive')) {
            require_once __DIR__ . '/zip.php';
            $zip = new cdx_zip_writer($target, $name);
            if ($zip->error() !== 'unknown error') {
                cdx_dl_zip_error($zip->error());
                return null;
            }
            if (!$zip->addTree($dir)) {
                cdx_dl_zip_error($zip->error());
                return null;
            }
            if (!$zip->close()) {
                cdx_dl_zip_error($zip->error());
                return null;
            }
        } else {
            $zip = new ZipArchive();
            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                cdx_dl_zip_error('unable to open the output file');
                return null;
            }
            if (!$zip->addEmptyDir($name)) {
                $zip->close();
                cdx_dl_zip_error('unable to add the root folder');
                return null;
            }
            $added = 0;
            if (!cdx_dl_zip_add($zip, $dir, $name . '/', $added)) {
                $zip->close();
                cdx_dl_zip_error('the folder contains something unreadable');
                return null;
            }
            if (!$zip->close()) {
                cdx_dl_zip_error('unable to finish the archive');
                return null;
            }
        }
        if (!is_file($target)) {
            cdx_dl_zip_error('the archive was not written');
            return null;
        }
        return $target;
    }

    function cdx_dl_cleanup($target) {
        if ($target && is_file($target)) { @unlink($target); }
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
    if ($file === false) { cdx_dl_fail('Not found', 404); }
    $file = str_replace('\\', '/', $file);
    $isDir = is_dir($file);
    if (!$isDir && !is_file($file)) { cdx_dl_fail('Not found', 404); }

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

    // Discard whatever the includes above buffered, and turn off output
    // compression: it corrupts binary bodies when combined with the
    // Content-Length we send below.
    while (ob_get_level() > 0) { ob_end_clean(); }
    @ini_set('zlib.output_compression', 'Off');
    @ini_set('output_buffering', 'Off');

    // A directory is packed first: the archive only exists once every entry
    // has been added, and that can take a while on a large tree. Grab the
    // folder name before $file is reassigned to the temporary archive.
    $cleanup = null;
    $isZip = false;
    if ($isDir) {
        $folder  = basename(rtrim($file, '/'));
        $archive = cdx_dl_zip($file);
        if ($archive === null) { return; }   // cdx_dl_zip() already reported
        $cleanup = $archive;
        $file    = $archive;
        $isZip   = true;
    }

    // Open before sending headers, so a failure can still answer cleanly
    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        if ($cleanup !== null) { cdx_dl_cleanup($cleanup); }
        cdx_dl_fail('File cannot be read', 403);
    }

    $stat = @fstat($handle);
    $size = ($stat && isset($stat['size'])) ? $stat['size'] : @filesize($file);

    $downloadName = ($isZip ? $folder . '.zip' : basename($file));

    header('Content-Description: File Transfer');
    header('Content-Type: ' . ($isZip ? 'application/zip' : 'application/octet-stream'));
    header('Content-Disposition: ' . cdx_dl_disposition($downloadName));
    header('Content-Transfer-Encoding: binary');
    if ($size !== false) { header('Content-Length: ' . (int) $size); }
    header('Expires: 0');
    header('Cache-Control: private, must-revalidate, max-age=0');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    fpassthru($handle);
    fclose($handle);

    if ($cleanup !== null) { cdx_dl_cleanup($cleanup); }
