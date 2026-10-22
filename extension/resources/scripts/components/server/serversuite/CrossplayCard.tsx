import React, { useEffect, useState } from 'react';
import Can from '@/components/elements/Can';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faMobileAlt, faSpinner } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { getClearance } from './prechange';

/*
 * Server Suite — Bedrock crossplay (#28): Geyser + Floodgate in one click.
 * From 27 October 2026 Bedrock only connects over NetherNet, so "Set up
 * connections" puts Geyser on the Java port with NetherNet and external
 * signaling; Geyser then logs the address players join with.
 */

const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const GhostButton = styled.button`
    ${tw`px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2`};
    background: rgba(30, 41, 59, 0.6);
    color: rgb(203, 213, 225);
    border: 1px solid rgba(255, 255, 255, 0.06);
    &:hover { background: rgba(51, 65, 85, 0.8); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;

interface Status {
    supported: boolean; reason: string | null; installed: Record<string, boolean>; config_found: boolean;
    bedrock_port: number | null; transport: string | null; signaling: string | null; ready: boolean;
    address: string | null; join_address: string | null; java_port: number | null;
}

export default ({ base, onChanged }: { base: string; onChanged: () => void }) => {
    const [st, setSt] = useState<Status | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [msg, setMsg] = useState<string | null>(null);

    const load = () => http.get(`${base}/crossplay`).then((res) => setSt(res.data.data)).catch(() => setSt(null));
    useEffect(() => { load(); }, [base]);

    if (!st || !st.supported) return null;
    const missing = Object.entries(st.installed).filter(([, v]) => !v).map(([k]) => k);

    const install = async () => {
        setBusy('install');
        setMsg(null);
        try {
            const clearance = await getClearance(base, 'add Bedrock crossplay', setMsg);
            const res = await http.post(`${base}/crossplay/install`, { clearance });
            const d = res.data.data;
            setMsg(`Installed ${d.installed.join(', ') || 'nothing'}${d.failed.length ? `. Failed: ${d.failed.join('; ')}` : ''}. Start the server once so Geyser writes its config, then set up connections.`);
            load();
            onChanged();
        } catch (e: any) {
            setMsg(e?.response?.data?.error || e.message || 'Install failed.');
        } finally {
            setBusy(null);
        }
    };

    const configure = () => {
        setBusy('configure');
        setMsg(null);
        http.post(`${base}/crossplay/configure`, {})
            .then((res) => { setMsg(`Geyser set to ${res.data.data.transport} on port ${res.data.data.port}. Restart the server to apply it.`); load(); })
            .catch((e) => setMsg(e?.response?.data?.error || 'Could not set up Geyser.'))
            .finally(() => setBusy(null));
    };

    return (
        <Panel>
            <div className="flex items-center gap-3 flex-wrap">
                <FontAwesomeIcon icon={faMobileAlt} className="text-xl text-indigo-400" />
                <div className="flex-1 min-w-0">
                    <p className="text-white font-semibold">Bedrock crossplay</p>
                    <p className="text-xs text-neutral-400">
                        {missing.length > 0 ? `Let Bedrock players (phones, consoles, Windows) join with Geyser and Floodgate.`
                            : st.ready ? `Ready${st.join_address ? `: players join at ${st.join_address}` : st.address ? `: ${st.address}, port ${st.bedrock_port}` : ''}.`
                                : st.config_found ? 'Installed. Set up connections so Bedrock clients can reach it (NetherNet, needed from 27 October 2026).'
                                    : 'Installed. Start the server once so Geyser writes its config.'}
                    </p>
                </div>
                <Can action={'mods.install'}>
                    {missing.length > 0 && (
                        <GhostButton disabled={busy !== null} onClick={install}>{busy === 'install' && <FontAwesomeIcon icon={faSpinner} spin />} Add Bedrock crossplay</GhostButton>
                    )}
                    {missing.length === 0 && st.config_found && !st.ready && (
                        <GhostButton disabled={busy !== null} onClick={configure}>{busy === 'configure' && <FontAwesomeIcon icon={faSpinner} spin />} Set up connections</GhostButton>
                    )}
                </Can>
            </div>
            {msg && <p className="text-sm text-neutral-300 mt-3">{msg}</p>}
        </Panel>
    );
};
