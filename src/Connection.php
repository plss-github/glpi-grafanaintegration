<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Fonte de BI cadastrada (uma linha por Grafana configurado).
 * Tabela: glpi_plugin_plugingrafanaintegration_connections
 */

namespace GlpiPlugin\Plugingrafanaintegration;

use CommonDBTM;
use Dropdown;
use GLPIKey;
use GlpiPlugin\Plugingrafanaintegration\Source\DashboardSourceInterface;
use GlpiPlugin\Plugingrafanaintegration\Source\GrafanaSource;
use GlpiPlugin\Plugingrafanaintegration\Source\SourceFactory;
use GlpiPlugin\Plugingrafanaintegration\Traits\HasFormFieldLayout;
use Html;
use Session;

class Connection extends CommonDBTM
{
    use HasFormFieldLayout;

    /**
     * Único direito do plugin, compartilhado por Connection e DashboardItem
     * (DashboardItem é sempre filho de uma Connection — não faz sentido um
     * direito separado). Centralizado aqui para não repetir a mesma string
     * em DashboardItem::$rightname e hook.php::plugin_plugingrafanaintegration_getrights().
     */
    public const RIGHTNAME = 'plugin_plugingrafanaintegration_connection';

    public static $rightname = self::RIGHTNAME;

    /** Histórico de alterações na aba "Histórico" do item. */
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Fonte de Dados', 'Fontes de Dados', $nb, 'analyticdesign');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-dots';
    }

    /**
     * Abas "Configurações" (URL/credenciais específicas do tipo já escolhido
     * + importação de dashboards), "Visibilidade" (regras de Critérios/Ação)
     * e "Pré-Visualização" (dashboards já importados, somente leitura),
     * exibidas no formulário da conexão. Só aparecem para uma Connection já
     * salva — CommonGLPI só chama addStandardTab() para itens não-novos (ver
     * CommonGLPI::defineAllTabs()), então nenhuma lógica extra é necessária
     * aqui para escondê-las na tela de criação.
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(ConnectionCharacteristics::class, $tabs, $options);
        $this->addStandardTab(ConnectionVisibilityRules::class, $tabs, $options);
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
            'name'     => __('Status'),
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

    /** Instancia a implementação de fonte (Grafana) associada. */
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
     * registro (Nome, Ferramenta, Status) + Comentários — URL base, modo de
     * embed e
     * credenciais específicas do tipo ficam na aba "Características"
     * (ConnectionCharacteristics), que só existe depois que a Connection já
     * tem um tipo salvo. Renderizado em PHP/HTML puro, mas reproduzindo o
     * mesmo layout em grid (Bootstrap `row`/`col-*`, rótulo em
     * `col-form-label`) que o GLPI 11 usa nos seus próprios formulários
     * baseados em Twig (ver HasFormFieldLayout) — showFormHeader()/
     * showFormButtons() continuam sendo usados (título, CSRF, botões), só a
     * <table> que eles abrem é fechada imediatamente e substituída por uma
     * única célula larga contendo nosso grid de campos.
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);
        echo "</td></tr><tr><td colspan='4'>";

        $this->showNameToolAndStatusFields();
        $this->showCommentField();

        $isGrafana = (int)$this->fields['id'] > 0 && $this->fields['type'] === GrafanaSource::getType();
        if ($isGrafana) {
            $this->showGrafanaCredentialsSection();
        }

        echo "</td></tr>";

        // "Testar conexão" pedido na MESMA linha dos botões padrão
        // (Salvar/Excluir) — usa o mecanismo nativo `addbuttons` de
        // showFormButtons() (components/form/buttons.html.twig) em vez de um
        // botão solto dentro da seção de credenciais.
        if ($isGrafana) {
            $hasToken = !empty($this->getDecryptedCredentials()['api_token'] ?? '');
            $options['addbuttons']['analyticdesign_test_connection'] = [
                'type'        => 'button',
                'text'        => __('Testar conexão', 'analyticdesign'),
                'icon'        => 'ti ti-plug',
                'add_class'   => 'analyticdesign-test-connection',
                'add_attribs' => [
                    'data-id'              => (int)$this->fields['id'],
                    'data-has-credentials' => $hasToken ? '1' : '0',
                    'style'                => $hasToken ? '' : 'display:none;',
                ],
            ];
        }
        $this->showFormButtons($options);

        return true;
    }

    /**
     * Nome, Ferramenta e Ativo lado a lado (3 colunas, `col-sm-4`) — pedido
     * explicitamente para ficar mais compacto/organizado do que uma coluna
     * por linha.
     */
    private function showNameToolAndStatusFields(): void
    {
        $isNew = (int)$this->fields['id'] <= 0;
        $thirdWidth = 'col-12 col-sm-4';

        self::openFieldsRow();

        self::openField('name', __('Nome'), 'analyticdesign_name', $thirdWidth);
        echo Html::input('name', ['id' => 'analyticdesign_name', 'value' => $this->fields['name']]);
        echo "<div class='form-text text-muted'>" . __('Ex.: Grafana Produção', 'analyticdesign') . "</div>";
        self::closeField();

        self::openField('type', __('Ferramenta', 'analyticdesign'), 'dropdown_type1', $thirdWidth);
        // Força vazio numa fonte nova: obriga uma escolha explícita em vez de
        // herdar o DEFAULT 'grafana' da coluna (ver Connection::install()). Sem
        // 'emptylabel' próprio: usa o "-----" padrão do GLPI, igual a qualquer
        // outro dropdown obrigatório do core. 'rand' fixo para o <label for>
        // acima apontar para o id de fato gerado (ver Dropdown::showFromArray()).
        Dropdown::showFromArray('type', SourceFactory::getAvailableTypes(), [
            'value'               => $isNew ? '' : $this->fields['type'],
            'display_emptychoice' => true,
            'required'            => true,
            'rand'                => 1,
        ]);
        self::closeField();

        // Lista suspensa Sim/Não (Dropdown::showYesNo()) em vez de checkbox —
        // mais explícito para quem está preenchendo o formulário pela
        // primeira vez. getEmpty()/initForm() zeram is_active para um item
        // novo (não respeitam o DEFAULT 1 da coluna) — mantém "Não" como
        // valor inicial num item novo, forçando o cadastrante a ativar
        // explicitamente a fonte depois de configurá-la.
        $isActive = !$isNew ? (int)($this->fields['is_active'] ?? 0) : 0;
        self::openField('is_active', __('Ativo', 'analyticdesign'), 'dropdown_is_active2', $thirdWidth);
        Dropdown::showYesNo('is_active', $isActive, -1, ['rand' => 2]);
        self::closeField();

        self::closeFieldsRow();
    }

    /** Campo livre de anotações — não interpretado pelo plugin, só um bloco de texto para quem administra a fonte. */
    private function showCommentField(): void
    {
        self::openFieldsRow();
        self::openField('comment', __('Comentários'), 'analyticdesign_comment');
        echo "<textarea name='comment' id='analyticdesign_comment' class='form-control' rows='2'>"
            . htmlspecialchars($this->fields['comment'] ?? '', ENT_QUOTES) . "</textarea>";
        self::closeField();
        self::closeFieldsRow();
    }

    /**
     * URL base + API token do Grafana, direto na aba "Fonte de dados" (não
     * mais na aba "Configurações") — só depois que a fonte já existe e o
     * tipo é Grafana. Reaproveita as mesmas classes/JS de
     * `ConnectionCharacteristics` (`.analyticdesign-characteristics` +
     * `.analyticdesign-error`/`.analyticdesign-fields-wrapper`) para o
     * "Testar conexão" e o tratamento de falha funcionarem sem duplicar JS.
     *
     * O campo de API token usa o mesmo padrão "revelável" que o GLPI usa
     * para a chave de licença do GLPI Network (ver
     * HasFormFieldLayout::showDisclosablePasswordInput()) — pedido
     * explicitamente para ficar visualmente igual. Quando já existe um
     * valor salvo, o campo nasce com um placeholder de bolinhas (nunca o
     * valor de fato — mesma convenção de "deixe em branco para manter o
     * valor salvo") para indicar visualmente que algo está configurado.
     *
     * O bloco inteiro fica escondido quando a fonte está com Status "Não" —
     * volta ao reativar (ver toggleGrafanaSectionVisibility() em
     * public/js/analyticdesign.js). O botão "Testar conexão" nasce visível
     * se já existe um token salvo; senão só aparece depois que algo é
     * digitado no campo (ver toggleTestButtonVisibility() no mesmo arquivo).
     */
    private function showGrafanaCredentialsSection(): void
    {
        $fieldsForType = SourceFactory::getConfigFieldsFor(GrafanaSource::getType());
        $credentials = $this->getDecryptedCredentials();
        $isActive = (int)($this->fields['is_active'] ?? 0) === 1;

        // Escondida quando a fonte está desativada — volta ao ativar de novo
        // (ver toggleGrafanaSectionVisibility() em public/js/analyticdesign.js).
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

        echo "<div class='analyticdesign-fields-wrapper'>";

        self::openFieldsRow();
        self::openField('base_url', __('URL base', 'analyticdesign'), 'analyticdesign_grafana_base_url', true);
        echo Html::input('base_url', ['id' => 'analyticdesign_grafana_base_url', 'value' => $this->fields['base_url']]);
        echo "<div class='form-text text-muted'>" . __('Ex.: https://grafana.suaempresa.com', 'analyticdesign') . "</div>";
        self::closeField();
        self::closeFieldsRow();

        // O Grafana não expõe uma API de embed-token — o token acima só
        // autentica as chamadas do BACKEND
        // do plugin (testar conexão, listar dashboards); o <iframe> em si é
        // uma requisição direta do NAVEGADOR do usuário pro Grafana, sem
        // nenhum token. Decisão de arquitetura (ver docs/CONFIGURACAO.md,
        // seção "Arquitetura e riscos de integração"): manter só embed
        // (iframe) pro Grafana e deixar esse requisito explícito aqui, em vez
        // de tentar construir um modo "via API" (renderizar uma imagem
        // estática via /render/ do grafana-image-renderer) — plugin externo
        // do Grafana nem sempre instalado, perderia interatividade, e
        // ninguém pediu essa troca.
        echo "<div class='alert alert-important alert-warning' style='margin-bottom:1rem;'>"
            . "<i class='ti ti-info-circle'></i> "
            . __('Cada usuário do GLPI precisa conseguir acessar este Grafana diretamente (login/SSO próprio, acesso anônimo habilitado, ou o dashboard convertido em "Public dashboard") — o token acima só serve para o plugin testar a conexão e listar dashboards, não para autenticar o embed em si.', 'analyticdesign')
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
        if (!empty($fieldsForType)) {
            echo "<p class='text-muted fst-italic'>"
                . __('Deixe os campos de credenciais em branco para manter os valores já salvos.', 'analyticdesign')
                . "</p>";
        }

        // O botão "Testar conexão" em si fica na linha dos botões padrão
        // (Salvar/Excluir) — ver showForm(), opção `addbuttons`. Só o
        // resultado do teste (sucesso/erro) continua aqui.
        echo "<div class='mt-2'><span class='analyticdesign-test-result'></span></div>";

        echo "</div>"; // .analyticdesign-fields-wrapper
        echo "</div>"; // .card-body
        echo "</div>"; // .card
        echo "</div>"; // .analyticdesign-characteristics
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
     * ConnectionCharacteristics). Sem mesclar, atualizar só um campo
     * apagaria silenciosamente os demais já armazenados.
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

        $sensitive = ['api_token'];
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
                    `comment` TEXT NULL,
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
        // Coluna adicionada em versão posterior — ver nota de idempotência de
        // install() em hook.php (roda de novo a cada atualização de versão).
        if (!$DB->fieldExists($table, 'comment')) {
            $DB->doQuery("ALTER TABLE `{$table}` ADD COLUMN `comment` TEXT NULL AFTER `is_active`");
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
