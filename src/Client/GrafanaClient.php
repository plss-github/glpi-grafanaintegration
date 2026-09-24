<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Client mínimo da API REST do Grafana (listagem de dashboards + ping).
 * Usa Guzzle (já disponível no GLPI 11).
 */

namespace GlpiPlugin\Plugingrafanaintegration\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class GrafanaClient
{
    private Client $http;
    private string $baseUrl;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->http = new Client([
            'base_uri' => $this->baseUrl,
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

    /**
     * `POST /login` com usuário/senha do usuário dedicado (Viewer) — API de
     * login por formulário do próprio Grafana (não é a mesma coisa que o
     * `api_token`/Bearer usado no resto desta classe: aquele autentica só as
     * chamadas de backend do plugin; este login gera uma SESSÃO de navegador
     * de verdade, com cookie, que é o que `front/grafana_proxy.php` repassa
     * ao Grafana no lugar do navegador de cada usuário do GLPI — ver
     * GrafanaSource::proxySession()).
     *
     * @return string|null string "Nome1=Valor1; Nome2=Valor2" pronta pro
     *   header `Cookie:` de uma requisição proxiada, ou null se o login falhar.
     */
    public function loginSession(string $username, string $password): ?string
    {
        $jar = new CookieJar();
        $resp = $this->http->post('login', [
            'json'        => ['user' => $username, 'password' => $password],
            'cookies'     => $jar,
            'http_errors' => false,
        ]);
        if ($resp->getStatusCode() !== 200) {
            return null;
        }

        $pairs = [];
        foreach ($jar->toArray() as $cookie) {
            $pairs[] = $cookie['Name'] . '=' . $cookie['Value'];
        }
        return empty($pairs) ? null : implode('; ', $pairs);
    }
}
