<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Aba "Visibilidade" no formulário da Connection: lista as regras
 * (Critérios | Ação) já cadastradas para os dashboards dessa fonte, com um
 * link para criar/editar cada uma na página independente
 * front/visibilityrule.form.php — ver docblock de VisibilityRule sobre por
 * que o formulário em si não é renderizado aqui dentro (nesta aba, carregada
 * via AJAX de common.tabs.php).
 *
 * Não é uma entidade de banco (extends CommonGLPI, sem tabela própria) — só
 * pluga no sistema de abas do GLPI sobre `Connection`, igual a
 * ConnectionCharacteristics.
 */

namespace GlpiPlugin\Analyticdesign;

use CommonGLPI;

class ConnectionVisibilityRules extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Visibilidade', 'analyticdesign');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-check';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Connection && $item->fields['id'] > 0) {
            $count = count(VisibilityRule::getForConnection((int)$item->fields['id']));
            return self::createTabEntry(self::getTypeName(), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Connection)) {
            return false;
        }

        $connectionsId = (int)$item->fields['id'];
        $canEdit = $item->can($connectionsId, UPDATE);

        global $CFG_GLPI;
        $formUrl = $CFG_GLPI['root_doc'] . '/plugins/analyticdesign/front/visibilityrule.form.php';

        echo "<div class='analyticdesign-visibility-rules'>";
        echo "<p class='text-muted'>"
            . __('Regras adicionais de acesso restrito, combinando Critérios (quais dashboards) e Ação (quem ganha acesso). Só valem para dashboards com Visibilidade "Restrito a...", como complemento ao ajuste feito diretamente no card.', 'analyticdesign')
            . "</p>";

        self::showRulesTable(VisibilityRule::getForConnection($connectionsId), $formUrl, $canEdit);

        if ($canEdit) {
            echo "<div class='mt-3'>";
            echo "<a class='btn btn-primary' href='"
                . htmlspecialchars($formUrl . '?connections_id=' . $connectionsId, ENT_QUOTES) . "'>"
                . "<i class='ti ti-plus'></i> " . __('Adicionar regra', 'analyticdesign') . "</a>";
            echo "</div>";
        }

        echo "</div>";

        return true;
    }

    /** @param VisibilityRule[] $rules */
    private static function showRulesTable(array $rules, string $formUrl, bool $canEdit): void
    {
        if (empty($rules)) {
            echo "<p class='text-muted'>" . __('Nenhuma regra de visibilidade cadastrada ainda.', 'analyticdesign') . "</p>";
            return;
        }

        echo "<table class='tab_cadre_fixe'><tr class='tab_bg_1'>";
        echo "<th>" . __('Nome') . "</th>";
        echo "<th>" . __('Critérios', 'analyticdesign') . "</th>";
        echo "<th>" . __('Ação', 'analyticdesign') . "</th>";
        echo "<th>" . __('Combinar com', 'analyticdesign') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        if ($canEdit) {
            echo "<th>" . __('Editar') . "</th>";
        }
        echo "</tr>";

        foreach ($rules as $rule) {
            $id = (int)$rule->fields['id'];
            $match = (string)$rule->fields['match'];

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . htmlspecialchars($rule->fields['name'], ENT_QUOTES) . "</td>";
            echo "<td>" . htmlspecialchars(VisibilityRule::summarizeCriteria($id, $match), ENT_QUOTES) . "</td>";
            echo "<td>" . htmlspecialchars(VisibilityRule::summarizeActions($id), ENT_QUOTES) . "</td>";
            echo "<td>" . ($match === 'OR' ? __('OU', 'analyticdesign') : __('E', 'analyticdesign')) . "</td>";
            echo "<td>" . ((int)$rule->fields['is_active'] === 1 ? __('Sim') : __('Não')) . "</td>";
            if ($canEdit) {
                echo "<td><a class='btn btn-sm btn-outline-secondary' href='"
                    . htmlspecialchars($formUrl . '?id=' . $id, ENT_QUOTES) . "'>"
                    . "<i class='ti ti-pencil'></i> " . __('Editar') . "</a></td>";
            }
            echo "</tr>";
        }

        echo "</table>";
    }
}
