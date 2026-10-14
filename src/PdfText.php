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
 * Text layer of digital PDF files in pure PHP, used when poppler-utils
 * (pdftotext) is not installed on the server.
 *
 * It covers what office suites, browsers and OCR scanners produce:
 * Flate-compressed content streams, object streams (PDF 1.5+), simple fonts
 * (WinAnsi) and composite fonts with a ToUnicode map, text inside form
 * XObjects. Encrypted files, pages without a text layer (scans) and fonts
 * without any usable encoding give an empty string: the caller then falls
 * back to other strategies (page images, the PDF sent to the provider, the
 * file name only).
 *
 * The input is untrusted: size, time and decompression budgets, bounded
 * recursion, no exception escapes extract().
 */
final class PdfText
{
    private const MAX_FILE_BYTES    = 20 * 1024 * 1024;
    private const MAX_STREAM_BYTES  = 8 * 1024 * 1024;
    private const MAX_DECODED_BYTES = 48 * 1024 * 1024;
    private const MAX_SECONDS       = 4.0;
    private const MAX_DEPTH         = 24;
    private const MAX_CMAP_ENTRIES  = 70000;

    /** @var array<int, string> object number => raw body (dictionary and stream) */
    private $objects = [];

    /** @var array<int, mixed> parsed objects (without their stream data) */
    private $parsed = [];

    /** @var array<string, array{map: array<string, string>, bytes: int, simple: bool}> */
    private $fonts = [];

    /** @var int */
    private $decoded = 0;

    /** @var float */
    private $deadline;

    private function __construct(string $data)
    {
        $this->deadline = microtime(true) + self::MAX_SECONDS;
        $this->indexObjects($data);
    }

    /**
     * Text of the first pages of a PDF file, '' when there is no usable text layer.
     */
    public static function extract(string $path, int $max_chars, int $max_pages = 10): string
    {
        $size = is_file($path) && is_readable($path) ? (int) @filesize($path) : 0;
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
            return '';
        }
        $data = @file_get_contents($path);
        return is_string($data) ? self::fromString($data, $max_chars, $max_pages) : '';
    }

    public static function fromString(string $data, int $max_chars, int $max_pages = 10): string
    {
        if (strlen($data) > self::MAX_FILE_BYTES || strpos(substr($data, 0, 1024), '%PDF-') === false) {
            return '';
        }
        // Encrypted documents (even with an empty user password): strings cannot be read
        if (preg_match('#/Encrypt\s*(?:\d+\s+\d+\s+R|<<)#', $data)) {
            return '';
        }
        try {
            $reader = new self($data);
            unset($data);
            $text = $reader->text(max(1, $max_pages), $max_chars);
        } catch (\Throwable $e) {
            return '';
        }
        $text = trim((string) preg_replace('/[ \t]+/', ' ', $text));
        // Typographic ligatures some fonts map to a single character
        $text = strtr($text, ["\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl', "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl', "\u{FB05}" => 'st', "\u{FB06}" => 'st']);
        return self::looksLikeText($text) ? $text : '';
    }

    // ---------------------------------------------------------------------
    // File structure
    // ---------------------------------------------------------------------

    /**
     * Finds the indirect objects ("12 0 obj … endobj") by scanning the file,
     * which also works for files with a broken cross-reference table, then
     * unpacks the object streams.
     */
    private function indexObjects(string $data): void
    {
        if (!preg_match_all('/(?<![0-9])([0-9]{1,10})[ \t\r\n\f\0]+[0-9]{1,5}[ \t\r\n\f\0]+obj(?![A-Za-z])/', $data, $headers, PREG_OFFSET_CAPTURE)) {
            return;
        }
        $cursor = 0;
        $size   = strlen($data);
        $count  = count($headers[0]);
        for ($i = 0; $i < $count; $i++) {
            $offset = (int) $headers[0][$i][1];
            if ($offset < $cursor) {
                continue; // inside the previous object (binary stream data)
            }
            if (microtime(true) > $this->deadline) {
                break;
            }
            $start = $offset + strlen($headers[0][$i][0]);
            $pos   = $start;
            $value = $this->parseValue($data, $pos);
            $pos   = min($pos, $size);
            $end   = false;
            // Stream object: skip its data with /Length when it is given directly
            if (preg_match('/\G[ \t\r\n\f\0]*stream(?:\r\n|\n|\r)/', $data, $m, 0, $pos)) {
                $data_start = $pos + strlen($m[0]);
                $length     = is_array($value) && isset($value['d']['Length']) ? $value['d']['Length'] : null;
                $endstream  = is_int($length) && $length >= 0 && $data_start + $length <= $size
                    && preg_match('/\G[ \t\r\n\f\0]*endstream/', $data, $m, 0, $data_start + $length)
                    ? $data_start + $length
                    : strpos($data, 'endstream', $data_start);
                if ($endstream !== false) {
                    $end = strpos($data, 'endobj', $endstream);
                }
            }
            if ($end === false) {
                $end = strpos($data, 'endobj', $pos);
            }
            if ($end === false) {
                $end = $i + 1 < $count ? (int) $headers[0][$i + 1][1] : strlen($data);
            }
            // Incremental updates append newer versions: the last one wins
            $this->objects[(int) $headers[1][$i][0]] = substr($data, $start, $end - $start);
            unset($this->parsed[(int) $headers[1][$i][0]]);
            $cursor = max($end, $pos);
        }

        foreach ($this->objects as $body) {
            if (strpos($body, '/ObjStm') !== false) {
                $this->expandObjectStream($body);
            }
        }
    }

    private function expandObjectStream(string $body): void
    {
        [$dict, $raw] = $this->split($body);
        if ($raw === null || !preg_match('#/Type\s*/ObjStm\b#', $dict)) {
            return;
        }
        $content = $this->decodeStream($dict, $raw);
        $pos     = 0;
        $info    = $this->parseValue($dict, $pos);
        $first   = (int) $this->resolve($info['d']['First'] ?? 0);
        $number  = (int) $this->resolve($info['d']['N'] ?? 0);
        if ($content === null || $first <= 0 || $number <= 0) {
            return;
        }
        preg_match_all('/(\d+)\s+(\d+)/', substr($content, 0, $first), $pairs, PREG_SET_ORDER);
        $pairs = array_slice($pairs, 0, $number);
        foreach ($pairs as $k => $pair) {
            $from = $first + (int) $pair[2];
            $to   = isset($pairs[$k + 1]) ? $first + (int) $pairs[$k + 1][2] : strlen($content);
            // Objects written directly in the file take precedence
            if ($to > $from && !isset($this->objects[(int) $pair[1]])) {
                $this->objects[(int) $pair[1]] = substr($content, $from, $to - $from);
            }
        }
    }

    /**
     * Splits an object body into its dictionary and its raw stream data.
     *
     * @return array{0: string, 1: string|null}
     */
    private function split(string $body): array
    {
        if (!preg_match('/>>\s*stream(?:\r\n|\n|\r)/', $body, $m, PREG_OFFSET_CAPTURE)) {
            return [$body, null];
        }
        $dict_end = (int) $m[0][1] + 2;
        $start    = (int) $m[0][1] + strlen($m[0][0]);
        $end      = strrpos($body, 'endstream');
        if ($end === false || $end < $start) {
            $end = strlen($body);
        }
        return [substr($body, 0, $dict_end), substr($body, $start, $end - $start)];
    }

    /**
     * Decoded data of a stream object, null when it is not readable text data.
     *
     * @param mixed $ref
     */
    private function streamOf($ref): ?string
    {
        if (!is_array($ref) || !isset($ref['r']) || !isset($this->objects[$ref['r']])) {
            return null;
        }
        [$dict, $raw] = $this->split($this->objects[$ref['r']]);
        return $raw === null ? null : $this->decodeStream($dict, $raw);
    }

    private function decodeStream(string $dict, string $raw): ?string
    {
        $pos  = 0;
        $info = $this->parseValue($dict, $pos);
        $info = is_array($info) && isset($info['d']) ? $info['d'] : [];

        $length = $this->resolve($info['Length'] ?? null);
        if (is_int($length) && $length >= 0 && $length <= strlen($raw)) {
            $raw = substr($raw, 0, $length);
        }

        $filters = $this->resolve($info['Filter'] ?? null);
        $list    = is_string($filters) ? [$filters] : (is_array($filters) && isset($filters['a']) ? $filters['a'] : []);
        foreach ($list as $filter) {
            switch ($filter) {
                case '/FlateDecode':
                case '/Fl':
                    $raw = self::inflate($raw);
                    break;
                case '/ASCIIHexDecode':
                case '/AHx':
                    $raw = self::asciiHex($raw);
                    break;
                case '/ASCII85Decode':
                case '/A85':
                    $raw = self::ascii85($raw);
                    break;
                default:
                    return null; // images (DCT, JBIG2, CCITT…) and rare encodings carry no text
            }
            if ($raw === null) {
                return null;
            }
        }

        $this->decoded += strlen($raw);
        if ($this->decoded > self::MAX_DECODED_BYTES) {
            throw new \RuntimeException('PDF decoding budget exceeded');
        }
        return $raw;
    }

    private static function inflate(string $raw): ?string
    {
        $out = @gzuncompress($raw, self::MAX_STREAM_BYTES);
        if ($out === false) {
            $out = @gzinflate(substr($raw, 2), self::MAX_STREAM_BYTES);
        }
        if ($out === false && function_exists('inflate_init')) {
            // Truncated or damaged stream: keep what can be decoded
            $context = @inflate_init(ZLIB_ENCODING_DEFLATE);
            $out     = '';
            if ($context !== false) {
                foreach (str_split($raw, 8192) as $chunk) {
                    $part = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
                    if ($part === false) {
                        break;
                    }
                    $out .= $part;
                    if (strlen($out) > self::MAX_STREAM_BYTES) {
                        $out = substr($out, 0, self::MAX_STREAM_BYTES);
                        break;
                    }
                }
            }
        }
        return is_string($out) && $out !== '' ? $out : null;
    }

    private static function asciiHex(string $raw): string
    {
        $end = strpos($raw, '>');
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', $end === false ? $raw : substr($raw, 0, $end));
        if (strlen($hex) % 2) {
            $hex .= '0';
        }
        return (string) hex2bin($hex);
    }

    private static function ascii85(string $raw): ?string
    {
        $data = (string) preg_replace('/\s+/', '', $raw);
        if (strncmp($data, '<~', 2) === 0) {
            $data = substr($data, 2);
        }
        $end = strpos($data, '~>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        $out   = '';
        $tuple = [];
        $len   = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            if ($data[$i] === 'z' && !count($tuple)) {
                $out .= "\0\0\0\0";
                continue;
            }
            $value = ord($data[$i]) - 33;
            if ($value < 0 || $value > 84) {
                return null;
            }
            $tuple[] = $value;
            if (count($tuple) === 5) {
                $out  .= self::ascii85Group($tuple);
                $tuple = [];
            }
        }
        $rest = count($tuple);
        if ($rest > 1) {
            $out .= substr(self::ascii85Group(array_pad($tuple, 5, 84)), 0, $rest - 1);
        }
        return $out;
    }

    /**
     * @param int[] $tuple
     */
    private static function ascii85Group(array $tuple): string
    {
        $value = 0;
        foreach ($tuple as $digit) {
            $value = $value * 85 + $digit;
        }
        return pack('N', $value & 0xFFFFFFFF);
    }

    // ---------------------------------------------------------------------
    // Objects
    // ---------------------------------------------------------------------

    /**
     * Follows indirect references.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function resolve($value)
    {
        $hops = 0;
        while (is_array($value) && isset($value['r']) && $hops++ < 8) {
            $number = (int) $value['r'];
            if (!array_key_exists($number, $this->parsed)) {
                $this->parsed[$number] = null; // guards self references
                if (isset($this->objects[$number])) {
                    $pos                   = 0;
                    $this->parsed[$number] = $this->parseValue($this->objects[$number], $pos);
                }
            }
            $value = $this->parsed[$number];
        }
        return $value;
    }

    /**
     * Parses one PDF object. Representation: int|float|bool|null, names as
     * "/Name" strings, ['s' => bytes] for strings, ['r' => number] for
     * references, ['a' => list] for arrays and ['d' => map] for dictionaries.
     *
     * @return mixed
     */
    private function parseValue(string $s, int &$pos, int $depth = 0)
    {
        $len = strlen($s);
        self::skipSpace($s, $pos);
        if ($pos >= $len || $depth > self::MAX_DEPTH) {
            return null;
        }
        $c = $s[$pos];

        if ($c === '<' && ($s[$pos + 1] ?? '') === '<') {
            $pos += 2;
            $dict = [];
            while (true) {
                self::skipSpace($s, $pos);
                if ($pos >= $len) {
                    break;
                }
                if ($s[$pos] === '>' && ($s[$pos + 1] ?? '') === '>') {
                    $pos += 2;
                    break;
                }
                if ($s[$pos] !== '/') {
                    $before = $pos;
                    $this->parseValue($s, $pos, $depth + 1); // malformed entry: skipped
                    if ($pos === $before) {
                        $pos++;
                    }
                    continue;
                }
                $key        = substr(self::parseName($s, $pos), 1);
                $dict[$key] = $this->parseValue($s, $pos, $depth + 1);
            }
            return ['d' => $dict];
        }
        if ($c === '[') {
            $pos++;
            $items = [];
            while (true) {
                self::skipSpace($s, $pos);
                if ($pos >= $len) {
                    break;
                }
                if ($s[$pos] === ']') {
                    $pos++;
                    break;
                }
                $before  = $pos;
                $items[] = $this->parseValue($s, $pos, $depth + 1);
                if ($pos === $before) {
                    $pos++;
                }
            }
            return ['a' => $items];
        }
        if ($c === '(') {
            return ['s' => self::parseLiteral($s, $pos)];
        }
        if ($c === '<') {
            return ['s' => self::parseHex($s, $pos)];
        }
        if ($c === '/') {
            return self::parseName($s, $pos);
        }
        if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $s, $m, 0, $pos)) {
            $pos += strlen($m[0]);
            if (ctype_digit($m[0]) && preg_match('/\G\s+\d+\s+R(?![A-Za-z])/', $s, $ref, 0, $pos)) {
                $pos += strlen($ref[0]);
                return ['r' => (int) $m[0]];
            }
            return strpbrk($m[0], '.') !== false ? (float) $m[0] : (int) $m[0];
        }
        if (preg_match('/\G[A-Za-z]+/', $s, $m, 0, $pos)) {
            $pos += strlen($m[0]);
            return $m[0] === 'true' ? true : ($m[0] === 'false' ? false : null);
        }
        $pos++;
        return null;
    }

    private static function skipSpace(string $s, int &$pos): void
    {
        $len = strlen($s);
        while ($pos < $len) {
            $c = $s[$pos];
            if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t" || $c === "\f" || $c === "\0") {
                $pos++;
            } elseif ($c === '%') {
                $eol = strcspn($s, "\r\n", $pos);
                $pos += $eol;
            } else {
                return;
            }
        }
    }

    private static function parseName(string $s, int &$pos): string
    {
        $pos++;
        $length = strcspn($s, " \t\r\n\f\0()<>[]{}/%", $pos);
        $name   = substr($s, $pos, $length);
        $pos   += $length;
        // #xx escapes (PDF 1.2+)
        $name = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static function ($m) {
            return chr((int) hexdec($m[1]));
        }, $name);
        return '/' . $name;
    }

    private static function parseLiteral(string $s, int &$pos): string
    {
        $len   = strlen($s);
        $depth = 1;
        $out   = '';
        $pos++;
        while ($pos < $len) {
            $c = $s[$pos];
            if ($c === '\\') {
                $pos++;
                if ($pos >= $len) {
                    break;
                }
                $e = $s[$pos];
                if ($e === 'n') {
                    $out .= "\n";
                } elseif ($e === 'r') {
                    $out .= "\r";
                } elseif ($e === 't') {
                    $out .= "\t";
                } elseif ($e === 'b') {
                    $out .= "\x08";
                } elseif ($e === 'f') {
                    $out .= "\f";
                } elseif ($e === "\r") {
                    if (($s[$pos + 1] ?? '') === "\n") {
                        $pos++; // line continuation
                    }
                } elseif ($e === "\n") {
                    // line continuation
                } elseif ($e >= '0' && $e <= '7') {
                    $octal = $e;
                    while (strlen($octal) < 3 && $pos + 1 < $len && $s[$pos + 1] >= '0' && $s[$pos + 1] <= '7') {
                        $octal .= $s[++$pos];
                    }
                    $out .= chr(octdec($octal) & 0xFF);
                } else {
                    $out .= $e; // \( \) \\ and unknown escapes
                }
                $pos++;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth === 0) {
                $pos++;
                break;
            }
            $out .= $c;
            $pos++;
        }
        return $out;
    }

    private static function parseHex(string $s, int &$pos): string
    {
        $len = strlen($s);
        $end = $pos + 1 < $len ? strpos($s, '>', $pos + 1) : false;
        if ($end === false) {
            $end = $len;
        }
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $pos + 1, $end - $pos - 1));
        $pos = min($end + 1, $len);
        if (strlen($hex) % 2) {
            $hex .= '0';
        }
        return (string) hex2bin($hex);
    }

    // ---------------------------------------------------------------------
    // Pages
    // ---------------------------------------------------------------------

    private function text(int $max_pages, int $max_chars): string
    {
        $out = '';
        foreach ($this->pages($max_pages) as [$page, $resources]) {
            $content = $this->contents($page);
            if ($content !== '') {
                $out .= $this->contentText($content, $this->resolve($resources), 0) . "\n\n";
            }
            if (($max_chars > 0 && mb_strlen($out) > $max_chars * 2) || microtime(true) > $this->deadline) {
                break;
            }
        }
        return $out;
    }

    /**
     * Pages in reading order with their resources (inherited from the page tree).
     *
     * @return array<int, array{0: mixed, 1: mixed}>
     */
    private function pages(int $max_pages): array
    {
        $pages = [];
        foreach ($this->objects as $number => $body) {
            if (preg_match('#/Type\s*/Catalog\b#', substr($body, 0, 4096))) {
                $catalog = $this->resolve(['r' => $number]);
                if (isset($catalog['d']['Pages'])) {
                    $this->walk($catalog['d']['Pages'], null, $pages, $max_pages, 0);
                }
                break;
            }
        }
        if (!count($pages)) {
            // No usable page tree: page objects in file order
            foreach ($this->objects as $number => $body) {
                if (count($pages) >= $max_pages) {
                    break;
                }
                if (preg_match('#/Type\s*/Page(?![A-Za-z])#', substr($body, 0, 4096))) {
                    $page    = $this->resolve(['r' => $number]);
                    $pages[] = [$page, $page['d']['Resources'] ?? null];
                }
            }
        }
        return $pages;
    }

    /**
     * @param mixed $node
     * @param mixed $resources
     */
    private function walk($node, $resources, array &$pages, int $max_pages, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || count($pages) >= $max_pages) {
            return;
        }
        $node = $this->resolve($node);
        if (!is_array($node) || !isset($node['d'])) {
            return;
        }
        $resources = $node['d']['Resources'] ?? $resources;
        $kids      = $this->resolve($node['d']['Kids'] ?? null);
        if (is_array($kids) && isset($kids['a'])) {
            foreach ($kids['a'] as $kid) {
                $this->walk($kid, $resources, $pages, $max_pages, $depth + 1);
                if (count($pages) >= $max_pages) {
                    return;
                }
            }
            return;
        }
        if (isset($node['d']['Contents'])) {
            $pages[] = [$node, $resources];
        }
    }

    /**
     * @param mixed $page
     */
    private function contents($page): string
    {
        $contents = $page['d']['Contents'] ?? null;
        $list     = [];
        if (is_array($contents) && isset($contents['r'])) {
            $target = $this->resolve($contents);
            $list   = is_array($target) && isset($target['a']) ? $target['a'] : [$contents];
        } elseif (is_array($contents) && isset($contents['a'])) {
            $list = $contents['a'];
        }
        $out = '';
        foreach ($list as $ref) {
            $stream = $this->streamOf($ref);
            if ($stream !== null) {
                $out .= $stream . "\n";
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Content streams
    // ---------------------------------------------------------------------

    /**
     * Text shown by a content stream. Word gaps and line breaks come from
     * the positions: the text matrix advances with the width of each glyph,
     * so a piece of word placed right after the previous one (generators
     * split words at kerning pairs) is joined, a gap becomes a space and a
     * new baseline a new line.
     *
     * @param mixed $resources
     */
    private function contentText(string $content, $resources, int $depth): string
    {
        $fonts    = $this->fonts($resources);
        $xobjects = is_array($resources) && isset($resources['d']['XObject']) ? $this->resolve($resources['d']['XObject']) : null;
        $len      = strlen($content);
        $pos      = 0;
        $operands = [];
        $out      = '';

        // Text state: font, size, character and word spacing, horizontal scaling, leading
        $font  = null;
        $state = ['size' => 0.0, 'tc' => 0.0, 'tw' => 0.0, 'th' => 1.0];
        $tl    = 0.0;
        // Line and text matrices reduced to [a, d, e, f] (no rotation), end of the last text shown
        $line = [1.0, 1.0, 0.0, 0.0];
        $tm   = $line;
        $end  = null;

        while ($pos < $len) {
            if (microtime(true) > $this->deadline) {
                break;
            }
            self::skipSpace($content, $pos);
            if ($pos >= $len) {
                break;
            }
            $c = $content[$pos];
            if (strpos('(<[/+-.0123456789', $c) !== false) {
                $operands[] = $this->parseValue($content, $pos);
                if (count($operands) > 32) {
                    array_shift($operands);
                }
                continue;
            }
            $length = strcspn($content, " \t\r\n\f\0()<>[]{}/%", $pos);
            if ($length === 0) {
                $pos++;
                continue;
            }
            $operator = substr($content, $pos, $length);
            $pos     += $length;

            $count = count($operands);
            switch ($operator) {
                case 'BT':
                    $line = [1.0, 1.0, 0.0, 0.0];
                    $tm   = $line;
                    break;
                case 'Tf':
                    $name          = $operands[$count - 2] ?? null;
                    $font          = is_string($name) ? ($fonts[substr($name, 1)] ?? null) : null;
                    $state['size'] = self::num($operands[$count - 1] ?? 0);
                    break;
                case 'Tc':
                case 'Tw':
                    $state[strtolower($operator)] = self::num($operands[$count - 1] ?? 0);
                    break;
                case 'Tz':
                    $state['th'] = self::num($operands[$count - 1] ?? 100) / 100;
                    break;
                case 'TL':
                    $tl = self::num($operands[$count - 1] ?? 0);
                    break;
                case 'Td':
                case 'TD':
                    $tx = self::num($operands[$count - 2] ?? 0);
                    $ty = self::num($operands[$count - 1] ?? 0);
                    if ($operator === 'TD') {
                        $tl = -$ty;
                    }
                    $line = [$line[0], $line[1], $line[2] + $tx * $line[0], $line[3] + $ty * $line[1]];
                    $tm   = $line;
                    break;
                case 'Tm':
                    if ($count >= 6) {
                        $line = [self::num($operands[$count - 6]), self::num($operands[$count - 3]), self::num($operands[$count - 2]), self::num($operands[$count - 1])];
                        $tm   = $line;
                    }
                    break;
                case 'T*':
                    $line = [$line[0], $line[1], $line[2], $line[3] - $tl * $line[1]];
                    $tm   = $line;
                    break;
                case "'":
                case '"':
                    if ($operator === '"' && $count >= 3) {
                        $state['tw'] = self::num($operands[$count - 3]);
                        $state['tc'] = self::num($operands[$count - 2]);
                    }
                    $line = [$line[0], $line[1], $line[2], $line[3] - $tl * $line[1]];
                    $tm   = $line;
                    // no break: shows the string like Tj
                case 'Tj':
                    $string = $operands[$count - 1] ?? null;
                    if (is_array($string) && isset($string['s'])) {
                        $this->show($out, $end, $tm, $font, $state, $string['s']);
                    }
                    break;
                case 'TJ':
                    $array = $operands[$count - 1] ?? null;
                    foreach ((is_array($array) && isset($array['a']) ? $array['a'] : []) as $item) {
                        if (is_array($item) && isset($item['s'])) {
                            $this->show($out, $end, $tm, $font, $state, $item['s']);
                        } elseif (is_int($item) || is_float($item)) {
                            // Adjustment in thousandths of the font size (negative: to the right)
                            $tm[2] -= $item / 1000 * $state['size'] * $state['th'] * $tm[0];
                        }
                    }
                    break;
                case 'Do':
                    $name = $operands[$count - 1] ?? null;
                    if ($depth < 3 && is_string($name) && is_array($xobjects) && isset($xobjects['d'][substr($name, 1)])) {
                        $ref  = $xobjects['d'][substr($name, 1)];
                        $form = $this->resolve($ref);
                        if (is_array($form) && ($form['d']['Subtype'] ?? '') === '/Form') {
                            $stream = $this->streamOf($ref);
                            if ($stream !== null) {
                                $inner = isset($form['d']['Resources']) ? $this->resolve($form['d']['Resources']) : $resources;
                                $text  = $this->contentText($stream, $inner, $depth + 1);
                                if (trim($text) !== '') {
                                    // Other coordinate system: kept on lines of its own
                                    self::newline($out);
                                    $out .= trim($text) . "\n";
                                    $end  = null;
                                }
                            }
                        }
                    }
                    break;
                case 'BI':
                    // Inline image: binary data between ID and EI
                    $data = strpos($content, 'ID', $pos);
                    if ($data === false || $data + 2 >= $len || !preg_match('/\sEI(?=[\s]|$)/', $content, $m, PREG_OFFSET_CAPTURE, $data + 2)) {
                        $pos = $len;
                    } else {
                        $pos = (int) $m[0][1] + 3;
                    }
                    break;
            }
            $operands = [];
        }
        return $out;
    }

    /**
     * Shows a string: separator deduced from where the previous text ended
     * (new line, word gap or nothing), decoded text, then the text matrix
     * moves by the width of the glyphs.
     *
     * @param array{0: float, 1: float, 2: float, 3: float}|null $end  [e, f, em, line] of the previous text
     * @param array{0: float, 1: float, 2: float, 3: float}      $tm   text matrix [a, d, e, f]
     * @param array{size: float, tc: float, tw: float, th: float} $state
     */
    private function show(string &$out, ?array &$end, array &$tm, ?array $font, array $state, string $bytes): void
    {
        [$text, $advance] = $this->decode($font, $bytes, $state);
        if ($end !== null) {
            $em = max(abs($end[2]), 0.001);
            $dx = ($tm[2] - $end[0]) * ($tm[0] < 0 ? -1 : 1);
            if (abs($tm[3] - $end[1]) > 0.4 * max(abs($end[3]), 0.001)) {
                self::newline($out);
            } elseif ($dx > 0.18 * $em) {
                self::space($out);
            } elseif ($dx < -$em) {
                self::newline($out); // back to the left on the same baseline: another column
            }
        }
        $out  .= $text;
        $tm[2] += $advance * $tm[0];
        $end   = [$tm[2], $tm[3], $state['size'] * $tm[0], $state['size'] * $tm[1]];
    }

    /**
     * @param mixed $value
     */
    private static function num($value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    private static function newline(string &$out): void
    {
        if ($out !== '' && substr($out, -1) !== "\n") {
            $out = rtrim($out, ' ') . "\n";
        }
    }

    private static function space(string &$out): void
    {
        if ($out !== '' && !in_array(substr($out, -1), [' ', "\n"], true)) {
            $out .= ' ';
        }
    }

    /**
     * Decoders of the fonts of a resources dictionary, by resource name:
     * Unicode map, code length, glyph widths (thousandths of the font size).
     *
     * @param mixed $resources
     *
     * @return array<string, array{map: array<string, string>, bytes: int, simple: bool, widths: array<int, float>, dw: float}>
     */
    private function fonts($resources): array
    {
        $dict = is_array($resources) && isset($resources['d']['Font']) ? $this->resolve($resources['d']['Font']) : null;
        if (!is_array($dict) || !isset($dict['d'])) {
            return [];
        }
        $out = [];
        foreach ($dict['d'] as $name => $ref) {
            $key = is_array($ref) && isset($ref['r']) ? 'r' . $ref['r'] : null;
            if ($key !== null && isset($this->fonts[$key])) {
                $out[$name] = $this->fonts[$key];
                continue;
            }
            $font = $this->resolve($ref);
            // Without metrics, a glyph is assumed half as wide as it is high
            $decoder = ['map' => [], 'bytes' => 1, 'simple' => true, 'widths' => [], 'dw' => 500.0];
            if (is_array($font) && isset($font['d'])) {
                if (($font['d']['Subtype'] ?? '') === '/Type0') {
                    $decoder['simple'] = false;
                    $decoder['bytes']  = 2;
                    $decoder['dw']     = 1000.0;
                    $descendants       = $this->resolve($font['d']['DescendantFonts'] ?? null);
                    $cid_font          = is_array($descendants) && isset($descendants['a'][0]) ? $this->resolve($descendants['a'][0]) : null;
                    if (is_array($cid_font) && isset($cid_font['d'])) {
                        $default = $this->resolve($cid_font['d']['DW'] ?? null);
                        if (is_int($default) || is_float($default)) {
                            $decoder['dw'] = (float) $default;
                        }
                        $decoder['widths'] = $this->cidWidths($this->resolve($cid_font['d']['W'] ?? null));
                    }
                } else {
                    $widths = $this->resolve($font['d']['Widths'] ?? null);
                    if (is_array($widths) && isset($widths['a'])) {
                        $first = (int) self::num($this->resolve($font['d']['FirstChar'] ?? 0));
                        foreach ($widths['a'] as $k => $width) {
                            $width = $this->resolve($width);
                            if (is_int($width) || is_float($width)) {
                                $decoder['widths'][$first + $k] = (float) $width;
                            }
                        }
                        $descriptor    = $this->resolve($font['d']['FontDescriptor'] ?? null);
                        $missing       = is_array($descriptor) && isset($descriptor['d']['MissingWidth']) ? $this->resolve($descriptor['d']['MissingWidth']) : null;
                        $decoder['dw'] = is_int($missing) || is_float($missing) ? (float) $missing : 500.0;
                    } elseif (stripos((string) ($font['d']['BaseFont'] ?? ''), 'Courier') !== false) {
                        $decoder['dw'] = 600.0;
                    }
                }
                $cmap = isset($font['d']['ToUnicode']) ? $this->streamOf($font['d']['ToUnicode']) : null;
                if ($cmap !== null) {
                    [$map, $bytes] = self::parseCMap($cmap);
                    if (count($map)) {
                        $decoder['map']   = $map;
                        $decoder['bytes'] = $bytes;
                    }
                }
            }
            if ($key !== null) {
                $this->fonts[$key] = $decoder;
            }
            $out[$name] = $decoder;
        }
        return $out;
    }

    /**
     * Glyph widths of a CIDFont: /W [c [w1 w2 …]  cfirst clast w  …].
     *
     * @param mixed $w
     *
     * @return array<int, float>
     */
    private function cidWidths($w): array
    {
        $items  = is_array($w) && isset($w['a']) ? $w['a'] : [];
        $count  = count($items);
        $out    = [];
        $budget = self::MAX_CMAP_ENTRIES;
        for ($i = 0; $i < $count && $budget > 0;) {
            $first = $items[$i];
            $next  = $this->resolve($items[$i + 1] ?? null);
            if (is_int($first) && is_array($next) && isset($next['a'])) {
                foreach ($next['a'] as $k => $width) {
                    if ((is_int($width) || is_float($width)) && $budget-- > 0) {
                        $out[$first + $k] = (float) $width;
                    }
                }
                $i += 2;
            } elseif (is_int($first) && is_int($next) && isset($items[$i + 2]) && (is_int($items[$i + 2]) || is_float($items[$i + 2]))) {
                for ($code = $first; $code <= $next && $budget-- > 0; $code++) {
                    $out[$code] = (float) $items[$i + 2];
                }
                $i += 3;
            } else {
                $i++;
            }
        }
        return $out;
    }

    /**
     * Text of a string shown with a font, and its advance in text space.
     *
     * @param array{map: array<string, string>, bytes: int, simple: bool, widths: array<int, float>, dw: float}|null $font
     * @param array{size: float, tc: float, tw: float, th: float} $state
     *
     * @return array{0: string, 1: float}
     */
    private function decode(?array $font, string $bytes, array $state): array
    {
        $step    = $font !== null ? max(1, $font['bytes']) : 1;
        $map     = $font !== null ? $font['map'] : [];
        $total   = strlen($bytes);
        $text    = '';
        $advance = 0.0;
        for ($i = 0; $i + $step <= $total; $i += $step) {
            $code   = substr($bytes, $i, $step);
            $number = 0;
            for ($k = 0; $k < $step; $k++) {
                $number = ($number << 8) | ord($code[$k]);
            }
            if (count($map)) {
                if (isset($map[$code])) {
                    $text .= $map[$code];
                } elseif ($step === 1) {
                    $text .= self::winAnsi($code);
                }
            }
            $width    = $font !== null ? ($font['widths'][$number] ?? $font['dw']) : 500.0;
            $advance += ($width / 1000 * $state['size'] + $state['tc'] + ($step === 1 && $number === 32 ? $state['tw'] : 0.0)) * $state['th'];
        }
        if (!count($map)) {
            // Simple font without map: WinAnsi; composite font without map: glyph numbers, no text
            $text = $font === null || $font['simple'] ? self::winAnsi($bytes) : '';
        }
        return [$text, $advance];
    }

    private static function winAnsi(string $bytes): string
    {
        $text = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        $text = is_string($text) ? $text : '';
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

    /**
     * ToUnicode CMap: [code bytes => UTF-8 text, code length in bytes].
     *
     * @return array{0: array<string, string>, 1: int}
     */
    private static function parseCMap(string $cmap): array
    {
        $bytes = 1;
        if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $m)) {
            $bytes = max(1, min(4, (int) (strlen($m[1]) / 2)));
        }

        $map    = [];
        $budget = self::MAX_CMAP_ENTRIES;
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $pair) {
                    if ($budget-- <= 0) {
                        break 2;
                    }
                    $map[self::hexBytes($pair[1])] = self::utf16($pair[2]);
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $block, $ranges, PREG_SET_ORDER);
                foreach ($ranges as $range) {
                    $low   = (int) hexdec($range[1]);
                    $high  = (int) hexdec($range[2]);
                    $width = strlen($range[1]) + strlen($range[1]) % 2;
                    if ($high < $low || $high - $low > 65535) {
                        continue;
                    }
                    if ($range[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]*)>/', $range[3], $targets);
                        foreach ($targets[1] as $k => $hex) {
                            if ($low + $k > $high || $budget-- <= 0) {
                                break;
                            }
                            $map[self::codeBytes($low + $k, $width)] = self::utf16($hex);
                        }
                        continue;
                    }
                    $start  = str_pad(trim($range[3], '<>'), 4, '0', STR_PAD_LEFT);
                    $prefix = substr($start, 0, -4);
                    $first  = (int) hexdec(substr($start, -4));
                    for ($code = $low; $code <= $high; $code++) {
                        if ($budget-- <= 0) {
                            break 3;
                        }
                        $map[self::codeBytes($code, $width)] = self::utf16($prefix . sprintf('%04X', ($first + $code - $low) & 0xFFFF));
                    }
                }
            }
        }
        return [$map, $bytes];
    }

    private static function hexBytes(string $hex): string
    {
        return (string) hex2bin(strlen($hex) % 2 ? '0' . $hex : $hex);
    }

    private static function codeBytes(int $code, int $width): string
    {
        $hex = dechex($code);
        return (string) hex2bin(str_pad($hex, max(2, $width, strlen($hex) + strlen($hex) % 2), '0', STR_PAD_LEFT));
    }

    private static function utf16(string $hex): string
    {
        if ($hex === '') {
            return '';
        }
        $units = (int) ceil(strlen($hex) / 4);
        $text  = @mb_convert_encoding((string) hex2bin(str_pad($hex, $units * 4, '0', STR_PAD_LEFT)), 'UTF-8', 'UTF-16BE');
        return is_string($text) ? (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) : '';
    }

    /**
     * Rejects what an unknown font encoding turns into: mostly symbols.
     * (No word-spacing test: Chinese or Japanese text has no spaces.)
     */
    private static function looksLikeText(string $text): bool
    {
        $total = mb_strlen($text);
        if ($total === 0) {
            return false;
        }
        if ($total < 40) {
            return true;
        }
        $readable = (int) preg_match_all('/[\p{L}\p{N}\p{P}\s]/u', $text);
        return $readable / $total >= 0.75;
    }
}
