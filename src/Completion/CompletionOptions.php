<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Completion;

use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;

/**
 * Per call overrides for a single turn completion. Every null value keeps the setting the
 * provider resolved from its ProviderSettingsInterface.
 */
final readonly class CompletionOptions
{
    public function __construct(
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        public ?string $model = null,
        public ?int $timeoutSeconds = null,
        public ?string $thinkingLevel = null,
    ) {
    }

    /**
     * The provider's defaults with these overrides applied.
     */
    public function applyTo(ChatOptions $options): ChatOptions
    {
        return new ChatOptions(
            $this->model ?? $options->model,
            $this->maxTokens ?? $options->maxTokens,
            $this->timeoutSeconds ?? $options->timeoutSeconds,
            $this->temperature ?? $options->temperature,
            $options->cacheableSystemPrefix,
            $this->thinkingLevel ?? $options->reasoningEffort,
        );
    }
}
