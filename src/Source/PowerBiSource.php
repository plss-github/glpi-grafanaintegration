<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * FONTE POWER BI — STUB DA FASE 2.
 *
 * Este arquivo NÃO está funcional ainda. Ele existe para demonstrar que a
 * abstração DashboardSourceInterface comporta o Power BI sem tocar em nenhum
 * outro arquivo do plugin: basta completar os métodos abaixo e registrar a
 * classe na SourceFactory.
 *
 * Suporta (por design) DOIS modos de embed, escolhidos via connection.embed_mode:
 *   - 'publish_to_web' : URL pública, iframe simples. ⚠️ SEM AUTENTICAÇÃO.
 *   - 'secure'         : Entra ID + service principal + embed token + powerbi-client.
 */

namespace GlpiPlugin\Analyticdesign\Source;

use GlpiPlugin\Analyticdesign\DashboardItem;

class PowerBiSource extends AbstractDashboardSource
{
    public static function getType(): string
    {
        return 'powerbi';
    }

    public static function getLabel(): string
    {
        return 'Power BI';
    }

    public function testConnection(): bool
    {
        // TODO (Fase 2):
        //  - modo publish_to_web: nada a autenticar (retorna true).
        //  - modo secure: obter token OAuth2 (client_credentials) no Entra ID
        //    e chamar GET /v1.0/myorg/groups como sanity check.
        return false;
    }

    public function listDashboards(): array
    {
        // TODO (Fase 2):
        //  - modo secure: GET /v1.0/myorg/groups/{workspace}/reports
        //  - modo publish_to_web: a listagem automática não é possível;
        //    o admin cola manualmente a URL pública por dashboard.
        return [];
    }

    public function renderEmbed(DashboardItem $item, array $context = []): string
    {
        $mode = $this->connection->fields['embed_mode'] ?? 'secure';

        if ($mode === 'publish_to_web') {
            // Reaproveita exatamente o mesmo padrão de iframe do Grafana.
            return $this->buildIframe($item->fields['embed_url'] ?? '', $context);
        }

        // Modo secure: gerar embed token no servidor e devolver um container
        // que o powerbi-client (JS) hidrata no front.
        // TODO (Fase 2): implementar geração de embed token e bootstrap JS.
        return '<div class="analyticdesign-powerbi-secure" '
             . 'data-report-id="' . htmlspecialchars((string)$item->fields['external_id'], ENT_QUOTES) . '">'
             . __('Embed seguro do Power BI pendente de implementação (Fase 2).', 'analyticdesign')
             . '</div>';
    }

    public static function getConfigFields(): array
    {
        // TODO (Fase 2): campos condicionais ao embed_mode.
        //  publish_to_web: apenas URLs públicas por dashboard.
        //  secure: tenant_id, client_id, client_secret, workspace_id.
        return [];
    }
}
