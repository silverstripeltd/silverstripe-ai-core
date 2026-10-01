<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Anthropic;

use SilverstripeLtd\AiCore\Provider\Message\BlockInterface;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\StopReason;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\Message\Usage;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ToolNameCodec;

/**
 * Turns a decoded Messages API response body into a ChatResponse.
 *
 * Only text and tool_use content blocks are kept; other block types (thinking, server tool
 * results) have no provider neutral equivalent and are dropped.
 */
final class ResponseParser
{
    private const array STOP_REASONS = [
        'end_turn' => StopReason::EndTurn,
        'tool_use' => StopReason::ToolUse,
        'max_tokens' => StopReason::MaxTokens,
    ];

    /**
     * @param array<string, mixed> $data
     * @throws ProviderException When the body has no content list.
     */
    public function parse(array $data): ChatResponse
    {
        $content = $data['content'] ?? null;

        if (!is_array($content)) {
            throw new ProviderException('Anthropic response has no content blocks.');
        }

        $usage = $data['usage'] ?? [];

        return new ChatResponse(
            new ChatMessage(Role::Assistant, $this->parseBlocks($content)),
            self::STOP_REASONS[(string) ($data['stop_reason'] ?? '')] ?? StopReason::Other,
            $this->parseUsage(is_array($usage) ? $usage : []),
            (string) ($data['id'] ?? ''),
        );
    }

    /**
     * @param array<int, mixed> $content
     * @return array<int, BlockInterface>
     */
    private function parseBlocks(array $content): array
    {
        $blocks = [];

        foreach ($content as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? '');

            if ($type === TextBlock::TYPE) {
                $blocks[] = new TextBlock((string) ($item['text'] ?? ''));
            } elseif ($type === ToolUseBlock::TYPE) {
                $input = $item['input'] ?? [];
                $blocks[] = new ToolUseBlock(
                    (string) ($item['id'] ?? ''),
                    ToolNameCodec::fromWire((string) ($item['name'] ?? '')),
                    is_array($input)
                        ? $input
                        : [],
                );
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function parseUsage(array $usage): Usage
    {
        return new Usage(
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['cache_read_input_tokens'] ?? 0),
            (int) ($usage['cache_creation_input_tokens'] ?? 0),
        );
    }
}
