<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Fonte Grafana (Fase 1). Primeira implementação concreta do contrato
 * DashboardSourceInterface. Embedding via iframe.
 */

namespace GlpiPlugin\Plugingrafanaintegration\Source;

use GlpiPlugin\Plugingrafanaintegration\Client\GrafanaClient;
use GlpiPlugin\Plugingrafanaintegration\Connection;
use GlpiPlugin\Plugingrafanaintegration\DashboardItem;

class GrafanaSource extends AbstractDashboardSource
{
    public static function getType(): string
    {
        return 'grafana';
    }

    public static function getLabel(): string
    {
        return 'Grafana';
    }

    private function client(): GrafanaClient
    {
        return new GrafanaClient(
            $this->getBaseUrl(),
            $this->credentials['api_token'] ?? ''
        );
    }

    /**
     * Testa tanto o token de backend (`ping()`, `/api/health`) quanto o
     * login do usuário dedicado (se já configurado) — sem o segundo, o
     * token sozinho não garante que os embeds vão funcionar (ver
     * proxySession()). Se o usuário dedicado ainda não foi preenchido
     * (fluxo progressivo: primeiro URL+token, depois o usuário — ver aba
     * "Conexão"), testa só o token, para não bloquear esse primeiro passo.
     */
    public function testConnection(): bool
    {
        try {
            if (!$this->client()->ping()) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        $hasDedicatedUser = (string)($this->credentials['grafana_username'] ?? '') !== ''
            && (string)($this->credentials['grafana_password'] ?? '') !== '';
        if (!$hasDedicatedUser) {
            return true;
        }

        try {
            return $this->proxySession(true) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function listDashboards(): array
    {
        $out = [];
        foreach ($this->client()->searchDashboards() as $dash) {
            // $dash esperado: ['uid' => ..., 'title' => ..., 'url' => ...]
            $out[] = [
                'external_id' => (string)($dash['uid'] ?? ''),
                'name'        => (string)($dash['title'] ?? ''),
                'embed_url'   => $this->buildEmbedUrl((string)($dash['uid'] ?? ''), $dash),
            ];
        }
        return $out;
    }

    /**
     * O iframe NUNCA aponta mais direto pro Grafana (era assim até a versão
     * anterior — o navegador do usuário fazia a requisição direto, sem
     * autenticação nenhuma do plugin, daí a tela de login do Grafana
     * aparecendo dentro do card). Agora aponta para
     * `front/grafana_proxy.php`, que repassa a requisição ao Grafana
     * autenticado com a sessão do usuário dedicado (ver proxySession()) — o
     * navegador do usuário final só fala com o próprio GLPI.
     */
    public function renderEmbed(DashboardItem $item, array $context = []): string
    {
        // Preferimos a embed_url salva; se ausente, remontamos a partir do uid.
        $url = $item->fields['embed_url'] ?? '';
        if ($url === '') {
            $url = $this->buildEmbedUrl((string)$item->fields['external_id']);
        }
        $relativePath = $this->toRelativePath($url);
        $proxyUrl = self::buildProxyUrl((int)$this->connection->fields['id'], $relativePath);
        return $this->buildIframe($proxyUrl, $context);
    }

    /**
     * `embed_url` pode ter sido salva como URL absoluta (import feito antes
     * desta versão) ou já relativa — normaliza para o caminho que
     * `front/grafana_proxy.php` espera em `path=` (sempre relativo à raiz do
     * Grafana, nunca com esquema/host).
     */
    private function toRelativePath(string $url): string
    {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return $url;
        }
        $path  = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        return $query ? "{$path}?{$query}" : $path;
    }

    /** URL absoluta (com esquema/host do próprio GLPI) do endpoint de proxy — ver front/grafana_proxy.php. */
    public static function buildProxyUrl(int $connectionsId, string $relativePath): string
    {
        global $CFG_GLPI;
        $root = rtrim((string)($CFG_GLPI['url_base'] ?? ''), '/') . $CFG_GLPI['root_doc'];
        return $root . '/plugins/plugingrafanaintegration/front/grafana_proxy.php'
            . '?id=' . $connectionsId
            . '&path=' . rawurlencode($relativePath);
    }

    /**
     * Sessão de navegador do usuário dedicado (Viewer, acesso a todos os
     * dashboards) — usada por `front/grafana_proxy.php` para autenticar
     * TODAS as requisições que repassa ao Grafana (HTML do dashboard, JS/CSS,
     * chamadas de API que o próprio Grafana faz para montar os painéis).
     * Nunca chega ao navegador do usuário final — fica só entre o backend do
     * plugin e o Grafana.
     *
     * Cache de 1h na própria Connection (`proxy_session_cookie`/
     * `proxy_session_expires` — ver Connection::install()), escrita direta
     * via query builder (não `CommonDBTM::update()`): evita disparar hooks
     * (`post_updateItem()`, histórico) para uma atualização que é puramente
     * derivada, mesmo padrão de `VisibilityRule::resyncAffectedItems()`. Faz
     * login de novo quando o cache expira, está vazio, ou quando
     * `$forceRelogin` (chamado pelo proxy depois de um 401/403 do Grafana,
     * uma vez).
     *
     * @return string|null null se as credenciais do usuário dedicado não
     *   estiverem configuradas, ou se o login no Grafana falhar.
     */
    public function proxySession(bool $forceRelogin = false): ?string
    {
        global $DB;

        $connectionId = (int)$this->connection->fields['id'];
        $cookie  = (string)($this->connection->fields['proxy_session_cookie'] ?? '');
        $expires = $this->connection->fields['proxy_session_expires'] ?? null;
        $stillValid = !$forceRelogin && $cookie !== '' && $expires !== null && strtotime((string)$expires) > time();
        if ($stillValid) {
            return $cookie;
        }

        $username = (string)($this->credentials['grafana_username'] ?? '');
        $password = (string)($this->credentials['grafana_password'] ?? '');
        if ($username === '' || $password === '') {
            return null;
        }

        $cookie = $this->client()->loginSession($username, $password);
        if ($cookie === null) {
            return null;
        }

        $expiresAt = date('Y-m-d H:i:s', time() + 3600);
        $DB->update(Connection::getTable(), [
            'proxy_session_cookie'  => $cookie,
            'proxy_session_expires' => $expiresAt,
        ], ['id' => $connectionId]);
        $this->connection->fields['proxy_session_cookie'] = $cookie;
        $this->connection->fields['proxy_session_expires'] = $expiresAt;

        return $cookie;
    }

    /**
     * Monta a URL de embed do Grafana. `kiosk` remove a navegação;
     * `theme` casa com o tema do GLPI. Painel único usa &viewPanel=.
     */
    private function buildEmbedUrl(string $uid, array $dash = []): string
    {
        $base = $this->getBaseUrl();
        $slug = $dash['url'] ?? "/d/{$uid}";
        // Normaliza para caminho relativo do Grafana.
        if (str_starts_with($slug, 'http')) {
            $path = parse_url($slug, PHP_URL_PATH) ?: "/d/{$uid}";
        } else {
            $path = $slug;
        }
        return $base . $path . '?kiosk=tv&theme=light';
    }

    public static function getConfigFields(): array
    {
        // 'base_url' não entra aqui: a aba "Conexão" já renderiza um campo
        // fixo "URL base" para todos os tipos de fonte (é uma coluna própria
        // da Connection, não uma credencial). Incluí-lo aqui geraria um
        // segundo <input name="base_url"> no formulário.
        return [
            [
                'name'  => 'api_token',
                'label' => __('API Token / Service account token', 'analyticdesign'),
                'type'  => 'password',
                'help'  => __('Token com permissão de leitura de dashboards, usado só pelo backend do plugin (testar conexão, listar dashboards). Ex.: glsa_1a2b3c4d5e6f7g8h9i0j_abcdef12', 'analyticdesign'),
            ],
            [
                'name'  => 'grafana_username',
                'label' => __('Usuário dedicado (Viewer)', 'analyticdesign'),
                'type'  => 'text',
                'help'  => __('Login de um usuário do Grafana com papel Viewer e acesso a todos os dashboards a expor — sua sessão é o que autentica o embed de todo mundo (ver "Testar conexão").', 'analyticdesign'),
            ],
            [
                'name'  => 'grafana_password',
                'label' => __('Senha do usuário dedicado', 'analyticdesign'),
                'type'  => 'password',
                'help'  => __('Senha do usuário acima. Recomendado: uma conta só para isso, sem outros privilégios além de Viewer.', 'analyticdesign'),
            ],
        ];
    }
}
