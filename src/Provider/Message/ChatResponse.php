<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * The model's reply to a ChatRequest.
 */
final readonly class ChatResponse
{
    public function __construct(
        public ChatMessage $message,
        public StopReason $stopReason,
        public Usage $usage,
        public string $providerMessageId,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $message = $data['message'] ?? [];
        $usage = $data['usage'] ?? [];

        return new self(
            ChatMessage::fromArray(is_array($message) ? $message : []),
            StopReason::tryFrom((string) ($data['stop_reason'] ?? '')) ?? StopReason::Other,
            Usage::fromArray(is_array($usage) ? $usage : []),
            (string) ($data['provider_message_id'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message->toArray(),
            'stop_reason' => $this->stopReason->value,
            'usage' => $this->usage->toArray(),
            'provider_message_id' => $this->providerMessageId,
        ];
    }

    /**
     * @return array<int, ToolUseBlock>
     */
    public function getToolUses(): array
    {
        return $this->message->getToolUses();
    }

    public function getText(): string
    {
        return $this->message->getText();
    }

    public function hasToolUses(): bool
    {
        return $this->getToolUses() !== [];
    }
}
