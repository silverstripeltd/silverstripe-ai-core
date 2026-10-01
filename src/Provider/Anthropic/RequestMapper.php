<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Anthropic;

use SilverstripeLtd\AiCore\Provider\Message\BlockInterface;
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ImageBlock;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolResultBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolSchema;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\Schema\AnthropicSchemaDialect;
use SilverstripeLtd\AiCore\Provider\Schema\SchemaDialectInterface;
use SilverstripeLtd\AiCore\Provider\ToolNameCodec;
use stdClass;

/**
 * Turns a provider neutral ChatRequest into a Messages API request body.
 *
 * Role mapping: User and Tool messages are sent as "user" (tool results are user-role
 * tool_result blocks), Assistant as "assistant". System messages never appear in "messages";
 * the system prompt travels in the top-level "system" array instead, with the stable prefix
 * marked for prompt caching. A configured reasoning effort is sent as output_config.effort.
 * Images travel as base64 image blocks in user messages; the API refuses them from the
 * assistant, so an image in an assistant message is dropped.
 */
final class RequestMapper
{
    private const string ROLE_USER = 'user';
    private const string ROLE_ASSISTANT = 'assistant';
    private const array CACHE_CONTROL = ['type' => 'ephemeral'];
    private const array TOOL_CHOICE_AUTO = ['type' => 'auto'];
    private const string SOURCE_BASE64 = 'base64';

    private readonly SchemaDialectInterface $dialect;

    public function __construct(?SchemaDialectInterface $dialect = null)
    {
        $this->dialect = $dialect ?? new AnthropicSchemaDialect();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(ChatRequest $request): array
    {
        $options = $request->options;
        $payload = [
            'model' => $options->model,
            'max_tokens' => $options->maxTokens,
        ];

        $system = $this->mapSystem($request->system, $options->cacheableSystemPrefix);

        if ($system !== []) {
            $payload['system'] = $system;
        }

        if ($request->tools !== []) {
            $payload['tools'] = array_map(fn (ToolSchema $tool): array => $this->mapTool($tool), $request->tools);
            $payload['tool_choice'] = self::TOOL_CHOICE_AUTO;
        }

        $payload['messages'] = $this->mapMessages($request->messages);

        if ($options->temperature !== ChatOptions::DEFAULT_TEMPERATURE) {
            $payload['temperature'] = $options->temperature;
        }

        $effort = $options->getEffectiveReasoningEffort();

        if ($effort !== null) {
            $payload['output_config'] = ['effort' => $effort];
        }

        return $payload;
    }

    /**
     * The cacheable prefix comes first, its last entry carrying cache_control so the whole
     * prefix (and the tool definitions rendered before it) is served from cache. The per
     * request system text follows uncached.
     *
     * @param array<int, string> $prefix
     * @return array<int, array<string, mixed>>
     */
    private function mapSystem(string $system, array $prefix): array
    {
        $blocks = [];
        $prefix = array_values(array_filter($prefix, static fn (string $text): bool => $text !== ''));
        $lastIndex = count($prefix) - 1;

        foreach ($prefix as $index => $text) {
            $block = ['type' => TextBlock::TYPE, 'text' => $text];

            if ($index === $lastIndex) {
                $block['cache_control'] = self::CACHE_CONTROL;
            }

            $blocks[] = $block;
        }

        if ($system !== '') {
            $blocks[] = ['type' => TextBlock::TYPE, 'text' => $system];
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTool(ToolSchema $tool): array
    {
        return [
            'name' => ToolNameCodec::toWire($tool->name),
            'description' => $tool->description,
            'input_schema' => $this->dialect->convert($tool->inputSchema),
        ];
    }

    /**
     * @param array<int, ChatMessage> $messages
     * @return array<int, array<string, mixed>>
     */
    private function mapMessages(array $messages): array
    {
        $mapped = [];

        foreach ($messages as $message) {
            if ($message->role === Role::System) {
                continue;
            }

            $content = $this->mapBlocks($message->blocks, $message->role !== Role::Assistant);

            if ($content === []) {
                continue;
            }

            $mapped[] = [
                'role' => $message->role === Role::Assistant
                    ? self::ROLE_ASSISTANT
                    : self::ROLE_USER,
                'content' => $content,
            ];
        }

        return $mapped;
    }

    /**
     * Empty text blocks are dropped because the API rejects them.
     *
     * @param array<int, BlockInterface> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function mapBlocks(array $blocks, bool $acceptsImages): array
    {
        $content = [];

        foreach ($blocks as $block) {
            if ($block instanceof ImageBlock && !$acceptsImages) {
                continue;
            }

            $mapped = $this->mapBlock($block);

            if ($mapped === null) {
                continue;
            }

            $content[] = $mapped;
        }

        return $content;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapBlock(BlockInterface $block): ?array
    {
        if ($block instanceof TextBlock) {
            return $block->text === ''
                ? null
                : ['type' => TextBlock::TYPE, 'text' => $block->text];
        }

        if ($block instanceof ImageBlock) {
            return [
                'type' => ImageBlock::TYPE,
                'source' => [
                    'type' => self::SOURCE_BASE64,
                    'media_type' => $block->mediaType,
                    'data' => $block->data,
                ],
            ];
        }

        if ($block instanceof ToolUseBlock) {
            return [
                'type' => ToolUseBlock::TYPE,
                'id' => $block->id,
                'name' => ToolNameCodec::toWire($block->name),
                'input' => $block->input === []
                    ? new stdClass()
                    : $block->input,
            ];
        }

        if ($block instanceof ToolResultBlock) {
            $mapped = [
                'type' => ToolResultBlock::TYPE,
                'tool_use_id' => $block->toolUseId,
                'content' => $block->content,
            ];

            if ($block->isError) {
                $mapped['is_error'] = true;
            }

            return $mapped;
        }

        return null;
    }
}
