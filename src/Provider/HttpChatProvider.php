<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverstripeLtd\AiCore\Provider\Message\ChatOptions;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Settings\ProviderSettingsInterface;

/**
 * Transport shared by the vendor chat providers: one JSON POST per chat() call, no retries,
 * and the same error classification for every vendor.
 *
 * Subclasses supply the endpoint, the headers, the request body and the response parser. The
 * API key only ever travels in a header, never in the URL, so transport errors (which quote
 * the URL) cannot leak it. Exception messages never include the key, and the vendor's own
 * error text is only appended in dev mode because it can describe the request in detail.
 *
 * Classification: missing key, 401 and 403 are blocking; 429, 5xx and network failures are
 * transient; everything else (other 4xx, unreadable bodies) is permanent. Subclasses refine
 * this for vendor specific bodies such as an exhausted quota or an invalid key sent as 400.
 *
 * A failed response's retry hint (the retry-after-ms or retry-after header, in seconds or
 * as a date) is carried on the exception; subclasses may read it from the body instead.
 */
abstract class HttpChatProvider implements SettingsAwareProviderInterface
{

    use Injectable;

    protected const int STATUS_UNAUTHORIZED = 401;
    protected const int STATUS_FORBIDDEN = 403;
    protected const int STATUS_RATE_LIMITED = 429;
    protected const int STATUS_SERVER_ERROR = 500;

    protected const string HEADER_RETRY_AFTER = 'retry-after';
    protected const string HEADER_RETRY_AFTER_MS = 'retry-after-ms';

    private const string REDACTED = '[redacted]';
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param ProviderSettingsInterface|null $settings Null uses the ProviderSettingsInterface
     *     Injector service, which reads the shared AI_* variables by default
     */
    public function __construct(
        private ?ClientInterface $httpClient = null,
        private ?ProviderSettingsInterface $settings = null,
    ) {
    }

    /**
     * Vendor name used at the start of exception messages, such as "Anthropic".
     */
    abstract protected function getLabel(): string;

    abstract protected function getDefaultModel(): string;

    abstract protected function getDefaultMaxTokens(): int;

    abstract protected function getEndpoint(ChatRequest $request): string;

    /**
     * @return array<string, string> Authentication and vendor headers; JSON headers are added
     */
    abstract protected function getHeaders(string $apiKey): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function toPayload(ChatRequest $request): array;

    /**
     * @param array<string, mixed> $data The decoded 2xx response body
     * @throws ProviderException When the body lacks what a reply needs.
     */
    abstract protected function parse(array $data): ChatResponse;

    public function withSettings(ProviderSettingsInterface $settings): static
    {
        $clone = clone $this;
        $clone->settings = $settings;

        return $clone;
    }

    public function getSettings(): ProviderSettingsInterface
    {
        if ($this->settings === null) {
            $this->settings = Injector::inst()->get(ProviderSettingsInterface::class);
        }

        return $this->settings;
    }

    public function getDefaultOptions(): ChatOptions
    {
        $settings = $this->getSettings();

        return new ChatOptions(
            $settings->getModel() ?? $this->getDefaultModel(),
            $settings->getMaxTokens() ?? $this->getDefaultMaxTokens(),
            $settings->getTimeoutSeconds(),
            $settings->getTemperature() ?? ChatOptions::DEFAULT_TEMPERATURE,
            [],
            $settings->getThinkingLevel(),
        );
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $apiKey = $this->resolveApiKey();
        $body = $this->encode($this->toPayload($request));
        $timeout = $request->options->timeoutSeconds;

        try {
            $response = $this->getHttpClient()->request('POST', $this->getEndpoint($request), [
                RequestOptions::HEADERS => $this->getHeaders($apiKey) + [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                RequestOptions::BODY => $body,
                RequestOptions::TIMEOUT => $timeout,
                RequestOptions::CONNECT_TIMEOUT => $timeout,
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (GuzzleException $exception) {
            throw ProviderException::transient(
                sprintf('%s request failed before a response was received.', $this->getLabel())
                    . $this->detail($exception->getMessage(), $apiKey),
                0,
                $exception,
            );
        }

        return $this->parse($this->decode($response, $apiKey));
    }

    /**
     * True when an error response means the credentials or the account need attention.
     *
     * @param array<string, mixed> $error The decoded error body, empty when it was not JSON
     */
    protected function isBlockingError(int $status, array $error): bool
    {
        return $status === self::STATUS_UNAUTHORIZED || $status === self::STATUS_FORBIDDEN;
    }

    /**
     * True when the same request may succeed later. 529 (Anthropic overloaded) is a 5xx.
     *
     * @param array<string, mixed> $error The decoded error body, empty when it was not JSON
     */
    protected function isTransientError(int $status, array $error): bool
    {
        return $status === self::STATUS_RATE_LIMITED || $status >= self::STATUS_SERVER_ERROR;
    }

    /**
     * Whole seconds the provider asked to wait before trying again, null when it did not say.
     *
     * @param array<string, mixed> $error The decoded error body, empty when it was not JSON
     */
    protected function extractRetryAfterSeconds(ResponseInterface $response, array $error): ?int
    {
        $milliseconds = trim($response->getHeaderLine(self::HEADER_RETRY_AFTER_MS));

        if (is_numeric($milliseconds)) {
            return self::wholeSeconds((float) $milliseconds / 1000);
        }

        $value = trim($response->getHeaderLine(self::HEADER_RETRY_AFTER));

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return self::wholeSeconds((float) $value);
        }

        $time = strtotime($value);

        return $time === false
            ? null
            : self::wholeSeconds((float) ($time - time()));
    }

    /**
     * True when the error says a per day allowance has run out. Vendors that say so override this.
     *
     * @param array<string, mixed> $error The decoded error body, empty when it was not JSON
     */
    protected function isDailyQuotaError(array $error): bool
    {
        return false;
    }

    /**
     * A wait rounded up to whole seconds; never less than one second, null for a negative wait.
     */
    protected static function wholeSeconds(float $seconds): ?int
    {
        if ($seconds < 0) {
            return null;
        }

        return max(1, (int) ceil($seconds));
    }

    /**
     * The human readable message in an error body. All three vendors use error.message.
     *
     * @param array<string, mixed> $error
     */
    protected function extractErrorMessage(array $error): string
    {
        $message = $error['error']['message'] ?? '';

        return is_string($message)
            ? $message
            : '';
    }

    /**
     * Settings implementations may signal a missing key with their own exception type; any
     * of them becomes a blocking ProviderException here.
     *
     * @throws ProviderException Blocking, when no key is configured.
     */
    private function resolveApiKey(): string
    {
        try {
            return $this->getSettings()->getApiKey();
        } catch (ProviderException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw ProviderException::blocking($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new ProviderException(
                sprintf('%s request could not be encoded as JSON.', $this->getLabel()),
                false,
                false,
                0,
                $exception,
            );
        }
    }

    /**
     * @return array<string, mixed>
     * @throws ProviderException For non-2xx statuses and unreadable bodies.
     */
    private function decode(ResponseInterface $response, string $apiKey): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw $this->mapHttpError($response, $body, $apiKey);
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderException(
                sprintf('%s returned a response that is not valid JSON.', $this->getLabel()),
                false,
                false,
                $status,
                $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new ProviderException(
                sprintf('%s returned a response that is not a JSON object.', $this->getLabel()),
                false,
                false,
                $status,
            );
        }

        return $decoded;
    }

    private function mapHttpError(ResponseInterface $response, string $body, string $apiKey): ProviderException
    {
        $status = $response->getStatusCode();
        $decoded = json_decode($body, true);
        $error = is_array($decoded)
            ? $decoded
            : [];
        $message = sprintf('%s API request failed with HTTP %d.', $this->getLabel(), $status)
            . $this->detail($this->extractErrorMessage($error), $apiKey);

        if ($this->isBlockingError($status, $error)) {
            return ProviderException::blocking($message, $status);
        }

        if ($this->isTransientError($status, $error)) {
            return ProviderException::transient(
                $message,
                $status,
                null,
                $this->extractRetryAfterSeconds($response, $error),
                $this->isDailyQuotaError($error),
            );
        }

        return new ProviderException($message, false, false, $status);
    }

    /**
     * Provider detail is only surfaced in dev, and never with the key in it.
     */
    private function detail(string $text, string $apiKey): string
    {
        if ($text === '' || !Director::isDev()) {
            return '';
        }

        return ' ' . str_replace($apiKey, self::REDACTED, $text);
    }

    private function getHttpClient(): ClientInterface
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client();
        }

        return $this->httpClient;
    }
}
