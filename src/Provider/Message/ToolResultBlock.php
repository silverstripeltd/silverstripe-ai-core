<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * The outcome of running a tool, sent back to the model.
 */
final readonly class ToolResultBlock implements BlockInterface
{
    public const string TYPE = 'tool_result';

    public function __construct(public string $toolUseId, public string $content, public bool $isError = false,)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['tool_use_id'] ?? ''),
            (string) ($data['content'] ?? ''),
            (bool) ($data['is_error'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'tool_use_id' => $this->toolUseId,
            'content' => $this->content,
            'is_error' => $this->isError,
        ];
    }
}
