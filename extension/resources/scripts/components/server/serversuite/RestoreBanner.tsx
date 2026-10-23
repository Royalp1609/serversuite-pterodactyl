import React, { useEffect, useState } from 'react';
import Can from '@/components/elements/Can';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faHistory, faTimes } from '@fortawesome/free-solid-svg-icons';

/*
 * Server Suite — after a backup restore (#60).
 * The panel brings the mod list back in line when a restore finishes; this
 * banner says what changed ("Restored backup X: 3 mods back, 2 gone") and
 * links to Reconcile when jars are left that nothing tracks.
 */

interface Result {
    at: string;
    backup: { uuid: string; name: string | null };
    saved_list: boolean;
    truncate: boolean;
    back: string[];
    gone: string[];
    forgotten: string[];
    adopted: string[];
    untracked: number;
}

const names = (list: string[]) => (list.length > 5 ? `${list.slice(0, 5).join(', ')} and ${list.length - 5} more` : list.join(', '));
const mods = (n: number) => `${n} mod${n === 1 ? '' : 's'}`;

export default ({ base, refreshKey, onChanged, onReconcile }: { base: string; refreshKey: number; onChanged: () => void; onReconcile: () => void }) => {
    const [r, setR] = useState<Result | null>(null);
    const [seen, setSeen] = useState<string | null>(null);

    useEffect(() => {
        http.get(`${base}/restore-sync`)
            .then((res) => {
                const d: Result | null = res.data.data;
                setR(d);
                // A restore finished while the tab was open: show the new list.
                if (d && seen !== null && d.at !== seen) onChanged();
                setSeen(d ? d.at : '');
            })
            .catch(() => undefined);
    }, [base, refreshKey]);

    if (!r) return null;

    const dismiss = () => {
        setR(null);
        http.post(`${base}/restore-sync/dismiss`).catch(() => undefined);
    };

    return (
        <div className="text-sm rounded-lg p-3 mb-4 flex gap-3 items-start" style={{ background: 'rgba(99,102,241,0.12)', color: 'rgb(199,210,254)' }}>
            <FontAwesomeIcon icon={faHistory} className="mt-1" />
            <div className="flex-1">
                <div className="font-semibold text-white">
                    Restored backup {r.backup.name || r.backup.uuid.slice(0, 8)}: mod list updated
                    <span className="font-normal text-neutral-400"> · {new Date(r.at).toLocaleString()}</span>
                </div>
                <div className="mt-1">
                    {r.back.length === 0 && r.gone.length === 0
                        ? 'The mod list already matched the backup.'
                        : [r.back.length > 0 && `${mods(r.back.length)} back (${names(r.back)})`, r.gone.length > 0 && `${mods(r.gone.length)} gone (${names(r.gone)})`].filter(Boolean).join('; ') + '.'}
                </div>
                {!r.saved_list && (
                    <div className="mt-1 text-neutral-400">
                        This backup has no saved mod list (it is older, or was taken outside Server Suite), so only the clear cases were fixed:
                        mods whose files are gone were forgotten{r.adopted.length > 0 ? `, and ${mods(r.adopted.length)} identified by hash were tracked again` : ''}.
                    </div>
                )}
                {r.untracked > 0 && (
                    <div className="mt-1">
                        {r.untracked} jar{r.untracked === 1 ? ' is' : 's are'} in the mods folder with nothing tracking {r.untracked === 1 ? 'it' : 'them'}.{' '}
                        <Can action={'mods.configure'} renderOnError={<span className="text-neutral-400">Someone with Configure mods can sort them out in Reconcile.</span>}>
                            <button className="underline" onClick={onReconcile}>Open Reconcile</button>
                        </Can>
                    </div>
                )}
            </div>
            <button onClick={dismiss} title="Dismiss" className="text-neutral-400 hover:text-white"><FontAwesomeIcon icon={faTimes} /></button>
        </div>
    );
};
