<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Client mínimo da API REST do Grafana (listagem de dashboards + ping).
 * Usa Guzzle (já disponível no GLPI 11).
 */

namespace GlpiPlugin\Analyticdesign\Client;

use GuzzleHttp\Client;

class GrafanaClient
{
    private Client $http;

    public function __construct(string $baseUrl, string $token)
    {
        $this->http = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout'  => 10,
            'headers'  => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);
    }

    /** GET /api/health — sanity check da conexão. */
    public function ping(): bool
    {
        $resp = $this->http->get('api/health', ['http_errors' => false]);
        return $resp->getStatusCode() === 200;
    }

    /**
     * GET /api/search?type=dash-db — lista dashboards.
     * @return array<int, array<string, mixed>>
     */
    public function searchDashboards(): array
    {
        $resp = $this->http->get('api/search', [
            'query'       => ['type' => 'dash-db'],
            'http_errors' => false,
        ]);
        if ($resp->getStatusCode() !== 200) {
            return [];
        }
        $data = json_decode((string)$resp->getBody(), true);
        return is_array($data) ? $data : [];
    }
}
