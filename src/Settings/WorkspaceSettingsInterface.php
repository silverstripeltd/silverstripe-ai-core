<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Settings;

/**
 * Optional companion to ProviderSettingsInterface for settings that can name a vendor
 * workspace, such as an Anthropic workspace for an API key that is not scoped to one.
 *
 * Kept separate so existing ProviderSettingsInterface implementations keep working unchanged:
 * a provider only reads the workspace when its settings also implement this interface.
 * Providers whose vendor has no workspace concept ignore it.
 *
 * Treat the value like the API key: never log it or put it in a message.
 */
interface WorkspaceSettingsInterface
{
    /**
     * The workspace every request should run in, or null to send none.
     */
    public function getWorkspaceId(): ?string;
}
