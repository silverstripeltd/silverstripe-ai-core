<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Settings;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;

/**
 * Provider settings read from environment variables, with YAML as the fallback.
 *
 * Every value is looked up in this order, and the first non blank one wins:
 *
 * 1. the module's own variable, AI_<PREFIX>_<NAME> (for example AI_SEO_MODEL)
 * 2. the shared variable, AI_<NAME> (for example AI_MODEL)
 * 3. YAML: modules.<PREFIX>.providers.<provider>.<key>, then modules.<PREFIX>.<key>
 * 4. YAML: shared.providers.<provider>.<key>, then shared.<key>
 *
 * The shared AI_API_KEY and AI_MODEL belong to the shared provider: AI_PROVIDER, or the default
 * provider when that is unset. They are used only when the module's resolved provider is that
 * same provider, so a key meant for one vendor is never sent to another. For these two names a
 * value in the module's own YAML entry also wins over the shared variable, since it was set for
 * this module specifically. An empty prefix reads the shared variables only.
 *
 * Names: PROVIDER, API_KEY, MODEL, MAX_TOKENS, REQUEST_TIMEOUT, TEMPERATURE and
 * THINKING_LEVEL. YAML keys are the lower case equivalents.
 */
class EnvProviderSettings implements ProviderSettingsInterface
{

    use Configurable;
    use Injectable;

    public const string ENV_PREFIX = 'AI_';

    public const string PROVIDER = 'PROVIDER';
    public const string API_KEY = 'API_KEY';
    public const string MODEL = 'MODEL';
    public const string MAX_TOKENS = 'MAX_TOKENS';
    public const string REQUEST_TIMEOUT = 'REQUEST_TIMEOUT';
    public const string TEMPERATURE = 'TEMPERATURE';
    public const string THINKING_LEVEL = 'THINKING_LEVEL';

    public const string DEFAULT_PROVIDER = ProviderFactory::PROVIDER_ANTHROPIC;
    public const int DEFAULT_TIMEOUT_SECONDS = 90;

    private const string YAML_PROVIDERS = 'providers';

    /**
     * Names whose shared value is only used when the module and the shared provider agree.
     */
    private const array CREDENTIAL_NAMES = [self::API_KEY, self::MODEL];

    /**
     * Per module fallbacks, keyed by module prefix such as "SEO". Each entry may hold provider,
     * api_key, model, max_tokens, request_timeout, temperature and thinking_level, plus a
     * "providers" map of provider name to the same keys, which wins over the module level
     * value while that provider is selected.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $modules = [];

    /**
     * Site wide fallbacks used after the module's own, with the same keys as one module entry.
     *
     * @var array<string, mixed>
     */
    private static array $shared = [];

    private readonly string $prefix;

    /**
     * @param string $modulePrefix Module part of the variable names, such as "SEO" for
     *     AI_SEO_*. Case and surrounding underscores are ignored. Empty reads AI_* only.
     */
    public function __construct(string $modulePrefix = '')
    {
        $this->prefix = strtoupper(trim($modulePrefix, " _\t\n"));
    }

    /**
     * Settings for one module, such as EnvProviderSettings::forModule('seo').
     */
    public static function forModule(string $modulePrefix): static
    {
        return static::create($modulePrefix);
    }

    public function getModulePrefix(): string
    {
        return $this->prefix;
    }

    /**
     * The module's own variable name for a setting, or the shared one when there is no prefix.
     */
    public function getEnvName(string $name): string
    {
        return $this->prefix === ''
            ? self::ENV_PREFIX . $name
            : self::ENV_PREFIX . $this->prefix . '_' . $name;
    }

    public function getProviderName(): string
    {
        $value = $this->moduleEnv(self::PROVIDER)
            ?? $this->sharedEnv(self::PROVIDER)
            ?? $this->yamlValue($this->moduleYaml(), self::PROVIDER)
            ?? $this->yamlValue($this->sharedYaml(), self::PROVIDER)
            ?? self::DEFAULT_PROVIDER;

        return strtolower($value);
    }

    public function getApiKey(): string
    {
        $value = $this->lookup(self::API_KEY);

        if ($value === null) {
            throw SettingsException::missing('api_key', $this->hint(self::API_KEY));
        }

        return $value;
    }

    public function hasApiKey(): bool
    {
        return $this->lookup(self::API_KEY) !== null;
    }

    public function getModel(): ?string
    {
        return $this->lookup(self::MODEL);
    }

    /**
     * Zero, like unset, means the provider's default.
     */
    public function getMaxTokens(): ?int
    {
        $value = $this->lookupInt(self::MAX_TOKENS);

        if ($value !== null && $value < 0) {
            throw SettingsException::invalid('max_tokens', 'zero or a positive integer');
        }

        return $value === 0
            ? null
            : $value;
    }

    public function getTimeoutSeconds(): int
    {
        $value = $this->lookupInt(self::REQUEST_TIMEOUT) ?? self::DEFAULT_TIMEOUT_SECONDS;

        if ($value < 1) {
            throw SettingsException::invalid('request_timeout', 'a positive integer');
        }

        return $value;
    }

    public function getTemperature(): ?float
    {
        $value = $this->lookup(self::TEMPERATURE);

        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            throw SettingsException::invalid('temperature', 'a number');
        }

        return (float) $value;
    }

    public function getThinkingLevel(): ?string
    {
        $value = $this->lookup(self::THINKING_LEVEL);

        return $value === null
            ? null
            : strtolower($value);
    }

    /**
     * The environment part of the chain on its own: the module's variable, then the shared
     * one. Lets a module that keeps its own YAML settings reuse the same variable rules.
     *
     * @param string|null $provider The module's resolved provider name, used to decide whether
     *     the shared API key and model apply. Null resolves it through this class's own chain,
     *     which a module that keeps its provider in its own YAML must not rely on.
     */
    public function getEnvValue(string $name, ?string $provider = null): ?string
    {
        return $this->getModuleEnvValue($name) ?? $this->getSharedEnvValue($name, $provider);
    }

    /**
     * The module's own variable, AI_<PREFIX>_<NAME>, or null when unset or blank.
     */
    public function getModuleEnvValue(string $name): ?string
    {
        return $this->moduleEnv($name);
    }

    /**
     * The shared variable, AI_<NAME>, or null when unset or blank. The shared API key and model
     * are also null unless the module's provider is the shared provider.
     *
     * @param string|null $provider The module's resolved provider name; null resolves it through
     *     this class's own chain.
     */
    public function getSharedEnvValue(string $name, ?string $provider = null): ?string
    {
        if (in_array($name, self::CREDENTIAL_NAMES, true)
            && !$this->sharesProvider($provider ?? $this->getProviderName())
        ) {
            return null;
        }

        return $this->sharedEnv($name);
    }

    /**
     * The provider the shared AI_API_KEY and AI_MODEL belong to: AI_PROVIDER, or the default
     * provider when that is unset.
     */
    public function getSharedProviderName(): string
    {
        return strtolower($this->sharedEnv(self::PROVIDER) ?? self::DEFAULT_PROVIDER);
    }

    /**
     * Resolves one setting through the environment and YAML chain. The API key and model
     * prefer the module's YAML over the shared variable.
     */
    protected function lookup(string $name): ?string
    {
        if (!in_array($name, self::CREDENTIAL_NAMES, true)) {
            return $this->getEnvValue($name) ?? $this->yamlLookup($name);
        }

        $provider = $this->getProviderName();

        return $this->moduleEnv($name)
            ?? $this->yamlFrom($this->moduleYaml(), $name, $provider)
            ?? $this->getSharedEnvValue($name, $provider)
            ?? $this->yamlFrom($this->sharedYaml(), $name, $provider);
    }

    /**
     * YAML part of the chain: the module entry first, then the shared entry, each with the
     * selected provider's override ahead of the general value.
     */
    protected function yamlLookup(string $name): ?string
    {
        $provider = $this->getProviderName();

        return $this->yamlFrom($this->moduleYaml(), $name, $provider)
            ?? $this->yamlFrom($this->sharedYaml(), $name, $provider);
    }

    /**
     * One YAML entry's value, with the provider's override ahead of the general value.
     *
     * @param array<string, mixed> $settings
     */
    private function yamlFrom(array $settings, string $name, string $provider): ?string
    {
        $providers = $settings[self::YAML_PROVIDERS] ?? [];
        $override = is_array($providers) && is_array($providers[$provider] ?? null)
            ? $providers[$provider]
            : [];

        return $this->yamlValue($override, $name) ?? $this->yamlValue($settings, $name);
    }

    /**
     * @throws SettingsException When the resolved value is not an integer.
     */
    private function lookupInt(string $name): ?int
    {
        $value = $this->lookup($name);

        if ($value === null) {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        if ($int === false) {
            throw SettingsException::invalid(strtolower($name), sprintf('an integer (%s)', $this->hint($name)));
        }

        return $int;
    }

    /**
     * True when the module's provider is the one the shared credentials belong to.
     */
    private function sharesProvider(string $provider): bool
    {
        return strtolower(trim($provider)) === $this->getSharedProviderName();
    }

    private function moduleEnv(string $name): ?string
    {
        return $this->prefix === ''
            ? null
            : self::env($this->getEnvName($name));
    }

    private function sharedEnv(string $name): ?string
    {
        return self::env(self::ENV_PREFIX . $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleYaml(): array
    {
        if ($this->prefix === '') {
            return [];
        }

        $modules = (array) $this->config()->get('modules');
        $settings = $modules[$this->prefix] ?? [];

        return is_array($settings)
            ? $settings
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedYaml(): array
    {
        return (array) $this->config()->get('shared');
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function yamlValue(array $settings, string $name): ?string
    {
        $value = $settings[strtolower($name)] ?? null;

        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }

    private function hint(string $name): string
    {
        return $this->prefix === ''
            ? sprintf('Set the %s environment variable.', $this->getEnvName($name))
            : sprintf(
                'Set the %s environment variable, or the shared %s.',
                $this->getEnvName($name),
                self::ENV_PREFIX . $name,
            );
    }

    /**
     * The trimmed environment value, or null when the variable is unset or blank.
     */
    private static function env(string $name): ?string
    {
        $value = Environment::getEnv($name);

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}
