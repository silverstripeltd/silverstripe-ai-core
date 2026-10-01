<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * Everything a provider needs for one model call.
 */
final readonly class ChatRequest
{
    /**
     * @param string $system The per request system prompt, sent after any cacheable prefix
     * @param array<int, ChatMessage> $messages The transcript, oldest first
     * @param array<int, ToolSchema> $tools Tools the model may call
     */
    public function __construct(
        public string $system,
        public array $messages,
        public array $tools,
        public ChatOptions $options,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $messages = $data['messages'] ?? [];
        $tools = $data['tools'] ?? [];
        $options = $data['options'] ?? [];

        return new self(
            (string) ($data['system'] ?? ''),
            array_values(array_map(
                static fn (array $message): ChatMessage => ChatMessage::fromArray($message),
                array_filter(is_array($messages) ? $messages : [], 'is_array'),
            )),
            array_values(array_map(
                static fn (array $tool): ToolSchema => ToolSchema::fromArray($tool),
                array_filter(is_array($tools) ? $tools : [], 'is_array'),
            )),
            ChatOptions::fromArray(is_array($options) ? $options : []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'system' => $this->system,
            'messages' => array_map(static fn (ChatMessage $message): array => $message->toArray(), $this->messages),
            'tools' => array_map(static fn (ToolSchema $tool): array => $tool->toArray(), $this->tools),
            'options' => $this->options->toArray(),
        ];
    }

    /**
     * The most recent message, or null for an empty transcript.
     */
    public function getLastMessage(): ?ChatMessage
    {
        $count = count($this->messages);

        return $count === 0
            ? null
            : $this->messages[$count - 1];
    }

    /**
     * The text of the most recent User message, used by providers that echo input.
     */
    public function getLastUserText(): string
    {
        for ($index = count($this->messages) - 1; $index >= 0; $index--) {
            $message = $this->messages[$index];

            if ($message->role === Role::User) {
                return $message->getText();
            }
        }

        return '';
    }

    public function hasAssistantMessage(): bool
    {
        foreach ($this->messages as $message) {
            if ($message->role === Role::Assistant) {
                return true;
            }
        }

        return false;
    }
}
