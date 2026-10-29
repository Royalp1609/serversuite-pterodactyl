import React, { useCallback, useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import Can from '@/components/elements/Can';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArrowDown, faArrowUp, faCube, faSpinner, faSync } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { getClearance } from './prechange';

/*
 * Server Suite — Add-ons tab (#93; Pelican #97): behavior and resource packs
 * on a Bedrock Dedicated Server, in place of the Mods tab. Archives
 * (.mcpack, .mcaddon) are uploaded to /addons in the File Manager, so Wings
 * takes the upload; BedrockAddonService checks each one (paths, symlinks,
 * sizes, manifests) before Wings unpacks it, and edits the world's pack
 * lists. Installs and removals follow "Backup before changes" (prechange.ts).
 */

const Wrapper = styled.div`${tw`p-6 max-w-7xl mx-auto`};`;  // same page margins as the Mods tab
const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
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
    &:disabled { ${tw`opacity-40 cursor-not-allowed`}; }
`;
const Row = styled.div`
    ${tw`flex items-center gap-3 rounded-lg px-3 py-2 mb-2 flex-wrap`};
    background: rgba(15, 23, 42, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.05);
`;
const Pill = styled.span<{ tone: 'on' | 'off' | 'warn' }>`
    ${tw`text-xs rounded px-2 py-px whitespace-nowrap`};
    background: ${(p) => (p.tone === 'on' ? 'rgba(34, 197, 94, 0.14)' : p.tone === 'warn' ? 'rgba(245, 158, 11, 0.14)' : 'rgba(100, 116, 139, 0.18)')};
    color: ${(p) => (p.tone === 'on' ? 'rgb(74, 222, 128)' : p.tone === 'warn' ? 'rgb(251, 191, 36)' : 'rgb(148, 163, 184)')};
`;
const Mono = styled.span`${tw`font-mono text-sm`};`;

type PackType = 'behavior' | 'resource';
interface Pack {
    uuid: string; version: string; name: string; description: string; folder: string | null; ours: boolean;
    enabled: boolean; position: number | null; needs_beta: boolean; missing: string[]; version_differs: boolean;
}
interface State {
    inbox: Array<{ name: string; size: number }> | null;
    world: string | null; world_exists: boolean; texturepack_required: boolean;
    packs: Record<PackType, Pack[]>;
}

const LABELS: Record<PackType, string> = { behavior: 'Behavior packs', resource: 'Resource packs' };
const BETA = 'Its scripts need the world\'s "Beta APIs" experiment: switch it on for the world in game (Settings, Experiments) or in level.dat, or the pack won\'t load.';
const errorOf = (e: any, fallback: string) => e?.response?.data?.error || e?.response?.data?.message || e?.message || fallback;
const size = (b: number) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MiB` : b >= 1024 ? `${Math.round(b / 1024)} KiB` : `${b} B`);

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const id = ServerContext.useStoreState((state) => state.server.data?.id);
    const base = `/extensions/serversuite/${uuid}`;
    const [data, setData] = useState<State | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const reload = useCallback(() => {
        if (!uuid) return;
        http.get(`${base}/addons`)
            .then((res) => { setData(res.data.data); setLoadError(null); })
            .catch((e) => setLoadError(errorOf(e, 'Could not read the add-ons.')));
    }, [uuid]);
    useEffect(reload, [reload]);

    /** Run a change; with a label it asks for a backup clearance first (install, remove). */
    const run = async (key: string, url: string, body: object, label?: string) => {
        setBusy(key);
        setError(null);
        setNotice(null);
        try {
            const clearance = label ? await getClearance(base, label, setNotice) : null;
            const res = await http.post(url, label ? { ...body, clearance } : body);
            const d = res.data.data;
            if (d.installed || d.updated) {
                setNotice([
                    d.installed.length ? `Installed: ${d.installed.join(', ')}.` : '',
                    d.updated.length ? `Updated: ${d.updated.join(', ')}.` : '',
                    d.skipped.length ? `Already up to date: ${d.skipped.join(', ')}.` : '',
                    'It applies when the server restarts.',
                    ...d.notes,
                ].filter(Boolean).join(' '));
            } else if (d.message) {
                setNotice(d.message);
            }
        } catch (e: any) {
            if (e?.message !== 'Cancelled.') setError(errorOf(e, 'Not done.'));
        } finally {
            setBusy(null);
            reload();
        }
    };

    if (loadError && !data) {
        return <Wrapper><Panel><p className="text-sm text-red-400">{loadError}</p></Panel></Wrapper>;
    }
    if (!data) {
        return <Wrapper><Panel><p className="text-sm text-neutral-400"><FontAwesomeIcon icon={faSpinner} spin /> Loading…</p></Panel></Wrapper>;
    }

    return (
        <Wrapper>
            <Panel>
                <div className="flex items-start gap-3 flex-wrap">
                    <FontAwesomeIcon icon={faCube} className="text-2xl text-indigo-400" />
                    <div className="flex-1" style={{ minWidth: '16rem' }}>
                        <h1 className="text-2xl font-bold text-white">Add-ons</h1>
                        <p className="text-xs text-neutral-500">
                            Upload <Mono>.mcpack</Mono> or <Mono>.mcaddon</Mono> files to the <Mono>addons</Mono> folder in
                            the <a className="text-indigo-300 underline" href={`/server/${id}/files#/addons`}>File Manager</a>, then install them here.
                            Each file is checked before it's unpacked, and removed from the folder once installed. Changes apply when the server restarts.
                        </p>
                    </div>
                    <GhostButton onClick={reload}><FontAwesomeIcon icon={faSync} /> Refresh</GhostButton>
                </div>
                {error && <p className="text-sm text-red-400 mt-3">{error}</p>}
                {notice && <p className="text-sm text-green-400 mt-3">{notice}</p>}

                <div className="mt-4">
                    {data.inbox === null ? (
                        <>
                            <p className="text-sm text-neutral-500 mb-2">There is no <Mono>addons</Mono> folder yet.</p>
                            <Can action={'mods.install'}>
                                <PrimaryButton disabled={busy !== null} onClick={() => run('folder', `${base}/addons/folder`, {})}>Create the addons folder</PrimaryButton>
                            </Can>
                        </>
                    ) : data.inbox.length === 0 ? (
                        <p className="text-sm text-neutral-500">No add-on files in <Mono>addons</Mono>.</p>
                    ) : data.inbox.map((f) => (
                        <Row key={f.name}>
                            <span className="flex-1 font-mono text-sm text-white break-all">{f.name}</span>
                            <span className="text-xs text-neutral-500">{size(f.size)}</span>
                            <Can action={'mods.install'}>
                                <PrimaryButton disabled={busy !== null} onClick={() => run(`in-${f.name}`, `${base}/addons/install`, { file: f.name }, `install ${f.name}`)}>
                                    {busy === `in-${f.name}` && <FontAwesomeIcon icon={faSpinner} spin />} Install
                                </PrimaryButton>
                            </Can>
                        </Row>
                    ))}
                </div>
            </Panel>

            {!data.world_exists && (
                <Panel>
                    <p className="text-sm text-neutral-400">
                        The world <Mono>{data.world || ''}</Mono> doesn't exist yet. Start the server once so it creates it; until then packs can be installed but not switched on.
                    </p>
                </Panel>
            )}

            {(['behavior', 'resource'] as PackType[]).map((type) => {
                const rows = data.packs[type] || [];
                const on = rows.filter((r) => r.enabled).length;
                return (
                    <Panel key={type}>
                        <div className="flex items-center gap-3 flex-wrap mb-3">
                            <h2 className="text-lg font-semibold text-white flex-1">{LABELS[type]}</h2>
                            {type === 'resource' && (
                                <Can action={'mods.update'}>
                                    <label className="flex items-center gap-2 text-sm text-neutral-300">
                                        <input type="checkbox" checked={data.texturepack_required} disabled={busy !== null}
                                               onChange={(e) => run('tp', `${base}/addons/texturepack`, { on: e.target.checked })} />
                                        Players must download them to join
                                    </label>
                                </Can>
                            )}
                        </div>
                        {rows.length === 0 && <p className="text-sm text-neutral-500">None installed.</p>}
                        {on > 1 && <p className="text-xs text-neutral-500 mb-2">Switched-on packs in priority order: when two change the same thing, the higher one wins.</p>}
                        {rows.map((p) => (
                            <Row key={p.uuid}>
                                <div className="flex-1" style={{ minWidth: '14rem' }}>
                                    <p className="text-sm text-white font-medium">{p.name} <span className="text-neutral-500 font-normal">{p.version}</span></p>
                                    {p.description && <p className="text-xs text-neutral-500">{p.description}</p>}
                                    <div className="flex gap-1 flex-wrap mt-1">
                                        <Pill tone={p.enabled ? 'on' : 'off'}>{p.enabled ? 'On' : 'Off'}</Pill>
                                        {p.needs_beta && <Pill tone="warn" title={BETA}>Needs Beta APIs</Pill>}
                                        {p.missing.length > 0 && <Pill tone="warn" title={p.missing.join(', ')}>Needs a pack that isn't installed</Pill>}
                                        {p.version_differs && <Pill tone="warn">World lists another version</Pill>}
                                        {!p.ours && <Pill tone="off">Added outside Server Suite</Pill>}
                                    </div>
                                </div>
                                {data.world_exists && (
                                    <Can action={'mods.update'}>
                                        {p.enabled ? (
                                            <>
                                                <GhostButton title="Higher priority" disabled={busy !== null || p.position === 0}
                                                             onClick={() => run(`up-${p.uuid}`, `${base}/addons/move`, { type, uuid: p.uuid, delta: -1 })}>
                                                    <FontAwesomeIcon icon={faArrowUp} />
                                                </GhostButton>
                                                <GhostButton title="Lower priority" disabled={busy !== null || p.position === on - 1}
                                                             onClick={() => run(`dn-${p.uuid}`, `${base}/addons/move`, { type, uuid: p.uuid, delta: 1 })}>
                                                    <FontAwesomeIcon icon={faArrowDown} />
                                                </GhostButton>
                                                <GhostButton disabled={busy !== null} onClick={() => run(`off-${p.uuid}`, `${base}/addons/enable`, { type, uuid: p.uuid, on: false })}>Switch off</GhostButton>
                                            </>
                                        ) : p.ours && (
                                            <GhostButton disabled={busy !== null} onClick={() => run(`on-${p.uuid}`, `${base}/addons/enable`, { type, uuid: p.uuid, on: true })}>Switch on</GhostButton>
                                        )}
                                    </Can>
                                )}
                                {p.ours && (
                                    <Can action={'mods.remove'}>
                                        <GhostButton style={{ color: 'rgb(248, 113, 113)' }} disabled={busy !== null}
                                                     onClick={() => window.confirm(`Remove ${p.name} from the server?`) && run(`rm-${p.uuid}`, `${base}/addons/remove`, { type, uuid: p.uuid }, `remove ${p.name}`)}>
                                            {busy === `rm-${p.uuid}` && <FontAwesomeIcon icon={faSpinner} spin />} Remove
                                        </GhostButton>
                                    </Can>
                                )}
                            </Row>
                        ))}
                    </Panel>
                );
            })}
        </Wrapper>
    );
};
