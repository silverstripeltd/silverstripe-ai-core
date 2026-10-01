<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Testing;

use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\StopReason;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;

class ScriptedProviderTest extends SapphireTest
{
    /**
     * @var bool
     */
    protected $usesDatabase = false;

    public function testReplaysTheQueueInOrderAndRecordsRequests(): void
    {
        $provider = new ScriptedProvider([
            ScriptedProvider::toolUse('records.search', ['q' => 'home']),
            ScriptedProvider::text('Found it.'),
        ]);

        $first = $provider->chat(self::request('Find home'));
        $second = $provider->chat(self::request('Thanks'));

        $this->assertSame(StopReason::ToolUse, $first->stopReason);
        $this->assertSame('Found it.', $second->getText());
        $this->assertCount(2, $provider->getRequests());
        $this->assertSame('Find home', $provider->getRequests()[0]->getLastUserText());
        $this->assertSame('Thanks', $provider->getLastRequest()?->getLastUserText());
        $this->assertSame(0, $provider->getRemainingCount());
    }

    public function testClosuresReceiveTheRequest(): void
    {
        $provider = new ScriptedProvider();
        $provider->queue(static function (ChatRequest $request): ChatResponse {
            return ScriptedProvider::text('Echo: ' . $request->getLastUserText());
        });

        $this->assertSame('Echo: ping', $provider->chat(self::request('ping'))->getText());
    }

    public function testEmptyQueueFailsLoudly(): void
    {
        $provider = new ScriptedProvider([ScriptedProvider::text('one')]);
        $provider->chat(self::request('a'));

        try {
            $provider->chat(self::request('b'));
            $this->fail('Expected a ProviderException');
        } catch (ProviderException $exception) {
            $this->assertStringContainsString('request 2', $exception->getMessage());
            $this->assertFalse($exception->isTransient());
            $this->assertFalse($exception->isBlocking());
        }

        $this->assertCount(2, $provider->getRequests());
    }

    public function testClosureMustReturnAResponse(): void
    {
        $provider = new ScriptedProvider([static fn (ChatRequest $request): string => 'nope']);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('string');

        $provider->chat(self::request('x'));
    }

    public function testTextBuilder(): void
    {
        $response = ScriptedProvider::text('Hi');

        $this->assertSame(StopReason::EndTurn, $response->stopReason);
        $this->assertSame('Hi', $response->getText());
        $this->assertSame(Role::Assistant, $response->message->role);
        $this->assertFalse($response->hasToolUses());
        $this->assertSame(ScriptedProvider::FAKE_INPUT_TOKENS, $response->usage->inputTokens);
        $this->assertSame(ScriptedProvider::FAKE_OUTPUT_TOKENS, $response->usage->outputTokens);
        $this->assertStringStartsWith('msg_scripted_', $response->providerMessageId);
    }

    public function testToolUseBuilder(): void
    {
        $generated = ScriptedProvider::toolUse('records.search', ['q' => 'a']);
        $explicit = ScriptedProvider::toolUse('records.delete', ['id' => 3], 'toolu_custom', 'Deleting now');

        $this->assertSame(StopReason::ToolUse, $generated->stopReason);
        $this->assertCount(1, $generated->getToolUses());
        $this->assertStringStartsWith('toolu_scripted_', $generated->getToolUses()[0]->id);
        $this->assertSame(['q' => 'a'], $generated->getToolUses()[0]->input);
        $this->assertSame('', $generated->getText());

        $this->assertSame('toolu_custom', $explicit->getToolUses()[0]->id);
        $this->assertSame('records.delete', $explicit->getToolUses()[0]->name);
        $this->assertSame('Deleting now', $explicit->getText());
    }

    public function testToolUsesBuilderAcceptsArraysAndBlocks(): void
    {
        $response = ScriptedProvider::toolUses([
            ['name' => 'records.get', 'input' => ['id' => 1]],
            ['name' => 'records.get', 'input' => ['id' => 2], 'id' => 'toolu_two'],
            new ToolUseBlock('toolu_three', 'records.publish', []),
        ], 'Batch');

        $uses = $response->getToolUses();
        $this->assertSame(StopReason::ToolUse, $response->stopReason);
        $this->assertCount(3, $uses);
        $this->assertStringStartsWith('toolu_scripted_', $uses[0]->id);
        $this->assertSame('toolu_two', $uses[1]->id);
        $this->assertSame('toolu_three', $uses[2]->id);
        $this->assertSame('records.publish', $uses[2]->name);
        $this->assertSame('Batch', $response->getText());
    }

    public function testGeneratedIdsAreUnique(): void
    {
        $first = ScriptedProvider::toolUse('a')->getToolUses()[0]->id;
        $second = ScriptedProvider::toolUse('a')->getToolUses()[0]->id;

        $this->assertNotSame($first, $second);
    }

    public function testCannedFallbackGreetsThenEchoes(): void
    {
        $provider = new ScriptedProvider([], true);

        $first = $provider->chat(self::request('Hello there'));
        $second = $provider->chat(new ChatRequest('', [
            ChatMessage::fromText(Role::User, 'Hello there'),
            $first->message,
            ChatMessage::fromText(Role::User, 'Again'),
        ], [], $provider->getDefaultOptions()));

        $this->assertSame(StopReason::EndTurn, $first->stopReason);
        $this->assertStringContainsString(ScriptedProvider::GREETING, $first->getText());
        $this->assertStringContainsString('You said: "Hello there"', $first->getText());
        $this->assertStringContainsString(ScriptedProvider::NO_PROVIDER_NOTE, $first->getText());

        $this->assertStringNotContainsString(ScriptedProvider::GREETING, $second->getText());
        $this->assertStringContainsString('You said: "Again"', $second->getText());
        $this->assertStringContainsString(ScriptedProvider::NO_PROVIDER_NOTE, $second->getText());
    }

    public function testCannedFallbackOnlyAppliesOnceTheQueueIsEmpty(): void
    {
        $provider = new ScriptedProvider([ScriptedProvider::text('scripted')], true);

        $this->assertSame('scripted', $provider->chat(self::request('a'))->getText());
        $this->assertStringContainsString(ScriptedProvider::GREETING, $provider->chat(self::request('b'))->getText());
    }

    public function testResetClearsQueueAndRequests(): void
    {
        $provider = new ScriptedProvider([ScriptedProvider::text('a'), ScriptedProvider::text('b')]);
        $provider->chat(self::request('x'));

        $provider->reset();

        $this->assertSame([], $provider->getRequests());
        $this->assertSame(0, $provider->getRemainingCount());
        $this->assertNull($provider->getLastRequest());
    }

    public function testNameAndDefaultOptions(): void
    {
        $provider = new ScriptedProvider();

        $this->assertSame('scripted', $provider->getName());
        $this->assertSame(ScriptedProvider::MODEL, $provider->getDefaultOptions()->model);
        $this->assertGreaterThan(0, $provider->getDefaultOptions()->maxTokens);
    }

    private static function request(string $userText): ChatRequest
    {
        $provider = new ScriptedProvider();

        return new ChatRequest('', [ChatMessage::fromText(Role::User, $userText)], [], $provider->getDefaultOptions());
    }
}
