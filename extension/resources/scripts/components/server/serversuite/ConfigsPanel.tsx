import React, { useCallback, useEffect, useState } from 'react';
import Can from '@/components/elements/Can';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArrowLeft, faFileAlt, faFolder, faSave, faSpinner } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — Mods tab, Configs view (#21).
 * Browse /plugins, /config, /defaultconfigs and /kubejs and edit a file as
 * a typed form (switches, numbers, choices, lists) or as text. The backend
 * (ConfigFilesService) enforces every rule: path limits, 512 KiB, in-place
 * edits checked by reading the result back, and "changed on disk since you
 * opened it". Opening needs file.read-content, saving file.update.
 */

const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Row = styled.button`
    ${tw`w-full flex items-center gap-3 text-left rounded-lg px-3 py-2 text-sm text-neutral-200`};
    background: rgba(15, 23, 42, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.05);
    &:hover { border-color: rgba(99, 102, 241, 0.4); }
`;
const FieldRow = styled.div`
    ${tw`grid gap-2 rounded-lg p-3`};
    grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid rgba(255, 255, 255, 0.05);
    @media (max-width: 768px) { grid-template-columns: minmax(0, 1fr); }
`;
const Input = styled.input`
    ${tw`w-full text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none`};
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
    &:focus { border-color: rgb(99, 102, 241); }
`;
const TextArea = styled.textarea`
    ${tw`w-full text-neutral-200 rounded-lg px-3 py-2 outline-none font-mono`};
    font-size: 0.8rem;
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
    &:focus { border-color: rgb(99, 102, 241); }
`;
const Select = styled.select`
    ${tw`w-full text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none`};
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
const Switch = styled.label`
    ${tw`relative inline-block cursor-pointer`};
    width: 2.4rem; height: 1.35rem;
    input { opacity: 0; width: 0; height: 0; }
    span { position: absolute; inset: 0; border-radius: 999px; background: rgb(51, 65, 85); transition: background 0.15s; }
    span::before { content: ''; position: absolute; width: 1rem; height: 1rem; left: 0.18rem; top: 0.18rem; border-radius: 50%; background: #fff; transition: transform 0.15s; }
    input:checked + span { background: rgb(99, 102, 241); }
    input:checked + span::before { transform: translateX(1.05rem); }
    input:focus-visible + span { outline: 2px solid rgb(165, 180, 252); outline-offset: 2px; }
`;

interface Entry { name: string; path: string; is_file: boolean; size: number }
interface Field {
    id: number; label: string; help: string; type: string;
    control: 'toggle' | 'select' | 'number' | 'list' | 'textarea' | 'text';
    value: string | boolean; choices: string[] | null; min: number | null; max: number | null;
}
interface OpenFile { path: string; content: string; hash: string; fields: Field[]; reason: string | null }

const errorOf = (e: any, fallback: string) => e?.response?.data?.error || e?.response?.data?.message || fallback;

export default ({ uuid }: { uuid: string }) => {
    const base = `/extensions/serversuite/${uuid}/configs`;
    const [path, setPath] = useState<string | null>(null);
    const [entries, setEntries] = useState<Entry[]>([]);
    const [file, setFile] = useState<OpenFile | null>(null);
    const [mode, setMode] = useState<'form' | 'text'>('form');
    const [values, setValues] = useState<Record<number, string | boolean>>({});
    const [text, setText] = useState('');
    const [filter, setFilter] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const browse = useCallback((p: string | null) => {
        setBusy(true);
        setError(null);
        http.get(base, { params: p ? { path: p } : {} })
            .then((res) => { setPath(res.data.data.path); setEntries(res.data.data.entries); })
            .catch((e) => setError(errorOf(e, 'Could not open the folder.')))
            .finally(() => setBusy(false));
    }, [base]);

    useEffect(() => { browse(null); }, [browse]);

    const load = (f: OpenFile) => {
        setFile(f);
        setText(f.content);
        setValues(Object.fromEntries(f.fields.map((x) => [x.id, x.value])));
        setMode(f.fields.length > 0 ? 'form' : 'text');
    };

    const open = (p: string) => {
        setBusy(true);
        setError(null);
        setNotice(null);
        setFilter('');
        http.get(`${base}/file`, { params: { path: p } })
            .then((res) => load(res.data.data))
            .catch((e) => setError(errorOf(e, 'Could not open the file.')))
            .finally(() => setBusy(false));
    };

    const up = () => {
        if (file) { setFile(null); return; }
        if (!path) return;
        const parent = path.substring(0, path.lastIndexOf('/'));
        browse(['', '/plugins', '/config', '/defaultconfigs', '/kubejs'].includes(path) ? null : parent || null);
    };

    const toggleMode = () => {
        if (!file) return;
        setError(null);
        if (mode === 'form') {
            setBusy(true);
            http.post(`${base}/render`, { path: file.path, hash: file.hash, values })
                .then((res) => { setText(res.data.data.content); setMode('text'); })
                .catch((e) => setError(errorOf(e, 'Fix the form first.')))
                .finally(() => setBusy(false));
        } else if (text !== file.content) {
            setError('The form works on the file as saved. Save or undo your text changes, then switch.');
        } else {
            setMode('form');
        }
    };

    const save = () => {
        if (!file) return;
        setBusy(true);
        setError(null);
        setNotice(null);
        const body = mode === 'form' ? { path: file.path, hash: file.hash, values } : { path: file.path, hash: file.hash, content: text };
        http.post(`${base}/file`, body)
            .then((res) => {
                const d = res.data.data;
                load(d);
                setNotice(!d.written ? 'No changes.' : `Saved${d.changed?.length ? `: ${d.changed.join(', ')}` : ''}. Most plugins and mods need a reload or restart to apply it.`);
            })
            .catch((e) => setError(errorOf(e, 'Save failed.')))
            .finally(() => setBusy(false));
    };

    const set = (id: number, v: string | boolean) => setValues((cur) => ({ ...cur, [id]: v }));
    const needle = filter.trim().toLowerCase();
    const shown = file ? file.fields.filter((f) => !needle || `${f.label} ${f.help}`.toLowerCase().includes(needle)) : [];

    return (
        <Panel>
            <div className="flex items-center gap-2 mb-3 flex-wrap">
                {(file || path) && <GhostButton onClick={up} disabled={busy}><FontAwesomeIcon icon={faArrowLeft} /> {file ? 'Back' : 'Up'}</GhostButton>}
                <span className="text-sm font-mono text-neutral-300 break-all">{file ? file.path : path || 'Config folders'}</span>
                {busy && <FontAwesomeIcon icon={faSpinner} spin className="text-neutral-400" />}
                {file && (
                    <span className="flex gap-2" style={{ marginLeft: 'auto' }}>
                        {file.fields.length > 0 && <GhostButton onClick={toggleMode} disabled={busy}>{mode === 'form' ? 'Text editor' : 'Form'}</GhostButton>}
                        <Can action={'file.update'}>
                            <PrimaryButton onClick={save} disabled={busy}><FontAwesomeIcon icon={faSave} /> Save</PrimaryButton>
                        </Can>
                    </span>
                )}
            </div>
            {error && <div className="text-sm rounded-lg p-3 mb-3" style={{ background: 'rgba(239,68,68,0.12)', color: 'rgb(248,113,113)' }}>{error}</div>}
            {notice && <div className="text-sm rounded-lg p-3 mb-3" style={{ background: 'rgba(99,102,241,0.12)', color: 'rgb(165,180,252)' }}>{notice}</div>}

            {!file && (
                <div className="flex flex-col gap-1">
                    {entries.map((e) => (
                        <Row key={e.path} onClick={() => (e.is_file ? open(e.path) : browse(e.path))} disabled={busy}>
                            <FontAwesomeIcon icon={e.is_file ? faFileAlt : faFolder} className="text-neutral-400" />
                            <span className="flex-1 truncate">{e.name}</span>
                            {e.is_file && <span className="text-xs text-neutral-500">{(e.size / 1024).toFixed(1)} KiB</span>}
                        </Row>
                    ))}
                    {entries.length === 0 && !busy && <p className="text-center text-neutral-500 py-6 text-sm">No config files here. Start the server once so plugins and mods write their configs.</p>}
                </div>
            )}

            {file && mode === 'form' && (
                <>
                    {file.fields.length > 10 && (
                        <div className="mb-3"><Input placeholder={`Find a setting (${file.fields.length})`} value={filter} onChange={(e) => setFilter(e.currentTarget.value)} /></div>
                    )}
                    <div className="flex flex-col gap-2">
                        {shown.map((f) => (
                            <FieldRow key={f.id}>
                                <div>
                                    <label htmlFor={`cfg-${f.id}`} className="text-sm font-mono text-neutral-100 break-all">{f.label}</label>
                                    {f.help && <div className="text-xs text-neutral-500 mt-1" style={{ whiteSpace: 'pre-line' }}>{f.help}</div>}
                                </div>
                                <div>
                                    {f.control === 'toggle' && (
                                        <Switch><input id={`cfg-${f.id}`} type="checkbox" checked={values[f.id] === true} onChange={(e) => set(f.id, e.currentTarget.checked)} /><span /></Switch>
                                    )}
                                    {f.control === 'select' && (
                                        <Select id={`cfg-${f.id}`} value={String(values[f.id])} onChange={(e) => set(f.id, e.currentTarget.value)}>
                                            {(f.choices || []).map((c) => <option key={c} value={c}>{c}</option>)}
                                        </Select>
                                    )}
                                    {f.control === 'number' && (
                                        <>
                                            <Input id={`cfg-${f.id}`} type="number" step={f.type === 'int' ? 1 : 'any'} min={f.min ?? undefined} max={f.max ?? undefined}
                                                value={String(values[f.id])} onChange={(e) => set(f.id, e.currentTarget.value)} />
                                            {(f.min !== null || f.max !== null) && <div className="text-xs text-neutral-500 mt-1">{f.min ?? '…'} to {f.max ?? '…'}</div>}
                                        </>
                                    )}
                                    {(f.control === 'list' || f.control === 'textarea') && (
                                        <>
                                            <TextArea id={`cfg-${f.id}`} spellCheck={false} rows={Math.min(8, Math.max(2, String(values[f.id]).split('\n').length + 1))}
                                                value={String(values[f.id])} onChange={(e) => set(f.id, e.currentTarget.value)} />
                                            {f.control === 'list' && <div className="text-xs text-neutral-500 mt-1">One per line</div>}
                                        </>
                                    )}
                                    {f.control === 'text' && (
                                        <Input id={`cfg-${f.id}`} spellCheck={false} value={String(values[f.id])} onChange={(e) => set(f.id, e.currentTarget.value)} />
                                    )}
                                </div>
                            </FieldRow>
                        ))}
                        {shown.length === 0 && <p className="text-center text-neutral-500 py-4 text-sm">No settings match.</p>}
                    </div>
                    <p className="text-xs text-neutral-500 mt-3">Only the values you change are written; comments and layout stay as they are. Settings the form can't edit safely are only in the text editor.</p>
                </>
            )}

            {file && mode === 'text' && (
                <>
                    {file.reason && <p className="text-xs text-neutral-500 mb-2">{file.reason}</p>}
                    <TextArea spellCheck={false} rows={24} style={{ whiteSpace: 'pre' }} value={text} onChange={(e) => setText(e.currentTarget.value)} />
                </>
            )}
        </Panel>
    );
};
