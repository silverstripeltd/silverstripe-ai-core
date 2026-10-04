<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Conformance;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\RequestInterface;
use SilverstripeLtd\AiCore\Provider\ChatProviderInterface;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;

/**
 * The scripted fake has no wire format: its fixtures are ChatResponse arrays and its "payload"
 * is the ChatRequest it recorded. Passing the same scenarios keeps tests written against it
 * honest about what a real provider would return.
 */
class ScriptedConformanceTest extends ProviderConformanceTestCase
{
    private ?ScriptedProvider $provider = null;

    protected function getFixtureDirectory(): string
    {
        return ScriptedProvider::NAME;
    }

    protected function createProvider(ClientInterface $client): ChatProviderInterface
    {
        return new ScriptedProvider();
    }

    protected function getExpectedDefaultModel(): string
    {
        return ScriptedProvider::MODEL;
    }

    protected function usesHttp(): bool
    {
        return false;
    }

    protected function assertConversationCacheRequested(array $payload): void
    {
        $this->assertTrue($payload['options']['cache_conversation'], 'the request records the option');
    }

    protected function wireName(string $name): string
    {
        return $name;
    }

    protected function chatWithFixtures(ChatRequest $request, string ...$fixtures): ChatResponse
    {
        $this->provider = new ScriptedProvider(array_map(
            fn (string $name): ChatResponse => ChatResponse::fromArray(
                json_decode($this->fixtureBody($name), true, 512, JSON_THROW_ON_ERROR),
            ),
            $fixtures,
        ));

        return $this->provider->chat($request);
    }

    protected function sentPayload(): array
    {
        $request = $this->provider?->getLastRequest();
        $this->assertNotNull($request, 'No request was recorded');

        return $request->toArray();
    }

    protected function systemText(array $payload): string
    {
        return implode("\n\n", [...$payload['options']['cacheable_system_prefix'], $payload['system']]);
    }

    protected function conversationTexts(array $payload): array
    {
        $texts = [];

        foreach ($payload['messages'] as $message) {
            if ($message['role'] === 'system') {
                continue;
            }

            foreach ($message['blocks'] as $block) {
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
            static fn (array $tool): array => [
                'name' => $tool['name'],
                'schema' => $tool['input_schema'] === [] ? ['type' => 'object'] : $tool['input_schema'],
            ],
            $payload['tools'],
        );
    }

    protected function wireToolCalls(array $payload): array
    {
        return array_map(
            static fn (array $block): array => ['ref' => $block['id'], 'name' => $block['name'], 'input' => $block['input']],
            $this->blocks($payload, 'assistant', 'tool_use'),
        );
    }

    protected function wireToolResults(array $payload): array
    {
        return array_map(
            static fn (array $block): array => [
                'ref' => $block['tool_use_id'],
                'content' => $block['content'],
                'is_error' => $block['is_error'],
            ],
            $this->blocks($payload, 'tool', 'tool_result'),
        );
    }

    protected function wireImages(array $payload): array
    {
        $images = [];

        foreach ($payload['messages'] as $message) {
            foreach ($message['blocks'] as $block) {
                if ($block['type'] !== 'image') {
                    continue;
                }

                $images[] = ['media_type' => $block['media_type'], 'data' => $block['data']];
            }
        }

        return $images;
    }

    protected function correlationRef(ToolUseBlock $use): string
    {
        return $use->id;
    }

    protected function sentModel(RequestInterface $request, array $payload): string
    {
        return $payload['options']['model'];
    }

    protected function sentMaxTokens(array $payload): int
    {
        return $payload['options']['max_tokens'];
    }

    protected function assertAuthenticated(RequestInterface $request, string $apiKey): void
    {
        $this->fail('The scripted provider sends no requests.');
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

            foreach ($message['blocks'] as $block) {
                if ($block['type'] !== $type) {
                    continue;
                }

                $blocks[] = $block;
            }
        }

        return $blocks;
    }
}
