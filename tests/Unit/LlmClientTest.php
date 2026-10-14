<?php

namespace GlpiPlugin\Ticketassistant\Tests\Unit;

use GlpiPlugin\Ticketassistant\Llm\ChatRequest;
use GlpiPlugin\Ticketassistant\Llm\Client;
use GlpiPlugin\Ticketassistant\Llm\LlmException;
use GlpiPlugin\Ticketassistant\Llm\Providers;
use PHPUnit\Framework\TestCase;

final class LlmClientTest extends TestCase
{
    private function request(): ChatRequest
    {
        $request                  = new ChatRequest('System prompt mentioning JSON');
        $request->maxTokens       = 1000;
        $request->reasoningEffort = 'low';
        $request->temperature     = 0.2;
        $request->json            = true;
        $request->addText('Hello');
        $request->addImage('image/png', 'AAAA', 'shot.png');
        return $request;
    }

    public function testOpenAiReasoningPayload(): void
    {
        $client = new Client(['provider' => 'openai', 'api_key' => 'k', 'model' => 'gpt-x', 'vision' => 1]);
        $spec   = $client->getDriver()->buildChat($this->request());

        $this->assertSame('https://api.openai.com/v1/chat/completions', $spec['url']);
        $this->assertSame('Bearer k', $spec['headers']['Authorization']);
        $this->assertSame(1000, $spec['body']['max_completion_tokens']);
        $this->assertSame('low', $spec['body']['reasoning_effort']);
        $this->assertArrayNotHasKey('temperature', $spec['body']);
        $this->assertArrayNotHasKey('max_tokens', $spec['body']);
        $this->assertSame('json_object', $spec['body']['response_format']['type']);
        $this->assertSame('image_url', $spec['body']['messages'][1]['content'][1]['type']);
    }

    public function testJsonSchemaWhenAvailable(): void
    {
        $client          = new Client(['provider' => 'openai', 'api_key' => 'k', 'model' => 'gpt-x', 'json_mode' => 'json_schema']);
        $request         = $this->request();
        $request->jsonSchema = ['name' => 'x', 'schema' => ['type' => 'object']];
        $spec            = $client->getDriver()->buildChat($request);
        $this->assertSame('json_schema', $spec['body']['response_format']['type']);
        $this->assertTrue($spec['body']['response_format']['json_schema']['strict']);
    }

    public function testStandardModelOnCompatibleServer(): void
    {
        $client = new Client([
            'provider'   => 'ollama',
            'model'      => 'llama',
            'model_type' => 'standard',
            'vision'     => 0,
        ]);
        $spec = $client->getDriver()->buildChat($this->request());

        $this->assertSame('http://localhost:11434/v1/chat/completions', $spec['url']);
        $this->assertArrayNotHasKey('Authorization', $spec['headers']);
        $this->assertSame(1000, $spec['body']['max_tokens']);
        $this->assertSame(0.2, $spec['body']['temperature']);
        $this->assertArrayNotHasKey('reasoning_effort', $spec['body']);
        // No vision: plain string content, image dropped
        $this->assertSame('Hello', $spec['body']['messages'][1]['content']);
    }

    public function testAzureClassicAndV1Urls(): void
    {
        $classic = new Client([
            'provider' => 'azure', 'base_url' => 'https://res.openai.azure.com/openai/', 'api_key' => 'k',
            'model' => 'dep1', 'api_version' => '2024-10-21',
        ]);
        $spec = $classic->getDriver()->buildChat($this->request());
        $this->assertSame('https://res.openai.azure.com/openai/deployments/dep1/chat/completions?api-version=2024-10-21', $spec['url']);
        $this->assertSame('k', $spec['headers']['api-key']);
        $this->assertArrayNotHasKey('model', $spec['body']);

        $v1   = new Client(['provider' => 'azure', 'base_url' => 'https://res.openai.azure.com', 'api_key' => 'k', 'model' => 'dep1', 'api_version' => 'v1']);
        $spec = $v1->getDriver()->buildChat($this->request());
        $this->assertSame('https://res.openai.azure.com/openai/v1/chat/completions', $spec['url']);
        $this->assertSame('dep1', $spec['body']['model']);
    }

    public function testAnthropicPayloadAndParsing(): void
    {
        $client = new Client(['provider' => 'anthropic', 'api_key' => 'k', 'model' => 'claude-x', 'vision' => 1]);
        $spec   = $client->getDriver()->buildChat($this->request());

        $this->assertSame('https://api.anthropic.com/v1/messages', $spec['url']);
        $this->assertSame('k', $spec['headers']['x-api-key']);
        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 2048], $spec['body']['thinking']);
        $this->assertSame(3048, $spec['body']['max_tokens']);
        $this->assertSame('image', $spec['body']['messages'][0]['content'][1]['type']);
        $this->assertStringContainsString('JSON object only', $spec['body']['system']);

        $response = $client->getDriver()->parseChat([
            'content'     => [['type' => 'thinking', 'thinking' => '...'], ['type' => 'text', 'text' => '{"a":1}']],
            'stop_reason' => 'end_turn',
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200);
        $this->assertSame('{"a":1}', $response->content);
        $this->assertSame(10, $response->promptTokens);
    }

    public function testThinkTagsAreRemoved(): void
    {
        $client   = new Client(['provider' => 'ollama', 'model' => 'qwen3']);
        $response = $client->getDriver()->parseChat([
            'choices' => [['message' => ['content' => "<think>reasoning...</think>\n{\"ok\":true}"], 'finish_reason' => 'stop']],
        ], 200);
        $this->assertSame('{"ok":true}', $response->content);
    }

    public function testRetryOn429ThenSuccessAndCost(): void
    {
        $calls     = 0;
        $transport = function (array $spec, array $options) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return [429, '{"error":{"message":"slow down"}}', ['retry-after' => '1']];
            }
            return [200, json_encode([
                'choices' => [['message' => ['content' => '{"reply_html":"ok"}'], 'finish_reason' => 'stop']],
                'usage'   => ['prompt_tokens' => 1000000, 'completion_tokens' => 1000000],
            ]), []];
        };
        $client = new Client([
            'provider' => 'openai', 'api_key' => 'k', 'model' => 'm', 'retries' => 2,
            'price_input' => 2.5, 'price_output' => 10,
        ], $transport);

        $response = $client->chat($this->request());
        $this->assertSame(2, $calls);
        $this->assertSame('{"reply_html":"ok"}', $response->content);
        $this->assertSame(12.5, $response->costUsd);
    }

    public function testContentFilterIsNotRetried(): void
    {
        $calls     = 0;
        $transport = function () use (&$calls) {
            $calls++;
            return [400, '{"error":{"code":"content_filter","message":"filtered"}}', []];
        };
        $client = new Client(['provider' => 'azure', 'base_url' => 'https://r.openai.azure.com', 'api_key' => 'k', 'model' => 'd', 'retries' => 3], $transport);

        try {
            $client->chat($this->request());
            $this->fail('An exception was expected');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::KIND_CONTENT_FILTER, $e->getKind());
            $this->assertSame(1, $calls);
        }
    }

    public function testInsufficientQuotaIsNotRetried(): void
    {
        $calls     = 0;
        $transport = function () use (&$calls) {
            $calls++;
            return [429, '{"error":{"code":"insufficient_quota","message":"You exceeded your current quota"}}', []];
        };
        $client = new Client(['provider' => 'openai', 'api_key' => 'k', 'model' => 'm', 'retries' => 3], $transport);
        try {
            $client->chat($this->request());
            $this->fail('An exception was expected');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::KIND_QUOTA, $e->getKind());
            $this->assertSame(1, $calls);
        }
    }

    public function testMissingSettings(): void
    {
        $this->assertSame(['api_key'], Providers::missingSettings(['provider' => 'openai', 'model' => 'm']));
        $this->assertSame([], Providers::missingSettings(['provider' => 'ollama', 'model' => 'm']));
        $this->assertSame(['base_url', 'model', 'api_key'], Providers::missingSettings(['provider' => 'azure']));
    }

    public function testLocalHostsBypassTheProxy(): void
    {
        $this->assertTrue(Client::isLocalHost('localhost'));
        $this->assertTrue(Client::isLocalHost('192.168.0.10'));
        $this->assertTrue(Client::isLocalHost('ollama'));
        $this->assertTrue(Client::isLocalHost('gpu.internal'));
        $this->assertFalse(Client::isLocalHost('api.openai.com'));
        $this->assertFalse(Client::isLocalHost('8.8.8.8'));
    }

    public function testJsonEncodingSurvivesInvalidUtf8(): void
    {
        $json = Client::encodeJson(['text' => "ok \xC3\x28 end"]);
        $this->assertNotFalse(json_decode($json));
    }
}
