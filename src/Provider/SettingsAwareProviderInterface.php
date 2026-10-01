<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider;

use SilverstripeLtd\AiCore\Settings\ProviderSettingsInterface;

/**
 * A chat provider whose credentials and defaults come from a ProviderSettingsInterface.
 *
 * ProviderFactory::forSettings() calls withSettings() so one registered provider service can
 * serve several modules, each with its own key, model and limits.
 */
interface SettingsAwareProviderInterface extends ChatProviderInterface
{
    /**
     * A copy of this provider bound to the given settings. The original is left unchanged.
     */
    public function withSettings(ProviderSettingsInterface $settings): static;

    public function getSettings(): ProviderSettingsInterface;
}
