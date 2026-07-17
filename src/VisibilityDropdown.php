<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Dropdown "compartilhar com" para restringir a visibilidade de um
 * DashboardItem — reaproveita `AbstractRightsDropdown`, o mesmo componente
 * (select2 + busca AJAX, agrupado por itemtype) que o GLPI usa para
 * compartilhar seus próprios dashboards nativos (`ShareDashboardDropdown`).
 *
 * Não reaproveita o endpoint AJAX nativo (`ajax/getShareDashboardDropdownValue.php`):
 * ele exige o direito `dashboard` (UPDATE) do core, que não é o direito deste
 * plugin — um usuário com direito de configurar fontes de dados aqui pode
 * perfeitamente não ter (nem precisar ter) o direito nativo de dashboards.
 * Por isso `ajax/getvisibilitydropdownvalue.php` existe: mesma lógica
 * (`AbstractRightsDropdown::fetchValues()`), checagem de direito própria.
 */

namespace GlpiPlugin\Analyticdesign;

use AbstractRightsDropdown;

class VisibilityDropdown extends AbstractRightsDropdown
{
    protected static function getAjaxUrl(): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/analyticdesign/ajax/getvisibilitydropdownvalue.php';
    }

    protected static function getTypes(array $options = []): array
    {
        // ItemVisibility::TARGET_TYPES é a única fonte de verdade da lista
        // de itemtypes suportados — ver docblock lá.
        return ItemVisibility::TARGET_TYPES;
    }
}
