import React, { useEffect, useState } from 'react';
import Modal from '@/components/elements/Modal';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArchive } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — the "back up first?" question before a mod change (#24),
 * as an in-panel dialog like Pterodactyl's EULA prompt instead of the
 * browser's confirm() boxes. Mount <BackupPromptHost /> once on a page;
 * askBackup() (used by getClearance in prechange.ts) opens it and resolves
 * with the answer. Closing it (Escape, background) cancels.
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

    return (
        <Modal visible={ask !== null} onDismissed={() => answer('cancel')} closeOnBackground closeOnEscape>
            <h2 className="text-2xl mb-4 text-neutral-100 flex items-center gap-3">
                <FontAwesomeIcon icon={faArchive} className="text-indigo-400" /> Back up first?
            </h2>
            <p className="text-neutral-200">
                You&apos;re about to <strong>{ask?.label}</strong>. A backup lets you put the server back exactly as it is now if something goes wrong.
            </p>
            <p className="text-neutral-400 text-sm mt-2">
                The change runs as soon as the backup finishes. To stop being asked, change &quot;Backup before changes&quot; on the Mods tab (Installed).
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-end gap-3">
                <Ghost onClick={() => answer('cancel')}>Cancel</Ghost>
                <Ghost onClick={() => answer('skip')}>Go ahead without</Ghost>
                <Primary onClick={() => answer('backup')}>Back up first</Primary>
            </div>
        </Modal>
    );
};
