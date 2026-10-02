<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Provider\Anthropic;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Provider\Anthropic\AnthropicProvider;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\StopReason;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolResultBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolSchema;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Settings\EnvProviderSettings;

class AnthropicProviderTest extends SapphireTest
{
    private const string SECRET = 'sk-ant-test-secret-3b9c1d';

    private const array ENV_VARS = [
        'AI_PROVIDER',
        'AI_API_KEY',
        'AI_MODEL',
        'AI_MAX_TOKENS',
        'AI_REQUEST_TIMEOUT',
        'AI_TEMPERATURE',
        'AI_THINKING_LEVEL',
    ];

    /**
     * @var bool
     */
    protected $usesDatabase = false;

    /**
     * @var array<string, mixed>
     */
    private array $originalEnv = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_VARS as $name) {
            $this->originalEnv[$name] = Environment::getEnv($name);
            Environment::setEnv($name, null);
        }

        Environment::setEnv('AI_API_KEY', self::SECRET);
        $this->history = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            Environment::setEnv($name, $value);
        }

        parent::tearDown();
    }

    public function testRequestCarriesVersionAndKeyHeaders(): void
    {
        $provider = $this->provider([self::apiResponse([['type' => 'text', 'text' => 'Hi']])]);

        $provider->chat(self::request());

        $sent = $this->sentRequest();
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame(AnthropicProvider::ENDPOINT, (string) $sent->getUri());
        $this->assertSame(AnthropicProvider::API_VERSION, $sent->getHeaderLine('anthropic-version'));
        $this->assertSame(self::SECRET, $sent->getHeaderLine('x-api-key'));
        $this->assertSame('application/json', $sent->getHeaderLine('Content-Type'));
    }

    public function testModelMaxTokensAndSystemPrefixShaping(): void
    {
        $provider = $this->provider([self::apiResponse([['type' => 'text', 'text' => 'Hi']])]);
        $options = new ChatOptions('model-x', 512, 30, ChatOptions::DEFAULT_TEMPERATURE, ['Rules', 'Tool docs']);

        $provider->chat(self::request([], [], 'Current page: Home', $options));

        $payload = $this->sentPayload();
        $this->assertSame('model-x', $payload['model']);
        $this->assertSame(512, $payload['max_tokens']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'Rules'],
            ['type' => 'text', 'text' => 'Tool docs', 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => 'Current page: Home'],
        ], $payload['system']);
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertArrayNotHasKey('tool_choice', $payload);
    }

    public function testSystemIsOmittedWhenEmptyAndTemperatureSentWhenNotDefault(): void
    {
        $provider = $this->provider([self::apiResponse([['type' => 'text', 'text' => 'Hi']])]);

        $provider->chat(self::request([], [], '', new ChatOptions('m', 1, 1, 0.3)));

        $payload = $this->sentPayload();
        $this->assertArrayNotHasKey('system', $payload);
        $this->assertSame(0.3, $payload['temperature']);
    }

    public function testReasoningEffortIsSentAsOutputConfigUnlessNone(): void
    {
        $provider = $this->provider([
            self::apiResponse([['type' => 'text', 'text' => 'Hi']]),
            self::apiResponse([['type' => 'text', 'text' => 'Hi']]),
        ]);

        $provider->chat(self::request([], [], 'x', (new ChatOptions('m', 1, 1))->withReasoningEffort('high')));
        $this->assertSame(['effort' => 'high'], $this->sentPayload()['output_config']);

        $this->history = [];
        $provider->chat(self::request([], [], 'x', (new ChatOptions('m', 1, 1))->withReasoningEffort('none')));
        $this->assertArrayNotHasKey('output_config', $this->sentPayload());
    }

    public function testProviderDataFromOtherVendorsIsNotSent(): void
    {
        $provider = $this->provider([self::apiResponse([['type' => 'text', 'text' => 'Hi']])]);
        $messages = [
            ChatMessage::fromText(Role::User, 'Find pages'),
            new ChatMessage(Role::Assistant, [
                new ToolUseBlock('fc_1', 'records.search', ['q' => 'x'], ['gemini.thought_signature' => 'c2ln']),
            ]),
            ChatMessage::toolResults([new ToolResultBlock('fc_1', 'ok')]),
        ];

        $provider->chat(self::request($messages));

        $this->assertSame(
            ['type' => 'tool_use', 'id' => 'fc_1', 'name' => 'records__search', 'input' => ['q' => 'x']],
            $this->sentPayload()['messages'][1]['content'][0],
        );
    }

    public function testRolesAreMappedAndSystemMessagesNeverAppear(): void
    {
        $provider = $this->provider([self::apiResponse([['type' => 'text', 'text' => 'Hi']])]);
        $messages = [
            ChatMessage::fromText(Role::System, 'ignored'),
            ChatMessage::fromText(Role::User, 'Find pages'),
            new ChatMessage(Role::Assistant, [
                new TextBlock('Searching'),
                new ToolUseBlock('toolu_1', 'records.search', []),
            ]),
            ChatMessage::toolResults([
                new ToolResultBlock('toolu_1', '{"count":0}'),
                new ToolResultBlock('toolu_2', 'boom', true),
            ]),
            new ChatMessage(Role::Assistant, [new TextBlock('')]),
        ];

        $provider->chat(self::request($messages));

        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Find pages']]],
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Searching'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'records__search', 'input' => []],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"count":0}'],
                    ['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'content' => 'boom', 'is_error' => true],
                ],
            ],
        ], $this->sentPayload()['messages']);

        $this->assertStringContainsString('"input":{}', (string) $this->sentRequest()->getBody());
    }

    public function testToolsPayloadAndToolChoice(): void
    {
        $provider = $this->provider([self::apiResponse([['type' => 'text', 'text' => 'Hi']])]);
        $tools = [
            new ToolSchema('records.search', 'Find records', [
                'type' => 'object',
                'properties' => ['q' => ['type' => 'string']],
                'required' => ['q'],
            ]),
            new ToolSchema('records.count', 'Count records', ['type' => 'object', 'properties' => []]),
            new ToolSchema('noop', 'Does nothing', []),
        ];

        $provider->chat(self::request([], $tools));

        $payload = $this->sentPayload();
        $this->assertSame(['type' => 'auto'], $payload['tool_choice']);
        $this->assertSame('records__search', $payload['tools'][0]['name']);
        $this->assertSame('Find records', $payload['tools'][0]['description']);
        $this->assertSame(['q'], $payload['tools'][0]['input_schema']['required']);
        $this->assertSame(['type' => 'object'], $payload['tools'][2]['input_schema']);
        $this->assertStringContainsString('"properties":{}', (string) $this->sentRequest()->getBody());
    }

    public function testParsesTextOnlyResponse(): void
    {
        $provider = $this->provider([
            self::apiResponse(
                [['type' => 'text', 'text' => 'Hello '], ['type' => 'text', 'text' => 'there']],
                'end_turn',
                ['input_tokens' => 12, 'output_tokens' => 4, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 50],
                'msg_abc',
            ),
        ]);

        $response = $provider->chat(self::request());

        $this->assertSame(StopReason::EndTurn, $response->stopReason);
        $this->assertSame("Hello \n\nthere", $response->getText());
        $this->assertSame([], $response->getToolUses());
        $this->assertSame('msg_abc', $response->providerMessageId);
        $this->assertSame(12, $response->usage->inputTokens);
        $this->assertSame(4, $response->usage->outputTokens);
        $this->assertSame(900, $response->usage->cacheReadTokens);
        $this->assertSame(50, $response->usage->cacheWriteTokens);
        $this->assertSame(Role::Assistant, $response->message->role);
    }

    public function testParsesTextAndToolUse(): void
    {
        $provider = $this->provider([
            self::apiResponse([
                ['type' => 'text', 'text' => 'Looking'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'records__search', 'input' => ['q' => 'home']],
            ], 'tool_use'),
        ]);

        $response = $provider->chat(self::request());

        $this->assertSame(StopReason::ToolUse, $response->stopReason);
        $this->assertTrue($response->hasToolUses());
        $this->assertSame('Looking', $response->getText());
        $this->assertSame('toolu_1', $response->getToolUses()[0]->id);
        $this->assertSame('records.search', $response->getToolUses()[0]->name);
        $this->assertSame(['q' => 'home'], $response->getToolUses()[0]->input);
    }

    public function testParsesMultipleToolUsesAndIgnoresUnknownBlocks(): void
    {
        $provider = $this->provider([
            self::apiResponse([
                ['type' => 'thinking', 'thinking' => ''],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'records.get', 'input' => ['id' => 1]],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'records.get', 'input' => []],
            ], 'tool_use'),
        ]);

        $response = $provider->chat(self::request());

        $this->assertCount(2, $response->getToolUses());
        $this->assertCount(2, $response->message->blocks);
        $this->assertSame([], $response->getToolUses()[1]->input);
    }

    public function testMaxTokensAndUnknownStopReasons(): void
    {
        $provider = $this->provider([
            self::apiResponse([['type' => 'text', 'text' => 'cut']], 'max_tokens'),
            self::apiResponse([['type' => 'text', 'text' => 'no']], 'refusal'),
        ]);

        $this->assertSame(StopReason::MaxTokens, $provider->chat(self::request())->stopReason);
        $this->assertSame(StopReason::Other, $provider->chat(self::request())->stopReason);
    }

    public function testUnauthorisedAndForbiddenAreBlocking(): void
    {
        foreach ([401, 403] as $status) {
            $exception = $this->chatExpectingFailure([self::errorResponse($status, 'bad key')]);

            $this->assertTrue($exception->isBlocking(), "HTTP $status");
            $this->assertFalse($exception->isTransient(), "HTTP $status");
            $this->assertSame($status, $exception->getCode());
        }
    }

    public function testRateLimitAndServerErrorsAreTransient(): void
    {
        foreach ([429, 500, 503, 529] as $status) {
            $exception = $this->chatExpectingFailure([self::errorResponse($status, 'later')]);

            $this->assertTrue($exception->isTransient(), "HTTP $status");
            $this->assertFalse($exception->isBlocking(), "HTTP $status");
            $this->assertStringContainsString((string) $status, $exception->getMessage());
        }
    }

    public function testRetryAfterHeaderIsCarriedOnTheException(): void
    {
        $seconds = self::errorResponse(429, 'slow down')->withHeader('retry-after', '15');
        $date = self::errorResponse(529, 'overloaded')
            ->withHeader('retry-after', gmdate('D, d M Y H:i:s \\G\\M\\T', time() + 120));
        $none = self::errorResponse(429, 'slow down');

        $this->assertSame(15, $this->chatExpectingFailure([$seconds])->getRetryAfterSeconds());
        $this->assertEqualsWithDelta(120, $this->chatExpectingFailure([$date])->getRetryAfterSeconds(), 2);
        $this->assertNull($this->chatExpectingFailure([$none])->getRetryAfterSeconds());
        $this->assertFalse($this->chatExpectingFailure([$seconds])->isDailyQuotaExhausted());
    }

    public function testBadRequestIsNeitherTransientNorBlocking(): void
    {
        $exception = $this->chatExpectingFailure([self::errorResponse(400, 'messages: roles must alternate')]);

        $this->assertFalse($exception->isTransient());
        $this->assertFalse($exception->isBlocking());
        $this->assertSame(400, $exception->getCode());
    }

    public function testConnectTimeoutIsTransient(): void
    {
        $exception = $this->chatExpectingFailure([
            new ConnectException(
                'cURL error 28: Operation timed out',
                new Request('POST', AnthropicProvider::ENDPOINT),
            ),
        ]);

        $this->assertTrue($exception->isTransient());
        $this->assertFalse($exception->isBlocking());
        $this->assertInstanceOf(ConnectException::class, $exception->getPrevious());
    }

    public function testMalformedJsonBodyThrows(): void
    {
        $exception = $this->chatExpectingFailure([new Response(200, [], '{"content": [')]);

        $this->assertFalse($exception->isTransient());
        $this->assertStringContainsString('JSON', $exception->getMessage());

        $exception = $this->chatExpectingFailure([new Response(200, [], '{"id": "msg_1"}')]);

        $this->assertStringContainsString('content', $exception->getMessage());
    }

    public function testMissingApiKeyIsBlockingAndNoRequestIsSent(): void
    {
        Environment::setEnv('AI_API_KEY', null);

        $exception = $this->chatExpectingFailure([self::apiResponse([])]);

        $this->assertTrue($exception->isBlocking());
        $this->assertStringContainsString('AI_API_KEY', $exception->getMessage());
        $this->assertSame([], $this->history);
    }

    public function testApiKeyNeverAppearsInExceptionMessages(): void
    {
        $bodyLeakingKey = sprintf('Invalid key %s supplied', self::SECRET);
        $failures = [
            [self::errorResponse(401, $bodyLeakingKey)],
            [self::errorResponse(500, $bodyLeakingKey)],
            [new Response(200, [], self::SECRET)],
        ];

        foreach ($failures as $queue) {
            $exception = $this->chatExpectingFailure($queue);

            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET, (string) $exception);
        }
    }

    public function testTransportErrorDetailIsRedactedInTheProviderMessage(): void
    {
        $exception = $this->chatExpectingFailure([
            new ConnectException(
                'Could not resolve host ' . self::SECRET,
                new Request('POST', AnthropicProvider::ENDPOINT),
            ),
        ]);

        $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        $this->assertStringContainsString('[redacted]', $exception->getMessage());
    }

    public function testErrorDetailIsIncludedOnlyInDevMode(): void
    {
        $kernel = Injector::inst()->get(Kernel::class);
        $original = $kernel->getEnvironment();

        try {
            $kernel->setEnvironment(Kernel::DEV);
            $exception = $this->chatExpectingFailure([self::errorResponse(400, 'max_tokens: must be positive')]);
            $this->assertStringContainsString('max_tokens: must be positive', $exception->getMessage());

            $kernel->setEnvironment(Kernel::LIVE);
            $exception = $this->chatExpectingFailure([self::errorResponse(400, 'max_tokens: must be positive')]);
            $this->assertStringNotContainsString('max_tokens', $exception->getMessage());
            $this->assertStringContainsString('400', $exception->getMessage());
        } finally {
            $kernel->setEnvironment($original);
        }
    }

    public function testDefaultOptionsFallBackToProviderDefaults(): void
    {
        $provider = new AnthropicProvider();

        $options = $provider->getDefaultOptions();

        $this->assertSame(AnthropicProvider::DEFAULT_MODEL, $options->model);
        $this->assertSame(AnthropicProvider::DEFAULT_MAX_TOKENS, $options->maxTokens);
        $this->assertSame(90, $options->timeoutSeconds);
        $this->assertSame([], $options->cacheableSystemPrefix);
        $this->assertSame('anthropic', $provider->getName());
    }

    public function testDefaultOptionsHonourConfiguration(): void
    {
        Environment::setEnv('AI_MODEL', 'claude-custom');
        Environment::setEnv('AI_MAX_TOKENS', '2048');
        Config::modify()->merge(EnvProviderSettings::class, 'shared', ['request_timeout' => 45]);

        $options = (new AnthropicProvider())->getDefaultOptions();

        $this->assertSame('claude-custom', $options->model);
        $this->assertSame(2048, $options->maxTokens);
        $this->assertSame(45, $options->timeoutSeconds);
    }

    public function testRequestTimeoutComesFromTheOptions(): void
    {
        $provider = $this->provider([self::apiResponse([['type' => 'text', 'text' => 'Hi']])]);

        $provider->chat(self::request([], [], 'x', new ChatOptions('m', 1, 7)));

        $this->assertSame(7, $this->history[0]['options']['timeout']);
        $this->assertSame(7, $this->history[0]['options']['connect_timeout']);
        $this->assertFalse($this->history[0]['options']['http_errors']);
    }

    /**
     * @param array<int, mixed> $queue
     */
    private function chatExpectingFailure(array $queue): ProviderException
    {
        $this->history = [];

        try {
            $this->provider($queue)->chat(self::request());
        } catch (ProviderException $exception) {
            return $exception;
        }

        $this->fail('Expected a ProviderException');
    }

    /**
     * @param array<int, mixed> $queue Responses or exceptions for the Guzzle MockHandler
     */
    private function provider(array $queue): AnthropicProvider
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new AnthropicProvider(new Client(['handler' => $stack]));
    }

    private function sentRequest(): RequestInterface
    {
        $this->assertCount(1, $this->history);

        return $this->history[0]['request'];
    }

    /**
     * @return array<string, mixed>
     */
    private function sentPayload(): array
    {
        return json_decode((string) $this->sentRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, ChatMessage> $messages
     * @param array<int, ToolSchema> $tools
     */
    private static function request(
        array $messages = [],
        array $tools = [],
        string $system = 'Be brief.',
        ?ChatOptions $options = null,
    ): ChatRequest {
        return new ChatRequest(
            $system,
            $messages === []
                ? [ChatMessage::fromText(Role::User, 'Hello')]
                : $messages,
            $tools,
            $options ?? new ChatOptions('model-x', 256, 10),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $content
     * @param array<string, int> $usage
     */
    private static function apiResponse(
        array $content,
        string $stopReason = 'end_turn',
        array $usage = [],
        string $id = 'msg_test',
    ): Response {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => $id,
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'model-x',
            'content' => $content,
            'stop_reason' => $stopReason,
            'usage' => $usage === []
                ? ['input_tokens' => 1, 'output_tokens' => 1]
                : $usage,
        ], JSON_THROW_ON_ERROR));
    }

    private static function errorResponse(int $status, string $message): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'type' => 'error',
            'error' => ['type' => 'error', 'message' => $message],
        ], JSON_THROW_ON_ERROR));
    }
}
