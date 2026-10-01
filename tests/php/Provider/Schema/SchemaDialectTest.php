<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Tests\Provider\Schema;

use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Provider\Schema\AnthropicSchemaDialect;
use SilverstripeLtd\AiCore\Provider\Schema\GeminiSchemaDialect;
use SilverstripeLtd\AiCore\Provider\Schema\OpenAISchemaDialect;
use SilverstripeLtd\AiCore\Provider\Schema\SchemaDialectInterface;
use stdClass;

class SchemaDialectTest extends SapphireTest
{
    /**
     * @var bool
     */
    protected $usesDatabase = false;

    /**
     * A schema using every keyword a dialect treats specially, plus properties named after
     * keywords to prove names are never mistaken for keywords.
     *
     * @return array<string, mixed>
     */
    private static function canonical(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'urn:tool:records.update',
            'type' => 'object',
            'properties' => [
                'targets' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'uniqueItems' => true,
                    'items' => [
                        'type' => 'object',
                        'properties' => ['class' => ['type' => 'string'], 'id' => ['type' => 'integer', 'exclusiveMinimum' => 0]],
                        'required' => ['class', 'id'],
                        'additionalProperties' => false,
                    ],
                ],
                'fields' => [
                    'type' => 'object',
                    'minProperties' => 1,
                    'patternProperties' => ['^[A-Za-z][A-Za-z0-9_]*$' => ['type' => ['string', 'integer', 'null']]],
                    'additionalProperties' => false,
                ],
                'mode' => ['const' => 'draft', 'description' => 'Always draft'],
                'when' => ['type' => 'string', 'format' => 'date-time'],
                'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 100],
                'choice' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
                'format' => ['type' => 'string', 'default' => 'html', 'examples' => ['html']],
                'pattern' => ['allOf' => [['type' => 'string']], 'not' => ['const' => '']],
                'empty' => ['type' => 'object', 'properties' => []],
            ],
            'required' => ['targets', 'fields'],
            'additionalProperties' => false,
        ];
    }

    public function testAnthropicKeepsTheSchemaAndEncodesEmptyMapsAsObjects(): void
    {
        $converted = (new AnthropicSchemaDialect())->convert(self::canonical());

        $this->assertSame(self::canonical()['properties']['fields'], $converted['properties']['fields']);
        $this->assertSame('urn:tool:records.update', $converted['$id']);
        $this->assertInstanceOf(stdClass::class, $converted['properties']['empty']['properties']);
        $this->assertSame(['type' => 'object'], (new AnthropicSchemaDialect())->convert([]));
    }

    public function testOpenAIDropsOnlyDocumentKeywords(): void
    {
        $converted = (new OpenAISchemaDialect())->convert(self::canonical());
        $expected = self::canonical();
        unset($expected['$schema'], $expected['$id']);

        $this->assertArrayNotHasKey('$schema', $converted);
        $this->assertArrayNotHasKey('$id', $converted);
        $this->assertSame($expected['properties']['targets'], $converted['properties']['targets']);
        $this->assertSame(
            $expected['properties']['fields']['patternProperties'],
            $converted['properties']['fields']['patternProperties'],
        );
        $this->assertSame(['const' => 'draft', 'description' => 'Always draft'], $converted['properties']['mode']);
    }

    public function testGeminiRewritesAndDropsWhatItDoesNotDocument(): void
    {
        $converted = (new GeminiSchemaDialect())->convert(self::canonical());
        $properties = $converted['properties'];

        $this->assertArrayNotHasKey('$schema', $converted);
        $this->assertArrayNotHasKey('$id', $converted);
        $this->assertFalse($converted['additionalProperties']);
        $this->assertArrayNotHasKey('uniqueItems', $properties['targets']);
        $this->assertSame(['type' => 'integer'], $properties['targets']['items']['properties']['id']);
        $this->assertSame(
            ['type' => 'object', 'minProperties' => 1, 'additionalProperties' => ['type' => ['string', 'integer', 'null']]],
            $properties['fields'],
            'patternProperties becomes an additionalProperties schema',
        );
        $this->assertSame(['description' => 'Always draft', 'enum' => ['draft']], $properties['mode']);
        $this->assertSame('date-time', $properties['when']['format']);
        $this->assertSame(['type' => 'string', 'maxLength' => 100], $properties['email']);
        $this->assertSame(['anyOf' => [['type' => 'string'], ['type' => 'integer']]], $properties['choice']);
        $this->assertSame(
            ['type' => 'string'],
            $properties['format'],
            'a property named "format" is kept as a property',
        );
        $this->assertInstanceOf(
            stdClass::class,
            $properties['pattern'],
            'only unsupported keywords, so an empty schema',
        );
        $this->assertInstanceOf(stdClass::class, $properties['empty']['properties']);
    }

    public function testGeminiFoldsSeveralPatternsIntoAnyOf(): void
    {
        $converted = (new GeminiSchemaDialect())->convert([
            'type' => 'object',
            'patternProperties' => ['^a' => ['type' => 'string'], '^b' => ['type' => 'integer']],
        ]);

        $this->assertSame(
            ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
            $converted['additionalProperties'],
        );
    }

    /**
     * The canonical schema converts for every vendor, encodes as JSON, is left untouched, and
     * for Gemini contains only documented keywords.
     */
    public function testEveryDialectConvertsWithoutChangingTheInput(): void
    {
        $before = self::canonical();

        foreach (self::dialects() as $dialect) {
            $converted = $dialect->convert($before);

            $this->assertSame('object', $converted['type']);
            $this->assertIsString(json_encode($converted, JSON_THROW_ON_ERROR));
        }

        $this->assertSame([], self::undocumentedGeminiKeywords((new GeminiSchemaDialect())->convert($before)));
        $this->assertSame(self::canonical(), $before);
    }

    /**
     * @return array<int, SchemaDialectInterface>
     */
    private static function dialects(): array
    {
        return [new AnthropicSchemaDialect(), new OpenAISchemaDialect(), new GeminiSchemaDialect()];
    }

    /**
     * Keywords in schema positions that Gemini does not document, found by walking the tree.
     *
     * @param array<string, mixed>|stdClass $schema
     * @return array<int, string>
     */
    private static function undocumentedGeminiKeywords(array|stdClass $schema): array
    {
        if ($schema instanceof stdClass) {
            return [];
        }

        $found = array_values(array_diff(array_keys($schema), GeminiSchemaDialect::ALLOWED_KEYWORDS));

        foreach (['items', 'additionalProperties'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null) && !(($schema[$keyword] ?? null) instanceof stdClass)) {
                continue;
            }

            $found = [...$found, ...self::undocumentedGeminiKeywords($schema[$keyword])];
        }

        foreach ($schema['properties'] ?? [] as $property) {
            $found = [...$found, ...self::undocumentedGeminiKeywords($property)];
        }

        foreach (['anyOf', 'prefixItems'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $item) {
                $found = [...$found, ...self::undocumentedGeminiKeywords($item)];
            }
        }

        return $found;
    }
}
