import React, { useCallback, useEffect, useState } from 'react';
import Can from '@/components/elements/Can';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faUsers, faSpinner, faSync } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — Players tab (#27).
 * The whitelist, operators and bans as lists. While the server runs,
 * changes go in as console commands and apply at once; while it's stopped,
 * the JSON files are edited and the server reads them on start. Names get
 * their UUID from Mojang (or the offline UUID). PlayersService checks every
 * name, IP and reason before it gets near the console.
 */

const Wrapper = styled.div`${tw`my-6`};`;
const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Input = styled.input`
    ${tw`text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none`};
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
    min-width: 12rem;
    &:focus { border-color: rgb(99, 102, 241); }
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
const Tab = styled.button<{ active?: boolean }>`
    ${tw`px-3 py-2 text-sm font-medium`};
    color: ${(p) => (p.active ? 'white' : 'rgb(148,163,184)')};
    border-bottom: 2px solid ${(p) => (p.active ? 'rgb(99,102,241)' : 'transparent')};
`;
const Row = styled.div`
    ${tw`flex items-center gap-3 rounded-lg px-3 py-2 mb-2`};
    background: rgba(15, 23, 42, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.05);
`;

type ListKey = 'whitelist' | 'ops' | 'banned-players' | 'banned-ips';
const TABS: Record<ListKey, string> = { whitelist: 'Whitelist', ops: 'Operators', 'banned-players': 'Banned players', 'banned-ips': 'Banned IPs' };

interface Overview {
    found: boolean; running: boolean; online_mode: boolean; whitelist_on: boolean;
    lists: Record<ListKey, Array<Record<string, any>>>;
    online: { count: number | null; max: number | null; names: string[] } | null;
}

const errorOf = (e: any, fallback: string) => e?.response?.data?.error || e?.response?.data?.message || fallback;

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const base = `/extensions/serversuite/${uuid}/players`;
    const [data, setData] = useState<Overview | null>(null);
    const [list, setList] = useState<ListKey>('whitelist');
    const [who, setWho] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const load = useCallback(() => {
        if (!uuid) return;
        http.get(base).then((res) => setData(res.data.data)).catch((e) => setError(errorOf(e, 'Could not read the player lists.')));
    }, [uuid, base]);

    useEffect(() => { load(); }, [load]);

    const run = (key: string, url: string, body: object, after?: () => void) => {
        setBusy(key);
        setError(null);
        setNotice(null);
        http.post(url, body)
            .then((res) => { setNotice(res.data.data.message); after?.(); setTimeout(load, data?.running ? 600 : 0); })
            .catch((e) => setError(errorOf(e, 'Not done.')))
            .finally(() => setBusy(null));
    };

    const banList = list === 'banned-players' || list === 'banned-ips';
    const rows = data?.lists[list] || [];

    return (
        <Wrapper>
            <Panel>
                <div className="flex items-center gap-3 flex-wrap">
                    <FontAwesomeIcon icon={faUsers} className="text-2xl text-indigo-400" />
                    <div>
                        <h1 className="text-2xl font-bold text-white">
                            {data?.running ? 'Online' : 'Stopped'}
                            {data?.running && data.online?.count != null && <span className="text-neutral-400 text-base font-normal"> · {data.online.count} / {data.online.max} players</span>}
                        </h1>
                        <p className="text-xs text-neutral-500">
                            {data?.running ? 'Changes apply straight away through the console.' : 'Changes are saved to the files and apply when the server starts.'}
                            {data && !data.online_mode && ' Offline mode: players get offline UUIDs.'}
                        </p>
                    </div>
                    <span className="flex items-center gap-2" style={{ marginLeft: 'auto' }}>
                        {data?.found && <span className="text-sm text-neutral-300">Whitelist {data.whitelist_on ? 'on' : 'off'}</span>}
                        {data?.found && (
                            <Can action={'players.manage'}>
                                <GhostButton disabled={busy !== null} onClick={() => run('wl', `${base}/whitelist`, { on: !data.whitelist_on })}>
                                    {data.whitelist_on ? 'Turn off' : 'Turn on'}
                                </GhostButton>
                            </Can>
                        )}
                        <GhostButton onClick={load} disabled={busy !== null}><FontAwesomeIcon icon={faSync} /> Refresh</GhostButton>
                    </span>
                </div>
                {data?.running && (data.online?.names || []).length > 0 && (
                    <div className="flex flex-wrap gap-2 mt-3">
                        {data.online!.names.map((n) => <span key={n} className="text-xs px-2 py-1 rounded-md" style={{ background: 'rgba(34,197,94,0.14)', color: 'rgb(74,222,128)' }}>{n}</span>)}
                        {(data.online!.count ?? 0) > data.online!.names.length && <span className="text-xs text-neutral-500">and {(data.online!.count ?? 0) - data.online!.names.length} more</span>}
                    </div>
                )}
            </Panel>

            {error && <div className="text-sm rounded-lg p-3 mb-4" style={{ background: 'rgba(239,68,68,0.12)', color: 'rgb(248,113,113)' }}>{error}</div>}
            {notice && <div className="text-sm rounded-lg p-3 mb-4" style={{ background: 'rgba(99,102,241,0.12)', color: 'rgb(165,180,252)' }}>{notice}</div>}
            {!data && !error && <p className="text-neutral-400 text-sm"><FontAwesomeIcon icon={faSpinner} spin /> Loading…</p>}
            {data && !data.found && <Panel><p className="text-neutral-400 text-sm">This server has no server.properties yet. Start it once and the player lists appear here.</p></Panel>}

            {data?.found && (
                <Panel>
                    <div className="flex gap-1 flex-wrap mb-3 border-b border-neutral-700">
                        {(Object.keys(TABS) as ListKey[]).map((k) => (
                            <Tab key={k} active={list === k} onClick={() => { setList(k); setWho(''); setReason(''); }}>
                                {TABS[k]} <span className="text-neutral-500">{(data.lists[k] || []).length}</span>
                            </Tab>
                        ))}
                    </div>
                    <Can action={'players.manage'}>
                        <form className="flex gap-2 flex-wrap mb-3" onSubmit={(e) => { e.preventDefault(); run('add', base, { list, who, reason }, () => { setWho(''); setReason(''); }); }}>
                            <Input value={who} onChange={(e) => setWho(e.currentTarget.value)} maxLength={45} spellCheck={false} autoComplete="off"
                                placeholder={list === 'banned-ips' ? 'IP address' : 'Player name'} style={{ flex: 1 }} />
                            {banList && <Input value={reason} onChange={(e) => setReason(e.currentTarget.value)} maxLength={120} placeholder="Reason (optional)" style={{ flex: 1 }} />}
                            <PrimaryButton type="submit" disabled={busy !== null || who.trim() === ''}>
                                {busy === 'add' && <FontAwesomeIcon icon={faSpinner} spin />} {banList ? 'Ban' : 'Add'}
                            </PrimaryButton>
                        </form>
                    </Can>
                    {rows.map((r) => {
                        const key = String(list === 'banned-ips' ? r.ip ?? '' : r.name ?? '');
                        return (
                            <Row key={key}>
                                <div className="flex-1 min-w-0">
                                    <div className="text-sm text-white font-mono break-all">{key}</div>
                                    <div className="text-xs text-neutral-500">
                                        {list === 'ops' && `Level ${r.level ?? 4}`}
                                        {r.reason ? ` ${r.reason}` : ''}
                                        {r.created ? ` · ${r.created}` : ''}
                                    </div>
                                </div>
                                {key !== '' && (
                                    <Can action={'players.manage'}>
                                        <GhostButton disabled={busy !== null} onClick={() => run(`rm-${key}`, `${base}/remove`, { list, who: key })}>
                                            {banList ? 'Unban' : 'Remove'}
                                        </GhostButton>
                                    </Can>
                                )}
                            </Row>
                        );
                    })}
                    {rows.length === 0 && <p className="text-sm text-neutral-500">Nobody here yet.</p>}
                </Panel>
            )}
        </Wrapper>
    );
};
