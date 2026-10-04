<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Conformance;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Provider\ChatProviderInterface;
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
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ToolNameCodec;

/**
 * The behaviour every ChatProviderInterface implementation must share, so the agent loop can
 * run on any of them unchanged.
 *
 * Each concrete subclass points the suite at its fixture directory (vendor response bodies
 * under fixtures/<provider>/, all describing the same conversation) and reads its own wire
 * format back through the payload hooks. The expectations below are deliberately identical
 * for every provider: the same fixture name must parse to the same neutral ChatResponse, and
 * the same ChatRequest must put the same information on the wire.
 *
 * Providers without HTTP (the scripted fake) report usesHttp() false; the transport and
 * credential scenarios are then skipped and the fixtures are ChatResponse arrays.
 *
 * Autoloaded with the package so a provider shipped elsewhere can extend it and run the same
 * scenarios against its own fixtures (see getFixtureRoot()). Credentials are read from the
 * shared AI_* variables through the default ProviderSettingsInterface service.
 */
abstract class ProviderConformanceTestCase extends SapphireTest
{
    protected const string SECRET = 'sk-conformance-secret-7f3a9c2e';

    protected const string TEXT_REPLY = 'Hello! How can I help with your content today?';
    protected const string TOOL_TEXT = 'Let me search for that.';
    protected const string MAX_TOKENS_TEXT = 'The summary of the page is';
    protected const string UNICODE_TEXT = 'Kia ora! Ngā mihi 👋 — 日本語のテキスト — مرحبا بالعالم — café ½ “quotes”';
    protected const array SEARCH_INPUT = ['class' => 'Page', 'query' => 'home', 'limit' => 5];
    protected const array PARALLEL_CALLS = [
        ['records.search', ['class' => 'Page', 'query' => 'news']],
        ['schema.describe', ['class' => 'Page']],
    ];

    protected const string PREFIX_RULES = 'Static rules: never invent record ids.';
    protected const string PREFIX_TOOLS = 'Tool guide: search before you read.';
    protected const string SYSTEM_TEXT = 'Current page: Home (SiteTree #1).';
    protected const string IMAGE_PROMPT = 'Describe this image for alternative text.';
    protected const string IMAGE_DESCRIPTION = 'A red square centred on a white background.';

    /**
     * A 1x1 red PNG and a 1x1 white GIF, as base64.
     */
    protected const string IMAGE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';
    protected const string IMAGE_GIF = 'R0lGODlhAQABAIAAAP///wAAACwAAAAAAQABAAACAkQBADs=';

    protected const string MODEL = 'conformance-model';
    protected const int MAX_TOKENS = 256;
    protected const int LARGE_RESULT_CHARS = 200000;

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
     * @var array<int, array<string, mixed>>
     */
    protected array $history = [];

    /**
     * @var array<string, mixed>
     */
    private array $originalEnv = [];

    /**
     * Directory name under the fixture root, which is also the provider name.
     */
    abstract protected function getFixtureDirectory(): string;

    /**
     * Directory holding one sub directory of fixtures per provider. A provider shipped
     * outside this package overrides it to point at its own recorded bodies.
     */
    protected function getFixtureRoot(): string
    {
        return __DIR__ . '/fixtures';
    }

    abstract protected function createProvider(ClientInterface $client): ChatProviderInterface;

    abstract protected function getExpectedDefaultModel(): string;

    /**
     * All system prompt text in the order sent, joined by blank lines.
     *
     * @param array<string, mixed> $payload
     */
    abstract protected function systemText(array $payload): string;

    /**
     * Every text the request carries outside the system slot, in order.
     *
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    abstract protected function conversationTexts(array $payload): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{name: string, schema: array<string, mixed>}>
     */
    abstract protected function wireTools(array $payload): array;

    /**
     * Assistant tool calls replayed in the request, in order.
     *
     * @param array<string, mixed> $payload
     * @return array<int, array{ref: string, name: string, input: array<string, mixed>}>
     */
    abstract protected function wireToolCalls(array $payload): array;

    /**
     * Tool results in the request, in order. is_error is null when the vendor has no flag.
     *
     * @param array<string, mixed> $payload
     * @return array<int, array{ref: string, content: string, is_error: bool|null}>
     */
    abstract protected function wireToolResults(array $payload): array;

    /**
     * Images the request carries, in order, as media type and base64 data. Providers that
     * take image input override this; the default skips the image scenarios so a provider
     * shipped elsewhere keeps passing until it declares support.
     *
     * @param array<string, mixed> $payload
     * @return array<int, array{media_type: string, data: string}>
     */
    protected function wireImages(array $payload): array
    {
        $this->markTestSkipped('This provider does not declare image input support.');
    }

    /**
     * The value the vendor uses to pair a result with its call (an id, or a name).
     */
    abstract protected function correlationRef(ToolUseBlock $use): string;

    /**
     * @param array<string, mixed> $payload
     */
    abstract protected function sentModel(RequestInterface $request, array $payload): string;

    /**
     * @param array<string, mixed> $payload
     */
    abstract protected function sentMaxTokens(array $payload): int;

    abstract protected function assertAuthenticated(RequestInterface $request, string $apiKey): void;

    /**
     * Whether usage reports cache writes (Anthropic, OpenAI) or only cache reads (Gemini).
     */
    protected function reportsCacheWrites(): bool
    {
        return true;
    }

    /**
     * Checks the wire form of a request that asked for conversation caching. Providers that
     * cache implicitly (OpenAI, Gemini) send nothing extra, so by default there is nothing to
     * check beyond the conversation arriving intact.
     *
     * @param array<string, mixed> $payload
     */
    protected function assertConversationCacheRequested(array $payload): void
    {
        $this->assertArrayNotHasKey('cache_control', $payload);
    }

    protected function usesHttp(): bool
    {
        return true;
    }

    /**
     * The tool name as it appears on the wire.
     */
    protected function wireName(string $name): string
    {
        return ToolNameCodec::toWire($name);
    }

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

    public function testTextReply(): void
    {
        $response = $this->chatWithFixtures(self::request(), 'text_reply');

        $this->assertSame(self::TEXT_REPLY, $response->getText());
        $this->assertSame(StopReason::EndTurn, $response->stopReason);
        $this->assertSame(Role::Assistant, $response->message->role);
        $this->assertFalse($response->hasToolUses());
        $this->assertNotSame('', $response->providerMessageId);
    }

    public function testSingleToolCall(): void
    {
        $response = $this->chatWithFixtures(self::request(), 'single_tool_call');

        $this->assertSame(StopReason::ToolUse, $response->stopReason);
        $this->assertSame(self::TOOL_TEXT, $response->getText());
        $this->assertInstanceOf(TextBlock::class, $response->message->blocks[0]);
        $this->assertCount(1, $response->getToolUses());

        $use = $response->getToolUses()[0];
        $this->assertSame('records.search', $use->name);
        $this->assertSame(self::SEARCH_INPUT, $use->input);
        $this->assertNotSame('', $use->id);
    }

    public function testParallelToolCallsKeepTheirOrderAndStableDistinctIds(): void
    {
        $first = $this->chatWithFixtures(self::request(), 'parallel_tool_calls');
        $second = $this->chatWithFixtures(self::request(), 'parallel_tool_calls');

        $this->assertSame(StopReason::ToolUse, $first->stopReason);
        $this->assertSame(self::TOOL_TEXT, $first->getText());
        $uses = $first->getToolUses();
        $this->assertCount(count(self::PARALLEL_CALLS), $uses);

        foreach (self::PARALLEL_CALLS as $index => [$name, $input]) {
            $this->assertSame($name, $uses[$index]->name);
            $this->assertSame($input, $uses[$index]->input);
            $this->assertSame($uses[$index]->id, $second->getToolUses()[$index]->id, 'ids are stable');
        }

        $this->assertNotSame($uses[0]->id, $uses[1]->id);
    }

    /**
     * The request after a parallel tool call must replay both calls and carry both results,
     * each paired with its call, in order.
     */
    public function testToolResultRoundTripCarriesEveryResultWithItsCall(): void
    {
        $toolTurn = $this->chatWithFixtures(self::request(), 'parallel_tool_calls');
        [$search, $describe] = $toolTurn->getToolUses();
        $messages = [
            ChatMessage::fromText(Role::User, 'Find the news pages and describe the Page class.'),
            $toolTurn->message,
            ChatMessage::toolResults([
                new ToolResultBlock($search->id, '{"status":"ok","data":{"count":2}}'),
                new ToolResultBlock($describe->id, '{"status":"error","code":"unknown_class"}', true),
            ]),
        ];

        $this->chatWithFixtures(self::request($messages), 'text_reply');
        $payload = $this->sentPayload();

        $this->assertSame([
            ['ref' => $this->correlationRef($search), 'name' => $this->wireName(
                'records.search',
            ), 'input' => $search->input],
            [
                'ref' => $this->correlationRef($describe),
                'name' => $this->wireName('schema.describe'),
                'input' => $describe->input,
            ],
        ], $this->wireToolCalls($payload));

        $results = $this->wireToolResults($payload);
        $this->assertCount(2, $results);
        $this->assertSame($this->correlationRef($search), $results[0]['ref']);
        $this->assertSame('{"status":"ok","data":{"count":2}}', $results[0]['content']);
        $this->assertSame($this->correlationRef($describe), $results[1]['ref']);
        $this->assertSame('{"status":"error","code":"unknown_class"}', $results[1]['content']);

        if ($results[0]['is_error'] !== null) {
            $this->assertFalse($results[0]['is_error']);
            $this->assertTrue($results[1]['is_error']);
        }

        $this->assertContains(self::TOOL_TEXT, $this->conversationTexts($payload));
    }

    /**
     * A user message holding a prompt and an image reaches the model with both, the image as
     * the vendor's base64 form, and the reply parses as usual.
     */
    public function testImageInputIsSentWithItsPrompt(): void
    {
        $request = self::request([
            new ChatMessage(Role::User, [
                new TextBlock(self::IMAGE_PROMPT),
                new ImageBlock(ImageBlock::MEDIA_PNG, self::IMAGE_PNG),
            ]),
        ]);

        $response = $this->chatWithFixtures($request, 'image_description');
        $payload = $this->sentPayload();

        $this->assertSame(self::IMAGE_DESCRIPTION, $response->getText());
        $this->assertSame(StopReason::EndTurn, $response->stopReason);
        $this->assertSame(
            [['media_type' => ImageBlock::MEDIA_PNG, 'data' => self::IMAGE_PNG]],
            $this->wireImages($payload),
        );
        $this->assertSame([self::IMAGE_PROMPT], $this->conversationTexts($payload));
    }

    /**
     * Several images keep their order and type; an image in an assistant message, which no
     * vendor accepts, is left out rather than failing the request.
     */
    public function testImagesKeepTheirOrderAndAssistantImagesAreDropped(): void
    {
        $request = self::request([
            new ChatMessage(Role::User, [
                new TextBlock('Compare these two images.'),
                new ImageBlock(ImageBlock::MEDIA_PNG, self::IMAGE_PNG),
                new ImageBlock(ImageBlock::MEDIA_GIF, self::IMAGE_GIF),
            ]),
            new ChatMessage(Role::Assistant, [
                new TextBlock('The first is red.'),
                new ImageBlock(ImageBlock::MEDIA_GIF, self::IMAGE_GIF),
            ]),
            ChatMessage::fromText(Role::User, 'And the second?'),
        ]);

        $this->chatWithFixtures($request, 'image_description');
        $payload = $this->sentPayload();
        $expected = [
            ['media_type' => ImageBlock::MEDIA_PNG, 'data' => self::IMAGE_PNG],
            ['media_type' => ImageBlock::MEDIA_GIF, 'data' => self::IMAGE_GIF],
        ];

        if (!$this->usesHttp()) {
            $expected[] = ['media_type' => ImageBlock::MEDIA_GIF, 'data' => self::IMAGE_GIF];
        }

        $this->assertSame($expected, $this->wireImages($payload));
        $this->assertSame(
            ['Compare these two images.', 'The first is red.', 'And the second?'],
            $this->conversationTexts($payload),
        );
    }

    public function testMaxTokensStop(): void
    {
        $response = $this->chatWithFixtures(self::request(), 'max_tokens');

        $this->assertSame(StopReason::MaxTokens, $response->stopReason);
        $this->assertSame(self::MAX_TOKENS_TEXT, $response->getText());
    }

    public function testVendorSpecificStopReasonsBecomeOther(): void
    {
        $response = $this->chatWithFixtures(self::request(), 'refusal');

        $this->assertSame(StopReason::Other, $response->stopReason);
        $this->assertFalse($response->hasToolUses());
    }

    public function testUsageIsNormalisedWithCacheFields(): void
    {
        $usage = $this->chatWithFixtures(self::request(), 'usage')->usage;
        $cacheWrites = $this->reportsCacheWrites()
            ? 50
            : 0;

        $this->assertSame(100, $usage->inputTokens, 'uncached input');
        $this->assertSame(20, $usage->outputTokens, 'output including reasoning');
        $this->assertSame(900, $usage->cacheReadTokens);
        $this->assertSame($cacheWrites, $usage->cacheWriteTokens);
        $this->assertSame(1000 + $cacheWrites, $usage->getTotalInputTokens());
    }

    public function testUnicodeSurvivesBothDirections(): void
    {
        $request = self::request([ChatMessage::fromText(Role::User, self::UNICODE_TEXT)]);

        $response = $this->chatWithFixtures($request, 'unicode');

        $this->assertSame(self::UNICODE_TEXT, $response->getText());
        $this->assertContains(self::UNICODE_TEXT, $this->conversationTexts($this->sentPayload()));

        if (!$this->usesHttp()) {
            return;
        }

        $this->assertStringContainsString('日本語のテキスト', (string) $this->sentRequest()->getBody());
    }

    public function testLargeToolResultIsSentIntact(): void
    {
        $use = new ToolUseBlock('toolu_large_1', 'records.get', ['class' => 'Page', 'id' => 1]);
        $content = self::largeResult();
        $messages = [
            ChatMessage::fromText(Role::User, 'Read page 1.'),
            new ChatMessage(Role::Assistant, [$use]),
            ChatMessage::toolResults([new ToolResultBlock($use->id, $content)]),
        ];

        $this->chatWithFixtures(self::request($messages), 'text_reply');

        $results = $this->wireToolResults($this->sentPayload());
        $this->assertCount(1, $results);
        $this->assertSame($content, $results[0]['content']);
    }

    public function testNestedSchemaWithEnumsAndArraysReachesTheModel(): void
    {
        $this->chatWithFixtures(self::request([], self::tools()), 'text_reply');

        $tools = $this->wireTools($this->sentPayload());
        $this->assertSame(
            [$this->wireName('records.search'), $this->wireName('records.count'), $this->wireName('noop')],
            array_column($tools, 'name'),
        );

        foreach ($tools as $tool) {
            if (!$this->usesHttp()) {
                continue;
            }

            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $tool['name']);
        }

        $schema = $tools[0]['schema'];
        $filter = $schema['properties']['filters']['items'];
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['class'], $schema['required']);
        $this->assertSame('array', $schema['properties']['filters']['type']);
        $this->assertSame(['eq', 'contains', 'gt'], $filter['properties']['operator']['enum']);
        $this->assertSame(['field', 'operator', 'value'], $filter['required']);
        $this->assertSame(['ASC', 'DESC'], $schema['properties']['sort']['properties']['dir']['enum']);
        $this->assertSame('string', $schema['properties']['fields']['items']['type']);
        $this->assertSame('object', $tools[2]['schema']['type']);

        if (!$this->usesHttp()) {
            return;
        }

        $this->assertStringContainsString('"properties":{}', (string) $this->sentRequest()->getBody());
    }

    public function testSystemPromptPlacement(): void
    {
        $messages = [
            ChatMessage::fromText(Role::System, 'A stray system message that must never be sent.'),
            ChatMessage::fromText(Role::User, 'Hello'),
        ];

        $this->chatWithFixtures(self::request($messages), 'text_reply');
        $payload = $this->sentPayload();
        $system = $this->systemText($payload);

        $this->assertSame(
            implode("\n\n", [self::PREFIX_RULES, self::PREFIX_TOOLS, self::SYSTEM_TEXT]),
            $system,
            'cacheable prefix first, in order, then the per request system prompt',
        );
        $this->assertSame(['Hello'], $this->conversationTexts($payload));

        if (!$this->usesHttp()) {
            return;
        }

        $this->assertStringNotContainsString('stray system message', (string) $this->sentRequest()->getBody());
    }

    public function testConversationCachingLeavesTheConversationIntact(): void
    {
        $messages = [
            ChatMessage::fromText(Role::User, 'Hello'),
            ChatMessage::fromText(Role::Assistant, 'Hi there'),
            ChatMessage::fromText(Role::User, 'Find the home page'),
        ];
        $base = self::request($messages);
        $cached = new ChatRequest(
            $base->system,
            $base->messages,
            $base->tools,
            $base->options->withConversationCache(),
        );

        $response = $this->chatWithFixtures($cached, 'text_reply');
        $payload = $this->sentPayload();

        $this->assertSame(self::TEXT_REPLY, $response->getText());
        $this->assertSame(['Hello', 'Hi there', 'Find the home page'], $this->conversationTexts($payload));
        $this->assertSame(
            implode("\n\n", [self::PREFIX_RULES, self::PREFIX_TOOLS, self::SYSTEM_TEXT]),
            $this->systemText($payload),
        );
        $this->assertConversationCacheRequested($payload);
    }

    public function testModelAndMaxTokensAreSent(): void
    {
        $this->chatWithFixtures(self::request(), 'text_reply');
        $payload = $this->sentPayload();

        $this->assertSame(
            self::MODEL,
            $this->sentModel($this->usesHttp() ? $this->sentRequest() : new Request('POST', '/'), $payload),
        );
        $this->assertSame(self::MAX_TOKENS, $this->sentMaxTokens($payload));
    }

    public function testDefaultOptions(): void
    {
        $provider = $this->createProvider(new Client());
        $options = $provider->getDefaultOptions();

        $this->assertSame($this->getFixtureDirectory(), $provider->getName());
        $this->assertSame($this->getExpectedDefaultModel(), $options->model);
        $this->assertGreaterThan(0, $options->maxTokens);
        $this->assertGreaterThan(0, $options->timeoutSeconds);
    }

    public function testAuthenticationHeaderIsSentAndTheKeyIsNeverInTheUrl(): void
    {
        $this->skipUnlessHttp();

        $this->chatWithFixtures(self::request(), 'text_reply');

        $this->assertAuthenticated($this->sentRequest(), self::SECRET);
        $this->assertStringNotContainsString(self::SECRET, (string) $this->sentRequest()->getUri());
        $this->assertStringNotContainsString(self::SECRET, (string) $this->sentRequest()->getBody());
    }

    public function testUnauthorisedAndForbiddenAreBlocking(): void
    {
        $this->skipUnlessHttp();

        foreach ([401, 403] as $status) {
            $exception = $this->chatExpectingFailure([$this->fixtureResponse('error_' . $status, $status)]);

            $this->assertTrue($exception->isBlocking(), "HTTP $status");
            $this->assertFalse($exception->isTransient(), "HTTP $status");
            $this->assertSame($status, $exception->getCode());
        }
    }

    public function testRateLimitAndServerErrorsAreTransient(): void
    {
        $this->skipUnlessHttp();

        foreach ([429, 500, 503] as $status) {
            $exception = $this->chatExpectingFailure([$this->fixtureResponse('error_' . $status, $status)]);

            $this->assertTrue($exception->isTransient(), "HTTP $status");
            $this->assertFalse($exception->isBlocking(), "HTTP $status");
            $this->assertStringContainsString((string) $status, $exception->getMessage());
        }
    }

    public function testBadRequestIsPermanent(): void
    {
        $this->skipUnlessHttp();

        $exception = $this->chatExpectingFailure([$this->fixtureResponse('error_400', 400)]);

        $this->assertFalse($exception->isTransient());
        $this->assertFalse($exception->isBlocking());
        $this->assertSame(400, $exception->getCode());
    }

    public function testNetworkFailureIsTransient(): void
    {
        $this->skipUnlessHttp();

        $exception = $this->chatExpectingFailure([
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://example.test')),
        ]);

        $this->assertTrue($exception->isTransient());
        $this->assertFalse($exception->isBlocking());
        $this->assertInstanceOf(ConnectException::class, $exception->getPrevious());
    }

    public function testMalformedBodiesFailPermanently(): void
    {
        $this->skipUnlessHttp();

        foreach (['{"broken": ', '"a string"', '{"unexpected": true}'] as $body) {
            $exception = $this->chatExpectingFailure([new Response(200, [], $body)]);

            $this->assertFalse($exception->isTransient(), $body);
            $this->assertFalse($exception->isBlocking(), $body);
        }
    }

    public function testMissingApiKeyIsBlockingAndNothingIsSent(): void
    {
        $this->skipUnlessHttp();
        Environment::setEnv('AI_API_KEY', null);

        $exception = $this->chatExpectingFailure([$this->fixtureResponse('text_reply')]);

        $this->assertTrue($exception->isBlocking());
        $this->assertStringContainsString('AI_API_KEY', $exception->getMessage());
        $this->assertSame([], $this->history);
    }

    /**
     * Even in dev mode, where the vendor's error text is appended, the key is redacted from
     * every failure path: error bodies that echo it, transport messages that quote it, and
     * bodies that are not JSON at all. A transport exception is kept as the previous exception
     * for diagnosis; it cannot hold the key in practice because the key never appears in the
     * URL, so only the provider's own message is checked for that case.
     */
    public function testApiKeyIsNeverPresentInAnyExceptionMessage(): void
    {
        $this->skipUnlessHttp();
        $kernel = Injector::inst()->get(Kernel::class);
        $original = $kernel->getEnvironment();
        $echo = json_encode(
            ['error' => ['message' => 'Invalid key ' . self::SECRET . ' supplied']],
            JSON_THROW_ON_ERROR,
        );
        $failures = [
            new Response(401, [], $echo),
            new Response(429, [], $echo),
            new Response(500, [], $echo),
            new Response(400, [], $echo),
            new Response(200, [], self::SECRET),
        ];
        $transport = new ConnectException(
            'Could not resolve host for ' . self::SECRET,
            new Request('POST', 'https://example.test'),
        );

        try {
            foreach ([Kernel::DEV, Kernel::LIVE] as $mode) {
                $kernel->setEnvironment($mode);

                foreach ($failures as $failure) {
                    $exception = $this->chatExpectingFailure([$failure]);

                    $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
                    $this->assertStringNotContainsString(self::SECRET, (string) $exception);
                }

                $this->assertStringNotContainsString(
                    self::SECRET,
                    $this->chatExpectingFailure([$transport])->getMessage(),
                );
            }
        } finally {
            $kernel->setEnvironment($original);
        }
    }

    /**
     * Sends the request with the named fixtures queued as the vendor's responses.
     */
    protected function chatWithFixtures(ChatRequest $request, string ...$fixtures): ChatResponse
    {
        return $this->providerWithQueue(array_map(
            fn (string $name): Response => $this->fixtureResponse($name),
            $fixtures,
        ))->chat($request);
    }

    /**
     * The decoded body of the most recent request.
     *
     * @return array<string, mixed>
     */
    protected function sentPayload(): array
    {
        return json_decode((string) $this->sentRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function sentRequest(): RequestInterface
    {
        $this->assertNotEmpty($this->history, 'No HTTP request was sent');

        return $this->history[count($this->history) - 1]['request'];
    }

    /**
     * @param array<int, mixed> $queue Responses or exceptions for the Guzzle MockHandler
     */
    protected function providerWithQueue(array $queue): ChatProviderInterface
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return $this->createProvider(new Client(['handler' => $stack]));
    }

    protected function fixtureResponse(string $name, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $this->fixtureBody($name));
    }

    protected function fixtureBody(string $name): string
    {
        $path = sprintf('%s/%s/%s.json', $this->getFixtureRoot(), $this->getFixtureDirectory(), $name);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @param array<int, mixed> $queue
     */
    protected function chatExpectingFailure(array $queue): ProviderException
    {
        $this->history = [];

        try {
            $this->providerWithQueue($queue)->chat(self::request());
        } catch (ProviderException $exception) {
            return $exception;
        }

        $this->fail('Expected a ProviderException');
    }

    protected function skipUnlessHttp(): void
    {
        if ($this->usesHttp()) {
            return;
        }

        $this->markTestSkipped('Transport and credential behaviour only applies to HTTP providers.');
    }

    /**
     * @param array<int, ChatMessage> $messages
     * @param array<int, ToolSchema> $tools
     */
    protected static function request(array $messages = [], array $tools = []): ChatRequest
    {
        return new ChatRequest(
            self::SYSTEM_TEXT,
            $messages === []
                ? [ChatMessage::fromText(Role::User, 'Hello')]
                : $messages,
            $tools,
            new ChatOptions(
                self::MODEL,
                self::MAX_TOKENS,
                10,
                ChatOptions::DEFAULT_TEMPERATURE,
                [self::PREFIX_RULES, self::PREFIX_TOOLS],
            ),
        );
    }

    /**
     * A search tool with nested objects, enums, arrays and a free form map, a tool with no
     * properties and a tool with no schema at all.
     *
     * @return array<int, ToolSchema>
     */
    protected static function tools(): array
    {
        return [
            new ToolSchema('records.search', 'Find records', [
                'type' => 'object',
                'properties' => [
                    'class' => ['type' => 'string', 'description' => 'Content class'],
                    'filters' => [
                        'type' => 'array',
                        'maxItems' => 10,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'field' => ['type' => 'string'],
                                'operator' => ['type' => 'string', 'enum' => ['eq', 'contains', 'gt']],
                                'value' => ['type' => ['string', 'integer', 'boolean', 'null']],
                            ],
                            'required' => ['field', 'operator', 'value'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'sort' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'dir' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
                        ],
                        'additionalProperties' => false,
                    ],
                    'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ],
                'required' => ['class'],
                'additionalProperties' => false,
            ]),
            new ToolSchema('records.count', 'Count records', ['type' => 'object', 'properties' => []]),
            new ToolSchema('noop', 'Does nothing', []),
        ];
    }

    /**
     * A result far over any tool limit, with multi byte characters throughout.
     */
    protected static function largeResult(): string
    {
        $row = '{"id":1,"title":"Ngā mihi — 日本語","content":"' . str_repeat('lorem ipsum ', 20) . '"},';

        return '{"status":"ok","data":[' . substr(
            str_repeat($row, intdiv(self::LARGE_RESULT_CHARS, strlen($row)) + 1),
            0,
            -1,
        ) . ']}';
    }
}
