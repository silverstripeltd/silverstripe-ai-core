<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

use InvalidArgumentException;

/**
 * One turn in a conversation: a role and an ordered list of content blocks.
 */
final readonly class ChatMessage
{
    /**
     * @param array<int, BlockInterface> $blocks
     */
    public function __construct(public Role $role, public array $blocks,)
    {
    }

    public static function fromText(Role $role, string $text): self
    {
        return new self($role, [new TextBlock($text)]);
    }

    /**
     * Builds the Tool role message that carries results back to the model.
     *
     * @param array<int, ToolResultBlock> $results
     */
    public static function toolResults(array $results): self
    {
        return new self(Role::Tool, array_values($results));
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException When the role or a block cannot be restored.
     */
    public static function fromArray(array $data): self
    {
        $role = Role::tryFrom((string) ($data['role'] ?? ''));

        if ($role === null) {
            throw new InvalidArgumentException(sprintf('Unknown message role "%s"', (string) ($data['role'] ?? '')));
        }

        $blocks = $data['blocks'] ?? [];

        return new self($role, BlockFactory::listFromArray(is_array($blocks) ? $blocks : []));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'blocks' => BlockFactory::listToArray($this->blocks),
        ];
    }

    /**
     * All text blocks joined by a blank line.
     */
    public function getText(): string
    {
        $texts = [];

        foreach ($this->blocks as $block) {
            if (!($block instanceof TextBlock) || $block->text === '') {
                continue;
            }

            $texts[] = $block->text;
        }

        return implode("\n\n", $texts);
    }

    /**
     * @return array<int, ToolUseBlock>
     */
    public function getToolUses(): array
    {
        return array_values(array_filter(
            $this->blocks,
            static fn (BlockInterface $block): bool => $block instanceof ToolUseBlock,
        ));
    }

    /**
     * @return array<int, ToolResultBlock>
     */
    public function getToolResults(): array
    {
        return array_values(array_filter(
            $this->blocks,
            static fn (BlockInterface $block): bool => $block instanceof ToolResultBlock,
        ));
    }
}
