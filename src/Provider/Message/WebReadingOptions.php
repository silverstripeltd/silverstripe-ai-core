<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * Asks the provider to let the model read public web pages during the call (see
 * ProviderCapability::WebReading). A provider that cannot do so ignores it, so callers check
 * CapabilityAwareProviderInterface::supports() first.
 *
 * Domains are plain host names without a scheme ("example.com", which also covers its
 * subdomains). When both lists are given, only the allowed list is sent, since vendors accept
 * one or the other.
 */
final readonly class WebReadingOptions
{
    /**
     * @param int|null $maxUses Most pages the model may read in one call; null leaves it to the provider
     * @param array<int, string> $blockedDomains Hosts the model may never read
     * @param array<int, string> $allowedDomains When not empty, the only hosts the model may read
     * @param int|null $maxContentTokens Approximate cap on the text of one page that enters the
     *     conversation; null leaves it to the provider
     */
    public function __construct(
        public ?int $maxUses = null,
        public array $blockedDomains = [],
        public array $allowedDomains = [],
        public ?int $maxContentTokens = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['max_uses']) ? (int) $data['max_uses'] : null,
            self::domains($data['blocked_domains'] ?? []),
            self::domains($data['allowed_domains'] ?? []),
            isset($data['max_content_tokens']) ? (int) $data['max_content_tokens'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'max_uses' => $this->maxUses,
            'blocked_domains' => $this->blockedDomains,
            'allowed_domains' => $this->allowedDomains,
            'max_content_tokens' => $this->maxContentTokens,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function domains(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_map('strval', array_filter($value, 'is_scalar')))
            : [];
    }
}
