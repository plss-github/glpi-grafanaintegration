<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Ponte com o sistema nativo de dashboards do GLPI.
 *
 * Registra um tipo de widget ('analyticdesign_embed') e expõe cada
 * DashboardItem ativo como um card. O admin posiciona os cards em qualquer
 * grade (principal, ativos, assistência...) pelo modo de edição nativo.
 *
 * Contrato confirmado contra o código-fonte real do GLPI 11.0.8
 * (src/Glpi/Dashboard/Grid.php e Provider.php):
 *  - getCards() NÃO aceita uma chave 'card_options' (isso é algo que só existe
 *    por instância de card já posicionada, editável pelo usuário — não é
 *    algo que o plugin defina). Para associar dados FIXOS (o item_id) a cada
 *    card, o valor correto é 'provider' (string "Classe::metodo", nunca uma
 *    closure — o array de cards inteiro é serializado em cache) + 'args'
 *    (dados fixos, passados posicionalmente via array_values() ao provider).
 *  - Grid::getCardHtml() monta os argumentos do provider assim:
 *      $provider_args = ($card['args'] ?? []) + ['params' => ['label' => ...]];
 *      $widget_args = call_user_func_array($card['provider'], array_values($provider_args));
 *    ou seja, o provider recebe (valor_de_args, array $params) posicionalmente.
 *  - O retorno do provider vira a base de $widget_args, que é o que chega no
 *    callback de render (getTypes()['function']) — carona por 'provider' é
 *    o único jeito confiável de o widget saber qual item renderizar.
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
     *
     * Sem cache a se preocupar aqui: `Grid::getAllDasboardCards()` cacheia só
     * os cards nativos do GLPI; o merge com os hooks de plugin
     * (`Plugin::doHookFunction(Hooks::DASHBOARD_CARDS)`) roda de novo a cada
     * chamada — confirmado lendo o código-fonte do GLPI 11.0.8. Um dashboard
     * novo aparece no catálogo assim que ativo, sem precisar limpar cache.
     *
     * `?array $cards = null` (em vez de `array $cards = []`): confirmado
     * contra uma instância real que `Plugin::doHookFunction()` chama este
     * método passando `null` explicitamente (não omite o argumento) quando
     * nenhum outro plugin/hook anterior definiu um valor — um parâmetro
     * `array` não-anulável rejeita `null` mesmo tendo valor default,
     * causando `TypeError` fatal (silencioso na tela: card some do catálogo).
     */
    public static function getCards(?array $cards = null): array
    {
        $cards ??= [];

        foreach (DashboardItem::getActiveItems() as $item) {
            // Sem isso, QUALQUER usuário que pudesse ver QUALQUER dashboard
            // nativo do GLPI enxergava este card no catálogo de widgets —
            // este hook nunca checou o direito do plugin nem o escopo de
            // entidade da Connection dona (achado na revisão de segurança;
            // ver docblock de DashboardItem::isVisibleForCurrentUser()).
            if (!$item->isVisibleForCurrentUser()) {
                continue;
            }

            $id       = (int)$item->fields['id'];
            $category = $item->fields['category'] !== ''
                ? $item->fields['category']
                : __('Analytic Design', 'analyticdesign');

            $cards["analyticdesign_item_{$id}"] = [
                'widgettype' => ['analyticdesign_embed'],
                'group'      => $category,
                'label'      => $item->fields['name'],
                'provider'   => self::class . '::provideItem',
                'args'       => ['item_id' => $id],
            ];
        }
        return $cards;
    }

    /**
     * Provider do card: recebe o item_id fixo (baked em 'args' no getCards())
     * mais o array 'params' que o Grid monta (contém pelo menos 'label').
     * Assinatura posicional — ver nota de contrato no topo do arquivo.
     */
    public static function provideItem($item_id, array $params = []): array
    {
        return [
            'item_id' => (int)$item_id,
            'label'   => $params['label'] ?? '',
        ];
    }

    /**
     * Render do widget: carrega o item, resolve a fonte via factory e delega
     * o HTML do embed para renderEmbed(). O widget nunca sabe se é Grafana ou
     * Power BI — essa é a razão da abstração.
     *
     * @param array $params dados devolvidos por provideItem() (inclui item_id)
     */
    public static function renderEmbedWidget(array $params = []): string
    {
        $itemId = (int)($params['item_id'] ?? 0);
        if ($itemId <= 0) {
            return self::errorBox(__('Card sem item associado.', 'analyticdesign'));
        }

        $item = new DashboardItem();
        if (!$item->getFromDB($itemId)) {
            return self::errorBox(__('Dashboard não encontrado.', 'analyticdesign'));
        }

        // Checagem obrigatória aqui, não só em getCards(): um card já
        // posicionado num dashboard continua sendo renderizado neste
        // caminho mesmo que a listagem/catálogo nunca seja consultada de
        // novo — só filtrar o catálogo não bastava (ver docblock de
        // DashboardItem::isVisibleForCurrentUser()).
        if (!$item->isVisibleForCurrentUser()) {
            return self::errorBox(__('Você não tem permissão para ver este dashboard.', 'analyticdesign'));
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
