<?php

namespace GlpiPlugin\Ticketassistant\Tests\Unit;

use GlpiPlugin\Ticketassistant\Privacy;
use PHPUnit\Framework\TestCase;

final class PrivacyTest extends TestCase
{
    public function testCpfIsMaskedWithAndWithoutPunctuation(): void
    {
        $text = 'CPF 123.456.789-09 e 98765432100.';
        $this->assertSame('CPF [CPF] e [CPF].', Privacy::mask($text, ['cpf']));
    }

    public function testCpfGluedToNextNumberIsMasked(): void
    {
        // Form plugins glue the next item number to the value
        $this->assertSame('cpf: [CPF]2)', Privacy::mask('cpf: 146.211.987-502)', ['cpf']));
    }

    public function testCnpjIsMaskedBeforeCpf(): void
    {
        $this->assertSame('Empresa [CNPJ].', Privacy::mask('Empresa 12.345.678/0001-95.', ['cnpj', 'cpf']));
    }

    public function testDisabledDetectorsDoNothing(): void
    {
        $text = 'CPF 123.456.789-09 mail john@example.com';
        $this->assertSame($text, Privacy::mask($text, []));
    }

    public function testEmail(): void
    {
        $this->assertSame('Write to [EMAIL] now', Privacy::mask('Write to john.doe+it@example.co.uk now', ['email']));
    }

    public function testCardNumbersNeedAValidLuhnChecksum(): void
    {
        $this->assertSame('card [CARD] ok', Privacy::mask('card 4111 1111 1111 1111 ok', ['card']));
        // Same length, invalid checksum: a protocol number, not a card
        $this->assertSame('protocol 4111 1111 1111 1112', Privacy::mask('protocol 4111 1111 1111 1112', ['card']));
    }

    public function testIbanNeedsAValidChecksum(): void
    {
        $this->assertSame('IBAN [IBAN].', Privacy::mask('IBAN GB82 WEST 1234 5698 7654 32.', ['iban']));
        $this->assertSame('IBAN GB00 WEST 1234 5698 7654 32.', Privacy::mask('IBAN GB00 WEST 1234 5698 7654 32.', ['iban']));
    }

    /**
     * @dataProvider secretsProvider
     */
    public function testSecrets(string $input, string $expected): void
    {
        $this->assertSame($expected, Privacy::mask($input, ['secrets']));
    }

    public static function secretsProvider(): array
    {
        return [
            'english colon'        => ['password: Hunter2!', 'password: [SECRET]'],
            'portuguese equals'    => ['senha=abc123', 'senha=[SECRET]'],
            'words before colon'   => ['senha provisória: Xy7#kk', 'senha provisória: [SECRET]'],
            'quoted'               => ['token: "eyJhbGciOi"', 'token: [SECRET]'],
            'api key'              => ['API key: sk-proj-123abc', 'API key: [SECRET]'],
            'no separator is kept' => ['senha resetada, testar e avisar', 'senha resetada, testar e avisar'],
            'password reset kept'  => ['password reset done', 'password reset done'],
        ];
    }

    public function testPhone(): void
    {
        $this->assertSame('Ligar [PHONE] hoje', Privacy::mask('Ligar (21) 98765-4321 hoje', ['phone']));
        $this->assertSame('Call [PHONE].', Privacy::mask('Call +1 415-555-1234.', ['phone']));
    }

    public function testCustomPatterns(): void
    {
        $custom = "# employee id\nMATRICULA=/\\bMAT-\\d{6}\\b/\ninvalid(regex";
        $this->assertSame('Id [MATRICULA].', Privacy::mask('Id MAT-123456.', [], $custom));
        $this->assertSame(['invalid(regex'], Privacy::invalidCustomPatterns($custom));
    }

    public function testDefaultsDependOnLanguage(): void
    {
        $this->assertContains('cpf', Privacy::defaultDetectors('pt_BR'));
        $this->assertNotContains('cpf', Privacy::defaultDetectors('en_GB'));
        $this->assertContains('secrets', Privacy::defaultDetectors('fr_FR'));
    }
}
