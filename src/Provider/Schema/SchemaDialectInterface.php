<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Schema;

/**
 * Converts a tool's canonical JSON Schema into the subset one vendor accepts.
 *
 * The canonical schema (ToolDefinition::getInputSchema()) stays the contract: ArgumentValidator
 * always validates arguments against it, whatever a dialect dropped. A dialect only decides
 * what the model is shown. Empty maps come back as objects so they encode as {} rather than [].
 */
interface SchemaDialectInterface
{
    /**
     * @param array<string, mixed> $schema The canonical schema for a tool's arguments object
     * @return array<string, mixed>
     */
    public function convert(array $schema): array;
}
