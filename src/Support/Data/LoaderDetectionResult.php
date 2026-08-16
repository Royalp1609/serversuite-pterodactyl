<?php

namespace ServerSuite\Support\Data;

/**
 * The outcome of a loader-detection attempt.
 *
 * Confidence is 0–100. `signal` records which detector produced the result
 * so the UI (and logs) can explain *why* a loader was chosen, e.g.
 * "egg_uuid" (we recognised the egg) vs "install_script" (we inferred it).
 */
readonly class LoaderDetectionResult
{
    public function __construct(
        public ?string $loader,
        public int $confidence,
        public string $signal,
        public ?string $gameVersion = null,
    ) {}

    /**
     * No loader could be determined.
     */
    public static function unknown(): self
    {
        return new self(null, 0, 'none');
    }

    /**
     * Whether a loader was identified at all.
     */
    public function isResolved(): bool
    {
        return $this->loader !== null;
    }

    /**
     * Whether confidence meets the configured auto-accept threshold.
     */
    public function isConfident(): bool
    {
        $threshold = (int) config('serversuite.detection.auto_accept_threshold', 60);

        return $this->isResolved() && $this->confidence >= $threshold;
    }

    public function toArray(): array
    {
        return [
            'loader'       => $this->loader,
            'confidence'   => $this->confidence,
            'signal'       => $this->signal,
            'game_version' => $this->gameVersion,
            'confident'    => $this->isConfident(),
        ];
    }
}
