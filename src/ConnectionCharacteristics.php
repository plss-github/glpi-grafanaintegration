<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Aba "Configurações" no formulário da Connection (rótulo — a classe/tabela
 * de abas internamente continua "ConnectionCharacteristics" por
 * continuidade de código/histórico).
 *
 * URL base e API token do Grafana ficam na aba "Fonte de dados" (ver
 * Connection::showGrafanaCredentialsSection()) — esta aba mostra só a
 * configuração de dashboards (seleção/importação, módulo, visibilidade,
 * substituição de módulo), delegada a
 * DashboardItem::showDashboardConfigurationSection().
 *
 * Não é uma entidade de banco (extends CommonGLPI, sem tabela própria) — só
 * pluga no sistema de abas do GLPI sobre `Connection`, via
 * `addStandardTab(self::class, ...)` em `Connection::defineTabs()`. Essa aba
 * só é chamada para itens já salvos (`CommonGLPI::defineAllTabs()` só chama
 * `addStandardTab()` fora do fluxo de criação), então nunca aparece no
 * formulário de uma Connection nova.
 */

namespace GlpiPlugin\Plugingrafanaintegration;

use CommonGLPI;

class ConnectionCharacteristics extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Configurações', 'analyticdesign');
    }

    public static function getIcon()
    {
        return 'ti ti-settings';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Connection) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Connection)) {
            return false;
        }

        echo "<div class='analyticdesign-characteristics'>";

        DashboardItem::showDashboardConfigurationSection($item, (int)$item->fields['id'], DashboardItem::ajaxRoot());

        echo "</div>"; // .analyticdesign-characteristics

        return true;
    }
}
