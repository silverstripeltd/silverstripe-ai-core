<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Completion;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injectable;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiCore\Settings\ProviderSettingsInterface;

/**
 * A single turn completion whose reply must be a JSON object.
 *
 * The prompt asks the model for JSON only; the reply is decoded as is, and when that fails the
 * text between the first "{" and the last "}" is decoded instead, which recovers answers
 * wrapped in prose or Markdown code fences.
 */
class JsonCompletion
{

    use Injectable;

    public const string MALFORMED_MESSAGE = 'AI provider returned malformed JSON';

    private readonly SimpleCompletion $completion;

    public function __construct(
        ProviderSettingsInterface $settings,
        ?ProviderFactory $factory = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->completion = SimpleCompletion::create($settings, $factory, $logger);
    }

    /**
     * @return array<int|string, mixed>
     * @throws ProviderException On any provider problem, and (permanent) when the reply holds
     *     no JSON object or array.
     */
    public function completeJson(string $system, string $user, ?CompletionOptions $options = null): array
    {
        $decoded = self::decode($this->completion->complete($system, $user, $options));

        if ($decoded === null) {
            throw new ProviderException(self::MALFORMED_MESSAGE);
        }

        return $decoded;
    }

    public function getCompletion(): SimpleCompletion
    {
        return $this->completion;
    }

    /**
     * Decodes a reply, recovering a JSON object embedded in surrounding text.
     *
     * @return array<int|string, mixed>|null Null when no JSON object or array can be found
     */
    public static function decode(string $text): ?array
    {
        $trimmed = trim($text);
        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);

        return is_array($decoded)
            ? $decoded
            : null;
    }
}
