<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * Token accounting for one model call, normalised to the Anthropic convention.
 *
 * Input tokens are the uncached input billed at the full rate. Cache reads and cache writes are
 * reported separately and are not part of inputTokens, so getTotalInputTokens() is the whole
 * prompt for every provider. Vendors that count cached tokens inside the prompt total (OpenAI,
 * Gemini) have them subtracted by their parser. Output tokens include any reasoning tokens.
 */
final readonly class Usage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['input_tokens'] ?? 0),
            (int) ($data['output_tokens'] ?? 0),
            (int) ($data['cache_read_tokens'] ?? 0),
            (int) ($data['cache_write_tokens'] ?? 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
        ];
    }

    /**
     * Every input token the model read, whether billed at full price or served from cache.
     */
    public function getTotalInputTokens(): int
    {
        return $this->inputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }
}
