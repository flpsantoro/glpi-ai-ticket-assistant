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
 * Vector helpers. Vectors are stored L2-normalized as little-endian float32
 * (4 bytes per dimension), base64 encoded in a TEXT column: cosine similarity
 * is a plain dot product, any MySQL/MariaDB version can hold them, and no
 * GLPI layer (charset conversion, legacy data decoding) can alter them.
 */
final class Embeddings
{
    /**
     * @param float[] $vector
     *
     * @return float[]
     */
    public static function normalize(array $vector): array
    {
        $sum = 0.0;
        foreach ($vector as $value) {
            $sum += $value * $value;
        }
        if ($sum <= 0.0) {
            return $vector;
        }
        $norm = sqrt($sum);
        foreach ($vector as $i => $value) {
            $vector[$i] = $value / $norm;
        }
        return $vector;
    }

    /**
     * @param float[] $vector
     */
    public static function pack(array $vector): string
    {
        return base64_encode(pack('g*', ...array_values(self::normalize($vector))));
    }

    /**
     * @return float[]
     */
    public static function unpack(string $encoded): array
    {
        $blob = $encoded === '' ? '' : (string) base64_decode($encoded, true);
        if ($blob === '' || strlen($blob) % 4 !== 0) {
            return [];
        }
        $values = unpack('g*', $blob);
        return $values === false ? [] : array_values($values);
    }

    /**
     * Dot product (= cosine similarity for normalized vectors). Vectors of
     * different sizes (model changed) are not comparable: 0.
     *
     * @param float[] $a
     * @param float[] $b
     */
    public static function dot(array $a, array $b): float
    {
        $count = count($a);
        if ($count === 0 || $count !== count($b)) {
            return 0.0;
        }
        $sum = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $sum += $a[$i] * $b[$i];
        }
        return $sum;
    }
}
