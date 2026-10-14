<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use Document;

/**
 * Turns the documents of a ticket into AI inputs, within the configured
 * limits (newest links first):
 *  - images                     -> vision parts (downscaled when needed);
 *  - PDF with text              -> extracted text (pdftotext, else PdfText);
 *  - scanned PDF                -> first pages as images (pdftoppm);
 *  - PDF, "native" mode         -> the file itself, for providers reading PDF;
 *  - Office / OpenDocument      -> extracted text;
 *  - text files (logs, CSV…)    -> text;
 *  - everything else            -> listed by name only.
 */
final class Attachments
{
    /** Maximum file names listed in "other attachments" */
    private const MAX_OTHERS = 20;

    /**
     * @param array<int, bool> $links        documents_id => only in private items
     * @param array            $config
     * @param array            $capabilities ['vision' => bool, 'native_pdf' => bool]
     *
     * @return array{
     *   images: array<int, array{mime: string, data: string, name: string}>,
     *   files: array<int, array{mime: string, data: string, name: string}>,
     *   texts: array<int, array{name: string, text: string}>,
     *   documents: string[],
     *   others: string[]
     * }
     */
    public static function collect(array $links, array $config, array $capabilities): array
    {
        $out = ['images' => [], 'files' => [], 'texts' => [], 'documents' => [], 'others' => []];

        $vision        = !empty($capabilities['vision']) && !empty($config['images_enabled']);
        $max_images    = $vision ? max(0, (int) ($config['images_max_count'] ?? 4)) : 0;
        $max_bytes     = max(1, (int) ($config['images_max_mb'] ?? 4)) * 1024 * 1024;
        $max_px        = max(512, (int) ($config['images_max_px'] ?? 2048));
        $pdf_enabled   = !empty($config['pdf_enabled']);
        $max_pdfs      = $pdf_enabled ? max(0, (int) ($config['pdf_max_count'] ?? 2)) : 0;
        $pdf_chars     = max(500, (int) ($config['pdf_max_chars'] ?? 4000));
        $scan_pages    = $vision ? max(0, (int) ($config['pdf_scan_pages'] ?? 2)) : 0;
        $native_pdf    = !empty($capabilities['native_pdf']) && !empty($config['pdf_native']);
        $docs_enabled  = !empty($config['docs_enabled']);
        $max_docs      = $docs_enabled ? max(0, (int) ($config['docs_max_count'] ?? 3)) : 0;
        $docs_chars    = max(500, (int) ($config['docs_max_chars'] ?? 4000));
        $poppler       = (string) ($config['poppler_path'] ?? '');
        $tmp_dir       = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
        $pdf_seen      = 0;
        $docs_seen     = 0;

        foreach ($links as $documents_id => $private) {
            $document = new Document();
            if (!$document->getFromDB((int) $documents_id) || !empty($document->fields['is_deleted'])) {
                continue;
            }

            $raw_name = (string) ($document->fields['filename'] ?? '');
            $name     = Text::toPlainText($raw_name !== '' ? $raw_name : ('document_' . $documents_id), 150)
                . ($private ? ' (internal note)' : '');
            $mime     = (string) ($document->fields['mime'] ?? '');
            $path     = rtrim((string) GLPI_DOC_DIR, '/\\') . '/' . ltrim((string) ($document->fields['filepath'] ?? ''), '/\\');
            $kind     = DocumentReader::kindOf($mime, $raw_name);

            if (DocumentReader::fileSize($path) <= 0) {
                self::other($out, $name);
                continue;
            }

            switch ($kind) {
                case DocumentReader::KIND_IMAGE:
                    if (count($out['images']) < $max_images) {
                        $image = DocumentReader::prepareImage($path, $max_bytes, $max_px);
                        if ($image !== null) {
                            $out['images'][] = ['mime' => $image[0], 'data' => $image[1], 'name' => $name];
                            continue 2;
                        }
                    }
                    break;

                case DocumentReader::KIND_PDF:
                    if ($pdf_seen >= $max_pdfs) {
                        break;
                    }
                    $pdf_seen++;
                    if ($native_pdf && DocumentReader::fileSize($path) <= 20 * 1024 * 1024) {
                        $data = @file_get_contents($path);
                        if ($data !== false && $data !== '') {
                            $out['files'][]     = ['mime' => 'application/pdf', 'data' => base64_encode($data), 'name' => $name];
                            $out['documents'][] = $name;
                            continue 2;
                        }
                    }
                    $text = DocumentReader::readPdfText($path, $pdf_chars, 10, $poppler);
                    if (mb_strlen($text) >= 120) {
                        $out['texts'][]     = ['name' => $name, 'text' => $text];
                        $out['documents'][] = $name;
                        continue 2;
                    }
                    // Scanned PDF: pages become images (shares the image budget)
                    $free = min($scan_pages, $max_images - count($out['images']));
                    if ($free > 0) {
                        $pages = DocumentReader::pdfPagesAsPng($path, $free, $tmp_dir, $poppler);
                        foreach ($pages as $index => $png) {
                            $out['images'][] = [
                                'mime' => 'image/png',
                                'data' => $png,
                                'name' => $name . ' (' . sprintf(__('page %d', 'ticketassistant'), $index + 1) . ')',
                            ];
                        }
                        if (count($pages)) {
                            $out['documents'][] = $name;
                            continue 2;
                        }
                    }
                    break;

                case DocumentReader::KIND_OFFICE:
                case DocumentReader::KIND_TEXT:
                    if ($docs_seen >= $max_docs) {
                        break;
                    }
                    $docs_seen++;
                    $text = $kind === DocumentReader::KIND_OFFICE
                        ? DocumentReader::readOffice($path, $raw_name, $docs_chars)
                        : DocumentReader::readText($path, $raw_name, $docs_chars);
                    if (trim($text) !== '') {
                        $out['texts'][]     = ['name' => $name, 'text' => $text];
                        $out['documents'][] = $name;
                        continue 2;
                    }
                    break;
            }

            self::other($out, $name);
        }

        return $out;
    }

    private static function other(array &$out, string $name): void
    {
        if (count($out['others']) < self::MAX_OTHERS) {
            $out['others'][] = $name;
        }
    }
}
