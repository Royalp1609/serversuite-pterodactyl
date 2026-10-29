import React, { useState } from 'react';
import Can from '@/components/elements/Can';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faUsers, faSpinner, faSync } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — Players tab for Rust and Valheim (#85; Pelican #89).
 * Lists by SteamID64 (no name lookup yet). Rust: while running, console
 * commands then server.writecfg (apply at once); while stopped, users.cfg
 * and bans.cfg are edited. Valheim: its lists are files it reads on start
 * (no console), saved as Steam_<id>. SteamPlayersService checks every ID,
 * name and reason before it reaches the console.
 */

const Wrapper = styled.div`${tw`p-6 max-w-7xl mx-auto`};`;  // same page margins as the Mods tab
const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Input = styled.input`
    ${tw`text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none flex-1`};
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
    min-width: 10rem;
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

export interface SteamOverview {
    game: 'rust' | 'valheim';
    labels: Record<string, string>;
    running: boolean;
    lists: Record<string, Array<{ id: string; name: string; reason: string }>>;
}

const errorOf = (e: any, fallback: string) => e?.response?.data?.error || e?.response?.data?.message || fallback;

export default ({ base, data, reload }: { base: string; data: SteamOverview; reload: () => void }) => {
    const keys = Object.keys(data.labels);
    const [list, setList] = useState<string>(keys[0]);
    const [who, setWho] = useState('');
    const [name, setName] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const rust = data.game === 'rust';
    const ban = list === 'bans' || list === 'banned';
    const rows = data.lists[list] || [];

    const run = (key: string, url: string, body: object, after?: () => void) => {
        setBusy(key);
        setError(null);
        setNotice(null);
        http.post(url, body)
            .then((res) => { setNotice(res.data.data.message); after?.(); setTimeout(reload, data.running ? 600 : 0); })
            .catch((e) => setError(errorOf(e, 'Not done.')))
            .finally(() => setBusy(null));
    };
    const add = (e: React.FormEvent) => {
        e.preventDefault();
        run('add', base, { list, who, name, reason }, () => { setWho(''); setName(''); setReason(''); });
    };

    return (
        <Wrapper>
            <Panel>
                <div className="flex items-center gap-3 flex-wrap">
                    <FontAwesomeIcon icon={faUsers} className="text-2xl text-indigo-400" />
                    <div>
                        <h1 className="text-2xl font-bold text-white">{data.running ? 'Online' : 'Stopped'}</h1>
                        <p className="text-xs text-neutral-500">
                            {rust
                                ? (data.running ? 'Changes apply straight away through the console.' : 'Changes are saved to server/rust/cfg and apply when the server starts.')
                                : `Valheim reads these lists when it starts, so changes apply at the next start${data.running ? ' (restart to apply them now)' : ''}.`}
                            {' '}Players are entered by SteamID64 (17 digits; steamid.io finds it from a profile link)
                            {rust ? '.' : ', saved as Steam_<id>. Crossplay players: their Platform User ID from the server log or the F2 panel.'}
                        </p>
                    </div>
                    <span style={{ marginLeft: 'auto' }}>
                        <GhostButton onClick={reload}><FontAwesomeIcon icon={faSync} /> Refresh</GhostButton>
                    </span>
                </div>
            </Panel>

            <Panel>
                <div className="flex gap-1 flex-wrap mb-3">
                    {keys.map((k) => (
                        <Tab key={k} active={list === k} onClick={() => { setList(k); setWho(''); setName(''); setReason(''); }}>
                            {data.labels[k]} <span className="text-neutral-500">{(data.lists[k] || []).length}</span>
                        </Tab>
                    ))}
                </div>
                {list === 'permitted' && <p className="text-xs text-neutral-500 mb-3">While anyone is on this list, only the players on it can join.</p>}
                {error && <p className="text-sm text-red-400 mb-3">{error}</p>}
                {notice && <p className="text-sm text-green-400 mb-3">{notice}</p>}

                <Can action={'players.manage'}>
                    <form onSubmit={add} className="flex gap-2 flex-wrap mb-3">
                        <Input value={who} onChange={(e) => setWho(e.target.value)} maxLength={80} autoComplete={'off'} spellCheck={false}
                               placeholder={rust ? 'SteamID64' : 'SteamID64 or Platform User ID'} />
                        {rust && <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={64} placeholder={'Name (optional, for your records)'} />}
                        {rust && <Input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={64} placeholder={ban ? 'Reason (optional)' : 'Note (optional)'} />}
                        <PrimaryButton type={'submit'} disabled={busy !== null || who.trim() === ''}>
                            {busy === 'add' && <FontAwesomeIcon icon={faSpinner} spin />} {ban ? 'Ban' : 'Add'}
                        </PrimaryButton>
                    </form>
                </Can>

                {rows.map((r) => (
                    <Row key={r.id}>
                        <div className="flex-1 min-w-0">
                            <p className="text-sm text-white font-mono break-all">{r.id}</p>
                            {(r.name || r.reason) && <p className="text-xs text-neutral-500">{[r.name, r.reason].filter(Boolean).join(' · ')}</p>}
                        </div>
                        <Can action={'players.manage'}>
                            <GhostButton disabled={busy !== null} onClick={() => run(`rm-${r.id}`, `${base}/remove`, { list, who: r.id })}>
                                {busy === `rm-${r.id}` && <FontAwesomeIcon icon={faSpinner} spin />} {ban ? 'Unban' : 'Remove'}
                            </GhostButton>
                        </Can>
                    </Row>
                ))}
                {rows.length === 0 && <p className="text-sm text-neutral-500">Nobody here yet.</p>}
            </Panel>
        </Wrapper>
    );
};
