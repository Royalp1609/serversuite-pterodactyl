import React, { useCallback, useEffect, useRef, useState } from 'react';
import Can from '@/components/elements/Can';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSlidersH, faSpinner, faSave, faSync } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import ConfigFields, { ConfigField } from './ConfigFields';

/*
 * Server Suite — Properties tab (#20).
 * server.properties as a form (the #21 editor: values changed in place, so
 * comments and order stay, and a typo can't add a setting). Save, save and
 * restart, or, when level-seed changed, reset the world so the new seed
 * applies: the backend stops the server and only wipes the world once it's
 * offline. Needs file.read-content to see it, file.update to save,
 * control.restart to restart, file.delete + control.* to reset the world.
 */

const Wrapper = styled.div`${tw`my-6`};`;
const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Input = styled.input`
    ${tw`w-full text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none`};
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
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

interface State { found: boolean; hash: string | null; fields: ConfigField[]; seed: string | null; power: string | null }

const errorOf = (e: any, fallback: string) => e?.response?.data?.error || e?.response?.data?.message || fallback;

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const base = `/extensions/serversuite/${uuid}/properties`;
    const [data, setData] = useState<State | null>(null);
    const [values, setValues] = useState<Record<number, string | boolean>>({});
    const [filter, setFilter] = useState('');
    const [resetWorld, setResetWorld] = useState(true);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const polls = useRef(0);

    const load = useCallback(() => {
        if (!uuid) return;
        http.get(base)
            .then((res) => {
                const d: State = res.data.data;
                setData(d);
                setValues(Object.fromEntries(d.fields.map((f) => [f.id, f.value])));
            })
            .catch((e) => setError(errorOf(e, 'Could not read server.properties.')));
    }, [uuid, base]);

    useEffect(() => { load(); }, [load]);

    const seedField = data?.fields.find((f) => f.label === 'level-seed');
    const seedChanged = !!seedField && String(values[seedField.id] ?? '') !== String(data?.seed ?? '');

    const pollReset = () => {
        polls.current += 1;
        if (polls.current > 45) {
            setBusy(null);
            setError('The server did not stop in time. Stop it, then reset the world from the Versions tab or try again.');
            return;
        }
        http.post(`${base}/reset-world`)
            .then((res) => {
                if (res.data.data.status === 'done') {
                    setBusy(null);
                    setNotice('World reset: the server is starting with the new seed.');
                    load();
                } else {
                    setTimeout(pollReset, 4000);
                }
            })
            .catch((e) => { setBusy(null); setError(errorOf(e, 'World reset failed.')); });
    };

    const save = (after: 'none' | 'restart' | 'reset_world') => {
        if (!data?.hash) return;
        setBusy(after);
        setError(null);
        setNotice(null);
        http.post(base, { hash: data.hash, values, after })
            .then((res) => {
                const r = res.data.data;
                if (after === 'reset_world') {
                    setNotice('Saved. Stopping the server to reset the world…');
                    polls.current = 0;
                    setTimeout(pollReset, 4000);
                    return;
                }
                setBusy(null);
                const what = r.changed.length ? `Saved: ${r.changed.join(', ')}.` : 'No changes.';
                setNotice(after === 'restart' ? `${what} The server is restarting.`
                    : `${what} Restart the server for changes to apply.${r.seed_changed ? ' The new seed only applies to a newly generated world.' : ''}`);
                load();
            })
            .catch((e) => { setBusy(null); setError(errorOf(e, 'Save failed.')); });
    };

    const needle = filter.trim().toLowerCase();
    const shown = (data?.fields || []).filter((f) => !needle || `${f.label} ${f.help}`.toLowerCase().includes(needle));

    return (
        <Wrapper>
            <Panel>
                <div className="flex items-center gap-3 mb-4 flex-wrap">
                    <FontAwesomeIcon icon={faSlidersH} className="text-2xl text-indigo-400" />
                    <h1 className="text-2xl font-bold text-white">Server properties</h1>
                    <span style={{ marginLeft: 'auto' }}>
                        <GhostButton onClick={load} disabled={busy !== null}><FontAwesomeIcon icon={faSync} /> Reload</GhostButton>
                    </span>
                </div>
                {error && <div className="text-sm rounded-lg p-3 mb-3" style={{ background: 'rgba(239,68,68,0.12)', color: 'rgb(248,113,113)' }}>{error}</div>}
                {notice && <div className="text-sm rounded-lg p-3 mb-3" style={{ background: 'rgba(99,102,241,0.12)', color: 'rgb(165,180,252)' }}>{notice}</div>}
                {!data && !error && <p className="text-neutral-400 text-sm"><FontAwesomeIcon icon={faSpinner} spin /> Loading…</p>}
                {data && !data.found && <p className="text-neutral-400 text-sm">This server has no server.properties yet. Start it once and it appears here.</p>}
                {data?.found && (
                    <>
                        <div className="mb-3"><Input placeholder={`Find a setting (${data.fields.length})`} value={filter} onChange={(e) => setFilter(e.currentTarget.value)} /></div>
                        <ConfigFields fields={shown} values={values} onChange={(id, v) => setValues((cur) => ({ ...cur, [id]: v }))} />
                        {seedChanged && (
                            <Can action={['file.delete', 'control.stop', 'control.start']}>
                                <label className="flex items-center gap-2 text-sm text-neutral-200 mt-4">
                                    <input type="checkbox" checked={resetWorld} onChange={(e) => setResetWorld(e.currentTarget.checked)} />
                                    Reset the world so the new seed applies (stops the server, deletes the world folders, starts it again; plugins and configs stay)
                                </label>
                            </Can>
                        )}
                        <div className="flex gap-2 mt-4 flex-wrap">
                            <Can action={'file.update'}>
                                {seedChanged && resetWorld ? (
                                    <Can action={['file.delete', 'control.stop', 'control.start']} renderOnError={
                                        <PrimaryButton onClick={() => save('none')} disabled={busy !== null}><FontAwesomeIcon icon={faSave} /> Save</PrimaryButton>
                                    }>
                                        <PrimaryButton onClick={() => window.confirm('Save, stop the server and delete its world so it regenerates with the new seed?') && save('reset_world')} disabled={busy !== null}>
                                            <FontAwesomeIcon icon={busy === 'reset_world' ? faSpinner : faSave} spin={busy === 'reset_world'} /> Save and reset the world
                                        </PrimaryButton>
                                    </Can>
                                ) : (
                                    <>
                                        <PrimaryButton onClick={() => save('none')} disabled={busy !== null}>
                                            <FontAwesomeIcon icon={busy === 'none' ? faSpinner : faSave} spin={busy === 'none'} /> Save
                                        </PrimaryButton>
                                        <Can action={'control.restart'}>
                                            <GhostButton onClick={() => save('restart')} disabled={busy !== null}>
                                                <FontAwesomeIcon icon={busy === 'restart' ? faSpinner : faSync} spin={busy === 'restart'} /> Save and restart
                                            </GhostButton>
                                        </Can>
                                    </>
                                )}
                            </Can>
                        </div>
                        <p className="text-xs text-neutral-500 mt-3">Only the values you change are written; comments and the order of the file stay as they are.</p>
                    </>
                )}
            </Panel>
        </Wrapper>
    );
};
