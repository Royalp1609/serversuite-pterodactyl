<?php

namespace ServerSuite\Support\Data;

/**
 * A normalised mod result returned by any RepositoryContract driver.
 * All fields are typed. Drivers fill what the source API provides.
 */
readonly class ModResult
{
    public function __construct(
        /** The repository driver key (modrinth|curseforge|hangar) */
        public string $source,

        /** The repository's own identifier for this mod */
        public string $id,

        /** URL-friendly slug (may equal $id for some providers) */
        public string $slug,

        /** Display name */
        public string $name,

        /** Short description / summary */
        public string $description,

        /** Author / team name */
        public string $author,

        /** Total download count reported by the repository */
        public int $downloads,

        /** CDN or repository URL for the mod icon image */
        public ?string $iconUrl,

        /** URL to the mod's page on the repository website */
        public ?string $pageUrl,

        /** Loader slugs this mod supports (e.g. ['fabric', 'quilt']) */
        public array $loaders,

        /** Game versions this mod supports (e.g. ['1.21', '1.21.1']) */
        public array $gameVersions,

        /** Source IDs of required dependencies (same $source namespace) */
        public array $dependencies,

        /** Available versions — may be empty until explicitly fetched */
        public array $versions = [],

        /** Full description body (markdown/plain). Modrinth /project body. */
        public ?string $body = null,

        /** Content categories (e.g. ['adventure','utility']). */
        public array $categories = [],

        /** Follower/like count where the source provides it. */
        public ?int $follows = null,

        /** ISO timestamps where the source provides them. */
        public ?string $updatedAt = null,
        public ?string $publishedAt = null,
    ) {}

    public function toArray(): array
    {
        return [
            'source'       => $this->source,
            'id'           => $this->id,
            'slug'         => $this->slug,
            'name'         => $this->name,
            'description'  => $this->description,
            'author'       => $this->author,
            'downloads'    => $this->downloads,
            'icon_url'     => $this->iconUrl,
            'page_url'     => $this->pageUrl,
            'loaders'      => $this->loaders,
            'game_versions' => $this->gameVersions,
            'dependencies' => $this->dependencies,
            'versions'     => array_map(fn ($v) => $v->toArray(), $this->versions),
            'body'         => $this->body,
            'categories'   => $this->categories,
            'follows'      => $this->follows,
            'updated_at'   => $this->updatedAt,
            'published_at' => $this->publishedAt,
        ];
    }
}
