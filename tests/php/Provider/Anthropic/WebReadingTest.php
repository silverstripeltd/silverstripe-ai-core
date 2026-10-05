<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Provider\Anthropic;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Provider\Anthropic\AnthropicProvider;
use SilverstripeLtd\AiCore\Provider\Anthropic\RequestMapper;
use SilverstripeLtd\AiCore\Provider\Gemini\GeminiProvider;
use SilverstripeLtd\AiCore\Provider\Gemini\RequestMapper as GeminiRequestMapper;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\ServerToolBlock;
use SilverstripeLtd\AiCore\Provider\Message\ServerToolPart;
use SilverstripeLtd\AiCore\Provider\Message\StopReason;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolSchema;
use SilverstripeLtd\AiCore\Provider\Message\WebReadingOptions;
use SilverstripeLtd\AiCore\Provider\OpenAI\OpenAIProvider;
use SilverstripeLtd\AiCore\Provider\OpenAI\RequestMapper as OpenAIRequestMapper;
use SilverstripeLtd\AiCore\Provider\ProviderCapability;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;

/**
 * Web reading through Anthropic's web fetch server tool: the capability, the tool definition
 * in the request, the server tool blocks in the reply, and their replay unchanged in the next
 * request. Other providers report the capability as unavailable and never send the blocks.
 */
class WebReadingTest extends SapphireTest
{
    private const string URL = 'https://en.wikipedia.org/wiki/New_Zealand';

    /**
     * @var bool
     */
    protected $usesDatabase = false;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $history = [];

    private mixed $originalKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalKey = Environment::getEnv('AI_API_KEY');
        Environment::setEnv('AI_API_KEY', 'test-key');
        $this->history = [];
    }

    protected function tearDown(): void
    {
        Environment::setEnv('AI_API_KEY', $this->originalKey);

        parent::tearDown();
    }

    public function testOnlyAnthropicOffersWebReading(): void
    {
        $this->assertTrue((new AnthropicProvider())->supports(ProviderCapability::WebReading));
        $this->assertFalse((new OpenAIProvider())->supports(ProviderCapability::WebReading));
        $this->assertFalse((new GeminiProvider())->supports(ProviderCapability::WebReading));

        $scripted = new ScriptedProvider();
        $this->assertFalse($scripted->supports(ProviderCapability::WebReading));
        $this->assertTrue($scripted->enable(ProviderCapability::WebReading)->supports(ProviderCapability::WebReading));
        $this->assertFalse($scripted->reset()->supports(ProviderCapability::WebReading));
    }

    public function testTheWebFetchToolIsOnlySentWhenAskedFor(): void
    {
        $this->chat($this->request(null));
        $this->assertArrayNotHasKey('tools', $this->sentPayload(0));

        $this->chat($this->request(new WebReadingOptions(5, ['intranet.example.com'], [], 20000)));
        $payload = $this->sentPayload(1);

        $this->assertSame([[
            'type' => RequestMapper::WEB_FETCH_TOOL,
            'name' => 'web_fetch',
            'max_uses' => 5,
            'blocked_domains' => ['intranet.example.com'],
            'max_content_tokens' => 20000,
        ]], $payload['tools']);
        $this->assertSame(['type' => 'auto'], $payload['tool_choice']);
    }

    public function testTheServerToolFollowsTheCallersToolsAndAnAllowListWins(): void
    {
        $search = new ToolSchema('records.search', 'Find records', ['type' => 'object', 'properties' => []]);
        $request = $this->request(new WebReadingOptions(null, ['blocked.example'], ['wikipedia.org']), [$search]);

        $this->chat($request);
        $tools = $this->sentPayload(0)['tools'];

        $this->assertSame('records__search', $tools[0]['name']);
        $this->assertSame(
            ['type' => RequestMapper::WEB_FETCH_TOOL, 'name' => 'web_fetch', 'allowed_domains' => ['wikipedia.org']],
            $tools[1],
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function models(): array
    {
        return [
            'opus 5.5' => ['claude-opus-5-5', RequestMapper::WEB_FETCH_TOOL],
            'sonnet 5.5' => ['claude-sonnet-5-5', RequestMapper::WEB_FETCH_TOOL],
            'opus 4.6' => ['claude-opus-4-6', RequestMapper::WEB_FETCH_TOOL],
            'sonnet 4.6' => ['claude-sonnet-4-6', RequestMapper::WEB_FETCH_TOOL],
            'fable' => ['claude-fable-5-1', RequestMapper::WEB_FETCH_TOOL],
            'haiku 4.5' => ['claude-haiku-4-5', RequestMapper::WEB_FETCH_TOOL_BASIC],
            'sonnet 4.5 dated' => ['claude-sonnet-4-5-20250929', RequestMapper::WEB_FETCH_TOOL_BASIC],
            'opus 4.1' => ['claude-opus-4-1', RequestMapper::WEB_FETCH_TOOL_BASIC],
            'sonnet 4 dated' => ['claude-sonnet-4-20250514', RequestMapper::WEB_FETCH_TOOL_BASIC],
            'claude 3' => ['claude-3-7-sonnet-latest', RequestMapper::WEB_FETCH_TOOL_BASIC],
        ];
    }

    #[DataProvider('models')]
    public function testOlderModelsGetTheBasicTool(string $model, string $type): void
    {
        $this->assertSame($type, RequestMapper::webFetchTool(new WebReadingOptions(), $model)['type']);
    }

    public function testServerToolBlocksAreParsedAndKeptAsReceived(): void
    {
        $response = $this->chat($this->request(new WebReadingOptions()), [self::apiResponse(self::fetchContent())]);
        $blocks = $response->message->blocks;

        $this->assertCount(6, $blocks);
        $this->assertInstanceOf(TextBlock::class, $blocks[0]);

        $call = $blocks[1];
        $this->assertInstanceOf(ServerToolBlock::class, $call);
        $this->assertSame(ServerToolPart::Call, $call->part);
        $this->assertTrue($call->isWebReading());
        $this->assertSame('srvtoolu_01', $call->callId);
        $this->assertSame(['url' => self::URL], $call->input);
        $this->assertSame(self::fetchContent()[1], $call->raw);

        $result = $blocks[2];
        $this->assertInstanceOf(ServerToolBlock::class, $result);
        $this->assertSame(ServerToolPart::Result, $result->part);
        $this->assertTrue($result->isWebReading());
        $this->assertSame('srvtoolu_01', $result->callId);
        $this->assertFalse($result->isError);
        $this->assertSame(self::fetchContent()[2], $result->raw);

        $this->assertSame('bash_code_execution', $blocks[3]->tool, 'other server tools keep their own name');
        $this->assertFalse($blocks[3]->isWebReading());
        $this->assertTrue($blocks[4]->isError, 'an error result is flagged');
        $this->assertSame([], $response->getToolUses(), 'server tools are never handed to the caller to run');
        $this->assertSame("I'll read it.\n\nNew Zealand is an island country.", $response->getText());
    }

    public function testTheBlocksSurviveStorageAndAreSentBackUnchanged(): void
    {
        $first = $this->chat($this->request(new WebReadingOptions()), [self::apiResponse(self::fetchContent())]);
        $stored = json_decode((string) json_encode($first->message->toArray()), true);
        $replayed = ChatMessage::fromArray($stored);

        $this->chat($this->request(new WebReadingOptions(), [], [
            ChatMessage::fromText(Role::User, 'Read ' . self::URL),
            $replayed,
            ChatMessage::fromText(Role::User, 'Thanks'),
        ]), [self::apiResponse([['type' => 'text', 'text' => 'ok']])]);

        $sent = $this->sentPayload(1)['messages'][1];
        $this->assertSame('assistant', $sent['role']);
        $this->assertSame(self::fetchContent(), $sent['content']);
    }

    public function testAPausedTurnIsReported(): void
    {
        $response = $this->chat(
            $this->request(new WebReadingOptions()),
            [self::apiResponse(array_slice(self::fetchContent(), 0, 2), 'pause_turn')],
        );

        $this->assertSame(StopReason::PauseTurn, $response->stopReason);
    }

    public function testServerToolBlocksFromAnotherProviderAreNotSent(): void
    {
        $scripted = ScriptedProvider::webReading(self::URL, 'Page text', 'Done');
        $this->chat($this->request(null, [], [
            ChatMessage::fromText(Role::User, 'Read ' . self::URL),
            $scripted->message,
            ChatMessage::fromText(Role::User, 'Thanks'),
        ]));

        $this->assertSame([['type' => 'text', 'text' => 'Done']], $this->sentPayload(0)['messages'][1]['content']);
    }

    public function testOtherProvidersLeaveServerToolBlocksOut(): void
    {
        $reply = new ChatMessage(Role::Assistant, [
            new ServerToolBlock('anthropic', ServerToolPart::Call, 'srv_1', 'web_reading', ['type' => 'x']),
            new TextBlock('Summary'),
        ]);
        $request = new ChatRequest(
            '',
            [ChatMessage::fromText(Role::User, 'Read it'), $reply],
            [],
            new ChatOptions('model-x', 64, 10),
        );

        foreach ([new OpenAIRequestMapper(), new GeminiRequestMapper()] as $mapper) {
            $payload = (string) json_encode($mapper->toPayload($request));

            $this->assertStringNotContainsString('srv_1', $payload, $mapper::class);
            $this->assertStringContainsString('Summary', $payload, $mapper::class);
        }
    }

    public function testWebReadingOptionsTravelWithTheRequestArray(): void
    {
        $request = $this->request(new WebReadingOptions(3, ['a.example'], [], 9000));
        $restored = ChatRequest::fromArray(json_decode((string) json_encode($request->toArray()), true));

        $this->assertEquals($request->webReading, $restored->webReading);
        $this->assertArrayNotHasKey('web_reading', $this->request(null)->toArray());
        $this->assertNull(ChatRequest::fromArray($this->request(null)->toArray())->webReading);
    }

    public function testTheScriptedProviderCanReplyAfterReadingAPage(): void
    {
        $reply = ScriptedProvider::webReading(self::URL, 'Page text', 'Summary', true);
        [$call, $result] = $reply->message->blocks;

        $this->assertInstanceOf(ServerToolBlock::class, $call);
        $this->assertSame(['url' => self::URL], $call->input);
        $this->assertTrue($result->isError);
        $this->assertSame($call->callId, $result->callId);
        $this->assertSame('Summary', $reply->getText());
        $this->assertEquals($reply->message, ChatMessage::fromArray($reply->message->toArray()));
    }

    /**
     * Text, a direct web fetch and its result, a code execution result from dynamic filtering,
     * a failed fetch, and the answer.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fetchContent(): array
    {
        return [
            ['type' => 'text', 'text' => "I'll read it."],
            [
                'type' => 'server_tool_use',
                'id' => 'srvtoolu_01',
                'name' => 'web_fetch',
                'input' => ['url' => self::URL],
            ],
            [
                'type' => 'web_fetch_tool_result',
                'tool_use_id' => 'srvtoolu_01',
                'content' => [
                    'type' => 'web_fetch_result',
                    'url' => self::URL,
                    'content' => [
                        'type' => 'document',
                        'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => 'New Zealand ...'],
                        'title' => 'New Zealand - Wikipedia',
                    ],
                    'retrieved_at' => '2026-10-06T10:30:00Z',
                ],
            ],
            [
                'type' => 'bash_code_execution_tool_result',
                'tool_use_id' => 'srvtoolu_02',
                'content' => ['type' => 'bash_code_execution_result', 'stdout' => 'filtered', 'stderr' => '', 'return_code' => 0],
            ],
            [
                'type' => 'web_fetch_tool_result',
                'tool_use_id' => 'srvtoolu_03',
                'content' => ['type' => 'web_fetch_tool_result_error', 'error_code' => 'url_not_accessible'],
            ],
            ['type' => 'text', 'text' => 'New Zealand is an island country.'],
        ];
    }

    /**
     * @param array<int, ChatMessage> $messages
     * @param array<int, ToolSchema> $tools
     */
    private function request(?WebReadingOptions $webReading, array $tools = [], array $messages = []): ChatRequest
    {
        return new ChatRequest(
            'Be brief.',
            $messages === []
                ? [ChatMessage::fromText(Role::User, 'Read ' . self::URL)]
                : $messages,
            $tools,
            new ChatOptions('claude-sonnet-5-5', 256, 10),
            $webReading,
        );
    }

    /**
     * @param array<int, Response> $queue
     */
    private function chat(ChatRequest $request, array $queue = []): ChatResponse
    {
        $provider = new AnthropicProvider($this->client($queue === [] ? [self::apiResponse([])] : $queue));

        return $provider->chat($request);
    }

    /**
     * @param array<int, Response> $queue
     */
    private function client(array $queue): Client
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentPayload(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, array<string, mixed>> $content
     */
    private static function apiResponse(array $content, string $stopReason = 'end_turn'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => $content === [] ? [['type' => 'text', 'text' => 'ok']] : $content,
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'server_tool_use' => ['web_fetch_requests' => 1]],
        ]));
    }
}
