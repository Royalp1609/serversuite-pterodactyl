import React, { useEffect, useState, useCallback, useRef } from 'react';
import Can from '@/components/elements/Can';
import ConfigsPanel from './ConfigsPanel';
import { getClearance } from './prechange';
import { BackupPromptHost } from './BackupPrompt';
import CrashCard from './CrashCard';
import CrossplayCard from './CrossplayCard';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faSearch, faDownload, faSpinner, faCheckCircle, faTimesCircle,
    faTimes, faCube, faExternalLinkAlt, faExclamationTriangle, faPuzzlePiece,
    faSync, faTrash, faArrowUp, faInfoCircle, faUser, faHeart, faCogs,
} from '@fortawesome/free-solid-svg-icons';
import debounce from 'debounce';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Mod Manager — Browse tab (Build 1).
 * Thin UI over the installed ModManager backend. Endpoints under
 * /extensions/serversuite/ take the server UUID. Functional-first styling.
 * The "Manage Mods" tab is a placeholder; implemented in Build 2.
 */

const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Card = styled.div`
    ${tw`rounded-xl p-5 flex flex-col`};
    background: rgba(30, 41, 59, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.06);
    transition: all 0.2s ease;
    &:hover { border-color: rgba(99,102,241,0.4); transform: translateY(-2px); }
`;
const Field = styled.select`
    ${tw`bg-neutral-900 border border-neutral-700 text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none`};
    background-color: rgba(15,23,42,0.6);
    &:focus { border-color: rgb(99,102,241); }
`;
const Input = styled.input`
    ${tw`w-full bg-neutral-900 border border-neutral-700 text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none`};
    background-color: rgba(15,23,42,0.6);
    &:focus { border-color: rgb(99,102,241); }
`;
const PrimaryButton = styled.button`
    ${tw`px-4 py-2 rounded-lg text-sm font-medium text-white flex items-center gap-2 transition-all`};
    background: rgb(99,102,241);
    &:hover { background: rgb(79,82,221); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;
const GhostButton = styled.button`
    ${tw`px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2 transition-all`};
    background: rgba(30,41,59,0.6);
    color: rgb(203,213,225);
    border: 1px solid rgba(255,255,255,0.06);
    &:hover { background: rgba(51,65,85,0.8); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;
const Tab = styled.button<{ active?: boolean }>`
    ${tw`px-4 py-2 text-sm font-medium flex items-center gap-2 transition-all`};
    color: ${(p) => (p.active ? 'white' : 'rgb(148,163,184)')};
    border-bottom: 2px solid ${(p) => (p.active ? 'rgb(99,102,241)' : 'transparent')};
    &:hover { color: white; }
`;
const Badge = styled.span`
    ${tw`text-xs px-2 py-0.5 rounded-md font-medium`};
    background: rgba(99,102,241,0.18);
    color: rgb(165,180,252);
`;

/*
 * Safe markdown-lite renderer for mod description bodies.
 *
 * Modrinth bodies are markdown that also contains RAW HTML (iframe, div, img
 * embeds). Rendering arbitrary HTML is an XSS risk, so we take the safe route:
 *   1. Strip ALL raw HTML tags entirely (they never reach the DOM).
 *   2. Drop image/embed markdown (the ![alt](url) noise the user saw).
 *   3. Convert a small, safe subset of markdown to React elements:
 *      headings, bold, italic, inline code, links, list items, paragraphs.
 * No dangerouslySetInnerHTML, no user HTML — formatting without the risk.
 */
const MarkdownLite = ({ source }: { source: string }) => {
    // 1. strip raw HTML tags and image embeds
    let text = source
        .replace(/<[^>]+>/g, '')                 // any HTML tag
        .replace(/!\[[^\]]*\]\([^)]*\)/g, '')     // ![alt](url) images
        .replace(/\r\n/g, '\n');

    // inline formatting -> React nodes (bold, italic, code, links)
    const renderInline = (line: string, keyBase: string): React.ReactNode[] => {
        const nodes: React.ReactNode[] = [];
        // tokenize on **bold**, *italic*, `code`, [text](url)
        const regex = /(\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`|\[[^\]]+\]\([^)]+\))/g;
        let last = 0; let m: RegExpExecArray | null; let i = 0;
        while ((m = regex.exec(line)) !== null) {
            if (m.index > last) nodes.push(line.slice(last, m.index));
            const tok = m[0];
            if (tok.startsWith('**')) nodes.push(<strong key={`${keyBase}-${i}`}>{tok.slice(2, -2)}</strong>);
            else if (tok.startsWith('*')) nodes.push(<em key={`${keyBase}-${i}`}>{tok.slice(1, -1)}</em>);
            else if (tok.startsWith('`')) nodes.push(<code key={`${keyBase}-${i}`} className="px-1 rounded bg-neutral-800 text-indigo-300">{tok.slice(1, -1)}</code>);
            else {
                const lm = tok.match(/\[([^\]]+)\]\(([^)]+)\)/);
                if (lm) nodes.push(<a key={`${keyBase}-${i}`} href={lm[2]} target="_blank" rel="noopener noreferrer" className="text-indigo-400 hover:underline">{lm[1]}</a>);
            }
            last = m.index + tok.length; i++;
        }
        if (last < line.length) nodes.push(line.slice(last));
        return nodes;
    };

    const lines = text.split('\n');
    const out: React.ReactNode[] = [];
    let key = 0;
    for (let li = 0; li < lines.length; li++) {
        const raw = lines[li];
        const line = raw.trim();
        if (line === '' || line === '---') { continue; }
        if (line.startsWith('### ')) { out.push(<h4 key={key++} className="text-base font-bold text-white mt-3 mb-1">{renderInline(line.slice(4), `h${key}`)}</h4>); continue; }
        if (line.startsWith('## ')) { out.push(<h3 key={key++} className="text-lg font-bold text-white mt-4 mb-1">{renderInline(line.slice(3), `h${key}`)}</h3>); continue; }
        if (line.startsWith('# ')) { out.push(<h2 key={key++} className="text-xl font-bold text-white mt-4 mb-2">{renderInline(line.slice(2), `h${key}`)}</h2>); continue; }
        if (/^[-*]\s+/.test(line)) { out.push(<li key={key++} className="ml-5 list-disc text-neutral-300">{renderInline(line.replace(/^[-*]\s+/, ''), `li${key}`)}</li>); continue; }
        out.push(<p key={key++} className="text-neutral-300 mb-2 leading-relaxed">{renderInline(line, `p${key}`)}</p>);
    }
    return <div className="text-sm">{out}</div>;
};

interface ModResult {
    source: string; id: string; name: string; description: string;
    author: string; downloads: number; follows?: number | null;
    icon_url?: string | null; page_url?: string | null; loaders?: string[];
    game_versions?: string[];
}
interface ModVersion {
    version_id: string; version_number: string; loaders: string[];
    game_versions: string[]; file_size?: number; downloads?: number; channel?: string;
    published_at?: string | null; download_url?: string;
}
/** A mod waiting in the install queue, at the version picked for it. */
interface QueueItem {
    source: string; id: string; name: string; icon_url?: string | null; version_id: string; version_number: string;
}
interface PlanItem {
    source: string; mod_id: string; mod_name: string; version_number: string;
    status: 'install' | 'satisfied' | 'conflict' | 'manual';
    kind: 'primary' | 'dependency'; installed_version?: string | null; reason?: string | null;
}
interface InstallPlan {
    items: PlanItem[];
    summary: {
        install: number; satisfied: number; conflicts: number; manual: number;
        dependency_installs: number; needs_confirmation: boolean;
    };
}
interface Repo { key: string; label: string; available: boolean; }

interface InstalledMod {
    id: number;
    source: string;
    source_id: string;
    mod_name: string;
    icon_url: string | null;
    page_url: string | null;
    installed_version: string | null;
    target_filename: string | null;
    has_update: boolean;
    disabled?: boolean;
    available_version: string | null;
    from_modpack?: boolean;
}

interface ReconIdentified {
    confidence: 'exact' | 'fuzzy'; source: string; mod_id: string; mod_name: string;
    icon_url?: string | null; page_url?: string | null;
    version_id?: string | null; version_number?: string | null;
}
interface ReconUntracked { filename: string; size: number; identified: ReconIdentified | null; }
interface ReconMissing { id: number; mod_name: string; installed_version?: string | null; target_filename: string; }

interface DetailsData {
    mod: {
        source: string; id: string;
        name: string; description: string; author: string; downloads: number;
        icon_url?: string | null; page_url?: string | null;
        loaders?: string[]; game_versions?: string[];
        body?: string | null; categories?: string[]; follows?: number | null;
        updated_at?: string | null; published_at?: string | null;
    };
    versions: ModVersion[];
}

const LOADERS = ['', 'forge', 'neoforge', 'fabric', 'quilt', 'paper', 'waterfall', 'velocity'];
const SIZES = [12, 24, 48];

export default () => {
    const uuid = ServerContext.useStoreState(state => state.server.data?.uuid);
    const [tab, setTab] = useState('browse');

    const [repos, setRepos] = useState<Repo[]>([]);
    const [provider, setProvider] = useState('modrinth');
    const [sortBy, setSortBy] = useState('relevance');
    const [page, setPage] = useState(0);
    const [hasMore, setHasMore] = useState(false);
    // provider offset to use for each UI page; page N+1's offset is the
    // next_offset the backend returns for page N (cursor pagination, needed
    // because "compatible only" over-fetches and pages don't align to page*size).
    const offsetsRef = useRef<number[]>([0]);
    // The server's OWN sourced version/loader (env vars or files via Wings) —
    // distinct from the filter-bar selection, used for compatibility badges.
    const [serverVersion, setServerVersion] = useState<string | null>(null);
    const [serverLoader, setServerLoader] = useState<string | null>(null);
    const [size, setSize] = useState(48);
    const [loader, setLoader] = useState('');
    const [gameVersion, setGameVersion] = useState('');
    const [gameVersionList, setGameVersionList] = useState<string[]>([]);
    const [compatibleOnly, setCompatibleOnly] = useState(false);
    const [query, setQuery] = useState('');

    const [results, setResults] = useState<ModResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [searchError, setSearchError] = useState<string | null>(null);

    const [selected, setSelected] = useState<ModResult | null>(null);
    // Install queue: several mods, one backup, one batch.
    const [queue, setQueue] = useState<QueueItem[]>([]);
    const queueLoaded = useRef<string | null>(null);
    const [queueState, setQueueState] = useState<'idle' | 'working' | 'done' | 'error'>('idle');
    const [queueMessage, setQueueMessage] = useState('');
    const [versions, setVersions] = useState<ModVersion[]>([]);
    const [chosenVersion, setChosenVersion] = useState('');
    const [loadingVersions, setLoadingVersions] = useState(false);
    const [plan, setPlan] = useState<InstallPlan | null>(null);
    const [planning, setPlanning] = useState(false);
    const [installing, setInstalling] = useState(false);
    const [installState, setInstallState] = useState('idle');
    const [installMessage, setInstallMessage] = useState('');

    // Manage tab
    const [installed, setInstalled] = useState<InstalledMod[]>([]);
    const [loadingInstalled, setLoadingInstalled] = useState(false);
    const [manageError, setManageError] = useState<string | null>(null);
    const [preChangeStatus, setPreChangeStatus] = useState<string | null>(null);
    const [preChangeMode, setPreChangeMode] = useState<string>('ask');
    const [manageSearch, setManageSearch] = useState('');
    const [rowBusy, setRowBusy] = useState<Record<number, string>>({}); // id -> 'update'|'remove'
    const [highlightId, setHighlightId] = useState<number | null>(null);

    // Removal modal
    const [removeTarget, setRemoveTarget] = useState<InstalledMod | null>(null);
    const [removeDependents, setRemoveDependents] = useState<{ id: number; mod_name: string }[]>([]);
    const [removeOrphans, setRemoveOrphans] = useState<{ id: number; mod_name: string; installed_version?: string | null }[]>([]);
    const [checkedOrphans, setCheckedOrphans] = useState<Record<number, boolean>>({});
    const [removeBusy, setRemoveBusy] = useState(false);
    const [removeError, setRemoveError] = useState<string | null>(null);

    // Reconcile modal
    const [reconOpen, setReconOpen] = useState(false);
    const [reconLoading, setReconLoading] = useState(false);
    const [reconError, setReconError] = useState<string | null>(null);
    const [reconUntracked, setReconUntracked] = useState<ReconUntracked[]>([]);
    const [reconMissing, setReconMissing] = useState<ReconMissing[]>([]);
    const [reconBusy, setReconBusy] = useState<Record<string, boolean>>({});

    // Quick lookup of installed mods by "source:source_id" for the Browse badge.
    const installedKey = (source: string, id: string) => `${source}:${id}`;
    const installedLookup: Record<string, InstalledMod> = {};
    installed.forEach(m => { installedLookup[installedKey(m.source, m.source_id)] = m; });

    const goToManage = (mod: InstalledMod) => {
        setTab('manage');
        setHighlightId(mod.id);
        setManageSearch('');
        // clear the highlight after a moment
        setTimeout(() => setHighlightId(null), 2500);
    };

    // Details modal
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [detailsTab, setDetailsTab] = useState<'description' | 'versions'>('description');
    const [details, setDetails] = useState<DetailsData | null>(null);
    const [detailsLoading, setDetailsLoading] = useState(false);
    const [detailsError, setDetailsError] = useState<string | null>(null);

    const base = `/extensions/serversuite/${uuid}`;

    useEffect(() => {
        if (!uuid) return;
        http.get(`${base}/repositories`)
            .then(res => setRepos(res.data.data || []))
            .catch(() => setRepos([
                { key: 'modrinth', label: 'Modrinth', available: true },
                { key: 'curseforge', label: 'CurseForge', available: false },
                { key: 'hangar', label: 'Hangar', available: false },
            ]));

        http.get(`${base}/game-versions`)
            .then(res => {
                const d = res.data.data || {};
                setGameVersionList(d.versions || []);
                if (d.detected) { setGameVersion(d.detected); setServerVersion(d.detected); }
                if (d.loader) { setLoader(d.loader); setServerLoader(d.loader); }
            })
            .catch(() => setGameVersionList([]));
    }, [uuid]);

    const runSearch = useCallback(async (q: string) => {
        if (!uuid) return;
        setLoading(true);
        setSearchError(null);
        const off = offsetsRef.current[page] ?? page * size;
        try {
            const res = await http.post(`${base}/search`, {
                query: q, source: provider, limit: size, sort: sortBy, offset: off,
                loader: loader || undefined, version: gameVersion || undefined,
                compatible_only: compatibleOnly || undefined,
            });
            setResults(res.data.data || []);
            offsetsRef.current[page + 1] = res.data.next_offset ?? (off + size);
            setHasMore(!!res.data.has_more);
        } catch (e: any) {
            setSearchError(e?.response?.data?.message || e.message || 'Search failed');
            setResults([]);
            setHasMore(false);
        } finally {
            setLoading(false);
        }
    }, [uuid, provider, size, loader, gameVersion, sortBy, page, compatibleOnly]);

    // The debounce must stay stable across renders, but it must call the
    // LATEST runSearch — wrapping runSearch directly in useRef(debounce(...))
    // freezes the first render's closure (provider='modrinth', loader=''),
    // which made every search ignore the filter bar. Route through a ref that
    // is updated to the current closure on every render.
    const runSearchRef = useRef(runSearch);
    useEffect(() => { runSearchRef.current = runSearch; }, [runSearch]);
    const debouncedSearch = useRef(debounce((q: string) => runSearchRef.current(q), 400)).current;
    useEffect(() => { offsetsRef.current = [0]; setHasMore(false); setPage(0); }, [query, provider, size, loader, gameVersion, sortBy, compatibleOnly]);
    useEffect(() => { debouncedSearch(query); }, [query, provider, size, loader, gameVersion, sortBy, page, compatibleOnly]);
    useEffect(() => { if (uuid) runSearchRef.current(''); }, [uuid]);

    const openInstall = async (mod: ModResult) => {
        setSelected(mod); setVersions([]); setChosenVersion(''); setPlan(null);
        setInstallState('idle'); setLoadingVersions(true);
        try {
            const res = await http.post(`${base}/versions`, { source: mod.source, mod_id: mod.id });
            const vs: ModVersion[] = res.data.data || [];
            setVersions(vs);
            if (vs.length > 0) {
                // Relate to the correct release: default to the newest one that
                // fits this server's loader + version, not just the newest overall.
                const pick = vs.find(v => versionCompatible(v)) || vs[0];
                setChosenVersion(pick.version_id);
                planFor(mod, pick.version_id);
            }
        } catch (e: any) {
            setInstallState('error');
            setInstallMessage(e?.response?.data?.message || 'Could not load versions');
        } finally { setLoadingVersions(false); }
    };

    const planFor = async (mod: ModResult, versionId: string) => {
        setPlanning(true); setPlan(null);
        try {
            const res = await http.post(`${base}/plan`, { source: mod.source, mod_id: mod.id, version_id: versionId });
            setPlan(res.data.data);
        } catch (e: any) {
            setInstallState('error');
            setInstallMessage(e?.response?.data?.message || 'Could not resolve dependencies');
        } finally { setPlanning(false); }
    };

    const onVersionChange = (versionId: string) => {
        setChosenVersion(versionId);
        if (selected) planFor(selected, versionId);
    };

    const doInstall = async () => {
        if (!selected || !chosenVersion) return;
        setInstalling(true); setInstallState('working'); setInstallMessage('Installing…');
        try {
            const clearance = await getClearance(base, `install ${selected.name || 'this mod'}`, (t) => t && setInstallMessage(t));
            setInstallMessage('Installing…');
            const res = await http.post(`${base}/install`, {
                source: selected.source, mod_id: selected.id, version_id: chosenVersion, clearance,
            });
            const result = res.data.data;
            if (result.mode === 'queued' && result.batch_id) {
                await pollBatch(result.batch_id, (s, m) => { setInstallState(s); setInstallMessage(m); });
            } else {
                setInstallState('done'); setInstallMessage('Installed successfully.');
                loadInstalled();
            }
        } catch (e: any) {
            setInstallState('error');
            setInstallMessage(e?.response?.data?.message || e.message || 'Install failed');
        } finally { setInstalling(false); }
    };

    type Report = (state: 'working' | 'done' | 'error', message: string) => void;
    const pollBatch = async (batch: string, report: Report, tries = 60) => {
        for (let i = 0; i < tries; i++) {
            await new Promise(r => setTimeout(r, 1500));
            try {
                const res = await http.get(`${base}/tasks/${batch}`);
                const meta = res.data.meta;
                report('working', `Installing… ${meta.succeeded}/${meta.total} complete`);
                if (meta.complete) {
                    if (meta.failed > 0) report('error', `${meta.failed} of ${meta.total} failed.`);
                    else { report('done', `Installed ${meta.succeeded} mod(s) successfully.`); loadInstalled(); }
                    return;
                }
            } catch { /* tolerate transient errors */ }
        }
        report('error', 'Timed out waiting for install to finish.');
    };

    // The queue survives leaving the tab: kept in this browser, per server.
    const queueKey = uuid ? `serversuite.queue.${uuid}` : null;
    useEffect(() => {
        if (!queueKey) return;
        try {
            const saved = JSON.parse(window.localStorage.getItem(queueKey) || '[]');
            setQueue(Array.isArray(saved) ? saved : []);
        } catch { setQueue([]); }
        queueLoaded.current = queueKey;
    }, [queueKey]);
    useEffect(() => {
        if (!queueKey || queueLoaded.current !== queueKey) return;   // not before it's been read
        try {
            if (queue.length > 0) window.localStorage.setItem(queueKey, JSON.stringify(queue));
            else window.localStorage.removeItem(queueKey);
        } catch { /* storage blocked: the queue just won't survive a reload */ }
    }, [queue, queueKey]);
    const queuedFor = (source: string, id: string) => queue.find((q) => q.source === source && q.id === id);

    /** Put the open mod in the queue at the chosen version (replacing it if it's already there). */
    const addToQueue = () => {
        const v = versions.find((x) => x.version_id === chosenVersion);
        if (!selected || !v) return;
        const item: QueueItem = { source: selected.source, id: selected.id, name: selected.name, icon_url: selected.icon_url, version_id: v.version_id, version_number: v.version_number };
        setQueue((q) => [...q.filter((x) => !(x.source === item.source && x.id === item.id)), item]);
        setQueueState('idle');
        closeModal();
    };

    const installQueue = async () => {
        if (queue.length === 0) return;
        const n = queue.length;
        setQueueState('working'); setQueueMessage('Planning…');
        try {
            const clearance = await getClearance(base, `install ${n} mod${n === 1 ? '' : 's'}`, (t) => t && setQueueMessage(t));
            setQueueMessage('Installing…');
            const res = await http.post(`${base}/install-batch`, {
                items: queue.map((q) => ({ source: q.source, mod_id: q.id, version_id: q.version_id })), clearance,
            });
            const result = res.data.data;
            if (result.mode === 'queued' && result.batch_id) {
                await pollBatch(result.batch_id, (s, m) => { setQueueState(s); setQueueMessage(m); if (s === 'done') setQueue([]); }, 60 + 40 * n);
            } else {
                setQueueState('done'); setQueueMessage('Nothing to install: everything in the queue is already there.'); setQueue([]);
            }
        } catch (e: any) {
            setQueueState('error');
            setQueueMessage(e?.response?.data?.error || e?.response?.data?.message || e.message || 'Install failed');
        }
    };

    const closeModal = () => { setSelected(null); setPlan(null); setInstallState('idle'); };
    const fmt = (n: number) => n >= 1000 ? `${(n / 1000).toFixed(1)}k` : `${n}`;

    const relTime = (iso?: string | null) => {
        if (!iso) return '';
        const d = new Date(iso).getTime();
        if (isNaN(d)) return '';
        const days = Math.floor((Date.now() - d) / 86400000);
        if (days < 1) return 'today';
        if (days < 30) return `${days} day${days === 1 ? '' : 's'} ago`;
        const months = Math.floor(days / 30);
        if (months < 12) return `${months} month${months === 1 ? '' : 's'} ago`;
        const years = Math.floor(months / 12);
        return `${years} year${years === 1 ? '' : 's'} ago`;
    };

    // Does a published game-version entry cover the server's version?
    // Handles exact ("1.21.4"), wildcard ("1.21.x"), and range ("1.19-1.21.4")
    // entries — Hangar in particular publishes ranges.
    const versionCovers = (entry: string, v: string): boolean => {
        if (entry === v) return true;
        const num = (x: string) => x.split('.').map(n => parseInt(n, 10) || 0);
        const cmp = (a: number[], b: number[]) => {
            for (let i = 0; i < 3; i++) { const d = (a[i] || 0) - (b[i] || 0); if (d) return d; }
            return 0;
        };
        if (entry.endsWith('.x')) return v === entry.slice(0, -2) || v.startsWith(entry.slice(0, -1));
        if (entry.includes('-')) {
            const [lo, hi] = entry.split('-', 2);
            return cmp(num(v), num(lo)) >= 0 && cmp(num(v), num(hi)) <= 0;
        }
        return false;
    };

    // The version the compat badges target: the picked version (dropdown) when
    // set, otherwise the server's detected version — so changing the dropdown
    // overrides the determined version everywhere, not just the search filter.
    const effectiveVersion = gameVersion || serverVersion;

    // null = unknown (no badge), true/false = compatible with the effective version.
    const compatWithServer = (mod: ModResult): boolean | null => {
        if (!effectiveVersion || !mod.game_versions || mod.game_versions.length === 0) return null;
        return mod.game_versions.some(e => versionCovers(e, effectiveVersion));
    };

    // Loaders that can run each other's content (approximate cross-compat), so a
    // Paper plugin isn't flagged incompatible on Purpur, etc.
    const loaderFamily = (l: string): string[] => {
        const fam: Record<string, string[]> = {
            paper: ['paper', 'spigot', 'bukkit', 'purpur', 'folia'],
            purpur: ['purpur', 'paper', 'spigot', 'bukkit', 'folia'],
            spigot: ['spigot', 'bukkit', 'paper'],
            bukkit: ['bukkit', 'spigot', 'paper'],
            folia: ['folia', 'paper'],
            fabric: ['fabric'],
            quilt: ['quilt', 'fabric'],
            forge: ['forge'],
            neoforge: ['neoforge', 'forge'],
        };
        return fam[l.toLowerCase()] || [l.toLowerCase()];
    };

    // Does a specific mod release support the server's loader + effective version?
    const versionCompatible = (v: ModVersion): boolean => {
        const gvOk = !effectiveVersion || (v.game_versions || []).some(g => versionCovers(g, effectiveVersion));
        const fam = serverLoader ? loaderFamily(serverLoader) : null;
        const loaderOk = !fam || (v.loaders || []).some(l => fam.includes(l.toLowerCase()));
        return gvOk && loaderOk;
    };

    // Install a specific version straight from the Details > Versions tab.
    const installFromDetails = (versionId: string) => {
        if (!details) return;
        const mod: ModResult = {
            source: details.mod.source, id: details.mod.id, name: details.mod.name,
            description: details.mod.description, author: details.mod.author,
            downloads: details.mod.downloads, icon_url: details.mod.icon_url,
            page_url: details.mod.page_url, loaders: details.mod.loaders,
        };
        closeDetails();
        // openInstall loads versions then plans the first; for a chosen version,
        // open the modal and pre-select it.
        setSelected(mod);
        setVersions(details.versions);
        setChosenVersion(versionId);
        setPlan(null);
        setInstallState('idle');
        setLoadingVersions(false);
        planFor(mod, versionId);
    };

    // ---- Manage tab logic ----
    const loadInstalled = useCallback(async () => {
        if (!uuid) return;
        setLoadingInstalled(true);
        setManageError(null);
        try {
            const res = await http.get(`${base}/installed`);
            setInstalled(res.data.data || []);
        } catch (e: any) {
            setManageError(e?.response?.data?.message || e.message || 'Could not load installed mods');
            setInstalled([]);
        } finally {
            setLoadingInstalled(false);
        }
    }, [uuid]);

    // load installed mods when entering the Manage tab
    // load installed mods on mount (Browse uses it for the Installed badge)
    // and whenever entering the Manage tab (to refresh).
    useEffect(() => { if (uuid) loadInstalled(); }, [uuid]);
    useEffect(() => { if (tab === 'manage') loadInstalled(); }, [tab, loadInstalled]);
    useEffect(() => {
        if (uuid && tab === 'manage') http.get(`${base}/prechange`).then((res) => setPreChangeMode(res.data.data.mode)).catch(() => undefined);
    }, [uuid, tab]);
    const savePreChangeMode = (mode: string) => {
        setPreChangeMode(mode);
        http.post(`${base}/prechange/mode`, { mode }).catch((e) => setManageError(e?.response?.data?.message || 'Could not save the backup setting.'));
    };

    const updateMod = async (mod: InstalledMod) => {
        setRowBusy(s => ({ ...s, [mod.id]: 'update' }));
        try {
            const clearance = await getClearance(base, `update ${mod.mod_name}`, setPreChangeStatus);
            await http.post(`${base}/mods/${mod.id}/update`, { clearance });
            await loadInstalled();
        } catch (e: any) {
            setManageError(`Update failed for ${mod.mod_name}: ${e?.response?.data?.error || e?.response?.data?.message || e.message}`);
        } finally {
            setRowBusy(s => { const n = { ...s }; delete n[mod.id]; return n; });
        }
    };

    // Open the removal modal (no immediate delete). Fetches dependents first so
    // the modal can show what would break and offer "remove all".
    const openRemove = async (mod: InstalledMod) => {
        setRemoveTarget(mod);
        setRemoveDependents([]);
        setRemoveOrphans([]);
        setCheckedOrphans({});
        setRemoveError(null);
        setRemoveBusy(false);
        // Probe: backend reports dependents (mods needing this) and orphans
        // (dependencies that become unused if this is removed). Never deletes.
        const applyProbe = (data: any) => {
            setRemoveDependents(data?.dependents || []);
            const orphans = data?.orphans || [];
            setRemoveOrphans(orphans);
            // default every orphan checked (the common intent is to clean them up)
            const checked: Record<number, boolean> = {};
            orphans.forEach((o: any) => { checked[o.id] = true; });
            setCheckedOrphans(checked);
        };
        try {
            const res = await http.delete(`${base}/mods/${mod.id}`, { data: { force: false, probe: true } });
            applyProbe(res.data);
        } catch (e: any) {
            if (e?.response?.status === 409) applyProbe(e.response.data);
        }
    };

    // Execute removal: the target plus any checked orphans. force=true bypasses
    // the dependents guard (used when the user chose "remove anyway").
    const executeRemoval = async (force: boolean) => {
        if (!removeTarget) return;
        setRemoveBusy(true);
        setRemoveError(null);

        const orphanIds = removeOrphans.filter(o => checkedOrphans[o.id]).map(o => o.id);

        try {
            const clearance = await getClearance(base, `remove ${removeTarget.mod_name}`, (t) => setRemoveError(t));
            setRemoveError(null);
            // Remove the target first (force if it has dependents the user accepted).
            await http.delete(`${base}/mods/${removeTarget.id}`, { data: { force, clearance } });
            // Then the checked orphans — force each, since their dependent (the
            // target) is going away in the same operation.
            for (const id of orphanIds) {
                await http.delete(`${base}/mods/${id}`, { data: { force: true, clearance } });
            }
            const removedIds = new Set([removeTarget.id, ...orphanIds]);
            setInstalled(list => list.filter(m => !removedIds.has(m.id)));
            setRemoveTarget(null);
        } catch (e: any) {
            if (e?.response?.status === 409 && !force && !e.response.data?.needs_backup) {
                // target still has dependents the user hasn't accepted; surface them
                setRemoveDependents(e.response.data?.dependents || []);
                setRemoveError('This mod is required by others — choose "Remove anyway" to proceed.');
            } else {
                setRemoveError(e?.response?.data?.error || e?.response?.data?.message || e.message || 'Remove failed');
            }
        } finally {
            setRemoveBusy(false);
        }
    };

    const toggleOrphan = (id: number) => setCheckedOrphans(s => ({ ...s, [id]: !s[id] }));

    // ---- Reconcile ----
    const openReconcile = async () => {
        setReconOpen(true);
        setReconLoading(true);
        setReconError(null);
        setReconUntracked([]);
        setReconMissing([]);
        try {
            const res = await http.get(`${base}/reconcile`);
            const d = res.data.data || {};
            setReconUntracked(d.untracked || []);
            setReconMissing(d.missing || []);
        } catch (e: any) {
            setReconError(e?.response?.data?.error || e?.response?.data?.message || e.message || 'Scan failed');
        } finally {
            setReconLoading(false);
        }
    };
    const closeReconcile = () => { setReconOpen(false); setReconError(null); };

    const adoptFile = async (file: ReconUntracked, asManual: boolean) => {
        setReconBusy(s => ({ ...s, [file.filename]: true }));
        try {
            await http.post(`${base}/reconcile/adopt`, asManual || !file.identified ? {
                filename: file.filename, source: 'manual',
            } : {
                filename: file.filename, source: file.identified.source,
                mod_id: file.identified.mod_id, version_id: file.identified.version_id || undefined,
            });
            setReconUntracked(list => list.filter(f => f.filename !== file.filename));
            loadInstalled();
        } catch (e: any) {
            setReconError(`Adopt failed for ${file.filename}: ${e?.response?.data?.message || e.message}`);
        } finally {
            setReconBusy(s => { const n = { ...s }; delete n[file.filename]; return n; });
        }
    };

    const forgetRow = async (row: ReconMissing) => {
        setReconBusy(s => ({ ...s, [`m${row.id}`]: true }));
        try {
            await http.post(`${base}/reconcile/forget`, { id: row.id });
            setReconMissing(list => list.filter(r => r.id !== row.id));
            loadInstalled();
        } catch (e: any) {
            setReconError(`Forget failed for ${row.mod_name}: ${e?.response?.data?.message || e.message}`);
        } finally {
            setReconBusy(s => { const n = { ...s }; delete n[`m${row.id}`]; return n; });
        }
    };

    const fmtBytes = (b: number) => b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`;

    // Bulk update: apply every pending update for the server.
    const [updatingAll, setUpdatingAll] = useState(false);
    const pendingCount = installed.filter(m => m.has_update).length;

    const updateAllMods = async () => {
        if (pendingCount === 0) return;
        setUpdatingAll(true);
        setManageError(null);
        try {
            const clearance = await getClearance(base, `update ${pendingCount} mod${pendingCount === 1 ? '' : 's'}`, setPreChangeStatus);
            const res = await http.post(`${base}/update-all`, { clearance });
            const data = res.data.data;
            await loadInstalled();
            if (data.failed && data.failed.length > 0) {
                setManageError(`${data.updated.length} updated, ${data.failed.length} failed: ` +
                    data.failed.map((f: any) => f.mod_name).join(', '));
            }
        } catch (e: any) {
            setManageError(e?.response?.data?.error || e?.response?.data?.message || e.message || 'Bulk update failed');
        } finally {
            setUpdatingAll(false);
        }
    };

    const closeRemove = () => { setRemoveTarget(null); setRemoveDependents([]); setRemoveError(null); };

    const filteredInstalled = installed.filter(m =>
        m.mod_name.toLowerCase().includes(manageSearch.toLowerCase())
    );

    // ---- Details modal ----
    const openDetails = async (source: string, modId: string) => {
        setDetailsOpen(true);
        setDetailsTab('description');
        setDetails(null);
        setDetailsError(null);
        setDetailsLoading(true);
        try {
            const res = await http.post(`${base}/details`, { source, mod_id: modId, loader, version: gameVersion });
            setDetails(res.data.data);
        } catch (e: any) {
            setDetailsError(e?.response?.data?.message || e.message || 'Could not load details');
        } finally {
            setDetailsLoading(false);
        }
    };
    const closeDetails = () => { setDetailsOpen(false); setDetails(null); setDetailsError(null); setDetailsLoading(false); };

    return (
        <div className="p-6 max-w-7xl mx-auto">
            <div className="flex items-center gap-3 mb-4">
                <FontAwesomeIcon icon={faCube} className="text-2xl text-indigo-400" />
                <h1 className="text-2xl font-bold text-white">Mod Manager</h1>
            </div>

            <div className="flex gap-2 border-b border-neutral-700 mb-6">
                <Tab active={tab === 'browse'} onClick={() => setTab('browse')}>
                    <FontAwesomeIcon icon={faSearch} /> Browse Mods
                </Tab>
                <Tab active={tab === 'manage'} onClick={() => setTab('manage')}>
                    <FontAwesomeIcon icon={faPuzzlePiece} /> Manage Mods
                </Tab>
                <Can action={'file.read-content'}>
                    <Tab active={tab === 'configs'} onClick={() => setTab('configs')}>
                        <FontAwesomeIcon icon={faCogs} /> Configs
                    </Tab>
                </Can>
            </div>

            {tab === 'configs' && uuid && <ConfigsPanel uuid={uuid} />}

            {tab === 'manage' && (
                <>
                    <Panel>
                        <div className="flex flex-wrap gap-4 items-end">
                            <div className="flex flex-col gap-1">
                                <label className="text-xs text-neutral-400 uppercase tracking-wide">Installed</label>
                                <div className="text-neutral-200 text-sm px-3 py-2 rounded-lg" style={{ background: 'rgba(15,23,42,0.6)', border: '1px solid rgba(255,255,255,0.06)' }}>
                                    {installed.length} mod{installed.length === 1 ? '' : 's'}
                                </div>
                            </div>
                            <div className="flex flex-col gap-1 flex-1" style={{ minWidth: '200px' }}>
                                <label className="text-xs text-neutral-400 uppercase tracking-wide">Search</label>
                                <Input placeholder="Search installed mods…" value={manageSearch} onChange={e => setManageSearch(e.target.value)} />
                            </div>
                            <Can action={'mods.install'}>
                                <label className="flex items-center gap-2 text-xs text-neutral-400" title="Take a backup before mods are installed, updated or removed">
                                    Backup before changes
                                    <Field value={preChangeMode} onChange={(e) => savePreChangeMode(e.currentTarget.value)}>
                                        <option value="ask">Ask</option>
                                        <option value="always">Always</option>
                                        <option value="never">Never</option>
                                    </Field>
                                </label>
                            </Can>
                            <GhostButton onClick={loadInstalled} title="Refresh">
                                <FontAwesomeIcon icon={faSync} spin={loadingInstalled} /> Refresh
                            </GhostButton>
                            {pendingCount > 0 && (
                                <Can action={'mods.update'}><PrimaryButton onClick={updateAllMods} disabled={updatingAll} style={{ background: 'rgb(16,185,129)' }}>
                                    <FontAwesomeIcon icon={updatingAll ? faSpinner : faArrowUp} spin={updatingAll} /> Update all ({pendingCount})
                                </PrimaryButton></Can>
                            )}
                            <Can action={'mods.configure'}><GhostButton onClick={openReconcile} title="Compare the mods folder with what's tracked here">
                                <FontAwesomeIcon icon={faPuzzlePiece} /> Reconcile
                            </GhostButton></Can>
                        </div>
                    </Panel>

                    <CrashCard base={base} refreshKey={installed.length} onChanged={loadInstalled} />
                    <CrossplayCard base={base} onChanged={loadInstalled} />
                    {preChangeStatus && (
                        <div className="text-sm rounded-lg p-3 mb-4" style={{ background: 'rgba(99,102,241,0.12)', color: 'rgb(165,180,252)' }}>
                            <FontAwesomeIcon icon={faSpinner} spin /> {preChangeStatus}
                        </div>
                    )}
                    {manageError && (
                        <Panel><p className="text-red-400 text-center py-2"><FontAwesomeIcon icon={faTimesCircle} /> {manageError}</p></Panel>
                    )}

                    {loadingInstalled && (
                        <div className="text-center py-12 text-neutral-400">
                            <FontAwesomeIcon icon={faSpinner} spin className="text-3xl text-indigo-500" />
                        </div>
                    )}

                    {!loadingInstalled && filteredInstalled.length === 0 && (
                        <p className="text-neutral-500 text-center py-12">
                            {installed.length === 0 ? 'No mods installed yet. Use Browse Mods to add some.' : 'No installed mods match your search.'}
                        </p>
                    )}

                    {!loadingInstalled && filteredInstalled.length > 0 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                            {filteredInstalled.map(mod => {
                                const busy = rowBusy[mod.id];
                                const highlighted = highlightId === mod.id;
                                return (
                                    <Card key={mod.id} style={highlighted ? { borderColor: 'rgb(99,102,241)', boxShadow: '0 0 0 2px rgba(99,102,241,0.4)' } : undefined}>
                                        <div className="flex gap-3 mb-3">
                                            {mod.icon_url ? (
                                                <img src={mod.icon_url} alt={mod.mod_name}
                                                    className="w-12 h-12 rounded-lg object-cover flex-shrink-0 bg-neutral-800"
                                                    onError={e => { (e.target as HTMLImageElement).style.display = 'none'; }} />
                                            ) : (
                                                <div className="w-12 h-12 rounded-lg bg-neutral-800 flex items-center justify-center flex-shrink-0">
                                                    <FontAwesomeIcon icon={faCube} className="text-indigo-400" />
                                                </div>
                                            )}
                                            <div className="min-w-0">
                                                <h3 className="text-base font-bold text-white truncate">{mod.mod_name}</h3>
                                                <div className="flex items-center gap-2 mt-1 flex-wrap">
                                                    {mod.installed_version && <Badge>{mod.installed_version}</Badge>}
                                                    {mod.from_modpack && <Badge title="Installed by the modpack; updating the pack updates it">modpack</Badge>}
                                                    {mod.disabled && (
                                                        <span className="text-xs px-2 py-0.5 rounded-md font-medium flex items-center gap-2"
                                                            style={{ background: 'rgba(245,158,11,0.18)', color: 'rgb(251,191,36)' }}>
                                                            disabled
                                                            <Can action={'mods.install'}>
                                                                <button className="underline" onClick={() => http.post(`${base}/mods/${mod.id}/enable`, {}).then(loadInstalled)
                                                                    .catch((e) => setManageError(e?.response?.data?.error || 'Could not enable it.'))}>enable</button>
                                                            </Can>
                                                        </span>
                                                    )}
                                                    {mod.has_update && mod.available_version && (
                                                        <span className="text-xs px-2 py-0.5 rounded-md font-medium flex items-center gap-1"
                                                            style={{ background: 'rgba(16,185,129,0.18)', color: 'rgb(52,211,153)' }}>
                                                            <FontAwesomeIcon icon={faArrowUp} /> {mod.available_version}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 mt-auto">
                                            {mod.source !== 'manual' && (
                                                <Can action={'mods.update'}><GhostButton onClick={() => updateMod(mod)} disabled={!!busy}>
                                                    <FontAwesomeIcon icon={busy === 'update' ? faSpinner : faSync} spin={busy === 'update'} /> Update
                                                </GhostButton></Can>
                                            )}
                                            {mod.source !== 'manual' && (
                                            <GhostButton onClick={() => openDetails(mod.source, mod.source_id)} disabled={!!busy}>
                                                <FontAwesomeIcon icon={faInfoCircle} />
                                            </GhostButton>
                                            )}
                                            <Can action={'mods.remove'}><PrimaryButton onClick={() => openRemove(mod)} disabled={!!busy} style={{ marginLeft: 'auto', background: 'rgb(139,92,246)' }}>
                                                <FontAwesomeIcon icon={faTrash} /> Remove
                                            </PrimaryButton></Can>
                                        </div>
                                    </Card>
                                );
                            })}
                        </div>
                    )}
                </>
            )}

            {tab === 'browse' && (
                <>
                    {(queue.length > 0 || queueState !== 'idle') && (
                        <Panel>
                            <div className="flex flex-wrap items-center gap-3 mb-3">
                                <h3 className="text-base font-bold text-white">Install queue</h3>
                                <span className="text-xs text-neutral-400">{queue.length} mod{queue.length === 1 ? '' : 's'} · one backup for all of them</span>
                                <div className="flex gap-2" style={{ marginLeft: 'auto' }}>
                                    {queue.length > 0 && queueState !== 'working' && <GhostButton onClick={() => { setQueue([]); setQueueState('idle'); }}>Clear</GhostButton>}
                                    {queue.length > 0 && (
                                        <Can action={'mods.install'}>
                                            <PrimaryButton onClick={installQueue} disabled={queueState === 'working'}>
                                                <FontAwesomeIcon icon={queueState === 'working' ? faSpinner : faDownload} spin={queueState === 'working'} /> Install {queue.length} mod{queue.length === 1 ? '' : 's'}
                                            </PrimaryButton>
                                        </Can>
                                    )}
                                </div>
                            </div>
                            <div className="flex flex-col gap-2">
                                {queue.map((q) => (
                                    <div key={`${q.source}:${q.id}`} className="flex items-center gap-3 rounded-lg px-3 py-2" style={{ background: 'rgba(15,23,42,0.4)', border: '1px solid rgba(255,255,255,0.05)' }}>
                                        {q.icon_url ? <img src={q.icon_url} alt="" className="w-8 h-8 rounded-md object-cover" /> : <FontAwesomeIcon icon={faCube} className="text-indigo-400" />}
                                        <span className="text-sm text-white truncate">{q.name}</span>
                                        <Badge>{q.version_number}</Badge>
                                        {queueState !== 'working' && (
                                            <button className="text-xs text-neutral-400 hover:text-red-400" style={{ marginLeft: 'auto' }}
                                                onClick={() => setQueue((all) => all.filter((x) => x !== q))}>Remove</button>
                                        )}
                                    </div>
                                ))}
                            </div>
                            {queueState === 'working' && <p className="text-indigo-300 text-sm mt-3"><FontAwesomeIcon icon={faSpinner} spin /> {queueMessage}</p>}
                            {queueState === 'done' && <p className="text-emerald-400 text-sm mt-3"><FontAwesomeIcon icon={faCheckCircle} /> {queueMessage}</p>}
                            {queueState === 'error' && <p className="text-red-400 text-sm mt-3"><FontAwesomeIcon icon={faTimesCircle} /> {queueMessage}</p>}
                        </Panel>
                    )}
                    <Panel>
                        <div className="flex flex-wrap gap-4 items-end">
                            <div className="flex flex-col gap-1">
                                <label className="text-xs text-neutral-400 uppercase tracking-wide">Provider</label>
                                <Field value={provider} onChange={e => {
                                    const v = e.target.value;
                                    setProvider(v);
                                    // Hangar only serves plugin platforms; default to paper
                                    // when the current loader is a mod loader (or unset).
                                    if (v === 'hangar' && !['paper', 'waterfall', 'velocity'].includes(loader)) {
                                        setLoader('paper');
                                    }
                                }}>
                                    {repos.map(r => (
                                        <option key={r.key} value={r.key} disabled={!r.available}>
                                            {r.label}{r.available ? '' : (r.key === 'curseforge' ? ' (needs key)' : ' (soon)')}
                                        </option>
                                    ))}
                                </Field>
                            </div>
                            <div className="flex flex-col gap-1">
                                <label className="text-xs text-neutral-400 uppercase tracking-wide">Size</label>
                                <Field value={size} onChange={e => setSize(Number(e.target.value))}>
                                    {SIZES.map(s => <option key={s} value={s}>{s}</option>)}
                                </Field>
                            </div>
                            <div className="flex flex-col gap-1">
                                <label className="text-xs text-neutral-400 uppercase tracking-wide">Sort By</label>
                                <Field value={sortBy} onChange={e => setSortBy(e.target.value)}>
                                    <option value="relevance">Relevance</option>
                                    <option value="downloads">Downloads</option>
                                    <option value="follows">Likes</option>
                                    <option value="newest">Newest</option>
                                    <option value="updated">Recently updated</option>
                                </Field>
                            </div>
                            <div className="flex flex-col gap-1">
                                <label className="text-xs text-neutral-400 uppercase tracking-wide">Loader</label>
                                <Field value={loader} onChange={e => setLoader(e.target.value)}>
                                    {LOADERS.map(l => <option key={l} value={l}>{l === '' ? 'Auto' : l}</option>)}
                                </Field>
                            </div>
                            <div className="flex flex-col gap-1">
                                <label className="text-xs text-neutral-400 uppercase tracking-wide">Version</label>
                                <Field value={gameVersion} onChange={e => setGameVersion(e.target.value)} style={{ minWidth: '110px' }}>
                                    <option value="">Any</option>
                                    {gameVersionList.map(v => <option key={v} value={v}>{v}</option>)}
                                </Field>
                            </div>
                            <div className="flex flex-col gap-1">
                                <label className="text-xs text-neutral-400 uppercase tracking-wide">&nbsp;</label>
                                <label
                                    className="flex items-center gap-2 text-sm text-neutral-200 cursor-pointer select-none"
                                    style={{ height: '38px' }}
                                    title="Hide mods that don't support this server's version"
                                >
                                    <input
                                        type="checkbox"
                                        checked={compatibleOnly}
                                        onChange={e => setCompatibleOnly(e.target.checked)}
                                    />
                                    Compatible only
                                </label>
                            </div>
                            <div className="flex flex-col gap-1 flex-1" style={{ minWidth: '200px' }}>
                                <label className="text-xs text-neutral-400 uppercase tracking-wide">Search</label>
                                <Input placeholder="Search mods…" value={query} onChange={e => setQuery(e.target.value)} />
                            </div>
                        </div>
                    </Panel>

                    {loading && (
                        <div className="text-center py-12 text-neutral-400">
                            <FontAwesomeIcon icon={faSpinner} spin className="text-3xl text-indigo-500" />
                        </div>
                    )}
                    {searchError && !loading && (
                        <Panel><p className="text-red-400 text-center py-4"><FontAwesomeIcon icon={faTimesCircle} /> {searchError}</p></Panel>
                    )}
                    {!loading && !searchError && results.length === 0 && (
                        <p className="text-neutral-500 text-center py-12">
                            {compatibleOnly
                                ? `No compatible mods for ${effectiveVersion} here. Try Next, a different loader, or untick “Compatible only”.`
                                : 'No mods found. Try a different search or loader.'}
                        </p>
                    )}
                    {!loading && results.length > 0 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                            {results.map(mod => (
                                <Card key={`${mod.source}-${mod.id}`}>
                                    <div className="flex gap-3 mb-3">
                                        <img src={mod.icon_url || 'https://via.placeholder.com/56'} alt={mod.name}
                                            className="w-14 h-14 rounded-lg object-cover flex-shrink-0 bg-neutral-800"
                                            onError={e => { (e.target as HTMLImageElement).src = 'https://via.placeholder.com/56'; }} />
                                        <div className="min-w-0">
                                            <h3 className="text-base font-bold text-white truncate">{mod.name}</h3>
                                            <div className="flex items-center gap-3 text-xs text-neutral-400 mt-1">
                                                <span><FontAwesomeIcon icon={faDownload} /> {fmt(mod.downloads)}</span>
                                                {mod.follows != null && <span><FontAwesomeIcon icon={faHeart} /> {fmt(mod.follows)}</span>}
                                                <span className="truncate">by {mod.author}</span>
                                                {(() => {
                                                    const ok = compatWithServer(mod);
                                                    if (ok === null) return null;
                                                    return ok ? (
                                                        <span className="text-xs px-2 py-0.5 rounded-md font-medium flex items-center gap-1 flex-shrink-0"
                                                            style={{ background: 'rgba(16,185,129,0.18)', color: 'rgb(52,211,153)' }}>
                                                            <FontAwesomeIcon icon={faCheckCircle} /> {effectiveVersion}
                                                        </span>
                                                    ) : (
                                                        <span className="text-xs px-2 py-0.5 rounded-md font-medium flex items-center gap-1 flex-shrink-0"
                                                            style={{ background: 'rgba(245,158,11,0.15)', color: 'rgb(251,191,36)' }}
                                                            title={`No published support for ${effectiveVersion}`}>
                                                            <FontAwesomeIcon icon={faExclamationTriangle} /> Not for {effectiveVersion}
                                                        </span>
                                                    );
                                                })()}
                                            </div>
                                        </div>
                                    </div>
                                    <p className="text-neutral-300 text-sm mb-4 line-clamp-3 flex-1">{mod.description}</p>
                                    <div className="flex items-center gap-2">
                                        {mod.page_url && (
                                            <GhostButton onClick={() => window.open(mod.page_url as string, '_blank')} title="Open on site">
                                                <FontAwesomeIcon icon={faExternalLinkAlt} />
                                            </GhostButton>
                                        )}
                                        <GhostButton onClick={() => openDetails(mod.source, mod.id)}>
                                            <FontAwesomeIcon icon={faInfoCircle} /> Details
                                        </GhostButton>
                                        {(() => {
                                            const inst = installedLookup[installedKey(mod.source, mod.id)];
                                            if (inst) {
                                                return (
                                                    <div className="flex items-center gap-2" style={{ marginLeft: 'auto' }}>
                                                        <span className="text-xs px-2 py-1 rounded-md font-medium flex items-center gap-1"
                                                            style={{ background: 'rgba(16,185,129,0.18)', color: 'rgb(52,211,153)' }}>
                                                            <FontAwesomeIcon icon={faCheckCircle} /> Installed{inst.installed_version ? ` · ${inst.installed_version}` : ''}
                                                        </span>
                                                        <GhostButton onClick={() => goToManage(inst)}>
                                                            <FontAwesomeIcon icon={faPuzzlePiece} /> Manage
                                                        </GhostButton>
                                                    </div>
                                                );
                                            }
                                            const q = queuedFor(mod.source, mod.id);
                                            if (q) {
                                                return (
                                                    <GhostButton onClick={() => openInstall(mod)} style={{ marginLeft: 'auto', color: 'rgb(52,211,153)', borderColor: 'rgba(16,185,129,0.4)' }}
                                                        title="In the install queue; click to change the version or install it now">
                                                        <FontAwesomeIcon icon={faCheckCircle} /> Queued · {q.version_number}
                                                    </GhostButton>
                                                );
                                            }
                                            return (
                                                <Can action={'mods.install'}><PrimaryButton onClick={() => openInstall(mod)} style={{ marginLeft: 'auto' }}>
                                                    <FontAwesomeIcon icon={faDownload} /> Install
                                                </PrimaryButton></Can>
                                            );
                                        })()}
                                    </div>
                                </Card>
                            ))}
                        </div>
                    )}

                    {!loading && (results.length > 0 || page > 0) && (
                        <div className="flex items-center justify-center gap-4 mt-6">
                            <GhostButton onClick={() => setPage(p => Math.max(0, p - 1))} disabled={page === 0}>
                                ← Previous
                            </GhostButton>
                            <span className="text-neutral-400 text-sm">Page {page + 1}</span>
                            <GhostButton onClick={() => setPage(p => p + 1)} disabled={!hasMore}>
                                Next →
                            </GhostButton>
                        </div>
                    )}
                </>
            )}

            {reconOpen && (
                <div className="fixed inset-0 bg-black/70 backdrop-blur-sm flex items-start justify-center p-4 z-50 overflow-y-auto">
                    <Panel className="max-w-2xl w-full" style={{ background: 'rgba(17,24,39,0.98)', marginBottom: 0, marginTop: '2rem' }}>
                        <div className="flex justify-between items-start mb-1">
                            <h2 className="text-xl font-bold text-white flex items-center gap-2">
                                <FontAwesomeIcon icon={faPuzzlePiece} className="text-indigo-400" /> Reconcile
                            </h2>
                            <button onClick={closeReconcile} className="text-neutral-400 hover:text-white">
                                <FontAwesomeIcon icon={faTimes} />
                            </button>
                        </div>
                        <p className="text-neutral-400 text-sm mb-4">Differences between the mods folder and what's tracked here.</p>

                        {reconLoading && (
                            <div className="py-10 text-center"><FontAwesomeIcon icon={faSpinner} spin className="text-2xl text-indigo-500" /></div>
                        )}
                        {reconError && <p className="text-red-400 text-sm mb-3"><FontAwesomeIcon icon={faTimesCircle} /> {reconError}</p>}

                        {!reconLoading && reconUntracked.length === 0 && reconMissing.length === 0 && !reconError && (
                            <p className="text-neutral-400 text-center py-8">
                                <FontAwesomeIcon icon={faCheckCircle} className="text-emerald-400" /> Everything on disk is tracked, and nothing tracked is missing.
                            </p>
                        )}

                        {!reconLoading && reconUntracked.length > 0 && (
                            <div className="mb-5">
                                <p className="text-sm font-bold text-white mb-2">Untracked files ({reconUntracked.length})</p>
                                <div className="space-y-2">
                                    {reconUntracked.map(f => (
                                        <div key={f.filename} className="rounded-lg p-3" style={{ background: 'rgba(30,41,59,0.4)', border: '1px solid rgba(255,255,255,0.05)' }}>
                                            <div className="flex items-center justify-between gap-3 flex-wrap">
                                                <div className="min-w-0">
                                                    <p className="text-neutral-100 text-sm font-medium truncate">{f.filename} <span className="text-neutral-500 text-xs">({fmtBytes(f.size)})</span></p>
                                                    {f.identified ? (
                                                        <p className="text-xs mt-1 flex items-center gap-2">
                                                            {f.identified.icon_url && <img src={f.identified.icon_url} className="w-4 h-4 rounded" alt="" />}
                                                            <span className="text-neutral-300">{f.identified.mod_name}{f.identified.version_number ? ` · ${f.identified.version_number}` : ''}</span>
                                                            <span className="px-1.5 py-0.5 rounded text-xs" style={f.identified.confidence === 'exact'
                                                                ? { background: 'rgba(16,185,129,0.18)', color: 'rgb(52,211,153)' }
                                                                : { background: 'rgba(245,158,11,0.15)', color: 'rgb(251,191,36)' }}>
                                                                {f.identified.confidence === 'exact' ? 'exact match' : 'best guess'}
                                                            </span>
                                                        </p>
                                                    ) : (
                                                        <p className="text-xs text-neutral-500 mt-1">Couldn't identify this file.</p>
                                                    )}
                                                </div>
                                                <div className="flex gap-2 flex-shrink-0">
                                                    {f.identified && (
                                                        <PrimaryButton onClick={() => adoptFile(f, false)} disabled={!!reconBusy[f.filename]}>
                                                            <FontAwesomeIcon icon={reconBusy[f.filename] ? faSpinner : faCheckCircle} spin={!!reconBusy[f.filename]} /> Adopt
                                                        </PrimaryButton>
                                                    )}
                                                    <GhostButton onClick={() => adoptFile(f, true)} disabled={!!reconBusy[f.filename]} title="Track the file without linking it to a repository (no updates)">
                                                        Track as-is
                                                    </GhostButton>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {!reconLoading && reconMissing.length > 0 && (
                            <div>
                                <p className="text-sm font-bold text-white mb-2">Missing from disk ({reconMissing.length})</p>
                                <div className="space-y-2">
                                    {reconMissing.map(r => (
                                        <div key={r.id} className="rounded-lg p-3 flex items-center justify-between gap-3" style={{ background: 'rgba(30,41,59,0.4)', border: '1px solid rgba(245,158,11,0.25)' }}>
                                            <div className="min-w-0">
                                                <p className="text-neutral-100 text-sm font-medium truncate">{r.mod_name}{r.installed_version ? ` · ${r.installed_version}` : ''}</p>
                                                <p className="text-xs text-neutral-500 truncate">{r.target_filename} is tracked but not on the server.</p>
                                            </div>
                                            <GhostButton onClick={() => forgetRow(r)} disabled={!!reconBusy[`m${r.id}`]} title="Remove the stale entry from tracking">
                                                <FontAwesomeIcon icon={reconBusy[`m${r.id}`] ? faSpinner : faTrash} spin={!!reconBusy[`m${r.id}`]} /> Forget
                                            </GhostButton>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </Panel>
                </div>
            )}

            {removeTarget && (
                <div className="fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 z-50 overflow-y-auto">
                    <Panel className="max-w-lg w-full" style={{ background: 'rgba(17,24,39,0.98)', marginBottom: 0 }}>
                        <div className="flex justify-between items-start mb-4">
                            <h2 className="text-xl font-bold text-white flex items-center gap-2">
                                <FontAwesomeIcon icon={faTrash} className="text-violet-400" /> Remove Mod
                            </h2>
                            <button onClick={closeRemove} className="text-neutral-400 hover:text-white">
                                <FontAwesomeIcon icon={faTimes} />
                            </button>
                        </div>

                        <p className="text-neutral-200 mb-1">
                            Remove <span className="font-semibold">{removeTarget.mod_name}</span>
                            {removeTarget.installed_version ? <span className="text-neutral-400"> ({removeTarget.installed_version})</span> : null}?
                        </p>
                        <p className="text-neutral-400 text-sm mb-4">This deletes the file from the server.</p>

                        {removeDependents.length > 0 && (
                            <div className="rounded-lg p-3 mb-4" style={{ background: 'rgba(245,158,11,0.1)', border: '1px solid rgba(245,158,11,0.3)' }}>
                                <p className="text-amber-400 text-sm font-medium mb-2 flex items-center gap-2">
                                    <FontAwesomeIcon icon={faExclamationTriangle} /> Other installed mods depend on this:
                                </p>
                                {removeDependents.map(d => (
                                    <div key={d.id} className="text-neutral-300 text-sm ml-2 flex items-center gap-2">
                                        <FontAwesomeIcon icon={faPuzzlePiece} className="text-amber-400 text-xs" /> {d.mod_name}
                                    </div>
                                ))}
                                <p className="text-neutral-400 text-xs mt-2">
                                    Removing {removeTarget.mod_name} may break these. Choose "Remove anyway" to proceed regardless.
                                </p>
                            </div>
                        )}

                        {removeOrphans.length > 0 && (
                            <div className="rounded-lg p-3 mb-4" style={{ background: 'rgba(99,102,241,0.08)', border: '1px solid rgba(99,102,241,0.25)' }}>
                                <p className="text-indigo-300 text-sm font-medium mb-2 flex items-center gap-2">
                                    <FontAwesomeIcon icon={faPuzzlePiece} /> These dependencies would no longer be needed:
                                </p>
                                {removeOrphans.map(o => (
                                    <label key={o.id} className="flex items-center gap-2 text-sm text-neutral-200 ml-1 py-1 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={!!checkedOrphans[o.id]}
                                            onChange={() => toggleOrphan(o.id)}
                                            className="accent-indigo-500"
                                        />
                                        {o.mod_name}
                                        {o.installed_version ? <span className="text-neutral-500 text-xs">({o.installed_version})</span> : null}
                                    </label>
                                ))}
                                <p className="text-neutral-400 text-xs mt-2">
                                    Checked dependencies will be removed too. Uncheck any you want to keep.
                                </p>
                            </div>
                        )}

                        {removeError && (
                            <p className="text-red-400 text-sm mb-3"><FontAwesomeIcon icon={faTimesCircle} /> {removeError}</p>
                        )}

                        <div className="flex justify-end gap-3 mt-4 flex-wrap">
                            <GhostButton onClick={closeRemove} disabled={removeBusy}>Cancel</GhostButton>
                            {removeDependents.length > 0 ? (
                                <GhostButton onClick={() => executeRemoval(true)} disabled={removeBusy}>
                                    <FontAwesomeIcon icon={removeBusy ? faSpinner : faTrash} spin={removeBusy} /> Remove anyway
                                </GhostButton>
                            ) : (
                                <PrimaryButton onClick={() => executeRemoval(false)} disabled={removeBusy} style={{ background: 'rgb(139,92,246)' }}>
                                    <FontAwesomeIcon icon={removeBusy ? faSpinner : faTrash} spin={removeBusy} />
                                    {(() => {
                                        const n = removeOrphans.filter(o => checkedOrphans[o.id]).length;
                                        return n > 0 ? ` Remove (${n + 1})` : ' Remove';
                                    })()}
                                </PrimaryButton>
                            )}
                        </div>
                    </Panel>
                </div>
            )}

            {detailsOpen && (
                <div className="fixed inset-0 bg-black/70 backdrop-blur-sm flex items-start justify-center p-4 z-50 overflow-y-auto">
                    <Panel className="max-w-4xl w-full" style={{ background: 'rgba(17,24,39,0.98)', marginBottom: 0, marginTop: '2rem' }}>
                        {/* top bar */}
                        <button onClick={closeDetails} className="text-neutral-300 hover:text-white text-sm flex items-center gap-2 mb-4">
                            <FontAwesomeIcon icon={faTimes} /> Back to Mods
                        </button>

                        {detailsLoading && (
                            <div className="py-12 text-center text-neutral-400">
                                <FontAwesomeIcon icon={faSpinner} spin className="text-2xl text-indigo-500" />
                            </div>
                        )}
                        {detailsError && !detailsLoading && (
                            <p className="text-red-400 text-sm py-4"><FontAwesomeIcon icon={faTimesCircle} /> {detailsError}</p>
                        )}

                        {details && !detailsLoading && (
                            <>
                                {/* header card */}
                                <div className="flex gap-4 items-start mb-4 pb-4 border-b border-neutral-700/50">
                                    {details.mod.icon_url && (
                                        <img src={details.mod.icon_url} alt={details.mod.name}
                                            className="w-16 h-16 rounded-lg object-cover bg-neutral-800 flex-shrink-0"
                                            onError={e => { (e.target as HTMLImageElement).style.display = 'none'; }} />
                                    )}
                                    <div className="flex-1 min-w-0">
                                        <h2 className="text-xl font-bold text-white">{details.mod.name}</h2>
                                        <div className="flex items-center gap-4 text-sm text-neutral-400 mt-1 flex-wrap">
                                            <span><FontAwesomeIcon icon={faDownload} /> {fmt(details.mod.downloads)}</span>
                                            {details.mod.follows != null && <span><FontAwesomeIcon icon={faHeart} /> {fmt(details.mod.follows)}</span>}
                                            <span><FontAwesomeIcon icon={faUser} /> {details.mod.author}</span>
                                        </div>
                                        <p className="text-neutral-300 text-sm mt-2">{details.mod.description}</p>
                                    </div>
                                    <div className="flex gap-2 flex-shrink-0">
                                        {details.mod.page_url && (
                                            <GhostButton onClick={() => window.open(details.mod.page_url as string, '_blank')} title="Open on site">
                                                <FontAwesomeIcon icon={faExternalLinkAlt} />
                                            </GhostButton>
                                        )}
                                        <Can action={'mods.install'}><PrimaryButton onClick={() => details.versions[0] && installFromDetails(details.versions[0].version_id)}>
                                            <FontAwesomeIcon icon={faDownload} /> Install
                                        </PrimaryButton></Can>
                                    </div>
                                </div>

                                {/* inner tabs */}
                                <div className="flex gap-2 border-b border-neutral-700 mb-4">
                                    <Tab active={detailsTab === 'description'} onClick={() => setDetailsTab('description')}>
                                        Description
                                    </Tab>
                                    <Tab active={detailsTab === 'versions'} onClick={() => setDetailsTab('versions')}>
                                        Versions ({details.versions.length})
                                    </Tab>
                                </div>

                                {/* body: content + sidebar */}
                                <div className="flex gap-6 flex-col lg:flex-row">
                                    <div className="flex-1 min-w-0">
                                        {detailsTab === 'description' && (
                                            <div className="leading-relaxed">
                                                {details.mod.body
                                                    ? <MarkdownLite source={details.mod.body} />
                                                    : <p className="text-neutral-400 text-sm">{details.mod.description || 'No description provided.'}</p>}
                                            </div>
                                        )}
                                        {detailsTab === 'versions' && (
                                            <div className="space-y-3">
                                                {details.versions.length === 0 && (
                                                    <p className="text-neutral-500 text-sm">No versions available.</p>
                                                )}
                                                {(() => {
                                                    const inst = installedLookup[installedKey(details.mod.source, details.mod.id)];
                                                    const installedVer = inst?.installed_version;
                                                    return details.versions.map(v => {
                                                        const isInstalled = installedVer && v.version_number === installedVer;
                                                        return (
                                                            <div key={v.version_id} className="rounded-lg p-4"
                                                                style={{ background: 'rgba(30,41,59,0.4)', border: isInstalled ? '1px solid rgb(16,185,129)' : '1px solid rgba(255,255,255,0.05)' }}>
                                                                <div className="flex justify-between items-start gap-3">
                                                                    <div className="min-w-0">
                                                                        <p className="font-semibold text-neutral-100 flex items-center gap-2">
                                                                            {v.version_number}
                                                                            {isInstalled && (
                                                                                <span className="text-xs px-2 py-0.5 rounded-md font-medium flex items-center gap-1"
                                                                                    style={{ background: 'rgba(16,185,129,0.18)', color: 'rgb(52,211,153)' }}>
                                                                                    <FontAwesomeIcon icon={faCheckCircle} /> Installed
                                                                                </span>
                                                                            )}
                                                                        </p>
                                                                        <div className="flex items-center gap-3 text-xs text-neutral-400 mt-1 flex-wrap">
                                                                            <span><FontAwesomeIcon icon={faDownload} /> {fmt(v.downloads || 0)}</span>
                                                                            {v.published_at && <span>Published {relTime(v.published_at)}</span>}
                                                                        </div>
                                                                        <div className="flex items-center gap-2 mt-2 flex-wrap">
                                                                            {(v.loaders || []).map(l => <Badge key={l}>{l}</Badge>)}
                                                                        </div>
                                                                        <div className="flex items-center gap-1 mt-1 flex-wrap">
                                                                            {(() => {
                                                                                const gvs = v.game_versions || [];
                                                                                // Current version first so the highlight is never hidden by the slice.
                                                                                const ordered = (effectiveVersion && gvs.includes(effectiveVersion))
                                                                                    ? [effectiveVersion, ...gvs.filter(g => g !== effectiveVersion)]
                                                                                    : gvs;
                                                                                return ordered.slice(0, 8).map(g => {
                                                                                    const isCurrent = g === effectiveVersion;
                                                                                    return (
                                                                                        <span key={g}
                                                                                            className="text-xs px-2 py-0.5 rounded-full font-medium"
                                                                                            title={isCurrent ? 'Your current version' : undefined}
                                                                                            style={isCurrent
                                                                                                ? { background: 'rgba(74,222,128,0.18)', color: 'rgb(134,239,172)', border: '1px solid rgba(74,222,128,0.45)' }
                                                                                                : { background: 'rgba(255,255,255,0.05)', color: 'rgb(148,163,184)', border: '1px solid rgba(255,255,255,0.08)' }}>
                                                                                            {g}
                                                                                        </span>
                                                                                    );
                                                                                });
                                                                            })()}
                                                                        </div>
                                                                    </div>
                                                                    {isInstalled ? (
                                                                        <GhostButton disabled className="flex-shrink-0">
                                                                            <FontAwesomeIcon icon={faCheckCircle} /> Installed
                                                                        </GhostButton>
                                                                    ) : (
                                                                        <Can action={'mods.install'}><PrimaryButton onClick={() => installFromDetails(v.version_id)} className="flex-shrink-0">
                                                                            <FontAwesomeIcon icon={faDownload} /> Install
                                                                        </PrimaryButton></Can>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        );
                                                    });
                                                })()}
                                            </div>
                                        )}
                                    </div>

                                    {/* sidebar */}
                                    <div className="lg:w-64 flex-shrink-0 space-y-4">
                                        {details.mod.categories && details.mod.categories.length > 0 && (
                                            <div>
                                                <p className="text-sm font-bold text-white mb-2">Categories</p>
                                                <div className="flex flex-wrap gap-1">
                                                    {details.mod.categories.map(c => <Badge key={c}>{c}</Badge>)}
                                                </div>
                                            </div>
                                        )}
                                        {details.mod.loaders && details.mod.loaders.length > 0 && (
                                            <div>
                                                <p className="text-sm font-bold text-white mb-2">Server Loaders</p>
                                                <div className="flex flex-wrap gap-1">
                                                    {details.mod.loaders.map(l => <Badge key={l}>{l}</Badge>)}
                                                </div>
                                            </div>
                                        )}
                                        {details.mod.game_versions && details.mod.game_versions.length > 0 && (
                                            <div>
                                                <p className="text-sm font-bold text-white mb-2">Game Versions</p>
                                                <div className="flex flex-wrap gap-1">
                                                    {details.mod.game_versions.map(g => (
                                                        g === effectiveVersion ? (
                                                            <span key={g} className="text-xs px-2 py-0.5 rounded-full font-medium"
                                                                title="Your current version"
                                                                style={{ background: 'rgba(74,222,128,0.18)', color: 'rgb(134,239,172)', border: '1px solid rgba(74,222,128,0.45)' }}>
                                                                {g}
                                                            </span>
                                                        ) : <Badge key={g}>{g}</Badge>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        {(details.mod.updated_at || details.mod.published_at) && (
                                            <div>
                                                <p className="text-sm font-bold text-white mb-2">Details</p>
                                                {details.mod.updated_at && (
                                                    <p className="text-xs text-neutral-400"><FontAwesomeIcon icon={faSync} /> Updated {relTime(details.mod.updated_at)}</p>
                                                )}
                                                {details.mod.published_at && (
                                                    <p className="text-xs text-neutral-400 mt-1">Published {relTime(details.mod.published_at)}</p>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </>
                        )}
                    </Panel>
                </div>
            )}

            {selected && (
                <div className="fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 z-50 overflow-y-auto">
                    <Panel className="max-w-2xl w-full" style={{ background: 'rgba(17,24,39,0.97)', marginBottom: 0 }}>
                        <div className="flex justify-between items-start mb-4">
                            <div className="flex gap-3">
                                <img src={selected.icon_url || 'https://via.placeholder.com/56'} alt={selected.name}
                                    className="w-14 h-14 rounded-lg object-cover bg-neutral-800"
                                    onError={e => { (e.target as HTMLImageElement).src = 'https://via.placeholder.com/56'; }} />
                                <div>
                                    <h2 className="text-xl font-bold text-white">{selected.name}</h2>
                                    <p className="text-xs text-neutral-400 mt-1">
                                        <FontAwesomeIcon icon={faDownload} /> {fmt(selected.downloads)} · by {selected.author}
                                    </p>
                                </div>
                            </div>
                            <button onClick={closeModal} className="text-neutral-400 hover:text-white">
                                <FontAwesomeIcon icon={faTimes} />
                            </button>
                        </div>

                        <p className="text-neutral-300 text-sm mb-4 line-clamp-3">{selected.description}</p>

                        <label className="block text-xs text-neutral-400 uppercase tracking-wide mb-1">Select Version</label>
                        {loadingVersions ? (
                            <div className="py-3 text-neutral-400"><FontAwesomeIcon icon={faSpinner} spin /> Loading versions…</div>
                        ) : versions.length === 0 ? (
                            <p className="text-neutral-500 text-sm py-3">No compatible versions for this loader / game version.</p>
                        ) : (
                            <Field value={chosenVersion} onChange={e => onVersionChange(e.target.value)} style={{ width: '100%' }}>
                                {versions.map(v => (
                                    <option key={v.version_id} value={v.version_id}>
                                        {v.version_number}{v.channel && v.channel !== 'release' ? ` (${v.channel})` : ''}{!versionCompatible(v) ? ' ⚠' : ''}
                                    </option>
                                ))}
                            </Field>
                        )}

                        {!loadingVersions && versions.length > 0 && chosenVersion && (() => {
                            const cv = versions.find(v => v.version_id === chosenVersion);
                            if (!cv) return null;
                            const targetLabel = `${serverLoader ? serverLoader.charAt(0).toUpperCase() + serverLoader.slice(1) : 'your loader'}${effectiveVersion ? ' ' + effectiveVersion : ''}`;
                            return versionCompatible(cv) ? (
                                <p className="text-sm mt-2 flex items-center gap-2" style={{ color: 'rgb(52,211,153)' }}>
                                    <FontAwesomeIcon icon={faCheckCircle} /> Compatible with {targetLabel}
                                </p>
                            ) : (
                                <p className="text-sm mt-2 flex items-center gap-2" style={{ color: 'rgb(251,191,36)' }}>
                                    <FontAwesomeIcon icon={faExclamationTriangle} /> This release doesn’t list support for {targetLabel} — it may not load.
                                </p>
                            );
                        })()}

                        {planning && <p className="text-neutral-400 text-sm mt-4"><FontAwesomeIcon icon={faSpinner} spin /> Resolving dependencies…</p>}

                        {plan && !planning && (
                            <div className="mt-4 space-y-2">
                                {plan.summary.dependency_installs > 0 && (
                                    <div className="text-sm">
                                        <p className="text-neutral-300 mb-1 font-medium">This will also install:</p>
                                        {plan.items.filter(i => i.kind === 'dependency' && i.status === 'install').map(i => (
                                            <div key={i.mod_id} className="flex items-center gap-2 text-neutral-400 ml-2">
                                                <FontAwesomeIcon icon={faPuzzlePiece} className="text-indigo-400 text-xs" />
                                                {i.mod_name} <Badge>{i.version_number}</Badge>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                {plan.items.filter(i => i.status === 'conflict').map(i => (
                                    <p key={i.mod_id} className="text-amber-400 text-sm flex items-center gap-2">
                                        <FontAwesomeIcon icon={faExclamationTriangle} />
                                        {i.mod_name}: already installed at {i.installed_version}, will be replaced.
                                    </p>
                                ))}
                                {plan.items.filter(i => i.status === 'manual').map(i => (
                                    <p key={i.mod_id} className="text-red-400 text-sm flex items-center gap-2">
                                        <FontAwesomeIcon icon={faTimesCircle} />
                                        {i.mod_name}: {i.reason || 'manual install required'}
                                    </p>
                                ))}
                                {plan.summary.satisfied > 0 && (
                                    <p className="text-emerald-400 text-sm flex items-center gap-2">
                                        <FontAwesomeIcon icon={faCheckCircle} />
                                        {plan.summary.satisfied} dependency(ies) already satisfied.
                                    </p>
                                )}
                            </div>
                        )}

                        {installState === 'working' && <p className="text-indigo-300 text-sm mt-4"><FontAwesomeIcon icon={faSpinner} spin /> {installMessage}</p>}
                        {installState === 'done' && <p className="text-emerald-400 text-sm mt-4"><FontAwesomeIcon icon={faCheckCircle} /> {installMessage}</p>}
                        {installState === 'error' && <p className="text-red-400 text-sm mt-4"><FontAwesomeIcon icon={faTimesCircle} /> {installMessage}</p>}

                        <div className="flex justify-end gap-3 mt-6">
                            <GhostButton onClick={closeModal}>{installState === 'done' ? 'Close' : 'Cancel'}</GhostButton>
                            {installState !== 'done' && (
                                (() => {
                                    const q = selected ? queuedFor(selected.source, selected.id) : undefined;
                                    if (q && q.version_id === chosenVersion) {
                                        return (
                                            <GhostButton onClick={() => { setQueue((all) => all.filter((x) => x !== q)); }}
                                                title="Click to take it out of the queue" style={{ color: 'rgb(52,211,153)', borderColor: 'rgba(16,185,129,0.4)' }}>
                                                <FontAwesomeIcon icon={faCheckCircle} /> Queued · {q.version_number}
                                            </GhostButton>
                                        );
                                    }
                                    return (
                                        <GhostButton onClick={addToQueue} disabled={installing || loadingVersions || versions.length === 0 || !chosenVersion}
                                            title="Queue it with others: they install together after one backup">
                                            {q ? `Queue this version instead of ${q.version_number}` : `Add to queue${queue.length > 0 ? ` (${queue.length})` : ''}`}
                                        </GhostButton>
                                    );
                                })()
                            )}
                            {installState !== 'done' && (
                                <PrimaryButton onClick={doInstall} disabled={installing || loadingVersions || versions.length === 0 || !chosenVersion}>
                                    <FontAwesomeIcon icon={installing ? faSpinner : faDownload} spin={installing} /> Install
                                </PrimaryButton>
                            )}
                        </div>
                    </Panel>
                </div>
            )}
            <BackupPromptHost />
        </div>
    );
};
