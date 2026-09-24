<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Aba "Conexão" no formulário da Connection — URL base, token de API
 * (backend do plugin) e usuário dedicado do Grafana (Viewer, usado pelo
 * proxy — ver GrafanaSource::proxySession()). Antes esses campos ficavam
 * direto na aba padrão ("Fonte de Dados", que hoje só tem Nome/Ferramenta/
 * Status/Comentários — ver Connection::showForm()); viraram uma aba própria
 * a pedido, para separar "o que é a fonte" de "como o plugin se conecta a
 * ela".
 *
 * Não é uma entidade de banco (extends CommonGLPI, sem tabela própria) — só
 * pluga no sistema de abas do GLPI sobre `Connection`, via
 * `addStandardTab(self::class, ...)` em `Connection::defineTabs()`. Só
 * aparece para uma Connection já salva (mesma regra de qualquer
 * addStandardTab — ver docblock de Connection::defineTabs()).
 *
 * O formulário desta aba posta direto para front/connection.form.php (fluxo
 * `update` padrão de CommonDBTM — Connection::prepareInputForUpdate() já
 * cuida de criptografar/mesclar os campos sensíveis, ver
 * Connection::handleCredentialInput()), com seu próprio botão "Salvar" — não
 * é mais o `addbuttons` do formulário principal (isso só existe dentro de um
 * único showForm()/showFormButtons(); como a seção virou uma aba separada,
 * cada aba tem seu próprio form/submit, mesmo padrão que
 * DashboardItem::showDashboardConfigurationSection() já usa para os
 * formulários de importação).
 */

namespace GlpiPlugin\Plugingrafanaintegration;

use CommonGLPI;
use GlpiPlugin\Plugingrafanaintegration\Source\GrafanaSource;
use GlpiPlugin\Plugingrafanaintegration\Source\SourceFactory;
use GlpiPlugin\Plugingrafanaintegration\Traits\HasFormFieldLayout;
use Html;

class ConnectionCredentials extends CommonGLPI
{
    use HasFormFieldLayout;

    public static function getTypeName($nb = 0)
    {
        return __('Conexão', 'analyticdesign');
    }

    public static function getIcon()
    {
        return 'ti ti-plug';
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

        self::showForConnection($item);

        return true;
    }

    /**
     * Mesma checagem que ConnectionCharacteristics já faz antes de montar o
     * formulário de importação: sem UPDATE, mostra só um aviso — sem isso,
     * um usuário com apenas READ preenchia credenciais inteiras só para
     * levar "Acesso negado" ao salvar.
     */
    private static function showForConnection(Connection $connection): void
    {
        $connectionsId = (int)$connection->fields['id'];

        if (!$connection->can($connectionsId, UPDATE)) {
            echo "<p class='alert alert-important alert-warning'>"
                . htmlspecialchars(__('Você não tem direito de editar esta fonte de dados.', 'analyticdesign'), ENT_QUOTES)
                . "</p>";
            return;
        }

        $isGrafana = $connection->fields['type'] === GrafanaSource::getType();
        if (!$isGrafana) {
            echo "<p class='text-muted'>" . __('Nenhuma configuração de conexão para esta ferramenta.', 'analyticdesign') . "</p>";
            return;
        }

        self::showGrafanaCredentialsSection($connection, $connectionsId);
    }

    /**
     * URL base, token de backend e usuário/senha dedicados do Grafana.
     * Reaproveita as mesmas classes/JS de sempre
     * (`.analyticdesign-characteristics` + `.analyticdesign-error`/
     * `.analyticdesign-fields-wrapper`/`.analyticdesign-status-toggle`) para
     * o "Testar conexão" e o tratamento de falha funcionarem sem duplicar JS
     * — ver public/js/analyticdesign.js (seletores globais, não escopados a
     * uma aba específica).
     */
    private static function showGrafanaCredentialsSection(Connection $connection, int $connectionsId): void
    {
        $fieldsForType = SourceFactory::getConfigFieldsFor(GrafanaSource::getType());
        $credentials = $connection->getDecryptedCredentials();
        $isActive = (int)($connection->fields['is_active'] ?? 0) === 1;
        $hasToken = !empty($credentials['api_token'] ?? '');

        // Caminho ABSOLUTO (a partir de $CFG_GLPI['root_doc']), não relativo:
        // o conteúdo desta aba é carregado via AJAX pelo próprio GLPI (ver
        // docblock de public/js/analyticdesign.js sobre isso), então a URL
        // "atual" no navegador ao renderizar este HTML nem sempre é a de
        // front/connection.form.php — mesmo padrão que
        // DashboardItem::ajaxRoot() já usa para os formulários da aba
        // "Configurações", em vez de um `action="../front/..."` relativo.
        global $CFG_GLPI;
        $formAction = $CFG_GLPI['root_doc'] . '/plugins/plugingrafanaintegration/front/connection.form.php';

        echo "<div class='analyticdesign-characteristics analyticdesign-status-toggle' style='"
            . ($isActive ? '' : 'display:none;') . "'>";
        echo "<div class='card mb-0'>";
        echo "<div class='card-header'><span class='card-title mb-0 d-flex align-items-center gap-2'>"
            . "<i class='ti ti-plug'></i> " . __('Conexão com o Grafana', 'analyticdesign') . "</span></div>";
        echo "<div class='card-body'>";
        echo "<div class='analyticdesign-error alert alert-important alert-danger' style='display:none;'>";
        echo "<i class='ti ti-plug-x'></i> <span class='analyticdesign-error-message'></span>";
        echo " <button type='button' class='btn btn-sm btn-outline-danger analyticdesign-reopen-fields'>"
            . __('Editar configuração', 'analyticdesign') . "</button>";
        echo "</div>";

        echo "<form method='post' action='" . htmlspecialchars($formAction, ENT_QUOTES) . "'>";
        echo "<input type='hidden' name='id' value='{$connectionsId}'>";

        echo "<div class='analyticdesign-fields-wrapper'>";

        self::openFieldsRow();
        self::openField('base_url', __('URL base', 'analyticdesign'), 'analyticdesign_grafana_base_url', true);
        echo Html::input('base_url', ['id' => 'analyticdesign_grafana_base_url', 'value' => $connection->fields['base_url']]);
        echo "<div class='form-text text-muted'>" . __('Ex.: https://grafana.suaempresa.com', 'analyticdesign') . "</div>";
        self::closeField();
        self::closeFieldsRow();

        // O usuário dedicado (abaixo) é quem resolve autenticação do embed —
        // sua sessão é repassada pelo proxy do plugin (ver
        // GrafanaSource::proxySession()) a TODA requisição que o navegador
        // do usuário final faz ao dashboard (HTML, JS/CSS, API do Grafana).
        // Precisa de um papel Viewer com acesso a todos os dashboards a
        // expor — sem isso configurado, o embed cai na tela de login do
        // Grafana dentro do card, do mesmo jeito que sem o usuário dedicado.
        echo "<div class='alert alert-important alert-info' style='margin-bottom:1rem;'>"
            . "<i class='ti ti-info-circle'></i> "
            . __('Crie no Grafana um usuário (ou Service Account com login/senha, se sua versão suportar) com papel Viewer e acesso a todos os dashboards a expor — o proxy deste plugin usa a sessão dele para autenticar o embed de TODOS os usuários do GLPI. Quem vê cada dashboard continua controlado pela aba "Visibilidade" e pelo direito de perfil "Análise de Dados: aba Grafana na Central".', 'analyticdesign')
            . "</div>";

        foreach ($fieldsForType as $field) {
            $fieldId = 'analyticdesign_grafana_' . $field['name'];
            $isConfigured = !empty($credentials[$field['name']] ?? '');
            $placeholder = $isConfigured ? self::configuredPlaceholder() : '';
            self::openFieldsRow();
            self::openField($field['name'], htmlspecialchars($field['label'], ENT_QUOTES), $fieldId, true);
            if (($field['type'] ?? '') === 'password') {
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
        echo "<p class='text-muted fst-italic'>"
            . __('Deixe os campos de credenciais em branco para manter os valores já salvos.', 'analyticdesign')
            . "</p>";

        echo "<div class='mt-2'><span class='analyticdesign-test-result'></span></div>";

        echo "<div class='mt-3 d-flex gap-2'>";
        echo "<button type='submit' name='update' class='btn btn-primary'><i class='ti ti-device-floppy'></i> " . __('Salvar') . "</button>";
        echo "<button type='button' class='btn btn-outline-secondary analyticdesign-test-connection'"
            . " data-id='{$connectionsId}' data-has-credentials='" . ($hasToken ? '1' : '0') . "'"
            . " style='" . ($hasToken ? '' : 'display:none;') . "'>"
            . "<i class='ti ti-plug'></i> " . __('Testar conexão', 'analyticdesign') . "</button>";
        echo "</div>";

        echo "</div>"; // .analyticdesign-fields-wrapper
        Html::closeForm();

        echo "</div>"; // .card-body
        echo "</div>"; // .card
        echo "</div>"; // .analyticdesign-characteristics
    }
}
