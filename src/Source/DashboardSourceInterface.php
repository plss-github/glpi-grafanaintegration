<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Contrato comum a todas as fontes de BI (Grafana, Power BI, ...).
 *
 * O restante do plugin (hook de card, render de widget) fala APENAS com esta
 * interface. Adicionar uma nova ferramenta = criar uma nova implementação e
 * registrá-la na SourceFactory. Nenhum outro arquivo precisa mudar.
 */

namespace GlpiPlugin\Analyticdesign\Source;

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\DashboardItem;

interface DashboardSourceInterface
{
    /**
     * Valores possíveis de `connections_id.embed_mode`, centralizados aqui
     * (em vez de string literais espalhadas por Connection/DashboardItem/
     * PowerBiSource) para evitar erros de digitação e ter um único lugar a
     * atualizar se um modo for renomeado.
     */
    public const EMBED_MODE_IFRAME = 'iframe';
    public const EMBED_MODE_PUBLISH_TO_WEB = 'publish_to_web';
    public const EMBED_MODE_SECURE = 'secure';

    /**
     * Tipo interno da fonte (ex.: 'grafana', 'powerbi').
     * Deve casar com o valor gravado em glpi_plugin_analyticdesign_connections.type
     */
    public static function getType(): string;

    /** Rótulo legível exibido na UI. */
    public static function getLabel(): string;

    /**
     * Valida a conexão com a ferramenta externa.
     * @return bool true se conseguiu autenticar/alcançar a API
     */
    public function testConnection(): bool;

    /**
     * Lista os dashboards disponíveis na fonte.
     *
     * @return array<int, array{external_id:string, name:string, embed_url:?string}>
     *   Cada item descreve um dashboard que o admin pode optar por expor.
     */
    public function listDashboards(): array;

    /**
     * Devolve o HTML do embed para um dashboard exposto.
     *
     * @param DashboardItem $item     item mapeado (dashboard escolhido)
     * @param array         $context  contexto opcional (entidade, filtros, tamanho)
     * @return string HTML seguro pronto para injeção no card do GLPI
     */
    public function renderEmbed(DashboardItem $item, array $context = []): string;

    /**
     * Campos de configuração específicos desta fonte, para montar o formulário
     * dinamicamente na aba "Análise de Dados".
     *
     * @return array<int, array{name:string, label:string, type:string, help:?string}>
     */
    public static function getConfigFields(): array;
}
