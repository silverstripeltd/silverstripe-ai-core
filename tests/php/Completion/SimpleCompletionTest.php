<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Completion;

use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Completion\CompletionOptions;
use SilverstripeLtd\AiCore\Completion\SimpleCompletion;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\StopReason;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\Usage;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiCore\Settings\EnvProviderSettings;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;
use SilverstripeLtd\AiCore\Testing\StubProviderFactory;

class SimpleCompletionTest extends SapphireTest
{
    /**
     * @var bool
     */
    protected $usesDatabase = false;

    private ScriptedProvider $provider;

    private StubProviderFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new ScriptedProvider();
        $this->factory = new StubProviderFactory($this->provider);
        Injector::inst()->registerService($this->factory, ProviderFactory::class);
    }

    protected function tearDown(): void
    {
        Injector::inst()->unregisterNamedObject(ProviderFactory::class);

        parent::tearDown();
    }

    public function testSendsOneSystemPromptAndOneUserMessage(): void
    {
        $this->provider->queue(ScriptedProvider::text("  The answer.\n"));
        $settings = EnvProviderSettings::forModule('test');

        $text = SimpleCompletion::create($settings)->complete('Be brief.', 'What is it?');
        $request = $this->provider->getLastRequest();

        $this->assertSame('The answer.', $text);
        $this->assertSame('Be brief.', $request->system);
        $this->assertCount(1, $request->messages);
        $this->assertSame(Role::User, $request->messages[0]->role);
        $this->assertSame('What is it?', $request->messages[0]->getText());
        $this->assertSame([], $request->tools);
        $this->assertSame($settings, $this->factory->getLastSettings());
    }

    public function testOptionsOverrideTheProviderDefaults(): void
    {
        $this->provider->queue(ScriptedProvider::text('ok'));
        $defaults = $this->provider->getDefaultOptions();

        SimpleCompletion::create(EnvProviderSettings::forModule('test'))->complete(
            'system',
            'user',
            new CompletionOptions(maxTokens: 20000, temperature: 0.0, thinkingLevel: 'low'),
        );
        $options = $this->provider->getLastRequest()->options;

        $this->assertSame(20000, $options->maxTokens);
        $this->assertSame(0.0, $options->temperature);
        $this->assertSame('low', $options->reasoningEffort);
        $this->assertSame($defaults->model, $options->model);
        $this->assertSame($defaults->timeoutSeconds, $options->timeoutSeconds);
    }

    public function testTextPartsAreJoinedWithoutASeparator(): void
    {
        $this->provider->queue(new ChatResponse(
            new ChatMessage(Role::Assistant, [new TextBlock('{"a":'), new TextBlock('1}')]),
            StopReason::EndTurn,
            new Usage(1, 1),
            'msg_1',
        ));

        $text = SimpleCompletion::create(EnvProviderSettings::forModule('test'))->complete('s', 'u');

        $this->assertSame('{"a":1}', $text);
    }

    public function testAnEmptyReplyIsAPermanentFailure(): void
    {
        $this->provider->queue(ScriptedProvider::text('   '));

        try {
            SimpleCompletion::create(EnvProviderSettings::forModule('test'))->complete('s', 'u');
            $this->fail('Expected a ProviderException');
        } catch (ProviderException $exception) {
            $this->assertFalse($exception->isTransient());
            $this->assertFalse($exception->isBlocking());
            $this->assertStringContainsString('missing content', $exception->getMessage());
        }
    }

    public function testProviderFailuresPassThroughUnchanged(): void
    {
        $failure = ProviderException::transient('Rate limited', 429);
        $this->provider->queue(static function () use ($failure): never {
            throw $failure;
        });

        try {
            SimpleCompletion::create(EnvProviderSettings::forModule('test'))->complete('s', 'u');
            $this->fail('Expected a ProviderException');
        } catch (ProviderException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testRealFactoryAppliesTheModuleSettingsToTheProvider(): void
    {
        Injector::inst()->unregisterNamedObject(ProviderFactory::class);
        $original = [];

        foreach (['AI_PROVIDER', 'AI_TEST_PROVIDER', 'AI_TEST_MODEL', 'AI_TEST_MAX_TOKENS'] as $name) {
            $original[$name] = Environment::getEnv($name);
        }

        Environment::setEnv('AI_PROVIDER', null);
        Environment::setEnv('AI_TEST_PROVIDER', 'openai');
        Environment::setEnv('AI_TEST_MODEL', 'gpt-test');
        Environment::setEnv('AI_TEST_MAX_TOKENS', '321');

        try {
            $provider = SimpleCompletion::create(EnvProviderSettings::forModule('test'))->getProvider();
            $options = $provider->getDefaultOptions();
        } finally {
            foreach ($original as $name => $value) {
                Environment::setEnv($name, $value);
            }
        }

        $this->assertSame('openai', $provider->getName());
        $this->assertSame('gpt-test', $options->model);
        $this->assertSame(321, $options->maxTokens);
    }
}
