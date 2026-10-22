<?php

namespace ServerSuite\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use ServerSuite\Services\StatusCardService;

/**
 * The public status page and badge of a subdomain (#29). An unknown name
 * and a card that's switched off give the same 404, so names can't be
 * probed. The page may be framed anywhere (the iframe snippet).
 */
class StatusCardController extends Controller
{
    public function show(StatusCardService $cards, string $fqdn): Response
    {
        $sub = $cards->find($fqdn) ?? abort(404);

        return $this->respond(response()->view('serversuite::status.card', [
            'status' => $cards->status($sub),
            'embed'  => request()->boolean('embed'),
        ]), [
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors *; base-uri 'none'; form-action 'none'",
        ]);
    }

    public function badge(StatusCardService $cards, string $fqdn): Response
    {
        $sub = $cards->find($fqdn) ?? abort(404);
        $s = $cards->status($sub);

        $label = $s['fqdn'];
        $value = $s['online'] ? ($s['players'] !== null ? "{$s['players']}/{$s['max']} online" : 'online') : 'offline';
        $colour = $s['online'] ? '#16a34a' : '#64748b';

        return $this->respond(response()->view('serversuite::status.badge', compact('label', 'value', 'colour'))
            ->header('Content-Type', 'image/svg+xml'), [
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }

    /** @param array<string, string> $headers */
    private function respond(Response $response, array $headers): Response
    {
        $ttl = max(5, (int) config('serversuite.status_cards.cache_seconds', 30));

        return $response->withHeaders($headers + [
            'Cache-Control'          => "public, max-age={$ttl}",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'no-referrer',
            'X-Robots-Tag'           => 'noindex',
        ]);
    }
}
