<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Completion;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Completion\JsonCompletion;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiCore\Settings\EnvProviderSettings;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;
use SilverstripeLtd\AiCore\Testing\StubProviderFactory;

class JsonCompletionTest extends SapphireTest
{
    /**
     * @var bool
     */
    protected $usesDatabase = false;

    private ScriptedProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new ScriptedProvider();
        Injector::inst()->registerService(new StubProviderFactory($this->provider), ProviderFactory::class);
    }

    protected function tearDown(): void
    {
        Injector::inst()->unregisterNamedObject(ProviderFactory::class);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, array<int|string, mixed>}>
     */
    public static function provideRecoverableReplies(): array
    {
        return [
            'plain object' => ['{"title":"Home"}', ['title' => 'Home']],
            'plain list' => ['[1, 2]', [1, 2]],
            'code fence' => ["```json\n{\"title\": \"Home\"}\n```", ['title' => 'Home']],
            'prose around it' => ['Sure! {"title": "Home", "tags": {"a": 1}} Hope that helps.', ['title' => 'Home', 'tags' => ['a' => 1]]],
            'unicode' => ['{"title":"Ngā mihi 👋"}', ['title' => 'Ngā mihi 👋']],
        ];
    }

    /**
     * @param array<int|string, mixed> $expected
     */
    #[DataProvider('provideRecoverableReplies')]
    public function testRecoversJsonFromTheReply(string $reply, array $expected): void
    {
        $this->provider->queue(ScriptedProvider::text($reply));

        $decoded = JsonCompletion::create(EnvProviderSettings::forModule('test'))->completeJson('s', 'u');

        $this->assertSame($expected, $decoded);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnrecoverableReplies(): array
    {
        return [
            'prose only' => ['I cannot help with that.'],
            'scalar' => ['"just a string"'],
            'broken object' => ['{"title": '],
            'braces in the wrong order' => ['} nothing {'],
        ];
    }

    #[DataProvider('provideUnrecoverableReplies')]
    public function testMalformedRepliesArePermanentFailures(string $reply): void
    {
        $this->provider->queue(ScriptedProvider::text($reply));

        try {
            JsonCompletion::create(EnvProviderSettings::forModule('test'))->completeJson('s', 'u');
            $this->fail('Expected a ProviderException');
        } catch (ProviderException $exception) {
            $this->assertSame(JsonCompletion::MALFORMED_MESSAGE, $exception->getMessage());
            $this->assertFalse($exception->isTransient());
            $this->assertFalse($exception->isBlocking());
        }
    }

    public function testDecodeIsUsableOnItsOwn(): void
    {
        $this->assertSame(['a' => 1], JsonCompletion::decode('x {"a":1} y'));
        $this->assertNull(JsonCompletion::decode(''));
    }
}
