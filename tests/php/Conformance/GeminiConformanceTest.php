<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Conformance;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\RequestInterface;
use SilverstripeLtd\AiCore\Provider\ChatProviderInterface;
use SilverstripeLtd\AiCore\Provider\Gemini\GeminiProvider;
use SilverstripeLtd\AiCore\Provider\Gemini\ToolCallIds;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\ToolNameCodec;

class GeminiConformanceTest extends ProviderConformanceTestCase
{
    protected function getFixtureDirectory(): string
    {
        return GeminiProvider::NAME;
    }

    protected function createProvider(ClientInterface $client): ChatProviderInterface
    {
        return new GeminiProvider($client);
    }

    protected function getExpectedDefaultModel(): string
    {
        return GeminiProvider::DEFAULT_MODEL;
    }

    /**
     * Gemini reports cache reads (cachedContentTokenCount) but not cache writes.
     */
    protected function reportsCacheWrites(): bool
    {
        return false;
    }

    protected function systemText(array $payload): string
    {
        return implode("\n\n", array_column($payload['systemInstruction']['parts'] ?? [], 'text'));
    }

    protected function conversationTexts(array $payload): array
    {
        $texts = [];

        foreach ($payload['contents'] as $content) {
            foreach ($content['parts'] as $part) {
                if (!isset($part['text'])) {
                    continue;
                }

                $texts[] = $part['text'];
            }
        }

        return $texts;
    }

    protected function wireTools(array $payload): array
    {
        return array_map(
            static fn (array $declaration): array => [
                'name' => $declaration['name'],
                'schema' => $declaration['parametersJsonSchema'],
            ],
            $payload['tools'][0]['functionDeclarations'] ?? [],
        );
    }

    protected function wireToolCalls(array $payload): array
    {
        return array_map(
            static fn (array $call): array => [
                'ref' => $call['id'] ?? $call['name'],
                'name' => $call['name'],
                'input' => $call['args'],
            ],
            $this->parts($payload, 'model', 'functionCall'),
        );
    }

    protected function wireToolResults(array $payload): array
    {
        return array_map(
            static fn (array $response): array => [
                'ref' => $response['id'] ?? $response['name'],
                'content' => $response['response']['output'] ?? $response['response']['error'],
                'is_error' => array_key_exists('error', $response['response']),
            ],
            $this->parts($payload, 'user', 'functionResponse'),
        );
    }

    /**
     * Gemini pairs by id when the model supplied one, otherwise by name and order.
     */
    protected function correlationRef(ToolUseBlock $use): string
    {
        return ToolCallIds::isGenerated($use->id)
            ? ToolNameCodec::toWire($use->name)
            : $use->id;
    }

    protected function sentModel(RequestInterface $request, array $payload): string
    {
        $this->assertArrayNotHasKey('model', $payload);
        $this->assertSame(1, preg_match('#/models/([^/:]+):generateContent$#', $request->getUri()->getPath(), $match));

        return rawurldecode($match[1]);
    }

    protected function sentMaxTokens(array $payload): int
    {
        return $payload['generationConfig']['maxOutputTokens'];
    }

    protected function assertAuthenticated(RequestInterface $request, string $apiKey): void
    {
        $this->assertSame($apiKey, $request->getHeaderLine('x-goog-api-key'));
        $this->assertStringNotContainsString('key=', $request->getUri()->getQuery());
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function parts(array $payload, string $role, string $key): array
    {
        $found = [];

        foreach ($payload['contents'] as $content) {
            if ($content['role'] !== $role) {
                continue;
            }

            foreach ($content['parts'] as $part) {
                if (!isset($part[$key])) {
                    continue;
                }

                $found[] = $part[$key];
            }
        }

        return $found;
    }
}
