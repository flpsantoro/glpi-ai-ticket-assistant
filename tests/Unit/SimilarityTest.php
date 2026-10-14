<?php

namespace GlpiPlugin\Ticketassistant\Tests\Unit;

use GlpiPlugin\Ticketassistant\Knowledge\Similarity;
use PHPUnit\Framework\TestCase;

final class SimilarityTest extends TestCase
{
    public function testTermsLeaveCommonWordsAside(): void
    {
        $this->assertSame(['printer', 'print'], Similarity::terms('The printer does not print anymore, please'));
        $this->assertSame(['impressora', 'imprime'], Similarity::terms('A impressora não imprime mais, por favor'));
        $this->assertSame(['imprimante', 'imprime'], Similarity::terms("L'imprimante n'imprime plus depuis hier"));
    }

    public function testTermsNormalizeAccentsHyphensAndPlurals(): void
    {
        $this->assertSame(['wifi', 'wifi', 'email'], Similarity::terms('Wi-Fi, wifi and e-mail'));
        $this->assertSame(['configuracao', 'acesso'], Similarity::terms('Configuração do acesso'));
        $this->assertSame(['page', 'page', 'access', 'statu'], Similarity::terms('pages page access status'));
    }

    public function testTermsKeepErrorCodesAndDropShortNumbersAndPlaceholders(): void
    {
        $this->assertSame(['error', '809', 'minute'], Similarity::terms('Error 809 after 5 minutes'));
        $this->assertSame(['contact'], Similarity::terms('Contact [EMAIL] or [CPF]'));
    }

    public function testTermsOfOtherScripts(): void
    {
        // No stopwords for these languages, but their words are compared
        $this->assertSame(['принтер', 'не', 'печатает'], Similarity::terms('Принтер не печатает'));
    }

    public function testCosine(): void
    {
        $a = Similarity::vector('VPN error 809 from the hotel');
        $this->assertEqualsWithDelta(1.0, Similarity::cosine($a, Similarity::vector('vpn ERROR 809, hotel')), 1e-9);
        $this->assertSame(0.0, Similarity::cosine($a, Similarity::vector('Printer out of paper')));
        $this->assertSame(0.0, Similarity::cosine($a, Similarity::vector('the and of')));

        $b = Similarity::vector('Error 809 when connecting to the VPN');
        $c = Similarity::vector('VPN disconnects every 5 minutes at home');
        $this->assertGreaterThan(Similarity::cosine($a, $c), Similarity::cosine($a, $b));
        $this->assertEqualsWithDelta(Similarity::cosine($a, $b), Similarity::cosine($b, $a), 1e-12);
    }

    public function testCaseVectorIsCachedByContent(): void
    {
        $first = Similarity::caseVector(1, 'Printer jam');
        $this->assertSame($first, Similarity::caseVector(1, 'Printer jam'));
        $this->assertNotSame($first, Similarity::caseVector(1, 'Laptop battery'));
    }

    public function testFulltextQueryWithoutStopwords(): void
    {
        $this->assertSame('VPN error morning', Similarity::withoutStopwords('The VPN error since this morning'));
        $this->assertSame('', Similarity::withoutStopwords('it is not what you were'));
    }
}
