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
 * Masks personal data and secrets in text before it leaves the server.
 *
 * Masking only applies to text (ticket content, history, extracted document
 * text, drafts). Images are sent as they are: disable image sending when the
 * policy of the organization requires it.
 */
final class Privacy
{
    /**
     * Built-in detectors, applied in this order.
     *
     * @return array<string, string> id => label
     */
    public static function detectors(): array
    {
        return [
            'secrets' => __('Passwords, tokens and keys written as "password: …"', 'ticketassistant'),
            'email'   => __('E-mail addresses', 'ticketassistant'),
            'card'    => __('Payment card numbers (Luhn check)', 'ticketassistant'),
            'iban'    => __('IBAN bank account numbers (checksum)', 'ticketassistant'),
            'cnpj'    => __('CNPJ — Brazilian company registry number', 'ticketassistant'),
            'cpf'     => __('CPF — Brazilian individual taxpayer number', 'ticketassistant'),
            'phone'   => __('Phone numbers', 'ticketassistant'),
            'ipv4'    => __('IPv4 addresses', 'ticketassistant'),
        ];
    }

    /**
     * Default detectors for a new installation.
     *
     * @param string $language GLPI default language (e.g. pt_BR)
     *
     * @return string[]
     */
    public static function defaultDetectors(string $language): array
    {
        $defaults = ['secrets', 'card'];
        if (stripos($language, 'pt_BR') === 0) {
            $defaults[] = 'cnpj';
            $defaults[] = 'cpf';
        }
        return $defaults;
    }

    /**
     * @param string   $text
     * @param string[] $enabled detector ids
     * @param string   $custom  one pattern per line: "LABEL=/regex/flags" or "/regex/flags"
     */
    public static function mask(string $text, array $enabled, string $custom = ''): string
    {
        if ($text === '') {
            return $text;
        }
        $enabled = array_flip($enabled);

        if (isset($enabled['secrets'])) {
            $text = self::maskSecrets($text);
        }
        if (isset($enabled['email'])) {
            $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[EMAIL]', $text) ?? $text;
        }
        if (isset($enabled['card'])) {
            $text = preg_replace_callback(
                '/(?<![\d])(?:\d[ \-]?){12,18}\d(?![\d])/',
                static function (array $m) {
                    return self::luhn(preg_replace('/\D/', '', $m[0]) ?? '') ? '[CARD]' : $m[0];
                },
                $text
            ) ?? $text;
        }
        if (isset($enabled['iban'])) {
            $text = preg_replace_callback(
                '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,30}\b/',
                static function (array $m) {
                    return self::ibanValid($m[0]) ? '[IBAN]' : $m[0];
                },
                $text
            ) ?? $text;
        }
        if (isset($enabled['cnpj'])) {
            $text = preg_replace('/(?<!\d)\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}/', '[CNPJ]', $text) ?? $text;
        }
        if (isset($enabled['cpf'])) {
            // No right boundary on purpose: form plugins often glue the next
            // number to the CPF ("146.211.987-502)"). Trade-off: the first 11
            // digits of any longer digit sequence are masked as well.
            $text = preg_replace('/(?<!\d)\d{3}\.?\d{3}\.?\d{3}-?\d{2}/', '[CPF]', $text) ?? $text;
        }
        if (isset($enabled['phone'])) {
            $text = preg_replace(
                '/(?<![\w+])(?:'
                // +55 21 98765-4321, (21) 98765-4321, 98765-4321, +33 1 4567-8901
                . '(?:\+\d{1,3}[\s.\-]?)?(?:\(\d{2,4}\)[\s.\-]?|\d{1,4}[\s.\-])?\d{4,5}[\s.\-]\d{4}'
                // +1 415-555-1234, (415) 555-1234
                . '|(?:\+1[\s.\-]?)?\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}'
                . ')(?![\w])/u',
                '[PHONE]',
                $text
            ) ?? $text;
        }
        if (isset($enabled['ipv4'])) {
            $text = preg_replace(
                '/\b(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\b/',
                '[IP]',
                $text
            ) ?? $text;
        }

        foreach (self::parseCustomPatterns($custom) as [$label, $regex]) {
            $masked = @preg_replace($regex, '[' . $label . ']', $text);
            if (is_string($masked)) {
                $text = $masked;
            }
        }

        return $text;
    }

    /**
     * "password: abc", "senha provisória = X1y2", "API key: sk-…", "token=…".
     * A ":" or "=" right after the keyword (up to two words in between) is
     * required, so that "password reset" or "senha resetada" stay readable.
     */
    private static function maskSecrets(string $text): string
    {
        $keywords = 'passwords?|passwd|pwd|senhas?|contrase(?:ñ|n)as?|mot\s+de\s+passe|mdp|'
            . 'tokens?|api[\s_\-]?keys?|secret|segredo|client[\s_\-]?secret|pin|otp|'
            . 'chave(?:\s+de\s+acesso)?|access[\s_\-]?key';

        $pattern = '/\b(' . $keywords . ')((?:\s+[\p{L}\p{M}]+){0,2}\s*[:=]\s*)(["\']?)([^\s"\'<>,;]{2,})\3/iu';

        return preg_replace($pattern, '$1$2[SECRET]', $text) ?? $text;
    }

    /**
     * @return array<int, array{0: string, 1: string}> [label, regex]
     */
    public static function parseCustomPatterns(string $custom): array
    {
        $patterns = [];
        foreach (preg_split('/\R/', $custom) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $label = 'REDACTED';
            if (preg_match('/^([A-Z0-9_]{2,30})=(.+)$/', $line, $m)) {
                $label = $m[1];
                $line  = trim($m[2]);
            }
            if (@preg_match($line, '') === false) {
                continue; // invalid regular expression: ignored (validated on save)
            }
            $patterns[] = [$label, $line];
        }
        return $patterns;
    }

    /**
     * Invalid lines of the custom patterns field (for the configuration form).
     *
     * @return string[]
     */
    public static function invalidCustomPatterns(string $custom): array
    {
        $invalid = [];
        foreach (preg_split('/\R/', $custom) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $regex = preg_match('/^([A-Z0-9_]{2,30})=(.+)$/', $line, $m) ? trim($m[2]) : $line;
            if (@preg_match($regex, '') === false) {
                $invalid[] = $line;
            }
        }
        return $invalid;
    }

    public static function luhn(string $digits): bool
    {
        $length = strlen($digits);
        if ($length < 13 || $length > 19 || preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }
        $sum    = 0;
        $double = false;
        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum   += $digit;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }

    public static function ibanValid(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric    = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (((string) $remainder) . $chunk) % 97;
        }
        return $remainder === 1;
    }
}
