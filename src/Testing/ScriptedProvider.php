<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Testing;

use Closure;
use SilverStripe\Core\Injector\Injectable;
use SilverstripeLtd\AiCore\Provider\CapabilityAwareProviderInterface;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\ServerToolBlock;
use SilverstripeLtd\AiCore\Provider\Message\ServerToolPart;
use SilverstripeLtd\AiCore\Provider\Message\StopReason;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\Message\Usage;
use SilverstripeLtd\AiCore\Provider\ProviderCapability;
use SilverstripeLtd\AiCore\Provider\ProviderException;

/**
 * Deterministic chat provider that replays a queue of responses and records every request.
 *
 * Tests script it with the text(), toolUse() and toolUses() builders, or with closures that
 * receive the ChatRequest so assertions can inspect what the agent loop sent. An under
 * scripted test fails loudly: an empty queue throws unless fallbackToCanned is set, in which
 * case the provider greets and echoes so a UI works with no API key at all. The greeting and
 * the note can be replaced through the constructor, typically from Injector YAML.
 *
 * It offers no built-in capability until a test turns one on with enable(), and webReading()
 * builds a reply in which the provider read a web page itself.
 */
class ScriptedProvider implements CapabilityAwareProviderInterface
{

    use Injectable;

    public const string NAME = 'scripted';
    public const string MODEL = 'scripted';
    public const int FAKE_INPUT_TOKENS = 100;
    public const int FAKE_OUTPUT_TOKENS = 25;
    public const string GREETING = 'Hello, I am a scripted assistant.';
    public const string NO_PROVIDER_NOTE = 'No AI provider is configured, so I can only echo what you say. '
        . 'Set AI_API_KEY to talk to a real model.';

    private const int DEFAULT_MAX_TOKENS = 1024;
    private const int DEFAULT_TIMEOUT_SECONDS = 1;
    private const string TOOL_USE_ID_PREFIX = 'toolu_scripted_';
    private const string MESSAGE_ID_PREFIX = 'msg_scripted_';
    private const string SERVER_TOOL_ID_PREFIX = 'srvtoolu_scripted_';

    private static int $sequence = 0;

    /**
     * @var array<int, ChatResponse|Closure>
     */
    private array $queue = [];

    /**
     * @var array<int, ChatRequest>
     */
    private array $requests = [];

    /**
     * Reply used for every request once the queue is empty, set by always().
     */
    private ChatResponse|Closure|null $always = null;

    /**
     * @var array<string, ProviderCapability>
     */
    private array $capabilities = [];

    /**
     * @param array<int, ChatResponse|Closure> $responses Replayed in order; a Closure receives
     *     the ChatRequest and must return a ChatResponse
     * @param bool $fallbackToCanned Greet and echo instead of throwing once the queue is empty
     * @param string $greeting First line of the first canned reply
     * @param string $noProviderNote Last line of every canned reply
     */
    public function __construct(
        array $responses = [],
        private readonly bool $fallbackToCanned = false,
        private readonly string $greeting = self::GREETING,
        private readonly string $noProviderNote = self::NO_PROVIDER_NOTE,
    ) {
        $this->queue(...$responses);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDefaultOptions(): ChatOptions
    {
        return new ChatOptions(self::MODEL, self::DEFAULT_MAX_TOKENS, self::DEFAULT_TIMEOUT_SECONDS);
    }

    /**
     * Appends responses to the queue.
     */
    public function queue(ChatResponse|Closure ...$responses): static
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }

        return $this;
    }

    /**
     * @throws ProviderException When the queue is empty and canned replies are off, or a
     *     scripted closure returns something other than a ChatResponse.
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        $this->requests[] = $request;

        if ($this->queue === [] && $this->always !== null) {
            return $this->resolve($this->always, $request);
        }

        if ($this->queue === []) {
            if ($this->fallbackToCanned) {
                return $this->canned($request);
            }

            throw new ProviderException(sprintf(
                'ScriptedProvider has no response queued for request %d.',
                count($this->requests),
            ));
        }

        return $this->resolve(array_shift($this->queue), $request);
    }

    /**
     * Answers every request with this reply once the queued ones are used up, however many the
     * caller makes. A Closure is called for each request and may throw.
     */
    public function always(ChatResponse|Closure $reply): static
    {
        $this->always = $reply;

        return $this;
    }

    /**
     * @throws ProviderException When a scripted closure returns something other than a
     *     ChatResponse.
     */
    private function resolve(ChatResponse|Closure $next, ChatRequest $request): ChatResponse
    {
        if (!$next instanceof Closure) {
            return $next;
        }

        $response = $next($request);

        if (!$response instanceof ChatResponse) {
            throw new ProviderException(sprintf(
                'Scripted closure must return a ChatResponse, got %s.',
                get_debug_type($response),
            ));
        }

        return $response;
    }

    /**
     * Every request received, oldest first.
     *
     * @return array<int, ChatRequest>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    public function getLastRequest(): ?ChatRequest
    {
        $count = count($this->requests);

        return $count === 0
            ? null
            : $this->requests[$count - 1];
    }

    public function getRemainingCount(): int
    {
        return count($this->queue);
    }

    public function supports(ProviderCapability $capability): bool
    {
        return isset($this->capabilities[$capability->value]);
    }

    /**
     * Offers built-in capabilities until reset().
     */
    public function enable(ProviderCapability ...$capabilities): static
    {
        foreach ($capabilities as $capability) {
            $this->capabilities[$capability->value] = $capability;
        }

        return $this;
    }

    /**
     * Clears the queue, the recorded requests and any enabled capability.
     */
    public function reset(): static
    {
        $this->queue = [];
        $this->requests = [];
        $this->always = null;
        $this->capabilities = [];

        return $this;
    }

    /**
     * A plain assistant reply that ends the turn.
     */
    public static function text(string $text): ChatResponse
    {
        return self::response([new TextBlock($text)], StopReason::EndTurn);
    }

    /**
     * A reply in which the provider read a web page on its own servers and then answered:
     * the call, its result (an error when $failed) and the reply text.
     */
    public static function webReading(string $url, string $pageText, string $reply, bool $failed = false): ChatResponse
    {
        $id = self::nextId(self::SERVER_TOOL_ID_PREFIX);
        $tool = ProviderCapability::WebReading->value;
        $input = ['url' => $url];
        $result = $failed
            ? ['error' => 'url_not_accessible']
            : ['url' => $url, 'text' => $pageText];

        return self::response([
            new ServerToolBlock(self::NAME, ServerToolPart::Call, $id, $tool, ['id' => $id, 'input' => $input], $input),
            new ServerToolBlock(
                self::NAME,
                ServerToolPart::Result,
                $id,
                $tool,
                ['tool_use_id' => $id, 'content' => $result],
                [],
                $failed,
            ),
            new TextBlock($reply),
        ], StopReason::EndTurn);
    }

    /**
     * A single tool call, optionally preceded by assistant text.
     *
     * @param array<string, mixed> $input
     */
    public static function toolUse(string $name, array $input = [], ?string $id = null, string $text = ''): ChatResponse
    {
        return self::toolUses([new ToolUseBlock($id ?? self::nextId(self::TOOL_USE_ID_PREFIX), $name, $input)], $text);
    }

    /**
     * Several tool calls in one turn, as ToolUseBlock instances or arrays with name, input and
     * optionally id.
     *
     * @param array<int, ToolUseBlock|array<string, mixed>> $calls
     */
    public static function toolUses(array $calls, string $text = ''): ChatResponse
    {
        $blocks = [];

        if ($text !== '') {
            $blocks[] = new TextBlock($text);
        }

        foreach ($calls as $call) {
            $blocks[] = $call instanceof ToolUseBlock
                ? $call
                : self::toolUseFromArray($call);
        }

        return self::response($blocks, StopReason::ToolUse);
    }

    /**
     * Sequential, process unique id such as "toolu_scripted_3".
     */
    public static function nextId(string $prefix): string
    {
        self::$sequence++;

        return $prefix . self::$sequence;
    }

    /**
     * @param array<int, TextBlock|ToolUseBlock> $blocks
     */
    private static function response(array $blocks, StopReason $stopReason): ChatResponse
    {
        return new ChatResponse(
            new ChatMessage(Role::Assistant, $blocks),
            $stopReason,
            new Usage(self::FAKE_INPUT_TOKENS, self::FAKE_OUTPUT_TOKENS),
            self::nextId(self::MESSAGE_ID_PREFIX),
        );
    }

    /**
     * @param array<string, mixed> $call
     */
    private static function toolUseFromArray(array $call): ToolUseBlock
    {
        $input = $call['input'] ?? [];
        $id = $call['id'] ?? null;

        return new ToolUseBlock(
            is_string($id) && $id !== ''
                ? $id
                : self::nextId(self::TOOL_USE_ID_PREFIX),
            (string) ($call['name'] ?? ''),
            is_array($input)
                ? $input
                : [],
        );
    }

    /**
     * Greets on the first turn, then echoes the latest user text, always noting that no real
     * provider is configured.
     */
    private function canned(ChatRequest $request): ChatResponse
    {
        $parts = [];

        if (!$request->hasAssistantMessage()) {
            $parts[] = $this->greeting;
        }

        $userText = $request->getLastUserText();

        if ($userText !== '') {
            $parts[] = sprintf('You said: "%s"', $userText);
        }

        $parts[] = $this->noProviderNote;

        return self::text(implode("\n\n", $parts));
    }
}
