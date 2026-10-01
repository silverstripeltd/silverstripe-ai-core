<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Settings\EnvProviderSettings;
use SilverstripeLtd\AiCore\Settings\SettingsException;

class EnvProviderSettingsTest extends SapphireTest
{
    private const array NAMES = [
        EnvProviderSettings::PROVIDER,
        EnvProviderSettings::API_KEY,
        EnvProviderSettings::MODEL,
        EnvProviderSettings::MAX_TOKENS,
        EnvProviderSettings::REQUEST_TIMEOUT,
        EnvProviderSettings::TEMPERATURE,
        EnvProviderSettings::THINKING_LEVEL,
    ];

    private const array PREFIXES = ['AI_', 'AI_TEST_', 'AI_OTHER_'];

    /**
     * @var bool
     */
    protected $usesDatabase = false;

    /**
     * @var array<string, mixed>
     */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::PREFIXES as $prefix) {
            foreach (self::NAMES as $name) {
                $this->originalEnv[$prefix . $name] = Environment::getEnv($prefix . $name);
                Environment::setEnv($prefix . $name, null);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            Environment::setEnv($name, $value);
        }

        parent::tearDown();
    }

    public function testDefaultsWhenNothingIsConfigured(): void
    {
        $settings = EnvProviderSettings::forModule('test');

        $this->assertSame('anthropic', $settings->getProviderName());
        $this->assertNull($settings->getModel());
        $this->assertNull($settings->getMaxTokens());
        $this->assertSame(EnvProviderSettings::DEFAULT_TIMEOUT_SECONDS, $settings->getTimeoutSeconds());
        $this->assertNull($settings->getTemperature());
        $this->assertNull($settings->getThinkingLevel());
        $this->assertFalse($settings->hasApiKey());
    }

    public function testPrefixIsNormalised(): void
    {
        $this->assertSame('TEST', EnvProviderSettings::forModule(' _test_ ')->getModulePrefix());
        $this->assertSame('AI_TEST_MODEL', EnvProviderSettings::forModule('test')->getEnvName('MODEL'));
        $this->assertSame('AI_MODEL', EnvProviderSettings::create()->getEnvName('MODEL'));
    }

    public function testModuleVariablesWinOverSharedOnes(): void
    {
        Environment::setEnv('AI_PROVIDER', 'openai');
        Environment::setEnv('AI_API_KEY', 'shared-key');
        Environment::setEnv('AI_MODEL', 'shared-model');
        Environment::setEnv('AI_MAX_TOKENS', '100');
        Environment::setEnv('AI_TEST_PROVIDER', 'OpenAI');
        Environment::setEnv('AI_TEST_API_KEY', 'module-key');
        Environment::setEnv('AI_TEST_MODEL', 'module-model');
        Environment::setEnv('AI_TEST_MAX_TOKENS', '200');
        Environment::setEnv('AI_TEST_REQUEST_TIMEOUT', '12');
        Environment::setEnv('AI_TEST_TEMPERATURE', '0.25');
        Environment::setEnv('AI_TEST_THINKING_LEVEL', 'LOW');

        $settings = EnvProviderSettings::forModule('test');

        $this->assertSame('openai', $settings->getProviderName());
        $this->assertSame('module-key', $settings->getApiKey());
        $this->assertSame('module-model', $settings->getModel());
        $this->assertSame(200, $settings->getMaxTokens());
        $this->assertSame(12, $settings->getTimeoutSeconds());
        $this->assertSame(0.25, $settings->getTemperature());
        $this->assertSame('low', $settings->getThinkingLevel());
    }

    public function testSharedVariablesAreTheFallbackForEveryModule(): void
    {
        Environment::setEnv('AI_PROVIDER', 'gemini');
        Environment::setEnv('AI_API_KEY', 'shared-key');
        Environment::setEnv('AI_MODEL', 'shared-model');
        Environment::setEnv('AI_REQUEST_TIMEOUT', '30');

        foreach (['test', 'other', ''] as $prefix) {
            $settings = EnvProviderSettings::create($prefix);

            $this->assertSame('gemini', $settings->getProviderName());
            $this->assertSame('shared-key', $settings->getApiKey());
            $this->assertSame('shared-model', $settings->getModel());
            $this->assertSame(30, $settings->getTimeoutSeconds());
        }
    }

    public function testSharedKeyAndModelAreSkippedWhenTheModulePicksAnotherProvider(): void
    {
        Environment::setEnv('AI_PROVIDER', 'anthropic');
        Environment::setEnv('AI_API_KEY', 'anthropic-key');
        Environment::setEnv('AI_MODEL', 'claude-model');
        Environment::setEnv('AI_REQUEST_TIMEOUT', '30');
        Environment::setEnv('AI_TEST_PROVIDER', 'gemini');

        $settings = EnvProviderSettings::forModule('test');

        $this->assertSame('gemini', $settings->getProviderName());
        $this->assertFalse($settings->hasApiKey());
        $this->assertNull($settings->getModel());
        $this->assertSame(30, $settings->getTimeoutSeconds());
    }

    public function testSharedKeyIsUsedWhenTheModuleNamesTheSameProvider(): void
    {
        Environment::setEnv('AI_PROVIDER', 'gemini');
        Environment::setEnv('AI_API_KEY', 'gemini-key');
        Environment::setEnv('AI_TEST_PROVIDER', 'GEMINI');

        $this->assertSame('gemini-key', EnvProviderSettings::forModule('test')->getApiKey());
    }

    public function testYamlIsTheLastFallbackWithProviderOverridesFirst(): void
    {
        Config::modify()->merge(EnvProviderSettings::class, 'modules', [
            'TEST' => [
                'provider' => 'gemini',
                'max_tokens' => 2000,
                'request_timeout' => 15,
                'temperature' => 0.0,
                'providers' => [
                    'gemini' => ['model' => 'gemini-model', 'thinking_level' => 'low'],
                    'anthropic' => ['model' => 'claude-model'],
                ],
            ],
        ]);
        Config::modify()->merge(EnvProviderSettings::class, 'shared', ['model' => 'shared-yaml-model']);

        $settings = EnvProviderSettings::forModule('test');

        $this->assertSame('gemini', $settings->getProviderName());
        $this->assertSame('gemini-model', $settings->getModel());
        $this->assertSame(2000, $settings->getMaxTokens());
        $this->assertSame(15, $settings->getTimeoutSeconds());
        $this->assertSame(0.0, $settings->getTemperature());
        $this->assertSame('low', $settings->getThinkingLevel());

        Environment::setEnv('AI_PROVIDER', 'anthropic');

        $this->assertSame('claude-model', $settings->getModel());
        $this->assertNull($settings->getThinkingLevel());

        Environment::setEnv('AI_PROVIDER', 'openai');

        $this->assertSame('shared-yaml-model', $settings->getModel());
        $this->assertSame('shared-yaml-model', EnvProviderSettings::forModule('other')->getModel());

        Environment::setEnv('AI_TEST_MODEL', 'env-model');

        $this->assertSame('env-model', $settings->getModel());
    }

    public function testBlankValuesAreTreatedAsUnset(): void
    {
        Environment::setEnv('AI_TEST_MODEL', '   ');
        Environment::setEnv('AI_MODEL', 'shared-model');
        Config::modify()->merge(EnvProviderSettings::class, 'modules', ['TEST' => ['max_tokens' => '']]);

        $settings = EnvProviderSettings::forModule('test');

        $this->assertSame('shared-model', $settings->getModel());
        $this->assertNull($settings->getMaxTokens());
    }

    public function testZeroMaxTokensMeansTheProviderDefault(): void
    {
        Environment::setEnv('AI_TEST_MAX_TOKENS', '0');

        $this->assertNull(EnvProviderSettings::forModule('test')->getMaxTokens());
    }

    public function testMissingKeyNamesBothVariablesAndIsBlocking(): void
    {
        try {
            EnvProviderSettings::forModule('test')->getApiKey();
            $this->fail('Expected a SettingsException');
        } catch (SettingsException $exception) {
            $this->assertTrue($exception->isBlocking());
            $this->assertFalse($exception->isTransient());
            $this->assertStringContainsString('AI_TEST_API_KEY', $exception->getMessage());
            $this->assertStringContainsString('AI_API_KEY', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideInvalidValues(): array
    {
        return [
            'max tokens not an integer' => ['AI_TEST_MAX_TOKENS', 'lots'],
            'negative max tokens' => ['AI_TEST_MAX_TOKENS', '-5'],
            'timeout not an integer' => ['AI_TEST_REQUEST_TIMEOUT', '1.5'],
            'zero timeout' => ['AI_TEST_REQUEST_TIMEOUT', '0'],
            'temperature not a number' => ['AI_TEST_TEMPERATURE', 'warm'],
        ];
    }

    #[DataProvider('provideInvalidValues')]
    public function testInvalidValuesAreBlockingAndNeverQuoted(string $name, string $value): void
    {
        Environment::setEnv($name, $value);
        $settings = EnvProviderSettings::forModule('test');

        try {
            $settings->getMaxTokens();
            $settings->getTimeoutSeconds();
            $settings->getTemperature();
            $this->fail('Expected a SettingsException');
        } catch (SettingsException $exception) {
            $this->assertTrue($exception->isBlocking());
            $this->assertStringNotContainsString($value, $exception->getMessage());
        }
    }

    public function testEnvValueSkipsYaml(): void
    {
        Config::modify()->merge(EnvProviderSettings::class, 'modules', ['TEST' => ['model' => 'yaml-model']]);

        $settings = EnvProviderSettings::forModule('test');

        $this->assertNull($settings->getEnvValue(EnvProviderSettings::MODEL));

        Environment::setEnv('AI_MODEL', 'shared-model');

        $this->assertSame('shared-model', $settings->getEnvValue(EnvProviderSettings::MODEL));
    }

    public function testSharedKeyBelongsToTheDefaultProviderWhenTheSharedProviderIsUnset(): void
    {
        Environment::setEnv('AI_API_KEY', 'anthropic-key');
        Environment::setEnv('AI_MODEL', 'claude-model');
        Environment::setEnv('AI_TEST_PROVIDER', 'openai');

        $settings = EnvProviderSettings::forModule('test');

        $this->assertSame('openai', $settings->getProviderName());
        $this->assertSame('anthropic', $settings->getSharedProviderName());
        $this->assertFalse($settings->hasApiKey());
        $this->assertNull($settings->getModel());
        $this->assertNull($settings->getEnvValue(EnvProviderSettings::API_KEY));
    }

    public function testSharedKeyIsSkippedWhenModuleYamlPicksAnotherProvider(): void
    {
        Environment::setEnv('AI_API_KEY', 'anthropic-key');
        Config::modify()->merge(EnvProviderSettings::class, 'modules', ['TEST' => ['provider' => 'gemini']]);

        $settings = EnvProviderSettings::forModule('test');

        $this->assertSame('gemini', $settings->getProviderName());
        $this->assertFalse($settings->hasApiKey());
    }

    public function testSharedKeyIsUsedForTheDefaultProviderWhenNothingNamesOne(): void
    {
        Environment::setEnv('AI_API_KEY', 'anthropic-key');

        $settings = EnvProviderSettings::forModule('test');

        $this->assertSame('anthropic', $settings->getProviderName());
        $this->assertSame('anthropic-key', $settings->getApiKey());
    }

    public function testModuleYamlKeyWinsOverTheSharedVariable(): void
    {
        Environment::setEnv('AI_PROVIDER', 'gemini');
        Environment::setEnv('AI_API_KEY', 'shared-key');
        Environment::setEnv('AI_MODEL', 'shared-model');
        Config::modify()->merge(EnvProviderSettings::class, 'modules', [
            'TEST' => ['api_key' => 'module-yaml-key', 'providers' => ['gemini' => ['model' => 'module-model']]],
        ]);

        $settings = EnvProviderSettings::forModule('test');

        $this->assertSame('module-yaml-key', $settings->getApiKey());
        $this->assertSame('module-model', $settings->getModel());
        $this->assertSame('shared-key', EnvProviderSettings::forModule('other')->getApiKey());

        Environment::setEnv('AI_TEST_API_KEY', 'module-env-key');

        $this->assertSame('module-env-key', $settings->getApiKey());
    }

    public function testEnvValueUsesTheProviderTheCallerResolved(): void
    {
        Environment::setEnv('AI_API_KEY', 'anthropic-key');
        Environment::setEnv('AI_REQUEST_TIMEOUT', '30');

        $settings = EnvProviderSettings::forModule('test');

        $this->assertNull($settings->getEnvValue(EnvProviderSettings::API_KEY, 'openai'));
        $this->assertSame('anthropic-key', $settings->getEnvValue(EnvProviderSettings::API_KEY, 'Anthropic'));
        $this->assertSame('30', $settings->getEnvValue(EnvProviderSettings::REQUEST_TIMEOUT, 'openai'));
        $this->assertNull($settings->getModuleEnvValue(EnvProviderSettings::API_KEY));
    }
}
