<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * Which half of a provider side tool call a ServerToolBlock holds.
 */
enum ServerToolPart: string
{
    /** The model asked the provider to run the tool. */
    case Call = 'call';

    /** What the provider's tool returned. */
    case Result = 'result';
}
