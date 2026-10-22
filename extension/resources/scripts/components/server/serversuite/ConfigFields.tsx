import React from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — the typed config form's fields (#21, #20): one row per
 * setting, the control chosen by the backend (ConfigEditor): toggle, choice,
 * number with its range, list (one per line), long text, text.
 */

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
const FieldRow = styled.div`
    ${tw`grid gap-2 rounded-lg p-3`};
    grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid rgba(255, 255, 255, 0.05);
    @media (max-width: 768px) { grid-template-columns: minmax(0, 1fr); }
`;
const Select = styled.select`
    ${tw`w-full text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none`};
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
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

export interface ConfigField {
    id: number; label: string; help: string; type: string;
    control: 'toggle' | 'select' | 'number' | 'list' | 'textarea' | 'text';
    value: string | boolean; choices: string[] | null; min: number | null; max: number | null;
}

interface Props {
    fields: ConfigField[];
    values: Record<number, string | boolean>;
    onChange: (id: number, value: string | boolean) => void;
}

export default ({ fields, values, onChange }: Props) => {
    const set = onChange;

    return (
        <div className="flex flex-col gap-2">
            {fields.map((f) => (
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
            {fields.length === 0 && <p className="text-center text-neutral-500 py-4 text-sm">No settings match.</p>}
        </div>
    );
};
