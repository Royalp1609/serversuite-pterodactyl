import http from '@/api/http';
import { askBackup, BackupAnswer } from './BackupPrompt';

/*
 * Server Suite — backup before a mod change (#24).
 * Ask the server for a clearance before installing, updating or removing:
 * per its setting it goes straight ahead (never), asks the user (ask), or
 * takes a backup and waits for it (always, or ask + yes). The change is then
 * sent with the token; the backend refuses it without one.
 */

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/** The clearance token (null when the server never backs up first); throws to cancel. */
/**
 * `preset` is the answer when the screen already asked (a "Take a backup
 * first" tick box in the Remove or Install dialog); otherwise, in Ask
 * mode, the BackupPrompt dialog asks.
 */
export const getClearance = async (base: string, label: string, onStatus: (text: string | null) => void, preset?: BackupAnswer): Promise<string | null> => {
    const mode = (await http.get(`${base}/prechange`)).data.data.mode as string;
    if (mode === 'never') return null;

    let take: boolean | undefined;
    if (mode === 'ask') {
        // The in-panel dialog (BackupPrompt.tsx), not the browser's confirm().
        const answer = preset ?? await askBackup(label);
        if (answer === 'cancel') throw new Error('Cancelled.');
        take = answer === 'backup';
    }

    let r;
    try {
        r = (await http.post(`${base}/prechange`, { label, take })).data.data;
    } catch (e: any) {
        throw new Error(`No backup, nothing changed: ${e?.response?.data?.message || e?.response?.data?.error || e.message}. Free up a backup slot, or set "Backup before changes" to Never.`);
    }
    if (r.status === 'started') {
        onStatus(`Taking backup "${r.backup}" before you ${label}. The change runs when it's done.`);
        for (let i = 0; i < 450; i++) {
            await sleep(4000);
            const state = (await http.get(`${base}/prechange/${r.token}`)).data.data.state;
            if (state === 'ready') break;
            if (state !== 'waiting') { onStatus(null); throw new Error(`The backup failed, so "${label}" was not run.`); }
        }
        onStatus(null);
    }

    return r.token;
};
