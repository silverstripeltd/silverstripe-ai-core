<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Anthropic;

use GuzzleHttp\ClientInterface;
use SilverstripeLtd\AiCore\Provider\HttpChatProvider;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Settings\ProviderSettingsInterface;

/**
 * Chat provider backed by the Anthropic Messages API.
 *
 * One HTTP request per chat() call and no retries; callers decide whether a transient failure
 * is worth another attempt. See HttpChatProvider for error classification and redaction.
 */
class AnthropicProvider extends HttpChatProvider
{
    public const string NAME = 'anthropic';
    public const string ENDPOINT = 'https://api.anthropic.com/v1/messages';
    public const string API_VERSION = '2023-06-01';
    public const string DEFAULT_MODEL = 'claude-opus-5-5';
    public const int DEFAULT_MAX_TOKENS = 16000;

    private const string LABEL = 'Anthropic';
    private const string HEADER_API_KEY = 'x-api-key';
    private const string HEADER_VERSION = 'anthropic-version';

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
        return [
            self::HEADER_API_KEY => $apiKey,
            self::HEADER_VERSION => self::API_VERSION,
        ];
    }

    protected function toPayload(ChatRequest $request): array
    {
        return $this->mapper->toPayload($request);
    }

    protected function parse(array $data): ChatResponse
    {
        return $this->parser->parse($data);
    }
}
