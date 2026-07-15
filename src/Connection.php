<?php

/**
 * Analytic Design
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
use Html;
use Session;

class Connection extends CommonDBTM
{
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
     * Abas "Características" (URL/credenciais específicas do tipo já
     * escolhido) e "Dashboards" (DashboardItem), exibidas no formulário da
     * conexão. Só aparecem para uma Connection já salva — CommonGLPI só
     * chama addStandardTab() para itens não-novos (ver
     * CommonGLPI::defineAllTabs()), então nenhuma lógica extra é necessária
     * aqui para escondê-las na tela de criação.
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(ConnectionCharacteristics::class, $tabs, $options);
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
     * Formulário de cadastro/edição da fonte. Só o essencial para criar o
     * registro (Nome, Ferramenta, Ativo) — URL base, modo de embed e
     * credenciais específicas do tipo ficam na aba "Características"
     * (ConnectionCharacteristics), que só existe depois que a Connection já
     * tem um tipo salvo. Renderizado em PHP/HTML puro
     * (showFormHeader/showFormButtons), mesma decisão de sempre neste plugin.
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $this->showNameAndToolFields();
        $this->showActiveField();

        $this->showFormButtons($options);

        return true;
    }

    private function showNameAndToolFields(): void
    {
        $isNew = (int)$this->fields['id'] <= 0;

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Nome') . "</td>";
        echo "<td>" . Html::input('name', ['value' => $this->fields['name']])
            . "<div class='form-text text-muted'>" . __('Ex.: Grafana Produção', 'analyticdesign') . "</div>"
            . "</td>";
        echo "<td>" . __('Ferramenta', 'analyticdesign') . "</td>";
        echo "<td>";
        // Força vazio numa fonte nova: obriga uma escolha explícita em vez de
        // herdar o DEFAULT 'grafana' da coluna (ver Connection::install()).
        Dropdown::showFromArray('type', SourceFactory::getAvailableTypes(), [
            'value'               => $isNew ? '' : $this->fields['type'],
            'display_emptychoice' => true,
            'emptylabel'          => __('Selecione uma ferramenta', 'analyticdesign'),
            'required'            => true,
        ]);
        echo "</td></tr>";
    }

    /**
     * "Ativo" como lista suspensa Sim/Não (Dropdown::showYesNo()) em vez de
     * checkbox — mais explícito para quem está preenchendo o formulário pela
     * primeira vez.
     */
    private function showActiveField(): void
    {
        // getEmpty()/initForm() zeram is_active para um item novo (não
        // respeitam o DEFAULT 1 da coluna) — mantém "Não" como valor inicial
        // do dropdown num item novo, forçando o cadastrante a ativar
        // explicitamente a fonte depois de configurá-la.
        $isActive = (int)$this->fields['id'] > 0 ? (int)($this->fields['is_active'] ?? 0) : 0;

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Ativo') . "</td>";
        echo "<td>";
        Dropdown::showYesNo('is_active', $isActive);
        echo "</td>";
        echo "<td colspan='2'></td></tr>";
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
        // O dropdown "Ferramenta" nasce vazio por design (ver
        // showNameAndToolFields()) — rejeita aqui em vez de deixar o DEFAULT
        // 'grafana' da coluna entrar silenciosamente caso o usuário ignore o
        // "required" do lado do cliente (JS desabilitado, requisição forjada etc.).
        if (empty($input['type'])) {
            Session::addMessageAfterRedirect(
                __('Selecione uma ferramenta.', 'analyticdesign'),
                false,
                ERROR
            );
            return false;
        }

        // O formulário de criação não pergunta o modo de embed (só a aba
        // "Características", depois de salvo) — sem isso, uma Connection
        // Power BI nasceria com o DEFAULT genérico da coluna ('iframe'),
        // que não é uma opção válida no dropdown de embed_mode do Power BI.
        if ($input['type'] === PowerBiSource::getType() && empty($input['embed_mode'])) {
            $input['embed_mode'] = DashboardSourceInterface::EMBED_MODE_SECURE;
        }
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
     * ConnectionCharacteristics). Sem mesclar, atualizar só um campo (ex.:
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
