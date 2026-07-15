<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Aba "Características" no formulário da Connection: URL base, modo de
 * embed e credenciais específicas do tipo de fonte já escolhido.
 *
 * Separada do formulário principal (Connection::showForm()) a pedido: a
 * criação de uma fonte deve pedir só Nome/Ferramenta/Ativo; as
 * especificações de cada ferramenta só fazem sentido depois que o tipo já
 * está salvo. Como o tipo não muda mais nesta aba, os campos são resolvidos
 * no servidor a partir de `$item->fields['type']` — sem precisar de JS para
 * alternar entre "campos do Grafana" e "campos do Power BI" (só o modo de
 * embed do Power BI, que pode ser trocado aqui mesmo antes de salvar,
 * continua com toggle em JS — ver public/js/analyticdesign.js).
 *
 * Não é uma entidade de banco (extends CommonGLPI, sem tabela própria) — só
 * pluga no sistema de abas do GLPI sobre `Connection`, via
 * `addStandardTab(self::class, ...)` em `Connection::defineTabs()`. Essa aba
 * só é chamada para itens já salvos (`CommonGLPI::defineAllTabs()` só chama
 * `addStandardTab()` fora do fluxo de criação), então nunca aparece no
 * formulário de uma Connection nova.
 */

namespace GlpiPlugin\Analyticdesign;

use CommonGLPI;
use Dropdown;
use GlpiPlugin\Analyticdesign\Source\DashboardSourceInterface;
use GlpiPlugin\Analyticdesign\Source\PowerBiSource;
use GlpiPlugin\Analyticdesign\Source\SourceFactory;
use Html;

class ConnectionCharacteristics extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Características', 'analyticdesign');
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

        global $CFG_GLPI;
        $formUrl = $CFG_GLPI['root_doc'] . '/plugins/analyticdesign/front/connection.form.php';

        echo "<div class='analyticdesign-characteristics'>";

        // Substitui os campos quando "Testar conexão" falha (ver
        // public/js/analyticdesign.js) — a mensagem real vem do JSON do
        // endpoint de teste, não é fixa aqui.
        echo "<div class='analyticdesign-error alert alert-important alert-danger' style='display:none;'>";
        echo "<i class='ti ti-plug-x'></i> <span class='analyticdesign-error-message'></span>";
        echo " <button type='button' class='btn btn-sm btn-outline-danger analyticdesign-reopen-fields'>"
            . __('Editar configuração', 'analyticdesign') . "</button>";
        echo "</div>";

        echo "<div class='analyticdesign-fields-wrapper'>";
        echo "<form name='analyticdesign_characteristics' method='post' action='"
            . htmlspecialchars($formUrl, ENT_QUOTES) . "'>";
        echo "<input type='hidden' name='id' value='" . (int)$item->fields['id'] . "'>";
        echo "<table class='tab_cadre_fixe'>";

        self::showBaseUrlField($item);
        self::showEmbedModeField($item);
        self::showCredentialFields($item);

        echo "</table>";
        echo "<div class='mt-2'>";
        echo "<button type='submit' name='update' class='btn btn-primary'>" . __('Salvar') . "</button>";
        echo " <button type='button' class='btn btn-outline-secondary analyticdesign-test-connection' data-id='"
            . (int)$item->fields['id'] . "'>"
            . "<i class='ti ti-plug'></i> " . __('Testar conexão', 'analyticdesign')
            . "</button> <span class='analyticdesign-test-result ms-2'></span>";
        echo "</div>";
        Html::closeForm();
        echo "</div>"; // .analyticdesign-fields-wrapper

        echo "</div>"; // .analyticdesign-characteristics

        return true;
    }

    private static function showBaseUrlField(Connection $item): void
    {
        echo "<tr class='tab_bg_1'>";
        echo "<td class='analyticdesign-field-cell'>" . __('URL base', 'analyticdesign') . "</td>";
        echo "<td class='analyticdesign-field-cell' colspan='3'>"
            . Html::input('base_url', ['value' => $item->fields['base_url'], 'size' => 60])
            . "<div class='form-text text-muted'>" . __('Ex.: https://grafana.suaempresa.com', 'analyticdesign') . "</div>"
            . "</td></tr>";
    }

    /** Modo de embed: só relevante para Power BI. */
    private static function showEmbedModeField(Connection $item): void
    {
        if ($item->fields['type'] !== PowerBiSource::getType()) {
            return;
        }

        $embedModes = [
            DashboardSourceInterface::EMBED_MODE_PUBLISH_TO_WEB => __('Publish to web — URL pública (Power BI)', 'analyticdesign'),
            DashboardSourceInterface::EMBED_MODE_SECURE         => __('Embed seguro — Entra ID / Premium (Power BI)', 'analyticdesign'),
        ];
        $isPublishToWeb = $item->fields['embed_mode'] === DashboardSourceInterface::EMBED_MODE_PUBLISH_TO_WEB;

        echo "<tr class='tab_bg_1'>";
        echo "<td class='analyticdesign-field-cell'>" . __('Modo de embed', 'analyticdesign') . "</td>";
        echo "<td class='analyticdesign-field-cell' colspan='3'>";
        Dropdown::showFromArray('embed_mode', $embedModes, ['value' => $item->fields['embed_mode']]);
        echo "<div class='analyticdesign-publish-warning alert alert-important alert-danger' style='margin-top:.5rem;"
            . ($isPublishToWeb ? '' : 'display:none;') . "'>"
            . "<i class='ti ti-alert-triangle'></i> "
            . __('Atenção: "Publish to web" deixa o conteúdo acessível a qualquer pessoa com o link, sem autenticação. Não use para dados confidenciais.', 'analyticdesign')
            . "</div>";
        echo "</td></tr>";
    }

    /**
     * Campos de credencial do tipo já salvo — resolvidos no servidor, não
     * precisam de JS para escolher "qual conjunto de campos mostrar". Para
     * Power BI, os campos ainda variam pelo modo de embed selecionado (que
     * pode ser trocado aqui mesmo, sem reload) — esses continuam com toggle
     * em JS via `data-embed-mode` (ver public/js/analyticdesign.js).
     */
    private static function showCredentialFields(Connection $item): void
    {
        $fieldsForType = SourceFactory::getConfigFieldsFor($item->fields['type']);
        if (empty($fieldsForType)) {
            return;
        }

        foreach ($fieldsForType as $field) {
            self::showCredentialFieldRow($item, $field);
        }

        echo "<tr class='tab_bg_1'><td colspan='4'><em>"
            . __('Deixe os campos de credenciais em branco para manter os valores já salvos.', 'analyticdesign')
            . "</em></td></tr>";
    }

    private static function showCredentialFieldRow(Connection $item, array $field): void
    {
        $inputType = $field['type'] === 'password' ? 'password' : 'text';

        // Só campos marcados com 'embed_mode' (hoje, os do modo "secure" do
        // Power BI) entram no toggle de JS — os demais (ex.: api_token do
        // Grafana) ficam sempre visíveis, sem a classe/atributo de toggle.
        $rowClass = 'tab_bg_1';
        $rowAttr  = '';
        if (isset($field['embed_mode'])) {
            $rowClass .= ' analyticdesign-embed-mode-field';
            $hidden = $field['embed_mode'] !== $item->fields['embed_mode'] ? 'display:none;' : '';
            $rowAttr = " data-embed-mode='" . htmlspecialchars($field['embed_mode'], ENT_QUOTES) . "'"
                . " style='{$hidden}'";
        }

        echo "<tr class='{$rowClass}'{$rowAttr}>";
        echo "<td class='analyticdesign-field-cell'>" . htmlspecialchars($field['label'], ENT_QUOTES) . "</td>";
        echo "<td class='analyticdesign-field-cell' colspan='3'>"
            . Html::input($field['name'], ['type' => $inputType, 'value' => '', 'size' => 60]);
        if (!empty($field['help'])) {
            echo "<div class='form-text text-muted'>" . htmlspecialchars($field['help'], ENT_QUOTES) . "</div>";
        }
        echo "</td></tr>";
    }
}
