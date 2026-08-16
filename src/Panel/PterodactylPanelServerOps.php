<?php

namespace ServerSuite\Panel;

use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Exceptions\BackupException;
use ServerSuite\Support\Data\BackupRef;

/**
 * Pterodactyl implementation of the panel server-operations seam (backups).
 *
 * Wraps three verified Pterodactyl entry points (signatures confirmed live —
 * see HANDOFF-backup-build.md "Pinned service calls"):
 *
 *   createBackup → Pterodactyl\Services\Backups\InitiateBackupService
 *                    ->setIsLocked(false)->handle(Server $server, ?string $name): Backup
 *   deleteBackup → Pterodactyl\Services\Backups\DeleteBackupService
 *                    ->handle(Backup $backup): void
 *                    (throws BackupLockedException on a locked row)
 *   listBackups  → Pterodactyl\Models\Backup::where('server_id', $id)...
 *
 * As with PterodactylDaemonFileClient, panel classes are referenced by
 * fully-qualified string and resolved through the container so the package
 * loads (and tests) on a machine without Pterodactyl installed. A missing
 * class yields a clear BackupException, never a fatal error.
 */
class PterodactylPanelServerOps implements PanelServerOps
{
    private const SERVER_MODEL  = \Pterodactyl\Models\Server::class;
    private const BACKUP_MODEL  = \Pterodactyl\Models\Backup::class;
    private const INITIATE_SVC  = \Pterodactyl\Services\Backups\InitiateBackupService::class;
    private const DELETE_SVC    = \Pterodactyl\Services\Backups\DeleteBackupService::class;
    private const DOWNLOAD_SVC  = \Pterodactyl\Services\Backups\DownloadLinkService::class;
    private const DAEMON_BACKUP_REPO = \Pterodactyl\Repositories\Wings\DaemonBackupRepository::class;
    private const REINSTALL_SVC      = \Pterodactyl\Services\Servers\ReinstallServerService::class;
    private const POWER_REPO         = \Pterodactyl\Repositories\Wings\DaemonPowerRepository::class;
    private const SERVER_REPO        = \Pterodactyl\Repositories\Wings\DaemonServerRepository::class;
    private const EGG_MODEL          = \Pterodactyl\Models\Egg::class;

    public function listBackups(string $serverUuid): array
    {
        $server = $this->serverFor($serverUuid);

        if (! class_exists(self::BACKUP_MODEL)) {
            throw BackupException::unavailable('Pterodactyl Backup model missing.');
        }

        try {
            $backupModel = self::BACKUP_MODEL;

            $rows = $backupModel::query()
                ->where('server_id', $server->id)
                ->orderBy('created_at', 'desc')
                ->get();
        } catch (\Throwable $e) {
            throw BackupException::fromPanel($e);
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->mapBackup($row);
        }

        return $out;
    }

    public function createBackup(
        string $serverUuid,
        ?string $name = null,
        ?array $ignoredFiles = null,
    ): BackupRef {
        $server = $this->serverFor($serverUuid);

        if (! class_exists(self::INITIATE_SVC)) {
            throw BackupException::unavailable('InitiateBackupService missing.');
        }

        try {
            /** @var object $service */
            $service = app(self::INITIATE_SVC);

            // Always create unlocked so rotation can evict it later.
            $service = $service->setIsLocked(false);

            // Optional ignore globs — only when the panel build supports it.
            if ($ignoredFiles !== null && method_exists($service, 'setIgnoredFiles')) {
                $service = $service->setIgnoredFiles($ignoredFiles);
            }

            // handle(Server $server, ?string $name = null): Backup
            $backup = $service->handle($server, $name);
        } catch (\Throwable $e) {
            throw BackupException::fromPanel($e);
        }

        return $this->mapBackup($backup);
    }

    public function deleteBackup(string $serverUuid, string $backupUuid): void
    {
        $server = $this->serverFor($serverUuid);

        if (! class_exists(self::BACKUP_MODEL) || ! class_exists(self::DELETE_SVC)) {
            throw BackupException::unavailable('Pterodactyl backup services missing.');
        }

        $backupModel = self::BACKUP_MODEL;

        // Scope the lookup to the server — never let a foreign uuid resolve.
        $backup = $backupModel::query()
            ->where('server_id', $server->id)
            ->where('uuid', $backupUuid)
            ->first();

        if (! $backup) {
            throw BackupException::notFound($serverUuid, $backupUuid);
        }

        try {
            // handle(Backup $backup): void — throws BackupLockedException when locked.
            app(self::DELETE_SVC)->handle($backup);
        } catch (\Throwable $e) {
            throw BackupException::fromPanel($e);
        }
    }

    public function setBackupLocked(string $serverUuid, string $backupUuid, bool $locked): void
    {
        $this->updateBackupColumn($serverUuid, $backupUuid, ['is_locked' => $locked]);
    }

    public function renameBackup(string $serverUuid, string $backupUuid, string $name): void
    {
        $this->updateBackupColumn($serverUuid, $backupUuid, ['name' => $name]);
    }

    public function downloadUrl(string $serverUuid, string $backupUuid, object $user): string
    {
        $server = $this->serverFor($serverUuid);

        if (! class_exists(self::BACKUP_MODEL) || ! class_exists(self::DOWNLOAD_SVC)) {
            throw BackupException::unavailable('Pterodactyl backup download service missing.');
        }

        $backupModel = self::BACKUP_MODEL;

        $backup = $backupModel::query()
            ->where('server_id', $server->id)
            ->where('uuid', $backupUuid)
            ->first();

        if (! $backup) {
            throw BackupException::notFound($serverUuid, $backupUuid);
        }

        try {
            // DownloadLinkService::handle(Backup $backup, User $user): string
            return app(self::DOWNLOAD_SVC)->handle($backup, $user);
        } catch (\Throwable $e) {
            throw BackupException::fromPanel($e);
        }
    }

    public function restoreBackup(string $serverUuid, string $backupUuid, bool $truncate, object $user): void
    {
        $server = $this->serverFor($serverUuid);

        if (! class_exists(self::BACKUP_MODEL) || ! class_exists(self::DAEMON_BACKUP_REPO)) {
            throw BackupException::unavailable('Pterodactyl backup restore service missing.');
        }

        // Server must be in a normal state — a non-null status means it is
        // installing, transferring, suspended, or already restoring.
        if (! is_null($server->status)) {
            throw BackupException::panelError('The server is not in a state that allows a backup restore right now.');
        }

        $backupModel = self::BACKUP_MODEL;
        $serverModel = self::SERVER_MODEL;

        $backup = $backupModel::query()
            ->where('server_id', $server->id)
            ->where('uuid', $backupUuid)
            ->first();

        if (! $backup) {
            throw BackupException::notFound($serverUuid, $backupUuid);
        }

        // Aligns with the panel's restore guard. NOTE: panel 1.14.1 fixed this
        // condition from `&&` to `||` — a backup must be BOTH successful AND
        // completed to be restorable; otherwise restoring a failed/incomplete
        // archive could corrupt the server. We mirror the corrected logic.
        if (! $backup->is_successful || is_null($backup->completed_at)) {
            throw BackupException::panelError('This backup cannot be restored: it has not completed, or it failed.');
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($backup, $backupModel, $serverModel, $server, $truncate, $user) {
                $url = null;

                // S3 backups need a signed URL so Wings can pull the archive.
                if ($backup->disk === $backupModel::ADAPTER_AWS_S3) {
                    $url = app(self::DOWNLOAD_SVC)->handle($backup, $user);
                }

                // Flag the server so the panel blocks conflicting actions mid-restore.
                $server->update(['status' => $serverModel::STATUS_RESTORING_BACKUP]);

                app(self::DAEMON_BACKUP_REPO)
                    ->setServer($server)
                    ->restore($backup, $url, $truncate);
            });
        } catch (BackupException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw BackupException::fromPanel($e);
        }
    }

    /**
     * Shared helper: resolve a backup scoped to the server and apply a column
     * update (lock toggle, rename). Never touches another server's backup.
     */
    private function updateBackupColumn(string $serverUuid, string $backupUuid, array $attributes): void
    {
        $server = $this->serverFor($serverUuid);

        if (! class_exists(self::BACKUP_MODEL)) {
            throw BackupException::unavailable('Pterodactyl Backup model missing.');
        }

        $backupModel = self::BACKUP_MODEL;

        $backup = $backupModel::query()
            ->where('server_id', $server->id)
            ->where('uuid', $backupUuid)
            ->first();

        if (! $backup) {
            throw BackupException::notFound($serverUuid, $backupUuid);
        }

        try {
            $backup->update($attributes);
        } catch (\Throwable $e) {
            throw BackupException::fromPanel($e);
        }
    }

    public function serverBackupLimit(string $serverUuid): ?int
    {
        $server = $this->serverFor($serverUuid);

        // Pterodactyl always has the column; cast defensively.
        $limit = $server->backup_limit ?? null;

        return $limit === null ? null : (int) $limit;
    }

    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Version switch primitives (Phase 2).
    // -------------------------------------------------------------------------

    public function setServerVariables(string $serverUuid, array $env): void
    {
        $server = $this->serverFor($serverUuid);

        // Map env name -> egg variable id, then write values straight to the
        // server_variables pivot. Touches only the given keys; nothing else is
        // reset (unlike StartupModificationService, which can clobber omitted
        // variables). Unknown env names are silently skipped.
        $byEnv = [];
        foreach (($server->egg->variables ?? []) as $v) {
            $byEnv[$v->env_variable] = $v->id;
        }

        foreach ($env as $name => $value) {
            if (! isset($byEnv[$name])) {
                continue;
            }
            \Illuminate\Support\Facades\DB::table('server_variables')->updateOrInsert(
                ['server_id' => $server->id, 'variable_id' => $byEnv[$name]],
                ['variable_value' => (string) $value],
            );
        }
    }

    public function changeEgg(string $serverUuid, int $eggId, array $environment): void
    {
        $server = $this->serverFor($serverUuid);

        if (! class_exists(self::EGG_MODEL)) {
            throw BackupException::unavailable('Egg model unavailable.');
        }
        $eggModel = self::EGG_MODEL;

        try {
            $egg = $eggModel::with('variables')->findOrFail($eggId);
        } catch (\Throwable $e) {
            throw BackupException::fromPanel($e);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($server, $egg, $environment) {
            // Adopt the new egg: id, its nest, and its startup command (the
            // per-server startup is a stored copy, so it must be replaced or the
            // old loader's command would run against the new jar).
            \Illuminate\Support\Facades\DB::table('servers')
                ->where('id', $server->id)
                ->update([
                    'egg_id'  => $egg->id,
                    'nest_id' => $egg->nest_id,
                    'startup' => $egg->startup,
                ]);

            // Re-seed variables for the TARGET egg. Old egg's rows reference
            // variable_ids that no longer apply, so clear them first.
            \Illuminate\Support\Facades\DB::table('server_variables')
                ->where('server_id', $server->id)
                ->delete();

            foreach ($egg->variables as $v) {
                $value = array_key_exists($v->env_variable, $environment)
                    ? $environment[$v->env_variable]
                    : ($v->default_value ?? '');

                \Illuminate\Support\Facades\DB::table('server_variables')->insert([
                    'server_id'      => $server->id,
                    'variable_id'    => $v->id,
                    'variable_value' => (string) $value,
                ]);
            }
        });
    }

    public function setDockerImage(string $serverUuid, string $image): void
    {
        $server = $this->serverFor($serverUuid);
        \Illuminate\Support\Facades\DB::table('servers')
            ->where('id', $server->id)
            ->update(['image' => $image]);
    }

    public function reinstall(string $serverUuid): void
    {
        $server = $this->serverFor($serverUuid);

        if (! class_exists(self::REINSTALL_SVC)) {
            throw BackupException::unavailable('Reinstall service unavailable.');
        }

        try {
            app(self::REINSTALL_SVC)->handle($server);
        } catch (\Throwable $e) {
            throw BackupException::fromPanel($e);
        }
    }

    public function power(string $serverUuid, string $signal): void
    {
        $server = $this->serverFor($serverUuid);

        if (! class_exists(self::POWER_REPO)) {
            throw BackupException::unavailable('Power repository unavailable.');
        }

        try {
            app(self::POWER_REPO)->setServer($server)->send($signal);
        } catch (\Throwable $e) {
            throw BackupException::fromPanel($e);
        }
    }

    public function runtimeState(string $serverUuid): array
    {
        $server = $this->serverFor($serverUuid);

        // Install status comes from the panel record; null means installed/idle.
        $install = $server->status ?? null;

        // Live power state comes from Wings and may be unreachable mid-reinstall.
        $power = null;
        try {
            if (class_exists(self::SERVER_REPO)) {
                $details = app(self::SERVER_REPO)->setServer($server)->getDetails();
                $power = $details['state'] ?? ($details['attributes']['state'] ?? null);
            }
        } catch (\Throwable $e) {
            $power = null;
        }

        return ['power' => $power, 'install' => $install];
    }

    // -------------------------------------------------------------------------

    /**
     * Best-effort loader + Minecraft version, via the same detection chain the
     * Mods tab uses. Never throws — detection problems must not block a backup.
     */
    public function detectVersion(string $serverUuid): array
    {
        try {
            $server = $this->serverFor($serverUuid);
            $result = app(\ServerSuite\Services\LoaderDetectionService::class)->detectDetailed($server);

            return [
                'loader'  => $result->loader,
                'version' => $result->gameVersion,
            ];
        } catch (\Throwable $e) {
            return ['loader' => null, 'version' => null];
        }
    }

    /**
     * Resolve the Pterodactyl Server model for a full UUID, or fail clearly.
     */
    protected function serverFor(string $serverUuid): object
    {
        if (! class_exists(self::SERVER_MODEL)) {
            throw BackupException::unavailable('Pterodactyl Server model missing.');
        }

        $serverModel = self::SERVER_MODEL;
        $server = $serverModel::query()->where('uuid', $serverUuid)->first();

        if (! $server) {
            throw BackupException::panelError("Server {$serverUuid} not found.");
        }

        return $server;
    }

    /**
     * Map a Pterodactyl Backup model to the panel-agnostic BackupRef DTO.
     *
     * completed_at / created_at are Carbon instances on the model but are
     * normalised through a string guard in case a future cast changes shape.
     */
    protected function mapBackup(object $backup): BackupRef
    {
        return new BackupRef(
            uuid:         (string) ($backup->uuid ?? ''),
            name:         (string) ($backup->name ?? ''),
            bytes:        (int) ($backup->bytes ?? 0),
            isLocked:     (bool) ($backup->is_locked ?? false),
            isSuccessful: (bool) ($backup->is_successful ?? false),
            createdAt:    $this->toIso($backup->created_at ?? null),
            completedAt:  $this->toIso($backup->completed_at ?? null),
            id:           (int) ($backup->id ?? 0),
        );
    }

    /**
     * Normalise a Carbon|string|null timestamp to an ISO-8601 string or null.
     */
    protected function toIso($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }
}
