<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Client do modo "embed seguro" do Power BI (Fase 2): autentica como service
 * principal no Entra ID (OAuth2 client_credentials) e fala com a API REST do
 * Power BI para listar relatórios e gerar embed tokens de curta duração.
 *
 * Endpoints usados (API pública e estável da Microsoft — não específicos do
 * GLPI, portanto não sujeitos à mesma incerteza de validação do restante do
 * plugin):
 *   POST https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token
 *   GET  https://api.powerbi.com/v1.0/myorg/groups/{groupId}/reports
 *   POST https://api.powerbi.com/v1.0/myorg/groups/{groupId}/reports/{reportId}/GenerateToken
 */

namespace GlpiPlugin\Analyticdesign\Client;

use GuzzleHttp\Client;

class PowerBiClient
{
    private const AAD_SCOPE = 'https://analysis.windows.net/powerbi/api/.default';

    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $workspaceId;
    private Client $http;
    private ?string $accessToken = null;

    public function __construct(string $tenantId, string $clientId, string $clientSecret, string $workspaceId)
    {
        $this->tenantId     = $tenantId;
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->workspaceId  = $workspaceId;
        $this->http = new Client([
            'base_uri' => 'https://api.powerbi.com/',
            'timeout'  => 15,
        ]);
    }

    /**
     * Valida o formato GUID esperado pela Microsoft para tenant/client/
     * workspace/report id. Falhar aqui, com uma mensagem clara, é melhor do
     * que deixar um valor mal formatado (erro de digitação, cole de espaço
     * em branco etc.) virar um erro genérico de HTTP 400 da API externa —
     * e evita compor URLs com valores inesperados vindos de configuração.
     */
    private static function isValidGuid(string $value): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * Troca client_id/client_secret por um access token OAuth2 (client
     * credentials grant) junto ao Entra ID. Cacheado em memória pela duração
     * do request (um embed token por render já é suficiente; não persiste
     * entre requisições HTTP distintas).
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        if ($this->tenantId === '' || $this->clientId === '' || $this->clientSecret === '') {
            throw new \RuntimeException('Credenciais do Power BI (tenant/client/secret) incompletas.');
        }
        if (!self::isValidGuid($this->tenantId) || !self::isValidGuid($this->clientId)) {
            throw new \RuntimeException('Tenant ID ou Client ID do Power BI não têm formato de GUID válido.');
        }

        $tokenClient = new Client([
            'base_uri' => "https://login.microsoftonline.com/{$this->tenantId}/",
            'timeout'  => 15,
        ]);

        $resp = $tokenClient->post('oauth2/v2.0/token', [
            'form_params' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => self::AAD_SCOPE,
            ],
            'http_errors' => false,
        ]);

        $data = json_decode((string)$resp->getBody(), true);
        if ($resp->getStatusCode() !== 200 || !isset($data['access_token'])) {
            $reason = $data['error_description'] ?? ('HTTP ' . $resp->getStatusCode());
            throw new \RuntimeException("Falha ao autenticar no Entra ID: {$reason}");
        }

        $this->accessToken = $data['access_token'];
        return $this->accessToken;
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Accept'        => 'application/json',
        ];
    }

    /** Sanity check: lista workspaces acessíveis pelo service principal. */
    public function ping(): bool
    {
        $resp = $this->http->get('v1.0/myorg/groups', [
            'headers'     => $this->authHeaders(),
            'http_errors' => false,
        ]);
        return $resp->getStatusCode() === 200;
    }

    /**
     * GET /groups/{workspaceId}/reports — lista os relatórios do workspace
     * configurado na Connection.
     * @return array<int, array{external_id:string, name:string, embed_url:?string}>
     */
    public function listReports(): array
    {
        if ($this->workspaceId === '') {
            throw new \RuntimeException('Workspace (group) do Power BI não configurado.');
        }
        if (!self::isValidGuid($this->workspaceId)) {
            throw new \RuntimeException('Workspace ID do Power BI não tem formato de GUID válido.');
        }

        $resp = $this->http->get("v1.0/myorg/groups/{$this->workspaceId}/reports", [
            'headers'     => $this->authHeaders(),
            'http_errors' => false,
        ]);
        if ($resp->getStatusCode() !== 200) {
            return [];
        }

        $data = json_decode((string)$resp->getBody(), true);
        $out = [];
        foreach ($data['value'] ?? [] as $report) {
            $out[] = [
                'external_id' => (string)($report['id'] ?? ''),
                'name'        => (string)($report['name'] ?? ''),
                'embed_url'   => (string)($report['embedUrl'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * POST .../reports/{reportId}/GenerateToken — embed token de curta
     * duração (~1h) para renderizar um único relatório com acesso somente
     * leitura. Gerado a cada render (não persistido em banco).
     * @return array{token:string, tokenId:string, expiration:string}
     */
    public function generateEmbedToken(string $reportId): array
    {
        if ($this->workspaceId === '' || $reportId === '') {
            throw new \RuntimeException('Workspace ou relatório do Power BI não informado.');
        }
        if (!self::isValidGuid($this->workspaceId) || !self::isValidGuid($reportId)) {
            throw new \RuntimeException('Workspace ID ou report ID do Power BI não têm formato de GUID válido.');
        }

        $resp = $this->http->post("v1.0/myorg/groups/{$this->workspaceId}/reports/{$reportId}/GenerateToken", [
            'headers'     => $this->authHeaders(),
            'json'        => ['accessLevel' => 'View'],
            'http_errors' => false,
        ]);

        $data = json_decode((string)$resp->getBody(), true);
        if ($resp->getStatusCode() !== 200 || !isset($data['token'])) {
            $reason = $data['error']['message'] ?? ('HTTP ' . $resp->getStatusCode());
            throw new \RuntimeException("Falha ao gerar embed token do Power BI: {$reason}");
        }

        return $data;
    }
}
