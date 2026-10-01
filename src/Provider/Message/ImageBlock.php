<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

use InvalidArgumentException;

/**
 * An image sent to the model as input, held as base64 data with its media type.
 *
 * Only the formats every supported vendor accepts are allowed, and the decoded image must fit
 * within MAX_BYTES, the smallest per image limit among them, so a request that carries an
 * image is never refused for its size or type by one provider and accepted by another.
 * Callers shrink large images before building the block.
 */
final readonly class ImageBlock implements BlockInterface
{
    public const string TYPE = 'image';

    public const string MEDIA_JPEG = 'image/jpeg';
    public const string MEDIA_PNG = 'image/png';
    public const string MEDIA_GIF = 'image/gif';
    public const string MEDIA_WEBP = 'image/webp';

    public const array MEDIA_TYPES = [self::MEDIA_JPEG, self::MEDIA_PNG, self::MEDIA_GIF, self::MEDIA_WEBP];

    /**
     * Largest decoded image accepted, in bytes (5 MB).
     */
    public const int MAX_BYTES = 5242880;

    private const string BASE64_PATTERN = '/^[A-Za-z0-9+\/]+={0,2}$/';

    /**
     * @param string $data Base64 encoded image bytes, without a data: prefix
     * @throws InvalidArgumentException When the media type is not supported, the data is not
     *     base64 or the image is larger than MAX_BYTES.
     */
    public function __construct(public string $mediaType, public string $data)
    {
        if (!in_array($mediaType, self::MEDIA_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Image media type "%s" is not supported; use one of %s.',
                $mediaType,
                implode(', ', self::MEDIA_TYPES),
            ));
        }

        if ($data === '' || strlen($data) % 4 !== 0 || preg_match(self::BASE64_PATTERN, $data) !== 1) {
            throw new InvalidArgumentException('Image data must be non-empty base64 without a data: prefix.');
        }

        if (self::decodedLength($data) > self::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'The image is larger than the %d byte limit for model input.',
                self::MAX_BYTES,
            ));
        }
    }

    /**
     * Builds the block from raw image bytes.
     *
     * @throws InvalidArgumentException
     */
    public static function fromBinary(string $bytes, string $mediaType): self
    {
        return new self($mediaType, base64_encode($bytes));
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['media_type'] ?? ''), (string) ($data['data'] ?? ''));
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'media_type' => $this->mediaType,
            'data' => $this->data,
        ];
    }

    /**
     * "data:image/png;base64,..." for vendors that take images as URLs.
     */
    public function toDataUri(): string
    {
        return sprintf('data:%s;base64,%s', $this->mediaType, $this->data);
    }

    /**
     * Size of the decoded image in bytes.
     */
    public function byteLength(): int
    {
        return self::decodedLength($this->data);
    }

    private static function decodedLength(string $data): int
    {
        return intdiv(strlen($data), 4) * 3 - (strlen($data) - strlen(rtrim($data, '=')));
    }
}
