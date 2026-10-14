<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Llm;

use GlpiPlugin\Ticketassistant\Llm\Driver\AnthropicDriver;
use GlpiPlugin\Ticketassistant\Llm\Driver\AzureDriver;
use GlpiPlugin\Ticketassistant\Llm\Driver\DriverInterface;
use GlpiPlugin\Ticketassistant\Llm\Driver\OpenAiDriver;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;

/**
 * Transport for every provider: HTTP (Guzzle, shipped with GLPI 10 and 11),
 * retries with exponential backoff or Retry-After, timing, UTF-8 safety and
 * cost estimation. The wire format is delegated to a driver.
 *
 * Contract kept from the production-proven version:
 *  - network errors, timeouts, HTTP 5xx and 429 are retried (2 s, 4 s, 8 s…,
 *    or the delay suggested by the provider, capped);
 *  - content filter blocks and other 4xx are never retried;
 *  - reasoning models get the output budget in the token parameter and a
 *    reasoning effort, never a temperature.
 */
final class Client
{
    /** Maximum wait between two attempts, whatever the provider asks */
    private const MAX_RETRY_WAIT = 20;

    /** @var array<string, mixed> */
    private $settings;

    /** @var DriverInterface */
    private $driver;

    /** @var HttpClient|null */
    private $http;

    /** @var callable|null test hook: fn(array $spec, array $options): array{0:int,1:string,2:array} */
    private $transport;

    public function __construct(array $settings, ?callable $transport = null)
    {
        $this->settings  = Providers::resolve($settings);
        $this->driver    = self::makeDriver($this->settings);
        $this->transport = $transport;
    }

    public static function makeDriver(array $resolved_settings): DriverInterface
    {
        switch ($resolved_settings['driver']) {
            case 'azure':
                return new AzureDriver($resolved_settings);
            case 'anthropic':
                return new AnthropicDriver($resolved_settings);
            default:
                return new OpenAiDriver($resolved_settings);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    public function supportsVision(): bool
    {
        return !empty($this->settings['vision']);
    }

    /**
     * The provider reads PDF files sent as such (and the option is on).
     */
    public function supportsNativePdf(): bool
    {
        return !empty($this->settings['native_pdf']);
    }

    public function supportsEmbeddings(): bool
    {
        return $this->driver->supportsEmbeddings();
    }

    /**
     * @throws LlmException
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        $this->assertConfigured();

        $start = microtime(true);
        [$http_code, $data] = $this->send(
            $this->driver->buildChat($request),
            $request->timeout !== null ? (int) $request->timeout : (int) $this->settings['timeout']
        );

        $response             = $this->driver->parseChat($data, $http_code);
        $response->durationMs = (int) round((microtime(true) - $start) * 1000);
        $response->costUsd    = $this->estimateCost($response->promptTokens, $response->completionTokens);
        return $response;
    }

    /**
     * Embeddings for a list of texts, in the same order.
     *
     * @param string[] $texts
     *
     * @return array{vectors: array<int, float[]>, tokens: int, cost_usd: float}
     *
     * @throws LlmException
     */
    public function embed(array $texts, int $dimensions = 0): array
    {
        if (!$this->driver->supportsEmbeddings()) {
            throw new LlmException(LlmException::KIND_UNSUPPORTED, 'This provider has no embeddings API');
        }
        if (trim((string) $this->settings['embedding_model']) === '') {
            throw new LlmException(LlmException::KIND_CONFIG, 'No embeddings model configured');
        }
        $this->assertConfigured(false);

        [, $data] = $this->send(
            $this->driver->buildEmbeddings(array_values($texts), $dimensions),
            (int) $this->settings['timeout']
        );
        $result = $this->driver->parseEmbeddings($data);
        if (count($result['vectors']) !== count($texts)) {
            throw new LlmException(LlmException::KIND_BAD_RESPONSE, 'Embeddings answer with a wrong number of vectors');
        }
        $result['cost_usd'] = $this->estimateCost((int) $result['tokens'], 0);
        return $result;
    }

    /**
     * Model identifiers offered by the provider (empty when unsupported).
     *
     * @return string[]
     *
     * @throws LlmException
     */
    public function listModels(): array
    {
        $spec = $this->driver->buildListModels();
        if ($spec === null) {
            return [];
        }
        [, $data] = $this->send($spec, min(30, (int) $this->settings['timeout']), 0);
        return $this->driver->parseListModels($data);
    }

    public function estimateCost(int $prompt_tokens, int $completion_tokens): float
    {
        return round(
            ($prompt_tokens / 1e6) * (float) $this->settings['price_input']
            + ($completion_tokens / 1e6) * (float) $this->settings['price_output'],
            6
        );
    }

    /**
     * @throws LlmException
     */
    private function assertConfigured(bool $model_required = true): void
    {
        $missing = Providers::missingSettings($this->settings);
        if (!$model_required) {
            $missing = array_diff($missing, ['model']);
        }
        if (count($missing)) {
            throw new LlmException(
                LlmException::KIND_CONFIG,
                'Incomplete AI connection settings: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Sends a request with retries.
     *
     * @return array{0: int, 1: array}
     *
     * @throws LlmException
     */
    private function send(array $spec, int $timeout, ?int $retries = null): array
    {
        $retries = $retries ?? (int) $this->settings['retries'];
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $this->sendOnce($spec, $timeout);
            } catch (LlmException $e) {
                if (!$e->isRetryable() || $attempt > $retries) {
                    throw $e;
                }
                $wait = $e->getRetryAfter() ?? (2 ** ($attempt - 1)) * 2;
                sleep(max(1, min(self::MAX_RETRY_WAIT, $wait)));
            }
        }
    }

    /**
     * @return array{0: int, 1: array}
     *
     * @throws LlmException
     */
    private function sendOnce(array $spec, int $timeout): array
    {
        $headers = $spec['headers'];
        $headers['User-Agent'] = 'GLPI-AI-Ticket-Assistant/' . (defined('PLUGIN_TICKETASSISTANT_VERSION') ? PLUGIN_TICKETASSISTANT_VERSION : 'dev');

        $guard = $this->settings['url_guard'] ?? null;
        if (is_callable($guard) && !$guard($spec['url'])) {
            throw new LlmException(
                LlmException::KIND_CONFIG,
                'Outbound URL refused (scheme, cloud metadata address or TICKETASSISTANT_URL_ALLOWLIST): ' . self::safeMessage($spec['url'])
            );
        }

        $options = [
            'timeout'         => $timeout,
            'connect_timeout' => min(15, $timeout),
            'http_errors'     => false,
            // A provider URL must never bounce the server to another host
            'allow_redirects' => false,
            'headers'         => $headers,
        ];
        if ($spec['body'] !== null) {
            $options['body'] = self::encodeJson($spec['body']);
        }
        $proxy = $this->proxyFor($spec['url']);
        if ($proxy !== null) {
            $options['proxy'] = $proxy;
        }

        if ($this->transport !== null) {
            [$http_code, $body, $response_headers] = ($this->transport)($spec, $options);
        } else {
            try {
                $response = $this->http()->request($spec['method'], $spec['url'], $options);
            } catch (ConnectException $e) {
                throw new LlmException(
                    self::isTimeout($e) ? LlmException::KIND_TIMEOUT : LlmException::KIND_NETWORK,
                    self::safeMessage($e->getMessage())
                );
            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                } else {
                    throw new LlmException(
                        self::isTimeout($e) ? LlmException::KIND_TIMEOUT : LlmException::KIND_NETWORK,
                        self::safeMessage($e->getMessage())
                    );
                }
            } catch (TransferException $e) {
                throw new LlmException(LlmException::KIND_NETWORK, self::safeMessage($e->getMessage()));
            }

            $http_code        = $response->getStatusCode();
            $body             = (string) $response->getBody();
            $response_headers = [];
            foreach ($response->getHeaders() as $name => $values) {
                $response_headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
            }
        }

        $data = json_decode((string) $body, true);

        if ($http_code >= 400) {
            throw $this->driver->classifyError($http_code, (string) $body, is_array($data) ? $data : null, $response_headers);
        }
        if (!is_array($data)) {
            throw new LlmException(
                LlmException::KIND_BAD_RESPONSE,
                'Answer is not JSON: ' . mb_substr(trim(strip_tags((string) $body)), 0, 200),
                $http_code
            );
        }

        return [$http_code, $data];
    }

    private function http(): HttpClient
    {
        if ($this->http === null) {
            $this->http = new HttpClient();
        }
        return $this->http;
    }

    /**
     * JSON encoding that never fails on invalid UTF-8 (legacy ticket content
     * pasted from word processors can contain broken byte sequences).
     */
    public static function encodeJson(array $body): string
    {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            throw new LlmException(LlmException::KIND_CONFIG, 'Could not encode the request: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * The GLPI proxy is used for remote providers only: local and private
     * network endpoints (Ollama, LM Studio, vLLM…) are reached directly.
     */
    private function proxyFor(string $url): ?string
    {
        $proxy = $this->settings['proxy'];
        if (!is_string($proxy) || $proxy === '') {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return self::isLocalHost($host) ? null : $proxy;
    }

    public static function isLocalHost(string $host): bool
    {
        $host = trim($host, '[]');
        if ($host === '' || $host === 'localhost' || $host === '::1' || strpos($host, '.') === false) {
            return true;
        }
        foreach (['.local', '.localhost', '.internal', '.lan', '.home.arpa'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        return false;
    }

    private static function isTimeout(\Throwable $e): bool
    {
        if (method_exists($e, 'getHandlerContext')) {
            $context = $e->getHandlerContext();
            if (isset($context['errno']) && (int) $context['errno'] === 28) {
                return true;
            }
        }
        return stripos($e->getMessage(), 'timed out') !== false
            || stripos($e->getMessage(), 'timeout') !== false;
    }

    /**
     * Transport messages may echo the URL; Azure and OpenAI keys travel in
     * headers, never in URLs, but strip query strings anyway.
     */
    private static function safeMessage(string $message): string
    {
        $message = preg_replace('/\?[^\s"\']*/', '?…', $message) ?? $message;
        return mb_substr($message, 0, 250);
    }
}
