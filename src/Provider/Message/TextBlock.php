<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

final readonly class TextBlock implements BlockInterface
{
    public const string TYPE = 'text';

    public function __construct(public string $text,)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['text'] ?? ''));
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'text' => $this->text,
        ];
    }
}
