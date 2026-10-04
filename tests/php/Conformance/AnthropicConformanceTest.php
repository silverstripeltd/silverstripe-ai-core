<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Conformance;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\RequestInterface;
use SilverstripeLtd\AiCore\Provider\Anthropic\AnthropicProvider;
use SilverstripeLtd\AiCore\Provider\ChatProviderInterface;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;

class AnthropicConformanceTest extends ProviderConformanceTestCase
{
    protected function getFixtureDirectory(): string
    {
        return AnthropicProvider::NAME;
    }

    protected function createProvider(ClientInterface $client): ChatProviderInterface
    {
        return new AnthropicProvider($client);
    }

    protected function getExpectedDefaultModel(): string
    {
        return AnthropicProvider::DEFAULT_MODEL;
    }

    protected function systemText(array $payload): string
    {
        return implode("\n\n", array_column($payload['system'] ?? [], 'text'));
    }

    protected function conversationTexts(array $payload): array
    {
        $texts = [];

        foreach ($payload['messages'] as $message) {
            foreach ($message['content'] as $block) {
                if ($block['type'] !== 'text') {
                    continue;
                }

                $texts[] = $block['text'];
            }
        }

        return $texts;
    }

    protected function wireTools(array $payload): array
    {
        return array_map(
            static fn (array $tool): array => ['name' => $tool['name'], 'schema' => $tool['input_schema']],
            $payload['tools'] ?? [],
        );
    }

    protected function wireToolCalls(array $payload): array
    {
        $calls = [];

        foreach ($this->blocks($payload, 'assistant', 'tool_use') as $block) {
            $calls[] = ['ref' => $block['id'], 'name' => $block['name'], 'input' => $block['input']];
        }

        return $calls;
    }

    protected function wireToolResults(array $payload): array
    {
        $results = [];

        foreach ($this->blocks($payload, 'user', 'tool_result') as $block) {
            $results[] = [
                'ref' => $block['tool_use_id'],
                'content' => $block['content'],
                'is_error' => (bool) ($block['is_error'] ?? false),
            ];
        }

        return $results;
    }

    protected function wireImages(array $payload): array
    {
        $images = [];

        foreach ($payload['messages'] as $message) {
            foreach ($message['content'] as $block) {
                if ($block['type'] !== 'image') {
                    continue;
                }

                $this->assertSame('user', $message['role']);
                $this->assertSame('base64', $block['source']['type']);
                $images[] = ['media_type' => $block['source']['media_type'], 'data' => $block['source']['data']];
            }
        }

        return $images;
    }

    protected function assertConversationCacheRequested(array $payload): void
    {
        $this->assertSame(['type' => 'ephemeral'], $payload['cache_control']);
        $this->assertSame(['type' => 'ephemeral'], $payload['system'][1]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $payload['system'][2]);
    }

    protected function correlationRef(ToolUseBlock $use): string
    {
        return $use->id;
    }

    protected function sentModel(RequestInterface $request, array $payload): string
    {
        return $payload['model'];
    }

    protected function sentMaxTokens(array $payload): int
    {
        return $payload['max_tokens'];
    }

    protected function assertAuthenticated(RequestInterface $request, string $apiKey): void
    {
        $this->assertSame($apiKey, $request->getHeaderLine('x-api-key'));
        $this->assertSame(AnthropicProvider::API_VERSION, $request->getHeaderLine('anthropic-version'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function blocks(array $payload, string $role, string $type): array
    {
        $blocks = [];

        foreach ($payload['messages'] as $message) {
            if ($message['role'] !== $role) {
                continue;
            }

            foreach ($message['content'] as $block) {
                if ($block['type'] !== $type) {
                    continue;
                }

                $blocks[] = $block;
            }
        }

        return $blocks;
    }
}
