<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * Why the model stopped generating, normalised across providers.
 */
enum StopReason: string
{
    /** The model finished its turn. */
    case EndTurn = 'end_turn';

    /** The model wants one or more tools executed before it continues. */
    case ToolUse = 'tool_use';

    /** Output was cut off by the max tokens limit. */
    case MaxTokens = 'max_tokens';

    /** Any provider specific reason with no normalised equivalent. */
    case Other = 'other';
}
