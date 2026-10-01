<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Gemini;

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
 * Turns a decoded generateContent response body into a ChatResponse.
 *
 * Only the first candidate is read. Thought parts are dropped (they only appear when thoughts
 * are requested, which this module never does). Each functionCall part becomes a ToolUseBlock
 * with Gemini's id, or a stable derived id for models that send none, and keeps the part's
 * thought signature as provider data so it can be replayed. A signature on a text part is not
 * kept: Gemini only enforces signatures on function calls.
 *
 * Gemini reports STOP even when it calls functions, so any call means ToolUse unless the
 * output was cut off. A prompt blocked before generation (promptFeedback.blockReason, no
 * candidates) returns an empty reply with stop reason Other.
 *
 * Usage: promptTokenCount includes cachedContentTokenCount, which is subtracted to give the
 * uncached input; tool use prompt tokens count as input. Output is candidates plus thoughts,
 * both billed as output. Gemini reports no cache writes.
 */
final class ResponseParser
{
    private const string LABEL = 'Gemini';

    private const array STOP_REASONS = [
        'STOP' => StopReason::EndTurn,
        'MAX_TOKENS' => StopReason::MaxTokens,
    ];

    /**
     * @param array<string, mixed> $data
     * @throws ProviderException When the body has neither a candidate nor a block reason.
     */
    public function parse(array $data): ChatResponse
    {
        $candidate = $data['candidates'][0] ?? null;
        $responseId = (string) ($data['responseId'] ?? '');
        $usage = is_array($data['usageMetadata'] ?? null)
            ? $this->parseUsage($data['usageMetadata'])
            : new Usage(0, 0);

        if (!is_array($candidate)) {
            if (isset($data['promptFeedback']['blockReason'])) {
                return new ChatResponse(new ChatMessage(Role::Assistant, []), StopReason::Other, $usage, $responseId);
            }

            throw new ProviderException(sprintf('%s response has no candidates.', self::LABEL));
        }

        $parts = $candidate['content']['parts'] ?? [];
        $blocks = $this->parseParts(is_array($parts) ? $parts : [], $responseId);

        return new ChatResponse(
            new ChatMessage(Role::Assistant, $blocks),
            $this->stopReason((string) ($candidate['finishReason'] ?? ''), $blocks),
            $usage,
            $responseId,
        );
    }

    /**
     * @param array<int, mixed> $parts
     * @return array<int, BlockInterface>
     */
    private function parseParts(array $parts, string $responseId): array
    {
        $blocks = [];
        $callIndex = 0;

        foreach ($parts as $part) {
            if (!is_array($part) || ($part['thought'] ?? false) === true) {
                continue;
            }

            if (is_string($part['text'] ?? null) && $part['text'] !== '') {
                $blocks[] = new TextBlock($part['text']);

                continue;
            }

            if (!is_array($part['functionCall'] ?? null)) {
                continue;
            }

            $blocks[] = $this->parseCall($part, $responseId, $callIndex);
            $callIndex++;
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $part
     */
    private function parseCall(array $part, string $responseId, int $index): ToolUseBlock
    {
        $call = $part['functionCall'];
        $wireName = (string) ($call['name'] ?? '');
        $args = is_array($call['args'] ?? null)
            ? $call['args']
            : [];
        $id = is_string($call['id'] ?? null) && $call['id'] !== ''
            ? $call['id']
            : ToolCallIds::generate($responseId, $index, $wireName, $args);
        $signature = $part['thoughtSignature'] ?? null;

        return new ToolUseBlock(
            $id,
            ToolNameCodec::fromWire($wireName),
            $args,
            is_string($signature) && $signature !== ''
                ? [ToolCallIds::KEY_THOUGHT_SIGNATURE => $signature]
                : [],
        );
    }

    /**
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
        $cached = (int) ($usage['cachedContentTokenCount'] ?? 0);
        $prompt = (int) ($usage['promptTokenCount'] ?? 0) + (int) ($usage['toolUsePromptTokenCount'] ?? 0);

        return new Usage(
            max(0, $prompt - $cached),
            (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            $cached,
        );
    }
}
