<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Schema;

use stdClass;

/**
 * Walks a JSON Schema, letting a vendor dialect rewrite each schema node, and encodes empty
 * maps and empty subschemas as JSON objects.
 *
 * Only values in schema positions are treated as schemas: property names inside "properties"
 * are never mistaken for keywords.
 */
abstract class SchemaDialect implements SchemaDialectInterface
{
    public const string KEYWORD_TYPE = 'type';
    public const string TYPE_OBJECT = 'object';

    /** Keywords whose value is a single subschema. */
    protected const array SCHEMA_KEYWORDS = [
        'items', 'additionalProperties', 'additionalItems', 'contains', 'not', 'if', 'then', 'else', 'propertyNames',
    ];

    /** Keywords whose value maps names to subschemas. */
    protected const array SCHEMA_MAP_KEYWORDS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    /** Keywords whose value is a list of subschemas. */
    protected const array SCHEMA_LIST_KEYWORDS = ['anyOf', 'oneOf', 'allOf', 'prefixItems'];

    public function convert(array $schema): array
    {
        if ($schema === []) {
            $schema = [self::KEYWORD_TYPE => self::TYPE_OBJECT];
        }

        $converted = $this->node($schema);

        return is_array($converted)
            ? $converted
            : [self::KEYWORD_TYPE => self::TYPE_OBJECT];
    }

    /**
     * Rewrites one schema node before its subschemas are visited.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    abstract protected function transform(array $node): array;

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|stdClass
     */
    private function node(array $node): array|stdClass
    {
        if ($node === []) {
            return new stdClass();
        }

        $node = $this->transform($node);

        foreach (self::SCHEMA_KEYWORDS as $keyword) {
            if (!is_array($node[$keyword] ?? null)) {
                continue;
            }

            $node[$keyword] = array_is_list($node[$keyword]) && $node[$keyword] !== []
                ? array_map(fn (mixed $item): mixed => $this->child($item), $node[$keyword])
                : $this->node($node[$keyword]);
        }

        foreach (self::SCHEMA_MAP_KEYWORDS as $keyword) {
            if (!is_array($node[$keyword] ?? null)) {
                continue;
            }

            $node[$keyword] = $this->map($node[$keyword]);
        }

        foreach (self::SCHEMA_LIST_KEYWORDS as $keyword) {
            if (!is_array($node[$keyword] ?? null)) {
                continue;
            }

            $node[$keyword] = array_values(array_map(fn (mixed $item): mixed => $this->child($item), $node[$keyword]));
        }

        return $node === []
            ? new stdClass()
            : $node;
    }

    private function child(mixed $item): mixed
    {
        return is_array($item)
            ? $this->node($item)
            : $item;
    }

    /**
     * @param array<string, mixed> $map
     */
    private function map(array $map): array|stdClass
    {
        if ($map === []) {
            return new stdClass();
        }

        foreach ($map as $name => $schema) {
            $map[$name] = $this->child($schema);
        }

        return $map;
    }
}
