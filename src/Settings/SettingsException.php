<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Settings;

use SilverstripeLtd\AiCore\Provider\ProviderException;

/**
 * A provider setting is missing or invalid.
 *
 * Always blocking: the call cannot succeed until someone fixes the configuration. Messages
 * name the variable or setting only, never its value, because the value may be a credential.
 */
class SettingsException extends ProviderException
{
    public static function missing(string $setting, string $hint): self
    {
        return new self(sprintf('AI setting "%s" is not configured. %s', $setting, $hint), false, true);
    }

    public static function invalid(string $setting, string $expectation): self
    {
        return new self(sprintf('AI setting "%s" is invalid. Expected %s.', $setting, $expectation), false, true);
    }
}
