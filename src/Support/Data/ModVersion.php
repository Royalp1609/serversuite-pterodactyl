<?php

namespace ServerSuite\Support\Data;

/**
 * A single available version of a mod as returned by a RepositoryContract driver.
 */
readonly class ModVersion
{
    public function __construct(
        /** Repository version identifier */
        public string $versionId,

        /** Human-readable version string (e.g. '1.2.3' or '1.2.3+1.21') */
        public string $versionNumber,

        /** Loader slugs this specific version supports */
        public array $loaders,

        /** Game versions this specific version supports */
        public array $gameVersions,

        /** Direct download URL for the primary .jar / .zip file */
        public string $downloadUrl,

        /** The filename that should be written to the server */
        public string $filename,

        /** File size in bytes (0 if unknown) */
        public int $fileSize,

        /** Optional changelog / release notes */
        public ?string $changelog,

        /**
         * File hashes for verification, keyed by algorithm.
         * e.g. ['sha1' => '...', 'sha512' => '...']  (Modrinth)
         *      ['sha1' => '...', 'md5' => '...']      (CurseForge)
         */
        public array $hashes = [],

        /** Release channel: release | beta | alpha */
        public string $channel = 'release',

        /**
         * Source-ids of REQUIRED dependencies for this version, in the same
         * source namespace (e.g. Modrinth project ids, CurseForge mod ids).
         *
         * @var string[]
         */
        public array $dependencies = [],

        /** Download count for this specific version (0 if unknown). */
        public int $downloads = 0,

        /** ISO publish timestamp where the source provides it. */
        public ?string $publishedAt = null,
    ) {}

    public function toArray(): array
    {
        return [
            'version_id'     => $this->versionId,
            'version_number' => $this->versionNumber,
            'loaders'        => $this->loaders,
            'game_versions'  => $this->gameVersions,
            'download_url'   => $this->downloadUrl,
            'filename'       => $this->filename,
            'file_size'      => $this->fileSize,
            'changelog'      => $this->changelog,
            'hashes'         => $this->hashes,
            'channel'        => $this->channel,
            'dependencies'   => $this->dependencies,
            'downloads'      => $this->downloads,
            'published_at'   => $this->publishedAt,
        ];
    }
}
