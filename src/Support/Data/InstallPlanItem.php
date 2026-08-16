<?php

namespace ServerSuite\Support\Data;

/**
 * A single node in an install plan: one mod at one version, plus the planner's
 * verdict on what should happen to it.
 *
 * status:
 *   install    — not present; will be downloaded and installed
 *   satisfied  — already installed at this exact version; nothing to do
 *   conflict   — installed at a DIFFERENT version; needs update/skip decision
 *   manual     — cannot be auto-installed (no public download URL)
 */
readonly class InstallPlanItem
{
    public function __construct(
        public string $source,
        public string $modId,
        public string $modName,
        public string $versionId,
        public string $versionNumber,
        public string $status,
        public string $kind,                 // primary | dependency
        public ?string $installedVersion = null,  // set when status = conflict/satisfied
        public ?string $reason = null,            // human-readable note (e.g. why manual)
    ) {}

    public function toArray(): array
    {
        return [
            'source'            => $this->source,
            'mod_id'            => $this->modId,
            'mod_name'          => $this->modName,
            'version_id'        => $this->versionId,
            'version_number'    => $this->versionNumber,
            'status'            => $this->status,
            'kind'              => $this->kind,
            'installed_version' => $this->installedVersion,
            'reason'            => $this->reason,
        ];
    }
}
