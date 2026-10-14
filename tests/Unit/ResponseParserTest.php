<?php

namespace GlpiPlugin\Ticketassistant\Tests\Unit;

use GlpiPlugin\Ticketassistant\Llm\LlmException;
use GlpiPlugin\Ticketassistant\ResponseParser;
use PHPUnit\Framework\TestCase;

final class ResponseParserTest extends TestCase
{
    public function testPlainJson(): void
    {
        $data = ResponseParser::parse('{"reply_html":"<p>Hi</p>"}', ['reply_html' => 'html']);
        $this->assertSame('<p>Hi</p>', $data['reply_html']);
    }

    public function testJsonInsideMarkdownFenceAndProse(): void
    {
        $this->assertSame(['a' => 1], ResponseParser::decodeObject("```json\n{\"a\": 1}\n```"));
        $this->assertSame(['a' => 1], ResponseParser::decodeObject("Sure! Here it is:\n{\"a\": 1}\nAnything else?"));
        $this->assertNull(ResponseParser::decodeObject('no json at all'));
    }

    public function testMissingRequiredKeyThrows(): void
    {
        $this->expectException(LlmException::class);
        ResponseParser::parse('{"other":"x"}', ['reply_html' => 'html']);
    }

    public function testOptionalAndNullableKeys(): void
    {
        $data = ResponseParser::parse(
            '{"type":"2","category_id":null,"confidence":0.8}',
            ['type' => 'int', 'category_id' => '?int', 'confidence' => 'float', '?urgency' => 'int', '?keywords' => 'array'],
        );
        $this->assertSame(2, $data['type']);
        $this->assertNull($data['category_id']);
        $this->assertSame(0.8, $data['confidence']);
        $this->assertNull($data['urgency']);
        $this->assertSame([], $data['keywords']);
    }

    public function testHtmlIsSanitized(): void
    {
        $html = '<p onclick="x()">Hi <a href="javascript:alert(1)">link</a></p><script>alert(1)</script>'
            . '<img src=x onerror=alert(1)><h1 class="t">Title</h1><div>Block</div>';
        $this->assertSame('<p>Hi link</p><h4>Title</h4><p>Block</p>', ResponseParser::sanitizeHtml($html));
    }

    public function testMarkdownIsConvertedBeforeSanitizing(): void
    {
        $html = ResponseParser::toSafeHtml("Hello **Maria**,\n\n1. Restart\n2. Test");
        $this->assertStringContainsString('<strong>Maria</strong>', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<li>Restart</li>', $html);
    }

    /**
     * @dataProvider closingProvider
     */
    public function testClosingIsStripped(string $input, string $expected): void
    {
        $this->assertSame($expected, ResponseParser::stripClosing($input));
    }

    public static function closingProvider(): array
    {
        return [
            'pt'               => ['<p>Feito.</p><p>Atenciosamente,</p>', '<p>Feito.</p>'],
            'en with name'     => ['<p>Done.</p><p>Best regards,<br>John</p>', '<p>Done.</p>'],
            'fr'               => ['<p>Fait.</p><p>Cordialement</p><p>&nbsp;</p>', '<p>Fait.</p>'],
            'sentence is kept' => ['<p>Thanks for your patience while we fix it.</p>', '<p>Thanks for your patience while we fix it.</p>'],
        ];
    }
}
