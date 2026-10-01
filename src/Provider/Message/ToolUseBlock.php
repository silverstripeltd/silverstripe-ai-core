<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * A request from the model to run a tool. The id is echoed back in the matching ToolResultBlock.
 *
 * Provider data holds opaque values a vendor needs to see again when this call is replayed in
 * a later request, such as a Gemini thought signature. Providers that do not recognise a key
 * ignore it, so a transcript can move between providers.
 */
final readonly class ToolUseBlock implements BlockInterface
{
    public const string TYPE = 'tool_use';
    public const string KEY_PROVIDER_DATA = 'provider_data';

    /**
     * @param array<string, mixed> $input Arguments as decoded JSON
     * @param array<string, string> $providerData Vendor specific replay values, keyed by a
     *     vendor prefixed name
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
        public array $providerData = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $input = $data['input'] ?? [];
        $providerData = $data[self::KEY_PROVIDER_DATA] ?? [];

        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['name'] ?? ''),
            is_array($input)
                ? $input
                : [],
            is_array($providerData)
                ? array_map('strval', array_filter($providerData, 'is_scalar'))
                : [],
        );
    }

    /**
     * Provider data is only present when set, so blocks from vendors that need none keep the
     * original four keys.
     */
    public function toArray(): array
    {
        $array = [
            'type' => self::TYPE,
            'id' => $this->id,
            'name' => $this->name,
            'input' => $this->input,
        ];

        if ($this->providerData !== []) {
            $array[self::KEY_PROVIDER_DATA] = $this->providerData;
        }

        return $array;
    }

    /**
     * A copy with different input and the same id, name and provider data.
     *
     * @param array<string, mixed> $input
     */
    public function withInput(array $input): self
    {
        return new self($this->id, $this->name, $input, $this->providerData);
    }
}
