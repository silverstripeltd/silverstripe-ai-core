<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Provider\OpenAI;

use GuzzleHttp\ClientInterface;
use SilverStripe\Core\Environment;
use SilverstripeLtd\AiCore\Provider\ChatProviderInterface;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\StopReason;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolResultBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolSchema;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\OpenAI\OpenAIProvider;
use SilverstripeLtd\AiCore\Tests\Provider\HttpProviderTestCase;

class OpenAIProviderTest extends HttpProviderTestCase
{
    protected function createProvider(ClientInterface $client): ChatProviderInterface
    {
        return new OpenAIProvider($client);
    }

    protected function getFixtureDirectory(): string
    {
        return OpenAIProvider::NAME;
    }

    public function testRequestGoesToChatCompletionsWithABearerToken(): void
    {
        $this->provider([$this->fixture('text_reply')])->chat(self::request());

        $this->assertSame('POST', $this->sentRequest()->getMethod());
        $this->assertSame(OpenAIProvider::ENDPOINT, (string) $this->sentRequest()->getUri());
        $this->assertSame('Bearer ' . self::SECRET, $this->sentRequest()->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $this->sentRequest()->getHeaderLine('Content-Type'));
    }

    public function testToolSettingsAreOnlySentWithTools(): void
    {
        $tools = [new ToolSchema('records.search', 'Find', ['type' => 'object', 'properties' => []])];

        $provider = $this->provider([$this->fixture('text_reply'), $this->fixture('text_reply')]);
        $provider->chat(self::request());
        $without = $this->sentPayload();
        $provider->chat(self::request([], $tools));
        $with = $this->sentPayload();

        foreach (['tools', 'tool_choice', 'parallel_tool_calls'] as $key) {
            $this->assertArrayNotHasKey($key, $without);
        }

        $this->assertSame('auto', $with['tool_choice']);
        $this->assertTrue($with['parallel_tool_calls']);
        $this->assertSame('function', $with['tools'][0]['type']);
        $this->assertSame('records__search', $with['tools'][0]['function']['name']);
        $this->assertFalse($with['tools'][0]['function']['strict']);
    }

    public function testTemperatureAndReasoningEffortAreOnlySentWhenSet(): void
    {
        $provider = $this->provider(array_fill(0, 3, $this->fixture('text_reply')));

        $provider->chat(self::request());
        $default = $this->sentPayload();
        $provider->chat(self::request([], [], (new ChatOptions('m', 1, 1, 0.2))->withReasoningEffort('high')));
        $tuned = $this->sentPayload();
        $provider->chat(self::request([], [], (new ChatOptions('m', 1, 1))->withReasoningEffort('none')));
        $none = $this->sentPayload();

        $this->assertArrayNotHasKey('temperature', $default);
        $this->assertArrayNotHasKey('reasoning_effort', $default);
        $this->assertSame(0.2, $tuned['temperature']);
        $this->assertSame('high', $tuned['reasoning_effort']);
        $this->assertSame('none', $none['reasoning_effort'], '"none" is a valid OpenAI effort');
    }

    public function testPromptCacheKeyFollowsTheCacheablePrefixAndTools(): void
    {
        $prefixed = (new ChatOptions('m', 1, 1))->withCacheableSystemPrefix(['Rules']);
        $other = (new ChatOptions('m', 1, 1))->withCacheableSystemPrefix(['Other rules']);
        $tools = [new ToolSchema('records.search', 'Find', [])];
        $provider = $this->provider(array_fill(0, 5, $this->fixture('text_reply')));

        $provider->chat(self::request());
        $this->assertArrayNotHasKey('prompt_cache_key', $this->sentPayload());

        $provider->chat(self::request([ChatMessage::fromText(Role::User, 'one')], [], $prefixed));
        $first = $this->sentPayload()['prompt_cache_key'];
        $provider->chat(self::request([ChatMessage::fromText(Role::User, 'two')], [], $prefixed));
        $second = $this->sentPayload()['prompt_cache_key'];
        $provider->chat(self::request([], [], $other));
        $otherPrefix = $this->sentPayload()['prompt_cache_key'];
        $provider->chat(self::request([], $tools, $prefixed));
        $withTools = $this->sentPayload()['prompt_cache_key'];

        $this->assertStringStartsWith('content-engineer-', $first);
        $this->assertSame($first, $second, 'the per message text does not change the key');
        $this->assertNotSame($first, $otherPrefix);
        $this->assertNotSame($first, $withTools);
    }

    public function testToolMessagesComeBeforeTextAndEmptyArgumentsAreAnObject(): void
    {
        $messages = [
            ChatMessage::fromText(Role::User, 'Count pages'),
            new ChatMessage(Role::Assistant, [new ToolUseBlock('call_1', 'records.count', [])]),
            new ChatMessage(Role::Tool, [new TextBlock('Also, thanks.'), new ToolResultBlock('call_1', '{"count":3}')]),
            new ChatMessage(Role::Assistant, [new TextBlock('')]),
        ];

        $this->provider([$this->fixture('text_reply')])->chat(self::request($messages));
        $sent = $this->sentPayload()['messages'];

        $this->assertSame(['developer', 'user', 'assistant', 'tool', 'user'], array_column($sent, 'role'));
        $this->assertNull($sent[2]['content']);
        $this->assertSame('{}', $sent[2]['tool_calls'][0]['function']['arguments']);
        $this->assertSame('call_1', $sent[3]['tool_call_id']);
        $this->assertSame('Also, thanks.', $sent[4]['content']);
    }

    public function testExhaustedQuotaIsBlockingNotTransient(): void
    {
        $exception = $this->chatExpectingFailure([$this->fixture('error_429_insufficient_quota', 429)]);

        $this->assertTrue($exception->isBlocking());
        $this->assertFalse($exception->isTransient());
        $this->assertSame(429, $exception->getCode());
    }

    public function testRefusalIsKeptAsTextWithStopReasonOther(): void
    {
        $response = $this->provider([$this->fixture('refusal')])->chat(self::request());

        $this->assertSame(StopReason::Other, $response->stopReason);
        $this->assertSame("I can't help with that request.", $response->getText());
    }

    public function testUnreadableArgumentsBecomeAnEmptyInput(): void
    {
        $response = $this->provider([self::json([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'records__get', 'arguments' => '{"id": 1']],
                    ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'records__get', 'arguments' => '[1, 2]']],
                ]],
                'finish_reason' => 'tool_calls',
            ]],
        ])])->chat(self::request());

        $this->assertSame([], $response->getToolUses()[0]->input);
        $this->assertSame([], $response->getToolUses()[1]->input);
        $this->assertSame('records.get', $response->getToolUses()[0]->name);
    }

    public function testToolCallsMeanToolUseUnlessOutputWasCutOff(): void
    {
        $call = ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'noop', 'arguments' => '{}']];
        $body = static fn (string $finish): array => [
            'id' => 'chatcmpl-1',
            'choices' => [['message' => ['role' => 'assistant', 'tool_calls' => [$call]], 'finish_reason' => $finish]],
        ];
        $provider = $this->provider([self::json($body('stop')), self::json($body('length'))]);

        $this->assertSame(StopReason::ToolUse, $provider->chat(self::request())->stopReason);
        $this->assertSame(StopReason::MaxTokens, $provider->chat(self::request())->stopReason);
    }

    public function testDefaultOptionsUseTheOpenAIDefaultsAndTheThinkingLevel(): void
    {
        Environment::setEnv('AI_THINKING_LEVEL', 'low');

        $options = (new OpenAIProvider())->getDefaultOptions();

        $this->assertSame(OpenAIProvider::DEFAULT_MODEL, $options->model);
        $this->assertSame(OpenAIProvider::DEFAULT_MAX_TOKENS, $options->maxTokens);
        $this->assertSame('low', $options->reasoningEffort);
    }
}
