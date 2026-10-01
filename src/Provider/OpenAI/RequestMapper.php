<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\OpenAI;

use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ImageBlock;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolSchema;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\Schema\OpenAISchemaDialect;
use SilverstripeLtd\AiCore\Provider\Schema\SchemaDialectInterface;
use SilverstripeLtd\AiCore\Provider\ToolNameCodec;
use stdClass;

/**
 * Turns a provider neutral ChatRequest into a Chat Completions request body.
 *
 * The cacheable system prefix and the per request system prompt become one leading
 * "developer" message, prefix first, so OpenAI's automatic prefix caching sees the same bytes
 * on every step; prompt_cache_key is derived from that prefix and the tool names to keep
 * requests that share it on the same cache. Assistant tool calls become tool_calls with their
 * ids preserved, and every ToolResultBlock becomes its own "tool" message carrying the call
 * id, in order. Error results are not flagged separately because their content already says
 * so. Output is capped with max_completion_tokens, which also covers reasoning tokens.
 * A user message with images is sent as content parts (text, then image_url parts holding
 * data URIs) in block order; only user messages may carry images, so assistant images are
 * dropped.
 */
final class RequestMapper
{
    public const string ROLE_DEVELOPER = 'developer';
    public const string ROLE_USER = 'user';
    public const string ROLE_ASSISTANT = 'assistant';
    public const string ROLE_TOOL = 'tool';
    public const string TOOL_TYPE_FUNCTION = 'function';
    public const string TOOL_CHOICE_AUTO = 'auto';
    public const string PART_TEXT = 'text';
    public const string PART_IMAGE_URL = 'image_url';
    public const string CACHE_KEY_PREFIX = 'content-engineer-';

    private const int CACHE_KEY_HASH_LENGTH = 32;
    private const string EMPTY_ARGUMENTS = '{}';
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    private readonly SchemaDialectInterface $dialect;

    public function __construct(?SchemaDialectInterface $dialect = null)
    {
        $this->dialect = $dialect ?? new OpenAISchemaDialect();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(ChatRequest $request): array
    {
        $options = $request->options;
        $payload = [
            'model' => $options->model,
            'max_completion_tokens' => $options->maxTokens,
            'messages' => $this->mapMessages($request),
        ];

        if ($request->tools !== []) {
            $payload['tools'] = array_map(fn (ToolSchema $tool): array => $this->mapTool($tool), $request->tools);
            $payload['tool_choice'] = self::TOOL_CHOICE_AUTO;
            $payload['parallel_tool_calls'] = true;
        }

        if ($options->temperature !== ChatOptions::DEFAULT_TEMPERATURE) {
            $payload['temperature'] = $options->temperature;
        }

        if ($options->reasoningEffort !== null) {
            $payload['reasoning_effort'] = $options->reasoningEffort;
        }

        $cacheKey = $this->cacheKey($request);

        if ($cacheKey !== null) {
            $payload['prompt_cache_key'] = $cacheKey;
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapMessages(ChatRequest $request): array
    {
        $mapped = [];
        $developer = $this->developerText($request);

        if ($developer !== '') {
            $mapped[] = ['role' => self::ROLE_DEVELOPER, 'content' => $developer];
        }

        foreach ($request->messages as $message) {
            if ($message->role === Role::System) {
                continue;
            }

            array_push($mapped, ...($message->role === Role::Assistant
                ? $this->mapAssistant($message)
                : $this->mapUserOrTool($message)));
        }

        return $mapped;
    }

    private function developerText(ChatRequest $request): string
    {
        $parts = array_filter(
            [...$request->options->cacheableSystemPrefix, $request->system],
            static fn (string $text): bool => $text !== '',
        );

        return implode("\n\n", $parts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapAssistant(ChatMessage $message): array
    {
        $text = $message->getText();
        $calls = array_map(fn (ToolUseBlock $use): array => [
            'id' => $use->id,
            'type' => self::TOOL_TYPE_FUNCTION,
            'function' => [
                'name' => ToolNameCodec::toWire($use->name),
                'arguments' => $this->encodeArguments($use->input),
            ],
        ], $message->getToolUses());

        if ($text === '' && $calls === []) {
            return [];
        }

        $mapped = [
            'role' => self::ROLE_ASSISTANT,
            'content' => $text === ''
                ? null
                : $text,
        ];

        if ($calls !== []) {
            $mapped['tool_calls'] = $calls;
        }

        return [$mapped];
    }

    /**
     * Tool messages must directly follow the assistant message that made the calls, so they
     * come before any text in the same neutral message.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapUserOrTool(ChatMessage $message): array
    {
        $mapped = [];

        foreach ($message->getToolResults() as $result) {
            $mapped[] = [
                'role' => self::ROLE_TOOL,
                'tool_call_id' => $result->toolUseId,
                'content' => $result->content,
            ];
        }

        if ($message->getImages() !== []) {
            $mapped[] = ['role' => self::ROLE_USER, 'content' => $this->contentParts($message)];

            return $mapped;
        }

        $text = $message->getText();

        if ($text !== '') {
            $mapped[] = ['role' => self::ROLE_USER, 'content' => $text];
        }

        return $mapped;
    }

    /**
     * Text and image blocks as Chat Completions content parts, in their original order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function contentParts(ChatMessage $message): array
    {
        $parts = [];

        foreach ($message->blocks as $block) {
            if ($block instanceof TextBlock && $block->text !== '') {
                $parts[] = ['type' => self::PART_TEXT, 'text' => $block->text];
            } elseif ($block instanceof ImageBlock) {
                $parts[] = ['type' => self::PART_IMAGE_URL, 'image_url' => ['url' => $block->toDataUri()]];
            }
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTool(ToolSchema $tool): array
    {
        return [
            'type' => self::TOOL_TYPE_FUNCTION,
            'function' => [
                'name' => ToolNameCodec::toWire($tool->name),
                'description' => $tool->description,
                'parameters' => $this->dialect->convert($tool->inputSchema),
                'strict' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function encodeArguments(array $input): string
    {
        $encoded = json_encode($input === [] ? new stdClass() : $input, self::JSON_FLAGS);

        return is_string($encoded)
            ? $encoded
            : self::EMPTY_ARGUMENTS;
    }

    private function cacheKey(ChatRequest $request): ?string
    {
        $prefix = $request->options->cacheableSystemPrefix;

        if ($prefix === []) {
            return null;
        }

        $tools = array_map(static fn (ToolSchema $tool): string => $tool->name, $request->tools);
        $hash = hash('sha256', (string) json_encode([$prefix, $tools], self::JSON_FLAGS));

        return self::CACHE_KEY_PREFIX . substr($hash, 0, self::CACHE_KEY_HASH_LENGTH);
    }
}
