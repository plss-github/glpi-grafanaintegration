<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Fonte Grafana (Fase 1). Primeira implementação concreta do contrato
 * DashboardSourceInterface. Embedding via iframe.
 */

namespace GlpiPlugin\Plugingrafanaintegration\Source;

use GlpiPlugin\Plugingrafanaintegration\Client\GrafanaClient;
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

    public function testConnection(): bool
    {
        try {
            return $this->client()->ping();
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

    public function renderEmbed(DashboardItem $item, array $context = []): string
    {
        // Preferimos a embed_url salva; se ausente, remontamos a partir do uid.
        $url = $item->fields['embed_url'] ?? '';
        if ($url === '') {
            $url = $this->buildEmbedUrl((string)$item->fields['external_id']);
        }
        return $this->buildIframe($url, $context);
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
        // 'base_url' não entra aqui: Connection::showForm() já renderiza um
        // campo fixo "URL base" para todos os tipos de fonte (é uma coluna
        // própria da Connection, não uma credencial). Incluí-lo aqui geraria
        // um segundo <input name="base_url"> no formulário.
        return [
            [
                'name'  => 'api_token',
                'label' => __('API Token / Service account token', 'analyticdesign'),
                'type'  => 'password',
                'help'  => __('Token com permissão de leitura de dashboards. Ex.: glsa_1a2b3c4d5e6f7g8h9i0j_abcdef12', 'analyticdesign'),
            ],
        ];
    }
}
