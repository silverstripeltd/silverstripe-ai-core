<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Gemini;

use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ImageBlock;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolResultBlock;
use SilverstripeLtd\AiCore\Provider\Message\ToolSchema;
use SilverstripeLtd\AiCore\Provider\Message\ToolUseBlock;
use SilverstripeLtd\AiCore\Provider\Schema\GeminiSchemaDialect;
use SilverstripeLtd\AiCore\Provider\Schema\SchemaDialectInterface;
use SilverstripeLtd\AiCore\Provider\ToolNameCodec;
use stdClass;

/**
 * Turns a provider neutral ChatRequest into a generateContent request body.
 *
 * The cacheable prefix and the per request system prompt become systemInstruction parts,
 * prefix first, which keeps the start of every request identical for Gemini's implicit
 * caching. User and Tool messages are "user" contents and Assistant messages "model"
 * contents; consecutive contents with the same role are merged. Tool calls are replayed as
 * functionCall parts with their thought signature, and results as functionResponse parts
 * carrying the call's name and Gemini id, found by looking the ToolResultBlock's id up among
 * the earlier calls. The result string is sent as {"output": ...}, or {"error": ...} for a
 * failed call, because Gemini expects an object. A result whose call is not in the transcript
 * is sent as text, since a functionResponse needs the function name. Images in user
 * messages become inlineData parts in block order; images from the model are dropped.
 */
final class RequestMapper
{
    public const string ROLE_USER = 'user';
    public const string ROLE_MODEL = 'model';
    public const string MODE_AUTO = 'AUTO';
    public const string RESPONSE_OUTPUT = 'output';
    public const string RESPONSE_ERROR = 'error';

    private const string ORPHAN_RESULT = 'Result of tool call %s: %s';

    private readonly SchemaDialectInterface $dialect;

    public function __construct(?SchemaDialectInterface $dialect = null)
    {
        $this->dialect = $dialect ?? new GeminiSchemaDialect();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(ChatRequest $request): array
    {
        $payload = [];
        $system = $this->mapSystem($request);

        if ($system !== []) {
            $payload['systemInstruction'] = ['parts' => $system];
        }

        $payload['contents'] = $this->mapContents($request->messages);

        if ($request->tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(
                    fn (ToolSchema $tool): array => $this->mapTool($tool),
                    $request->tools,
                ),
            ]];
            $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => self::MODE_AUTO]];
        }

        $payload['generationConfig'] = $this->mapGenerationConfig($request->options);

        return $payload;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function mapSystem(ChatRequest $request): array
    {
        $parts = [];

        foreach ([...$request->options->cacheableSystemPrefix, $request->system] as $text) {
            if ($text === '') {
                continue;
            }

            $parts[] = ['text' => $text];
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapGenerationConfig(ChatOptions $options): array
    {
        $config = ['maxOutputTokens' => $options->maxTokens];

        if ($options->temperature !== ChatOptions::DEFAULT_TEMPERATURE) {
            $config['temperature'] = $options->temperature;
        }

        $effort = $options->getEffectiveReasoningEffort();

        if ($effort !== null) {
            $config['thinkingConfig'] = ['thinkingLevel' => $effort];
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTool(ToolSchema $tool): array
    {
        return [
            'name' => ToolNameCodec::toWire($tool->name),
            'description' => $tool->description,
            'parametersJsonSchema' => $this->dialect->convert($tool->inputSchema),
        ];
    }

    /**
     * @param array<int, ChatMessage> $messages
     * @return array<int, array<string, mixed>>
     */
    private function mapContents(array $messages): array
    {
        $contents = [];
        $calls = $this->indexCalls($messages);

        foreach ($messages as $message) {
            if ($message->role === Role::System) {
                continue;
            }

            $isModel = $message->role === Role::Assistant;
            $parts = $isModel
                ? $this->mapModelParts($message)
                : $this->mapUserParts($message, $calls);

            if ($parts === []) {
                continue;
            }

            $role = $isModel
                ? self::ROLE_MODEL
                : self::ROLE_USER;
            $last = count($contents) - 1;

            if ($last >= 0 && $contents[$last]['role'] === $role) {
                array_push($contents[$last]['parts'], ...$parts);

                continue;
            }

            $contents[] = ['role' => $role, 'parts' => $parts];
        }

        return $contents;
    }

    /**
     * Every tool call in the transcript by id, so a result can be sent with its call's name.
     *
     * @param array<int, ChatMessage> $messages
     * @return array<string, ToolUseBlock>
     */
    private function indexCalls(array $messages): array
    {
        $calls = [];

        foreach ($messages as $message) {
            if ($message->role !== Role::Assistant) {
                continue;
            }

            foreach ($message->getToolUses() as $use) {
                $calls[$use->id] = $use;
            }
        }

        return $calls;
    }

    /**
     * When no call in the message carries a signature, the first one gets the documented
     * skip value.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapModelParts(ChatMessage $message): array
    {
        $parts = [];
        $firstCall = null;
        $signed = false;

        foreach ($message->blocks as $block) {
            if ($block instanceof TextBlock && $block->text !== '') {
                $parts[] = ['text' => $block->text];
            }

            if (!$block instanceof ToolUseBlock) {
                continue;
            }

            $firstCall ??= count($parts);
            $parts[] = $this->mapCall($block);
            $signed = $signed || isset($block->providerData[ToolCallIds::KEY_THOUGHT_SIGNATURE]);
        }

        if ($firstCall !== null && !$signed) {
            $parts[$firstCall]['thoughtSignature'] = ToolCallIds::SKIP_SIGNATURE_VALIDATION;
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCall(ToolUseBlock $use): array
    {
        $call = [
            'name' => ToolNameCodec::toWire($use->name),
            'args' => $use->input === []
                ? new stdClass()
                : $use->input,
        ];

        if (!ToolCallIds::isGenerated($use->id) && $use->id !== '') {
            $call['id'] = $use->id;
        }

        $part = ['functionCall' => $call];
        $signature = $use->providerData[ToolCallIds::KEY_THOUGHT_SIGNATURE] ?? null;

        if (is_string($signature) && $signature !== '') {
            $part['thoughtSignature'] = $signature;
        }

        return $part;
    }

    /**
     * @param array<string, ToolUseBlock> $calls
     * @return array<int, array<string, mixed>>
     */
    private function mapUserParts(ChatMessage $message, array $calls): array
    {
        $parts = [];

        foreach ($message->blocks as $block) {
            if ($block instanceof TextBlock && $block->text !== '') {
                $parts[] = ['text' => $block->text];
            } elseif ($block instanceof ImageBlock) {
                $parts[] = ['inlineData' => ['mimeType' => $block->mediaType, 'data' => $block->data]];
            } elseif ($block instanceof ToolResultBlock) {
                $parts[] = $this->mapResult($block, $calls[$block->toolUseId] ?? null);
            }
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapResult(ToolResultBlock $result, ?ToolUseBlock $call): array
    {
        if ($call === null) {
            return ['text' => sprintf(self::ORPHAN_RESULT, $result->toolUseId, $result->content)];
        }

        $response = [
            'name' => ToolNameCodec::toWire($call->name),
            'response' => [
                ($result->isError ? self::RESPONSE_ERROR : self::RESPONSE_OUTPUT) => $result->content,
            ],
        ];

        if (!ToolCallIds::isGenerated($call->id) && $call->id !== '') {
            $response['id'] = $call->id;
        }

        return ['functionResponse' => $response];
    }
}
