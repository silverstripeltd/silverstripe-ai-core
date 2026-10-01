<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Provider;

use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Provider\ToolNameCodec;

class ToolNameCodecTest extends SapphireTest
{
    /**
     * @var bool
     */
    protected $usesDatabase = false;

    public function testDotsBecomeDoubleUnderscoresOnTheWireAndBack(): void
    {
        $this->assertSame('records__search', ToolNameCodec::toWire('records.search'));
        $this->assertSame('seo__meta__generate_v2', ToolNameCodec::toWire('seo.meta.generate_v2'));
        $this->assertSame('noop', ToolNameCodec::toWire('noop'));
        $this->assertSame('records.search', ToolNameCodec::fromWire('records__search'));
        $this->assertSame(
            'seo.meta.generate_v2',
            ToolNameCodec::fromWire(ToolNameCodec::toWire('seo.meta.generate_v2')),
        );
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', ToolNameCodec::toWire('assets.update_metadata'));
    }
}
