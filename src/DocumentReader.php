<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

/**
 * Reads attached files for the AI context, without any dependency beyond
 * what GLPI already requires:
 *  - images: sent to vision models; oversized ones are downscaled with GD
 *    instead of being skipped, BMP is converted;
 *  - PDF: text through poppler-utils (pdftotext) or, without it, the
 *    built-in reader (PdfText); scanned pages rendered as images (pdftoppm),
 *    or the file itself for providers that read PDF;
 *  - Office/OpenDocument (docx, xlsx, pptx, odt, ods, odp): text extracted
 *    from the XML parts (needs the PHP zip extension);
 *  - plain text files (logs, CSV, JSON, XML, configuration…).
 */
final class DocumentReader
{
    public const KIND_IMAGE  = 'image';
    public const KIND_PDF    = 'pdf';
    public const KIND_OFFICE = 'office';
    public const KIND_TEXT   = 'text';
    public const KIND_OTHER  = 'other';

    /** Image formats every vision provider accepts */
    public const PROVIDER_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Never load more than this from disk for one attachment */
    private const MAX_FILE_BYTES = 25 * 1024 * 1024;

    /** Zip entries larger than this are skipped (zip bomb guard) */
    private const MAX_ZIP_ENTRY_BYTES = 30 * 1024 * 1024;

    private const TEXT_EXTENSIONS = [
        'txt', 'log', 'csv', 'tsv', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf', 'cfg', 'properties',
        'md', 'sql', 'sh', 'ps1', 'bat', 'cmd', 'html', 'htm', 'eml', 'out', 'err', 'trace',
    ];

    private const OFFICE_EXTENSIONS = ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'];

    public static function kindOf(string $mime, string $filename): string
    {
        $mime = strtolower(trim($mime));
        $ext  = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        if (strpos($mime, 'image/') === 0 || in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], true)) {
            return self::KIND_IMAGE;
        }
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return self::KIND_PDF;
        }
        if (in_array($ext, self::OFFICE_EXTENSIONS, true)) {
            return self::KIND_OFFICE;
        }
        if (
            strpos($mime, 'text/') === 0
            || in_array($mime, ['application/json', 'application/xml', 'application/x-yaml', 'application/yaml', 'message/rfc822'], true)
            || in_array($ext, self::TEXT_EXTENSIONS, true)
        ) {
            return self::KIND_TEXT;
        }
        return self::KIND_OTHER;
    }

    // ---------------------------------------------------------------------
    // Images
    // ---------------------------------------------------------------------

    /**
     * Image ready to be sent: [mime, base64 data], or null when unusable.
     *
     * @param int $max_bytes size limit of the data actually sent
     * @param int $max_px    longest side after downscaling
     *
     * @return array{0: string, 1: string}|null
     */
    public static function prepareImage(string $path, int $max_bytes, int $max_px = 2048): ?array
    {
        $size = self::fileSize($path);
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
            return null;
        }
        $info = @getimagesize($path);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return null;
        }
        [$width, $height] = $info;
        $mime = strtolower((string) ($info['mime'] ?? ''));

        $supported    = in_array($mime, self::PROVIDER_IMAGE_MIMES, true);
        $needs_resize = max($width, $height) > $max_px || $size > $max_bytes;

        if ($supported && !$needs_resize) {
            $data = @file_get_contents($path);
            return $data !== false && $data !== '' ? [$mime, base64_encode($data)] : null;
        }

        if (!function_exists('imagecreatefromstring') || !self::canDecode($width, $height)) {
            return null;
        }
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            return null;
        }
        $source = @imagecreatefromstring($data);
        unset($data);
        if ($source === false) {
            return null;
        }

        $keep_alpha = in_array($mime, ['image/png', 'image/gif', 'image/webp'], true);
        foreach ([[$max_px, 85], [(int) min($max_px, 1600), 75], [1024, 70]] as [$limit_px, $quality]) {
            $encoded = self::resample($source, $width, $height, $limit_px, $keep_alpha, $quality);
            if ($encoded !== null && strlen($encoded[1]) <= $max_bytes) {
                imagedestroy($source);
                return [$encoded[0], base64_encode($encoded[1])];
            }
            $keep_alpha = false; // second and third tries: JPEG, smaller
        }
        imagedestroy($source);
        return null;
    }

    /**
     * @param resource|\GdImage $source
     *
     * @return array{0: string, 1: string}|null [mime, binary]
     */
    private static function resample($source, int $width, int $height, int $limit_px, bool $png, int $quality): ?array
    {
        $scale  = min(1.0, $limit_px / max($width, $height));
        $new_w  = max(1, (int) round($width * $scale));
        $new_h  = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($new_w, $new_h);
        if ($target === false) {
            return null;
        }
        if ($png) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
        } else {
            // JPEG has no transparency: paint transparent areas white
            imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $new_w, $new_h, $width, $height);

        ob_start();
        $ok = $png ? imagepng($target, null, 6) : imagejpeg($target, null, $quality);
        $binary = (string) ob_get_clean();
        imagedestroy($target);

        return $ok && $binary !== '' ? [$png ? 'image/png' : 'image/jpeg', $binary] : null;
    }

    /**
     * Decoding a bitmap takes ~5 bytes per pixel: refuse what would exhaust
     * the PHP memory limit.
     */
    private static function canDecode(int $width, int $height): bool
    {
        $needed = $width * $height * 5 + 8 * 1024 * 1024;
        $limit  = self::memoryLimitBytes();
        if ($limit <= 0) {
            return $width * $height <= 50000000;
        }
        return memory_get_usage(true) + $needed < $limit * 0.9;
    }

    private static function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;
        switch ($unit) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return (int) $value;
        }
    }

    // ---------------------------------------------------------------------
    // PDF
    // ---------------------------------------------------------------------

    public static function shellAvailable(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
        return !in_array('shell_exec', $disabled, true);
    }

    /**
     * Full path of a poppler binary, or '' when it is not available.
     */
    public static function popplerBinary(string $name, string $directory = ''): string
    {
        static $cache = [];
        $key = $name . '|' . $directory;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        if (!self::shellAvailable()) {
            return $cache[$key] = '';
        }

        $windows = DIRECTORY_SEPARATOR === '\\';
        $binary  = $name . ($windows ? '.exe' : '');
        if ($directory !== '') {
            $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $binary;
            return $cache[$key] = is_file($path) ? $path : '';
        }

        $lookup = $windows ? 'where ' . escapeshellarg($binary) : 'command -v ' . escapeshellarg($binary);
        $found  = trim((string) @shell_exec($lookup));
        $found  = $found !== '' ? trim((string) strtok($found, "\r\n")) : '';
        return $cache[$key] = ($found !== '' && is_file($found)) ? $found : '';
    }

    /**
     * Text of a digital PDF (first pages), '' when nothing usable.
     */
    public static function readPdfText(string $path, int $max_chars, int $max_pages = 10, string $poppler_dir = ''): string
    {
        if (self::fileSize($path) <= 0) {
            return '';
        }
        $binary = self::popplerBinary('pdftotext', $poppler_dir);
        if ($binary === '') {
            // No poppler-utils (or shell_exec disabled): built-in reader
            return self::compact(PdfText::extract($path, $max_chars, $max_pages), $max_chars);
        }
        $command = escapeshellarg($binary) . ' -q -nopgbrk -enc UTF-8 -l ' . max(1, $max_pages)
            . ' ' . escapeshellarg($path) . ' -';
        $text = Text::scrubUtf8((string) @shell_exec($command));
        return self::compact($text, $max_chars);
    }

    /**
     * First pages of a (scanned) PDF rendered as PNG images.
     *
     * @return string[] base64 PNG data, one per page
     */
    public static function pdfPagesAsPng(string $path, int $pages, string $tmp_dir, string $poppler_dir = '', int $dpi = 110): array
    {
        $binary = self::popplerBinary('pdftoppm', $poppler_dir);
        if ($binary === '' || $pages <= 0 || !is_dir($tmp_dir) || !is_writable($tmp_dir)) {
            return [];
        }
        $prefix = rtrim($tmp_dir, '/\\') . DIRECTORY_SEPARATOR . 'ticketassistant_pdf_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @shell_exec(
            escapeshellarg($binary) . ' -png -r ' . max(50, min(200, $dpi)) . ' -f 1 -l ' . $pages . ' '
            . escapeshellarg($path) . ' ' . escapeshellarg($prefix)
        );

        $images = [];
        $files  = glob($prefix . '-*.png') ?: [];
        natsort($files);
        foreach ($files as $png) {
            if (count($images) < $pages) {
                $data = @file_get_contents($png);
                if ($data !== false && $data !== '') {
                    $images[] = base64_encode($data);
                }
            }
            @unlink($png);
        }
        return $images;
    }

    // ---------------------------------------------------------------------
    // Office / OpenDocument
    // ---------------------------------------------------------------------

    public static function officeSupported(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    public static function readOffice(string $path, string $filename, int $max_chars): string
    {
        if (!self::officeSupported() || self::fileSize($path) <= 0) {
            return '';
        }
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $text = '';
        try {
            switch ($ext) {
                case 'docx':
                    $text = self::wordText((string) self::zipEntry($zip, 'word/document.xml'));
                    break;
                case 'pptx':
                    $slides = [];
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = (string) $zip->getNameIndex($i);
                        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                            $slides[(int) $m[1]] = $name;
                        }
                    }
                    ksort($slides);
                    foreach ($slides as $number => $name) {
                        $xml   = (string) self::zipEntry($zip, $name);
                        $xml   = str_replace(['</a:p>', '<a:br/>'], ["\n", "\n"], $xml);
                        $text .= '[' . sprintf(__('Slide %d', 'ticketassistant'), $number) . "]\n" . self::xmlToText($xml) . "\n";
                        if (mb_strlen($text) > $max_chars) {
                            break;
                        }
                    }
                    break;
                case 'xlsx':
                    $text = self::spreadsheetText($zip, $max_chars);
                    break;
                case 'odt':
                case 'ods':
                case 'odp':
                    $xml = (string) self::zipEntry($zip, 'content.xml');
                    $xml = str_replace(
                        ['</text:p>', '</text:h>', '<text:tab/>', '<text:line-break/>', '<text:s/>', '</table:table-cell>', '</table:table-row>'],
                        ["\n", "\n", "\t", "\n", ' ', "\t", "\n"],
                        $xml
                    );
                    $text = self::xmlToText($xml);
                    break;
            }
        } finally {
            $zip->close();
        }

        return self::compact($text, $max_chars);
    }

    private static function zipEntry(\ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false || (int) $stat['size'] > self::MAX_ZIP_ENTRY_BYTES) {
            return null;
        }
        $data = $zip->getFromName($name);
        return $data === false ? null : $data;
    }

    private static function wordText(string $xml): string
    {
        $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>', '<w:cr/>'], ["\n", "\t", "\n", "\n"], $xml);
        return self::xmlToText($xml);
    }

    private static function xmlToText(string $xml): string
    {
        $text = strip_tags($xml);
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function spreadsheetText(\ZipArchive $zip, int $max_chars): string
    {
        $shared = [];
        $xml    = self::zipEntry($zip, 'xl/sharedStrings.xml');
        if ($xml !== null) {
            $doc = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            if ($doc !== false) {
                foreach ($doc->si as $si) {
                    $value = '';
                    if (isset($si->t)) {
                        $value = (string) $si->t;
                    }
                    foreach ($si->r as $run) {
                        $value .= (string) $run->t;
                    }
                    $shared[] = $value;
                }
            }
        }

        $sheets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m)) {
                $sheets[(int) $m[1]] = $name;
            }
        }
        ksort($sheets);

        $out = '';
        foreach (array_slice($sheets, 0, 3, true) as $number => $name) {
            $xml = self::zipEntry($zip, $name);
            if ($xml === null) {
                continue;
            }
            $doc = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            if ($doc === false || !isset($doc->sheetData)) {
                continue;
            }
            $out .= '[' . sprintf(__('Sheet %d', 'ticketassistant'), $number) . "]\n";
            $rows = 0;
            foreach ($doc->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $cell) {
                    $type = (string) $cell['t'];
                    if ($type === 's') {
                        $cells[] = $shared[(int) $cell->v] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $cells[] = (string) $cell->is->t;
                    } else {
                        $cells[] = (string) $cell->v;
                    }
                }
                $line = rtrim(implode("\t", $cells));
                if ($line !== '') {
                    $out .= $line . "\n";
                }
                if (++$rows >= 300 || mb_strlen($out) > $max_chars) {
                    break 2;
                }
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Plain text
    // ---------------------------------------------------------------------

    public static function readText(string $path, string $filename, int $max_chars): string
    {
        if (self::fileSize($path) <= 0) {
            return '';
        }
        $raw = @file_get_contents($path, false, null, 0, 512 * 1024);
        if ($raw === false || $raw === '') {
            return '';
        }

        // Encoding: UTF-16 BOMs, UTF-8 (with or without BOM), else Windows-1252
        if (strncmp($raw, "\xFF\xFE", 2) === 0) {
            $raw = (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        } elseif (strncmp($raw, "\xFE\xFF", 2) === 0) {
            $raw = (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        } else {
            if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
                $raw = substr($raw, 3);
            }
            if (!mb_check_encoding($raw, 'UTF-8')) {
                $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
                $raw       = is_string($converted) ? $converted : Text::scrubUtf8($raw);
            }
        }
        $raw = str_replace("\0", '', $raw);

        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['html', 'htm'], true)) {
            $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return self::compact($raw, $max_chars);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    public static function fileSize(string $path): int
    {
        return is_file($path) && is_readable($path) ? (int) @filesize($path) : 0;
    }

    /**
     * Normalizes whitespace and truncates.
     */
    public static function compact(string $text, int $max_chars): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        // Tabs are kept: they separate spreadsheet columns
        $text = preg_replace('/ {2,}/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);
        if ($max_chars > 0 && mb_strlen($text) > $max_chars) {
            $text = mb_substr($text, 0, $max_chars) . '…';
        }
        return $text;
    }
}
