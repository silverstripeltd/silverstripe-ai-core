<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * Who authored a chat message as far as the provider is concerned.
 *
 * This enum is local to the provider layer and string backed so serialised messages stay
 * readable. The persistence layer stores its own integer backed role and maps between the two.
 */
enum Role: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';

    /** A message carrying tool results back to the model. */
    case Tool = 'tool';
}
