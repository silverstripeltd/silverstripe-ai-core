<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Settings;

use SilverstripeLtd\AiCore\Provider\ProviderException;

/**
 * The connection and generation settings a chat provider is built from.
 *
 * Each consuming module supplies its own implementation (or an EnvProviderSettings with its
 * own prefix), so several modules can talk to different vendors, models and keys on one site.
 * Null return values mean "use the provider's own default".
 */
interface ProviderSettingsInterface
{
    /**
     * Provider identifier looked up in ProviderFactory.providers, such as "anthropic".
     */
    public function getProviderName(): string;

    /**
     * @throws ProviderException Blocking, when no key is configured. The message names the
     *     variable to set and never contains a key.
     */
    public function getApiKey(): string;

    public function getModel(): ?string;

    public function getMaxTokens(): ?int;

    /**
     * Seconds allowed for one provider call, used for both the connect and the total timeout.
     */
    public function getTimeoutSeconds(): int;

    /**
     * Sampling temperature. Null leaves the vendor default, which is then not sent at all.
     */
    public function getTemperature(): ?float;

    /**
     * Reasoning depth passed to the vendor as is (Anthropic effort, OpenAI reasoning_effort,
     * Gemini thinkingLevel). Null sends nothing.
     */
    public function getThinkingLevel(): ?string;
}
