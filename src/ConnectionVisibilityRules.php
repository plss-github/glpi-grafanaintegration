<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Aba "Visibilidade" no formulário da Connection: TUDO inline (lista de
 * regras, cada uma com sua tabela de Critérios e de Ação, mais uma linha de
 * "adicionar" em cada — ver `VisibilityRule::showRuleBlock()`) e um botão
 * "Adicionar regra" no rodapé. Nenhuma navegação para outra página —
 * `front/visibilityrule.form.php` só processa os POSTs e redireciona de
 * volta para esta mesma aba (`forcetab`).
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
            . __('Regras de acesso restrito: de um lado Critérios (quais dashboards), do outro Ação (quem ganha acesso). Um dashboard sem nenhuma regra apontando pra ele fica visível a todos com o direito de leitura do módulo.', 'analyticdesign')
            . "</p>";

        $rules = VisibilityRule::getForConnection($connectionsId);
        if (empty($rules)) {
            echo "<p class='text-muted'>" . __('Nenhuma regra de visibilidade cadastrada ainda.', 'analyticdesign') . "</p>";
        } else {
            foreach ($rules as $rule) {
                VisibilityRule::showRuleBlock($rule, $formUrl, $canEdit);
            }
        }

        if ($canEdit) {
            echo "<form method='post' action='" . htmlspecialchars($formUrl, ENT_QUOTES) . "'>";
            echo "<input type='hidden' name='action' value='add_rule'>";
            echo "<input type='hidden' name='connections_id' value='{$connectionsId}'>";
            echo "<button type='submit' class='btn btn-primary'><i class='ti ti-plus'></i> " . __('Adicionar regra', 'analyticdesign') . "</button>";
            echo "</form>";
        }

        echo "</div>";

        return true;
    }
}
