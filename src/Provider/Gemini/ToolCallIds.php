<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Gemini;

/**
 * Ids and replay data for Gemini function calls.
 *
 * Gemini 3 returns an id with every functionCall and expects it back on the matching
 * functionResponse. Older models return none, so the parser derives a stable id from the
 * response id, the call's position and its name. Derived ids carry a prefix so the request
 * mapper knows not to send them back as Gemini ids; their results are matched by name and
 * order instead, which is how Gemini pairs id-less calls.
 */
final class ToolCallIds
{
    public const string GENERATED_PREFIX = 'gemini_call_';

    /** Provider data key holding the thought signature of a functionCall part. */
    public const string KEY_THOUGHT_SIGNATURE = 'gemini.thought_signature';

    /**
     * Signature Gemini documents for replaying calls that did not come from Gemini (or lost
     * their signature), so the strict Gemini 3 validation accepts the turn.
     */
    public const string SKIP_SIGNATURE_VALIDATION = 'skip_thought_signature_validator';

    private const int HASH_LENGTH = 24;

    /**
     * @param array<string, mixed> $args
     */
    public static function generate(string $responseId, int $index, string $name, array $args): string
    {
        $seed = $responseId !== ''
            ? sprintf('%s|%d|%s', $responseId, $index, $name)
            : sprintf('%d|%s|%s', $index, $name, (string) json_encode($args));

        return self::GENERATED_PREFIX . substr(hash('sha256', $seed), 0, self::HASH_LENGTH);
    }

    public static function isGenerated(string $id): bool
    {
        return str_starts_with($id, self::GENERATED_PREFIX);
    }
}
