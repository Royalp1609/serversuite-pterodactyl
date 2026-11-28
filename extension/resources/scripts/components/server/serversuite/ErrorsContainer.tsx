import React, { useCallback, useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheck, faExclamationTriangle, faSpinner, faSync } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — Errors tab (#87; Pelican #41/#91).
 * The warnings and errors in the server's log, grouped by message, with
 * the plugin or mod each points at. Minecraft reads logs/latest.log and
 * lists crash reports; Rust reads Oxide's or Carbon's log, Valheim
 * BepInEx's. When plugins fail because Rust changed under Oxide/Carbon, a
 * notice says so instead of blaming them. Read through Wings (the
 * ErrorLogService); refreshes every 15 s while the tab is open.
 * Acknowledge (one row, or all) hides an error until it happens again (a group
 * that repeats, a new log after a restart, a newer crash report).
 */

const Wrapper = styled.div`${tw`p-6 max-w-7xl mx-auto`};`;  // same page margins as the Mods tab
const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Notice = styled.div`
    ${tw`rounded-xl px-4 py-3 mb-4 text-sm`};
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.35);
    color: rgb(253, 230, 138);
`;
const Row = styled.div`
    ${tw`rounded-lg mb-2`};
    background: rgba(15, 23, 42, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.05);
`;
const Head = styled.button`${tw`w-full flex items-start gap-3 text-left px-3 py-2`};`;
const Badge = styled.span<{ error: boolean }>`
    ${tw`text-xs font-semibold rounded px-2 py-px flex-shrink-0`};
    background: ${(p) => (p.error ? 'rgba(239, 68, 68, 0.18)' : 'rgba(245, 158, 11, 0.16)')};
    color: ${(p) => (p.error ? 'rgb(248, 113, 113)' : 'rgb(251, 191, 36)')};
`;
const Chip = styled.span`
    ${tw`text-xs rounded px-2 py-px`};
    background: rgba(99, 102, 241, 0.16);
    color: rgb(165, 180, 252);
`;
const Pre = styled.pre`
    ${tw`text-xs text-neutral-300 font-mono px-3 pb-3 overflow-auto whitespace-pre`};
    max-height: 24rem;
`;
const GhostButton = styled.button`
    ${tw`px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2`};
    background: rgba(30, 41, 59, 0.6);
    color: rgb(203, 213, 225);
    border: 1px solid rgba(255, 255, 255, 0.06);
    &:hover { background: rgba(51, 65, 85, 0.8); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;

interface Culprit { name: string | null; evidence: string; strength: string }
interface Group {
    key: string; level: 'error' | 'warn'; count: number; first: string; last: string; message: string; exception: string | null;
    culprit: Culprit | null; mod: { mod_name: string | null } | null; detail: string; framework?: boolean; acknowledged?: boolean;
}
interface Report {
    minecraft: boolean; found: boolean; size: number; partial: boolean; files: string[]; groups: Group[];
    crashes: { name: string; at: string | null; acknowledged?: boolean }[]; notice: { plugins: string[]; wipe_day: boolean } | null;
    ack: { at: string | null } | null; new_count: number;
}
interface Crash { name: string; summary: string | null; suspects: (Culprit & { mod_name: string | null })[]; text: string }

const size = (b: number) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MiB` : `${Math.round(b / 1024)} KiB`);

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const base = `/extensions/serversuite/${uuid}/errors`;
    const [report, setReport] = useState<Report | null>(null);
    const [warnings, setWarnings] = useState(false);
    const [open, setOpen] = useState<string[]>([]);
    const [crash, setCrash] = useState<Crash | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [showAcked, setShowAcked] = useState(false);
    const [acking, setAcking] = useState(false);

    const load = useCallback((refresh = false) => {
        if (!uuid) return;
        if (refresh) setBusy(true);
        http.get(base, { params: { warnings: warnings ? 1 : 0, refresh: refresh ? 1 : 0 } })
            .then((res) => { setReport(res.data.data); setError(null); })
            .catch((e) => setError(e?.response?.data?.error || e?.response?.data?.message || 'Could not read the log.'))
            .finally(() => setBusy(false));
    }, [uuid, warnings]);

    useEffect(() => {
        load();
        const t = setInterval(() => document.visibilityState === 'visible' && load(), 15000);
        return () => clearInterval(t);
    }, [load]);

    const toggle = (key: string) => setOpen((o) => (o.includes(key) ? o.filter((k) => k !== key) : [...o, key].slice(-20)));
    const showCrash = (name: string) => http.get(`${base}/crash`, { params: { name } })
        .then((res) => setCrash(res.data.data))
        .catch((e) => setError(e?.response?.data?.error || 'Could not open the crash report.'));

    // No key/crash: everything listed now. With one: just that group or crash report.
    const acknowledge = (undo = false, item: { key?: string; crash?: string } = {}) => {
        setAcking(true);
        http.post(`${base}/acknowledge`, { undo, ...item })
            .then(() => load(true))
            .catch((e) => setError(e?.response?.data?.error || e?.response?.data?.message || 'Could not acknowledge.'))
            .finally(() => setAcking(false));
    };

    const r = report;
    const groups = (r?.groups ?? []).filter((g) => showAcked || !g.acknowledged);
    const crashes = (r?.crashes ?? []).filter((c) => showAcked || !c.acknowledged);
    const ackedCount = (r?.groups ?? []).filter((g) => g.acknowledged).length + (r?.crashes ?? []).filter((c) => c.acknowledged).length;
    const files = r?.files?.length ? r.files : ['logs/latest.log'];

    return (
        <Wrapper>
            <Panel>
                <div className="flex items-center gap-3 flex-wrap mb-3">
                    <FontAwesomeIcon icon={faExclamationTriangle} className="text-2xl text-indigo-400" />
                    <div>
                        <h1 className="text-2xl font-bold text-white">Log errors</h1>
                        <p className="text-xs text-neutral-500">
                            {!r ? (error || 'Loading…')
                                : !r.found ? `No log on this server yet${r.minecraft ? '' : ' (this game\'s log is written once the server and its mod loader have started)'}. Start it once and errors show up here.`
                                : `From ${files.join(', ')}${r.partial ? ` (the most recent part: the log is ${size(r.size)})` : ''}. Repeats are grouped; newest first.`}
                        </p>
                    </div>
                    <span className="flex items-center gap-3" style={{ marginLeft: 'auto' }}>
                        <label className="flex items-center gap-2 text-sm text-neutral-300">
                            <input type="checkbox" checked={warnings} onChange={(e) => setWarnings(e.target.checked)} /> Show warnings
                        </label>
                        <GhostButton onClick={() => load(true)} disabled={busy}>
                            <FontAwesomeIcon icon={busy ? faSpinner : faSync} spin={busy} /> Refresh
                        </GhostButton>
                        {!!r && r.new_count > 0 && (
                            <GhostButton onClick={() => acknowledge()} disabled={acking} title="Hide every error and crash report listed now until it happens again">
                                <FontAwesomeIcon icon={acking ? faSpinner : faCheck} spin={acking} /> Acknowledge all
                            </GhostButton>
                        )}
                    </span>
                </div>
                {r?.ack && (
                    <p className="text-xs text-neutral-500 mb-3 flex items-center gap-3 flex-wrap">
                        <span>{ackedCount} acknowledged{r.ack.at ? ` (last Acknowledge all ${new Date(r.ack.at).toLocaleString()})` : ''}: hidden until {ackedCount === 1 ? 'it happens' : 'they happen'} again.</span>
                        {ackedCount > 0 && (
                            <label className="flex items-center gap-1 text-neutral-400">
                                <input type="checkbox" checked={showAcked} onChange={(e) => setShowAcked(e.target.checked)} /> Show them
                            </label>
                        )}
                        <button className="underline text-neutral-400" onClick={() => acknowledge(true)} disabled={acking} title="Show every acknowledged error and crash report as new again">Undo all</button>
                    </p>
                )}
                {error && r && <p className="text-sm text-red-400 mb-3">{error}</p>}

                {r?.notice && (
                    <Notice>
                        <p className="font-semibold text-white">This looks like Rust changed under Oxide/Carbon, not broken plugins</p>
                        <p className="mt-1 text-xs">
                            {r.notice.plugins.length ? `${r.notice.plugins.join(', ')} ${r.notice.plugins.length > 1 ? 'fail' : 'fails'}` : 'Plugins fail'} with a method,
                            field or type that no longer exists. That happens when Rust updates before the mod framework does
                            {r.notice.wipe_day ? ', and Rust updates on force-wipe day (the first Thursday of the month), which is now' : ''}.
                            Wait for the Oxide or Carbon update (they usually follow within hours), restart the server so it installs, then check again.
                            Removing the plugins won&apos;t fix this.
                        </p>
                    </Notice>
                )}

                {groups.map((g) => (
                    <Row key={g.key} style={g.acknowledged ? { opacity: 0.55 } : undefined}>
                        <Head onClick={() => toggle(g.key)}>
                            <Badge error={g.level === 'error'}>{g.level === 'error' ? 'Error' : 'Warn'}</Badge>
                            <span className="text-xs text-neutral-400 flex-shrink-0" title="times in this part of the log">×{g.count}</span>
                            <span className="text-sm text-neutral-200 flex-1 break-words">
                                {g.message || g.exception || '(no message)'}
                                {g.exception && g.message && <span className="block text-xs text-neutral-500 mt-1">{g.exception}</span>}
                            </span>
                            <span className="flex flex-col items-end gap-1 flex-shrink-0">
                                {g.mod?.mod_name && <Chip title={g.culprit?.evidence}>{g.culprit?.strength === 'weak' ? 'maybe ' : ''}{g.mod.mod_name}</Chip>}
                                {g.framework && <span className="text-xs text-neutral-500" title="A method, field or type the plugin uses is missing: usually Rust updated before Oxide/Carbon.">probably not the plugin&apos;s fault</span>}
                                {g.acknowledged && <span className="text-xs text-neutral-500">acknowledged</span>}
                                <button
                                    className="text-xs underline text-neutral-400"
                                    disabled={acking}
                                    onClick={(e) => { e.stopPropagation(); acknowledge(!!g.acknowledged, { key: g.key }); }}
                                    title={g.acknowledged ? 'Show this error as new again' : 'Hide this error until it happens again'}
                                >
                                    {g.acknowledged ? 'Undo' : 'Acknowledge'}
                                </button>
                                {(g.first || g.last) && <span className="text-xs text-neutral-500 font-mono">{g.count > 1 ? `${g.first} – ${g.last}` : g.last}</span>}
                            </span>
                        </Head>
                        {open.includes(g.key) && <Pre>{g.detail}</Pre>}
                    </Row>
                ))}
                {r?.found && groups.length === 0 && (
                    <p className="text-center text-sm text-neutral-500 py-6">
                        {r.groups.length > 0 ? `No new ${warnings ? 'errors or warnings' : 'errors'} since you acknowledged.` : `No ${warnings ? 'errors or warnings' : 'errors'} in the log.`}
                    </p>
                )}
            </Panel>

            {r?.minecraft && (
                <Panel>
                    <h2 className="text-lg font-semibold text-white mb-2">Crash reports</h2>
                    {crashes.length === 0 && <p className="text-sm text-neutral-500">{r.crashes.length > 0 ? 'No new crash reports since you acknowledged.' : 'No crash reports.'}</p>}
                    {crashes.map((c) => (
                        <Row key={c.name} className="flex items-center gap-3 px-3 py-2" style={c.acknowledged ? { opacity: 0.55 } : undefined}>
                            <span className="text-sm text-neutral-200 flex-1 font-mono break-all">{c.name}</span>
                            {c.acknowledged && <span className="text-xs text-neutral-500">acknowledged</span>}
                            {c.at && <span className="text-xs text-neutral-500">{new Date(c.at).toLocaleString()}</span>}
                            <button
                                className="text-xs underline text-neutral-400"
                                disabled={acking}
                                onClick={() => acknowledge(!!c.acknowledged, { crash: c.name })}
                                title={c.acknowledged ? 'Show this crash report as new again' : 'Hide this crash report'}
                            >
                                {c.acknowledged ? 'Undo' : 'Acknowledge'}
                            </button>
                            <GhostButton onClick={() => showCrash(c.name)}>View</GhostButton>
                        </Row>
                    ))}
                    {crash && (
                        <div className="mt-3">
                            <div className="flex items-center gap-2 mb-2">
                                <span className="font-mono text-white text-sm break-all">{crash.name}</span>
                                <GhostButton onClick={() => setCrash(null)} style={{ marginLeft: 'auto' }}>Close</GhostButton>
                            </div>
                            {crash.summary && <p className="text-sm text-white mb-2">{crash.summary}</p>}
                            {crash.suspects.map((s, i) => (
                                <p key={i} className="text-xs mb-1">
                                    <Chip>{s.strength === 'weak' ? 'maybe ' : ''}{s.mod_name}</Chip> <span className="text-neutral-500">{s.evidence}</span>
                                </p>
                            ))}
                            <Pre style={{ maxHeight: 'none' }}>{crash.text}</Pre>
                        </div>
                    )}
                </Panel>
            )}
        </Wrapper>
    );
};
