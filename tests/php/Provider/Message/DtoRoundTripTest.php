<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Provider\Message;

use InvalidArgumentException;
use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Provider\Message\BlockFactory;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\Message\ImageBlock;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\StopReason;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolResultBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolSchema;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\Message\Usage;

class DtoRoundTripTest extends SapphireTest
{
    /**
     * @var bool
     */
    protected $usesDatabase = false;

    public function testBlocksRoundTrip(): void
    {
        $blocks = [
            new TextBlock('Hello'),
            new ToolUseBlock('toolu_1', 'records.search', ['class' => 'Page', 'limit' => 5]),
            new ToolUseBlock('fc_1', 'records.get', ['id' => 1], ['gemini.thought_signature' => 'c2ln']),
            new ToolResultBlock('toolu_1', '{"count":2}'),
            new ToolResultBlock('toolu_2', 'Not found', true),
            new ImageBlock(ImageBlock::MEDIA_PNG, base64_encode('png-bytes')),
        ];

        foreach ($blocks as $block) {
            $restored = BlockFactory::fromArray($block->toArray());

            $this->assertEquals($block, $restored);
            $this->assertSame($block->toArray(), $restored->toArray());
        }

        $this->assertEquals($blocks, BlockFactory::listFromArray(BlockFactory::listToArray($blocks)));
    }

    public function testBlockArraysCarryTheirType(): void
    {
        $this->assertSame('text', (new TextBlock('x'))->toArray()['type']);
        $this->assertSame('tool_use', (new ToolUseBlock('a', 'b', []))->toArray()['type']);
        $this->assertSame('tool_result', (new ToolResultBlock('a', 'b'))->toArray()['type']);
    }

    public function testImageBlockFromBinaryReportsSizeAndDataUri(): void
    {
        $block = ImageBlock::fromBinary('abcde', ImageBlock::MEDIA_JPEG);

        $this->assertSame('image', $block->toArray()['type']);
        $this->assertSame(5, $block->byteLength());
        $this->assertSame('data:image/jpeg;base64,YWJjZGU=', $block->toDataUri());
        $this->assertSame([$block], (new ChatMessage(Role::User, [new TextBlock('x'), $block]))->getImages());
    }

    public function testImageBlockAcceptsAnImageAtTheSizeLimit(): void
    {
        $block = ImageBlock::fromBinary(str_repeat('a', ImageBlock::MAX_BYTES), ImageBlock::MEDIA_PNG);

        $this->assertSame(ImageBlock::MAX_BYTES, $block->byteLength());
    }

    public function testImageBlockRejectsAnImageOverTheSizeLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('byte limit');

        ImageBlock::fromBinary(str_repeat('a', ImageBlock::MAX_BYTES + 1), ImageBlock::MEDIA_PNG);
    }

    public function testImageBlockRejectsUnsupportedMediaTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('image/svg+xml');

        new ImageBlock('image/svg+xml', base64_encode('<svg/>'));
    }

    public function testImageBlockRejectsDataThatIsNotBase64(): void
    {
        foreach (['', 'data:image/png;base64,AAAA', 'not base64!', 'abc'] as $data) {
            try {
                new ImageBlock(ImageBlock::MEDIA_PNG, $data);
                $this->fail('Accepted ' . $data);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('base64', $exception->getMessage());
            }
        }
    }

    public function testUnknownBlockTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('thinking');

        BlockFactory::fromArray(['type' => 'thinking', 'thinking' => '...']);
    }

    public function testToolUseProviderDataIsOnlySerialisedWhenPresentAndSurvivesWithInput(): void
    {
        $plain = new ToolUseBlock('a', 'b', ['x' => 1]);
        $signed = new ToolUseBlock('a', 'b', ['x' => 1], ['gemini.thought_signature' => 'c2ln']);

        $this->assertArrayNotHasKey(ToolUseBlock::KEY_PROVIDER_DATA, $plain->toArray());
        $this->assertSame(['gemini.thought_signature' => 'c2ln'], $signed->toArray()[ToolUseBlock::KEY_PROVIDER_DATA]);
        $this->assertSame(['gemini.thought_signature' => 'c2ln'], $signed->withInput(['y' => 2])->providerData);
        $this->assertSame(['y' => 2], $signed->withInput(['y' => 2])->input);
        $this->assertSame(
            [],
            ToolUseBlock::fromArray(['id' => 'a', 'name' => 'b', 'provider_data' => 'junk'])->providerData,
        );
    }

    public function testToolUseInputThatIsNotAnArrayBecomesEmpty(): void
    {
        $block = ToolUseBlock::fromArray(['id' => 'x', 'name' => 'y', 'input' => 'oops']);

        $this->assertSame([], $block->input);
    }

    public function testChatMessageRoundTripAndHelpers(): void
    {
        $message = new ChatMessage(Role::Assistant, [
            new TextBlock('Let me look.'),
            new ToolUseBlock('toolu_1', 'records.search', ['q' => 'a']),
            new TextBlock('And also.'),
            new ToolUseBlock('toolu_2', 'records.get', ['id' => 1]),
        ]);

        $restored = ChatMessage::fromArray($message->toArray());

        $this->assertEquals($message, $restored);
        $this->assertSame('assistant', $message->toArray()['role']);
        $this->assertSame("Let me look.\n\nAnd also.", $restored->getText());
        $this->assertCount(2, $restored->getToolUses());
        $this->assertSame('toolu_2', $restored->getToolUses()[1]->id);
        $this->assertSame([], $restored->getToolResults());
    }

    public function testChatMessageBuilders(): void
    {
        $user = ChatMessage::fromText(Role::User, 'Hi');
        $this->assertSame(Role::User, $user->role);
        $this->assertSame('Hi', $user->getText());

        $results = ChatMessage::toolResults([
            5 => new ToolResultBlock('toolu_1', 'ok'),
            9 => new ToolResultBlock('toolu_2', 'bad', true),
        ]);
        $this->assertSame(Role::Tool, $results->role);
        $this->assertCount(2, $results->getToolResults());
        $this->assertTrue($results->getToolResults()[1]->isError);
        $this->assertSame('', $results->getText());
    }

    public function testChatMessageRejectsUnknownRole(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('narrator');

        ChatMessage::fromArray(['role' => 'narrator', 'blocks' => []]);
    }

    public function testToolSchemaRoundTrip(): void
    {
        $schema = new ToolSchema('records.search', 'Find records', [
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'required' => ['q'],
        ]);

        $this->assertEquals($schema, ToolSchema::fromArray($schema->toArray()));
        $this->assertSame('input_schema', array_keys($schema->toArray())[2]);
    }

    public function testChatOptionsRoundTripAndImmutableVariants(): void
    {
        $options = new ChatOptions('model-x', 2048, 30, 0.2, ['stable prefix'], 'medium');

        $this->assertEquals($options, ChatOptions::fromArray($options->toArray()));

        $withPrefix = $options->withCacheableSystemPrefix(['a', 'b']);
        $this->assertSame(['stable prefix'], $options->cacheableSystemPrefix);
        $this->assertSame(['a', 'b'], $withPrefix->cacheableSystemPrefix);
        $this->assertSame(4096, $options->withMaxTokens(4096)->maxTokens);
        $this->assertSame(0.7, $options->withTemperature(0.7)->temperature);
        $this->assertSame('model-x', $withPrefix->model);
        $this->assertSame('medium', $withPrefix->withMaxTokens(1)->withTemperature(0.1)->reasoningEffort);
        $this->assertSame('low', $options->withReasoningEffort('low')->getEffectiveReasoningEffort());
        $this->assertNull(
            $options->withReasoningEffort(ChatOptions::REASONING_EFFORT_NONE)->getEffectiveReasoningEffort(),
        );
        $this->assertNull($options->withReasoningEffort(null)->getEffectiveReasoningEffort());
    }

    public function testChatOptionsDefaults(): void
    {
        $options = ChatOptions::fromArray(['model' => 'm', 'max_tokens' => 10, 'timeout_seconds' => 5]);

        $this->assertSame(ChatOptions::DEFAULT_TEMPERATURE, $options->temperature);
        $this->assertSame([], $options->cacheableSystemPrefix);
        $this->assertNull($options->reasoningEffort);
    }

    public function testUsageRoundTrip(): void
    {
        $usage = new Usage(120, 30, 1000, 250);

        $this->assertEquals($usage, Usage::fromArray($usage->toArray()));
        $this->assertSame(1370, $usage->getTotalInputTokens());
        $this->assertSame(0, Usage::fromArray([])->cacheReadTokens);
    }

    public function testChatRequestRoundTripAndHelpers(): void
    {
        $request = new ChatRequest(
            'System prompt',
            [
                ChatMessage::fromText(Role::User, 'First'),
                ChatMessage::fromText(Role::Assistant, 'Reply'),
                ChatMessage::fromText(Role::User, 'Second'),
                ChatMessage::toolResults([new ToolResultBlock('toolu_1', 'done')]),
            ],
            [new ToolSchema('records.search', 'Find', ['type' => 'object'])],
            new ChatOptions('model-x', 100, 10),
        );

        $restored = ChatRequest::fromArray($request->toArray());

        $this->assertEquals($request, $restored);
        $this->assertSame('Second', $restored->getLastUserText());
        $this->assertTrue($restored->hasAssistantMessage());
        $this->assertSame(Role::Tool, $restored->getLastMessage()?->role);
    }

    public function testEmptyChatRequestHelpers(): void
    {
        $request = new ChatRequest('', [], [], new ChatOptions('m', 1, 1));

        $this->assertNull($request->getLastMessage());
        $this->assertSame('', $request->getLastUserText());
        $this->assertFalse($request->hasAssistantMessage());
        $this->assertEquals($request, ChatRequest::fromArray($request->toArray()));
    }

    public function testChatResponseRoundTripAndHelpers(): void
    {
        $response = new ChatResponse(
            new ChatMessage(Role::Assistant, [
                new TextBlock('Searching'),
                new ToolUseBlock('toolu_9', 'records.search', ['q' => 'x']),
            ]),
            StopReason::ToolUse,
            new Usage(10, 5, 2, 1),
            'msg_123',
        );

        $restored = ChatResponse::fromArray($response->toArray());

        $this->assertEquals($response, $restored);
        $this->assertSame('tool_use', $response->toArray()['stop_reason']);
        $this->assertTrue($restored->hasToolUses());
        $this->assertSame('records.search', $restored->getToolUses()[0]->name);
        $this->assertSame('Searching', $restored->getText());
        $this->assertSame('msg_123', $restored->providerMessageId);
    }

    public function testUnknownStopReasonBecomesOther(): void
    {
        $response = ChatResponse::fromArray([
            'message' => ['role' => 'assistant', 'blocks' => []],
            'stop_reason' => 'refusal',
        ]);

        $this->assertSame(StopReason::Other, $response->stopReason);
        $this->assertFalse($response->hasToolUses());
        $this->assertSame('', $response->providerMessageId);
    }
}
