import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useHistory } from 'react-router-dom';
import Can from '@/components/elements/Can';
import http from '@/api/http';
import { ServerContext } from '@/state/server';
import { SocketEvent } from '@/components/server/events';
import useWebsocketEvent from '@/plugins/useWebsocketEvent';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheckCircle, faCircle, faExclamationTriangle, faPlay, faSpinner, faTerminal } from '@fortawesome/free-solid-svg-icons';

/*
 * Server Suite — full-screen reinstall progress (#61).
 * Shown on every server page while the server reinstalls, however that was
 * started (Versions tab, Settings → Reinstall, an admin, a modpack switch):
 * it follows the server's install status, not a button. Stages: reinstalling
 * → starting → done, then it goes to the console. If the install fails it
 * stays up with the error. Mounted next to the sidebar (Blueprint's
 * BeforeSubNavigation slot) and rendered into <body>, so neither the
 * sidebar's CSS nor its stacking order can hide it on any screen size.
 *
 * Wings sends the installer's output only to panel admins
 * (admin.websocket.install), so others see the stage and the timer without
 * the log. Install events may not reach them either, so the status is also
 * polled from the client API.
 */

type Stage = 'installing' | 'starting' | 'failed' | null;

const FAILED = ['install_failed', 'reinstall_failed'];
const MAX_LINES = 400;
// eslint-disable-next-line no-control-regex
const stripAnsi = (s: string) => s.replace(/\u001b\[[0-9;]*[A-Za-z]/g, '');
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
    const [lines, setLines] = useState<string[]>([]);
    const [startedAt, setStartedAt] = useState<number>(Date.now());
    const [now, setNow] = useState(Date.now());
    const [offlineSince, setOfflineSince] = useState<number | null>(null);
    const [packRunning, setPackRunning] = useState(false);
    const [busy, setBusy] = useState(false);
    const logRef = useRef<HTMLPreElement>(null);

    // Stage from the server's status (also when the page opens mid-reinstall).
    useEffect(() => {
        if (!uuid) return;
        if (status === 'installing') {
            if (stage !== 'installing') {
                let t = Date.now();
                try {
                    t = Number(sessionStorage.getItem(startKey(uuid))) || t;
                    sessionStorage.setItem(startKey(uuid), String(t));
                } catch {
                    // storage off: time from now
                }
                setStartedAt(t);
                setLines([]);
                setStage('installing');
            }
        } else if (status && FAILED.includes(status)) {
            setStage('failed');
        } else if (status === null && stage === 'installing') {
            try { sessionStorage.removeItem(startKey(uuid)); } catch { /* ignore */ }
            setStage('starting');
            setOfflineSince(null);
            // A modpack install (#23 stage 2) carries on after the reinstall and starts nothing.
            http.get(`/extensions/serversuite/${uuid}/modpacks`)
                .then((res) => setPackRunning(res.data.data?.status?.status === 'installing'))
                .catch(() => setPackRunning(false));
        }
    }, [uuid, status]);

    // Poll the status: install events only reach panel admins.
    useEffect(() => {
        if (!uuid) return;
        const poll = () => http.get(`/api/client/servers/${uuid}`)
            .then((res) => {
                const s = res.data?.attributes?.status ?? null;
                if (s !== status) setServerFromState((d) => ({ ...d, status: s }));
            })
            .catch(() => undefined);
        const t = setInterval(poll, stage === 'installing' ? 5000 : 20000);
        return () => clearInterval(t);
    }, [uuid, status, stage]);

    useWebsocketEvent(SocketEvent.INSTALL_OUTPUT, (line) => {
        setLines((cur) => [...cur, stripAnsi(line)].slice(-MAX_LINES));
    });

    useEffect(() => {
        if (!stage) return;
        const t = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(t);
    }, [stage]);

    useEffect(() => {
        if (logRef.current) logRef.current.scrollTop = logRef.current.scrollHeight;
    }, [lines]);

    // After the install: wait for the server to run, then go to the console.
    useEffect(() => {
        if (stage !== 'starting') return;
        if (power === 'running') {
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
    const idle = stage === 'starting' && power === 'offline' && offlineSince !== null && now - offlineSince > 15000;

    const steps: Array<{ key: string; label: string }> = [
        { key: 'installing', label: 'Reinstalling' },
        { key: 'starting', label: 'Starting the server' },
        { key: 'done', label: 'Done' },
    ];
    const at = stage === 'installing' || stage === 'failed' ? 0 : 1;

    return createPortal(
        <div style={{ position: 'fixed', inset: 0, zIndex: 1000, background: 'rgba(2, 6, 23, 0.94)', overflowY: 'auto' }}>
            <div className="max-w-4xl mx-auto px-4 py-10">
                <h2 className="text-2xl font-bold text-white mb-1">
                    {stage === 'failed' ? 'The reinstall failed' : packRunning && stage === 'starting' ? 'Reinstall finished' : 'Reinstalling the server'}
                </h2>
                <p className="text-sm text-neutral-400 mb-6">
                    {stage === 'failed'
                        ? 'The install script stopped with an error, so the server has no working files.'
                        : `Running for ${clock(now - startedAt)}. You can leave this page; the reinstall carries on.`}
                </p>

                <ol className="flex flex-wrap gap-6 mb-6 text-sm">
                    {steps.map((s, i) => (
                        <li key={s.key} className="flex items-center gap-2" style={{ color: i < at ? 'rgb(52,211,153)' : i === at ? 'white' : 'rgb(115,115,115)' }}>
                            <FontAwesomeIcon
                                icon={stage === 'failed' && i === 0 ? faExclamationTriangle : i < at ? faCheckCircle : i === at ? faSpinner : faCircle}
                                spin={stage !== 'failed' && i === at}
                                style={stage === 'failed' && i === 0 ? { color: 'rgb(248,113,113)' } : undefined}
                            />
                            {s.label}
                        </li>
                    ))}
                </ol>

                {stage === 'starting' && packRunning && (
                    <div className="text-sm rounded-lg p-3 mb-4" style={{ background: 'rgba(99,102,241,0.12)', color: 'rgb(199,210,254)' }}>
                        The modpack install carries on now (downloads and configs) and leaves the server stopped. Follow it on the Modpacks tab.
                        <div className="mt-3 flex gap-2">
                            <Link to={`/server/${id}/modpacks`} onClick={close} className="px-3 py-2 rounded-lg text-white" style={{ background: 'rgb(99,102,241)' }}>Open Modpacks</Link>
                        </div>
                    </div>
                )}

                {stage === 'starting' && !packRunning && (
                    <div className="text-sm text-neutral-300 mb-4">
                        {idle ? (
                            <>
                                The install finished, but nothing has started the server.{' '}
                                <Can action={'control.start'} renderOnError={<span className="text-neutral-400">You don&apos;t have permission to start it.</span>}>
                                    <button onClick={start} disabled={busy} className="ml-2 px-3 py-2 rounded-lg text-white inline-flex items-center gap-2" style={{ background: 'rgb(16,185,129)' }}>
                                        <FontAwesomeIcon icon={busy ? faSpinner : faPlay} spin={busy} /> Start the server
                                    </button>
                                </Can>
                            </>
                        ) : (
                            <><FontAwesomeIcon icon={faSpinner} spin /> Install finished. Waiting for the server to start ({power || 'unknown'})…</>
                        )}
                    </div>
                )}

                {stage === 'failed' && (
                    <div className="text-sm rounded-lg p-3 mb-4" style={{ background: 'rgba(239,68,68,0.12)', color: 'rgb(252,165,165)' }}>
                        Check the end of the install log below{lines.length === 0 ? ' (panel admins see it; ask one if it is empty)' : ''}.
                        If this was a version or loader switch, the backup Server Suite took first has the old server: restore it from Backups
                        once the server can be used again (a panel admin can reinstall or restore it now).
                    </div>
                )}

                <pre
                    ref={logRef}
                    className="text-xs rounded-lg p-3 mb-4"
                    style={{ background: 'rgb(15,23,42)', color: 'rgb(203,213,225)', height: '22rem', overflow: 'auto', whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}
                >
                    {lines.length > 0
                        ? (stage === 'failed' ? lines.slice(-60) : lines).join('\n')
                        : 'The installer\'s output is sent to panel admins only, so it isn\'t shown here. The stages above still update.'}
                </pre>

                <div className="flex flex-wrap gap-2">
                    <Link to={`/server/${id}`} onClick={close} className="px-3 py-2 rounded-lg text-sm inline-flex items-center gap-2" style={{ background: 'rgba(30,41,59,0.8)', color: 'rgb(203,213,225)' }}>
                        <FontAwesomeIcon icon={faTerminal} /> Console
                    </Link>
                    {stage === 'failed' && (
                        <Link to={`/server/${id}/backup-rotation`} onClick={close} className="px-3 py-2 rounded-lg text-sm" style={{ background: 'rgba(30,41,59,0.8)', color: 'rgb(203,213,225)' }}>
                            Backups
                        </Link>
                    )}
                    {stage !== 'installing' && (
                        <button onClick={close} className="px-3 py-2 rounded-lg text-sm" style={{ color: 'rgb(148,163,184)' }}>Close</button>
                    )}
                </div>
            </div>
        </div>,
        document.body,
    );
};
