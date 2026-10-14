<?php

namespace GlpiPlugin\Ticketassistant\Tests\Unit;

use GlpiPlugin\Ticketassistant\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testHtmlToTextKeepsStructure(): void
    {
        $html = '<p>Hello&nbsp;<strong>Maria</strong>,</p><ul><li>one</li><li>two</li></ul><p>Line<br>break</p>'
            . '<script>alert(1)</script><img src="x">';
        $this->assertSame("Hello Maria,\n\n- one\n- two\n\nLine\nbreak\n[image]", Text::htmlToText($html));
    }

    public function testDoubleEncodedLegacyContent(): void
    {
        $this->assertSame('Old & encoded', Text::htmlToText('&lt;p&gt;Old &amp;amp; encoded&lt;/p&gt;'));
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        $this->assertTrue(mb_check_encoding(Text::scrubUtf8("abc \xC3\x28 def"), 'UTF-8'));
    }

    public function testFulltextQueryCleaning(): void
    {
        $this->assertSame('printer does not print', Text::cleanFulltextQuery('+printer -does* "not" (print)'));
    }

    public function testTruncate(): void
    {
        $this->assertSame('abc…', Text::truncate('abcdef', 3));
        $this->assertSame('abc', Text::truncate('abc', 3));
    }
}
