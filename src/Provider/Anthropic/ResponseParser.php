<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Anthropic;

use SilverstripeLtd\AiCore\Provider\Message\BlockInterface;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\ServerToolBlock;
use SilverstripeLtd\AiCore\Provider\Message\ServerToolPart;
use SilverstripeLtd\AiCore\Provider\Message\StopReason;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolResultBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\Message\Usage;
use SilverstripeLtd\AiCore\Provider\ProviderCapability;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ToolNameCodec;

/**
 * Turns a decoded Messages API response body into a ChatResponse.
 *
 * Text and tool_use blocks become their provider neutral blocks. Server tool blocks (a
 * server_tool_use call such as web_fetch, and its "..._tool_result") become ServerToolBlocks
 * that keep the API's block unchanged, since the API needs them back as they were in later
 * requests; web_fetch is reported as ProviderCapability::WebReading. Other block types
 * (thinking) have no provider neutral equivalent and are dropped. A pause_turn stop means the
 * API paused its own server side loop and the caller should send the conversation again.
 */
final class ResponseParser
{
    private const array STOP_REASONS = [
        'end_turn' => StopReason::EndTurn,
        'tool_use' => StopReason::ToolUse,
        'max_tokens' => StopReason::MaxTokens,
        'pause_turn' => StopReason::PauseTurn,
    ];

    private const string SERVER_TOOL_USE = 'server_tool_use';
    private const string TOOL_RESULT_SUFFIX = '_tool_result';
    private const string ERROR_SUFFIX = '_error';

    /** Server tool names with a provider neutral capability. */
    private const array CAPABILITY_TOOLS = [
        RequestMapper::WEB_FETCH_NAME => ProviderCapability::WebReading,
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
            } elseif ($type === self::SERVER_TOOL_USE || self::isServerToolResult($type)) {
                $blocks[] = self::serverToolBlock($type, $item);
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

    private static function isServerToolResult(string $type): bool
    {
        return $type !== ToolResultBlock::TYPE && str_ends_with($type, self::TOOL_RESULT_SUFFIX);
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function serverToolBlock(string $type, array $item): ServerToolBlock
    {
        if ($type === self::SERVER_TOOL_USE) {
            $input = $item['input'] ?? [];

            return new ServerToolBlock(
                AnthropicProvider::NAME,
                ServerToolPart::Call,
                (string) ($item['id'] ?? ''),
                self::neutralToolName((string) ($item['name'] ?? '')),
                $item,
                is_array($input)
                    ? $input
                    : [],
            );
        }

        $content = $item['content'] ?? null;
        $contentType = is_array($content)
            ? (string) ($content['type'] ?? '')
            : '';

        return new ServerToolBlock(
            AnthropicProvider::NAME,
            ServerToolPart::Result,
            (string) ($item['tool_use_id'] ?? ''),
            self::neutralToolName(substr($type, 0, -strlen(self::TOOL_RESULT_SUFFIX))),
            $item,
            [],
            str_ends_with($contentType, self::ERROR_SUFFIX),
        );
    }

    private static function neutralToolName(string $name): string
    {
        return (self::CAPABILITY_TOOLS[$name] ?? null)?->value ?? $name;
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
