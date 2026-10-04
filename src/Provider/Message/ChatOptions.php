<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * Per call generation settings.
 *
 * Providers expose their own defaults through ChatProviderInterface::getDefaultOptions(); the
 * with*() methods derive a variant without mutating the original.
 */
final readonly class ChatOptions
{
    /** The sampling temperature the API uses when none is sent. */
    public const float DEFAULT_TEMPERATURE = 1.0;

    /** Reasoning effort value that tells a provider to send no thinking setting at all. */
    public const string REASONING_EFFORT_NONE = 'none';

    /**
     * @param array<int, string> $cacheableSystemPrefix Stable system text sent ahead of the
     *     per request system prompt and marked for provider side prompt caching
     * @param string|null $reasoningEffort Vendor reasoning level passed through as is (Anthropic
     *     effort, OpenAI reasoning_effort, Gemini thinkingLevel). Null leaves the model default.
     * @param bool $cacheConversation Ask the provider to cache the conversation as well as the
     *     system prefix, for callers that resend a growing conversation (agent loops). Off by
     *     default: on a one off request the cache write premium is never earned back. Providers
     *     that cache implicitly (OpenAI, Gemini) ignore it.
     */
    public function __construct(
        public string $model,
        public int $maxTokens,
        public int $timeoutSeconds,
        public float $temperature = self::DEFAULT_TEMPERATURE,
        public array $cacheableSystemPrefix = [],
        public ?string $reasoningEffort = null,
        public bool $cacheConversation = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $prefix = $data['cacheable_system_prefix'] ?? [];
        $effort = $data['reasoning_effort'] ?? null;

        return new self(
            (string) ($data['model'] ?? ''),
            (int) ($data['max_tokens'] ?? 0),
            (int) ($data['timeout_seconds'] ?? 0),
            (float) ($data['temperature'] ?? self::DEFAULT_TEMPERATURE),
            is_array($prefix)
                ? array_values(array_map('strval', $prefix))
                : [],
            is_string($effort) && $effort !== ''
                ? $effort
                : null,
            (bool) ($data['cache_conversation'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'timeout_seconds' => $this->timeoutSeconds,
            'temperature' => $this->temperature,
            'cacheable_system_prefix' => $this->cacheableSystemPrefix,
            'reasoning_effort' => $this->reasoningEffort,
            'cache_conversation' => $this->cacheConversation,
        ];
    }

    /**
     * @param array<int, string> $prefix
     */
    public function withCacheableSystemPrefix(array $prefix): self
    {
        return new self(
            $this->model,
            $this->maxTokens,
            $this->timeoutSeconds,
            $this->temperature,
            $prefix,
            $this->reasoningEffort,
            $this->cacheConversation,
        );
    }

    public function withMaxTokens(int $maxTokens): self
    {
        return new self(
            $this->model,
            $maxTokens,
            $this->timeoutSeconds,
            $this->temperature,
            $this->cacheableSystemPrefix,
            $this->reasoningEffort,
            $this->cacheConversation,
        );
    }

    public function withTemperature(float $temperature): self
    {
        return new self(
            $this->model,
            $this->maxTokens,
            $this->timeoutSeconds,
            $temperature,
            $this->cacheableSystemPrefix,
            $this->reasoningEffort,
            $this->cacheConversation,
        );
    }

    public function withReasoningEffort(?string $reasoningEffort): self
    {
        return new self(
            $this->model,
            $this->maxTokens,
            $this->timeoutSeconds,
            $this->temperature,
            $this->cacheableSystemPrefix,
            $reasoningEffort,
            $this->cacheConversation,
        );
    }

    public function withConversationCache(bool $enabled = true): self
    {
        return new self(
            $this->model,
            $this->maxTokens,
            $this->timeoutSeconds,
            $this->temperature,
            $this->cacheableSystemPrefix,
            $this->reasoningEffort,
            $enabled,
        );
    }

    /**
     * The effort to send, or null when none was configured or it was set to "none".
     */
    public function getEffectiveReasoningEffort(): ?string
    {
        return $this->reasoningEffort === null || $this->reasoningEffort === self::REASONING_EFFORT_NONE
            ? null
            : $this->reasoningEffort;
    }
}
