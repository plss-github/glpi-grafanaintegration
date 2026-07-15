<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Fonte de BI cadastrada (uma linha por Grafana/Power BI configurado).
 * Tabela: glpi_plugin_analyticdesign_connections
 */

namespace GlpiPlugin\Analyticdesign;

use CommonDBTM;
use Dropdown;
use GLPIKey;
use GlpiPlugin\Analyticdesign\Source\DashboardSourceInterface;
use GlpiPlugin\Analyticdesign\Source\PowerBiSource;
use GlpiPlugin\Analyticdesign\Source\SourceFactory;
use GlpiPlugin\Analyticdesign\Traits\HasCheckboxField;
use Html;

class Connection extends CommonDBTM
{
    use HasCheckboxField;

    /**
     * Único direito do plugin, compartilhado por Connection e DashboardItem
     * (DashboardItem é sempre filho de uma Connection — não faz sentido um
     * direito separado). Centralizado aqui para não repetir a mesma string
     * em DashboardItem::$rightname e hook.php::plugin_analyticdesign_getrights().
     */
    public const RIGHTNAME = 'plugin_analyticdesign_connection';

    public static $rightname = self::RIGHTNAME;

    /** Histórico de alterações na aba "Histórico" do item. */
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Fonte de dados', 'Fontes de dados', $nb, 'analyticdesign');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-dots';
    }

    /**
     * Aba "Dashboards" (DashboardItem) exibida no formulário da conexão,
     * onde o admin marca quais dashboards expor. Ver DashboardItem::getTabNameForItem().
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(DashboardItem::class, $tabs, $options);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => '10',
            'table'    => self::getTable(),
            'field'    => 'type',
            'name'     => __('Ferramenta', 'analyticdesign'),
            'datatype' => 'specific',
        ];
        $tab[] = [
            'id'       => '11',
            'table'    => self::getTable(),
            'field'    => 'base_url',
            'name'     => __('URL base', 'analyticdesign'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => '12',
            'table'    => self::getTable(),
            'field'    => 'embed_mode',
            'name'     => __('Modo de embed', 'analyticdesign'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => '13',
            'table'    => self::getTable(),
            'field'    => 'is_active',
            'name'     => __('Ativo'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => '19',
            'table'    => self::getTable(),
            'field'    => 'date_mod',
            'name'     => __('Última atualização'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => '121',
            'table'    => self::getTable(),
            'field'    => 'date_creation',
            'name'     => __('Data de criação'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        return $tab;
    }

    /** Rótulo legível do tipo, usado pelo datatype 'specific' na busca. */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'type') {
            $types = SourceFactory::getAvailableTypes();
            return $types[$values['type']] ?? $values['type'];
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /** Instancia a implementação de fonte (Grafana/Power BI) associada. */
    public function getSource(): DashboardSourceInterface
    {
        return SourceFactory::make($this);
    }

    /**
     * Carrega a Connection de ID `$id` só se o usuário atual tem `$right`
     * nela (direito **e** escopo de entidade, via `can()`) — usado pelos
     * endpoints em `ajax/` para evitar repetir esse boilerplate de
     * autorização em cada arquivo. Devolve `null` em vez de lançar/exibir um
     * erro para deixar cada chamador decidir como responder (JSON, página de
     * erro etc.).
     */
    public static function loadAuthorized(int $id, int $right): ?self
    {
        $connection = new self();
        if ($id <= 0 || !$connection->can($id, $right)) {
            return null;
        }
        return $connection;
    }

    /**
     * Formulário de cadastro/edição da fonte. Renderizado em PHP/HTML puro
     * (showFormHeader/showFormButtons) em vez de templates Twig: sem uma
     * instância GLPI 11 real para validar os macros de `fields_macros.html.twig`,
     * essa é a via de menor risco. Os campos de credenciais de cada tipo ficam
     * todos no DOM e são mostrados/escondidos por JS conforme o tipo escolhido
     * (ver public/js/analyticdesign.js), sem chamada AJAX extra.
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $this->showNameAndToolFields();
        $this->showBaseUrlField();
        $this->showEmbedModeField();
        $this->showCredentialFields();
        $this->showActiveField();
        $this->showTestConnectionButton();

        $this->showFormButtons($options);

        return true;
    }

    private function showNameAndToolFields(): void
    {
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Nome') . "</td>";
        echo "<td>" . Html::input('name', ['value' => $this->fields['name']]) . "</td>";
        echo "<td>" . __('Ferramenta', 'analyticdesign') . "</td>";
        echo "<td>";
        Dropdown::showFromArray('type', SourceFactory::getAvailableTypes(), ['value' => $this->fields['type']]);
        echo "</td></tr>";
    }

    private function showBaseUrlField(): void
    {
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('URL base', 'analyticdesign') . "</td>";
        echo "<td colspan='3'>"
            . Html::input('base_url', ['value' => $this->fields['base_url'], 'size' => 60])
            . "<div class='form-text text-muted'>" . __('Ex.: https://grafana.suaempresa.com', 'analyticdesign') . "</div>"
            . "</td></tr>";
    }

    /**
     * Modo de embed: só relevante para Power BI. Aparece antes dos campos de
     * credenciais porque decide QUAIS campos de credencial do Power BI fazem
     * sentido (publish_to_web não usa nenhum — a URL pública é colada por
     * dashboard, na aba "Dashboards").
     */
    private function showEmbedModeField(): void
    {
        $embedModes = [
            DashboardSourceInterface::EMBED_MODE_IFRAME         => __('Iframe direto (Grafana)', 'analyticdesign'),
            DashboardSourceInterface::EMBED_MODE_PUBLISH_TO_WEB => __('Publish to web — URL pública (Power BI)', 'analyticdesign'),
            DashboardSourceInterface::EMBED_MODE_SECURE         => __('Embed seguro — Entra ID / Premium (Power BI)', 'analyticdesign'),
        ];

        echo "<tr class='tab_bg_2 analyticdesign-fields-for-type' data-source-type='"
            . htmlspecialchars(PowerBiSource::getType(), ENT_QUOTES) . "'>";
        echo "<td>" . __('Modo de embed', 'analyticdesign') . "</td>";
        echo "<td colspan='3'>";
        Dropdown::showFromArray('embed_mode', $embedModes, ['value' => $this->fields['embed_mode']]);
        echo "<div class='analyticdesign-publish-warning alert alert-important alert-danger' style='display:none;margin-top:.5rem;'>"
            . "<i class='ti ti-alert-triangle'></i> "
            . __('Atenção: "Publish to web" deixa o conteúdo acessível a qualquer pessoa com o link, sem autenticação. Não use para dados confidenciais.', 'analyticdesign')
            . "</div>";
        echo "</td></tr>";
    }

    /**
     * Campos de credencial de todos os tipos ficam no DOM e são
     * mostrados/escondidos por JS conforme o tipo/modo escolhido (ver
     * public/js/analyticdesign.js), sem chamada AJAX extra.
     */
    private function showCredentialFields(): void
    {
        $types        = SourceFactory::getAvailableTypes();
        $configFields = SourceFactory::getAllConfigFields();

        foreach ($configFields as $type => $fieldsForType) {
            if (empty($fieldsForType)) {
                continue;
            }
            echo "<tr class='tab_bg_2 analyticdesign-fields-for-type' data-source-type='" . htmlspecialchars($type, ENT_QUOTES) . "'>";
            echo "<td colspan='4'><strong>" . htmlspecialchars($types[$type] ?? $type, ENT_QUOTES) . "</strong></td>";
            echo "</tr>";
            foreach ($fieldsForType as $field) {
                $this->showCredentialFieldRow($type, $field);
            }
        }

        echo "<tr class='tab_bg_1'><td colspan='4'><em>"
            . __('Deixe os campos de credenciais em branco para manter os valores já salvos.', 'analyticdesign')
            . "</em></td></tr>";
    }

    private function showCredentialFieldRow(string $type, array $field): void
    {
        $inputType = $field['type'] === 'password' ? 'password' : 'text';
        $embedModeAttr = isset($field['embed_mode'])
            ? " data-embed-mode='" . htmlspecialchars($field['embed_mode'], ENT_QUOTES) . "'"
            : '';

        echo "<tr class='tab_bg_1 analyticdesign-fields-for-type' data-source-type='"
            . htmlspecialchars($type, ENT_QUOTES) . "'{$embedModeAttr}>";
        echo "<td>" . htmlspecialchars($field['label'], ENT_QUOTES) . "</td>";
        echo "<td colspan='3'>"
            . Html::input($field['name'], ['type' => $inputType, 'value' => '', 'size' => 60]);
        if (!empty($field['help'])) {
            echo "<div class='form-text text-muted'>" . htmlspecialchars($field['help'], ENT_QUOTES) . "</div>";
        }
        echo "</td></tr>";
    }

    private function showActiveField(): void
    {
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Ativo') . "</td>";
        echo "<td>" . self::renderCheckbox('is_active', (int)($this->fields['is_active'] ?? 0) === 1) . "</td>";
        echo "<td colspan='2'></td></tr>";
    }

    private function showTestConnectionButton(): void
    {
        if ((int)$this->fields['id'] <= 0) {
            return;
        }

        echo "<tr class='tab_bg_1'><td colspan='4'>";
        echo "<button type='button' class='btn btn-outline-secondary analyticdesign-test-connection' data-id='"
            . (int)$this->fields['id'] . "'>"
            . "<i class='ti ti-plug'></i> " . __('Testar conexão', 'analyticdesign')
            . "</button> <span class='analyticdesign-test-result ms-2'></span>";
        echo "</td></tr>";
    }

    /**
     * Devolve as credenciais descriptografadas como array.
     * O campo `credentials` é gravado como JSON criptografado via GLPIKey.
     */
    public function getDecryptedCredentials(): array
    {
        $raw = $this->fields['credentials'] ?? '';
        if ($raw === '' || $raw === null) {
            return [];
        }
        try {
            $json = (new GLPIKey())->decrypt($raw);
            $data = json_decode($json, true);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Criptografa e grava credenciais a partir de um array.
     * Chamar antes de add()/update().
     */
    public function setCredentials(array $credentials): void
    {
        $json = json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->fields['credentials'] = (new GLPIKey())->encrypt($json);
    }

    public function prepareInputForAdd($input)
    {
        return $this->handleCredentialInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->handleCredentialInput($input);
    }

    /**
     * Coleta campos sensíveis vindos do form (ex.: api_token, client_secret),
     * mescla com as credenciais já salvas e criptografa em `credentials`,
     * removendo os campos sensíveis do input plano.
     *
     * A mesclagem com `getDecryptedCredentials()` é essencial: a UI permite
     * deixar um campo em branco para "manter o valor salvo" (ver
     * showForm()/analyticdesign.js). Sem mesclar, atualizar só um campo (ex.:
     * `client_secret` de uma fonte Power BI) apagaria silenciosamente os
     * demais (`client_id`, `tenant_id`) já armazenados.
     */
    private function handleCredentialInput($input)
    {
        // O único caminho válido para popular `credentials` é o bloco de
        // criptografia abaixo. Como front/connection.form.php repassa todo o
        // $_POST para update()/add(), um valor de `credentials` enviado
        // diretamente (fora dos campos do formulário) precisa ser descartado
        // aqui antes de qualquer outra coisa — senão um usuário com direito
        // de UPDATE na Connection poderia gravar um blob arbitrário não
        // criptografado nesse campo.
        unset($input['credentials']);

        // workspace_id não é secreto por natureza, mas fica no mesmo blob
        // criptografado por simplicidade (evita migração para uma coluna nova
        // só para esse campo específico do Power BI).
        $sensitive = ['api_token', 'client_id', 'client_secret', 'tenant_id', 'workspace_id'];
        $creds = $this->getDecryptedCredentials();
        $touched = false;
        foreach ($sensitive as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $creds[$key] = $input[$key];
                $touched = true;
            }
            unset($input[$key]);
        }
        if ($touched) {
            $json = json_encode($creds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $input['credentials'] = (new GLPIKey())->encrypt($json);
        }
        return $input;
    }

    /**
     * Definição da tabela (chamada no install).
     */
    public static function install(\Migration $migration): void
    {
        global $DB;
        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $DB->doQuery("
                CREATE TABLE `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(255) NOT NULL DEFAULT '',
                    `type` VARCHAR(50) NOT NULL DEFAULT 'grafana',
                    `base_url` VARCHAR(255) NOT NULL DEFAULT '',
                    `credentials` TEXT NULL,
                    `embed_mode` VARCHAR(50) NOT NULL DEFAULT 'iframe',
                    `is_active` TINYINT NOT NULL DEFAULT 1,
                    `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `is_recursive` TINYINT NOT NULL DEFAULT 0,
                    `date_creation` TIMESTAMP NULL DEFAULT NULL,
                    `date_mod` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `type` (`type`),
                    KEY `entities_id` (`entities_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public static function uninstall(): void
    {
        global $DB;
        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }
}
