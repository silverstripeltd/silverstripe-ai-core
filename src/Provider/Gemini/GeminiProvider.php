<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Gemini;

use GuzzleHttp\ClientInterface;
use SilverstripeLtd\AiCore\Provider\HttpChatProvider;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Settings\ProviderSettingsInterface;

/**
 * Chat provider backed by the Gemini API generateContent method with function calling.
 *
 * The key travels in the x-goog-api-key header, never in the ?key= query parameter, so no URL
 * that a transport error quotes contains it. Gemini reports an invalid key as HTTP 400 with
 * reason API_KEY_INVALID and an unsupported region or missing billing as FAILED_PRECONDITION;
 * both are blocking alongside 401/403 (UNAUTHENTICATED, PERMISSION_DENIED). 429
 * RESOURCE_EXHAUSTED and 5xx are transient.
 */
class GeminiProvider extends HttpChatProvider
{
    public const string NAME = 'gemini';
    public const string ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    public const string METHOD = ':generateContent';
    public const string DEFAULT_MODEL = 'gemini-3.8-flash';
    public const int DEFAULT_MAX_TOKENS = 16000;

    public const string REASON_API_KEY_INVALID = 'API_KEY_INVALID';
    public const array BLOCKING_STATUSES = ['UNAUTHENTICATED', 'PERMISSION_DENIED', 'FAILED_PRECONDITION'];

    private const string LABEL = 'Gemini';
    private const string HEADER_API_KEY = 'x-goog-api-key';
    private const string MODEL_PREFIX = 'models/';

    private readonly RequestMapper $mapper;
    private readonly ResponseParser $parser;

    public function __construct(?ClientInterface $httpClient = null, ?ProviderSettingsInterface $settings = null)
    {
        parent::__construct($httpClient, $settings);

        $this->mapper = new RequestMapper();
        $this->parser = new ResponseParser();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getLabel(): string
    {
        return self::LABEL;
    }

    protected function getDefaultModel(): string
    {
        return self::DEFAULT_MODEL;
    }

    protected function getDefaultMaxTokens(): int
    {
        return self::DEFAULT_MAX_TOKENS;
    }

    /**
     * The model is part of the path; a "models/" prefix in the configured id is accepted.
     */
    protected function getEndpoint(ChatRequest $request): string
    {
        $model = $request->options->model;

        if (str_starts_with($model, self::MODEL_PREFIX)) {
            $model = substr($model, strlen(self::MODEL_PREFIX));
        }

        return self::ENDPOINT_BASE . rawurlencode($model) . self::METHOD;
    }

    protected function getHeaders(string $apiKey): array
    {
        return [self::HEADER_API_KEY => $apiKey];
    }

    protected function toPayload(ChatRequest $request): array
    {
        return $this->mapper->toPayload($request);
    }

    protected function parse(array $data): ChatResponse
    {
        return $this->parser->parse($data);
    }

    protected function isBlockingError(int $status, array $error): bool
    {
        if (parent::isBlockingError($status, $error)) {
            return true;
        }

        $detail = $error['error'] ?? null;

        if (!is_array($detail)) {
            return false;
        }

        if (in_array($detail['status'] ?? null, self::BLOCKING_STATUSES, true)) {
            return true;
        }

        foreach (is_array($detail['details'] ?? null) ? $detail['details'] : [] as $item) {
            if (is_array($item) && ($item['reason'] ?? null) === self::REASON_API_KEY_INVALID) {
                return true;
            }
        }

        return false;
    }
}
