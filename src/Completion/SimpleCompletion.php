<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Completion;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverstripeLtd\AiCore\Provider\ChatProviderInterface;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiCore\Settings\ProviderSettingsInterface;

/**
 * One system prompt and one user message in, the model's text out.
 *
 * For modules that need a single turn and no tools. The provider is resolved from the
 * settings on every call through the ProviderFactory Injector service, so tests can swap in a
 * StubProviderFactory at any time. Never retries; callers decide what to do with transient
 * failures. Logs carry the provider, model and timing, never prompts, replies or keys.
 */
class SimpleCompletion
{

    use Injectable;

    public function __construct(
        private readonly ProviderSettingsInterface $settings,
        private readonly ?ProviderFactory $factory = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @throws ProviderException On any configuration, transport or response problem, and when
     *     the reply has no text.
     */
    public function complete(string $system, string $user, ?CompletionOptions $options = null): string
    {
        $response = $this->send($system, $user, $options);
        $text = trim(self::joinText($response));

        if ($text === '') {
            $provider = $this->settings->getProviderName();
            $exception = new ProviderException(sprintf('%s response missing content', ucfirst($provider)));
            $this->logFailure($exception, $provider);

            throw $exception;
        }

        return $text;
    }

    /**
     * The full reply, for callers that need the stop reason or usage as well as the text.
     *
     * @throws ProviderException On any configuration, transport or response problem.
     */
    public function send(string $system, string $user, ?CompletionOptions $options = null): ChatResponse
    {
        try {
            $provider = $this->getProvider();
            $chatOptions = $provider->getDefaultOptions();

            if ($options !== null) {
                $chatOptions = $options->applyTo($chatOptions);
            }
        } catch (ProviderException $exception) {
            $this->logFailure($exception);

            throw $exception;
        }

        $request = new ChatRequest($system, [ChatMessage::fromText(Role::User, $user)], [], $chatOptions);

        return $this->dispatch($provider, $request);
    }

    /**
     * Every text block of the reply joined without a separator, because some vendors split
     * one answer (and so one JSON document) across several parts.
     */
    public static function joinText(ChatResponse $response): string
    {
        $text = '';

        foreach ($response->message->blocks as $block) {
            if (!($block instanceof TextBlock)) {
                continue;
            }

            $text .= $block->text;
        }

        return $text;
    }

    /**
     * The provider these settings select, bound to them.
     */
    public function getProvider(): ChatProviderInterface
    {
        return $this->getFactory()->forSettings($this->settings);
    }

    public function getSettings(): ProviderSettingsInterface
    {
        return $this->settings;
    }

    private function dispatch(ChatProviderInterface $provider, ChatRequest $request): ChatResponse
    {
        $this->getLogger()->info('AI provider request starting', [
            'provider' => $provider->getName(),
            'model' => $request->options->model,
            'timeout' => $request->options->timeoutSeconds,
        ]);
        $startedAt = microtime(true);

        try {
            $response = $provider->chat($request);
        } catch (ProviderException $exception) {
            $this->logFailure($exception, $provider->getName());

            throw $exception;
        }

        $this->getLogger()->debug('AI provider response received', [
            'provider' => $provider->getName(),
            'stopReason' => $response->stopReason->value,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $response;
    }

    private function logFailure(ProviderException $exception, ?string $provider = null): void
    {
        $this->getLogger()->warning('AI provider error', [
            'provider' => $provider,
            'message' => $exception->getMessage(),
            'transient' => $exception->isTransient(),
            'blocking' => $exception->isBlocking(),
        ]);
    }

    private function getFactory(): ProviderFactory
    {
        return $this->factory ?? Injector::inst()->get(ProviderFactory::class);
    }

    private function getLogger(): LoggerInterface
    {
        return $this->logger ?? Injector::inst()->get(LoggerInterface::class);
    }
}
