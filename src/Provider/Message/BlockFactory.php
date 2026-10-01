<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

use InvalidArgumentException;

/**
 * Restores content blocks from their array form by dispatching on the "type" key.
 */
final class BlockFactory
{
    /**
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException When the type is missing or unknown.
     */
    public static function fromArray(array $data): BlockInterface
    {
        $type = (string) ($data['type'] ?? '');

        return match ($type) {
            TextBlock::TYPE => TextBlock::fromArray($data),
            ToolUseBlock::TYPE => ToolUseBlock::fromArray($data),
            ToolResultBlock::TYPE => ToolResultBlock::fromArray($data),
            default => throw new InvalidArgumentException(sprintf('Unknown content block type "%s"', $type)),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, BlockInterface>
     */
    public static function listFromArray(array $items): array
    {
        return array_values(array_map(
            static fn (array $item): BlockInterface => self::fromArray($item),
            array_filter($items, 'is_array'),
        ));
    }

    /**
     * @param array<int, BlockInterface> $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function listToArray(array $blocks): array
    {
        return array_values(array_map(
            static fn (BlockInterface $block): array => $block->toArray(),
            $blocks,
        ));
    }
}
