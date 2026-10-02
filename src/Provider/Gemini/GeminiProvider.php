<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Gemini;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
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
 * RESOURCE_EXHAUSTED and 5xx are transient. A 429 says when to try again in a RetryInfo
 * detail ("13.1s"), and names the quota that ran out in a QuotaFailure detail, whose id
 * contains "PerDay" for a daily allowance.
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

    public const string DETAIL_RETRY_INFO = 'google.rpc.RetryInfo';
    public const string DETAIL_QUOTA_FAILURE = 'google.rpc.QuotaFailure';
    public const string DAILY_QUOTA_MARKER = 'PerDay';

    private const string LABEL = 'Gemini';
    private const string KEY_TYPE = '@type';
    private const string DELAY_PATTERN = '/^(\d+(?:\.\d+)?)s$/';
    private const string MESSAGE_DELAY_PATTERN = '/retry in (\d+(?:\.\d+)?)\s*s/i';
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

    /**
     * The RetryInfo detail's delay, else the "Please retry in 13.1s" of the message, else
     * the headers.
     */
    protected function extractRetryAfterSeconds(ResponseInterface $response, array $error): ?int
    {
        foreach (self::details($error, self::DETAIL_RETRY_INFO) as $item) {
            $delay = $item['retryDelay'] ?? null;

            if (is_string($delay) && preg_match(self::DELAY_PATTERN, trim($delay), $match) === 1) {
                return self::wholeSeconds((float) $match[1]);
            }
        }

        if (preg_match(self::MESSAGE_DELAY_PATTERN, $this->extractErrorMessage($error), $match) === 1) {
            return self::wholeSeconds((float) $match[1]);
        }

        return parent::extractRetryAfterSeconds($response, $error);
    }

    protected function isDailyQuotaError(array $error): bool
    {
        foreach (self::details($error, self::DETAIL_QUOTA_FAILURE) as $item) {
            foreach (is_array($item['violations'] ?? null) ? $item['violations'] : [] as $violation) {
                $quotaId = is_array($violation)
                    ? ($violation['quotaId'] ?? '')
                    : '';

                if (is_string($quotaId) && str_contains($quotaId, self::DAILY_QUOTA_MARKER)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The error's details of one type, such as google.rpc.RetryInfo.
     *
     * @param array<string, mixed> $error
     * @return array<int, array<string, mixed>>
     */
    private static function details(array $error, string $type): array
    {
        $details = $error['error']['details'] ?? null;

        if (!is_array($details)) {
            return [];
        }

        return array_values(array_filter(
            $details,
            static fn (mixed $item): bool => is_array($item)
                && is_string($item[self::KEY_TYPE] ?? null)
                && str_ends_with($item[self::KEY_TYPE], $type),
        ));
    }
}
