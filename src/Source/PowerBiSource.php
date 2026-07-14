<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * FONTE POWER BI — STUB DAS FASES 2 e 3.
 *
 * Este arquivo NÃO está funcional ainda. Ele existe para demonstrar que a
 * abstração DashboardSourceInterface comporta o Power BI sem tocar em nenhum
 * outro arquivo do plugin: basta completar os métodos abaixo e registrar a
 * classe na SourceFactory.
 *
 * Suporta (por design) DOIS modos de embed, escolhidos via connection.embed_mode,
 * cada um planejado como uma fase separada por terem custo/risco bem diferentes:
 *
 *   - 'secure' (Fase 2)         : Entra ID + service principal + embed token +
 *     powerbi-client. Requer capacity/licença Premium. ~8-10 dias.
 *   - 'publish_to_web' (Fase 3) : URL pública, iframe simples — reaproveita
 *     AbstractDashboardSource::buildIframe(), o mesmo usado pelo Grafana.
 *     ⚠️ SEM AUTENTICAÇÃO: qualquer pessoa com o link acessa. Exige aviso
 *     obrigatório e não descartável na UI sempre que este modo é selecionado
 *     (ver Connection::showForm(), classe `.analyticdesign-publish-warning`).
 *     Não usar para dados confidenciais. ~2-3 dias — mais simples que a Fase 2
 *     por não precisar de OAuth/backend, por isso planejada como fase própria
 *     e independente (pode até ser entregue antes da Fase 2, se priorizado).
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
        // TODO (Fase 3 — publish_to_web): nada a autenticar, pode retornar
        //   true direto (não há API a chamar nesse modo).
        // TODO (Fase 2 — secure): obter token OAuth2 (client_credentials) no
        //   Entra ID e chamar GET /v1.0/myorg/groups como sanity check.
        return false;
    }

    public function listDashboards(): array
    {
        // TODO (Fase 2 — secure): GET /v1.0/myorg/groups/{workspace}/reports
        // Fase 3 — publish_to_web: por design NÃO há listagem automática (a
        //   API do Power BI não expõe os links de "publish to web"); o admin
        //   cola manualmente a URL pública ao criar/editar o DashboardItem.
        return [];
    }

    public function renderEmbed(DashboardItem $item, array $context = []): string
    {
        $mode = $this->connection->fields['embed_mode'] ?? 'secure';

        if ($mode === 'publish_to_web') {
            // Fase 3: reaproveita exatamente o mesmo padrão de iframe do
            // Grafana (inclusive a validação de esquema http/https).
            return $this->buildIframe($item->fields['embed_url'] ?? '', $context);
        }

        // Fase 2 (secure): gerar embed token no servidor e devolver um
        // container que o powerbi-client (JS) hidrata no front.
        // TODO (Fase 2): implementar geração de embed token e bootstrap JS.
        return '<div class="analyticdesign-powerbi-secure" '
             . 'data-report-id="' . htmlspecialchars((string)$item->fields['external_id'], ENT_QUOTES) . '">'
             . __('Embed seguro do Power BI pendente de implementação (Fase 2).', 'analyticdesign')
             . '</div>';
    }

    public static function getConfigFields(): array
    {
        // TODO: campos condicionais ao embed_mode.
        //  Fase 3 (publish_to_web): nenhum campo de credencial aqui — a URL
        //    pública é preenchida por DashboardItem, não pela Connection.
        //  Fase 2 (secure): tenant_id, client_id, client_secret, workspace_id.
        return [];
    }
}
