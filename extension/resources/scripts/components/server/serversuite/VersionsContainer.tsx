import React, { useEffect, useState, useCallback } from 'react';
import Can from '@/components/elements/Can';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faSpinner, faCheckCircle, faExclamationTriangle, faLayerGroup, faCircle,
    faArrowUp, faArrowDown, faArchive, faTimes, faSyncAlt, faRadiation,
} from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — Versions tab.
 * Shows the running loader/version + the versions its loader publishes, and
 * drives a backup-gated version switch: pick a target -> preflight preview ->
 * pre-switch backup -> apply -> reinstall. Every switch takes a real backup
 * first (server-side gated) and clears plugin jars by default (they routinely
 * halt boot across versions). Endpoints under /extensions/serversuite/<uuid>.
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
const CurrentRow = styled.div`${tw`flex flex-wrap items-center gap-3`};`;
const Pill = styled.span`
    ${tw`text-sm font-medium text-neutral-100 rounded-lg px-3 py-2 inline-flex items-center gap-2`};
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Muted = styled.div`${tw`text-center text-neutral-500 py-8`};`;
const Note = styled.p`${tw`text-xs text-neutral-400 mt-3 leading-relaxed`};`;
const VersionGrid = styled.div`${tw`grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2 mt-2`};`;
const VersionChip = styled.button<{ current?: boolean; selected?: boolean }>`
    ${tw`text-sm rounded-lg px-3 py-2 flex items-center justify-between w-full transition`};
    background: ${(p) => (p.current ? 'rgba(34,197,94,0.12)' : p.selected ? 'rgba(59,130,246,0.15)' : 'rgba(15,23,42,0.5)')};
    border: 1px solid ${(p) => (p.current ? 'rgba(34,197,94,0.4)' : p.selected ? 'rgba(59,130,246,0.5)' : 'rgba(255,255,255,0.08)')};
    color: ${(p) => (p.current ? 'rgb(134,239,172)' : 'rgb(212,212,212)')};
    cursor: ${(p) => (p.current ? 'default' : 'pointer')};
    &:hover { border-color: ${(p) => (p.current ? 'rgba(34,197,94,0.4)' : 'rgba(59,130,246,0.5)')}; }
`;
const Banner = styled.div<{ tone?: 'info' | 'warn' | 'error' }>`
    ${tw`rounded-xl px-4 py-3 text-sm flex items-start gap-2 mt-3`};
    background: ${(p) => (p.tone === 'warn' ? 'rgba(245,158,11,0.10)' : p.tone === 'error' ? 'rgba(239,68,68,0.10)' : 'rgba(59,130,246,0.08)')};
    border: 1px solid ${(p) => (p.tone === 'warn' ? 'rgba(245,158,11,0.35)' : p.tone === 'error' ? 'rgba(239,68,68,0.35)' : 'rgba(59,130,246,0.25)')};
    color: ${(p) => (p.tone === 'warn' ? 'rgb(252,211,77)' : p.tone === 'error' ? 'rgb(252,165,165)' : 'rgb(212,212,212)')};
`;
const StatRow = styled.div`${tw`flex flex-wrap gap-2 mb-2`};`;
const Stat = styled.span<{ tone?: 'up' | 'down' | 'neutral' }>`
    ${tw`text-sm rounded-lg px-3 py-2 inline-flex items-center gap-2`};
    background: rgba(15,23,42,0.6);
    border: 1px solid ${(p) => (p.tone === 'up' ? 'rgba(34,197,94,0.4)' : p.tone === 'down' ? 'rgba(245,158,11,0.4)' : 'rgba(255,255,255,0.08)')};
    color: ${(p) => (p.tone === 'up' ? 'rgb(134,239,172)' : p.tone === 'down' ? 'rgb(252,211,77)' : 'rgb(212,212,212)')};
`;
const ToggleRow = styled.label`${tw`flex items-start gap-3 mt-3 cursor-pointer select-none`};`;
const Actions = styled.div`${tw`flex items-center gap-3 mt-4`};`;
const Btn = styled.button<{ variant?: 'primary' | 'ghost' }>`
    ${tw`text-sm font-medium rounded-lg px-4 py-2 inline-flex items-center gap-2 transition`};
    background: ${(p) => (p.variant === 'ghost' ? 'transparent' : 'rgba(59,130,246,0.9)')};
    border: 1px solid ${(p) => (p.variant === 'ghost' ? 'rgba(255,255,255,0.15)' : 'rgba(59,130,246,0.9)')};
    color: ${(p) => (p.variant === 'ghost' ? 'rgb(212,212,212)' : '#fff')};
    &:disabled { opacity: 0.45; cursor: not-allowed; }
`;
const DangerPanel = styled(Panel)`
    border-color: rgba(239, 68, 68, 0.35);
    background: rgba(239, 68, 68, 0.05);
`;
const Overlay = styled.div`
    ${tw`fixed inset-0 z-50 flex items-center justify-center p-4`};
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(2px);
`;
const Modal = styled.div`
    ${tw`rounded-2xl p-6 w-full`};
    max-width: 34rem;
    max-height: 85vh;
    overflow-y: auto;
    background: rgb(23, 31, 46);
    border: 1px solid rgba(255, 255, 255, 0.1);
`;
const RadioRow = styled.label`${tw`flex items-start gap-3 mt-2 cursor-pointer select-none`};`;
const NameInput = styled.input`
    ${tw`w-full rounded-lg px-3 py-2 text-sm mt-2`};
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: rgb(229, 229, 229);
`;
const Select = styled.select`
    ${tw`w-full rounded-lg px-3 py-2 text-sm`};
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: rgb(229, 229, 229);
`;
const DangerBtn = styled.button`
    ${tw`text-sm font-medium rounded-lg px-4 py-2 inline-flex items-center gap-2 transition mt-3`};
    background: rgba(239, 68, 68, 0.9);
    border: 1px solid rgba(239, 68, 68, 0.9);
    color: #fff;
    &:disabled { opacity: 0.4; cursor: not-allowed; }
`;

interface Current { loader: string | null; game_version: string | null; confidence: number | null; }
interface Available { supported: boolean; loader: string | null; versions: string[]; }
interface Preflight {
    can_proceed: boolean;
    direction: string;
    target: { version: string; build: number | null };
    image: { change: boolean; current?: string | null; resolved?: string | null };
    warnings?: string[];
    blockers?: string[];
}
type Phase = 'idle' | 'loading_preflight' | 'preview' | 'backing_up' | 'applying' | 'installing' | 'done' | 'failed';

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));
const cap = (s: string | null) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : 'Unknown');

export default () => {
    const uuid = ServerContext.useStoreState((s) => s.server.data!.uuid);
    const serverName = ServerContext.useStoreState((s) => s.server.data!.name);
    const base = `/extensions/serversuite/${uuid}`;

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [current, setCurrent] = useState<Current | null>(null);
    const [available, setAvailable] = useState<Available | null>(null);

    const [selected, setSelected] = useState<string | null>(null);
    const [preflight, setPreflight] = useState<Preflight | null>(null);
    const [deletePlugins, setDeletePlugins] = useState(true);
    const [wipeMode, setWipeMode] = useState<'none' | 'reset_world' | 'clean_slate'>('none');
    const [phase, setPhase] = useState<Phase>('idle');
    const [phaseMsg, setPhaseMsg] = useState('');

    const [nukeMode, setNukeMode] = useState<'keep_plugins' | 'clean_slate'>('keep_plugins');
    const [nukeBackup, setNukeBackup] = useState(true);
    const [nukeConfirm, setNukeConfirm] = useState('');
    const [nukePhase, setNukePhase] = useState<'idle' | 'working' | 'done' | 'failed'>('idle');
    const [nukeMsg, setNukeMsg] = useState('');

    // Loader switching (Phase 3). A loader change is always a clean-slate reset.
    const [loaderList, setLoaderList] = useState<{ egg_id: number; egg_name: string; loader: string }[]>([]);
    const [loaderCurrent, setLoaderCurrent] = useState<string | null>(null);
    const [loaderTarget, setLoaderTarget] = useState<{ egg_id: number; loader: string } | null>(null);
    const [loaderVersion, setLoaderVersion] = useState('');
    const [loaderConfirm, setLoaderConfirm] = useState('');
    const [loaderPhase, setLoaderPhase] = useState<'idle' | 'working' | 'done' | 'failed'>('idle');
    const [loaderMsg, setLoaderMsg] = useState('');

    const load = useCallback(() => {
        setLoading(true);
        http.get(`${base}/loaders`)
            .then(({ data }) => { setLoaderList(data.data.installable || []); setLoaderCurrent(data.data.current?.loader ?? null); })
            .catch(() => { setLoaderList([]); });
        http.get(`${base}/server-versions`)
            .then(({ data }) => { setCurrent(data.data.current); setAvailable(data.data.available); setError(null); })
            .catch((e) => setError(e?.response?.data?.error || 'Failed to load version information.'))
            .finally(() => setLoading(false));
    }, [base]);

    useEffect(() => { load(); }, [load]);

    const loaderLabel = cap(current?.loader ?? null);
    const currentVer = current?.game_version || null;
    const busy = phase === 'backing_up' || phase === 'applying' || phase === 'installing' || phase === 'loading_preflight';

    const reset = () => { setSelected(null); setPreflight(null); setPhase('idle'); setPhaseMsg(''); setDeletePlugins(true); setWipeMode('none'); };

    const selectTarget = async (v: string) => {
        if (v === currentVer || busy) return;
        setSelected(v); setPreflight(null); setPhase('loading_preflight'); setPhaseMsg('');
        try {
            const { data } = await http.post(`${base}/version-preflight`, { version: v });
            setPreflight(data.data); setPhase('preview');
            // Downgrades keep a newer-format world that the older version may not
            // read — default to a world reset for safety; upgrades keep the world.
            setWipeMode(data.data?.direction === 'downgrade' ? 'reset_world' : 'none');
        } catch (e: any) {
            setPhase('failed'); setPhaseMsg(e?.response?.data?.error || 'Could not preflight that version.');
        }
    };

    const pollBackup = async (backupUuid: string) => {
        for (let i = 0; i < 60; i++) {
            const { data } = await http.get(`${base}/backups`);
            const list = (data?.data?.backups || []) as any[];
            const b = list.find((x: any) => x.uuid === backupUuid);
            if (b && b.is_successful && b.completed_at) return;
            if (b && !b.is_successful && b.completed_at) throw new Error('The pre-switch backup failed — aborting.');
            await sleep(5000);
        }
        throw new Error('The pre-switch backup did not finish in time — aborting.');
    };

    const pollInstalled = async (target: string) => {
        for (let i = 0; i < 60; i++) {
            await sleep(6000);
            try {
                const { data } = await http.get(`${base}/server-versions`);
                if (data.data.current?.game_version === target) return true;
            } catch { /* detection may be briefly unavailable mid-install */ }
        }
        return false;
    };

    const pollOffline = async () => {
        for (let i = 0; i < 60; i++) {
            try {
                const { data } = await http.get(`${base}/server-status`);
                if (data.data?.power === 'offline') return true;
            } catch { /* keep waiting */ }
            await sleep(4000);
        }
        return false;
    };

    const pollInstallDone = async () => {
        await sleep(5000); // let the reinstall enter the "installing" state
        for (let i = 0; i < 80; i++) {
            try {
                const { data } = await http.get(`${base}/server-status`);
                if (!data.data?.install) return true; // null/empty = installed / idle
            } catch { /* keep waiting */ }
            await sleep(5000);
        }
        return false;
    };

    const confirmNuclear = async () => {
        if (nukeConfirm.trim() !== serverName) return;
        const label = nukeMode === 'clean_slate' ? 'clean slate' : 'reset world';
        try {
            setNukePhase('working');
            if (nukeBackup) {
                setNukeMsg('Taking a safety backup…');
                const { data: bud } = await http.post(`${base}/backups/create`, { name: `Pre-wipe (${label})` });
                const backupUuid = bud?.data?.created?.uuid;
                if (!backupUuid) throw new Error(bud?.data?.message || 'Could not create the safety backup — aborting.');
                setNukeMsg('Backing up — waiting for it to complete…');
                await pollBackup(backupUuid);
            }
            setNukeMsg('Stopping the server…');
            let r = await http.post(`${base}/version-nuclear`, { mode: nukeMode, confirm_name: nukeConfirm.trim() });
            if (r.data.data?.status === 'stopping') {
                setNukeMsg('Waiting for the server to stop…');
                await pollOffline();
                setNukeMsg('Wiping files and reinstalling…');
                r = await http.post(`${base}/version-nuclear`, { mode: nukeMode, confirm_name: nukeConfirm.trim() });
            }
            setNukeMsg('Reinstalling — this can take a few minutes. Watch the console.');
            await pollInstallDone();
            setNukePhase('done');
            setNukeMsg('Reset complete.');
            setNukeConfirm('');
            load();
        } catch (e: any) {
            setNukePhase('failed');
            setNukeMsg(e?.response?.data?.error || e?.message || 'The reset failed. Your backup (if taken) is safe.');
        }
    };

    const confirmSwitch = async () => {
        if (!selected || !preflight) return;
        const targetVer = selected;
        const build = preflight.target?.build ?? null;
        const body = { version: targetVer, build, delete_plugins: deletePlugins, wipe_mode: wipeMode };
        try {
            setPhase('backing_up'); setPhaseMsg('Taking a pre-switch backup…');
            const { data: bud } = await http.post(`${base}/backups/create`, { name: `Pre-switch to ${targetVer}` });
            const backupUuid = bud?.data?.created?.uuid;
            if (!backupUuid) throw new Error(bud?.data?.message || 'Could not create the pre-switch backup — aborting.');
            setPhaseMsg('Backing up — waiting for it to complete…');
            await pollBackup(backupUuid);

            setPhase('applying');
            setPhaseMsg(wipeMode === 'none' ? 'Pinning the version and triggering the reinstall…' : 'Stopping the server to wipe files…');
            let r = await http.post(`${base}/version-apply`, body);
            if (r.data.data?.status === 'stopping') {
                setPhaseMsg('Waiting for the server to stop…');
                await pollOffline();
                setPhaseMsg('Wiping files and reinstalling…');
                r = await http.post(`${base}/version-apply`, body);
            }

            setPhase('installing'); setPhaseMsg(`Reinstalling to ${targetVer}. This can take a few minutes — watch the console.`);
            const ok = await pollInstalled(targetVer);
            setPhase('done');
            setPhaseMsg(ok
                ? `Now running ${targetVer}.`
                : `The reinstall is still finishing — check the console; this tab will catch up on refresh.`);
            load();
        } catch (e: any) {
            setPhase('failed');
            setPhaseMsg(e?.response?.data?.error || e?.message || 'The version change failed. Your backup is safe.');
        }
    };

    const selectLoader = (eggIdStr: string) => {
        const eggId = parseInt(eggIdStr, 10);
        const l = loaderList.find(x => x.egg_id === eggId);
        if (!l) { setLoaderTarget(null); setLoaderPhase('idle'); setLoaderMsg(''); return; }
        setLoaderTarget({ egg_id: l.egg_id, loader: l.loader });
        setLoaderVersion(currentVer || '');
        setLoaderConfirm('');
        setLoaderPhase('idle');
        setLoaderMsg('');
    };
    const clearLoaderSelection = () => { setLoaderTarget(null); setLoaderPhase('idle'); setLoaderMsg(''); setLoaderConfirm(''); };

    const confirmLoaderSwitch = async () => {
        if (!loaderTarget || loaderConfirm.trim() !== serverName) return;
        const body = { egg_id: loaderTarget.egg_id, version: loaderVersion.trim() || undefined, confirm_name: loaderConfirm.trim() };
        try {
            setLoaderPhase('working');
            // Always: the panel refuses a loader switch without a completed backup.
            setLoaderMsg('Taking a safety backup…');
            const { data: bud } = await http.post(`${base}/backups/create`, { name: `Pre-loader ${loaderTarget.loader}` });
            const bu = bud?.data?.created?.uuid;
            if (!bu) throw new Error(bud?.data?.message || 'Could not create the safety backup — aborting.');
            setLoaderMsg('Backing up — waiting for it to complete…');
            await pollBackup(bu);
            setLoaderMsg('Stopping the server…');
            let r = await http.post(`${base}/loader-switch`, body);
            if (r.data.data?.status === 'stopping') {
                setLoaderMsg('Waiting for the server to stop…');
                await pollOffline();
                setLoaderMsg('Wiping everything and installing the new loader…');
                r = await http.post(`${base}/loader-switch`, body);
            }
            setLoaderMsg('Reinstalling — this can take a few minutes. Watch the console.');
            await pollInstallDone();
            setLoaderPhase('done');
            setLoaderMsg(`Now running ${loaderTarget.loader}. Re-add mods compatible with it.`);
            load();
        } catch (e: any) {
            setLoaderPhase('failed');
            setLoaderMsg(e?.response?.data?.error || e?.message || 'The loader switch failed. Your backup is safe.');
        }
    };

    const isDown = preflight?.direction === 'downgrade';
    const isUp = preflight?.direction === 'upgrade';
    const blockers = preflight?.blockers ?? [];
    const warnings = preflight?.warnings ?? [];

    return (
        <Wrapper>
            <Panel>
                <Header>
                    <div>
                        <Title><FontAwesomeIcon icon={faLayerGroup} /> Versions</Title>
                        <Subtitle>What this server runs now, and the versions available for its loader.</Subtitle>
                    </div>
                </Header>

                {loading ? (
                    <Muted><FontAwesomeIcon icon={faSpinner} spin /> Loading…</Muted>
                ) : error ? (
                    <Muted><FontAwesomeIcon icon={faExclamationTriangle} /> {error}</Muted>
                ) : (
                    <CurrentRow>
                        <Pill><FontAwesomeIcon icon={faCircle} tw="text-green-400" style={{ fontSize: 8 }} /> Loader: {loaderLabel}</Pill>
                        <Pill>Version: {currentVer ?? 'Not detected'}</Pill>
                        {current?.confidence != null && current.loader && (
                            <Pill style={{ opacity: 0.8 }}>Detection confidence: {current.confidence}%</Pill>
                        )}
                    </CurrentRow>
                )}
            </Panel>

            {/* Preview / progress popout */}
            {!loading && !error && selected && (
                <Overlay onClick={(e) => { if (e.target === e.currentTarget && !busy) reset(); }}>
                  <Modal>
                    <Header>
                        <div>
                            <Title>
                                <FontAwesomeIcon icon={faSyncAlt} spin={busy} /> Change to {selected}
                            </Title>
                            <Subtitle>{currentVer ? `From ${currentVer} → ${selected}` : `Target ${selected}`}</Subtitle>
                        </div>
                    </Header>

                    {phase === 'loading_preflight' && <Muted><FontAwesomeIcon icon={faSpinner} spin /> Checking that version…</Muted>}

                    {phase === 'preview' && preflight && (
                        <>
                            <StatRow>
                                <Stat tone={isUp ? 'up' : isDown ? 'down' : 'neutral'}>
                                    <FontAwesomeIcon icon={isDown ? faArrowDown : isUp ? faArrowUp : faCircle} />
                                    {cap(preflight.direction)}
                                </Stat>
                                <Stat>Build {preflight.target?.build ?? 'latest'}</Stat>
                                <Stat tone={preflight.image?.change ? 'down' : 'neutral'}>
                                    {preflight.image?.change
                                        ? `Runtime image changes`
                                        : `Runtime image unchanged`}
                                </Stat>
                            </StatRow>

                            {preflight.image?.change && (
                                <Note>Java runtime: {preflight.image?.current || 'current'} → <strong>{preflight.image?.resolved}</strong>.</Note>
                            )}

                            {isDown && (
                                <Banner tone="warn">
                                    <FontAwesomeIcon icon={faExclamationTriangle} style={{ marginTop: 2 }} />
                                    <span>
                                        <strong>Downgrading can corrupt your world.</strong> An older version may be unable
                                        to read a world saved by a newer one, and newer format changes can be one-way. The
                                        pre-switch backup taken below is your safety net — keep it.
                                    </span>
                                </Banner>
                            )}

                            {warnings.map((w, i) => (
                                <Banner tone="warn" key={i}>
                                    <FontAwesomeIcon icon={faExclamationTriangle} style={{ marginTop: 2 }} /><span>{w}</span>
                                </Banner>
                            ))}
                            {blockers.map((b, i) => (
                                <Banner tone="error" key={i}>
                                    <FontAwesomeIcon icon={faTimes} style={{ marginTop: 2 }} /><span>{b}</span>
                                </Banner>
                            ))}

                            <Note style={{ marginTop: 14, marginBottom: 2 }}><strong tw="text-neutral-100">World &amp; files</strong></Note>
                            <RadioRow>
                                <input type="radio" name="wipemode" checked={wipeMode === 'none'} onChange={() => setWipeMode('none')} style={{ marginTop: 3 }} />
                                <span>
                                    <strong tw="text-neutral-100">Keep world</strong>
                                    <Note style={{ marginTop: 2 }}>Reinstall over your existing world — best for upgrades.{isDown ? ' On a downgrade the older version may fail to load it.' : ''}</Note>
                                </span>
                            </RadioRow>
                            <RadioRow>
                                <input type="radio" name="wipemode" checked={wipeMode === 'reset_world'} onChange={() => setWipeMode('reset_world')} style={{ marginTop: 3 }} />
                                <span>
                                    <strong tw="text-neutral-100">Reset world{isDown ? ' (recommended)' : ''}</strong>
                                    <Note style={{ marginTop: 2 }}>Wipe the world (a fresh one generates) but keep plugins &amp; config. Avoids format-mismatch corruption when downgrading.</Note>
                                </span>
                            </RadioRow>
                            <RadioRow>
                                <input type="radio" name="wipemode" checked={wipeMode === 'clean_slate'} onChange={() => setWipeMode('clean_slate')} style={{ marginTop: 3 }} />
                                <span>
                                    <strong tw="text-neutral-100">Clean slate</strong>
                                    <Note style={{ marginTop: 2 }}>Wipe everything (world, plugins, config) and reinstall fresh at {selected}. Only the EULA acceptance is kept.</Note>
                                </span>
                            </RadioRow>

                            {wipeMode !== 'clean_slate' && (
                                <ToggleRow>
                                    <input type="checkbox" checked={deletePlugins} onChange={(e) => setDeletePlugins(e.target.checked)} style={{ marginTop: 3 }} />
                                    <span>
                                        <strong tw="text-neutral-100">Remove plugin jars (recommended)</strong>
                                        <Note style={{ marginTop: 2 }}>
                                            Plugins built for {currentVer} often stop {selected} from booting. Jars are removed;
                                            their config folders are kept, so re-adding a compatible build keeps its settings.
                                            {!deletePlugins && ' Keeping them may prevent the server from starting.'}
                                        </Note>
                                    </span>
                                </ToggleRow>
                            )}

                            <Banner tone="info">
                                <FontAwesomeIcon icon={faArchive} style={{ marginTop: 2 }} />
                                <span>A full backup named “Pre-switch → {selected}” is taken and confirmed complete before anything changes.</span>
                            </Banner>

                            <Actions>
                                <Can action={['startup.update', 'startup.docker-image', 'settings.reinstall', 'backup.create']}><Btn onClick={confirmSwitch} disabled={!preflight.can_proceed || blockers.length > 0}>
                                    <FontAwesomeIcon icon={faSyncAlt} /> Back up &amp; change to {selected}
                                </Btn></Can>
                                <Btn variant="ghost" onClick={reset}>Cancel</Btn>
                            </Actions>
                        </>
                    )}

                    {(phase === 'backing_up' || phase === 'applying' || phase === 'installing') && (
                        <Banner tone="info"><FontAwesomeIcon icon={faSpinner} spin style={{ marginTop: 2 }} /><span>{phaseMsg}</span></Banner>
                    )}

                    {phase === 'done' && (
                        <>
                            <Banner tone="info"><FontAwesomeIcon icon={faCheckCircle} style={{ marginTop: 2 }} /><span>{phaseMsg}</span></Banner>
                            <Actions><Btn variant="ghost" onClick={reset}>Done</Btn></Actions>
                        </>
                    )}

                    {phase === 'failed' && (
                        <>
                            <Banner tone="error"><FontAwesomeIcon icon={faExclamationTriangle} style={{ marginTop: 2 }} /><span>{phaseMsg}</span></Banner>
                            <Actions><Btn variant="ghost" onClick={reset}>Close</Btn></Actions>
                        </>
                    )}
                  </Modal>
                </Overlay>
            )}

            {/* Loader picker (Phase 3) */}
            {!loading && !error && loaderList.length > 0 && (
                <Panel>
                    <Header>
                        <div>
                            <Title><FontAwesomeIcon icon={faLayerGroup} /> Loader</Title>
                            <Subtitle>
                                {loaderCurrent ? <>Current loader: <strong tw="text-neutral-100" style={{ textTransform: 'capitalize' }}>{loaderCurrent}</strong>. </> : null}
                                Select a different loader to switch — a loader change wipes everything and reinstalls fresh.
                            </Subtitle>
                        </div>
                    </Header>

                    {loaderPhase === 'working' ? (
                        <Banner tone="info"><FontAwesomeIcon icon={faSpinner} spin style={{ marginTop: 2 }} /><span>{loaderMsg}</span></Banner>
                    ) : loaderPhase === 'done' ? (
                        <>
                            <Banner tone="info"><FontAwesomeIcon icon={faCheckCircle} style={{ marginTop: 2 }} /><span>{loaderMsg}</span></Banner>
                            <Actions><Btn variant="ghost" onClick={clearLoaderSelection}>Done</Btn></Actions>
                        </>
                    ) : (
                        <>
                            <Select
                                value={loaderTarget ? String(loaderTarget.egg_id) : ''}
                                disabled={busy}
                                onChange={(e) => selectLoader(e.target.value)}
                            >
                                <option value="">Select a loader…</option>
                                {loaderList.map((l) => {
                                    const isCurrent = !!loaderCurrent && l.loader === loaderCurrent.toLowerCase();
                                    return (
                                        <option key={l.egg_id} value={l.egg_id} disabled={isCurrent}>
                                            {l.loader.charAt(0).toUpperCase() + l.loader.slice(1)}{isCurrent ? ' (current)' : ''}
                                        </option>
                                    );
                                })}
                            </Select>

                            {loaderPhase === 'failed' && (
                                <Banner tone="error"><FontAwesomeIcon icon={faExclamationTriangle} style={{ marginTop: 2 }} /><span>{loaderMsg}</span></Banner>
                            )}

                            {loaderTarget && (
                                <>
                                    <Banner tone="warn">
                                        <FontAwesomeIcon icon={faExclamationTriangle} style={{ marginTop: 2 }} />
                                        <span><strong>This wipes everything.</strong> Switching loader deletes all files (world, plugins/mods, configs) and reinstalls fresh — the old loader's content isn't compatible. Only your backup can bring the old world back.</span>
                                    </Banner>

                                    <Note style={{ marginTop: 12, marginBottom: 2 }}>Minecraft version for the new loader:</Note>
                                    <NameInput value={loaderVersion} onChange={(e) => setLoaderVersion(e.target.value)} placeholder="e.g. 1.21.1" />

                                    <Note style={{ marginTop: 12 }}>
                                        A full backup named “Pre-loader {loaderTarget.loader}” is taken and confirmed complete before anything is wiped.
                                    </Note>

                                    <Note style={{ marginTop: 12 }}>Type the server name <strong tw="text-neutral-100">{serverName}</strong> to confirm:</Note>
                                    <NameInput value={loaderConfirm} onChange={(e) => setLoaderConfirm(e.target.value)} placeholder={serverName} />

                                    <Actions>
                                        <Can action={['startup.update', 'startup.docker-image', 'settings.reinstall', 'backup.create']}><Btn onClick={confirmLoaderSwitch} disabled={loaderConfirm.trim() !== serverName}>
                                            <FontAwesomeIcon icon={faSyncAlt} /> Wipe &amp; switch
                                        </Btn></Can>
                                        <Btn variant="ghost" onClick={clearLoaderSelection}>Cancel</Btn>
                                    </Actions>
                                </>
                            )}
                        </>
                    )}

                    <Note style={{ marginTop: 12 }}>Missing a loader (e.g. Fabric)? Import its egg via the admin Egg Manager and it appears here.</Note>
                </Panel>
            )}

            {/* Available versions list */}
            {!loading && !error && (
                <Panel>
                    <Header>
                        <div>
                            <Title><FontAwesomeIcon icon={faCheckCircle} /> Available versions</Title>
                            <Subtitle>
                                {available?.supported
                                    ? `Published ${loaderLabel} versions, newest first. Select one to change to it.`
                                    : 'Version browsing for this loader is coming in a later update.'}
                            </Subtitle>
                        </div>
                    </Header>

                    {available?.supported && available.versions.length > 0 ? (
                        <VersionGrid>
                            {available.versions.map((v) => (
                                <VersionChip
                                    key={v}
                                    current={v === currentVer}
                                    selected={v === selected}
                                    disabled={busy}
                                    onClick={() => selectTarget(v)}
                                >
                                    <span>{v}</span>
                                    {v === currentVer && <FontAwesomeIcon icon={faCheckCircle} />}
                                </VersionChip>
                            ))}
                        </VersionGrid>
                    ) : available?.supported ? (
                        <Muted>No versions returned upstream. Try again shortly.</Muted>
                    ) : (
                        <Note>
                            Detected loader: <strong>{loaderLabel}</strong>. Live version listing is currently
                            available for Paper; other loaders will follow. Your current version is still shown above.
                        </Note>
                    )}
                </Panel>
            )}
            {/* Danger zone — nuclear reset */}
            {!loading && !error && (
                <DangerPanel>
                    <Header>
                        <div>
                            <Title><FontAwesomeIcon icon={faRadiation} /> Danger zone — reset &amp; reinstall</Title>
                            <Subtitle>Wipe files and reinstall from scratch. This cannot be undone.</Subtitle>
                        </div>
                    </Header>

                    {nukePhase === 'working' ? (
                        <Banner tone="warn"><FontAwesomeIcon icon={faSpinner} spin style={{ marginTop: 2 }} /><span>{nukeMsg}</span></Banner>
                    ) : nukePhase === 'done' ? (
                        <>
                            <Banner tone="info"><FontAwesomeIcon icon={faCheckCircle} style={{ marginTop: 2 }} /><span>{nukeMsg}</span></Banner>
                            <Actions><Btn variant="ghost" onClick={() => { setNukePhase('idle'); setNukeMsg(''); }}>Done</Btn></Actions>
                        </>
                    ) : (
                        <>
                            {nukePhase === 'failed' && (
                                <Banner tone="error"><FontAwesomeIcon icon={faExclamationTriangle} style={{ marginTop: 2 }} /><span>{nukeMsg}</span></Banner>
                            )}

                            <RadioRow>
                                <input type="radio" name="nukemode" checked={nukeMode === 'keep_plugins'} onChange={() => setNukeMode('keep_plugins')} style={{ marginTop: 3 }} />
                                <span>
                                    <strong tw="text-neutral-100">Reset world, keep plugins &amp; config</strong>
                                    <Note style={{ marginTop: 2 }}>Deletes the world (and nether/end). Plugins, configs and server.properties are kept; a fresh world generates on next boot.</Note>
                                </span>
                            </RadioRow>
                            <RadioRow>
                                <input type="radio" name="nukemode" checked={nukeMode === 'clean_slate'} onChange={() => setNukeMode('clean_slate')} style={{ marginTop: 3 }} />
                                <span>
                                    <strong tw="text-neutral-100">Clean slate — wipe everything</strong>
                                    <Note style={{ marginTop: 2 }}>Deletes all server files (world, plugins, configs, jar). Only the EULA acceptance is kept so it still boots — effectively a brand-new server.</Note>
                                </span>
                            </RadioRow>

                            <ToggleRow>
                                <input type="checkbox" checked={nukeBackup} onChange={(e) => setNukeBackup(e.target.checked)} style={{ marginTop: 3 }} />
                                <span>
                                    <strong tw="text-neutral-100">Take a backup first (recommended)</strong>
                                    <Note style={{ marginTop: 2 }}>Saves a full backup before wiping, so you can restore if this was a mistake.</Note>
                                </span>
                            </ToggleRow>

                            <Note style={{ marginTop: 14 }}>Type the server name <strong tw="text-neutral-100">{serverName}</strong> to confirm:</Note>
                            <NameInput value={nukeConfirm} onChange={(e) => setNukeConfirm(e.target.value)} placeholder={serverName} />

                            <Can action={['settings.reinstall', 'file.delete']}><DangerBtn onClick={confirmNuclear} disabled={nukeConfirm.trim() !== serverName}>
                                <FontAwesomeIcon icon={faRadiation} /> Wipe &amp; reinstall
                            </DangerBtn></Can>
                        </>
                    )}
                </DangerPanel>
            )}
            <p className="text-xs text-neutral-500 text-center mt-2">
                Direct server jars for version and loader switches are powered by <a href="https://mcjars.app" target="_blank" rel="noopener noreferrer" className="underline">MCJars</a>.
            </p>
        </Wrapper>
    );
};
