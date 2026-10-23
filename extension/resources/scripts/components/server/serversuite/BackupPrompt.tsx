import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArchive } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — the "back up first?" question before a mod change (#24),
 * as an in-panel dialog instead of the browser's confirm() boxes, for
 * changes that have no dialog of their own (update, update all, config
 * save, crossplay). Dialogs that do (Remove, Install, the queue) ask with
 * a tick box instead (BackupChoice), so there is only ever one card. Mount <BackupPromptHost /> once on a page;
 * askBackup() (used by getClearance in prechange.ts) opens it and resolves
 * with the answer. Closing it (Escape, background) cancels. Its own overlay
 * on <body> at z-index 1000: Pterodactyl's Modal renders into #modal-portal,
 * which sits under the tabs' own dialogs.
 */

export type BackupAnswer = 'backup' | 'skip' | 'cancel';

let opener: ((label: string) => Promise<BackupAnswer>) | null = null;

/** Ask before a change. Without a mounted host, nothing is changed. */
export const askBackup = (label: string): Promise<BackupAnswer> => (opener ? opener(label) : Promise.resolve('cancel'));

const Primary = styled.button`
    ${tw`px-4 py-2 rounded-lg text-sm font-medium text-white`};
    background: rgb(99, 102, 241);
    &:hover { background: rgb(79, 82, 221); }
`;
const Ghost = styled.button`
    ${tw`px-4 py-2 rounded-lg text-sm font-medium`};
    background: rgba(30, 41, 59, 0.6);
    color: rgb(203, 213, 225);
    border: 1px solid rgba(255, 255, 255, 0.08);
    &:hover { background: rgba(51, 65, 85, 0.8); }
`;

/** The tick box for dialogs that ask in place (Ask mode); a note in Always mode; nothing in Never. */
export const BackupChoice = ({ mode, value, onChange }: { mode: string; value: boolean; onChange: (v: boolean) => void }) => {
    if (mode === 'never') return null;
    if (mode === 'always') {
        return <p className="text-xs text-neutral-400 mt-3"><FontAwesomeIcon icon={faArchive} /> A backup is taken first; the change runs when it&apos;s done.</p>;
    }
    return (
        <label className="flex items-center gap-2 mt-3 text-sm text-neutral-300 cursor-pointer">
            <input type="checkbox" checked={value} onChange={(e) => onChange(e.currentTarget.checked)} />
            <FontAwesomeIcon icon={faArchive} className="text-indigo-400" /> Take a backup first (recommended)
        </label>
    );
};

export const BackupPromptHost = () => {
    const [ask, setAsk] = useState<{ label: string; resolve: (a: BackupAnswer) => void } | null>(null);

    useEffect(() => {
        const open = (label: string) => new Promise<BackupAnswer>((resolve) => setAsk({ label, resolve }));
        opener = open;
        return () => {
            if (opener === open) opener = null;
        };
    }, []);

    const answer = (a: BackupAnswer) => {
        ask?.resolve(a);
        setAsk(null);
    };

    useEffect(() => {
        if (!ask) return;
        const esc = (e: KeyboardEvent) => e.key === 'Escape' && answer('cancel');
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    });

    if (!ask) return null;

    return createPortal(
        <div className="fixed inset-0 flex items-center justify-center p-4" style={{ zIndex: 1000, background: 'rgba(0,0,0,0.7)' }}
            onClick={(e) => e.target === e.currentTarget && answer('cancel')}>
            <div className="rounded-2xl p-6 w-full" style={{ maxWidth: '32rem', background: 'rgb(17,24,39)', border: '1px solid rgba(255,255,255,0.1)' }}>
                <h2 className="text-xl font-bold mb-3 text-neutral-100 flex items-center gap-3">
                    <FontAwesomeIcon icon={faArchive} className="text-indigo-400" /> Back up first?
                </h2>
                <p className="text-neutral-200 text-sm">
                    You&apos;re about to <strong>{ask.label}</strong>. A backup lets you put the server back exactly as it is now if something goes wrong.
                </p>
                <p className="text-neutral-400 text-xs mt-2">
                    The change runs as soon as the backup finishes. To stop being asked, change &quot;Backup before changes&quot; on the Mods tab (Installed).
                </p>
                <div className="mt-6 flex flex-wrap items-center justify-end gap-3">
                    <Ghost onClick={() => answer('cancel')}>Cancel</Ghost>
                    <Ghost onClick={() => answer('skip')}>Go ahead without</Ghost>
                    <Primary onClick={() => answer('backup')}>Back up first</Primary>
                </div>
            </div>
        </div>,
        document.body,
    );
};
