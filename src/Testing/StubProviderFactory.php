<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Testing;

use SilverstripeLtd\AiCore\Provider\ChatProviderInterface;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiCore\Settings\ProviderSettingsInterface;

/**
 * Provider factory for tests: always returns the provider it was given, whatever the settings
 * ask for, and records the settings of every call.
 *
 * Register it in place of the real factory so every SimpleCompletion and JsonCompletion talks
 * to a ScriptedProvider instead of a vendor:
 *
 *     Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);
 */
class StubProviderFactory extends ProviderFactory
{
    /**
     * @var array<int, ProviderSettingsInterface>
     */
    private array $settings = [];

    public function __construct(private readonly ChatProviderInterface $provider)
    {
    }

    public function forSettings(ProviderSettingsInterface $settings): ChatProviderInterface
    {
        $this->settings[] = $settings;

        return $this->provider;
    }

    public function getProvider(): ChatProviderInterface
    {
        return $this->provider;
    }

    /**
     * Settings passed to forSettings(), oldest first.
     *
     * @return array<int, ProviderSettingsInterface>
     */
    public function getRequestedSettings(): array
    {
        return $this->settings;
    }

    public function getLastSettings(): ?ProviderSettingsInterface
    {
        $count = count($this->settings);

        return $count === 0
            ? null
            : $this->settings[$count - 1];
    }
}
