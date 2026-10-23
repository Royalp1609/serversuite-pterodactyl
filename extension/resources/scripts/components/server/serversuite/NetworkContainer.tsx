import React, { useCallback, useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faProjectDiagram, faSpinner } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — Velocity network (#30).
 * On a Velocity server: tick the owner's other servers to put behind it,
 * name them, order them, Apply. The backend writes velocity.toml (servers,
 * try list, modern forwarding) and each backend's forwarding secret and
 * online-mode=false. On a backend: which proxy it's behind. Owner and root
 * admins only (the tab's permission is one no subuser can hold).
 */

const Wrapper = styled.div`${tw`p-6 max-w-7xl mx-auto`};`;  // same page margins as the Mods tab
const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Input = styled.input`
    ${tw`text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none w-full`};
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
`;
const PrimaryButton = styled.button`
    ${tw`px-4 py-2 rounded-lg text-sm font-medium text-white flex items-center gap-2 mt-3`};
    background: rgb(99, 102, 241);
    &:hover { background: rgb(79, 82, 221); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;
const Row = styled.div`
    ${tw`grid items-center gap-3 rounded-lg px-3 py-2 mb-2`};
    grid-template-columns: auto minmax(0, 2fr) minmax(0, 1.4fr) 4.5rem;
    background: rgba(15, 23, 42, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.05);
`;

interface Candidate { uuid: string; name: string; loader: string | null; supported: boolean; address: string | null; member: string | null; position: number | null; elsewhere: boolean }
interface Result { server: string; status: string; message: string }

const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 32) || 'server';

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const base = `/extensions/serversuite/${uuid}/network`;
    const [proxy, setProxy] = useState<boolean | null>(null);
    const [member, setMember] = useState<{ proxy: string | null } | null>(null);
    const [cands, setCands] = useState<Candidate[]>([]);
    const [chosen, setChosen] = useState<Record<string, boolean>>({});
    const [names, setNames] = useState<Record<string, string>>({});
    const [order, setOrder] = useState<Record<string, number>>({});
    const [results, setResults] = useState<Result[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(() => {
        if (!uuid) return;
        http.get(base).then((res) => {
            const d = res.data.data;
            setProxy(d.proxy);
            setMember(d.member ?? null);
            const c: Candidate[] = d.candidates || [];
            setCands(c);
            setChosen(Object.fromEntries(c.map((x) => [x.uuid, x.member !== null])));
            setNames(Object.fromEntries(c.map((x) => [x.uuid, x.member ?? slug(x.name)])));
            setOrder(Object.fromEntries(c.map((x, i) => [x.uuid, x.position ?? 100 + i])));
        }).catch((e) => setError(e?.response?.data?.error || e?.response?.data?.message || 'Could not load the network.'));
    }, [uuid, base]);
    useEffect(() => { load(); }, [load]);

    const apply = () => {
        const backends = cands.filter((c) => chosen[c.uuid]).sort((a, b) => (order[a.uuid] ?? 0) - (order[b.uuid] ?? 0))
            .map((c) => ({ uuid: c.uuid, name: names[c.uuid] }));
        setBusy(true);
        setError(null);
        http.post(base, { backends })
            .then((res) => { setResults(res.data.data.results); load(); })
            .catch((e) => setError(e?.response?.data?.error || 'Not applied.'))
            .finally(() => setBusy(false));
    };

    const colour = (s: string) => (s === 'ok' ? 'rgb(74,222,128)' : s === 'action' ? 'rgb(251,191,36)' : 'rgb(248,113,113)');

    return (
        <Wrapper>
            <Panel>
                <div className="flex items-center gap-3 mb-2">
                    <FontAwesomeIcon icon={faProjectDiagram} className="text-2xl text-indigo-400" />
                    <h1 className="text-2xl font-bold text-white">Velocity network</h1>
                </div>
                {error && <div className="text-sm rounded-lg p-3 mb-3" style={{ background: 'rgba(239,68,68,0.12)', color: 'rgb(248,113,113)' }}>{error}</div>}
                {proxy === null && !error && <p className="text-neutral-400 text-sm"><FontAwesomeIcon icon={faSpinner} spin /> Loading…</p>}
                {proxy === false && (
                    <p className="text-sm text-neutral-300">
                        {member ? `This server is a backend behind ${member.proxy ?? 'a Velocity proxy'}. Players join through the proxy; manage the network from the proxy's Network tab.`
                            : 'This server isn\'t a Velocity proxy. Switch a server to Velocity on its Versions tab to build a network from it.'}
                    </p>
                )}
                {proxy && (
                    <>
                        <p className="text-xs text-neutral-500 mb-3">
                            Your other servers. Tick the ones that go behind this proxy, give each the name Velocity uses, and set the order players are tried in (lowest first).
                            Paper, Purpur, Folia, Fabric and Quilt (with FabricProxy-Lite) are set up automatically. Point your subdomain at this proxy.
                        </p>
                        {cands.map((c) => (
                            <Row key={c.uuid}>
                                <input type="checkbox" checked={!!chosen[c.uuid]} disabled={c.elsewhere} onChange={(e) => setChosen({ ...chosen, [c.uuid]: e.currentTarget.checked })} />
                                <div className="min-w-0">
                                    <div className="text-sm text-white break-words">{c.name}</div>
                                    <div className="text-xs text-neutral-500">{c.loader ?? 'Unknown loader'} · {c.address ?? 'no allocation'}{c.elsewhere ? ' · in another network' : !c.supported ? ' · manual setup' : ''}</div>
                                </div>
                                <Input value={names[c.uuid] ?? ''} maxLength={32} spellCheck={false} aria-label="Velocity name" onChange={(e) => setNames({ ...names, [c.uuid]: e.currentTarget.value })} />
                                <Input type="number" min={0} value={order[c.uuid] ?? 0} aria-label="Try order" onChange={(e) => setOrder({ ...order, [c.uuid]: Number(e.currentTarget.value) })} />
                            </Row>
                        ))}
                        {cands.length === 0 && <p className="text-sm text-neutral-500">You have no other servers to put behind this proxy.</p>}
                        {cands.length > 0 && <PrimaryButton onClick={apply} disabled={busy}>{busy && <FontAwesomeIcon icon={faSpinner} spin />} Apply</PrimaryButton>}
                    </>
                )}
            </Panel>
            {results.length > 0 && (
                <Panel>
                    <h2 className="text-lg font-semibold text-white mb-2">What changed</h2>
                    {results.map((r, i) => (
                        <p key={i} className="text-sm mb-1"><span style={{ color: colour(r.status) }}>{r.status === 'ok' ? '✓' : r.status === 'action' ? '!' : '✗'}</span> <strong className="text-white">{r.server}</strong> <span className="text-neutral-400">{r.message}</span></p>
                    ))}
                    <p className="text-xs text-neutral-500 mt-2">Restart the proxy and every backend for the changes to take effect. Backends refuse players who don't come through the proxy.</p>
                </Panel>
            )}
        </Wrapper>
    );
};
