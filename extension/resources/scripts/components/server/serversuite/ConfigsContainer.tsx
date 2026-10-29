import React, { useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSlidersH } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import ConfigsPanel from './ConfigsPanel';

/*
 * Server Suite — Configs tab (#86): the config browser and editor for games
 * without a Mods tab (Rust, Valheim), on the folders from the game profile.
 * Minecraft keeps its configs in the Mods tab; the sidebar hides this tab
 * there and the server refuses paths outside the game's folders.
 */

const Wrapper = styled.div`${tw`p-6 max-w-7xl mx-auto`};`;  // same page margins as the Mods tab
const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;

interface Game { name: string; mods_label: string; config_roots: string[] }

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const [game, setGame] = useState<Game | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!uuid) return;
        http.get(`/extensions/serversuite/${uuid}/game`)
            .then((res) => setGame(res.data.data as Game))
            .catch(() => setError('Could not load this server\'s game.'));
    }, [uuid]);

    return (
        <Wrapper>
            <Panel>
                <div className="flex items-center gap-3">
                    <FontAwesomeIcon icon={faSlidersH} className="text-2xl text-indigo-400" />
                    <div>
                        <h1 className="text-2xl font-bold text-white">Configs</h1>
                        <p className="text-xs text-neutral-500">
                            {game
                                ? `${game.name} config files in ${game.config_roots.map((r) => r.replace(/^\//, '')).join(', ')}. A folder shows up once the server or a ${game.mods_label.toLowerCase() === 'plugins' ? 'plugin' : 'mod'} has written it.`
                                : error || 'Loading…'}
                        </p>
                    </div>
                </div>
            </Panel>
            {uuid && game && <ConfigsPanel uuid={uuid} roots={game.config_roots} />}
        </Wrapper>
    );
};
