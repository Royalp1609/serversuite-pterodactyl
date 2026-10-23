import React, { useCallback, useEffect, useRef, useState } from 'react';
import Can from '@/components/elements/Can';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArrowLeft, faBoxOpen, faExternalLinkAlt, faSpinner } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — Modpacks tab (#23).
 * Search Modrinth packs that fit this server, review a version, install it,
 * follow the progress, and update it later. The install runs as a queued
 * job on the panel (ModpackInstaller): stop the server, "Pre-modpack"
 * backup, Wings fetches the pack and every file, then the pack's configs.
 * Only Fabric, Quilt, Forge and NeoForge servers can take a pack; others
 * get a note instead. The server must already run the pack's loader and
 * Minecraft version (the review says what to switch to).
 */

const Wrapper = styled.div`${tw`my-6`};`;
const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Grid = styled.div`
    ${tw`grid gap-4`};
    grid-template-columns: repeat(1, minmax(0, 1fr));
    @media (min-width: 768px) { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    @media (min-width: 1280px) { grid-template-columns: repeat(3, minmax(0, 1fr)); }
`;
const Card = styled.button`
    ${tw`flex gap-3 rounded-xl p-4 text-left w-full`};
    background: rgba(30, 41, 59, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.06);
    &:hover { border-color: rgba(99, 102, 241, 0.45); }
`;
const Row = styled.div`
    ${tw`flex flex-wrap items-center gap-3 rounded-lg px-3 py-2`};
    background: rgba(15, 23, 42, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.05);
`;
const Input = styled.input`
    ${tw`text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none flex-1`};
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
    min-width: 14rem;
    &:focus { border-color: rgb(99, 102, 241); }
`;
const Select = styled.select`
    ${tw`text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none`};
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
`;
const PrimaryButton = styled.button`
    ${tw`px-4 py-2 rounded-lg text-sm font-medium text-white flex items-center gap-2`};
    background: rgb(99, 102, 241);
    &:hover { background: rgb(79, 82, 221); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;
const GhostButton = styled.button`
    ${tw`px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2`};
    background: rgba(30, 41, 59, 0.6);
    color: rgb(203, 213, 225);
    border: 1px solid rgba(255, 255, 255, 0.06);
    &:hover { background: rgba(51, 65, 85, 0.8); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;
const Icon = styled.img`
    ${tw`rounded-lg flex-shrink-0`};
    width: 3rem; height: 3rem; object-fit: cover;
    background: rgba(15, 23, 42, 0.6);
`;
const NoIcon = styled.div`
    ${tw`rounded-lg flex-shrink-0`};
    width: 3rem; height: 3rem;
    background: rgba(15, 23, 42, 0.6);
`;
const Chip = styled.span<{ green?: boolean }>`
    ${tw`text-xs font-semibold rounded px-2`};
    background: ${(p) => (p.green ? 'rgba(16,185,129,0.16)' : 'rgba(99,102,241,0.18)')};
    color: ${(p) => (p.green ? 'rgb(52,211,153)' : 'rgb(165,180,252)')};
`;
const Bad = styled.div`
    ${tw`text-sm rounded-lg p-3 mb-3`};
    background: rgba(239, 68, 68, 0.12);
    color: rgb(248, 113, 113);
`;
const Note = styled.div`
    ${tw`text-sm rounded-lg p-3 mb-3`};
    background: rgba(99, 102, 241, 0.12);
    color: rgb(165, 180, 252);
`;
const Bar = styled.div`
    ${tw`rounded overflow-hidden`};
    height: 0.5rem;
    background: rgba(15, 23, 42, 0.7);
    & > div { height: 100%; background: rgb(99, 102, 241); transition: width 0.4s ease; }
`;
const List = styled.div`
    ${tw`mt-2 text-xs`};
    max-height: 14rem;
    overflow-y: auto;
`;

interface Context { loader: string | null; minecraft: string | null }
interface Status {
    status: 'installing' | 'installed' | 'failed';
    phase: string | null;
    done: number; total: number; failed: number;
    failures: Array<{ path: string; reason: string }>;
    error: string | null;
    target: { name: string | null; version_number: string | null };
    installed: { project_id: string; name: string; version_number: string; icon_url: string | null; minecraft: string | null; loader: string | null; at: string | null } | null;
}
interface Hit { id: string; name: string; author: string; description: string; downloads: number; icon_url: string | null; page_url: string | null }
interface Version { id: string; version_number: string; channel: string; loaders: string[]; game_versions: string[]; compatible: boolean; reason: string | null }
interface Preview {
    pack: { project_id: string; name: string; icon_url: string | null; page_url: string | null };
    version: { id: string; version_number: string };
    requires: { loader: string | null; loader_version: string | null; minecraft: string };
    blockers: string[];
    current: { name: string; version_number: string; same_pack: boolean } | null;
    counts: { install: number; unchanged: number; remove: number; replace: number; overrides: number; skipped: number };
    download_bytes: number;
    skipped: Array<{ path: string; reason: string }>;
    replace: Array<{ name: string }>;
    backups: 'yes' | 'disabled';
}
interface Pack { id: string; name: string; icon: string | null; url: string | null }

const PAGE = 24;
const errorOf = (e: any, fallback: string) => e?.response?.data?.error || e?.response?.data?.message || fallback;
const ucfirst = (s: string | null | undefined) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : '');
const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;
const bytes = (n: number) => {
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${i ? n.toFixed(1) : n} ${units[i]}`;
};
const PHASES: Record<string, string> = {
    stopping: 'Stopping the server', backing_up: 'Taking a backup', staging: 'Downloading the pack',
    files: 'Downloading files', overrides: "Applying the pack's configs", finishing: 'Finishing',
};

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const base = `/extensions/serversuite/${uuid}/modpacks`;

    const [supported, setSupported] = useState<boolean | null>(null);
    const [context, setContext] = useState<Context>({ loader: null, minecraft: null });
    const [status, setStatus] = useState<Status | null>(null);
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState('relevance');
    const [results, setResults] = useState<Hit[]>([]);
    const [hasMore, setHasMore] = useState(false);
    const [pack, setPack] = useState<Pack | null>(null);
    const [versions, setVersions] = useState<Version[]>([]);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const wasRunning = useRef(false);

    const running = status?.status === 'installing';

    const loadStatus = useCallback(() => {
        if (!uuid) return;
        http.get(base)
            .then((res) => {
                const d = res.data.data;
                setSupported(d.supported);
                setContext(d.context);
                setStatus(d.status);
                const nowRunning = d.status?.status === 'installing';
                if (wasRunning.current && !nowRunning) {
                    setNotice(d.status?.status === 'installed'
                        ? "Modpack installed. Start the server from the console when you're ready."
                        : null);
                }
                wasRunning.current = nowRunning;
            })
            .catch((e) => setError(errorOf(e, 'Could not load the Modpacks tab.')));
    }, [base, uuid]);

    useEffect(() => { loadStatus(); }, [loadStatus]);

    // Poll while an install runs.
    useEffect(() => {
        if (!running) return;
        const t = setInterval(loadStatus, 3000);
        return () => clearInterval(t);
    }, [running, loadStatus]);

    /** offset 0 starts a new search; more than 0 appends ("Load more"). */
    const search = useCallback((offset: number) => {
        if (!uuid) return;
        setBusy('search');
        http.get(`${base}/search`, { params: { query, sort, offset } })
            .then((res) => {
                const hits: Hit[] = res.data.data;
                setResults((cur) => (offset > 0 ? [...cur, ...hits] : hits));
                setHasMore(hits.length === PAGE);
            })
            .catch((e) => { if (offset === 0) setResults([]); setHasMore(false); setError(errorOf(e, 'Could not search modpacks.')); })
            .finally(() => setBusy(null));
    }, [base, uuid, query, sort]);

    useEffect(() => {
        if (!supported) return;
        const t = setTimeout(() => search(0), 400);
        return () => clearTimeout(t);
    }, [supported, search]);

    const open = (p: Pack) => {
        setPack(p);
        setPreview(null);
        setConfirmed(false);
        setVersions([]);
        setError(null);
        setBusy('versions');
        http.get(`${base}/${encodeURIComponent(p.id)}/versions`)
            .then((res) => setVersions(res.data.data))
            .catch((e) => setError(errorOf(e, 'Could not load versions.')))
            .finally(() => setBusy(null));
    };

    const review = (v: Version) => {
        if (!pack || !v.compatible) return;
        setBusy(`review-${v.id}`);
        setError(null);
        http.post(`${base}/preview`, { project_id: pack.id, version_id: v.id })
            .then((res) => { setPreview(res.data.data); setConfirmed(false); })
            .catch((e) => setError(errorOf(e, 'Could not read the modpack.')))
            .finally(() => setBusy(null));
    };

    const install = () => {
        if (!pack || !preview || !confirmed) return;
        setBusy('install');
        setError(null);
        http.post(`${base}/install`, { project_id: pack.id, version_id: preview.version.id, confirmed: true })
            .then((res) => {
                setStatus(res.data.data);
                wasRunning.current = true;
                setPack(null);
                setPreview(null);
                setNotice('Install started. The server is stopped and backed up first. You can leave this page; the install carries on.');
            })
            .catch((e) => setError(errorOf(e, 'Could not start the install.')))
            .finally(() => setBusy(null));
    };

    if (supported === null) {
        return (
            <Wrapper>
                {error ? <Bad>{error}</Bad> : <p className="text-neutral-400 text-sm"><FontAwesomeIcon icon={faSpinner} spin /> Loading…</p>}
            </Wrapper>
        );
    }

    if (!supported) {
        return (
            <Wrapper>
                <Panel>
                    <h3 className="font-semibold text-white mb-2"><FontAwesomeIcon icon={faBoxOpen} /> Modpacks</h3>
                    <p className="text-sm text-neutral-300">
                        Modpacks need a mod loader server: Fabric, Quilt, Forge or NeoForge.
                        This server runs {context.loader ? ucfirst(context.loader) : 'something else'}. To use a modpack, change the loader on the Versions tab first.
                    </p>
                </Panel>
            </Wrapper>
        );
    }

    const s = status;
    const pct = s && s.total > 0 ? Math.floor((s.done * 100) / s.total) : 0;

    return (
        <Wrapper>
            {error && <Bad>{error}</Bad>}
            {notice && <Note>{notice}</Note>}

            {/* Current pack / running install */}
            {s && (
                <Panel>
                    {running && (
                        <>
                            <div className="flex items-center gap-2 mb-2">
                                <h3 className="font-semibold text-white">Installing {s.target.name} {s.target.version_number}</h3>
                                <Chip style={{ marginLeft: 'auto' }}>{PHASES[s.phase || ''] || 'Working'}</Chip>
                            </div>
                            <Bar><div style={{ width: `${['files', 'overrides', 'finishing'].includes(s.phase || '') ? pct : 0}%` }} /></Bar>
                            <p className="text-xs text-neutral-400 mt-2">
                                {s.done} of {s.total} files{s.failed > 0 ? ` (${s.failed} failed)` : ''}. The server stays stopped until the install finishes. You can leave this page.
                            </p>
                        </>
                    )}
                    {s.status === 'failed' && (
                        <>
                            <h3 className="font-semibold text-white mb-2">The last modpack install failed</h3>
                            <Bad>{s.error}</Bad>
                            <p className="text-xs text-neutral-400">If files were already changed, the "Pre-modpack" backup on the Backups tab has the server as it was.</p>
                        </>
                    )}
                    {s.installed && !running && (
                        <div className={`flex flex-wrap items-center gap-3 ${s.status === 'failed' ? 'mt-4' : ''}`}>
                            {s.installed.icon_url ? <Icon src={s.installed.icon_url} alt="" /> : <NoIcon />}
                            <div>
                                <div className="font-semibold text-white">{s.installed.name} <span className="text-neutral-400 font-normal">{s.installed.version_number}</span></div>
                                <div className="text-xs text-neutral-400">
                                    {ucfirst(s.installed.loader)} {s.installed.minecraft}{s.installed.at ? ` · installed ${new Date(s.installed.at).toLocaleString()}` : ''}
                                </div>
                            </div>
                            <GhostButton style={{ marginLeft: 'auto' }} onClick={() => s.installed && open({ id: s.installed.project_id, name: s.installed.name, icon: s.installed.icon_url, url: null })}>
                                Versions and updates
                            </GhostButton>
                        </div>
                    )}
                    {!running && s.failed > 0 && (
                        <details className="mt-3">
                            <summary className="text-xs text-neutral-400 cursor-pointer">{plural(s.failed, 'file')} couldn&apos;t be downloaded</summary>
                            <List>
                                {s.failures.map((f) => <div key={f.path}><span className="text-white">{f.path}</span> <span className="text-neutral-400">{f.reason}</span></div>)}
                            </List>
                            <p className="text-xs text-neutral-500 mt-1">Install the same version again to retry just these files.</p>
                        </details>
                    )}
                </Panel>
            )}

            {pack ? (
                <Panel>
                    <div className="flex items-center gap-3 mb-3">
                        {pack.icon ? <Icon src={pack.icon} alt="" /> : <NoIcon />}
                        <div>
                            <h3 className="font-semibold text-white">{pack.name}</h3>
                            {pack.url && <a href={pack.url} target="_blank" rel="noopener noreferrer" className="text-xs text-neutral-400">View on Modrinth <FontAwesomeIcon icon={faExternalLinkAlt} /></a>}
                        </div>
                        <GhostButton style={{ marginLeft: 'auto' }} onClick={() => { setPack(null); setPreview(null); }}><FontAwesomeIcon icon={faArrowLeft} /> Back</GhostButton>
                    </div>

                    {!preview ? (
                        <>
                            {busy === 'versions' && <p className="text-sm text-neutral-400"><FontAwesomeIcon icon={faSpinner} spin /> Loading versions…</p>}
                            <div className="flex flex-col gap-2" style={{ maxHeight: '28rem', overflowY: 'auto' }}>
                                {versions.map((v) => (
                                    <Row key={v.id} style={v.compatible ? undefined : { opacity: 0.55 }}>
                                        <span className="text-white font-semibold">{v.version_number}</span>
                                        {v.channel !== 'release' && <Chip>{v.channel}</Chip>}
                                        <span className="text-xs text-neutral-400">{v.loaders.map(ucfirst).join(', ')} · {v.game_versions.slice(0, 3).join(', ')}</span>
                                        {s?.installed?.project_id === pack.id && s.installed.version_number === v.version_number && <Chip green>installed</Chip>}
                                        {v.compatible ? (
                                            <GhostButton style={{ marginLeft: 'auto' }} disabled={busy !== null} onClick={() => review(v)}>
                                                {busy === `review-${v.id}` ? <><FontAwesomeIcon icon={faSpinner} spin /> Reading the pack…</> : 'Review'}
                                            </GhostButton>
                                        ) : (
                                            <span className="text-xs text-neutral-400" style={{ marginLeft: 'auto' }}>{v.reason}</span>
                                        )}
                                    </Row>
                                ))}
                                {versions.length === 0 && busy !== 'versions' && <p className="text-sm text-neutral-400">No versions with a server-installable .mrpack file.</p>}
                            </div>
                            {versions.length > 0 && !versions.some((v) => v.compatible) && (
                                <p className="text-xs text-neutral-400 mt-2">None of this pack&apos;s versions are for {ucfirst(context.loader)} {context.minecraft}.</p>
                            )}
                        </>
                    ) : (
                        <ReviewPanel
                            preview={preview}
                            confirmed={confirmed}
                            setConfirmed={setConfirmed}
                            busy={busy === 'install' || running}
                            onBack={() => { setPreview(null); setConfirmed(false); }}
                            onInstall={install}
                        />
                    )}
                </Panel>
            ) : (
                <Panel>
                    <div className="flex flex-wrap items-center gap-2 mb-4">
                        <Input value={query} placeholder="Search Modrinth modpacks…" onChange={(e) => setQuery(e.currentTarget.value)} />
                        <Select value={sort} onChange={(e) => setSort(e.currentTarget.value)}>
                            <option value="relevance">Relevance</option>
                            <option value="downloads">Downloads</option>
                            <option value="follows">Follows</option>
                            <option value="updated">Recently updated</option>
                            <option value="newest">Newest</option>
                        </Select>
                        {busy === 'search' && <FontAwesomeIcon icon={faSpinner} spin className="text-neutral-400" />}
                    </div>
                    <Grid>
                        {results.map((r) => (
                            <Card key={r.id} onClick={() => open({ id: r.id, name: r.name, icon: r.icon_url, url: r.page_url })}>
                                {r.icon_url ? <Icon src={r.icon_url} alt="" loading="lazy" /> : <NoIcon />}
                                <div style={{ minWidth: 0 }}>
                                    <div className="font-semibold text-white truncate">{r.name}</div>
                                    <div className="text-xs text-neutral-400">by {r.author} · {r.downloads.toLocaleString()} downloads</div>
                                    <div className="text-xs text-neutral-400 mt-1" style={{ display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>{r.description}</div>
                                </div>
                            </Card>
                        ))}
                    </Grid>
                    {results.length === 0 && busy !== 'search' && <p className="text-sm text-neutral-400">No modpacks found.</p>}
                    {hasMore && (
                        <div className="mt-4 flex justify-center">
                            <GhostButton disabled={busy !== null} onClick={() => search(results.length)}>Load more</GhostButton>
                        </div>
                    )}
                    <p className="text-xs text-neutral-500 mt-4">
                        Showing server packs for this server: {ucfirst(context.loader)} {context.minecraft}. For packs on another Minecraft version or loader, change it on the Versions tab first.
                    </p>
                </Panel>
            )}
        </Wrapper>
    );
};

const ReviewPanel = ({ preview: p, confirmed, setConfirmed, busy, onBack, onInstall }: {
    preview: Preview; confirmed: boolean; setConfirmed: (v: boolean) => void; busy: boolean; onBack: () => void; onInstall: () => void;
}) => {
    const c = p.counts;

    return (
        <>
            <div className="flex items-center gap-2 mb-3">
                <span className="text-white font-semibold">{p.version.version_number}</span>
                <span className="text-xs text-neutral-400">needs {ucfirst(p.requires.loader)} {p.requires.loader_version} · Minecraft {p.requires.minecraft}</span>
                <GhostButton style={{ marginLeft: 'auto' }} onClick={onBack}>Other versions</GhostButton>
            </div>

            {p.blockers.map((b) => <Bad key={b}>{b}</Bad>)}

            {p.current && (
                <p className="text-sm text-neutral-300 mb-2">
                    {p.current.same_pack
                        ? `Updates ${p.current.name} from ${p.current.version_number} to ${p.version.version_number}.`
                        : `Replaces ${p.current.name} ${p.current.version_number}: its files that aren't in this pack are removed.`}
                </p>
            )}

            <ul className="text-sm text-neutral-300 flex flex-col gap-1" style={{ listStyle: 'disc', paddingLeft: '1.2rem' }}>
                <li>
                    Downloads <strong>{plural(c.install, 'file')}</strong> ({bytes(p.download_bytes)}), each straight from its host to the server.
                    {c.unchanged > 0 && ` ${plural(c.unchanged, 'unchanged file')} ${c.unchanged === 1 ? 'is' : 'are'} kept.`}
                </li>
                {c.overrides > 0 && <li>Applies the pack&apos;s {plural(c.overrides, 'config file')} (overwriting files with the same name).</li>}
                {c.remove > 0 && <li>Removes {plural(c.remove, 'file')} the previous pack version had.</li>}
                {c.replace > 0 && <li>Replaces your own copies of {p.replace.map((r) => r.name).join(', ')} with the pack&apos;s.</li>}
                {c.skipped > 0 && <li>Skips {plural(c.skipped, 'file')} that don&apos;t belong on a server (details below).</li>}
                <li>Mods you added yourself stay installed.</li>
                <li>
                    The server is stopped first and left stopped.{' '}
                    {p.backups === 'disabled'
                        ? <strong style={{ color: 'rgb(251,191,36)' }}>Backups are turned off for this server, so no backup is taken.</strong>
                        : 'A "Pre-modpack" backup is taken before anything changes.'}
                </li>
            </ul>

            {c.skipped > 0 && (
                <details className="mt-2">
                    <summary className="text-xs text-neutral-400 cursor-pointer">Skipped files</summary>
                    <List>
                        {p.skipped.map((f) => <div key={f.path}><span className="text-white">{f.path}</span> <span className="text-neutral-400">{f.reason}</span></div>)}
                    </List>
                </details>
            )}

            {p.blockers.length === 0 && (
                <Can action={['mods.install', 'mods.remove', 'control.stop']} renderOnError={
                    <p className="text-xs text-neutral-400 mt-3">Installing a modpack needs the Install mods, Remove mods and Stop server permissions.</p>
                }>
                    <label className="flex items-center gap-2 mt-4 text-sm text-neutral-300 cursor-pointer">
                        <input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.currentTarget.checked)} />
                        I understand the server will be stopped while the pack installs.
                    </label>
                    <PrimaryButton className="mt-3" disabled={!confirmed || busy} onClick={onInstall}>
                        {busy && <FontAwesomeIcon icon={faSpinner} spin />}
                        {p.current?.same_pack ? 'Update modpack' : 'Install modpack'}
                    </PrimaryButton>
                </Can>
            )}
        </>
    );
};
