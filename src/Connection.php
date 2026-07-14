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
use Glpi\Security\GLPIKey;
use GlpiPlugin\Analyticdesign\Source\SourceFactory;
use Html;

class Connection extends CommonDBTM
{
    public static $rightname = 'plugin_analyticdesign_connection';

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
    public function getSource(): \GlpiPlugin\Analyticdesign\Source\DashboardSourceInterface
    {
        return SourceFactory::make($this);
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

        $types        = SourceFactory::getAvailableTypes();
        $configFields = SourceFactory::getAllConfigFields();
        $embedModes   = [
            'iframe'         => __('Iframe direto (Grafana)', 'analyticdesign'),
            'publish_to_web' => __('Publish to web — URL pública (Power BI)', 'analyticdesign'),
            'secure'         => __('Embed seguro — Entra ID / Premium (Power BI)', 'analyticdesign'),
        ];

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Nome') . "</td>";
        echo "<td>" . Html::input('name', ['value' => $this->fields['name']]) . "</td>";
        echo "<td>" . __('Ferramenta', 'analyticdesign') . "</td>";
        echo "<td>";
        Dropdown::showFromArray('type', $types, ['value' => $this->fields['type']]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('URL base', 'analyticdesign') . "</td>";
        echo "<td colspan='3'>"
            . Html::input('base_url', ['value' => $this->fields['base_url'], 'size' => 60])
            . "<div class='form-text text-muted'>" . __('Ex.: https://grafana.suaempresa.com', 'analyticdesign') . "</div>"
            . "</td></tr>";

        foreach ($configFields as $type => $fieldsForType) {
            if (empty($fieldsForType)) {
                continue;
            }
            echo "<tr class='tab_bg_2 analyticdesign-fields-for-type' data-source-type='" . htmlspecialchars($type, ENT_QUOTES) . "'>";
            echo "<td colspan='4'><strong>" . htmlspecialchars($types[$type] ?? $type, ENT_QUOTES) . "</strong></td>";
            echo "</tr>";
            foreach ($fieldsForType as $field) {
                $inputType = $field['type'] === 'password' ? 'password' : 'text';
                echo "<tr class='tab_bg_1 analyticdesign-fields-for-type' data-source-type='" . htmlspecialchars($type, ENT_QUOTES) . "'>";
                echo "<td>" . htmlspecialchars($field['label'], ENT_QUOTES) . "</td>";
                echo "<td colspan='3'>"
                    . Html::input($field['name'], ['type' => $inputType, 'value' => '', 'size' => 60]);
                if (!empty($field['help'])) {
                    echo "<div class='form-text text-muted'>" . htmlspecialchars($field['help'], ENT_QUOTES) . "</div>";
                }
                echo "</td></tr>";
            }
        }
        echo "<tr class='tab_bg_1'><td colspan='4'><em>"
            . __('Deixe os campos de credenciais em branco para manter os valores já salvos.', 'analyticdesign')
            . "</em></td></tr>";

        echo "<tr class='tab_bg_2 analyticdesign-fields-for-type' data-source-type='powerbi'>";
        echo "<td>" . __('Modo de embed', 'analyticdesign') . "</td>";
        echo "<td colspan='3'>";
        Dropdown::showFromArray('embed_mode', $embedModes, ['value' => $this->fields['embed_mode']]);
        echo "<div class='analyticdesign-publish-warning alert alert-important alert-danger' style='display:none;margin-top:.5rem;'>"
            . "<i class='ti ti-alert-triangle'></i> "
            . __('Atenção: "Publish to web" deixa o conteúdo acessível a qualquer pessoa com o link, sem autenticação. Não use para dados confidenciais.', 'analyticdesign')
            . "</div>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Ativo') . "</td>";
        echo "<td><input type='checkbox' name='is_active' value='1'"
            . ((int)($this->fields['is_active'] ?? 0) === 1 ? " checked" : "")
            . "></td>";
        echo "<td colspan='2'></td></tr>";

        if ((int)$this->fields['id'] > 0) {
            echo "<tr class='tab_bg_1'><td colspan='4'>";
            echo "<button type='button' class='btn btn-outline-secondary analyticdesign-test-connection' data-id='"
                . (int)$this->fields['id'] . "'>"
                . "<i class='ti ti-plug'></i> " . __('Testar conexão', 'analyticdesign')
                . "</button> <span class='analyticdesign-test-result ms-2'></span>";
            echo "</td></tr>";
        }

        $this->showFormButtons($options);

        return true;
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
     * criptografa em `credentials` e remove-os do input plano.
     */
    private function handleCredentialInput($input)
    {
        $sensitive = ['api_token', 'client_id', 'client_secret', 'tenant_id'];
        $creds = [];
        foreach ($sensitive as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $creds[$key] = $input[$key];
            }
            unset($input[$key]);
        }
        if (!empty($creds)) {
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
