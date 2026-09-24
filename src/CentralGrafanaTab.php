<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Aba "Grafana" na Central (Home) do GLPI — lista os dashboards visíveis ao
 * usuário atual, no estilo do plugin Metabase (uma aba própria na tela
 * inicial, em vez de precisar entrar num módulo específico ou de um dashboard
 * nativo substituído — mecânica antiga, `ModuleDashboard`, removida).
 *
 * Não é uma entidade de banco (extends CommonGLPI, sem tabela própria) — só
 * pluga no sistema de abas do GLPI sobre `Central`, via
 * `Plugin::registerClass(self::class, ['addtabon' => \Central::class])`
 * (ver setup.php), mesmo mecanismo já usado por ProfileRights/
 * ProfileHomeRights sobre `Profile`.
 *
 * Gate de dois níveis, nessa ordem:
 *  1. Session::haveRight(Connection::HOME_RIGHTNAME, READ) — direito de
 *     perfil separado (ver ProfileHomeRights): sem ele, a aba nem aparece na
 *     Central. Não tem nada a ver com o direito de administrar fontes/
 *     dashboards (Connection::RIGHTNAME) — um atendente comum só precisa
 *     deste.
 *  2. Por dashboard: DashboardItem::isVisibleForCurrentUser() (mesma
 *     checagem usada pelo catálogo de widgets do dashboard nativo — nenhuma
 *     lógica de visibilidade nova aqui, só reaproveitada) — controla QUAIS
 *     dashboards aparecem na lista pra esse usuário (aba "Visibilidade" de
 *     cada fonte).
 */

namespace GlpiPlugin\Plugingrafanaintegration;

use Central;
use CommonGLPI;
use Session;

class CentralGrafanaTab extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Grafana', 'analyticdesign');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-dots';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Central && Session::haveRight(Connection::HOME_RIGHTNAME, READ)) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Central) || !Session::haveRight(Connection::HOME_RIGHTNAME, READ)) {
            return false;
        }

        self::showDashboardList();

        return true;
    }

    /**
     * Lista os dashboards ativos e visíveis ao usuário atual, agrupados por
     * módulo (mesmo agrupamento do catálogo de widgets — ver
     * Dashboard::getCards()). Cada um é um link para
     * front/homedashboard.php?id=X, que abre o embed proxiado em tela cheia
     * (ver docblock daquele arquivo sobre por que não é o mesmo
     * front/previewdashboarditem.php usado pela aba "Pré-Visualização").
     */
    private static function showDashboardList(): void
    {
        $visible = array_values(array_filter(
            DashboardItem::getActiveItems(),
            static fn (DashboardItem $item) => $item->isVisibleForCurrentUser()
        ));

        if (empty($visible)) {
            echo "<p class='text-muted'>" . __('Nenhum dashboard disponível para o seu usuário no momento.', 'analyticdesign') . "</p>";
            return;
        }

        $groups = [];
        foreach ($visible as $item) {
            $moduleKey = $item->fields['category'] !== '' ? $item->fields['category'] : '';
            $label = $moduleKey !== ''
                ? (DashboardItem::MODULE_LABELS[$moduleKey] ?? $moduleKey)
                : __('Pellissari Grafana Integration', 'analyticdesign');
            $groups[$label][] = $item;
        }
        ksort($groups);

        global $CFG_GLPI;
        $homeRoot = $CFG_GLPI['root_doc'] . '/plugins/plugingrafanaintegration/front/homedashboard.php';

        echo "<div class='analyticdesign-central-dashboards d-flex flex-column gap-3'>";
        foreach ($groups as $label => $items) {
            echo "<div class='card mb-0'>";
            echo "<div class='card-header'><span class='card-title mb-0'>" . htmlspecialchars($label, ENT_QUOTES) . "</span></div>";
            echo "<div class='list-group list-group-flush'>";
            foreach ($items as $item) {
                $id = (int)$item->fields['id'];
                echo "<a class='list-group-item list-group-item-action d-flex align-items-center gap-2' href='"
                    . htmlspecialchars($homeRoot . '?id=' . $id, ENT_QUOTES) . "'>"
                    . "<i class='ti ti-layout-dashboard'></i> " . htmlspecialchars($item->fields['name'], ENT_QUOTES)
                    . "</a>";
            }
            echo "</div>"; // .list-group
            echo "</div>"; // .card
        }
        echo "</div>";
    }
}
