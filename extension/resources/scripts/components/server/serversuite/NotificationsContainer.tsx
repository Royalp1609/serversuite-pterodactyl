import React, { useCallback, useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBell, faSpinner } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — Notifications tab (#26).
 * This server's Discord webhooks and ntfy topics, and which events go to
 * them. Without its own targets, a server uses the admin's default. URLs and
 * tokens are write-only: this tab only ever sees a label. The backend checks
 * that owner-entered targets are on the public internet.
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

interface State { labels: string[]; inherits: boolean; default_count: number; events: Record<string, boolean>; catalog: Record<string, string> }
interface Result { label: string; ok: boolean; error: string | null }

const errorOf = (e: any, fallback: string) => e?.response?.data?.error || e?.response?.data?.message || fallback;

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const base = `/extensions/serversuite/${uuid}/notifications`;
    const [data, setData] = useState<State | null>(null);
    const [events, setEvents] = useState<Record<string, boolean>>({});
    const [type, setType] = useState<'discord' | 'ntfy'>('discord');
    const [url, setUrl] = useState('');
    const [ntfyServer, setNtfyServer] = useState('https://ntfy.sh');
    const [topic, setTopic] = useState('');
    const [token, setToken] = useState('');
    const [results, setResults] = useState<Result[]>([]);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const apply = (d: State) => { setData(d); setEvents(d.events); };
    const load = useCallback(() => {
        if (!uuid) return;
        http.get(base).then((res) => apply(res.data.data)).catch((e) => setError(errorOf(e, 'Could not load the notification settings.')));
    }, [uuid, base]);
    useEffect(() => { load(); }, [load]);

    const post = (key: string, url2: string, body: object, after?: (d: any) => void) => {
        setBusy(key);
        setError(null);
        http.post(url2, body)
            .then((res) => { apply(res.data.data); after?.(res.data.data); })
            .catch((e) => setError(errorOf(e, 'Not saved.')))
            .finally(() => setBusy(null));
    };

    return (
        <Wrapper>
            <Panel>
                <div className="flex items-center gap-3 mb-2">
                    <FontAwesomeIcon icon={faBell} className="text-2xl text-indigo-400" />
                    <h1 className="text-2xl font-bold text-white">Notifications</h1>
                </div>
                <p className="text-xs text-neutral-500 mb-3">
                    Discord webhooks and ntfy topics. URLs and tokens are stored encrypted and never shown again.
                    {data?.inherits && (data.default_count > 0 ? ` This server uses the panel's default (${data.default_count} target${data.default_count === 1 ? '' : 's'}) until you add your own.` : ' Nothing is set up yet.')}
                </p>
                {error && <div className="text-sm rounded-lg p-3 mb-3" style={{ background: 'rgba(239,68,68,0.12)', color: 'rgb(248,113,113)' }}>{error}</div>}
                {(data?.labels || []).map((l, i) => (
                    <div key={i} className="flex items-center gap-3 rounded-lg px-3 py-2 mb-2" style={{ background: 'rgba(15,23,42,0.4)' }}>
                        <span className="text-sm text-white font-mono flex-1">{l}</span>
                        <GhostButton disabled={busy !== null} onClick={() => post(`rm-${i}`, `${base}/remove`, { index: i })}>Remove</GhostButton>
                    </div>
                ))}
                <form className="flex gap-2 flex-wrap mt-3" autoComplete="off"
                    onSubmit={(e) => { e.preventDefault(); post('add', base, type === 'discord' ? { type, url } : { type, server: ntfyServer, topic, token }, () => { setUrl(''); setTopic(''); setToken(''); }); }}>
                    <Select value={type} onChange={(e) => setType(e.currentTarget.value as 'discord' | 'ntfy')}>
                        <option value="discord">Discord</option>
                        <option value="ntfy">ntfy</option>
                    </Select>
                    {type === 'discord' ? (
                        <Input type="url" value={url} onChange={(e) => setUrl(e.currentTarget.value)} placeholder="https://discord.com/api/webhooks/…" style={{ flex: 1, minWidth: '18rem' }} />
                    ) : (
                        <>
                            <Input type="url" value={ntfyServer} onChange={(e) => setNtfyServer(e.currentTarget.value)} placeholder="https://ntfy.sh" />
                            <Input value={topic} onChange={(e) => setTopic(e.currentTarget.value)} placeholder="topic" />
                            <Input type="password" value={token} onChange={(e) => setToken(e.currentTarget.value)} placeholder="Access token (optional)" autoComplete="new-password" />
                        </>
                    )}
                    <PrimaryButton type="submit" disabled={busy !== null}>{busy === 'add' && <FontAwesomeIcon icon={faSpinner} spin />} Add</PrimaryButton>
                </form>
            </Panel>

            {data && (
                <Panel>
                    <h2 className="text-lg font-semibold text-white mb-2">What to send</h2>
                    {Object.entries(data.catalog).map(([key, label]) => (
                        <label key={key} className="flex items-center gap-2 text-sm text-neutral-200 mb-2">
                            <input type="checkbox" checked={events[key] !== false} onChange={(e) => setEvents({ ...events, [key]: e.currentTarget.checked })} /> {label}
                        </label>
                    ))}
                    <div className="flex gap-2 mt-3">
                        <PrimaryButton disabled={busy !== null} onClick={() => post('events', `${base}/events`, { events })}>Save</PrimaryButton>
                        <GhostButton disabled={busy !== null} onClick={() => post('test', `${base}/test`, {}, (d) => setResults(d.results || []))}>
                            {busy === 'test' && <FontAwesomeIcon icon={faSpinner} spin />} Send a test
                        </GhostButton>
                    </div>
                    {results.map((r, i) => (
                        <p key={i} className="text-sm mt-2" style={{ color: r.ok ? 'rgb(74,222,128)' : 'rgb(248,113,113)' }}>{r.ok ? '✓' : '✗'} {r.label}{r.error ? `: ${r.error}` : ''}</p>
                    ))}
                    <p className="text-xs text-neutral-500 mt-3">Repeats are held back: the same message at most once per cooldown (a day for updates, six hours for a failing backup), and no more than 30 an hour.</p>
                </Panel>
            )}
        </Wrapper>
    );
};
