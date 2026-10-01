<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Provider;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Provider\Anthropic\AnthropicProvider;
use SilverstripeLtd\AiCore\Provider\ChatProviderInterface;
use SilverstripeLtd\AiCore\Provider\Gemini\GeminiProvider;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\OpenAI\OpenAIProvider;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiCore\Settings\EnvProviderSettings;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;
use stdClass;

class ProviderFactoryTest extends SapphireTest
{
    /**
     * @var bool
     */
    protected $usesDatabase = false;

    private mixed $originalProvider = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalProvider = Environment::getEnv('AI_PROVIDER');
        Environment::setEnv('AI_PROVIDER', null);
        Injector::inst()->unregisterNamedObject(ChatProviderInterface::class);
    }

    protected function tearDown(): void
    {
        Environment::setEnv('AI_PROVIDER', $this->originalProvider);
        Injector::inst()->unregisterNamedObject(ChatProviderInterface::class);

        parent::tearDown();
    }

    public function testDefaultsToAnthropic(): void
    {
        $provider = $this->factory()->getProvider();

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertSame('anthropic', $provider->getName());
    }

    public function testEnvironmentSelectsScripted(): void
    {
        Environment::setEnv('AI_PROVIDER', 'scripted');

        $provider = $this->factory()->getProvider();

        $this->assertInstanceOf(ScriptedProvider::class, $provider);
        $this->assertSame('scripted', $provider->getName());
    }

    public function testEnvironmentSelectsOpenAIAndGemini(): void
    {
        Environment::setEnv('AI_PROVIDER', ProviderFactory::PROVIDER_OPENAI);
        $openai = $this->factory()->getProvider();

        Environment::setEnv('AI_PROVIDER', ProviderFactory::PROVIDER_GEMINI);
        $gemini = $this->factory()->getProvider();

        $this->assertInstanceOf(OpenAIProvider::class, $openai);
        $this->assertSame('openai', $openai->getName());
        $this->assertInstanceOf(GeminiProvider::class, $gemini);
        $this->assertSame('gemini', $gemini->getName());
    }

    public function testYamlSelectsProviderWhenEnvironmentIsUnset(): void
    {
        Config::modify()->merge(EnvProviderSettings::class, 'shared', ['provider' => 'scripted']);

        $this->assertInstanceOf(ScriptedProvider::class, $this->factory()->getProvider());
    }

    public function testUnknownProviderIsBlocking(): void
    {
        Environment::setEnv('AI_PROVIDER', 'mistral');

        try {
            $this->factory()->getProvider();
            $this->fail('Expected a ProviderException');
        } catch (ProviderException $exception) {
            $this->assertTrue($exception->isBlocking());
            $this->assertFalse($exception->isTransient());
            $this->assertStringContainsString('mistral', $exception->getMessage());

            foreach (['anthropic', 'openai', 'gemini', 'scripted'] as $known) {
                $this->assertStringContainsString($known, $exception->getMessage());
            }
        }
    }

    public function testServiceThatIsNotAProviderIsRejected(): void
    {
        Config::modify()->merge(ProviderFactory::class, 'providers', ['broken' => stdClass::class]);
        Environment::setEnv('AI_PROVIDER', 'broken');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('not a chat provider');

        $this->factory()->getProvider();
    }

    public function testProvidersMapIsExtensibleThroughConfig(): void
    {
        Config::modify()->merge(ProviderFactory::class, 'providers', ['fake' => ScriptedProvider::class]);
        Environment::setEnv('AI_PROVIDER', 'fake');

        $this->assertInstanceOf(ScriptedProvider::class, $this->factory()->getProvider());
    }

    public function testInjectorResolvesTheInterfaceThroughTheFactory(): void
    {
        Environment::setEnv('AI_PROVIDER', 'scripted');

        $provider = Injector::inst()->get(ChatProviderInterface::class);

        $this->assertInstanceOf(ScriptedProvider::class, $provider);
        $this->assertSame($provider, Injector::inst()->get(ChatProviderInterface::class));
    }

    public function testFactoryCanBeReplacedThroughTheInjector(): void
    {
        $scripted = new ScriptedProvider([ScriptedProvider::text('stubbed')]);
        Injector::inst()->registerService($scripted, ChatProviderInterface::class);

        $provider = Injector::inst()->get(ChatProviderInterface::class);

        $this->assertSame($scripted, $provider);
        $this->assertSame('stubbed', $provider->chat(self::request())->getText());
    }

    public function testScriptedProviderWorksWithNoConfigurationAtAll(): void
    {
        Environment::setEnv('AI_PROVIDER', 'scripted');

        $provider = Injector::inst()->get(ChatProviderInterface::class);
        $response = $provider->chat(self::request());

        $this->assertStringContainsString(ScriptedProvider::GREETING, $response->getText());
        $this->assertStringContainsString('You said: "Hello"', $response->getText());
        $this->assertStringContainsString(ScriptedProvider::NO_PROVIDER_NOTE, $response->getText());
    }

    public function testForSettingsBindsEachModuleToItsOwnSettings(): void
    {
        $seo = new EnvProviderSettings('core_test_seo');
        $refine = new EnvProviderSettings('core_test_refine');
        Config::modify()->merge(EnvProviderSettings::class, 'modules', [
            'CORE_TEST_SEO' => ['provider' => 'anthropic', 'model' => 'model-a', 'max_tokens' => 100],
            'CORE_TEST_REFINE' => ['provider' => 'anthropic', 'model' => 'model-b', 'max_tokens' => 200],
        ]);

        $first = $this->factory()->forSettings($seo);
        $second = $this->factory()->forSettings($refine);

        $this->assertInstanceOf(AnthropicProvider::class, $first);
        $this->assertNotSame($first, $second);
        $this->assertSame($seo, $first->getSettings());
        $this->assertSame('model-a', $first->getDefaultOptions()->model);
        $this->assertSame(100, $first->getDefaultOptions()->maxTokens);
        $this->assertSame('model-b', $second->getDefaultOptions()->model);
        $this->assertSame(200, $second->getDefaultOptions()->maxTokens);
        $this->assertNotSame($seo, Injector::inst()->get(AnthropicProvider::class)->getSettings());
    }

    public function testForSettingsReturnsProvidersWithoutSettingsAsTheyAre(): void
    {
        Config::modify()->merge(EnvProviderSettings::class, 'modules', ['CORE_TEST' => ['provider' => 'scripted']]);

        $provider = $this->factory()->forSettings(new EnvProviderSettings('core_test'));

        $this->assertSame(Injector::inst()->get(ScriptedProvider::class), $provider);
    }

    public function testInjectorFactoryUsesSettingsPassedAsTheFirstConstructorArgument(): void
    {
        Config::modify()->merge(EnvProviderSettings::class, 'modules', ['CORE_TEST' => ['provider' => 'gemini']]);
        Injector::inst()->load([
            'CoreTestChatProvider' => [
                'factory' => ProviderFactory::class,
                'constructor' => ['%$CoreTestProviderSettings'],
            ],
            'CoreTestProviderSettings' => [
                'class' => EnvProviderSettings::class,
                'constructor' => ['core_test'],
            ],
        ]);

        $provider = Injector::inst()->get('CoreTestChatProvider');

        $this->assertInstanceOf(GeminiProvider::class, $provider);
        $this->assertSame('CORE_TEST', $provider->getSettings()->getModulePrefix());
    }

    private function factory(): ProviderFactory
    {
        return Injector::inst()->get(ProviderFactory::class);
    }

    private static function request(): ChatRequest
    {
        $provider = new ScriptedProvider();

        return new ChatRequest('', [ChatMessage::fromText(Role::User, 'Hello')], [], $provider->getDefaultOptions());
    }
}
