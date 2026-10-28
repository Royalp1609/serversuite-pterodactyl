import React, { useEffect, useState, useCallback } from 'react';
import Can from '@/components/elements/Can';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faGlobe, faPlus, faSpinner, faTrash, faSync, faExclamationTriangle, faSave, faCopy, faSignal,
} from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

/*
 * Server Suite — Subdomains tab (#14).
 * Claim <name>.<domain> for this server: on a domain the panel admin offers,
 * or on the server's own Cloudflare domain. The backend creates an A record
 * (the server's IP) and, for games that look one up (Minecraft), a SRV record
 * (the port), so players can join with just the name. Other games join with
 * name:port, which the tab shows (#79). Endpoints under /extensions/serversuite/<uuid>/subdomains.
 * No business logic here: SubdomainService enforces every rule.
 * Each subdomain can also have a public status card (#29) at
 * /status/<fqdn>, off by default.
 */

const Wrapper = styled.div`${tw`p-6 max-w-7xl mx-auto`};`;  // same page margins as the Mods tab
const Panel = styled.div`
    ${tw`rounded-2xl p-5 mb-6`};
    background: rgba(30, 41, 59, 0.4);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.08);
`;
const Header = styled.div`${tw`flex items-center justify-between mb-4`};`;
const Title = styled.h2`${tw`text-lg font-semibold text-neutral-100 flex items-center gap-2`};`;
const Subtitle = styled.p`${tw`text-sm text-neutral-400 mt-1`};`;
const FieldLabel = styled.label`${tw`block text-sm text-neutral-300 mb-1`};`;
const Hint = styled.p`${tw`text-xs text-neutral-500 mt-1 leading-relaxed`};`;
const Muted = styled.div`${tw`text-center text-neutral-500 py-6`};`;
const Loading = styled.div`${tw`text-center text-neutral-400 py-8`};`;
const Row = styled.div`
    ${tw`flex items-center justify-between rounded-xl p-4 mb-2 gap-3 flex-wrap`};
    background: rgba(30, 41, 59, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.06);
`;
const Name = styled.span`${tw`text-neutral-100 text-sm font-medium flex items-center gap-2`};`;
const Meta = styled.span`${tw`text-xs text-neutral-500`};`;
const Actions = styled.div`${tw`flex items-center gap-2`};`;
const Form = styled.div`${tw`flex items-end gap-2 flex-wrap`};`;
const Col = styled.div`${tw`flex flex-col`};`;
const Input = styled.input`
    ${tw`text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none`};
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
    min-width: 12rem;
    &:focus { border-color: rgb(99, 102, 241); }
`;
const Select = styled.select`
    ${tw`text-neutral-200 text-sm rounded-lg px-3 py-2 outline-none`};
    background-color: rgba(15, 23, 42, 0.6);
    border: 1px solid rgb(64, 64, 64);
    &:focus { border-color: rgb(99, 102, 241); }
`;
const PrimaryButton = styled.button`
    ${tw`px-4 py-2 rounded-lg text-sm font-medium text-white flex items-center gap-2 transition-all`};
    background: rgb(99, 102, 241);
    &:hover { background: rgb(79, 82, 221); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;
const GhostButton = styled.button`
    ${tw`px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2 transition-all`};
    background: rgba(30, 41, 59, 0.6);
    color: rgb(203, 213, 225);
    border: 1px solid rgba(255, 255, 255, 0.06);
    &:hover { background: rgba(51, 65, 85, 0.8); }
    &:disabled { ${tw`opacity-50 cursor-not-allowed`}; }
`;
const DangerButton = styled(GhostButton)`
    color: rgb(248, 113, 113);
`;
const Note = styled.div`
    ${tw`text-sm rounded-lg p-3 mb-4`};
    background: rgba(99, 102, 241, 0.12);
    color: rgb(165, 180, 252);
`;
const ErrorBox = styled.div`
    ${tw`text-sm rounded-lg p-3 mb-4`};
    background: rgba(239, 68, 68, 0.12);
    color: rgb(248, 113, 113);
`;
const Public = styled.span`
    ${tw`text-xs px-2 py-0.5 rounded-full font-medium inline-flex items-center gap-1`};
    background: rgba(34, 197, 94, 0.14);
    color: rgb(74, 222, 128);
`;
const Snippets = styled.div`
    ${tw`text-xs text-neutral-400 w-full flex flex-col gap-1`};
    code { ${tw`text-neutral-300 break-all`}; user-select: all; }
    a { ${tw`text-neutral-300 break-all underline`}; }
`;
const Stale = styled.span`
    ${tw`text-xs px-2 py-0.5 rounded-full font-medium inline-flex items-center gap-1`};
    background: rgba(245, 158, 11, 0.15);
    color: rgb(251, 191, 36);
`;

interface Item {
    id: number; fqdn: string; address: string; own: boolean; ip: string | null; port: number | null; stale: boolean; move_pending: boolean;
    status_public: boolean; card_url: string | null; badge_url: string | null;
}
interface Overview {
    allowance: number;
    used: number;
    target: { ip: string | null; port: number | null };
    domains: { id: number; zone: string }[];
    own: { zone: string | null; configured: boolean };
    items: Item[];
    status_cards: boolean;
    game: { name: string; srv: boolean };
}

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const base = `/extensions/serversuite/${uuid}/subdomains`;

    const [data, setData] = useState<Overview | null>(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [label, setLabel] = useState('');
    const [domain, setDomain] = useState('');
    const [ownZone, setOwnZone] = useState('');
    const [ownToken, setOwnToken] = useState('');

    const load = useCallback(() => {
        if (!uuid) return;
        http.get(base)
            .then((res) => {
                const d: Overview = res.data.data;
                setData(d);
                setOwnZone(d.own.zone ?? '');
                setDomain((cur) => cur || (d.domains[0] ? String(d.domains[0].id) : d.own.configured ? 'own' : ''));
                setError(null);
            })
            .catch((e) => setError(e?.response?.data?.error || 'Failed to load subdomains.'))
            .finally(() => setLoading(false));
    }, [uuid, base]);

    useEffect(() => { load(); }, [load]);

    const run = (key: string, url: string, body: object, ok: (fqdn: string | null) => string) => {
        setBusy(key);
        setNotice(null);
        setError(null);
        http.post(url, body)
            .then((res) => { setNotice(ok(res.data?.data?.fqdn ?? null)); load(); })
            .catch((e) => setError(e?.response?.data?.error || e?.response?.data?.message || 'Request failed.'))
            .finally(() => setBusy(null));
    };

    if (loading) return <Loading><FontAwesomeIcon icon={faSpinner} spin /> Loading…</Loading>;
    if (!data) return <Wrapper>{error && <ErrorBox>{error}</ErrorBox>}</Wrapper>;

    const zoneFor = (d: string) => d === 'own' ? data.own.zone : data.domains.find((x) => String(x.id) === d)?.zone;
    const canClaim = data.allowance > data.used && (data.domains.length > 0 || data.own.configured);

    return (
        <Wrapper>
            {notice && <Note>{notice}</Note>}
            {error && <ErrorBox>{error}</ErrorBox>}

            <Panel>
                <Header>
                    <div>
                        <Title><FontAwesomeIcon icon={faGlobe} /> Subdomains</Title>
                        <Subtitle>
                            {data.game.srv
                                ? 'Give players an address instead of an IP and port.'
                                : `Give players an address instead of an IP. ${data.game.name} doesn't look up the port by name, so players join with the name and the port shown.`}{' '}
                            Using {data.used} of {data.allowance}.
                        </Subtitle>
                    </div>
                </Header>

                {data.items.length === 0 && <Muted>No subdomains yet.</Muted>}
                {data.items.map((s) => (
                    <Row key={s.id}>
                        <div>
                            <Name>
                                {s.address}
                                {s.stale && (
                                    <Stale title="DNS moves within a minute of the server starting on the new address.">
                                        <FontAwesomeIcon icon={faExclamationTriangle} /> moves to {data.target.ip}:{data.target.port} after the next restart
                                    </Stale>
                                )}
                                {s.status_public && <Public><FontAwesomeIcon icon={faSignal} /> public status card</Public>}
                            </Name>
                            <Meta>
                                → {s.ip}:{s.port}{s.own ? ' · your domain' : ''}
                            </Meta>
                        </div>
                        <Actions>
                            <GhostButton title="Copy the address players join with" onClick={() => navigator.clipboard?.writeText(s.address)}>
                                <FontAwesomeIcon icon={faCopy} />
                            </GhostButton>
                            {data.status_cards && (
                                <Can action={'subdomains.manage'}><GhostButton disabled={busy !== null}
                                    onClick={() => run(`card-${s.id}`, `${base}/${s.id}/status-card`, { public: !s.status_public }, () => s.status_public
                                        ? `The status card for ${s.fqdn} is off. Its page and badge now return 404.`
                                        : `The status card for ${s.fqdn} is on. Anyone with the link can see whether the server is online, its player count, MOTD and version.`)}>
                                    <FontAwesomeIcon icon={busy === `card-${s.id}` ? faSpinner : faSignal} spin={busy === `card-${s.id}`} />
                                    {s.status_public ? 'Hide status card' : 'Show status card'}
                                </GhostButton></Can>
                            )}
                            {s.stale && (
                                <Can action={'subdomains.manage'}><GhostButton disabled={busy !== null} title="Move DNS now, even if the server hasn't restarted on it yet"
                                    onClick={() => run(`repoint-${s.id}`, `${base}/${s.id}/repoint`, {}, () => `${s.fqdn} now points at this server's current address.`)}>
                                    <FontAwesomeIcon icon={busy === `repoint-${s.id}` ? faSpinner : faSync} spin={busy === `repoint-${s.id}`} /> Move now
                                </GhostButton></Can>
                            )}
                            <Can action={'subdomains.manage'}><DangerButton disabled={busy !== null}
                                onClick={() => window.confirm(`Remove ${s.fqdn}? Its DNS records are deleted and players can no longer use it.`)
                                    && run(`release-${s.id}`, `${base}/${s.id}/release`, {}, () => `${s.fqdn} removed.`)}>
                                <FontAwesomeIcon icon={busy === `release-${s.id}` ? faSpinner : faTrash} spin={busy === `release-${s.id}`} /> Remove
                            </DangerButton></Can>
                        </Actions>
                        {s.card_url && s.badge_url && (
                            <Snippets>
                                <div>Page: <a href={s.card_url} target="_blank" rel="noopener noreferrer">{s.card_url}</a></div>
                                <div>Badge: <code>{`<a href="${s.card_url}"><img src="${s.badge_url}" alt="${s.fqdn} status"></a>`}</code></div>
                                <div>Embed: <code>{`<iframe src="${s.card_url}?embed=1" width="480" height="120" style="border:0" title="${s.fqdn} status"></iframe>`}</code></div>
                            </Snippets>
                        )}
                    </Row>
                ))}

                {data.allowance === 0 ? (
                    <Hint>This server has no subdomain allowance. A panel admin can add one.</Hint>
                ) : !canClaim ? (
                    <Hint>
                        {data.used >= data.allowance
                            ? 'Allowance used. Remove a subdomain, or ask a panel admin for more.'
                            : 'No domains are available yet. Add your own domain below, or ask a panel admin.'}
                    </Hint>
                ) : (
                    <Form style={{ marginTop: '1rem' }}>
                        <Col>
                            <FieldLabel>Name</FieldLabel>
                            <Input value={label} placeholder="mysmp" maxLength={63}
                                onChange={(e) => setLabel(e.target.value.toLowerCase())} />
                        </Col>
                        <Col>
                            <FieldLabel>Domain</FieldLabel>
                            <Select value={domain} onChange={(e) => setDomain(e.target.value)}>
                                {data.domains.map((d) => <option key={d.id} value={String(d.id)}>.{d.zone}</option>)}
                                {data.own.configured && <option value="own">.{data.own.zone} (yours)</option>}
                            </Select>
                        </Col>
                        <Can action={'subdomains.manage'}><PrimaryButton disabled={busy !== null || !label || !domain}
                            onClick={() => run('claim', base, { domain, label }, (fqdn) => `${fqdn ?? `${label}.${zoneFor(domain)}`} created. DNS can take a minute to update.`)}>
                            <FontAwesomeIcon icon={busy === 'claim' ? faSpinner : faPlus} spin={busy === 'claim'} /> Claim
                        </PrimaryButton></Can>
                    </Form>
                )}
                {canClaim && label && domain && <Hint>Players will join with <strong>{label}.{zoneFor(domain)}</strong>.</Hint>}
                {data.target.ip && <Hint>Points at this server&apos;s primary address: {data.target.ip}:{data.target.port}.</Hint>}
            </Panel>

            <Panel>
                <Title>Your own domain</Title>
                <Subtitle>
                    Use a domain you own on Cloudflare. Create an API token with <strong>Zone → DNS → Edit</strong> for
                    that domain only. The token is stored encrypted and can&apos;t be viewed again.
                </Subtitle>
                <Form style={{ marginTop: '1rem' }}>
                    <Col>
                        <FieldLabel>Domain</FieldLabel>
                        <Input value={ownZone} placeholder="example.com" onChange={(e) => setOwnZone(e.target.value)} />
                    </Col>
                    <Col>
                        <FieldLabel>API token</FieldLabel>
                        <Input type="password" value={ownToken} autoComplete="new-password"
                            placeholder={data.own.configured ? 'saved (leave blank to keep)' : 'Cloudflare API token'}
                            onChange={(e) => setOwnToken(e.target.value)} />
                    </Col>
                    <Can action={'subdomains.manage'}><GhostButton disabled={busy !== null || !ownZone}
                        onClick={() => run('own', `${base}/own`, { zone: ownZone, token: ownToken || null }, () => { setOwnToken(''); return 'Domain saved. The token was checked with Cloudflare.'; })}>
                        <FontAwesomeIcon icon={busy === 'own' ? faSpinner : faSave} spin={busy === 'own'} /> Save
                    </GhostButton></Can>
                    {data.own.configured && (
                        <Can action={'subdomains.manage'}><DangerButton disabled={busy !== null}
                            onClick={() => window.confirm('Remove your domain and its saved token?')
                                && run('own-remove', `${base}/own/remove`, {}, () => 'Your domain was removed.')}>
                            <FontAwesomeIcon icon={faTrash} /> Remove
                        </DangerButton></Can>
                    )}
                </Form>
                <Hint>Subdomains on your own domain still count towards this server&apos;s allowance.</Hint>
            </Panel>
        </Wrapper>
    );
};
