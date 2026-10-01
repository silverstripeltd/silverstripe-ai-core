<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Testing;

use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Settings\EnvProviderSettings;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;
use SilverstripeLtd\AiCore\Testing\StubProviderFactory;

class StubProviderFactoryTest extends SapphireTest
{
    /**
     * @var bool
     */
    protected $usesDatabase = false;

    public function testReturnsTheGivenProviderForAnySettingsAndRecordsThem(): void
    {
        $provider = new ScriptedProvider();
        $factory = new StubProviderFactory($provider);
        $seo = EnvProviderSettings::forModule('seo');
        $refine = EnvProviderSettings::forModule('refine');

        $this->assertNull($factory->getLastSettings());
        $this->assertSame($provider, $factory->forSettings($seo));
        $this->assertSame($provider, $factory->forSettings($refine));
        $this->assertSame($provider, $factory->getProvider());
        $this->assertSame([$seo, $refine], $factory->getRequestedSettings());
        $this->assertSame($refine, $factory->getLastSettings());
    }

    public function testCannedRepliesCanBeRebranded(): void
    {
        $provider = new ScriptedProvider([], true, 'Hello from a module.', 'Set AI_MODULE_API_KEY.');
        $request = new ChatRequest('', [ChatMessage::fromText(Role::User, 'Hi')], [], $provider->getDefaultOptions());
        $reply = $provider->chat($request)->getText();

        $this->assertStringContainsString('Hello from a module.', $reply);
        $this->assertStringContainsString('You said: "Hi"', $reply);
        $this->assertStringContainsString('Set AI_MODULE_API_KEY.', $reply);
        $this->assertStringNotContainsString(ScriptedProvider::GREETING, $reply);
    }
}
