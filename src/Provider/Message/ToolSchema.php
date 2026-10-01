<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * A tool the model may call, described by name, purpose and a JSON Schema for its input.
 */
final readonly class ToolSchema
{
    /**
     * @param array<string, mixed> $inputSchema JSON Schema (draft 2020-12) for the tool input object
     */
    public function __construct(public string $name, public string $description, public array $inputSchema,)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $schema = $data['input_schema'] ?? [];

        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['description'] ?? ''),
            is_array($schema)
                ? $schema
                : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
        ];
    }
}
