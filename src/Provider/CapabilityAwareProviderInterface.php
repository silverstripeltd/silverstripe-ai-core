<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider;

/**
 * A provider that can say which built-in capabilities it offers. A provider that does not
 * implement this interface offers none.
 */
interface CapabilityAwareProviderInterface extends ChatProviderInterface
{
    public function supports(ProviderCapability $capability): bool;
}
