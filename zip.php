<?php

/*
 *  Codiad-Download - minimal ZIP writer.
 *
 *  Exists because ext/zip is not always present: the PHP build serving this
 *  install (5.5.12, D:/wwwwmp/bin/php/php5.5.12) ships an empty ext/ folder,
 *  so ZipArchive is unavailable and directory downloads fail outright.
 *
 *  zlib is compiled into PHP itself, so gzdeflate() is always there - which is
 *  all a ZIP file actually needs. CRC-32 comes from the native crc32(), which
 *  is correct on 32-bit builds where a hand-rolled table would not be.
 *
 *  Deliberately PHP 5.x compatible: no short list(), ??, scalar types, etc.
 */

class cdx_zip_writer {

    // In-memory deflate cutoff. Larger files are STOREd so a single big file
    // cannot exhaust memory_limit.
    const DEFLATE_MAX = 15728640;   // 15 MB

    // Extensions that are already compressed: deflating them burns CPU and
    // usually grows them.
    private static $store_ext = array(
        'zip', 'gz', 'bz2', 'xz', '7z', 'rar', 'jpg', 'jpeg', 'png', 'gif',
        'webp', 'ico', 'mp3', 'mp4', 'avi', 'mkv', 'mov', 'wmv', 'ogg',
        'pdf', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'jar', 'apk', 'so',
        'dll', 'exe', 'zst'
    );

    private $handle = null;
    private $prefix;
    private $entries = array();
    private $offset = 0;
    private $error = '';
    private $count = 0;

    const MAX_ENTRIES = 65534;
    const MAX_OFFSET  = 4294967295;   // 4 GB, the non-ZIP64 limit

    public function __construct($target, $prefix) {
        $this->prefix = $prefix;
        $handle = @fopen($target, 'wb');
        if ($handle === false) {
            $this->error = 'unable to open the output file';
            return;
        }
        $this->handle = $handle;
        $this->addDir($prefix);
    }

    //////////////////////////////////////////////////////////////////////
    // Errors
    //////////////////////////////////////////////////////////////////////

    public function error() {
        return $this->error === '' ? 'unknown error' : $this->error;
    }

    private function fail($message) {
        if ($this->error === '') { $this->error = $message; }
        return false;
    }

    //////////////////////////////////////////////////////////////////////
    // Walking
    //////////////////////////////////////////////////////////////////////

    /**
     * Recursively add $base. Entries are named "$prefix/<relpath>".
     *
     * $rel carries the path walked so far - without it every nested file would
     * be written straight into the root and the tree would come out flat.
     */
    public function addTree($base, $rel = '') {
        $handle = @opendir($base);
        if ($handle === false) {
            return $this->fail('cannot read ' . basename($base));
        }
        $ok = true;
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') { continue; }
            $source = $base . '/' . $entry;
            // is_link() first: a symlinked directory would otherwise be
            // descended into and a link cycle would never terminate.
            if (is_link($source)) { continue; }
            $relName = $rel . $entry;
            $name = $this->prefix . '/' . $relName;
            if (is_dir($source)) {
                if (!$this->addDir($name)) { $ok = false; break; }
                if (!$this->addTree($source, $relName . '/')) { $ok = false; break; }
            } elseif (is_file($source)) {
                if (!$this->addFile($source, $name)) { $ok = false; break; }
            }
        }
        closedir($handle);
        return $ok;
    }

    //////////////////////////////////////////////////////////////////////
    // Entries
    //////////////////////////////////////////////////////////////////////

    public function addDir($name) {
        return $this->write($name . '/', 0, '', 0, 0, 0, true);
    }

    public function addFile($source, $name) {
        $size = @filesize($source);
        if ($size === false) {
            return $this->fail('cannot size ' . $name);
        }
        $mtime = @filemtime($source);
        if ($mtime === false) { $mtime = 0; }
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        $data = null;
        $raw = null;
        $method = 0;
        if ($size <= self::DEFLATE_MAX && !in_array($ext, self::$store_ext, true)) {
            $raw = @file_get_contents($source);
            if ($raw === false) {
                return $this->fail('cannot read ' . $name);
            }
            $data = @gzdeflate($raw, 6);
            if ($data === false) {
                $data = null;
                $raw = null;
            } else {
                $method = 8;
            }
        }
        // CRC-32 is always of the uncompressed bytes, so the deflated buffer
        // must never reach crc32Of().
        $crc = $this->crc32Of($source, $raw);

        if ($data === null) {
            return $this->writeStreamed($source, $name, $crc, $size, $mtime);
        }
        return $this->write($name, $method, $data, strlen($data), $size, $crc, false, $mtime);
    }

    /**
     * STORE method straight from disk: no size, no memory use.
     */
    private function writeStreamed($source, $name, $crc, $size, $mtime) {
        if ($this->error !== '') { return false; }
        $head = $this->localHeader($name, 0, $crc, $size, $size, $mtime);
        if (!$this->writeRaw($head)) { return false; }
        $in = @fopen($source, 'rb');
        if ($in === false) { return $this->fail('cannot read ' . $name); }
        $buffer = '';
        while (!feof($in)) {
            $buffer = fread($in, 262144);
            if ($buffer === false) { break; }
            if ($buffer === '') { continue; }
            if (!$this->writeRaw($buffer)) {
                fclose($in);
                return false;
            }
        }
        fclose($in);
        $this->register($name, 0, $crc, $size, $size, $mtime, $this->offset - strlen($head) - $size);
        return true;
    }

    private function write($name, $method, $data, $compressed, $size, $crc, $isDir, $mtime = 0) {
        if ($this->error !== '') { return false; }
        $head = $this->localHeader($name, $method, $crc, $compressed, $size, $mtime, $isDir);
        if (!$this->writeRaw($head)) { return false; }
        if ($compressed > 0 && !$this->writeRaw($data)) { return false; }
        $this->register($name, $method, $crc, $compressed, $size, $mtime, $this->offset - strlen($head) - $compressed, $isDir);
        return true;
    }

    //////////////////////////////////////////////////////////////////////
    // CRC-32
    //////////////////////////////////////////////////////////////////////

    /**
     * CRC-32 of the *uncompressed* bytes.
     *
     * Two exact paths, because this host runs a 32-bit PHP (PHP_INT_SIZE=4)
     * where a hand-rolled table is unusable: the literal 0xEDB88320 overflows
     * to a double, quietly corrupting the table, and "& 0xFFFFFFFF" cannot be
     * expressed either. So: native crc32() when the bytes are already in
     * memory, hash_file() when they are still on disk.
     *
     * A negative result is fine - pack('V') masks to 32 bits.
     *
     * @param $raw Uncompressed contents when they are already in memory,
     *             otherwise null (the CRC is then taken from disk).
     */
    private function crc32Of($source, $raw) {
        if ($raw !== null) {
            return crc32($raw);
        }
        // ext/hash is bundled and enabled by default since PHP 5.1.2, and
        // hash_file() streams internally, so this costs no memory.
        if (function_exists('hash_file')) {
            $hex = @hash_file('crc32b', $source);
            if ($hex !== false && strlen($hex) === 8) {
                // Half words on purpose: hexdec() of a full 32-bit value
                // returns a float on a 32-bit build, which pack() truncates.
                // The CRC's high word is the first two bytes of the digest.
                return (hexdec(substr($hex, 0, 4)) << 16) | hexdec(substr($hex, 4, 4));
            }
        }
        // Only if ext/hash has been stripped out. Reads the whole file, which
        // is why hash_file() is preferred above.
        $whole = @file_get_contents($source);
        return $whole === false ? 0 : crc32($whole);
    }

    //////////////////////////////////////////////////////////////////////
    // ZIP structures
    //////////////////////////////////////////////////////////////////////

    private function localHeader($name, $method, $crc, $compressed, $size, $mtime, $isDir = false) {
        list($dostime, $dosdate) = self::dosTime($mtime);
        $flags = self::hasHighBytes($name) ? 0x0800 : 0x0000;   // UTF-8 names
        return pack('VvvvvvVVVvv',
            0x04034B50,                       // local file header signature
            20,                                // version needed to extract
            $flags,
            $method,
            $dostime,
            $dosdate,
            $crc,
            $compressed,
            $size,
            strlen($name),
            0                                  // extra field length
        ) . $name;
    }

    private function register($name, $method, $crc, $compressed, $size, $mtime, $offset, $isDir = false) {
        $this->entries[] = array(
            $name, $method, $crc, $compressed, $size, $mtime, $offset, $isDir
        );
        $this->count++;
    }

    private function writeRaw($data) {
        $written = @fwrite($this->handle, $data);
        if ($written === false || $written !== strlen($data)) {
            return $this->fail('write failed');
        }
        $this->offset += $written;
        if ($this->offset > self::MAX_OFFSET) {
            return $this->fail('the archive would exceed 4 GB, which needs ZIP64');
        }
        return true;
    }

    /**
     * Write the central directory and close the file.
     */
    public function close() {
        if ($this->error !== '') { return false; }
        if ($this->count > self::MAX_ENTRIES) {
            return $this->fail('too many files for a ZIP without ZIP64');
        }
        $start = $this->offset;
        foreach ($this->entries as $entry) {
            list($name, $method, $crc, $compressed, $size, $mtime, $offset, $isDir) = $entry;
            list($dostime, $dosdate) = self::dosTime($mtime);
            $flags = self::hasHighBytes($name) ? 0x0800 : 0x0000;
            // version made by: 0x03 = UNIX, so the mode below is honoured
            $external = $isDir
                ? ((0040755 << 16) | 0x10)      // drwxr-xr-x + directory flag
                : (0100644 << 16);              // -rw-r--r--
            $record = pack('VvvvvvvVVVvvvvvVV',
                0x02014B50,                     // central file header signature
                0x031E,                         // version made by (UNIX, 3.0)
                20,                             // version needed to extract
                $flags,
                $method,
                $dostime,
                $dosdate,
                $crc,
                $compressed,
                $size,
                strlen($name),
                0,                              // extra field length
                0,                              // file comment length
                0,                              // disk number start
                0,                              // internal file attributes
                $external,
                $offset
            ) . $name;
            if (!$this->writeRaw($record)) { return false; }
        }
        $size = $this->offset - $start;
        $eocd = pack('VvvvvVVv',
            0x06054B50,                       // end of central dir signature
            0,                                // number of this disk
            0,                                // disk with central directory
            $this->count,
            $this->count,
            $size,
            $start,
            0                                 // comment length
        );
        if (!$this->writeRaw($eocd)) { return false; }
        return @fclose($this->handle) !== false;
    }

    public function __destruct() {
        if ($this->handle !== null) {
            @fclose($this->handle);
            $this->handle = null;
        }
    }

    private static function hasHighBytes($string) {
        return preg_match('/[\x80-\xFF]/', $string) === 1;
    }

    private static function dosTime($timestamp) {
        if ($timestamp <= 0) { $timestamp = 315532800; }   // 1980-01-01
        $year = (int) date('Y', $timestamp);
        if ($year < 1980) { return array(0, 0x0021); }
        return array(
            ((int) date('G', $timestamp) << 11) | ((int) date('i', $timestamp) << 5) | ((int) date('s', $timestamp) >> 1),
            (($year - 1980) << 9) | ((int) date('n', $timestamp) << 5) | (int) date('j', $timestamp)
        );
    }
}
?>
