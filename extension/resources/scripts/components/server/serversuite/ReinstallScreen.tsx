import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useHistory } from 'react-router-dom';
import Can from '@/components/elements/Can';
import http from '@/api/http';
import { ServerContext } from '@/state/server';
import ServerInstallSvg from '@/assets/images/server_installing.svg';
import ServerRestoreSvg from '@/assets/images/server_restore.svg';
import ServerErrorSvg from '@/assets/images/server_error.svg';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheckCircle, faCircle, faExclamationTriangle, faPlay, faTerminal } from '@fortawesome/free-solid-svg-icons';

/*
 * Server Suite — full-screen reinstall and restore progress (#61).
 * Shown on every server page while the server reinstalls or restores a
 * backup, however that was started (Versions tab, Settings → Reinstall, an
 * admin, a modpack switch, either Backups page): it follows the server's
 * status, not a button. Reinstall: reinstalling → starting → done, then it
 * goes to the console. Restore: restoring → done. If an install fails it
 * stays up with the error. Mounted next to the sidebar (Blueprint's
 * BeforeSubNavigation slot) and rendered into <body>, so neither the
 * sidebar's CSS nor its stacking order can hide it on any screen size.
 *
 * Install and restore events may not reach subusers over the websocket, so
 * the status is also polled from the client API.
 */

type Stage = 'installing' | 'starting' | 'failed' | 'restoring' | 'restored' | null;

const FAILED = ['install_failed', 'reinstall_failed'];
const clock = (ms: number) => {
    const s = Math.max(0, Math.floor(ms / 1000));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
};
const startKey = (uuid: string) => `serversuite.reinstall.${uuid}`;

export default () => {
    const history = useHistory();
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const id = ServerContext.useStoreState((state) => state.server.data?.id);
    const status = ServerContext.useStoreState((state) => state.server.data?.status || null);
    const power = ServerContext.useStoreState((state) => state.status.value);
    const socket = ServerContext.useStoreState((state) => state.socket.instance);
    const setServerFromState = ServerContext.useStoreActions((actions) => actions.server.setServerFromState);

    const [stage, setStage] = useState<Stage>(null);
    const [startedAt, setStartedAt] = useState<number>(Date.now());
    const [now, setNow] = useState(Date.now());
    const [offlineSince, setOfflineSince] = useState<number | null>(null);
    const [packRunning, setPackRunning] = useState(false);
    const [busy, setBusy] = useState(false);

    /** Start the timer, or carry on the one from before a page change. */
    const begin = (next: Stage) => {
        if (!uuid || stage === next) return;
        let t = Date.now();
        try {
            t = Number(sessionStorage.getItem(startKey(uuid))) || t;
            sessionStorage.setItem(startKey(uuid), String(t));
        } catch {
            // storage off: time from now
        }
        setStartedAt(t);
        setStage(next);
    };
    const end = () => {
        try { if (uuid) sessionStorage.removeItem(startKey(uuid)); } catch { /* ignore */ }
    };

    // Stage from the server's status (also when the page opens mid-way).
    useEffect(() => {
        if (!uuid) return;
        if (status === 'installing') {
            begin('installing');
        } else if (status === 'restoring_backup') {
            begin('restoring');
        } else if (status && FAILED.includes(status)) {
            end();
            setStage('failed');
        } else if (status === null && stage === 'restoring') {
            end();
            setStage('restored');
        } else if (status === null && stage === 'installing') {
            end();
            setStage('starting');
            setOfflineSince(null);
            // A modpack install (#23 stage 2) carries on after the reinstall and starts nothing.
            http.get(`/extensions/serversuite/${uuid}/modpacks`)
                .then((res) => setPackRunning(res.data.data?.status?.status === 'installing'))
                .catch(() => setPackRunning(false));
        }
    }, [uuid, status]);

    // Poll the status: install and restore events may not reach subusers.
    useEffect(() => {
        if (!uuid) return;
        const poll = () => http.get(`/api/client/servers/${uuid}`)
            .then((res) => {
                const s = res.data?.attributes?.status ?? null;
                if (s !== status) setServerFromState((d) => ({ ...d, status: s }));
            })
            .catch(() => undefined);
        const t = setInterval(poll, stage === 'installing' || stage === 'restoring' ? 5000 : 20000);
        return () => clearInterval(t);
    }, [uuid, status, stage]);

    useEffect(() => {
        if (!stage) return;
        const t = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(t);
    }, [stage]);

    // After the install: wait for the server to run, then go to the console.
    // After a restore: stay (the Mods tab has news), but say it runs again or offer Start (#69).
    useEffect(() => {
        if (stage !== 'starting' && stage !== 'restored') return;
        if (power === 'running' && stage === 'starting') {
            setStage(null);
            history.push(`/server/${id}`);
        } else if (power === 'offline' && offlineSince === null) {
            setOfflineSince(Date.now());
        } else if (power !== 'offline') {
            setOfflineSince(null);
        }
    }, [stage, power]);

    if (!stage || !uuid) return null;

    const start = () => {
        setBusy(true);
        socket?.send('set state', 'start');
        setTimeout(() => setBusy(false), 3000);
    };
    const close = () => setStage(null);
    // Not starting on its own: whoever reinstalled it doesn't start it (Settings → Reinstall, an admin).
    const idle = (stage === 'starting' || stage === 'restored') && power === 'offline' && offlineSince !== null && now - offlineSince > 15000;
    const restore = stage === 'restoring' || stage === 'restored';

    const steps = restore
        ? ['Restoring the backup', 'Done']
        : ['Reinstalling', 'Starting the server', 'Done'];
    const at = { installing: 0, failed: 0, restoring: 0, starting: 1, restored: 1 }[stage];
    const image = stage === 'failed' ? ServerErrorSvg : restore ? ServerRestoreSvg : ServerInstallSvg;
    const title = stage === 'failed' ? 'The reinstall failed'
        : stage === 'restoring' ? 'Restoring a backup'
            : stage === 'restored' ? 'Backup restored'
                : packRunning && stage === 'starting' ? 'Reinstall finished' : 'Reinstalling the server';

    return createPortal(
        <div style={{ position: 'fixed', inset: 0, zIndex: 1000, background: 'rgba(2, 6, 23, 0.94)', overflowY: 'auto', display: 'flex' }}>
            {/* margin: auto centres it both ways and still scrolls when it's taller than the screen */}
            <div className="w-full max-w-xl px-4 py-10 flex flex-col items-center text-center" style={{ margin: 'auto' }}>
                <img src={image} alt="" style={{ width: '100%', maxWidth: '16rem', height: 'auto' }} className="mb-6" />
                <h2 className="text-2xl font-bold text-white mb-1">{title}</h2>
                <p className="text-sm text-neutral-400 mb-6">
                    {stage === 'failed'
                        ? 'The install script stopped with an error, so the server has no working files.'
                        : stage === 'restored'
                            ? `Took ${clock(now - startedAt)}. Server Suite checks the mod list against the backup in a few seconds; the Mods tab says what changed.`
                            : `Running for ${clock(now - startedAt)}. You can leave this page; it carries on.`}
                </p>

                <ol className="flex flex-wrap justify-center gap-6 mb-6 text-sm">
                    {steps.map((label, i) => (
                        <li key={label} className="flex items-center gap-2" style={{ color: i < at || stage === 'restored' ? 'rgb(52,211,153)' : i === at ? 'white' : 'rgb(115,115,115)' }}>
                            <FontAwesomeIcon
                                icon={stage === 'failed' && i === 0 ? faExclamationTriangle : i < at || stage === 'restored' ? faCheckCircle : faCircle}
                                style={stage === 'failed' && i === 0 ? { color: 'rgb(248,113,113)' } : i > at ? { opacity: 0.4 } : undefined}
                            />
                            {label}
                        </li>
                    ))}
                </ol>

                {stage === 'starting' && packRunning && (
                    <div className="text-sm rounded-lg p-3 mb-4" style={{ background: 'rgba(99,102,241,0.12)', color: 'rgb(199,210,254)' }}>
                        The modpack install carries on now (downloads and configs) and leaves the server stopped. Follow it on the Modpacks tab.
                        <div className="mt-3 flex justify-center gap-2">
                            <Link to={`/server/${id}/modpacks`} onClick={close} className="px-3 py-2 rounded-lg text-white" style={{ background: 'rgb(99,102,241)' }}>Open Modpacks</Link>
                        </div>
                    </div>
                )}

                {stage === 'restored' && power === 'running' && (
                    <p className="text-sm mb-4" style={{ color: 'rgb(52,211,153)' }}>The server is running again.</p>
                )}

                {((stage === 'starting' && !packRunning) || (stage === 'restored' && idle)) && (
                    <div className="text-sm text-neutral-300 mb-4 w-full">
                        {idle ? (
                            <>
                                {stage === 'restored' ? 'The restore finished' : 'The install finished'}, but nothing has started the server.{' '}
                                <Can action={'control.start'} renderOnError={<span className="text-neutral-400">You don&apos;t have permission to start it.</span>}>
                                    <button onClick={start} disabled={busy} className="ml-2 px-3 py-2 rounded-lg text-white inline-flex items-center gap-2" style={{ background: 'rgb(16,185,129)', opacity: busy ? 0.6 : 1 }}>
                                        <FontAwesomeIcon icon={faPlay} /> Start the server
                                    </button>
                                </Can>
                            </>
                        ) : (
                            <>Install finished. Waiting for the server to start ({power || 'unknown'}).</>
                        )}
                    </div>
                )}

                {stage === 'failed' && (
                    <div className="text-sm rounded-lg p-3 mb-4" style={{ background: 'rgba(239,68,68,0.12)', color: 'rgb(252,165,165)' }}>
                        A panel admin can see the install log in the console. If this was a version or loader switch, the backup Server Suite
                        took first has the old server: restore it from Backups once the server can be used again (a panel admin can reinstall
                        or restore it now).
                    </div>
                )}

                <div className="flex flex-wrap justify-center gap-2">
                    <Link to={`/server/${id}`} onClick={close} className="px-3 py-2 rounded-lg text-sm inline-flex items-center gap-2" style={{ background: 'rgba(30,41,59,0.8)', color: 'rgb(203,213,225)' }}>
                        <FontAwesomeIcon icon={faTerminal} /> Console
                    </Link>
                    {stage === 'failed' && (
                        <Link to={`/server/${id}/backup-rotation`} onClick={close} className="px-3 py-2 rounded-lg text-sm" style={{ background: 'rgba(30,41,59,0.8)', color: 'rgb(203,213,225)' }}>
                            Backups
                        </Link>
                    )}
                    {stage === 'restored' && (
                        <Link to={`/server/${id}/mod-manager`} onClick={close} className="px-3 py-2 rounded-lg text-sm" style={{ background: 'rgba(30,41,59,0.8)', color: 'rgb(203,213,225)' }}>
                            Mods
                        </Link>
                    )}
                    {stage !== 'installing' && stage !== 'restoring' && (
                        <button onClick={close} className="px-3 py-2 rounded-lg text-sm" style={{ color: 'rgb(148,163,184)' }}>Close</button>
                    )}
                </div>
            </div>
        </div>,
        document.body,
    );
};
