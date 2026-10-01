<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Schema;

/**
 * Chat Completions function parameters in non-strict mode accept JSON Schema, so only the
 * document level keywords that describe the schema rather than the arguments are dropped.
 *
 * Strict mode (`strict: true`) is not used: it needs every property listed in `required`
 * (optional ones made nullable) and rejects `patternProperties`, which the mutation tools
 * use for their field maps. The model would then send null for every omitted optional
 * argument, which the canonical schema rejects. ArgumentValidator enforces the canonical
 * schema server side instead, exactly as it does for the other providers.
 */
final class OpenAISchemaDialect extends SchemaDialect
{
    /** Keywords removed from every node. */
    public const array DROPPED_KEYWORDS = ['$schema', '$id', '$comment'];

    protected function transform(array $node): array
    {
        return array_diff_key($node, array_flip(self::DROPPED_KEYWORDS));
    }
}
