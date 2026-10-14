<?php

namespace GlpiPlugin\Ticketassistant\Tests\Unit;

use GlpiPlugin\Ticketassistant\DocumentReader;
use GlpiPlugin\Ticketassistant\PdfText;
use PHPUnit\Framework\TestCase;

final class PdfTextTest extends TestCase
{
    private const PAGE_TREE = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    ];

    public function testSimpleFontWithEscapesAndLineBreaks(): void
    {
        $content = "BT /F1 12 Tf 50 780 Td 16 TL\n(Network change notice \\(NC-118\\)) Tj T*\n(Configura\\347\\343o da VPN) Tj T*\nET";
        $pdf     = self::pdf(self::PAGE_TREE + [4 => self::stream($content)]);

        $this->assertSame("Network change notice (NC-118)\nConfiguração da VPN", PdfText::fromString($pdf, 1000));
    }

    public function testCompressedStreamWithKerningAndTextMatrix(): void
    {
        $content = 'BT /F1 10 Tf 1 0 0 1 72 700 Tm [(Hel) -20 (lo) -300 (world)] TJ 1 0 0 1 72 680 Tm (Second line) Tj ET';
        $pdf     = self::pdf(self::PAGE_TREE + [4 => self::stream((string) gzcompress($content), ' /Filter /FlateDecode')]);

        $this->assertSame("Hello world\nSecond line", PdfText::fromString($pdf, 1000));
    }

    public function testWordPiecesPlacedByTheirWidthAreJoined(): void
    {
        // Browsers (Skia) split words at kerning pairs and place each piece with Td;
        // table cells are separate text blocks on the same baseline
        $widths  = '[' . implode(' ', array_fill(0, 95, 500)) . ']';
        $content = 'BT /F1 10 Tf 1 0 0 -1 72 700 Tm (A) Tj 5 0 Td (viso) Tj 25 0 Td (de rede) Tj ET'
            . ' BT /F1 10 Tf 1 0 0 -1 72 720 Tm (Servi) Tj 25 0 Td (\\347o) Tj ET'
            . ' BT /F1 10 Tf 1 0 0 -1 160 720 Tm (Porta) Tj ET';
        $pdf = self::pdf([
            1 => self::PAGE_TREE[1],
            2 => self::PAGE_TREE[2],
            3 => self::PAGE_TREE[3],
            4 => self::stream($content),
            5 => '<< /Type /Font /Subtype /TrueType /BaseFont /Arial /FirstChar 32 /LastChar 126 /Widths ' . $widths . ' /Encoding /WinAnsiEncoding >>',
        ]);

        $this->assertSame("Aviso de rede\nServiço Porta", PdfText::fromString($pdf, 1000));
    }

    public function testCompositeFontWithToUnicodeMap(): void
    {
        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CMapName /Adobe-Identity-UCS def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . "2 beginbfchar\n<0001> <0048>\n<0002> <00E9>\nendbfchar\n"
            . "1 beginbfrange\n<0003> <0005> <006C>\nendbfrange\n"
            . "1 beginbfrange\n<0006> <0007> [<0020> <D83DDE00>]\nendbfrange\n"
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
        $content = 'BT /F2 12 Tf 72 700 Td <0001000200030003> Tj <0006> Tj <0007> Tj ET';
        $pdf     = self::pdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /Font << /F2 5 0 R >> >> >>',
            4 => self::stream((string) gzcompress($content), ' /Filter /FlateDecode'),
            5 => '<< /Type /Font /Subtype /Type0 /BaseFont /ABCDEF+Calibri /Encoding /Identity-H /DescendantFonts [7 0 R] /ToUnicode 6 0 R >>',
            6 => self::stream((string) gzcompress($cmap), ' /Filter /FlateDecode'),
            7 => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /ABCDEF+Calibri /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> >>',
        ]);

        $this->assertSame("Héll \u{1F600}", PdfText::fromString($pdf, 1000));
    }

    public function testLigaturesAreExpanded(): void
    {
        $cmap = "begincmap\n1 begincodespacerange\n<00> <FF>\nendcodespacerange\n1 beginbfchar\n<01> <FB01>\nendbfchar\nendcmap";
        $pdf  = self::pdf([
            1 => self::PAGE_TREE[1],
            2 => self::PAGE_TREE[2],
            3 => self::PAGE_TREE[3],
            4 => self::stream('BT /F1 12 Tf 72 700 Td (\\001le per\\001l) Tj ET'),
            5 => '<< /Type /Font /Subtype /TrueType /BaseFont /Calibri /ToUnicode 6 0 R >>',
            6 => self::stream($cmap),
        ]);

        $this->assertSame('file perfil', PdfText::fromString($pdf, 1000));
    }

    public function testCompositeFontWithoutToUnicodeGivesNothing(): void
    {
        $pdf = self::pdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /Font << /F2 5 0 R >> >> >>',
            4 => self::stream('BT /F2 12 Tf 72 700 Td <00240025> Tj ET'),
            5 => '<< /Type /Font /Subtype /Type0 /BaseFont /Calibri /Encoding /Identity-H >>',
        ]);

        $this->assertSame('', PdfText::fromString($pdf, 1000));
    }

    public function testObjectsInsideAnObjectStream(): void
    {
        $inner  = [
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $header = '';
        $body   = '';
        foreach ($inner as $number => $object) {
            $header .= $number . ' ' . strlen($body) . ' ';
            $body   .= $object . "\n";
        }
        $pdf = self::pdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            4 => self::stream('BT /F1 12 Tf 72 700 Td (Stored in an object stream) Tj ET'),
            6 => self::stream((string) gzcompress($header . $body), ' /Type /ObjStm /N 3 /First ' . strlen($header) . ' /Filter /FlateDecode'),
        ]);

        $this->assertSame('Stored in an object stream', PdfText::fromString($pdf, 1000));
    }

    public function testTextInsideAFormXObject(): void
    {
        $pdf = self::pdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /XObject << /Fm1 6 0 R >> >> >>',
            4 => self::stream('q 1 0 0 1 0 0 cm /Fm1 Do Q'),
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            6 => self::stream('BT /F1 9 Tf 10 10 Td (Inside the form) Tj ET', ' /Type /XObject /Subtype /Form /BBox [0 0 200 50] /Resources << /Font << /F1 5 0 R >> >>'),
        ]);

        $this->assertSame('Inside the form', PdfText::fromString($pdf, 1000));
    }

    public function testInlineImageDataIsSkipped(): void
    {
        $content = "BI /W 2 /H 1 /BPC 8 /CS /G ID (\xFF\nEI\nBT /F1 12 Tf 72 700 Td (After the image) Tj ET";
        $pdf     = self::pdf(self::PAGE_TREE + [4 => self::stream($content)]);

        $this->assertSame('After the image', PdfText::fromString($pdf, 1000));
    }

    public function testIncrementalUpdateUsesTheLastVersion(): void
    {
        $pdf  = self::pdf(self::PAGE_TREE + [4 => self::stream('BT /F1 12 Tf 72 700 Td (Old text) Tj ET')]);
        $pdf .= "4 0 obj\n" . self::stream('BT /F1 12 Tf 72 700 Td (New text) Tj ET') . "\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        $this->assertSame('New text', PdfText::fromString($pdf, 1000));
    }

    public function testStreamDataLookingLikeObjectsDoesNotBreakTheIndex(): void
    {
        // Binary data of an image containing "obj" and "endobj" sequences
        $noise = "garbage 9 0 obj << >> endobj (unbalanced 7 0 obj";
        $pdf   = self::pdf(self::PAGE_TREE + [
            4 => self::stream('BT /F1 12 Tf 72 700 Td (Real text) Tj ET'),
            6 => self::stream($noise, ' /Type /XObject /Subtype /Image /Width 1 /Height 1 /Filter /DCTDecode'),
        ]);

        $this->assertSame('Real text', PdfText::fromString($pdf, 1000));
    }

    public function testEncryptedOrInvalidFilesGiveNothing(): void
    {
        $encrypted = str_replace('/Root 1 0 R', '/Root 1 0 R /Encrypt 9 0 R', self::pdf(self::PAGE_TREE + [4 => self::stream('BT (Secret) Tj ET')]));

        $this->assertSame('', PdfText::fromString($encrypted, 1000));
        $this->assertSame('', PdfText::fromString('not a pdf at all', 1000));
        $this->assertSame('', PdfText::fromString("%PDF-1.7\n" . random_bytes(4096), 1000));
    }

    public function testDocumentReaderFallsBackToTheBuiltInReader(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, self::pdf(self::PAGE_TREE + [4 => self::stream('BT /F1 12 Tf 72 700 Td (Read without poppler) Tj ET')]));
        try {
            // A poppler directory that does not exist: pdftotext is not available
            $text = DocumentReader::readPdfText($file, 1000, 10, sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'no-poppler-' . uniqid());
        } finally {
            @unlink($file);
        }
        $this->assertSame('Read without poppler', $text);
    }

    /**
     * @param array<int, string> $objects object number => body
     */
    private static function pdf(array $objects): string
    {
        $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
        foreach ($objects as $number => $body) {
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }
        return $pdf . "trailer\n<< /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R >>\n%%EOF\n";
    }

    private static function stream(string $data, string $extra = ''): string
    {
        return '<< /Length ' . strlen($data) . $extra . " >>\nstream\n" . $data . "\nendstream";
    }
}
