<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Aba "Configurações" no formulário da Connection (rótulo — a classe/tabela
 * de abas internamente continua "ConnectionCharacteristics" por
 * continuidade de código/histórico).
 *
 * Para Power BI: URL base, modo de embed e credenciais específicas do tipo
 * já escolhido — igual desde sempre, nada mudou aqui.
 *
 * Para Grafana: URL base e API token saíram daqui e foram para a aba "Fonte
 * de dados" (ver Connection::showGrafanaCredentialsSection()) — esta aba
 * mostra só a configuração de dashboards (seleção/importação, módulo,
 * visibilidade, substituição de módulo), delegada a
 * DashboardItem::showDashboardConfigurationSection().
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
use GlpiPlugin\Analyticdesign\Traits\HasFormFieldLayout;
use Html;

class ConnectionCharacteristics extends CommonGLPI
{
    use HasFormFieldLayout;

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

        if ($item->fields['type'] === PowerBiSource::getType()) {
            self::showPowerBiFieldsBlock($item);
        }

        DashboardItem::showDashboardConfigurationSection($item, (int)$item->fields['id'], DashboardItem::ajaxRoot());

        echo "</div>"; // .analyticdesign-characteristics

        return true;
    }

    /** URL base, modo de embed e credenciais do Power BI — inalterado desde sempre. */
    private static function showPowerBiFieldsBlock(Connection $item): void
    {
        global $CFG_GLPI;
        $formUrl = $CFG_GLPI['root_doc'] . '/plugins/analyticdesign/front/connection.form.php';

        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><span class='card-title mb-0 d-flex align-items-center gap-2'>"
            . "<i class='ti ti-plug'></i> " . __('Conexão com o Power BI', 'analyticdesign') . "</span></div>";
        echo "<div class='card-body'>";

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

        self::showBaseUrlField($item);
        self::showEmbedModeField($item);
        self::showCredentialFields($item);

        echo "<div class='mt-2'>";
        echo "<button type='submit' name='update' class='btn btn-primary'>" . __('Salvar') . "</button>";
        echo " <button type='button' class='btn btn-outline-secondary analyticdesign-test-connection' data-id='"
            . (int)$item->fields['id'] . "'>"
            . "<i class='ti ti-plug'></i> " . __('Testar conexão', 'analyticdesign')
            . "</button> <span class='analyticdesign-test-result ms-2'></span>";
        echo "</div>";
        Html::closeForm();
        echo "</div>"; // .analyticdesign-fields-wrapper

        echo "</div>"; // .card-body
        echo "</div>"; // .card
    }

    private static function showBaseUrlField(Connection $item): void
    {
        self::openFieldsRow();
        self::openField('base_url', __('URL base', 'analyticdesign'), 'analyticdesign_base_url', true);
        echo Html::input('base_url', ['id' => 'analyticdesign_base_url', 'value' => $item->fields['base_url']]);
        echo "<div class='form-text text-muted'>" . __('Ex.: https://grafana.suaempresa.com', 'analyticdesign') . "</div>";
        self::closeField();
        self::closeFieldsRow();
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

        self::openFieldsRow();
        self::openField('embed_mode', __('Modo de embed', 'analyticdesign'), 'dropdown_embed_mode3', true);
        Dropdown::showFromArray('embed_mode', $embedModes, ['value' => $item->fields['embed_mode'], 'rand' => 3]);
        echo "<div class='analyticdesign-publish-warning alert alert-important alert-danger' style='margin-top:.5rem;"
            . ($isPublishToWeb ? '' : 'display:none;') . "'>"
            . "<i class='ti ti-alert-triangle'></i> "
            . __('Atenção: "Publish to web" deixa o conteúdo acessível a qualquer pessoa com o link, sem autenticação. Não use para dados confidenciais.', 'analyticdesign')
            . "</div>";
        self::closeField();
        self::closeFieldsRow();
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

        echo "<p class='text-muted fst-italic'>"
            . __('Deixe os campos de credenciais em branco para manter os valores já salvos.', 'analyticdesign')
            . "</p>";
    }

    private static function showCredentialFieldRow(Connection $item, array $field): void
    {
        static $rand = 10;
        $rand++;

        $inputType = $field['type'] === 'password' ? 'password' : 'text';
        $fieldId   = 'analyticdesign_' . $field['name'];

        // Só campos marcados com 'embed_mode' (hoje, os do modo "secure" do
        // Power BI) entram no toggle de JS — os demais (ex.: api_token do
        // Grafana) ficam sempre visíveis, sem a classe/atributo de toggle.
        $extraClass = '';
        $extraAttr  = '';
        if (isset($field['embed_mode'])) {
            $extraClass = 'analyticdesign-embed-mode-field';
            $hidden = $field['embed_mode'] !== $item->fields['embed_mode'] ? 'display:none;' : '';
            $extraAttr = " data-embed-mode='" . htmlspecialchars($field['embed_mode'], ENT_QUOTES) . "'"
                . " style='{$hidden}'";
        }

        $isConfigured = !empty($item->getDecryptedCredentials()[$field['name']] ?? '');
        $placeholder = $isConfigured ? self::configuredPlaceholder() : '';

        self::openFieldsRow();
        self::openField($field['name'], htmlspecialchars($field['label'], ENT_QUOTES), $fieldId, true, $extraClass, $extraAttr);
        if ($inputType === 'password') {
            self::showDisclosablePasswordInput($field['name'], $fieldId, '', $placeholder);
        } else {
            echo Html::input($field['name'], ['id' => $fieldId, 'value' => '', 'placeholder' => $placeholder]);
        }
        if (!empty($field['help'])) {
            echo "<div class='form-text text-muted'>" . htmlspecialchars($field['help'], ENT_QUOTES) . "</div>";
        }
        self::closeField();
        self::closeFieldsRow();
    }
}
