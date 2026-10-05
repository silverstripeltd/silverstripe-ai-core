<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider;

/**
 * Something a provider can do on its own servers during a chat call, beyond the tools the
 * caller defines. A caller asks a provider whether it supports one before requesting it.
 */
enum ProviderCapability: string
{
    /** The provider fetches a public web page the conversation names and reads its text. */
    case WebReading = 'web_reading';
}
