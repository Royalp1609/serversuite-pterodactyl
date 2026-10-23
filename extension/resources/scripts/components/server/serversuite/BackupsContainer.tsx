import React, { useEffect, useState, useCallback } from 'react';
import Can from '@/components/elements/Can';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faLock, faLockOpen, faTrash, faSync, faSpinner, faCheckCircle,
    faClock, faSave, faShieldAlt, faExclamationTriangle, faHdd, faPen, faDownload, faUndo,
} from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — Backups tab.
 * Thin UI over the installed backend: PanelServerOps (list/lock/delete) and
 * BackupRotationService (rotate). Endpoints under
 * /extensions/serversuite/<uuid>/backups. No business logic lives here.
 * Styled-components only (no css prop), matching ModManagerContainer.
 */

const Wrapper = styled.div`${tw`p-6 max-w-7xl mx-auto`};`;  // same page margins as the Mods tab
const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Header = styled.div`${tw`flex items-center justify-between mb-4`};`;
const Title = styled.h2`${tw`text-lg font-semibold text-neutral-100 flex items-center gap-2`};`;
const Subtitle = styled.p`${tw`text-sm text-neutral-400 mt-1`};`;
const Grid = styled.div`${tw`grid grid-cols-1 md:grid-cols-3 gap-4 items-end`};`;
const FieldLabel = styled.label`${tw`block text-sm text-neutral-300 mb-1`};`;
const LimitNote = styled.span`${tw`text-xs text-neutral-500`};`;
const Synopsis = styled.p`${tw`text-xs text-neutral-400 mt-2 leading-relaxed`};`;
const Retention = styled.div`${tw`flex flex-col gap-1`};`;
const RetentionValue = styled.div`
    ${tw`text-sm font-medium text-neutral-100 rounded-lg px-3 py-2 inline-block`};
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Muted = styled.div`${tw`text-center text-neutral-500 py-8`};`;
const Loading = styled.div`${tw`text-center text-neutral-400 py-8`};`;
const Row = styled.div`
    ${tw`flex items-center justify-between rounded-xl p-4 mb-2`};
    background: rgba(30, 41, 59, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.06);
`;
const BackupName = styled.span`${tw`text-neutral-100 text-sm font-medium flex items-center gap-2`};`;
const MetaLine = styled.span`${tw`text-xs text-neutral-500 flex items-center gap-3`};`;
const Actions = styled.div`${tw`flex items-center gap-2`};`;
const Col = styled.div`${tw`flex flex-col gap-1`};`;
const PrimaryButton = styled.button`
    ${tw`px-4 py-2 rounded-lg text-sm font-medium text-white flex items-center gap-2 transition-all`};
    background: rgb(99, 102, 241);
    &:hover { background: rgb(79, 82, 221); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;
const GhostButton = styled.button`
    ${tw`px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2 transition-all`};
    background: rgba(30, 41, 59, 0.6);
    color: rgb(203, 213, 225);
    border: 1px solid rgba(255, 255, 255, 0.06);
    &:hover { background: rgba(51, 65, 85, 0.8); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;
const Toggle = styled.label`${tw`flex items-center gap-2 cursor-pointer text-sm text-neutral-200`};`;
const ScheduleRow = styled.div`${tw`mt-3 flex flex-col gap-1`};`;
const Select = styled.select`
    ${tw`bg-neutral-900 border border-neutral-700 text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none`};
    background-color: rgba(15, 23, 42, 0.6);
    max-width: 220px;
    &:focus { border-color: rgb(99, 102, 241); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;
const Counter = styled.span`${tw`text-neutral-300 font-medium`};`;
const BackupControls = styled.div`${tw`flex items-center gap-2`};`;
const Note = styled.div`
    ${tw`text-sm rounded-lg p-3 mb-4`};
    background: rgba(99, 102, 241, 0.12);
    color: rgb(165, 180, 252);
`;
const Overlay = styled.div`
    ${tw`fixed inset-0 z-50 flex items-center justify-center p-4`};
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(2px);
`;
const Modal = styled.div`
    ${tw`rounded-2xl p-6 w-full`};
    max-width: 32rem;
    background: rgb(23, 31, 46);
    border: 1px solid rgba(255, 255, 255, 0.1);
`;
const ModalTitle = styled.h3`${tw`text-lg font-semibold text-neutral-100 mb-1 flex items-center gap-2`};`;
const ModalText = styled.p`${tw`text-sm text-neutral-400 mb-4 leading-relaxed`};`;
const ModalInput = styled.input`
    ${tw`w-full text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none mb-4`};
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
    &:focus { border-color: rgb(99, 102, 241); }
`;
const CheckRow = styled.label`${tw`flex items-start gap-3 mb-4 cursor-pointer select-none text-sm text-neutral-200`};`;
const ModalActions = styled.div`${tw`flex items-center justify-end gap-3`};`;
const ErrorBox = styled.div`
    ${tw`text-sm rounded-lg p-3 mb-4`};
    background: rgba(239, 68, 68, 0.12);
    color: rgb(248, 113, 113);
`;
const WarnBox = styled.div`
    ${tw`text-sm rounded-lg p-3 mb-4`};
    background: rgba(245, 158, 11, 0.12);
    color: rgb(251, 191, 36);
`;
const Badge = styled.span<{ tone?: string }>`
    ${tw`text-xs px-2 py-0.5 rounded-full font-medium inline-flex items-center gap-1`};
    background: ${(p) => p.tone === 'green' ? 'rgba(34,197,94,0.15)'
        : p.tone === 'amber' ? 'rgba(245,158,11,0.15)'
        : p.tone === 'red' ? 'rgba(239,68,68,0.15)'
        : 'rgba(148,163,184,0.15)'};
    color: ${(p) => p.tone === 'green' ? 'rgb(74,222,128)'
        : p.tone === 'amber' ? 'rgb(251,191,36)'
        : p.tone === 'red' ? 'rgb(248,113,113)'
        : 'rgb(148,163,184)'};
`;

interface Backup {
    uuid: string;
    name: string;
    bytes: number;
    is_locked: boolean;
    is_successful: boolean;
    completed_at: string | null;
    created_at: string | null;
}

function humanBytes(bytes: number): string {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}

/** Local date/time for an ISO timestamp, e.g. "Mon 20 Oct, 04:00". */
function formatWhen(iso: string): string {
    const d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function relativeAge(iso: string | null): string {
    if (!iso) return '—';
    const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.round(hrs / 24)}d ago`;
}

function intervalLabel(h: number): string {
    const map: Record<number, string> = {
        6: 'Every 6 hours', 12: 'Every 12 hours', 24: 'Daily', 48: 'Every 2 days', 168: 'Weekly',
    };
    return map[h] || `Every ${h}h`;
}

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const base = `/extensions/serversuite/${uuid}`;

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [backups, setBackups] = useState<Backup[]>([]);
    const [limit, setLimit] = useState<number | null>(null);
    const [effectiveKeep, setEffectiveKeep] = useState<number>(0);
    const [enabled, setEnabled] = useState(false);
    const [intervalHours, setIntervalHours] = useState('');
    const [defaultInterval, setDefaultInterval] = useState(12);
    const [presets, setPresets] = useState<number[]>([]);
    const [lastRunAt, setLastRunAt] = useState<string | null>(null);
    const [scheduleHour, setScheduleHour] = useState('');
    const [timezone, setTimezone] = useState('UTC');
    const [nextRunAt, setNextRunAt] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [backupName, setBackupName] = useState('');
    const [modal, setModal] = useState<null | 'backup' | 'restore'>(null);
    const [restoreTarget, setRestoreTarget] = useState<Backup | null>(null);
    const [restoreTruncate, setRestoreTruncate] = useState(false);

    const load = useCallback(() => {
        if (!uuid) return;
        setLoading(true);
        http.get(`${base}/backups`)
            .then((res) => {
                const d = res.data.data;
                setBackups(d.backups || []);
                setLimit(d.limit);
                setEffectiveKeep(d.effective_keep);
                setEnabled(!!d.settings.enabled);
                setIntervalHours(d.schedule?.interval_hours == null ? '' : String(d.schedule.interval_hours));
                setDefaultInterval(d.schedule?.default_interval_hours ?? 12);
                setPresets(d.schedule?.presets ?? []);
                setLastRunAt(d.schedule?.last_run_at ?? null);
                setScheduleHour(d.schedule?.hour == null ? '' : String(d.schedule.hour));
                setTimezone(d.schedule?.timezone ?? 'UTC');
                setNextRunAt(d.schedule?.next_run_at ?? null);
                setError(null);
            })
            .catch((e) => setError(e?.response?.data?.error || 'Failed to load backups.'))
            .finally(() => setLoading(false));
    }, [uuid, base]);

    useEffect(() => { load(); }, [load]);

    const saveSettings = () => {
        setBusy('settings');
        http.post(`${base}/backups/settings`, {
            enabled,
            interval_hours: intervalHours === '' ? null : Number(intervalHours),
            hour: scheduleHour === '' ? null : Number(scheduleHour),
        })
            .then(() => { setNotice('Settings saved.'); load(); })
            .catch((e) => setError(e?.response?.data?.error || 'Failed to save settings.'))
            .finally(() => setBusy(null));
    };

    const backupNow = () => {
        setBusy('rotate');
        setNotice(null);
        http.post(`${base}/backups/create`, { name: backupName.trim() || undefined })
            .then((res) => {
                const r = res.data.data;
                if (r.created) {
                    setNotice(
                        `Backup created: "${r.created.name}"` +
                        (r.evicted?.length ? ` (replaced ${r.evicted.length} oldest).` : '.'),
                    );
                    setBackupName('');
                } else {
                    setNotice(`${r.status}: ${r.message || r.reason}`);
                }
                load();
            })
            .catch((e) => setError(e?.response?.data?.error || 'Backup failed.'))
            .finally(() => setBusy(null));
    };

    const renameBackup = (b: Backup) => {
        const next = window.prompt('Rename backup', b.name);
        if (next === null) return;
        const trimmed = next.trim();
        if (trimmed === '' || trimmed === b.name) return;
        setBusy(b.uuid);
        http.post(`${base}/backups/${b.uuid}/rename`, { name: trimmed })
            .then(() => load())
            .catch((e) => setError(e?.response?.data?.error || 'Failed to rename backup.'))
            .finally(() => setBusy(null));
    };

    const restoreBackup = (b: Backup) => {
        setRestoreTarget(b);
        setRestoreTruncate(false);
        setNotice(null);
        setModal('restore');
    };

    const doRestore = () => {
        const b = restoreTarget;
        if (!b) return;
        setModal(null);
        setBusy(b.uuid);
        setNotice(null);
        http.post(`${base}/backups/${b.uuid}/restore`, { truncate: restoreTruncate })
            .then(() => { setNotice(`Restore started from "${b.name}". The server will come back once Wings finishes.`); load(); })
            .catch((e) => setError(e?.response?.data?.error || 'Failed to start restore.'))
            .finally(() => setBusy(null));
    };

    const downloadBackup = (b: Backup) => {
        setBusy(b.uuid);
        http.get(`${base}/backups/${b.uuid}/download`)
            .then((res) => { window.open(res.data.data.url, '_blank', 'noopener'); })
            .catch((e) => setError(e?.response?.data?.error || 'Failed to get download link.'))
            .finally(() => setBusy(null));
    };

    const toggleLock = (b: Backup) => {
        setBusy(b.uuid);
        http.post(`${base}/backups/${b.uuid}/lock`, { locked: !b.is_locked })
            .then(() => load())
            .catch((e) => setError(e?.response?.data?.error || 'Failed to change lock.'))
            .finally(() => setBusy(null));
    };

    const removeBackup = (b: Backup) => {
        if (!window.confirm(`Delete backup "${b.name}"? This cannot be undone.`)) return;
        setBusy(b.uuid);
        http.delete(`${base}/backups/${b.uuid}`)
            .then(() => load())
            .catch((e) => setError(e?.response?.data?.error || 'Failed to delete backup.'))
            .finally(() => setBusy(null));
    };

    const backupsDisabled = limit === 0;

    return (
        <Wrapper>
            <Panel>
                <Header>
                    <div>
                        <Title><FontAwesomeIcon icon={faShieldAlt} /> Backups</Title>
                        <Subtitle>
                            Rolling backups with retention. Locked backups are never evicted.
                            {limit != null && limit > 0 && (
                                <Counter> · {backups.length} of {limit} used</Counter>
                            )}
                        </Subtitle>
                    </div>
                    <BackupControls>
                        <Can action={'backup.create'}><PrimaryButton onClick={() => { setNotice(null); setBackupName(''); setModal('backup'); }} disabled={busy !== null || backupsDisabled}>
                            <FontAwesomeIcon icon={busy === 'rotate' ? faSpinner : faSync} spin={busy === 'rotate'} />
                            Back Up
                        </PrimaryButton></Can>
                    </BackupControls>
                </Header>

                {backupsDisabled && (
                    <WarnBox>
                        <FontAwesomeIcon icon={faExclamationTriangle} /> This server&apos;s backup limit is 0, so
                        backups are disabled. Raise the Backup Limit in the panel to enable rotation.
                    </WarnBox>
                )}

                <Grid>
                    <div>
                        <Toggle>
                            <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
                            Turn on scheduled backups
                        </Toggle>
                        <Synopsis>
                            When on, this server is backed up automatically on the cadence you choose
                            below. Each run keeps your most recent backups up to this server&apos;s backup
                            limit and removes the oldest <em>unlocked</em> one once the limit is reached.
                            Locked backups are always kept and never count toward removal.
                        </Synopsis>
                        {enabled && (
                            <ScheduleRow>
                                <FieldLabel>How often</FieldLabel>
                                <Select
                                    value={intervalHours}
                                    onChange={(e) => setIntervalHours(e.target.value)}
                                    disabled={busy !== null}
                                >
                                    <option value="">Default ({intervalLabel(defaultInterval)})</option>
                                    {presets.map((p) => (
                                        <option key={p} value={String(p)}>{intervalLabel(p)}</option>
                                    ))}
                                </Select>
                                <FieldLabel>Run at</FieldLabel>
                                <Select
                                    value={scheduleHour}
                                    onChange={(e) => setScheduleHour(e.target.value)}
                                    disabled={busy !== null}
                                >
                                    <option value="">No fixed time</option>
                                    {Array.from({ length: 24 }, (_, h) => (
                                        <option key={h} value={String(h)}>
                                            {String(h).padStart(2, '0')}:00 ({timezone})
                                        </option>
                                    ))}
                                </Select>
                                <LimitNote>
                                    {scheduleHour === ''
                                        ? 'Without a fixed time, the next backup runs once the interval has passed since the last one.'
                                        : 'Daily and longer schedules run in this hour; shorter ones run on hours in step with it (e.g. every 6 hours from 02:00 = 02, 08, 14, 20).'}
                                </LimitNote>
                                <LimitNote>
                                    Last scheduled backup: {lastRunAt ? relativeAge(lastRunAt) : 'never'}
                                    {nextRunAt && <>{' · '}Next: {formatWhen(nextRunAt)} (your local time)</>}
                                </LimitNote>
                            </ScheduleRow>
                        )}
                    </div>
                    <Retention>
                        <FieldLabel>Backups kept</FieldLabel>
                        <RetentionValue>
                            {limit == null ? `${effectiveKeep} (default)` : `up to ${limit}`}
                        </RetentionValue>
                        <LimitNote>Set by this server&apos;s backup limit — a panel admin changes it.</LimitNote>
                    </Retention>
                    <Can action={['backup.create', 'backup.delete']}><GhostButton onClick={saveSettings} disabled={busy !== null}>
                        <FontAwesomeIcon icon={busy === 'settings' ? faSpinner : faSave} spin={busy === 'settings'} />
                        Save settings
                    </GhostButton></Can>
                </Grid>
            </Panel>

            {notice && <Note>{notice}</Note>}
            {error && <ErrorBox>{error}</ErrorBox>}

            <Panel>
                {loading ? (
                    <Loading><FontAwesomeIcon icon={faSpinner} spin /> Loading backups…</Loading>
                ) : backups.length === 0 ? (
                    <Muted>No backups yet.</Muted>
                ) : (
                    backups.map((b) => {
                        const inProgress = b.completed_at === null;
                        const tone = inProgress ? 'amber' : b.is_successful ? 'green' : 'red';
                        const label = inProgress ? 'In progress' : b.is_successful ? 'Complete' : 'Failed';
                        return (
                            <Row key={b.uuid}>
                                <Col>
                                    <BackupName>
                                        {b.name}
                                        {b.is_locked && <Badge tone="amber"><FontAwesomeIcon icon={faLock} /> Locked</Badge>}
                                    </BackupName>
                                    <MetaLine>
                                        <Badge tone={tone}>
                                            <FontAwesomeIcon icon={inProgress ? faClock : faCheckCircle} /> {label}
                                        </Badge>
                                        <span><FontAwesomeIcon icon={faHdd} /> {humanBytes(b.bytes)}</span>
                                        <span>{relativeAge(b.completed_at || b.created_at)}</span>
                                    </MetaLine>
                                </Col>
                                <Actions>
                                    <Can action={'backup.restore'}><GhostButton
                                        onClick={() => restoreBackup(b)}
                                        disabled={busy !== null || inProgress || !b.is_successful}
                                        title={inProgress ? 'Backup still in progress' : !b.is_successful ? 'Backup did not complete' : 'Restore this backup'}
                                    >
                                        <FontAwesomeIcon icon={faUndo} /> Restore
                                    </GhostButton></Can>
                                    <Can action={'backup.download'}><GhostButton
                                        onClick={() => downloadBackup(b)}
                                        disabled={busy !== null || inProgress || !b.is_successful}
                                        title={inProgress ? 'Backup still in progress' : !b.is_successful ? 'Backup did not complete' : 'Download backup'}
                                    >
                                        <FontAwesomeIcon icon={faDownload} />
                                    </GhostButton></Can>
                                    <Can action={'backup.create'}><GhostButton onClick={() => renameBackup(b)} disabled={busy !== null} title="Rename backup">
                                        <FontAwesomeIcon icon={faPen} />
                                    </GhostButton></Can>
                                    <Can action={'backup.delete'}><GhostButton onClick={() => toggleLock(b)} disabled={busy !== null}>
                                        <FontAwesomeIcon icon={busy === b.uuid ? faSpinner : (b.is_locked ? faLockOpen : faLock)} spin={busy === b.uuid} />
                                        {b.is_locked ? 'Unlock' : 'Lock'}
                                    </GhostButton></Can>
                                    <Can action={'backup.delete'}><GhostButton
                                        onClick={() => removeBackup(b)}
                                        disabled={busy !== null || b.is_locked}
                                        title={b.is_locked ? 'Unlock before deleting' : 'Delete backup'}
                                    >
                                        <FontAwesomeIcon icon={faTrash} />
                                    </GhostButton></Can>
                                </Actions>
                            </Row>
                        );
                    })
                )}
            </Panel>

            {modal === 'backup' && (
                <Overlay onClick={(e) => { if (e.target === e.currentTarget) setModal(null); }}>
                    <Modal>
                        <ModalTitle><FontAwesomeIcon icon={faShieldAlt} /> Create a backup</ModalTitle>
                        <ModalText>Give it a name, or leave blank for an automatic one. The current version and loader are stamped onto the name automatically.</ModalText>
                        <ModalInput
                            type="text"
                            autoFocus
                            value={backupName}
                            placeholder="Optional name…"
                            maxLength={191}
                            onChange={(e) => setBackupName(e.target.value)}
                        />
                        <ModalActions>
                            <GhostButton onClick={() => setModal(null)}>Cancel</GhostButton>
                            <PrimaryButton onClick={() => { setModal(null); backupNow(); }}>
                                <FontAwesomeIcon icon={faSync} /> Back Up
                            </PrimaryButton>
                        </ModalActions>
                    </Modal>
                </Overlay>
            )}

            {modal === 'restore' && restoreTarget && (
                <Overlay onClick={(e) => { if (e.target === e.currentTarget) setModal(null); }}>
                    <Modal>
                        <ModalTitle><FontAwesomeIcon icon={faUndo} /> Restore backup</ModalTitle>
                        <ModalText>
                            Restore <strong tw="text-neutral-100">“{restoreTarget.name}”</strong>? The server will be stopped
                            and put into a restoring state until Wings finishes. If this backup is a different loader/version,
                            the server is realigned to match it.
                        </ModalText>
                        <CheckRow>
                            <input type="checkbox" checked={restoreTruncate} onChange={(e) => setRestoreTruncate(e.target.checked)} style={{ marginTop: 3 }} />
                            <span>
                                <strong tw="text-neutral-100">Delete all current files first (clean restore)</strong>
                                <div tw="text-xs text-neutral-400" style={{ marginTop: 2 }}>Leave off to restore on top of the existing files.</div>
                            </span>
                        </CheckRow>
                        <ModalActions>
                            <GhostButton onClick={() => setModal(null)}>Cancel</GhostButton>
                            <PrimaryButton onClick={doRestore}>
                                <FontAwesomeIcon icon={faUndo} /> Restore
                            </PrimaryButton>
                        </ModalActions>
                    </Modal>
                </Overlay>
            )}
        </Wrapper>
    );
};
