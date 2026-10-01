<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Schema;

/**
 * Converts the canonical schema to the JSON Schema subset Gemini documents for
 * `parametersJsonSchema`.
 *
 * Kept: type (including type lists with "null"), title, description, properties, required,
 * additionalProperties (boolean or schema), enum, minimum, maximum, items, prefixItems,
 * minItems, maxItems, anyOf, and the string and object bounds the OpenAPI Schema object also
 * has (minLength, maxLength, pattern, minProperties, maxProperties). format is kept only for
 * date-time, date and time.
 *
 * Rewritten: const becomes a one value enum; oneOf becomes anyOf; patternProperties becomes
 * additionalProperties with the value schema (anyOf when there are several), so free form
 * field maps still reach the model as maps. The key pattern itself is lost.
 *
 * Dropped: everything else, notably $schema, $id, $ref, $defs, allOf, not, if/then/else,
 * uniqueItems, exclusiveMinimum, exclusiveMaximum, multipleOf, default, examples and other
 * format values. ArgumentValidator still enforces all of them against the canonical schema.
 */
final class GeminiSchemaDialect extends SchemaDialect
{
    public const array ALLOWED_KEYWORDS = [
        'type', 'title', 'description', 'properties', 'required', 'additionalProperties', 'enum',
        'format', 'minimum', 'maximum', 'items', 'prefixItems', 'minItems', 'maxItems', 'anyOf',
        'minLength', 'maxLength', 'pattern', 'minProperties', 'maxProperties',
    ];

    public const array ALLOWED_FORMATS = ['date-time', 'date', 'time'];

    protected function transform(array $node): array
    {
        if (array_key_exists('const', $node) && !array_key_exists('enum', $node)) {
            $node['enum'] = [$node['const']];
        }

        if (is_array($node['oneOf'] ?? null) && !array_key_exists('anyOf', $node)) {
            $node['anyOf'] = $node['oneOf'];
        }

        $node = $this->foldPatternProperties($node);

        if (array_key_exists('format', $node) && !in_array($node['format'], self::ALLOWED_FORMATS, true)) {
            unset($node['format']);
        }

        return array_intersect_key($node, array_flip(self::ALLOWED_KEYWORDS));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function foldPatternProperties(array $node): array
    {
        $patterns = $node['patternProperties'] ?? null;

        if (!is_array($patterns) || $patterns === [] || ($node['additionalProperties'] ?? false) !== false) {
            return $node;
        }

        $schemas = array_values(array_filter($patterns, 'is_array'));

        if ($schemas === []) {
            return $node;
        }

        $node['additionalProperties'] = count($schemas) === 1
            ? $schemas[0]
            : ['anyOf' => $schemas];

        return $node;
    }
}
