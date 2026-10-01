<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider;

use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;

/**
 * A chat model that can call tools. One implementation per vendor plus a scripted fake.
 */
interface ChatProviderInterface
{
    /**
     * Sends one request and returns the model's reply. Never retries.
     *
     * @throws ProviderException On any transport, authentication or response problem.
     */
    public function chat(ChatRequest $request): ChatResponse;

    /**
     * Short identifier such as "anthropic" or "scripted", used in logs and the UI.
     */
    public function getName(): string;

    /**
     * Options built from module configuration with this provider's own defaults filled in.
     */
    public function getDefaultOptions(): ChatOptions;
}
