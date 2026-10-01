<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Conformance;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\RequestInterface;
use SilverstripeLtd\AiCore\Provider\ChatProviderInterface;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\OpenAI\OpenAIProvider;

class OpenAIConformanceTest extends ProviderConformanceTestCase
{
    protected function getFixtureDirectory(): string
    {
        return OpenAIProvider::NAME;
    }

    protected function createProvider(ClientInterface $client): ChatProviderInterface
    {
        return new OpenAIProvider($client);
    }

    protected function getExpectedDefaultModel(): string
    {
        return OpenAIProvider::DEFAULT_MODEL;
    }

    protected function systemText(array $payload): string
    {
        return implode("\n\n", array_column($this->messages($payload, 'developer'), 'content'));
    }

    protected function conversationTexts(array $payload): array
    {
        $texts = [];

        foreach ($payload['messages'] as $message) {
            if (!in_array($message['role'], ['user', 'assistant'], true) || !is_string($message['content'])) {
                continue;
            }

            $texts[] = $message['content'];
        }

        return $texts;
    }

    protected function wireTools(array $payload): array
    {
        return array_map(
            static fn (array $tool): array => [
                'name' => $tool['function']['name'],
                'schema' => $tool['function']['parameters'],
            ],
            $payload['tools'] ?? [],
        );
    }

    protected function wireToolCalls(array $payload): array
    {
        $calls = [];

        foreach ($this->messages($payload, 'assistant') as $message) {
            foreach ($message['tool_calls'] ?? [] as $call) {
                $calls[] = [
                    'ref' => $call['id'],
                    'name' => $call['function']['name'],
                    'input' => json_decode($call['function']['arguments'], true, 512, JSON_THROW_ON_ERROR),
                ];
            }
        }

        return $calls;
    }

    /**
     * Chat Completions has no error flag on tool messages; the content says so instead.
     */
    protected function wireToolResults(array $payload): array
    {
        return array_map(
            static fn (array $message): array => [
                'ref' => $message['tool_call_id'],
                'content' => $message['content'],
                'is_error' => null,
            ],
            $this->messages($payload, 'tool'),
        );
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
        $this->assertArrayNotHasKey('max_tokens', $payload, 'max_tokens is deprecated');

        return $payload['max_completion_tokens'];
    }

    protected function assertAuthenticated(RequestInterface $request, string $apiKey): void
    {
        $this->assertSame('Bearer ' . $apiKey, $request->getHeaderLine('Authorization'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function messages(array $payload, string $role): array
    {
        return array_values(array_filter(
            $payload['messages'],
            static fn (array $message): bool => $message['role'] === $role,
        ));
    }
}
