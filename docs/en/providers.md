# Providers

Every module built on ai-core talks to a chat model through
`SilverstripeLtd\AiCore\Provider\ChatProviderInterface`. Three vendor providers ship for
production use (Anthropic, OpenAI and Gemini) and one scripted provider for development and
tests. All of them pass the same conformance suite, so a caller behaves the same whichever is
configured. Where the vendors genuinely differ, the differences are listed under
[Parity](#parity-what-is-the-same-and-what-differs). Configuration is described in the
[README](../../README.md).

## Vendors

| Provider name | API | Default model | Default max tokens | Key header |
|---|---|---|---|---|
| `anthropic` (default) | Messages API, `POST https://api.anthropic.com/v1/messages` | `claude-opus-5-5` | 16000 | `x-api-key` |
| `openai` | Chat Completions, `POST https://api.openai.com/v1/chat/completions` | `gpt-6.1-sol` | 16000 | `Authorization: Bearer` |
| `gemini` | `generateContent`, `POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` | `gemini-3.8-flash` | 16000 | `x-goog-api-key` |
| `scripted` | none (canned replies) | `scripted` | 1024 | none |

The defaults apply only when the settings return no model or max tokens; modules usually set
their own (see each module's configuration).

The thinking level maps to:

| Provider | Sent as | Accepted values | `none` |
|---|---|---|---|
| Anthropic | `output_config.effort` | `low`, `medium`, `high`, `xhigh`, `max` (Opus 5.5 defaults to `medium`) | nothing sent; thinking cannot be disabled on current models |
| OpenAI | `reasoning_effort` | `none`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max` (model dependent) | sent as `none`, which turns reasoning off |
| Gemini | `generationConfig.thinkingConfig.thinkingLevel` | `low`, `medium`, `high` on Gemini 3.8 Flash (`minimal` is rejected there) | nothing sent, so the model default applies |

An unsupported value is rejected by the vendor with HTTP 400, reported as a permanent
ProviderException with the vendor's message in dev mode.

## Anthropic

- One request per step with native tool definitions and `tool_choice: auto`. Tools are sent
  without `strict`; arguments are validated server side (see [schemas](#tool-schemas)).
- The cacheable prefix (instructions and the tool guide) goes first in the `system` array with
  a `cache_control` breakpoint on its last block; tool definitions render before it, so they
  are cached too. The per request system text follows uncached.
- With `ChatOptions::withConversationCache()` the request also carries a top-level
  `cache_control` (automatic caching): the API puts a second breakpoint on the last block of
  the messages and moves it forward as the conversation grows, so each step of an agent loop
  reads the previous step's whole prompt from cache. It is off by default, because a one off
  request would pay the cache write premium on text that is never read back. The OpenAI and
  Gemini mappers ignore it; both cache the repeated prefix on their own.
- Parallel tool calls are on (the API default); every result returns in one user message.
- Usage: `input_tokens` is uncached input; `cache_read_input_tokens` and
  `cache_creation_input_tokens` are reported separately.
- Workspace: when the settings implement `WorkspaceSettingsInterface` and return a workspace
  id (`AI_WORKSPACE_ID`, `AI_<MODULE>_WORKSPACE_ID` or `workspace_id` in YAML), every request
  carries the `anthropic-workspace-id` header. A key that is not scoped to a workspace needs
  it; without it the API answers HTTP 400. No id, or settings without the interface, sends no
  header. The id is redacted from error detail like the key. OpenAI and Gemini ignore it.
- Web reading (`ProviderCapability::WebReading`): a request with `WebReadingOptions` adds the
  `web_fetch` server tool after the caller's tools, as `web_fetch_20260209` (dynamic filtering:
  the API may run code to trim a page before it enters the context) or `web_fetch_20250910` on
  Haiku and models before Opus and Sonnet 4.6. No beta header is needed and there is no
  charge beyond the tokens the page adds. The API only fetches URLs that already appear in a
  user message or an earlier result, never one that appears only in the model's own text. The
  `server_tool_use` blocks and their `..._tool_result` blocks (including code execution
  results from dynamic filtering, and fetch errors, which arrive as a result whose content is
  `web_fetch_tool_result_error`) become `ServerToolBlock`s holding the API's block unchanged
  and are replayed exactly. `pause_turn` maps to `StopReason::PauseTurn`. An organisation can
  also restrict or turn off web fetch in the Claude Console; a request then gets a
  `url_not_allowed` error result rather than an HTTP error.

## OpenAI

- Chat Completions with `tools` (`type: function`), `tool_choice: auto` and
  `parallel_tool_calls: true`. Output is capped with `max_completion_tokens`;
  `max_tokens` is deprecated and rejected by reasoning models, so it is never sent.
- The cacheable prefix and the per request system prompt become one leading `developer`
  message, prefix first. OpenAI caches the longest repeated prefix automatically;
  `prompt_cache_key` (a hash of the prefix and the tool names) keeps requests that share it
  routed to the same cache.
- Tool call ids (`call_...`) are kept as the `ToolUseBlock` ids, and each result is sent as a
  `tool` message with that `tool_call_id`, in order, directly after the assistant message.
  Chat Completions has no error flag on tool messages; the result content already says
  `"status":"error"`.
- `strict` is `false`. Strict mode needs every property listed in `required` (optional ones
  made nullable) and rejects `patternProperties`, which the mutation tools use for their field
  maps. The model would then send `null` for every omitted optional argument, which the
  canonical schema rejects. Validation stays server side, as for the other vendors.
- Arguments arrive as a JSON string. One that does not decode to an object becomes an empty
  input, which schema validation rejects with errors the model can act on.
- A `refusal` is kept as assistant text with stop reason Other. HTTP 429 with code
  `insufficient_quota` is blocking (billing), not transient.
- Usage: `prompt_tokens` includes `cached_tokens` and `cache_write_tokens`, so both are
  subtracted to give uncached input; `completion_tokens` includes reasoning tokens.

## Gemini

- `generateContent` on `v1beta` with `tools.functionDeclarations` and
  `toolConfig.functionCallingConfig.mode: AUTO`. The key travels only in the
  `x-goog-api-key` header, never in the `?key=` query string, so no URL that an error quotes
  contains it. A configured model id may include the `models/` prefix.
- The cacheable prefix and the per request system prompt become `systemInstruction` parts,
  prefix first, which keeps the start of every request identical for Gemini's implicit cache.
- Declarations use `parametersJsonSchema` (JSON Schema) rather than `parameters` (the older
  OpenAPI subset), because only the former can describe the free form field maps the mutation
  tools take. The schema is reduced to the documented subset first (see
  [schemas](#tool-schemas)).
- Gemini 3 returns an `id` on every `functionCall`; it becomes the `ToolUseBlock` id and is
  sent back on the matching `functionResponse`. Models that send no id get a stable derived
  id (`gemini_call_` and a hash of the response id, position and name); those results are
  matched by name and order, as Gemini pairs id-less calls, and no id is sent back.
- Thought signatures: Gemini 3 rejects a replayed turn whose first `functionCall` lacks its
  `thoughtSignature`. The signature is stored with the call (`ToolUseBlock::$providerData`,
  never sent to the browser) and replayed on the same part. Calls that came from another
  provider, or lost their signature, get Gemini's documented `skip_thought_signature_validator`
  value so a conversation can switch provider. Signatures on text parts are optional and not
  kept.
- Results are sent as `{"output": "..."}`, or `{"error": "..."}` for a failed call, because
  `functionResponse.response` must be an object. A result whose call is no longer in the
  transcript is sent as text. Consecutive contents with the same role are merged.
- `STOP` is reported even when the model calls functions, so any call means stop reason
  ToolUse. A prompt blocked before generation (`promptFeedback.blockReason`) is an empty reply
  with stop reason Other. Thought parts are dropped.
- HTTP 400 with reason `API_KEY_INVALID`, and `FAILED_PRECONDITION` (unsupported region, no
  billing), are blocking like 401 and 403. `RESOURCE_EXHAUSTED` (429) is transient.
- Usage: `promptTokenCount` includes `cachedContentTokenCount`, which is subtracted to give
  uncached input; output is `candidatesTokenCount` plus `thoughtsTokenCount`. Gemini reports
  no cache writes.

## Scripted provider

```
AI_PROVIDER="scripted"
```

No key, no network. Resolved through the Injector it greets on the first turn and echoes the
user's message afterwards, so a UI can be exercised without spending tokens; a module can
rebrand the greeting and the note through the constructor. In tests the same class
(`SilverstripeLtd\AiCore\Testing\ScriptedProvider`) replays a queue of prepared responses
(text, tool calls or closures) and records every request it received.

## Shared behaviour

- One HTTP request per step and no retries inside the provider. Missing key, HTTP 401 and 403
  (plus the vendor specific cases above) are blocking: someone must fix the configuration
  before a retry can succeed. 429, 5xx (including Anthropic's 529) and network failures are
  transient: the caller may retry later. Other 4xx and unreadable bodies are permanent. The vendor's own error text is kept only in dev mode, with the key redacted.
- A failed call's retry hint is kept on the exception as `getRetryAfterSeconds()`: Gemini's
  `RetryInfo` detail (or the "Please retry in" of its message), else the `retry-after-ms` or
  `retry-after` header that Anthropic and OpenAI send. `isDailyQuotaExhausted()` is true when
  a Gemini `QuotaFailure` names a quota id containing `PerDay`.
- Tool names use dots (`records.search`). Anthropic and OpenAI only accept letters, digits,
  `_` and `-`, and Gemini rejects dots in call and response names, so every vendor receives
  `records__search` and the name is mapped back (`Provider\ToolNameCodec`). Wire names must be
  64 characters or fewer, the OpenAI and Anthropic limit, and a tool name must never contain
  `__` itself.
- Token usage is normalised to one convention (`Provider\Message\Usage`): `inputTokens` is
  uncached input, cache reads and writes are separate, output includes reasoning. 

## The conformance guarantee

`tests/php/Conformance/ProviderConformanceTestCase.php` is an abstract suite that
`AnthropicConformanceTest`, `OpenAIConformanceTest`, `GeminiConformanceTest` and
`ScriptedConformanceTest` extend. Each vendor has a fixture directory
(`tests/php/Conformance/fixtures/<provider>/`) of response bodies in that vendor's
documented format, all describing the same conversation, and the suite asserts the same
neutral result for each:

- a text reply (text, EndTurn, message id);
- a single tool call (preceding text kept, dotted name restored, input decoded, id set);
- parallel tool calls (order kept, ids distinct and stable when the same body is parsed again);
- a tool result round trip: the follow-up request replays both calls and carries both results,
  each paired with its call by the vendor's own correlation (id, or name and order), in order,
  with the error flag where the vendor has one;
- a max tokens stop, and vendor specific stops (refusal, safety) mapped to Other;
- usage: 100 uncached input, 20 output (reasoning included), 900 cache reads and 50 cache
  writes where the vendor reports them;
- unicode in both directions, sent as UTF-8 rather than escapes;
- a 200,000 character tool result sent intact;
- a schema with nested objects, enums, arrays and type lists reaching the model with the
  enums and nesting intact, empty property maps encoded as `{}`, and wire names valid for
  every vendor;
- system prompt placement: cacheable prefix first and in order, then the per request system
  prompt, in the vendor's system slot, never in a user turn, and System role transcript
  messages never sent;
- conversation caching: a request that asks for it reaches the model with its conversation
  and system prompt unchanged, and only Anthropic adds a marker (the top-level
  `cache_control`) on the wire;
- the model and the max tokens value on the wire;
- image input: a user message with a prompt and an image reaches the model with both, the
  image in the vendor's base64 form, several images keep their order and media types, and an
  image in an assistant message is left out;
- error mapping: 401 and 403 blocking, 429, 500 and 503 transient, network failure transient,
  400 and malformed bodies permanent, a missing key blocking with no request sent;
- the API key never in any exception message, in dev or live mode, including error bodies
  that echo it, and never in the URL.

The scripted provider runs the scenarios that apply to it (it has no HTTP). The fixtures are
built from each vendor's published request and response shapes; no real API was called. A new
provider earns the same guarantee by extending the suite with its own fixtures and payload
readers. Vendor specific behaviour (thought signatures, derived Gemini ids, OpenAI quota
errors, cache keys) is covered by `tests/php/Provider/{Anthropic,OpenAI,Gemini}/`.

## Tool schemas

A tool's canonical JSON Schema (`ToolSchema::$inputSchema`) is the contract. Callers should
validate the model's arguments against it whatever the vendor was shown, so a rule a dialect
drops is still enforced (Content Engineer's `ArgumentValidator` does). `Provider\Schema\*SchemaDialect` decides what each vendor sees:

| Vendor | Kept | Rewritten | Dropped |
|---|---|---|---|
| Anthropic | everything | none | none |
| OpenAI (non-strict) | everything else | none | `$schema`, `$id`, `$comment` |
| Gemini (`parametersJsonSchema`) | `type` (including lists with `null`), `title`, `description`, `properties`, `required`, `additionalProperties`, `enum`, `minimum`, `maximum`, `items`, `prefixItems`, `minItems`, `maxItems`, `anyOf`, `minLength`, `maxLength`, `pattern`, `minProperties`, `maxProperties`, `format` for `date-time`, `date`, `time` | `const` becomes a one value `enum`; `oneOf` becomes `anyOf`; `patternProperties` becomes an `additionalProperties` schema (`anyOf` for several patterns), so field maps stay maps but lose the key pattern | everything else, notably `$schema`, `$id`, `$ref`, `$defs`, `allOf`, `not`, `if`/`then`/`else`, `uniqueItems`, `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf`, `default`, `examples` and other `format` values |

In every dialect empty maps are encoded as `{}`.

## Parity: what is the same and what differs

The neutral message model maps onto all three APIs, so a tool loop is unchanged. Measured
by the conformance suite, these behave identically: text replies, single and parallel tool
calls, tool result round trips, max tokens stops, error classification, key redaction, unicode,
large results, schema delivery (within each dialect) and system prompt placement.

| Area | Anthropic | OpenAI | Gemini |
|---|---|---|---|
| Parallel tool calls | on by default; results in one user message | `parallel_tool_calls: true`; one `tool` message per result | on; results as `functionResponse` parts in one user content |
| Tool call ids | `toolu_...` from the API | `call_...` from the API | API ids on Gemini 3; derived stable ids on older models, results paired by name and order |
| Replay state | none needed | none needed | thought signature per call, stored and replayed; skip value for foreign calls |
| Schema strictness | JSON Schema as is, not strict | JSON Schema, `strict: false` | documented subset; some rules only enforced server side |
| System prompt | top-level `system` blocks | leading `developer` message | `systemInstruction` parts |
| Prompt caching | explicit `cache_control` breakpoint after the static prefix, plus automatic caching of the conversation when asked; reads and writes reported | automatic prefix caching plus `prompt_cache_key`; reads and writes reported | implicit caching on a stable prefix; reads reported, writes not |
| Token accounting | input excludes cache | prompt includes cache (subtracted) | prompt includes cache (subtracted); thoughts added to output |
| Stop reasons | `end_turn`, `tool_use`, `max_tokens`, `pause_turn` (PauseTurn); `refusal`, `stop_sequence` become Other | `stop`, `tool_calls`, `length`; `content_filter` and refusals become Other | `STOP` (ToolUse when calls are present), `MAX_TOKENS`; `SAFETY`, `RECITATION`, `MALFORMED_FUNCTION_CALL` and the rest become Other |
| Errors beyond the shared mapping | 529 overloaded is transient | 429 `insufficient_quota` is blocking | 400 `API_KEY_INVALID` and `FAILED_PRECONDITION` are blocking |
| Reasoning control | `output_config.effort` | `reasoning_effort` | `thinkingConfig.thinkingLevel` |
| Web reading (`WebReadingOptions`) | `web_fetch` server tool; calls and results as `ServerToolBlock` | not supported; option ignored | not supported; option ignored |
| Image input (`ImageBlock`) | `image` block with a `base64` source in a user message | `image_url` content part holding a data URI; the user message becomes a parts array | `inlineData` part with `mimeType` and `data` |

What this means in practice: a conversation can move between providers (ids are opaque
strings valid everywhere, and Gemini accepts foreign calls through the skip signature), cost
reporting is comparable across vendors, and the one behavioural gap is schema strictness on
Gemini, where the model is not shown some constraints (key patterns of field maps, uniqueness,
exclusive bounds). A caller that validates against the canonical schema rejects those calls with
errors the model can correct, which costs an extra step rather than allowing a bad write.

## Sources

Checked on 2026-10-01:

- Anthropic: the Messages API, tool use and prompt caching reference bundled with the
  `claude-api` skill; models and prices from its model table.
- OpenAI function calling: <https://developers.openai.com/api/docs/guides/function-calling>
- OpenAI Chat Completions reference: <https://developers.openai.com/api/reference/python/resources/chat/subresources/completions/methods/create>
- OpenAI models: <https://developers.openai.com/api/docs/models> and
  <https://developers.openai.com/api/docs/models/gpt-6.1-sol>
- Gemini function calling (generateContent): <https://ai.google.dev/gemini-api/docs/generate-content/function-calling>
- Gemini API reference (FunctionDeclaration, FunctionCall, FunctionResponse, Schema,
  UsageMetadata, FinishReason, ThinkingConfig): <https://ai.google.dev/api/generate-content>
  and <https://ai.google.dev/api/caching>
- Gemini thought signatures: <https://ai.google.dev/gemini-api/docs/generate-content/thought-signatures>
- Gemini JSON Schema support: <https://ai.google.dev/gemini-api/docs/generate-content/structured-output>
- Gemini models: <https://ai.google.dev/gemini-api/docs/models> and
  <https://ai.google.dev/gemini-api/docs/models/gemini-3.8-flash>


## Other providers

`ProviderFactory` maps a provider name to an Injector service:

```yaml
SilverstripeLtd\AiCore\Provider\ProviderFactory:
  providers:
    anthropic: SilverstripeLtd\AiCore\Provider\Anthropic\AnthropicProvider
    openai: SilverstripeLtd\AiCore\Provider\OpenAI\OpenAIProvider
    gemini: SilverstripeLtd\AiCore\Provider\Gemini\GeminiProvider
    scripted: SilverstripeLtd\AiCore\Testing\ScriptedProvider
```

A new provider implements `ChatProviderInterface` (`getName()`, `getDefaultOptions()`,
`chat(ChatRequest): ChatResponse`) over the provider-neutral message DTOs, is registered under
a name here, and extends `SilverstripeLtd\AiCore\Tests\Conformance\ProviderConformanceTestCase`
(autoloaded with the package) with its own fixtures, overriding `getFixtureRoot()`. An HTTP
vendor can extend `HttpChatProvider`, which supplies the transport, error classification,
redaction and the binding to `ProviderSettingsInterface`, and only needs an endpoint, headers,
a request mapper and a response parser. Any other secret it sends in a header (such as a
workspace id) goes in `getRedactedValues()` so it is redacted like the key. Implement `SettingsAwareProviderInterface` (as
`HttpChatProvider` does) so `ProviderFactory::forSettings()` can bind one service to each
module's settings.
