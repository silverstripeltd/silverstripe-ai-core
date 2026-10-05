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

    /**
     * The provider paused a long turn of its own server side tool calls. Send the
     * conversation again, with this reply and nothing after it, and the turn carries on.
     */
    case PauseTurn = 'pause_turn';

    /** Output was cut off by the max tokens limit. */
    case MaxTokens = 'max_tokens';

    /** Any provider specific reason with no normalised equivalent. */
    case Other = 'other';
}
