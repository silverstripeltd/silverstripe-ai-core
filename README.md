# Silverstripe AI core

One provider layer for every Silverstripe AI module: Anthropic, OpenAI and Gemini chat
providers behind a single provider-neutral interface, shared configuration with per-module
overrides, single turn text and JSON helpers, and test doubles.

Used by Content Engineer, AI SEO, AI Refine, AI Compose and AI Translate. A site sets one key
and every module uses it; a module that needs a different vendor, model or key overrides just
that.

## Requirements

- PHP 8.3+
- Silverstripe framework 6.2+
- Guzzle 7

## Installation

```sh
composer require silverstripeltd/silverstripe-ai-core
```

Modules require it themselves, so a site normally gets it as a dependency.

## Configuration

Set the shared variables once in `.env`:

```
AI_PROVIDER="anthropic"
AI_API_KEY="..."
```

Every module then uses that provider and key with its own defaults (model, max tokens,
timeout). To change one module only, set its own variable; the module variable always wins:

```
AI_SEO_PROVIDER="gemini"
AI_SEO_API_KEY="..."
```

| Setting | Shared variable | Module variable | YAML key | Meaning |
|---|---|---|---|---|
| Provider | `AI_PROVIDER` | `AI_<MODULE>_PROVIDER` | `provider` | `anthropic` (default), `openai`, `gemini`, `scripted`, or any name added to `ProviderFactory.providers` |
| API key | `AI_API_KEY` | `AI_<MODULE>_API_KEY` | `api_key` | One key for whichever provider is active. Prefer the environment |
| Model | `AI_MODEL` | `AI_<MODULE>_MODEL` | `model` | Any model id the vendor accepts. Unset uses the module's, then the provider's, default |
| Max tokens | `AI_MAX_TOKENS` | `AI_<MODULE>_MAX_TOKENS` | `max_tokens` | Output tokens per call; 0 means the default |
| Timeout | `AI_REQUEST_TIMEOUT` | `AI_<MODULE>_REQUEST_TIMEOUT` | `request_timeout` | Seconds per call, 90 when nothing sets it |
| Temperature | `AI_TEMPERATURE` | `AI_<MODULE>_TEMPERATURE` | `temperature` | Unset leaves the vendor default and sends nothing |
| Thinking level | `AI_THINKING_LEVEL` | `AI_<MODULE>_THINKING_LEVEL` | `thinking_level` | Passed as is: Anthropic effort, OpenAI `reasoning_effort`, Gemini `thinkingLevel`. `none` sends nothing (OpenAI receives `none`) |

`<MODULE>` is the module's prefix: `SEO`, `REFINE`, `COMPOSE`, `TRANSLATE`, `CONTENT_ENGINEER`.

### Resolution order

For each setting the first non blank value wins:

1. `AI_<MODULE>_<NAME>`
2. `AI_<NAME>`
3. YAML `EnvProviderSettings.modules.<MODULE>.providers.<provider>.<key>`, then
   `EnvProviderSettings.modules.<MODULE>.<key>` (where modules keep their defaults)
4. YAML `EnvProviderSettings.shared.providers.<provider>.<key>`, then
   `EnvProviderSettings.shared.<key>`
5. the provider's built-in default

Two exceptions for the API key and model:

- The shared `AI_API_KEY` and `AI_MODEL` belong to the shared provider, which is `AI_PROVIDER`,
  or `anthropic` when that is unset. They are used only for a module whose resolved provider
  (from any step above) is that same provider, so a key for one vendor is never sent to another.
  Set `AI_PROVIDER` whenever you set `AI_API_KEY` for a vendor other than Anthropic.
- A key or model in the module's own YAML entry (step 3) wins over the shared variable
  (step 2), since it was set for that module specifically.

Invalid values (a non numeric timeout, a negative max tokens) raise a blocking
`SettingsException` naming the variable, never echoing its value.

### Module defaults in YAML

A module ships its defaults as YAML, and a project can override them the same way:

```yaml
SilverstripeLtd\AiCore\Settings\EnvProviderSettings:
  modules:
    SEO:
      provider: gemini
      max_tokens: 2000
      request_timeout: 15
      providers:
        anthropic:
          model: claude-haiku-4-5
        gemini:
          model: gemini-3.1-flash-lite
          thinking_level: low
```

## Usage

### Text or JSON from one prompt

```php
use SilverstripeLtd\AiCore\Completion\CompletionOptions;
use SilverstripeLtd\AiCore\Completion\JsonCompletion;
use SilverstripeLtd\AiCore\Completion\SimpleCompletion;
use SilverstripeLtd\AiCore\Settings\EnvProviderSettings;

$settings = EnvProviderSettings::forModule('seo');

$text = SimpleCompletion::create($settings)->complete($systemPrompt, $userPrompt);

$data = JsonCompletion::create($settings)->completeJson(
    $systemPrompt,
    $userPrompt,
    new CompletionOptions(maxTokens: 4000),
);
```

`JsonCompletion` decodes the reply as is and, when that fails, the text between the first
`{` and the last `}`, which recovers JSON wrapped in prose or code fences. An unrecoverable
reply throws a permanent `ProviderException` with `JsonCompletion::MALFORMED_MESSAGE`;
`JsonCompletion::decode()` is available for callers that parse text themselves.

### Multi-turn chat and tools

```php
use SilverstripeLtd\AiCore\Provider\Message\ChatMessage;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\Role;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;

$provider = ProviderFactory::singleton()->forSettings($settings);
$response = $provider->chat(new ChatRequest(
    $system,
    [ChatMessage::fromText(Role::User, 'Hello')],
    $toolSchemas,
    $provider->getDefaultOptions(),
));
```

The DTOs under `Provider\Message` (messages, text, image, tool use and tool result blocks,
tool schemas, usage, stop reasons) are the same for every vendor.

### Image input

A user message can carry images next to its text, for vision tasks such as describing a
photo for alternative text:

```php
use SilverstripeLtd\AiCore\Provider\Message\ImageBlock;
use SilverstripeLtd\AiCore\Provider\Message\TextBlock;

$message = new ChatMessage(Role::User, [
    new TextBlock('Describe this image in one sentence.'),
    ImageBlock::fromBinary($bytes, ImageBlock::MEDIA_JPEG),
]);
```

`ImageBlock` takes JPEG, PNG, GIF or WebP up to `ImageBlock::MAX_BYTES` (5 MB decoded), the
limit every vendor accepts; anything else throws `InvalidArgumentException`, so shrink large
images first. Images in assistant messages are not sent, since no vendor accepts them there. Vendor details, the parity table
and the conformance guarantee are in [docs/en/providers.md](docs/en/providers.md).

An Injector service can resolve straight to a provider for a module's settings:

```yaml
SilverStripe\Core\Injector\Injector:
  MyModuleChatProvider:
    factory: SilverstripeLtd\AiCore\Provider\ProviderFactory
    constructor:
      - '%$MyModuleProviderSettings'
  MyModuleProviderSettings:
    class: SilverstripeLtd\AiCore\Settings\EnvProviderSettings
    constructor:
      - MY_MODULE
```

`ChatProviderInterface` itself resolves through the same factory with the
`ProviderSettingsInterface` service, which reads the shared `AI_*` variables.

### Custom settings

A module with its own configuration class implements `ProviderSettingsInterface`
(`getProviderName()`, `getApiKey()`, `getModel()`, `getMaxTokens()`, `getTimeoutSeconds()`,
`getTemperature()`, `getThinkingLevel()`) and passes it wherever an `EnvProviderSettings`
would go. `EnvProviderSettings::getEnvValue()` exposes the environment part of the chain on
its own, so such a class can keep the same variable rules.

## Errors

Every failure is a `Provider\ProviderException`:

- `isBlocking()`: credentials or configuration need a person (missing key, HTTP 401 and 403,
  OpenAI `insufficient_quota`, Gemini `API_KEY_INVALID`, unknown provider, invalid settings).
- `isTransient()`: the same call may succeed later (429, 5xx, network failures).
- neither: the request or the reply is wrong (other 4xx, malformed replies).
- `getRetryAfterSeconds()`: the provider's hint of how long to wait before trying again, in
  whole seconds (Gemini's `RetryInfo` detail, else the `retry-after-ms` or `retry-after`
  header), or null when it gave none.
- `isDailyQuotaExhausted()`: a per day allowance ran out (a Gemini quota id containing
  `PerDay`), so waiting a few seconds will not help.

Nothing retries inside the package; callers decide. Messages never contain an API key, and the
vendor's own error text is only appended in dev mode.

## Testing

`SilverstripeLtd\AiCore\Testing` ships two test doubles:

- `ScriptedProvider` replays queued replies (`ScriptedProvider::text()`, `toolUse()`,
  `toolUses()` or closures that receive the request and may throw) and records every request.
  `always($reply)` answers every request after the queue is used up.
- `StubProviderFactory` returns that provider for any settings and records the settings.

```php
$provider = new ScriptedProvider([ScriptedProvider::text('{"title":"Home"}')]);
Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);
// ...exercise the module, then:
$provider->getLastRequest()->system;
```

Remove the registration in `tearDown()` with
`Injector::inst()->unregisterNamedObject(ProviderFactory::class)`.

Run this package's own suite and coding standard with:

```sh
composer install
composer test
composer phpcs
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
