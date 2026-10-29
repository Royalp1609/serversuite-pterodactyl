import React, { useEffect, useState } from 'react';
import { NavLink, useLocation, useRouteMatch } from 'react-router-dom';
import { useStoreState } from 'easy-peasy';
import http from '@/api/http';
import Can from '@/components/elements/Can';
import { ServerContext } from '@/state/server';
import ReinstallScreen from './ReinstallScreen';
import routes from '@/routers/routes';
import blueprintRoutes from '@blueprint/extends/routers/routes';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import {
    faArchive, faBell, faBoxOpen, faClock, faCodeBranch, faCog, faCube, faDatabase, faExclamationTriangle, faExternalLinkAlt, faFolder,
    faGlobe, faHistory, faNetworkWired, faPlay, faProjectDiagram, faPuzzlePiece, faSlidersH, faTerminal, faUserFriends, faUsers,
} from '@fortawesome/free-solid-svg-icons';

/*
 * Server Suite — the server menu as a grouped left sidebar, like Pelican's
 * (General / Management / Configuration). Mounted in Blueprint's
 * BeforeSubNavigation slot; serversuite.css hides Pterodactyl's top link row
 * and makes room on the left while body has .ss-sidebar (wide screens only;
 * narrow ones keep the top row). Links, permissions and per-egg extension
 * routes are filtered exactly as Pterodactyl's own menu does. Routes this
 * file doesn't know (a newer panel, other extensions) still show, under
 * "Other".
 */

type Route = { path: string; name?: string; permission?: string | string[] | null; exact?: boolean; adminOnly?: boolean; identifier?: string };
type Item = { key: string; route: Route; label: string; icon: IconDefinition };

// Pterodactyl's own routes, by path.
const NATIVE: Record<string, { group: string; label?: string; icon: IconDefinition }> = {
    '/': { group: 'General', icon: faTerminal },
    '/activity': { group: 'General', icon: faHistory },
    '/files': { group: 'Management', icon: faFolder },
    '/databases': { group: 'Management', icon: faDatabase },
    '/network': { group: 'Management', icon: faNetworkWired },
    '/schedules': { group: 'Configuration', icon: faClock },
    '/users': { group: 'Configuration', icon: faUserFriends },
    '/startup': { group: 'Configuration', icon: faPlay },
    '/settings': { group: 'Configuration', icon: faCog },
};
// Server Suite's Backups tab replaces the native one (serversuite.css hides it too).
const HIDDEN_NATIVE = ['/backups'];

// Ours, by path.
const SUITE: Record<string, { group: string; icon: IconDefinition }> = {
    '/errors': { group: 'General', icon: faExclamationTriangle },
    '/mod-manager': { group: 'Management', icon: faCube },
    '/addons': { group: 'Management', icon: faCube },
    '/modpacks': { group: 'Management', icon: faBoxOpen },
    '/configs': { group: 'Management', icon: faSlidersH },
    '/backup-rotation': { group: 'Management', icon: faArchive },
    '/subdomains': { group: 'Management', icon: faGlobe },
    '/players': { group: 'Management', icon: faUsers },
    '/velocity': { group: 'Management', icon: faProjectDiagram },
    '/properties': { group: 'Configuration', icon: faSlidersH },
    '/versions': { group: 'Configuration', icon: faCodeBranch },
    '/notifications': { group: 'Configuration', icon: faBell },
};

// Tabs that only some games have (#78): path => feature in the server's game profile.
// Tabs not listed (Backups, Subdomains, Notifications) work on every game.
const GAME_FEATURE: Record<string, string> = {
    '/errors': 'errors',
    '/mod-manager': 'mods',
    '/addons': 'addons',   // Bedrock Dedicated Server only (#93)
    '/modpacks': 'modpacks',
    '/configs': 'configs',
    '/versions': 'versions',
    '/properties': 'properties',
    '/players': 'players',
    '/velocity': 'network',
};

// Order inside each group, as on Pelican.
const ORDER = [
    '/', '/errors', '/activity',
    '/files', '/mod-manager', '/addons', '/configs', '/modpacks', '/databases', '/backup-rotation', '/network', '/subdomains', '/players', '/velocity',
    '/schedules', '/properties', '/versions', '/users', '/startup', '/notifications', '/settings',
];
const GROUPS = ['General', 'Management', 'Configuration', 'Other'];

/** Which extensions limit their routes to some eggs (Blueprint's own endpoint, as its menu uses). */
const useExtensionEggs = (ids: string[]) => {
    const [eggs, setEggs] = useState<Record<string, string[]>>(() => Object.fromEntries(ids.map((id) => [id, ['-1']])));
    useEffect(() => {
        let gone = false;
        (async () => {
            const out: Record<string, string[]> = {};
            for (const id of ids) {
                try {
                    const res = await fetch(`/api/client/extensions/blueprint/eggs?${new URLSearchParams({ id })}`);
                    out[id] = (await res.json()) as string[];
                } catch {
                    out[id] = ['-1'];
                }
            }
            if (!gone) setEggs(out);
        })();
        return () => { gone = true; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ids.join(',')]);
    return eggs;
};

/**
 * The Server Suite features the server's game has (Minecraft: all of them).
 * null while loading: game-specific tabs wait, so a Rust server never flashes
 * the Minecraft tabs. If the check fails they show, and the server still
 * refuses what doesn't apply.
 */
const useGameFeatures = (uuid?: string) => {
    const [features, setFeatures] = useState<string[] | null>(null);
    useEffect(() => {
        if (!uuid) return;
        let gone = false;
        setFeatures(null);
        http.get(`/extensions/serversuite/${uuid}/game`)
            .then((res) => !gone && setFeatures(res.data.data.features as string[]))
            .catch(() => !gone && setFeatures(Object.values(GAME_FEATURE)));
        return () => { gone = true; };
    }, [uuid]);
    return features;
};

const ServerSidebar = () => {
    const rootAdmin = useStoreState((state: any) => state.user.data?.rootAdmin) as boolean;
    const serverId = ServerContext.useStoreState((state) => state.server.data?.internalId);
    const serverEgg = ServerContext.useStoreState((state) => (state.server.data as any)?.BlueprintFramework?.eggId);
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const features = useGameFeatures(uuid);
    const match = useRouteMatch<{ id: string }>();
    const ext = blueprintRoutes.server as Route[];
    const ids = [...new Set(ext.map((r) => r.identifier || ''))];
    const eggs = useExtensionEggs(ids);

    useEffect(() => {
        document.body.classList.add('ss-sidebar');
        // Pterodactyl's top bar scrolls away; keep the sidebar right under it.
        const place = () => document.body.style.setProperty('--ss-top', `${Math.max(0, 56 - window.scrollY)}px`);
        place();
        window.addEventListener('scroll', place, { passive: true });
        return () => {
            document.body.classList.remove('ss-sidebar');
            window.removeEventListener('scroll', place);
        };
    }, []);

    const to = (path: string) => (path === '/' ? match.url : `${match.url.replace(/\/*$/, '')}/${path.replace(/^\/+/, '')}`);

    const items: Record<string, Item[]> = { General: [], Management: [], Configuration: [], Other: [] };
    (routes.server as Route[])
        .filter((r) => !!r.name && !HIDDEN_NATIVE.includes(r.path))
        .forEach((r) => {
            const n = NATIVE[r.path];
            items[n?.group || 'Other'].push({ key: `p${r.path}`, route: r, label: n?.label || r.name || '', icon: n?.icon || faPuzzlePiece });
        });
    ext.filter((r) => !!r.name)
        .filter((r) => (r.adminOnly ? rootAdmin : true))
        .filter((r) => {
            const list = eggs[r.identifier || ''] || ['-1'];
            return list.includes('-1') || list.includes(String(serverEgg));
        })
        .filter((r) => {
            const feature = r.identifier === 'serversuite' ? GAME_FEATURE[r.path] : undefined;
            return !feature || (features !== null && features.includes(feature));
        })
        .forEach((r) => {
            const s = r.identifier === 'serversuite' ? SUITE[r.path] : undefined;
            items[s?.group || 'Other'].push({ key: `b${r.identifier}${r.path}`, route: r, label: r.name || '', icon: s?.icon || faPuzzlePiece });
        });
    const rank = (i: Item) => {
        const n = ORDER.indexOf(i.route.path);
        return n === -1 ? ORDER.length : n;
    };

    const link = (i: Item) => {
        const a = (
            <NavLink key={i.key} to={to(i.route.path)} exact={i.route.path === '/' || i.route.exact} className={'ss-side-link'} activeClassName={'active'}>
                <FontAwesomeIcon icon={i.icon} fixedWidth />
                <span>{i.label}</span>
            </NavLink>
        );
        return i.route.permission ? <Can key={i.key} action={i.route.permission} matchAny>{a}</Can> : a;
    };

    return (
        <nav className={'ss-side'} aria-label={'Server'}>
            {GROUPS.filter((g) => items[g].length > 0).map((g) => (
                <div key={g} className={'ss-side-group'}>
                    <p className={'ss-side-title'}>{g}</p>
                    {items[g].sort((a, b) => rank(a) - rank(b)).map(link)}
                </div>
            ))}
            {rootAdmin && serverId && (
                <div className={'ss-side-group ss-side-bottom'}>
                    <a href={`/admin/servers/view/${serverId}`} target={'_blank'} rel={'noreferrer'} className={'ss-side-link'}>
                        <FontAwesomeIcon icon={faExternalLinkAlt} fixedWidth />
                        <span>Open in admin</span>
                    </a>
                </div>
            )}
        </nav>
    );
};

/**
 * The slot is shared with the account pages; only server pages get the
 * sidebar, and the reinstall screen (#61), which sits outside the sidebar
 * so it shows on narrow screens too.
 */
export default () => {
    const { pathname } = useLocation();
    return pathname.startsWith('/server/') ? <><ServerSidebar /><ReinstallScreen /></> : null;
};
