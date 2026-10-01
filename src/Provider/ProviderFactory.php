<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Factory;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverstripeLtd\AiCore\Provider\Anthropic\AnthropicProvider;
use SilverstripeLtd\AiCore\Provider\Gemini\GeminiProvider;
use SilverstripeLtd\AiCore\Provider\OpenAI\OpenAIProvider;
use SilverstripeLtd\AiCore\Settings\ProviderSettingsInterface;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;

/**
 * Builds the chat provider a set of settings asks for.
 *
 * The provider name comes from ProviderSettingsInterface::getProviderName() and is looked up in
 * the "providers" map, which projects can extend through YAML to add their own
 * implementations. Settings aware providers are returned bound to the settings, so one
 * registered provider service serves every module with that module's key and defaults.
 *
 * Also an Injector factory: a service declared with "factory: ProviderFactory" and a
 * ProviderSettingsInterface as its first constructor argument resolves to the provider those
 * settings select. Without an argument the ProviderSettingsInterface service is used.
 */
class ProviderFactory implements Factory
{

    use Configurable;
    use Injectable;

    public const string PROVIDER_ANTHROPIC = 'anthropic';
    public const string PROVIDER_OPENAI = 'openai';
    public const string PROVIDER_GEMINI = 'gemini';
    public const string PROVIDER_SCRIPTED = 'scripted';

    /**
     * Provider name to Injector service name.
     *
     * @var array<string, string>
     */
    private static array $providers = [
        self::PROVIDER_ANTHROPIC => AnthropicProvider::class,
        self::PROVIDER_OPENAI => OpenAIProvider::class,
        self::PROVIDER_GEMINI => GeminiProvider::class,
        self::PROVIDER_SCRIPTED => ScriptedProvider::class,
    ];

    /**
     * @throws ProviderException Blocking, when the configured name has no registered provider.
     */
    public function forSettings(ProviderSettingsInterface $settings): ChatProviderInterface
    {
        $name = $settings->getProviderName();
        $providers = (array) $this->config()->get('providers');
        $service = $providers[$name] ?? null;

        if (!is_string($service) || $service === '') {
            throw ProviderException::blocking(sprintf(
                'Unknown AI provider "%s". Known providers: %s.',
                $name,
                implode(', ', array_keys($providers)),
            ));
        }

        $provider = Injector::inst()->get($service);

        if (!$provider instanceof ChatProviderInterface) {
            throw ProviderException::blocking(sprintf(
                'AI provider "%s" resolves to %s, which is not a chat provider.',
                $name,
                get_debug_type($provider),
            ));
        }

        return $provider instanceof SettingsAwareProviderInterface
            ? $provider->withSettings($settings)
            : $provider;
    }

    /**
     * The provider selected by the ProviderSettingsInterface Injector service.
     */
    public function getProvider(): ChatProviderInterface
    {
        return $this->forSettings(Injector::inst()->get(ProviderSettingsInterface::class));
    }

    /**
     * Injector factory entry point. The service name is ignored because the provider is chosen
     * by the settings.
     *
     * @param array<int|string, mixed> $params Optionally a ProviderSettingsInterface first
     */
    public function create(string $service, array $params = []): ?object
    {
        $settings = reset($params);

        return $settings instanceof ProviderSettingsInterface
            ? $this->forSettings($settings)
            : $this->getProvider();
    }

    /**
     * Registered provider names.
     *
     * @return array<int, string>
     */
    public function getProviderNames(): array
    {
        return array_map('strval', array_keys((array) $this->config()->get('providers')));
    }
}
