<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * FONTE POWER BI.
 *
 * Suporta (por design) DOIS modos de embed, escolhidos via connection.embed_mode:
 *
 *   - 'secure' (Fase 2) — IMPLEMENTADO: Entra ID (service principal) + embed
 *     token gerado no servidor a cada render + powerbi-client no front.
 *     Requer capacity/licença Premium no workspace do Power BI.
 *   - 'publish_to_web' (Fase 3) — IMPLEMENTADO: reaproveita
 *     AbstractDashboardSource::buildIframe(), o mesmo usado pelo Grafana. Sem
 *     listagem automática (a API do Power BI não expõe URLs de publish-to-web);
 *     o admin cola a URL manualmente por dashboard.
 *     ⚠️ SEM AUTENTICAÇÃO: qualquer pessoa com o link acessa. Aviso obrigatório
 *     e não descartável na UI sempre que este modo é selecionado (ver
 *     Connection::showForm(), classe `.analyticdesign-publish-warning`).
 */

namespace GlpiPlugin\Analyticdesign\Source;

use GlpiPlugin\Analyticdesign\Client\PowerBiClient;
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

    private function embedMode(): string
    {
        return $this->connection->fields['embed_mode'] ?? 'secure';
    }

    private function client(): PowerBiClient
    {
        return new PowerBiClient(
            $this->credentials['tenant_id'] ?? '',
            $this->credentials['client_id'] ?? '',
            $this->credentials['client_secret'] ?? '',
            $this->credentials['workspace_id'] ?? ''
        );
    }

    public function testConnection(): bool
    {
        if ($this->embedMode() === 'publish_to_web') {
            // Nada a autenticar nesse modo — não há API a chamar.
            return true;
        }
        try {
            return $this->client()->ping();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function listDashboards(): array
    {
        if ($this->embedMode() === 'publish_to_web') {
            // Por design: a API do Power BI não expõe links de "publish to
            // web"; o admin adiciona manualmente (ver
            // DashboardItem::showForConnection() -> formulário de adição manual).
            return [];
        }
        try {
            return $this->client()->listReports();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function renderEmbed(DashboardItem $item, array $context = []): string
    {
        if ($this->embedMode() === 'publish_to_web') {
            // Fase 3: reaproveita exatamente o mesmo padrão de iframe do
            // Grafana (inclusive a validação de esquema http/https).
            return $this->buildIframe($item->fields['embed_url'] ?? '', $context);
        }

        // Fase 2 (secure): gera um embed token novo a cada render (validade
        // curta, ~1h por padrão da API) e devolve um container que o
        // powerbi-client (JS) hidrata no front — ver
        // public/js/analyticdesign-powerbi.js. Nenhum token fica persistido.
        try {
            $tokenData = $this->client()->generateEmbedToken((string)$item->fields['external_id']);
        } catch (\Throwable $e) {
            return '<div class="analyticdesign-error" style="padding:1rem;color:#b00;">'
                . htmlspecialchars(__('Falha ao gerar o embed token do Power BI.', 'analyticdesign'), ENT_QUOTES)
                . '</div>';
        }

        $width  = htmlspecialchars((string)($context['width']  ?? '100%'), ENT_QUOTES);
        $height = htmlspecialchars((string)($context['height'] ?? '100%'), ENT_QUOTES);

        return sprintf(
            '<div class="analyticdesign-powerbi-secure" style="width:%s;height:%s;" '
            . 'data-report-id="%s" data-embed-url="%s" data-embed-token="%s"></div>',
            $width,
            $height,
            htmlspecialchars((string)$item->fields['external_id'], ENT_QUOTES),
            htmlspecialchars($item->fields['embed_url'] ?? '', ENT_QUOTES),
            htmlspecialchars($tokenData['token'], ENT_QUOTES)
        );
    }

    public static function getConfigFields(): array
    {
        // 'embed_mode' marca campos que só se aplicam a um modo específico —
        // Connection::showForm() e analyticdesign.js usam essa chave para
        // mostrar/esconder o campo junto com o dropdown de embed_mode.
        // publish_to_web não tem campos aqui: a URL pública é colada por
        // DashboardItem (aba "Dashboards"), não pela Connection.
        return [
            [
                'name'       => 'tenant_id',
                'label'      => __('Tenant ID (Entra ID)', 'analyticdesign'),
                'type'       => 'text',
                'help'       => __('GUID do tenant do Azure AD / Entra ID.', 'analyticdesign'),
                'embed_mode' => 'secure',
            ],
            [
                'name'       => 'client_id',
                'label'      => __('Client ID (aplicativo registrado)', 'analyticdesign'),
                'type'       => 'text',
                'help'       => __('ID do aplicativo (service principal) registrado no Entra ID.', 'analyticdesign'),
                'embed_mode' => 'secure',
            ],
            [
                'name'       => 'client_secret',
                'label'      => __('Client secret', 'analyticdesign'),
                'type'       => 'password',
                'help'       => __('Segredo do aplicativo registrado no Entra ID.', 'analyticdesign'),
                'embed_mode' => 'secure',
            ],
            [
                'name'       => 'workspace_id',
                'label'      => __('Workspace ID (group)', 'analyticdesign'),
                'type'       => 'text',
                'help'       => __('GUID do workspace do Power BI onde os relatórios estão publicados.', 'analyticdesign'),
                'embed_mode' => 'secure',
            ],
        ];
    }
}
