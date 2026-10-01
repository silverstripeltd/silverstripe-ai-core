<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\OpenAI;

use GuzzleHttp\ClientInterface;
use SilverstripeLtd\AiCore\Provider\HttpChatProvider;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Settings\ProviderSettingsInterface;

/**
 * Chat provider backed by the OpenAI Chat Completions API with function tools.
 *
 * Shares transport and error handling with the other vendors through HttpChatProvider. A 429
 * whose error code is insufficient_quota is blocking rather than transient: the account is out
 * of credit, and retrying later does not help until someone adds billing.
 */
class OpenAIProvider extends HttpChatProvider
{
    public const string NAME = 'openai';
    public const string ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    public const string DEFAULT_MODEL = 'gpt-6.1-sol';
    public const int DEFAULT_MAX_TOKENS = 16000;

    public const string ERROR_INSUFFICIENT_QUOTA = 'insufficient_quota';

    private const string LABEL = 'OpenAI';
    private const string HEADER_AUTHORIZATION = 'Authorization';
    private const string BEARER = 'Bearer ';

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

    protected function getEndpoint(ChatRequest $request): string
    {
        return self::ENDPOINT;
    }

    protected function getHeaders(string $apiKey): array
    {
        return [self::HEADER_AUTHORIZATION => self::BEARER . $apiKey];
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
        return parent::isBlockingError($status, $error) || $this->isQuotaExhausted($error);
    }

    protected function isTransientError(int $status, array $error): bool
    {
        return !$this->isQuotaExhausted($error) && parent::isTransientError($status, $error);
    }

    /**
     * @param array<string, mixed> $error
     */
    private function isQuotaExhausted(array $error): bool
    {
        $detail = $error['error'] ?? null;

        if (!is_array($detail)) {
            return false;
        }

        return ($detail['code'] ?? null) === self::ERROR_INSUFFICIENT_QUOTA
            || ($detail['type'] ?? null) === self::ERROR_INSUFFICIENT_QUOTA;
    }
}
