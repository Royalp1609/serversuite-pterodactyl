import React, { useEffect, useState } from 'react';
import Can from '@/components/elements/Can';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExclamationTriangle, faSpinner } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — crash help (#25).
 * When the server crashed after the last mod change, say what changed, name
 * the likely mod (CrashParser: loader messages, then stack frames), and offer
 * to disable it, point at the backup taken before the change, or show the
 * report. Nothing happens on its own.
 */

const Card = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.35);
`;
const GhostButton = styled.button`
    ${tw`px-3 py-1 rounded-lg text-sm font-medium flex items-center gap-2`};
    background: rgba(30, 41, 59, 0.6);
    color: rgb(203, 213, 225);
    border: 1px solid rgba(255, 255, 255, 0.06);
    &:hover { background: rgba(51, 65, 85, 0.8); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;

interface Suspect { id: string | null; name: string | null; file: string | null; evidence: string; strength: string; server_mod_id: number | null; mod_name: string | null; disabled: boolean }
interface Crash {
    change: { at: string; label: string; backup: { uuid: string; name: string | null } | null };
    source: string; at: string; summary: string | null; suspects: Suspect[]; report: string;
}

export default ({ base, refreshKey, onChanged }: { base: string; refreshKey: number; onChanged: () => void }) => {
    const [crash, setCrash] = useState<Crash | null>(null);
    const [showReport, setShowReport] = useState(false);
    const [busy, setBusy] = useState<number | 'dismiss' | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = () => http.get(`${base}/crash`).then((res) => setCrash(res.data.data)).catch(() => setCrash(null));
    useEffect(() => { load(); }, [base, refreshKey]);

    if (!crash) return null;

    const act = (key: number | 'dismiss', url: string) => {
        setBusy(key);
        setError(null);
        http.post(url, {})
            .then(() => { load(); onChanged(); })
            .catch((e) => setError(e?.response?.data?.error || e?.response?.data?.message || 'That didn\'t work.'))
            .finally(() => setBusy(null));
    };

    return (
        <Card>
            <div className="flex items-start gap-3">
                <FontAwesomeIcon icon={faExclamationTriangle} className="text-red-400 mt-1" />
                <div className="flex-1 min-w-0">
                    <p className="text-white font-semibold">The server crashed after: {crash.change.label}</p>
                    {crash.summary && <p className="text-sm text-neutral-300 mt-1 break-words">{crash.summary}</p>}
                    <p className="text-xs text-neutral-500 mt-1">From {crash.source}</p>

                    {crash.suspects.length > 0 && (
                        <div className="mt-3 flex flex-col gap-2">
                            {crash.suspects.map((s, i) => (
                                <div key={i} className="flex items-center gap-3 flex-wrap text-sm">
                                    <span className="text-neutral-100 font-medium">{s.strength === 'weak' ? 'Maybe ' : 'Likely: '}{s.mod_name || s.name || s.id || s.file}</span>
                                    <span className="text-xs text-neutral-500 break-all flex-1">{s.evidence}</span>
                                    {s.server_mod_id !== null && (s.disabled ? (
                                        <Can action={'mods.install'}>
                                            <GhostButton disabled={busy !== null} onClick={() => act(s.server_mod_id as number, `${base}/mods/${s.server_mod_id}/enable`)}>
                                                {busy === s.server_mod_id && <FontAwesomeIcon icon={faSpinner} spin />} Enable again
                                            </GhostButton>
                                        </Can>
                                    ) : (
                                        <Can action={'mods.remove'}>
                                            <GhostButton disabled={busy !== null} onClick={() => act(s.server_mod_id as number, `${base}/mods/${s.server_mod_id}/disable`)}>
                                                {busy === s.server_mod_id && <FontAwesomeIcon icon={faSpinner} spin />} Disable it
                                            </GhostButton>
                                        </Can>
                                    ))}
                                </div>
                            ))}
                        </div>
                    )}

                    {crash.change.backup && (
                        <p className="text-sm text-neutral-300 mt-3">
                            The backup from before the change is <strong>{crash.change.backup.name || crash.change.backup.uuid}</strong>: restore it from the Backups tab to undo everything.
                        </p>
                    )}
                    {error && <p className="text-sm text-red-400 mt-2">{error}</p>}

                    <div className="flex gap-2 mt-3">
                        <GhostButton onClick={() => setShowReport(!showReport)}>{showReport ? 'Hide' : 'Show'} the report</GhostButton>
                        <GhostButton disabled={busy !== null} onClick={() => act('dismiss', `${base}/crash/dismiss`)}>Dismiss</GhostButton>
                    </div>
                    {showReport && (
                        <pre className="mt-3 text-xs text-neutral-300 overflow-auto rounded-lg p-3" style={{ maxHeight: '24rem', background: 'rgba(0,0,0,0.35)', whiteSpace: 'pre' }}>{crash.report}</pre>
                    )}
                </div>
            </div>
        </Card>
    );
};
