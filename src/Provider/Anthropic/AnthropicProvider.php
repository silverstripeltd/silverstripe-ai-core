<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Anthropic;

use GuzzleHttp\ClientInterface;
use SilverstripeLtd\AiCore\Provider\HttpChatProvider;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\ProviderCapability;
use SilverstripeLtd\AiCore\Settings\ProviderSettingsInterface;
use SilverstripeLtd\AiCore\Settings\WorkspaceSettingsInterface;

/**
 * Chat provider backed by the Anthropic Messages API.
 *
 * One HTTP request per chat() call and no retries; callers decide whether a transient failure
 * is worth another attempt. See HttpChatProvider for error classification and redaction.
 *
 * When the settings implement WorkspaceSettingsInterface and name a workspace, every request
 * carries it in the anthropic-workspace-id header (needed for a key that is not scoped to a
 * workspace). The workspace id is redacted from error detail like the key.
 *
 * Web reading runs on Anthropic's servers through its web fetch tool: see RequestMapper for
 * the request and ResponseParser for the blocks that come back.
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
    private const string HEADER_WORKSPACE = 'anthropic-workspace-id';

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

    public function supports(ProviderCapability $capability): bool
    {
        return $capability === ProviderCapability::WebReading;
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
        $headers = [
            self::HEADER_API_KEY => $apiKey,
            self::HEADER_VERSION => self::API_VERSION,
        ];
        $workspaceId = $this->resolveWorkspaceId();

        if ($workspaceId !== null) {
            $headers[self::HEADER_WORKSPACE] = $workspaceId;
        }

        return $headers;
    }

    protected function getRedactedValues(): array
    {
        $workspaceId = $this->resolveWorkspaceId();

        return $workspaceId === null
            ? []
            : [$workspaceId];
    }

    protected function toPayload(ChatRequest $request): array
    {
        return $this->mapper->toPayload($request);
    }

    protected function parse(array $data): ChatResponse
    {
        return $this->parser->parse($data);
    }

    /**
     * The settings' workspace, or null when they cannot name one or leave it blank.
     */
    private function resolveWorkspaceId(): ?string
    {
        $settings = $this->getSettings();

        if (!$settings instanceof WorkspaceSettingsInterface) {
            return null;
        }

        $workspaceId = trim((string) $settings->getWorkspaceId());

        return $workspaceId === ''
            ? null
            : $workspaceId;
    }
}
