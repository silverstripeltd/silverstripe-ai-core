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
     * @param WebReadingOptions|null $webReading Lets the model read public web pages on a
     *     provider that supports ProviderCapability::WebReading; null keeps it off
     */
    public function __construct(
        public string $system,
        public array $messages,
        public array $tools,
        public ChatOptions $options,
        public ?WebReadingOptions $webReading = null,
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
        $webReading = $data['web_reading'] ?? null;

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
            is_array($webReading)
                ? WebReadingOptions::fromArray($webReading)
                : null,
        );
    }

    /**
     * The web reading key is only present when web reading was asked for, so requests without
     * it keep their original shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'system' => $this->system,
            'messages' => array_map(static fn (ChatMessage $message): array => $message->toArray(), $this->messages),
            'tools' => array_map(static fn (ToolSchema $tool): array => $tool->toArray(), $this->tools),
            'options' => $this->options->toArray(),
        ];

        if ($this->webReading !== null) {
            $array['web_reading'] = $this->webReading->toArray();
        }

        return $array;
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
