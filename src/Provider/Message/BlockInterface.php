<?php

declare(strict_types=1);

namespace SilverstripeLtd\AiCore\Provider\Message;

/**
 * One content block inside a chat message.
 */
interface BlockInterface
{
    /**
     * Array form with a "type" key so BlockFactory::fromArray() can restore it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
