<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Schema;

/**
 * The Messages API takes JSON Schema as is, so nothing is dropped. Tools are sent without
 * `strict`, so the schema guides the model and ArgumentValidator enforces it.
 */
final class AnthropicSchemaDialect extends SchemaDialect
{
    protected function transform(array $node): array
    {
        return $node;
    }
}
