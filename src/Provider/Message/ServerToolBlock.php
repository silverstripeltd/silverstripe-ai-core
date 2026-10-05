<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

use SilverstripeLtd\AiCore\Provider\ProviderCapability;

/**
 * A tool call the provider ran on its own servers, such as reading a web page, or the result
 * it got back. The caller never executes these and never answers them with a tool result.
 *
 * The vendor's block is kept unchanged in $raw, because the vendor needs to see it again,
 * exactly as it sent it, in later requests of the same conversation. Only the provider named
 * in $provider sends it back; other providers leave it out. The other fields are a provider
 * neutral summary for callers that want to show or count the call: the call id that pairs a
 * call with its result, the tool (a ProviderCapability value when it is one, otherwise the
 * vendor's own name), the call's input and whether the result was an error.
 */
final readonly class ServerToolBlock implements BlockInterface
{
    public const string TYPE = 'server_tool';

    /**
     * @param array<string, mixed> $raw The vendor's block exactly as received
     * @param array<string, mixed> $input The call's arguments, such as the URL; empty on a result
     */
    public function __construct(
        public string $provider,
        public ServerToolPart $part,
        public string $callId,
        public string $tool,
        public array $raw,
        public array $input = [],
        public bool $isError = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $raw = $data['raw'] ?? [];
        $input = $data['input'] ?? [];

        return new self(
            (string) ($data['provider'] ?? ''),
            ServerToolPart::tryFrom((string) ($data['part'] ?? '')) ?? ServerToolPart::Call,
            (string) ($data['call_id'] ?? ''),
            (string) ($data['tool'] ?? ''),
            is_array($raw)
                ? $raw
                : [],
            is_array($input)
                ? $input
                : [],
            (bool) ($data['is_error'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'provider' => $this->provider,
            'part' => $this->part->value,
            'call_id' => $this->callId,
            'tool' => $this->tool,
            'input' => $this->input,
            'is_error' => $this->isError,
            'raw' => $this->raw,
        ];
    }

    public function isWebReading(): bool
    {
        return $this->tool === ProviderCapability::WebReading->value;
    }
}
