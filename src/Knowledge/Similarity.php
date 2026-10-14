<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Knowledge;

/**
 * Similarity of the words of two texts, for the full-text search of the case
 * library: the MySQL full-text index finds the candidates, this cosine ranks
 * them.
 *
 * The relevance of MySQL ("MATCH ... AGAINST") is unbounded and, on a small
 * library, dominated by common words ("does", "every", "cannot") that its
 * short stopword list keeps. The cosine of the meaningful words is in [0, 1],
 * comparable from one ticket to the next, so it can weigh the votes of the
 * classification and drop weak references.
 *
 * Terms: lowercase, accents removed, hyphenated words joined ("Wi-Fi" =
 * "wifi"), stopwords of English, Portuguese, Spanish and French removed, a
 * final "s" removed from words of 5 letters or more (plurals), numbers of 1
 * or 2 digits ignored (error codes such as 809 are kept), masking
 * placeholders ([EMAIL], [CPF]…) ignored. Weights: 1 + log(occurrences).
 */
final class Similarity
{
    private const STOPWORDS = 'about above after again against all also am an and any are as at be because been before '
        . 'being below between both but by can cannot could did do does doing done don doesn didn during each either else '
        . 'even ever every few for from further had has have having he her here hers herself him himself his how if in into '
        . 'is isn it its itself just let me more most my myself no nor not now of on once only or other our ours ourselves '
        . 'out over own same she should so some such than that the their theirs them themselves then there these they this '
        . 'those through to too under until up upon us very was wasn we were weren what when where which while who whom '
        . 'whose why will with within without won would wouldn yet you your yours yourself yourselves already always '
        . 'anymore never still since please thanks thank hello hi regards dear kind ok okay yes ll ve re '
        // Portuguese
        . 'os um uma uns umas de do da dos das em na nos nas ao aos por pelo pela pelos pelas para pra com sem sob sobre '
        . 'entre ate apos desde que se ou mas nem como quando onde qual quais quem porque pois porem entao tambem ja ainda '
        . 'mais menos muito muita muitos muitas pouco pouca todo toda todos todas tudo outro outra outros outras mesmo mesma '
        . 'este esta estes estas esse essa esses essas aquele aquela isto isso aquilo eu tu ele ela eles elas voce voces te '
        . 'lhe lhes meu minha meus minhas seu sua seus suas nosso nossa ser sou sao era foi fui sera seria estar estou estao '
        . 'estava estamos ter tem tenho tinha temos ha havia fazer faz fez feito pode podem posso consigo nao sim aqui ali la '
        . 'agora hoje ontem depois antes sempre nunca bom boa dia tarde noite ola oi obrigado obrigada grato grata favor '
        . 'prezado prezada prezados att atenciosamente cordialmente '
        // Spanish
        . 'el los las un unos unas del al en con sin pero hasta cuando donde cual cuales quien quienes pues tambien ya '
        . 'todavia aun muy mucho mucha muchos muchas otro otra otros otras mismo misma estos ese esa esos esas esto eso '
        . 'aquel yo ella ellos ellas nosotros usted ustedes le les mi mis su sus nuestro nuestra soy es son fue estoy '
        . 'estan estaba tener tiene tengo tenia hay hacer hace hizo puede pueden puedo si alli ahora hoy ayer despues '
        . 'siempre hola buenos buenas dias gracias saludos estimado estimada atentamente '
        // French
        . 'le une des du au aux dans par pour avec sans sur sous chez depuis avant apres qui quoi dont et mais donc car '
        . 'ni comme comment oui non nous tout tous toute toutes moins '
        . 'pourquoi ce cet cette ces ceci cela ca je il elle vous ils elles on lui leur leurs mon ma mes ton ta tes son sa ses '
        . 'notre votre vos etre suis est sont etait ete avoir ai avons avez ont avait fait peut peux pouvez ne pas plus tres '
        . 'bien aussi encore deja toujours jamais ici maintenant aujourd hui hier bonjour bonsoir merci salutations svp '
        . 'madame monsieur';

    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e',
        'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o',
        'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
    ];

    /** @var array<string, true>|null */
    private static $stopwords = null;

    /** @var array<string, array<string, float>> vectors of the cases, by id and content */
    private static $cache = [];

    /**
     * Meaningful terms of a text, in order.
     *
     * @return string[]
     */
    public static function terms(string $text): array
    {
        $text = preg_replace('/\[[A-Z][A-Z0-9_]*\]/', ' ', $text) ?? $text;
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
        $text = preg_replace('/(?<=[\p{L}\p{N}])-(?=[\p{L}\p{N}])/u', '', $text) ?? $text;
        $stopwords = self::stopwords();
        $terms     = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $length = mb_strlen($word, 'UTF-8');
            if ($length < 2 || isset($stopwords[$word]) || ($length < 3 && preg_match('/^[0-9]+$/', $word))) {
                continue;
            }
            if ($length > 4 && substr($word, -1) === 's' && substr($word, -2) !== 'ss') {
                $word = substr($word, 0, -1);
            }
            $terms[] = $word;
        }
        return $terms;
    }

    /**
     * Words of a text without the stopwords, as written: the query of the
     * full-text index (which neither removes accents nor plurals).
     */
    public static function withoutStopwords(string $text): string
    {
        $stopwords = self::stopwords();
        $kept      = [];
        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $key = strtr(mb_strtolower(trim($word, ".,;:!?()[]{}\"'"), 'UTF-8'), self::ACCENTS);
            if (!isset($stopwords[$key])) {
                $kept[] = $word;
            }
        }
        return implode(' ', $kept);
    }

    /**
     * Unit-length vector of the terms of a text.
     *
     * @return array<string, float>
     */
    public static function vector(string $text): array
    {
        $counts = array_count_values(self::terms($text));
        $vector = [];
        $norm   = 0.0;
        foreach ($counts as $term => $count) {
            $weight                 = 1.0 + log($count);
            $vector[(string) $term] = $weight;
            $norm                  += $weight * $weight;
        }
        if ($norm <= 0.0) {
            return [];
        }
        $norm = sqrt($norm);
        foreach ($vector as $term => $weight) {
            $vector[$term] = $weight / $norm;
        }
        return $vector;
    }

    /**
     * Vector of a case, kept for the rest of the request (the evaluation
     * compares every case with many others).
     *
     * @return array<string, float>
     */
    public static function caseVector(int $id, string $text): array
    {
        $key = $id . ':' . crc32($text);
        if (!isset(self::$cache[$key])) {
            if (count(self::$cache) >= 20000) {
                self::$cache = [];
            }
            self::$cache[$key] = self::vector($text);
        }
        return self::$cache[$key];
    }

    /**
     * Cosine of two unit-length vectors, in [0, 1].
     *
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    public static function cosine(array $a, array $b): float
    {
        if (count($a) > count($b)) {
            [$a, $b] = [$b, $a];
        }
        $sum = 0.0;
        foreach ($a as $term => $weight) {
            if (isset($b[$term])) {
                $sum += $weight * $b[$term];
            }
        }
        return max(0.0, min(1.0, $sum));
    }

    /**
     * @return array<string, true>
     */
    private static function stopwords(): array
    {
        if (self::$stopwords === null) {
            self::$stopwords = array_fill_keys(explode(' ', self::STOPWORDS), true);
        }
        return self::$stopwords;
    }
}
