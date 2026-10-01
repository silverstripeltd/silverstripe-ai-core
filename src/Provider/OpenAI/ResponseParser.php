<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\OpenAI;

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
 * Turns a decoded Chat Completions response body into a ChatResponse.
 *
 * Only the first choice is read. Text comes from message.content, a refusal from
 * message.refusal (kept as text so the user sees it, with stop reason Other), and each
 * function tool call becomes a ToolUseBlock keeping OpenAI's call id. Arguments arrive as a
 * JSON string; one that does not decode to an object becomes an empty input, which
 * ArgumentValidator then rejects with errors the model can act on.
 *
 * Usage: prompt_tokens includes cached and cache written tokens, so both are subtracted to
 * give the uncached input the neutral Usage expects. completion_tokens already includes
 * reasoning tokens.
 */
final class ResponseParser
{
    private const string LABEL = 'OpenAI';

    private const array STOP_REASONS = [
        'stop' => StopReason::EndTurn,
        'tool_calls' => StopReason::ToolUse,
        'function_call' => StopReason::ToolUse,
        'length' => StopReason::MaxTokens,
    ];

    /**
     * @param array<string, mixed> $data
     * @throws ProviderException When the body has no choice with a message.
     */
    public function parse(array $data): ChatResponse
    {
        $choice = $data['choices'][0] ?? null;
        $message = is_array($choice)
            ? ($choice['message'] ?? null)
            : null;

        if (!is_array($message)) {
            throw new ProviderException(sprintf('%s response has no choices.', self::LABEL));
        }

        $blocks = $this->parseBlocks($message);
        $refused = is_string($message['refusal'] ?? null) && $message['refusal'] !== '';
        $usage = $data['usage'] ?? [];

        return new ChatResponse(
            new ChatMessage(Role::Assistant, $blocks),
            $refused
                ? StopReason::Other
                : $this->stopReason((string) ($choice['finish_reason'] ?? ''), $blocks),
            $this->parseUsage(is_array($usage) ? $usage : []),
            (string) ($data['id'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $message
     * @return array<int, BlockInterface>
     */
    private function parseBlocks(array $message): array
    {
        $blocks = [];
        $text = $this->parseText($message['content'] ?? null);

        if ($text !== '') {
            $blocks[] = new TextBlock($text);
        }

        $refusal = $message['refusal'] ?? null;

        if (is_string($refusal) && $refusal !== '') {
            $blocks[] = new TextBlock($refusal);
        }

        $calls = $message['tool_calls'] ?? [];

        foreach (is_array($calls) ? $calls : [] as $call) {
            $function = is_array($call)
                ? ($call['function'] ?? null)
                : null;

            if (!is_array($function)) {
                continue;
            }

            $blocks[] = new ToolUseBlock(
                (string) ($call['id'] ?? ''),
                ToolNameCodec::fromWire((string) ($function['name'] ?? '')),
                $this->decodeArguments($function['arguments'] ?? null),
            );
        }

        return $blocks;
    }

    /**
     * Content is a string; a list of text parts is accepted as well.
     */
    private function parseText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $texts = [];

        foreach ($content as $part) {
            if (!is_array($part) || !is_string($part['text'] ?? null)) {
                continue;
            }

            $texts[] = $part['text'];
        }

        return implode('', $texts);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (!is_string($arguments) || trim($arguments) === '') {
            return [];
        }

        $decoded = json_decode($arguments, true);

        return is_array($decoded) && !array_is_list($decoded)
            ? $decoded
            : [];
    }

    /**
     * Tool calls mean ToolUse unless the output was cut off.
     *
     * @param array<int, BlockInterface> $blocks
     */
    private function stopReason(string $finishReason, array $blocks): StopReason
    {
        $reason = self::STOP_REASONS[$finishReason] ?? StopReason::Other;
        $hasToolUse = array_filter(
            $blocks,
            static fn (BlockInterface $block): bool => $block instanceof ToolUseBlock,
        ) !== [];

        return $hasToolUse && $reason !== StopReason::MaxTokens
            ? StopReason::ToolUse
            : $reason;
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function parseUsage(array $usage): Usage
    {
        $details = is_array($usage['prompt_tokens_details'] ?? null)
            ? $usage['prompt_tokens_details']
            : [];
        $cacheRead = (int) ($details['cached_tokens'] ?? 0);
        $cacheWrite = (int) ($details['cache_write_tokens'] ?? 0);

        return new Usage(
            max(0, (int) ($usage['prompt_tokens'] ?? 0) - $cacheRead - $cacheWrite),
            (int) ($usage['completion_tokens'] ?? 0),
            $cacheRead,
            $cacheWrite,
        );
    }
}
