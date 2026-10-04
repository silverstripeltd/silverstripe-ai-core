# Changelog

## Unreleased

- Anthropic workspaces: the optional `WorkspaceSettingsInterface::getWorkspaceId()` (kept
  separate so existing `ProviderSettingsInterface` implementations need no change), implemented
  by `EnvProviderSettings` from `AI_<MODULE>_WORKSPACE_ID`, the shared `AI_WORKSPACE_ID` (only
  for the shared provider, like the key) and `workspace_id` in YAML. The Anthropic provider
  sends it as the `anthropic-workspace-id` header, which keys not scoped to a workspace need,
  and redacts it from error detail. Other providers ignore it.

- `ProviderException::getRetryAfterSeconds()` carries the provider's retry hint (Gemini
  `RetryInfo`, `retry-after-ms` and `retry-after` headers) and `isDailyQuotaExhausted()`
  tells a used up Gemini daily quota from a short busy period.

- `ImageBlock`: image input (JPEG, PNG, GIF, WebP up to 5 MB) in user messages, mapped to
  Anthropic image blocks, OpenAI `image_url` parts and Gemini `inlineData` parts, with
  conformance scenarios for every provider. `ProviderConformanceTestCase::wireImages()` is
  optional for providers shipped elsewhere; the image scenarios skip until it is overridden.

## 0.1.0

First release, extracted from Content Engineer's provider layer.

- `ChatProviderInterface` with provider-neutral messages, tool use and tool result blocks,
  tool schemas, usage and stop reasons.
- Anthropic (Messages API), OpenAI (Chat Completions) and Gemini (`generateContent`)
  providers on a shared HTTP transport with one error classification (blocking, transient,
  permanent) and key redaction, plus per vendor schema dialects and tool name encoding.
- `ProviderSettingsInterface` and `EnvProviderSettings`: per module `AI_<MODULE>_*`
  variables, shared `AI_*` fallbacks and YAML module defaults.
- `ProviderFactory::forSettings()` binding one provider service to each module's settings.
- `SimpleCompletion` and `JsonCompletion` for single turn text and JSON replies, with
  brace recovery for JSON wrapped in prose or code fences.
- `ScriptedProvider` and `StubProviderFactory` test doubles, and the provider conformance
  suite exported as `ProviderConformanceTestCase`.
