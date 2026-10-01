<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider;

/**
 * Converts between the module's dotted tool names and the names every vendor accepts.
 *
 * Tool names in this module group capabilities with dots (`records.search`). The Anthropic
 * and OpenAI APIs only allow letters, digits, underscores and hyphens, and Gemini accepts dots
 * in a function declaration but not in the name of a function call or response. Each dot
 * therefore travels as a double underscore for all three and is restored when a tool call
 * comes back. ToolDefinition forbids double underscores in names, which keeps the mapping
 * reversible. The strictest vendor limit on the wire name is 64 characters (OpenAI and
 * Anthropic; Gemini allows 128).
 */
final class ToolNameCodec
{
    public const string SEPARATOR = '.';
    public const string WIRE_SEPARATOR = '__';
    public const int MAX_WIRE_LENGTH = 64;

    public static function toWire(string $name): string
    {
        return str_replace(self::SEPARATOR, self::WIRE_SEPARATOR, $name);
    }

    public static function fromWire(string $name): string
    {
        return str_replace(self::WIRE_SEPARATOR, self::SEPARATOR, $name);
    }
}
