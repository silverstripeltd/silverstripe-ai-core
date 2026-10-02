<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Provider\Gemini;

use GuzzleHttp\ClientInterface;
use SilverstripeLtd\AiCore\Provider\ChatProviderInterface;
use SilverstripeLtd\AiCore\Provider\Gemini\GeminiProvider;
use SilverstripeLtd\AiCore\Provider\Gemini\ToolCallIds;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\StopReason;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolResultBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Tests\Provider\HttpProviderTestCase;

class GeminiProviderTest extends HttpProviderTestCase
{
    protected function createProvider(ClientInterface $client): ChatProviderInterface
    {
        return new GeminiProvider($client);
    }

    protected function getFixtureDirectory(): string
    {
        return GeminiProvider::NAME;
    }

    public function testModelIsInThePathAndTheKeyOnlyInAHeader(): void
    {
        $provider = $this->provider([$this->fixture('text_reply'), $this->fixture('text_reply')]);

        $provider->chat(self::request([], [], new ChatOptions('gemini-3.8-flash', 1, 1)));
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent',
            (string) $this->sentRequest()->getUri(),
        );
        $this->assertSame(self::SECRET, $this->sentRequest()->getHeaderLine('x-goog-api-key'));

        $provider->chat(self::request([], [], new ChatOptions('models/gemini-3.5-flash-lite', 1, 1)));
        $this->assertStringEndsWith(
            '/models/gemini-3.5-flash-lite:generateContent',
            (string) $this->sentRequest()->getUri(),
        );
        $this->assertSame('', $this->sentRequest()->getUri()->getQuery());
    }

    public function testThoughtSignaturesAreKeptAndReplayedOnTheirOwnParts(): void
    {
        $provider = $this->provider([$this->fixture('parallel_tool_calls'), $this->fixture('text_reply')]);
        $turn = $provider->chat(self::request());
        [$search, $describe] = $turn->getToolUses();

        $this->assertSame('fc_7hw2lp4x', $search->id);
        $this->assertArrayHasKey(ToolCallIds::KEY_THOUGHT_SIGNATURE, $search->providerData);
        $this->assertSame([], $describe->providerData, 'only the first parallel call is signed');

        $provider->chat(self::request([
            ChatMessage::fromText(Role::User, 'Go'),
            $turn->message,
            ChatMessage::toolResults([new ToolResultBlock($search->id, 'a'), new ToolResultBlock($describe->id, 'b')]),
        ]));
        $model = $this->sentPayload()['contents'][1];

        $this->assertSame('model', $model['role']);
        $this->assertSame(['text' => 'Let me search for that.'], $model['parts'][0]);
        $this->assertSame(
            $search->providerData[ToolCallIds::KEY_THOUGHT_SIGNATURE],
            $model['parts'][1]['thoughtSignature'],
        );
        $this->assertArrayNotHasKey('thoughtSignature', $model['parts'][2]);
    }

    public function testCallsFromAnotherProviderGetTheDocumentedSkipSignature(): void
    {
        $messages = [
            ChatMessage::fromText(Role::User, 'Go'),
            new ChatMessage(Role::Assistant, [
                new ToolUseBlock('toolu_01A', 'records.search', ['query' => 'home']),
                new ToolUseBlock('toolu_01B', 'records.count', []),
            ]),
            ChatMessage::toolResults([new ToolResultBlock('toolu_01A', 'a'), new ToolResultBlock('toolu_01B', 'b')]),
        ];

        $this->provider([$this->fixture('text_reply')])->chat(self::request($messages));
        $contents = $this->sentPayload()['contents'];

        $this->assertSame(ToolCallIds::SKIP_SIGNATURE_VALIDATION, $contents[1]['parts'][0]['thoughtSignature']);
        $this->assertArrayNotHasKey('thoughtSignature', $contents[1]['parts'][1]);
        $this->assertSame('toolu_01A', $contents[1]['parts'][0]['functionCall']['id']);
        $this->assertSame([], $contents[1]['parts'][1]['functionCall']['args']);
        $this->assertStringContainsString('"args":{}', (string) $this->sentRequest()->getBody());
        $this->assertSame('toolu_01A', $contents[2]['parts'][0]['functionResponse']['id']);
    }

    public function testCallsWithoutIdsGetStableIdsAndResultsArePairedByNameAndOrder(): void
    {
        $provider = $this->provider([
            $this->fixture('parallel_tool_calls_without_ids'),
            $this->fixture('parallel_tool_calls_without_ids'),
            $this->fixture('text_reply'),
        ]);
        $turn = $provider->chat(self::request());
        $again = $provider->chat(self::request());
        [$search, $describe] = $turn->getToolUses();

        $this->assertTrue(ToolCallIds::isGenerated($search->id));
        $this->assertTrue(ToolCallIds::isGenerated($describe->id));
        $this->assertNotSame($search->id, $describe->id);
        $this->assertSame($search->id, $again->getToolUses()[0]->id);
        $this->assertMatchesRegularExpression(
            '/^[a-zA-Z0-9_-]{1,40}$/',
            $search->id,
            'valid as an id for every vendor',
        );

        $provider->chat(self::request([
            ChatMessage::fromText(Role::User, 'Go'),
            $turn->message,
            ChatMessage::toolResults(
                [new ToolResultBlock($search->id, 'a'), new ToolResultBlock($describe->id, 'b', true)],
            ),
        ]));
        $contents = $this->sentPayload()['contents'];

        $this->assertArrayNotHasKey('id', $contents[1]['parts'][1]['functionCall']);
        $this->assertSame(
            [
                ['functionResponse' => ['name' => 'records__search', 'response' => ['output' => 'a']]],
                ['functionResponse' => ['name' => 'schema__describe', 'response' => ['error' => 'b']]],
            ],
            $contents[2]['parts'],
        );
    }

    public function testConsecutiveSameRoleContentsAreMergedAndOrphanResultsBecomeText(): void
    {
        $messages = [
            ChatMessage::fromText(Role::User, 'Go'),
            new ChatMessage(Role::Assistant, [new ToolUseBlock('fc_1', 'records.count', [])]),
            ChatMessage::toolResults([new ToolResultBlock('fc_1', '3'), new ToolResultBlock('fc_gone', 'lost')]),
            new ChatMessage(Role::User, [new TextBlock('Now publish them.')]),
        ];

        $this->provider([$this->fixture('text_reply')])->chat(self::request($messages));
        $contents = $this->sentPayload()['contents'];

        $this->assertSame(['user', 'model', 'user'], array_column($contents, 'role'));
        $this->assertCount(3, $contents[2]['parts']);
        $this->assertSame('records__count', $contents[2]['parts'][0]['functionResponse']['name']);
        $this->assertSame(['text' => 'Result of tool call fc_gone: lost'], $contents[2]['parts'][1]);
        $this->assertSame(['text' => 'Now publish them.'], $contents[2]['parts'][2]);
    }

    public function testGenerationConfigCarriesThinkingLevelAndTemperatureOnlyWhenSet(): void
    {
        $provider = $this->provider(array_fill(0, 3, $this->fixture('text_reply')));

        $provider->chat(self::request());
        $default = $this->sentPayload()['generationConfig'];
        $provider->chat(self::request([], [], (new ChatOptions('m', 64, 1, 0.4))->withReasoningEffort('high')));
        $tuned = $this->sentPayload()['generationConfig'];
        $provider->chat(self::request([], [], (new ChatOptions('m', 64, 1))->withReasoningEffort('none')));
        $none = $this->sentPayload()['generationConfig'];

        $this->assertSame(['maxOutputTokens' => 256], $default);
        $this->assertSame(
            ['maxOutputTokens' => 64, 'temperature' => 0.4, 'thinkingConfig' => ['thinkingLevel' => 'high']],
            $tuned,
        );
        $this->assertSame(['maxOutputTokens' => 64], $none);
    }

    public function testToolConfigIsAutoAndOnlySentWithTools(): void
    {
        $provider = $this->provider([$this->fixture('text_reply')]);

        $provider->chat(self::request());

        $this->assertArrayNotHasKey('tools', $this->sentPayload());
        $this->assertArrayNotHasKey('toolConfig', $this->sentPayload());
    }

    public function testThoughtPartsAreDropped(): void
    {
        $response = $this->provider([$this->fixture('thought_parts')])->chat(self::request());

        $this->assertSame('Hello! How can I help with your content today?', $response->getText());
        $this->assertCount(1, $response->message->blocks);
    }

    public function testBlockedPromptIsAnEmptyReplyWithStopReasonOther(): void
    {
        $response = $this->provider([$this->fixture('prompt_blocked')])->chat(self::request());

        $this->assertSame(StopReason::Other, $response->stopReason);
        $this->assertSame([], $response->message->blocks);
        $this->assertSame(30, $response->usage->inputTokens);
    }

    public function testMalformedFunctionCallIsOther(): void
    {
        $response = $this->provider([self::json([
            'candidates' => [['content' => ['role' => 'model', 'parts' => []], 'finishReason' => 'MALFORMED_FUNCTION_CALL']],
            'responseId' => 'r1',
        ])])->chat(self::request());

        $this->assertSame(StopReason::Other, $response->stopReason);
    }

    public function testInvalidKeyAndUnsupportedLocationAreBlockingEvenAsHttp400(): void
    {
        foreach (['error_400_api_key_invalid', 'error_400_location'] as $fixture) {
            $exception = $this->chatExpectingFailure([$this->fixture($fixture, 400)]);

            $this->assertTrue($exception->isBlocking(), $fixture);
            $this->assertFalse($exception->isTransient(), $fixture);
            $this->assertSame(400, $exception->getCode());
        }
    }

    public function testRateLimitCarriesTheRetryInfoDelayRoundedUp(): void
    {
        $exception = $this->chatExpectingFailure([$this->fixture('error_429', 429)]);

        $this->assertTrue($exception->isTransient());
        $this->assertSame(23, $exception->getRetryAfterSeconds());
        $this->assertFalse($exception->isDailyQuotaExhausted());
    }

    public function testDailyQuotaIsToldApartFromAShortBusyPeriod(): void
    {
        $daily = $this->chatExpectingFailure([$this->fixture('error_429_daily', 429)]);

        $this->assertTrue($daily->isTransient());
        $this->assertTrue($daily->isDailyQuotaExhausted());
        $this->assertSame(14, $daily->getRetryAfterSeconds());

        $perMinute = $this->chatExpectingFailure([$this->fixture('error_429_per_minute', 429)]);

        $this->assertFalse($perMinute->isDailyQuotaExhausted());
        // No RetryInfo detail: the delay is read from the message.
        $this->assertSame(46, $perMinute->getRetryAfterSeconds());
    }

    public function testAFailureWithoutAHintHasNoRetryDelay(): void
    {
        $exception = $this->chatExpectingFailure([$this->fixture('error_500', 500)]);

        $this->assertTrue($exception->isTransient());
        $this->assertNull($exception->getRetryAfterSeconds());
        $this->assertFalse($exception->isDailyQuotaExhausted());
    }
}
