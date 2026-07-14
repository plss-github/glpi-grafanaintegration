<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Ponte com o sistema nativo de dashboards do GLPI.
 *
 * Registra um tipo de widget ('analyticdesign_embed') e expõe cada
 * DashboardItem ativo como um card. O admin posiciona os cards em qualquer
 * grade (principal, ativos, assistência...) pelo modo de edição nativo.
 *
 * >>> A VALIDAR contra o GLPI 11: a estrutura exata do array de widget/card e
 *     a assinatura do callback de render. A modelagem abaixo segue o padrão
 *     documentado (getTypes/getCards + callback que devolve HTML).
 */

namespace GlpiPlugin\Analyticdesign;

use GlpiPlugin\Analyticdesign\Source\SourceFactory;

class Dashboard
{
    /**
     * Tipos de widget adicionados ao dashboard.
     * Hook: Glpi\Plugin\Hooks::DASHBOARD_TYPES
     */
    public static function getTypes(): array
    {
        return [
            'analyticdesign_embed' => [
                'label'    => __('Analytic Design (BI externo)', 'analyticdesign'),
                'function' => self::class . '::renderEmbedWidget',
                // Sem 'image': não há ícone de preview específico ainda. Se o
                // GLPI 11 exigir a chave, adicionar um PNG/SVG em public/img/.
            ],
        ];
    }

    /**
     * Cards disponíveis no catálogo — um por dashboard exposto, agrupado por categoria.
     * Hook: Glpi\Plugin\Hooks::DASHBOARD_CARDS
     */
    public static function getCards(array $cards = []): array
    {
        foreach (DashboardItem::getActiveItems() as $item) {
            $id       = (int)$item->fields['id'];
            $category = $item->fields['category'] !== ''
                ? $item->fields['category']
                : __('Analytic Design', 'analyticdesign');

            $cards["analyticdesign_item_{$id}"] = [
                'widgettype'   => ['analyticdesign_embed'],
                'group'        => $category,
                'label'        => $item->fields['name'],
                'card_options' => [
                    'item_id' => $id,
                ],
            ];
        }
        return $cards;
    }

    /**
     * Render do widget: carrega o item, resolve a fonte via factory e delega
     * o HTML do embed para renderEmbed(). O widget nunca sabe se é Grafana ou
     * Power BI — essa é a razão da abstração.
     *
     * @param array $params parâmetros do card (inclui card_options.item_id)
     */
    public static function renderEmbedWidget(array $params = []): string
    {
        $itemId = (int)($params['card_options']['item_id'] ?? $params['item_id'] ?? 0);
        if ($itemId <= 0) {
            return self::errorBox(__('Card sem item associado.', 'analyticdesign'));
        }

        $item = new DashboardItem();
        if (!$item->getFromDB($itemId)) {
            return self::errorBox(__('Dashboard não encontrado.', 'analyticdesign'));
        }

        $connection = $item->getConnection();
        if ($connection === null) {
            return self::errorBox(__('Fonte de dados indisponível.', 'analyticdesign'));
        }

        try {
            $source = SourceFactory::make($connection);
            $context = [
                'width'  => $params['width']  ?? '100%',
                'height' => $params['height'] ?? '100%',
            ];
            return $source->renderEmbed($item, $context);
        } catch (\Throwable $e) {
            return self::errorBox(__('Falha ao renderizar o dashboard.', 'analyticdesign'));
        }
    }

    private static function errorBox(string $msg): string
    {
        return '<div class="analyticdesign-error" style="padding:1rem;color:#b00;">'
             . htmlspecialchars($msg, ENT_QUOTES)
             . '</div>';
    }
}
