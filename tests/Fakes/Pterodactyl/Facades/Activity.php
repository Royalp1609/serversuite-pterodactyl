<?php

namespace Pterodactyl\Facades;

/** Test stand-in for the panel's Activity facade: records what would be logged. */
class Activity
{
    /** @var list<array{event: string, subject: mixed, properties: array, actor: mixed}> */
    public static array $logged = [];

    public static bool $fail = false;

    private array $entry = [];

    public static function event(string $event): self
    {
        $a = new self();
        $a->entry = ['event' => $event, 'subject' => null, 'properties' => [], 'actor' => null];

        return $a;
    }

    public function subject(mixed $subject): self
    {
        $this->entry['subject'] = $subject;

        return $this;
    }

    public function property(array $properties): self
    {
        $this->entry['properties'] = $properties;

        return $this;
    }

    public function actor(mixed $actor): self
    {
        $this->entry['actor'] = $actor;

        return $this;
    }

    public function log(): void
    {
        if (self::$fail) {
            throw new \RuntimeException('activity table missing');
        }
        self::$logged[] = $this->entry;
    }
}
