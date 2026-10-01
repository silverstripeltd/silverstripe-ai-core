<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Provider\ChatProviderInterface;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\ToolSchema;
use SilverstripeLtd\AiCore\Provider\ProviderException;

/**
 * Shared plumbing for the vendor specific provider tests: a Guzzle MockHandler with request
 * history, the conformance fixtures, and a clean provider environment.
 */
abstract class HttpProviderTestCase extends SapphireTest
{
    protected const string SECRET = 'sk-vendor-test-secret-91ab';

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

    abstract protected function createProvider(ClientInterface $client): ChatProviderInterface;

    /**
     * Directory under Conformance/fixtures holding this vendor's response bodies.
     */
    abstract protected function getFixtureDirectory(): string;

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

    /**
     * @param array<int, mixed> $queue Responses or exceptions for the Guzzle MockHandler
     */
    protected function provider(array $queue): ChatProviderInterface
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return $this->createProvider(new Client(['handler' => $stack]));
    }

    protected function fixture(string $name, int $status = 200): Response
    {
        $path = sprintf('%s/../Conformance/fixtures/%s/%s.json', __DIR__, $this->getFixtureDirectory(), $name);

        return new Response($status, ['Content-Type' => 'application/json'], (string) file_get_contents($path));
    }

    /**
     * @param array<string, mixed> $body
     */
    protected static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    protected function sentRequest(): RequestInterface
    {
        $this->assertNotEmpty($this->history, 'No HTTP request was sent');

        return $this->history[count($this->history) - 1]['request'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentPayload(): array
    {
        return json_decode((string) $this->sentRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, mixed> $queue
     */
    protected function chatExpectingFailure(array $queue): ProviderException
    {
        try {
            $this->provider($queue)->chat(self::request());
        } catch (ProviderException $exception) {
            return $exception;
        }

        $this->fail('Expected a ProviderException');
    }

    /**
     * @param array<int, ChatMessage> $messages
     * @param array<int, ToolSchema> $tools
     */
    protected static function request(
        array $messages = [],
        array $tools = [],
        ?ChatOptions $options = null,
    ): ChatRequest {
        return new ChatRequest(
            'Be brief.',
            $messages === []
                ? [ChatMessage::fromText(Role::User, 'Hello')]
                : $messages,
            $tools,
            $options ?? new ChatOptions('model-x', 256, 10),
        );
    }
}
