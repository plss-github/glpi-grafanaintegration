<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Dashboard externo exposto como card no GLPI.
 * Tabela: glpi_plugin_plugingrafanaintegration_dashboarditems (nome derivado da classe
 * por CommonDBTM::getTable() — não é `..._items`, apesar do que sugeriria
 * uma leitura rápida do nome da classe).
 */

namespace GlpiPlugin\Plugingrafanaintegration;

use CommonDBTM;
use CommonGLPI;
use Dropdown;
use Session;
use GlpiPlugin\Plugingrafanaintegration\Traits\HasCheckboxField;
use GlpiPlugin\Plugingrafanaintegration\Traits\HasFormFieldLayout;
use GlpiPlugin\Plugingrafanaintegration\Traits\HasTimestampMigration;
use Html;

class DashboardItem extends CommonDBTM
{
    use HasCheckboxField;
    use HasFormFieldLayout;
    use HasTimestampMigration;

    /** Compartilha o direito de Connection — ver Connection::RIGHTNAME. */
    public static $rightname = Connection::RIGHTNAME;

    public static function getTypeName($nb = 0)
    {
        return _n('Dashboard exposto', 'Dashboards expostos', $nb, 'analyticdesign');
    }

    public static function getIcon()
    {
        return 'ti ti-layout-dashboard';
    }

    /** Exemplo de URL de embed mostrado abaixo do campo (formato do Grafana). */
    private static function embedUrlExample(?Connection $connection): string
    {
        return __('Ex.: https://seu-grafana.suaempresa.com/d/ab12cd34/meu-dashboard?kiosk=tv&theme=light', 'analyticdesign');
    }

    private ?Connection $connectionCache = null;
    private bool $connectionCacheLoaded = false;

    /**
     * Devolve a Connection dona deste item — cacheada na instância: tanto
     * `isVisibleForCurrentUser()` quanto o chamador de `renderEmbedWidget()`
     * (ver Dashboard.php) precisam da Connection na mesma requisição; sem o
     * cache, cada render fazia a mesma consulta duas vezes (achado na
     * revisão de código).
     */
    public function getConnection(): ?Connection
    {
        if (!$this->connectionCacheLoaded) {
            $conn = new Connection();
            $this->connectionCache = $conn->getFromDB((int)($this->fields['connections_id'] ?? 0)) ? $conn : null;
            $this->connectionCacheLoaded = true;
        }
        return $this->connectionCache;
    }

    /**
     * O usuário logado pode ver este card? Único ponto de checagem usado
     * tanto no catálogo de widgets (Dashboard::getCards()) quanto no render
     * de fato (Dashboard::renderEmbedWidget()) — ver docblock de
     * VisibilityRule sobre o modelo de visibilidade.
     *
     * Quatro camadas, todas obrigatórias (E entre elas, ao contrário do OR
     * dentro de cada uma):
     *  1. `is_active` — desativar um item deve parar de renderizá-lo mesmo
     *     que já esteja posicionado num dashboard (getActiveItems() já filtra
     *     isso do catálogo, mas o caminho de render direto por ID, chamado
     *     de novo a cada vez que o dashboard é aberto, não checava; achado
     *     na revisão de código).
     *  2. Direito de leitura do módulo (`Connection::RIGHTNAME`) — sem isso,
     *     antes desta correção, QUALQUER usuário que pudesse ver qualquer
     *     dashboard nativo do GLPI enxergava o conteúdo embedado, mesmo sem
     *     nenhum direito no plugin (achado na revisão de segurança).
     *  3. Escopo de entidade da Connection dona (`entities_id`/`is_recursive`)
     *     — mesma regra de multi-tenant que o resto do GLPI já aplica a
     *     `Connection::can()`, mas que o caminho de render de card nunca
     *     verificava.
     *  4. Se `is_private`, casar com pelo menos uma regra de visibilidade da
     *     aba "Visibilidade" da Connection (VisibilityRule — Critérios/Ação,
     *     ver docblock da classe). `is_private` em si NUNCA é digitado num
     *     formulário: é recalculado automaticamente (VisibilityRule::
     *     resyncAffectedItems()) sempre que uma regra muda — 1 quando pelo
     *     menos uma regra casa com o item, 0 quando nenhuma casa (público,
     *     visível a quem já tem o direito de leitura do módulo).
     */
    public function isVisibleForCurrentUser(): bool
    {
        if (!$this->passesActiveRightAndEntityChecks()) {
            return false;
        }

        if (!(bool)((int)($this->fields['is_private'] ?? 0))) {
            return true;
        }
        return VisibilityRule::isVisibleForCurrentUserViaRules($this);
    }

    /**
     * Mesmas camadas 1-3 de isVisibleForCurrentUser() (is_active, direito do
     * módulo, escopo de entidade), mas SEM a camada 4 (regras de
     * visibilidade) — usado só pelo botão "Pré-visualizar" da aba "Pré-Visualização"
     * (front/previewdashboarditem.php): o pedido explícito foi que a
     * pré-visualização ignore a regra de visibilidade configurada em
     * "Configurações", já que é uma ferramenta de administração (quem chega
     * até essa aba já tem direito de UPDATE na Connection dona), não uma
     * simulação de "o que o usuário final veria".
     */
    public function isPreviewableByCurrentUser(): bool
    {
        return $this->passesActiveRightAndEntityChecks();
    }

    private function passesActiveRightAndEntityChecks(): bool
    {
        if ((int)($this->fields['is_active'] ?? 0) !== 1) {
            return false;
        }
        if (!Session::haveRight(self::$rightname, READ)) {
            return false;
        }

        $connection = $this->getConnection();
        if ($connection === null) {
            return false;
        }
        return Session::haveAccessToEntity((int)$connection->fields['entities_id'], (bool)$connection->fields['is_recursive']);
    }

    /** @return DashboardItem[] todos os itens ativos, para o hook de cards. */
    public static function getActiveItems(): array
    {
        global $DB;
        $items = [];
        $it = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['is_active' => 1],
        ]);
        foreach ($it as $row) {
            $obj = new self();
            $obj->fields = $row;
            $items[] = $obj;
        }
        return $items;
    }

    /** @return DashboardItem[] itens já importados de uma conexão (todos, ativos ou não). */
    public static function getForConnection(int $connectionsId): array
    {
        global $DB;
        $items = [];
        $it = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['connections_id' => $connectionsId],
            'ORDER' => ['category', 'name'],
        ]);
        foreach ($it as $row) {
            $obj = new self();
            $obj->fields = $row;
            $items[] = $obj;
        }
        return $items;
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => '10',
            'table'    => Connection::getTable(),
            'field'    => 'name',
            'name'     => Connection::getTypeName(1),
            'datatype' => 'dropdown',
            'itemtype' => Connection::class,
            'linkfield' => 'connections_id',
            'massiveaction' => false,
            'forcegroupby' => true,
        ];
        $tab[] = [
            'id'       => '11',
            'table'    => self::getTable(),
            'field'    => 'external_id',
            'name'     => __('ID externo', 'analyticdesign'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => '12',
            'table'    => self::getTable(),
            'field'    => 'category',
            'name'     => __('Módulo', 'analyticdesign'),
            'datatype' => 'specific',
        ];
        $tab[] = [
            'id'       => '13',
            'table'    => self::getTable(),
            'field'    => 'is_active',
            'name'     => __('Status'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => '14',
            'table'    => self::getTable(),
            'field'    => 'embed_url',
            'name'     => __('URL de embed', 'analyticdesign'),
            'datatype' => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => '15',
            'table'    => self::getTable(),
            'field'    => 'is_private',
            'name'     => __('Visibilidade restrita', 'analyticdesign'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => '16',
            'table'    => self::getTable(),
            'field'    => 'replaces_module',
            'name'     => __('Substitui dashboard do módulo', 'analyticdesign'),
            'datatype' => 'specific',
        ];

        return $tab;
    }

    /** Traduz a chave de módulo ('assets', 'helpdesk'...) salva em `category`/`replaces_module` para o rótulo legível. */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'category' || $field === 'replaces_module') {
            $value = $values[$field];
            return $value !== '' ? (ModuleDashboard::MODULES[$value] ?? $value) : '';
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Aba "Dashboards" no formulário da Connection (ver Connection::defineTabs()).
     *
     * NÃO static: CommonGLPI::getTabNameForItem() é um método de instância na
     * base (confirmado em src/CommonGLPI.php do GLPI 11.0.8 — o dispatcher de
     * abas em CommonGLPI::getTabNameForItem() chama `$obj->getTabNameForItem(...)`
     * numa instância, não `Class::getTabNameForItem(...)`); declarar como
     * `static` aqui é um erro fatal de compilação em PHP (não dá para tornar
     * static um método não-static ao sobrescrever).
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Connection && $item->fields['id'] > 0) {
            $count = count(self::getForConnection((int)$item->fields['id']));
            // Rótulo próprio da aba (não getTypeName()): essa aba virou um
            // pré-visualizador — o nome geral do tipo ("Dashboard(s)
            // exposto(s)"), usado na busca/menu, continua o mesmo.
            return self::createTabEntry(__('Pré-Visualização', 'analyticdesign'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Connection) {
            self::showForConnection($item);
        }
        return true;
    }

    /**
     * Renderiza a aba "Pré-Visualização" da Connection — EXCLUSIVAMENTE um
     * pré-visualizador, somente leitura: nome, ID externo, módulo e um
     * botão para ver o card renderizado. Nenhuma configuração/edição
     * acontece aqui — módulo/status editáveis, remover e importar ficam na
     * aba "Configurações" (ver
     * ConnectionCharacteristics::displayTabContentForItem() e
     * DashboardItem::showDashboardConfigurationSection()).
     *
     * Renderizado em PHP/HTML puro (mesma decisão de Connection::showForm()) —
     * sem depender de templates Twig cuja integração exata com o GLPI 11 não
     * pôde ser validada contra uma instância real.
     */
    public static function showForConnection(Connection $connection): void
    {
        $imported = self::getForConnection((int)$connection->fields['id']);

        if (empty($imported)) {
            echo "<p class='text-muted'>" . __('Nenhum dashboard importado ainda.', 'analyticdesign') . "</p>";
            return;
        }

        global $CFG_GLPI;
        $previewRoot = $CFG_GLPI['root_doc'] . '/plugins/plugingrafanaintegration/front/previewdashboarditem.php';

        echo "<div class='card mb-0'>";
        echo "<div class='table-responsive'>";
        echo "<table class='table table-sm table-vcenter card-table'><thead><tr>";
        echo "<th>" . __('Nome') . "</th>";
        echo "<th>" . __('ID externo', 'analyticdesign') . "</th>";
        echo "<th>" . __('Módulo', 'analyticdesign') . "</th>";
        echo "<th class='text-end'>" . __('Pré-visualizar', 'analyticdesign') . "</th>";
        echo "</tr></thead><tbody>";
        foreach ($imported as $item) {
            $id = (int)$item->fields['id'];
            $moduleLabel = $item->fields['category'] !== ''
                ? (ModuleDashboard::MODULES[$item->fields['category']] ?? $item->fields['category'])
                : '';
            echo "<tr>";
            echo "<td>" . htmlspecialchars($item->fields['name'], ENT_QUOTES) . "</td>";
            echo "<td class='text-muted'>" . htmlspecialchars($item->fields['external_id'], ENT_QUOTES) . "</td>";
            echo "<td>" . htmlspecialchars($moduleLabel, ENT_QUOTES) . "</td>";
            echo "<td class='text-end'><a class='btn btn-sm btn-outline-secondary' target='_blank' rel='noopener' href='"
                . htmlspecialchars($previewRoot . '?id=' . $id, ENT_QUOTES) . "'>"
                . "<i class='ti ti-eye'></i> " . __('Ver', 'analyticdesign') . "</a></td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "</div>"; // .table-responsive
        echo "</div>"; // .card
    }

    public static function ajaxRoot(): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/plugingrafanaintegration/ajax';
    }

    /**
     * Consulta a fonte por dashboards ainda não importados. Best-effort: se a
     * fonte não responder (ou não suportar listagem, caso do publish_to_web),
     * devolve uma lista vazia e a mensagem de erro correspondente, deixando a
     * UI cair no formulário de adição manual em vez de quebrar a tela.
     *
     * @return array{0: array<int, array{external_id:string, name:string, embed_url:?string}>, 1: ?string}
     */
    private static function resolveAvailableDashboards(Connection $connection, array $imported): array
    {
        $importedExternalIds = array_map(
            static fn (self $item) => $item->fields['external_id'],
            $imported
        );

        try {
            $available = array_values(array_filter(
                $connection->getSource()->listDashboards(),
                static fn (array $dash) => !in_array($dash['external_id'], $importedExternalIds, true)
            ));
            return [$available, null];
        } catch (\Throwable $e) {
            return [[], __('Não foi possível listar dashboards da fonte. Verifique a conexão.', 'analyticdesign')];
        }
    }

    /**
     * Tabela de gerenciamento dos dashboards já importados — módulo, status e
     * substituição de módulo editáveis em lote (mesmo endpoint de sempre,
     * updatedashboarditems.php) e um botão para remover cada um por completo
     * (ver ajax/deletedashboarditem.php). Pré-visualizar um item já importado
     * fica só na aba "Pré-Visualização" (showForConnection()) — esta tabela
     * é sobre CONFIGURAR, não visualizar.
     *
     * "Substituir dashboard do módulo" só fica editável quando o item já tem
     * uma regra de visibilidade (aba "Visibilidade") com pelo menos um alvo
     * enumerável (Perfil/Grupo/Usuário/Entidade — não "Todos") — ver
     * VisibilityRule::hasConcreteGrantForItem() e docblock de ModuleDashboard
     * sobre por que.
     *
     * @param DashboardItem[] $imported
     */
    private static function showImportedManagementSection(int $connectionsId, array $imported, string $ajaxRoot): void
    {
        echo "<div class='analyticdesign-imported card'>";
        echo "<div class='card-header'><span class='card-title mb-0 d-flex align-items-center gap-2'>"
            . "<i class='ti ti-layout-dashboard'></i> " . __('Dashboards importados', 'analyticdesign') . "</span></div>";
        echo "<div class='card-body'>";

        if (empty($imported)) {
            echo "<p class='text-muted mb-0'>" . __('Nenhum dashboard importado ainda.', 'analyticdesign') . "</p>";
            echo "</div></div>";
            return;
        }

        echo "<form name='analyticdesign_update_items' method='post' action='"
            . htmlspecialchars($ajaxRoot . '/updatedashboarditems.php', ENT_QUOTES) . "'>";
        echo "<input type='hidden' name='connections_id' value='{$connectionsId}'>";

        echo "<div class='table-responsive'>";
        echo "<table class='table table-sm table-vcenter card-table'><thead><tr>";
        echo "<th>" . __('Nome') . "</th>";
        echo "<th>" . __('ID externo', 'analyticdesign') . "</th>";
        echo "<th>" . __('Módulo', 'analyticdesign') . "</th>";
        echo "<th>" . __('Ativo', 'analyticdesign') . "</th>";
        echo "<th>" . __('Substituir dashboard do módulo', 'analyticdesign') . "</th>";
        echo "<th class='text-end'>" . __('Remover', 'analyticdesign') . "</th>";
        echo "</tr></thead><tbody>";
        foreach ($imported as $item) {
            $id = (int)$item->fields['id'];
            echo "<tr>";
            echo "<td>" . htmlspecialchars($item->fields['name'], ENT_QUOTES) . "</td>";
            echo "<td class='text-muted'>" . htmlspecialchars($item->fields['external_id'], ENT_QUOTES) . "</td>";
            echo "<td>" . Dropdown::showFromArray("items[{$id}][category]", self::moduleOptions(), [
                'value'    => $item->fields['category'] !== '' ? $item->fields['category'] : 0,
                'display_emptychoice' => false,
                'display'  => false,
            ]) . "</td>";
            echo "<td>" . self::renderCheckbox("items[{$id}][is_active]", (int)$item->fields['is_active'] === 1) . "</td>";
            echo "<td>";
            if (VisibilityRule::hasConcreteGrantForItem($item)) {
                echo Dropdown::showFromArray("items[{$id}][replaces_module]", [0 => __('Não substituir', 'analyticdesign')] + ModuleDashboard::MODULES, [
                    'value'               => $item->fields['replaces_module'] !== '' ? $item->fields['replaces_module'] : 0,
                    'display_emptychoice' => false,
                    'display'             => false,
                ]);
            } else {
                echo "<span class='text-muted' title='"
                    . htmlspecialchars(__('Configure uma regra na aba "Visibilidade" com pelo menos um perfil/grupo/usuário/entidade para habilitar.', 'analyticdesign'), ENT_QUOTES)
                    . "'>" . __('Indisponível', 'analyticdesign') . "</span>";
            }
            echo "</td>";
            echo "<td class='text-end'><button type='button' class='btn btn-icon btn-ghost-danger analyticdesign-delete-item' data-id='{$id}' data-name='"
                . htmlspecialchars($item->fields['name'], ENT_QUOTES) . "' title='" . htmlspecialchars(__('Remover', 'analyticdesign'), ENT_QUOTES) . "'>"
                . "<i class='ti ti-trash'></i></button></td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "</div>"; // .table-responsive
        echo "<div class='mt-3'>";
        echo "<button type='submit' name='update' class='btn btn-primary'><i class='ti ti-device-floppy'></i> " . __('Salvar') . "</button>";
        echo "</div>";
        Html::closeForm();

        echo "</div>"; // .card-body
        echo "</div>"; // .card
    }

    /**
     * Aba "Configurações" (parte de DashboardItem): tabela de gerenciamento
     * dos dashboards já importados (módulo/status/substituição de módulo
     * editáveis + remover — ver showImportedManagementSection()), NESSA
     * ORDEM — pedido explicitamente para importar vir primeiro, já que é o
     * passo mais comum ao abrir a aba. Chamado a partir de
     * ConnectionCharacteristics::displayTabContentForItem() — a aba
     * "Pré-Visualização" (ver showForConnection()) não configura mais nada,
     * e a aba "Visibilidade" (ver ConnectionVisibilityRules) cuida de quem
     * vê cada dashboard.
     *
     * O formulário de importação tem duas variantes, conforme a fonte
     * suporta listagem ao vivo ou não:
     *  - Suporta (Grafana): dropdown com os dashboards disponíveis na fonte —
     *    escolhido um, nome/URL de embed são resolvidos no servidor a partir
     *    da própria listagem (nunca confiando em nome/URL vindos do POST do
     *    navegador).
     *  - Não suporta (a listagem falhou — ver resolveAvailableDashboards()):
     *    formulário manual (nome + URL de embed colada à mão), usado como
     *    fallback nesse caso.
     * Módulo é configurado junto, na mesma submissão; visibilidade e
     * substituição de módulo ficam para depois (aba "Visibilidade" e a
     * tabela de gerenciamento logo abaixo, respectivamente — um item
     * recém-importado ainda não tem regra nenhuma apontando pra ele).
     */
    public static function showDashboardConfigurationSection(Connection $connection, int $connectionsId, string $ajaxRoot): void
    {
        echo "<div class='analyticdesign-manual-add d-flex flex-column gap-3'>";

        // Checagem explícita ANTES de montar qualquer formulário — sem isso,
        // um usuário só com direito de leitura via editar módulo/status,
        // remover ou preencher a tela de importação inteira só para levar
        // "Acesso negado" ao submeter (os ajax/*.php exigem UPDATE, mas a
        // tela não avisava antes disso — achado ao investigar um relato de
        // AccessDeniedHttpException em ajax/importselecteddashboard.php).
        if (!$connection->can($connectionsId, UPDATE)) {
            echo "<p class='alert alert-important alert-warning'>"
                . htmlspecialchars(__('Você não tem direito de editar esta fonte de dados.', 'analyticdesign'), ENT_QUOTES)
                . "</p></div>";
            return;
        }

        $imported = self::getForConnection($connectionsId);
        [$available, $listError] = self::resolveAvailableDashboards($connection, $imported);

        echo "<div class='card mb-0'>";
        echo "<div class='card-header'><span class='card-title mb-0 d-flex align-items-center gap-2'>"
            . "<i class='ti ti-plus'></i> " . __('Adicionar dashboard', 'analyticdesign') . "</span></div>";
        echo "<div class='card-body'>";
        if ($listError === null) {
            echo "<p class='text-muted'>" . __('Escolha um dashboard disponível na fonte para importar e configurar o módulo.', 'analyticdesign') . "</p>";
            self::showDropdownImportForm($connectionsId, $available, $ajaxRoot);
        } else {
            echo "<p class='text-muted'>" . __('Cadastre aqui um dashboard manualmente — necessário quando a fonte não permite listar automaticamente.', 'analyticdesign') . "</p>";
            self::showManualAddForm($connection, $connectionsId, $ajaxRoot);
        }
        echo "</div>"; // .card-body
        echo "</div>"; // .card

        self::showImportedManagementSection($connectionsId, $imported, $ajaxRoot);

        echo "</div>";
    }

    /** @param array<int, array{external_id:string, name:string, embed_url:?string}> $available */
    private static function showDropdownImportForm(int $connectionsId, array $available, string $ajaxRoot): void
    {
        if (empty($available)) {
            echo "<p class='text-muted mb-0'>" . __('Nada novo para importar — todos os dashboards já foram importados, ou a fonte não retornou nenhum.', 'analyticdesign') . "</p>";
            return;
        }

        echo "<form name='analyticdesign_import_selected' method='post' action='"
            . htmlspecialchars($ajaxRoot . '/importselecteddashboard.php', ENT_QUOTES) . "'>";
        echo "<input type='hidden' name='connections_id' value='{$connectionsId}'>";

        self::openFieldsRow();
        self::openField('external_id', __('Dashboard', 'analyticdesign'), 'analyticdesign_select_dashboard');
        $options = [];
        foreach ($available as $dash) {
            $options[$dash['external_id']] = $dash['name'];
        }
        Dropdown::showFromArray('external_id', $options, [
            'display_emptychoice' => true,
            'required'            => true,
        ]);
        self::closeField();

        self::showModuleField('');
        self::closeFieldsRow();

        echo "<div class='mt-2'>";
        echo "<button type='submit' name='add' class='btn btn-primary'><i class='ti ti-download'></i> " . __('Importar', 'analyticdesign') . "</button>";
        echo "</div>";
        Html::closeForm();
    }

    private static function showManualAddForm(Connection $connection, int $connectionsId, string $ajaxRoot): void
    {
        echo "<form name='analyticdesign_add_manual' method='post' action='"
            . htmlspecialchars($ajaxRoot . '/addmanualdashboard.php', ENT_QUOTES) . "'>";
        echo "<input type='hidden' name='connections_id' value='{$connectionsId}'>";

        self::openFieldsRow();
        self::openField('name', __('Nome'), 'analyticdesign_manual_name');
        echo Html::input('name', ['id' => 'analyticdesign_manual_name', 'value' => '']);
        echo "<div class='form-text text-muted'>" . __('Ex.: Indicadores de chamados', 'analyticdesign') . "</div>";
        self::closeField();

        self::showModuleField('');
        self::closeFieldsRow();

        self::openFieldsRow();
        self::openField('embed_url', __('URL de embed', 'analyticdesign'), 'analyticdesign_manual_embed_url', true);
        echo Html::input('embed_url', ['id' => 'analyticdesign_manual_embed_url', 'value' => '']);
        echo "<div class='form-text text-muted'>" . self::embedUrlExample($connection) . "</div>";
        self::closeField();
        self::closeFieldsRow();

        echo "<div class='mt-2'>";
        echo "<button type='submit' name='add' class='btn btn-primary'><i class='ti ti-plus'></i> " . __('Adicionar', 'analyticdesign') . "</button>";
        echo "</div>";
        Html::closeForm();
    }

    /** @return array<int|string, string> opções do dropdown de módulo: "Nenhum" + módulos (exceto Configurar). */
    private static function moduleOptions(): array
    {
        return [0 => __('Nenhum', 'analyticdesign')] + ModuleDashboard::MODULES;
    }

    /**
     * Campo "Módulo" (era "Categoria" — texto livre; agora uma lista fixa
     * dos módulos do GLPI, exceto Configurar). Continua sendo só o
     * agrupamento do card no catálogo de widgets do dashboard nativo
     * (`Dashboard::getCards()` usa o valor salvo aqui como `group`) — não
     * tem relação com "Substituir dashboard do módulo" (ModuleDashboard),
     * configurado na tabela de gerenciamento (ver
     * showImportedManagementSection()), nem com a aba "Visibilidade" (ver
     * ConnectionVisibilityRules).
     *
     * NÃO gerencia a própria linha (sem openFieldsRow()/closeFieldsRow()) —
     * pedido para ficar lado a lado com o campo anterior (Dashboard/Nome),
     * então quem chama é responsável por abrir/fechar a linha em volta.
     */
    private static function showModuleField(string $currentValue): void
    {
        self::openField('category', __('Módulo', 'analyticdesign'), 'analyticdesign_module_field');
        Dropdown::showFromArray('category', self::moduleOptions(), [
            'value' => $currentValue !== '' ? $currentValue : 0,
        ]);
        echo "<div class='form-text text-muted'>"
            . __('Agrupa este card no catálogo de widgets do dashboard nativo do GLPI.', 'analyticdesign')
            . "</div>";
        self::closeField();
    }

    /**
     * Formulário de edição pontual (a partir da busca geral). O fluxo principal
     * é a aba "Dashboards" da Connection (showForConnection); aqui só se ajusta
     * categoria/ativo/URL de um item já importado.
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);
        echo "</td></tr><tr><td colspan='4'>";

        $connection = $this->getConnection();

        self::openFieldsRow();

        self::openField('name', __('Nome'), 'analyticdesign_item_name');
        echo Html::input('name', ['id' => 'analyticdesign_item_name', 'value' => $this->fields['name']]);
        echo "<div class='form-text text-muted'>" . __('Ex.: Indicadores de chamados', 'analyticdesign') . "</div>";
        self::closeField();

        self::openField('connections_id', Connection::getTypeName(1), 'analyticdesign_item_connection');
        echo "<span id='analyticdesign_item_connection' class='form-control-plaintext'>"
            . ($connection !== null ? htmlspecialchars($connection->fields['name'], ENT_QUOTES) : '-')
            . "</span>";
        self::closeField();

        self::closeFieldsRow();

        self::openFieldsRow();

        self::openField('external_id', __('ID externo', 'analyticdesign'), 'analyticdesign_item_external_id');
        echo "<span id='analyticdesign_item_external_id' class='form-control-plaintext'>"
            . htmlspecialchars($this->fields['external_id'], ENT_QUOTES) . "</span>";
        self::closeField();

        self::openField('category', __('Módulo', 'analyticdesign'), 'analyticdesign_item_category');
        Dropdown::showFromArray('category', self::moduleOptions(), [
            'value' => $this->fields['category'] !== '' ? $this->fields['category'] : 0,
        ]);
        self::closeField();

        self::closeFieldsRow();

        self::openFieldsRow();
        self::openField('embed_url', __('URL de embed', 'analyticdesign'), 'analyticdesign_item_embed_url', true);
        echo Html::input('embed_url', ['id' => 'analyticdesign_item_embed_url', 'value' => $this->fields['embed_url']]);
        echo "<div class='form-text text-muted'>" . self::embedUrlExample($connection) . "</div>";
        self::closeField();
        self::closeFieldsRow();

        self::openFieldsRow();
        self::openField('is_active', __('Status'), 'analyticdesign_item_is_active');
        echo self::renderCheckbox('is_active', (int)($this->fields['is_active'] ?? 0) === 1);
        self::closeField();
        self::closeFieldsRow();

        // Visibilidade (regras de Critérios/Ação) e substituição de módulo
        // não são editadas aqui — ver aba "Visibilidade" da Connection e a
        // tabela de gerenciamento em Configurações, respectivamente.
        if ($connection !== null) {
            echo "<div class='mt-2'>";
            echo "<a href='" . htmlspecialchars(
                Connection::getFormURLWithID((int)$connection->fields['id']) . '&forcetab=' . rawurlencode(ConnectionVisibilityRules::class . '$1'),
                ENT_QUOTES
            ) . "'>" . __('Configurar visibilidade na aba "Visibilidade" da fonte', 'analyticdesign') . "</a>";
            echo "</div>";
        }

        echo "</td></tr>";
        $this->showFormButtons($options);

        return true;
    }

    /**
     * Cria os DashboardItem selecionados pelo admin — a partir do dropdown
     * de importação (ver showDropdownImportForm()/ajax/importselecteddashboard.php)
     * ou do formulário manual (ver showManualAddForm()/ajax/addmanualdashboard.php),
     * ambos já informando o módulo desde a criação. Visibilidade e
     * substituição de módulo NÃO são configuráveis na criação — um item
     * recém-importado ainda não pode ser alvo de nenhuma regra de
     * Critérios/Ação (a regra escolhe o Dashboard a partir dos já
     * importados) — ver aba "Visibilidade" e a tabela de gerenciamento em
     * Configurações, respectivamente, para configurar depois.
     *
     * @param array<int, array{external_id:string, name:string, embed_url?:string, category?:string}> $selection
     * @return int quantidade efetivamente criada
     */
    public static function importSelection(Connection $connection, array $selection): int
    {
        $created = 0;
        foreach ($selection as $dash) {
            if (($dash['external_id'] ?? '') === '') {
                continue;
            }
            $item = new self();
            $ok = $item->add([
                'connections_id'      => (int)$connection->fields['id'],
                'external_id'         => $dash['external_id'],
                'name'                => $dash['name'] ?? $dash['external_id'],
                'category'            => $dash['category'] ?? '',
                'embed_url'           => $dash['embed_url'] ?? '',
                'is_active'           => 1,
            ]);
            if ($ok) {
                $created++;
            }
        }
        return $created;
    }

    public function prepareInputForAdd($input)
    {
        return $this->normalizeCategoryInput($this->validateModuleReplacement($input));
    }

    public function prepareInputForUpdate($input)
    {
        return $this->normalizeCategoryInput($this->validateModuleReplacement($input));
    }

    /**
     * O dropdown "Módulo" (showModuleField()) usa `0` como valor do
     * placeholder "Nenhum" (mesmo padrão de `replaces_module`/"Não
     * substituir") — normaliza pra string vazia antes de gravar, mantendo a
     * coluna `category` limpa (usada como `group` do card no catálogo de
     * widgets — ver Dashboard::getCards()).
     */
    private function normalizeCategoryInput(array $input): array
    {
        if (($input['category'] ?? null) === '0') {
            $input['category'] = '';
        }
        return $input;
    }

    /**
     * Substituir o dashboard nativo de um módulo só é permitido pra um item
     * que já tem uma regra de visibilidade (aba "Visibilidade") concedendo um
     * público explícito e enumerável — ver docblock de ModuleDashboard sobre
     * por que ("Todos com acesso ao módulo", seja por não ter regra nenhuma
     * seja por uma regra com Ação "Todos", não é uma lista enumerável de
     * Perfil/Grupo/Usuário/Entidade, e o dashboard nativo auto-provisionado
     * precisa de uma lista concreta pra conceder acesso). `replaces_module`
     * só é postado pela tabela de gerenciamento (showImportedManagementSection()),
     * numa edição de item já existente — nunca na criação. Não rejeita a
     * submissão inteira — só limpa `replaces_module` com um aviso, mantendo o
     * resto das alterações.
     */
    private function validateModuleReplacement(array $input): array
    {
        $module = trim((string)($input['replaces_module'] ?? ''));
        if ($module === '' || $module === '0' || !isset(ModuleDashboard::MODULES[$module])) {
            $input['replaces_module'] = '';
            return $input;
        }

        if (!VisibilityRule::hasConcreteGrantForItem($this)) {
            Session::addMessageAfterRedirect(
                __('Para substituir o dashboard de um módulo, configure uma regra na aba "Visibilidade" com pelo menos um perfil/grupo/usuário/entidade.', 'analyticdesign'),
                false,
                ERROR
            );
            $input['replaces_module'] = '';
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        ModuleDashboard::syncNativeDashboard($this);
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        ModuleDashboard::syncNativeDashboard($this);
    }

    /**
     * Limpa o que fica órfão ao remover um dashboard exposto (ver botão
     * "Remover" em showImportedManagementSection()/ajax/deletedashboarditem.php):
     * o dashboard nativo auto-provisionado (se `replaces_module` estivesse
     * configurado) — sem isso ficaria para sempre no banco, referenciando um
     * item que não existe mais. Nenhuma regra de visibilidade (aba
     * "Visibilidade") referencia o item diretamente por ID (elas casam por
     * Critérios, ex.: nome do dashboard) — nada a limpar nesse lado.
     */
    public function post_purgeItem()
    {
        parent::post_purgeItem();
        ModuleDashboard::deleteNativeDashboard((int)$this->fields['id']);
    }

    public static function install(\Migration $migration): void
    {
        global $DB;
        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $DB->doQuery("
                CREATE TABLE `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `connections_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `external_id` VARCHAR(255) NOT NULL DEFAULT '',
                    `name` VARCHAR(255) NOT NULL DEFAULT '',
                    `category` VARCHAR(255) NOT NULL DEFAULT '',
                    `embed_url` TEXT NULL,
                    `is_active` TINYINT NOT NULL DEFAULT 1,
                    `is_private` TINYINT NOT NULL DEFAULT 0,
                    `replaces_module` VARCHAR(20) NOT NULL DEFAULT '',
                    `date_creation` DATETIME NULL DEFAULT NULL,
                    `date_mod` DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `connections_id` (`connections_id`),
                    KEY `category` (`category`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        // Colunas adicionadas em versões depois da 0.3.0: em upgrade, a
        // tabela já existe sem elas — ver nota de idempotência de install()
        // em hook.php (este método também roda de novo em toda atualização
        // de versão, não só na primeira instalação).
        if (!$DB->fieldExists($table, 'is_private')) {
            $DB->doQuery("ALTER TABLE `{$table}` ADD COLUMN `is_private` TINYINT NOT NULL DEFAULT 0 AFTER `is_active`");
        }
        if (!$DB->fieldExists($table, 'replaces_module')) {
            $DB->doQuery("ALTER TABLE `{$table}` ADD COLUMN `replaces_module` VARCHAR(20) NOT NULL DEFAULT '' AFTER `is_private`");
        }
        // `date_creation`/`date_mod` nasceram como TIMESTAMP — ver
        // HasTimestampMigration e Connection::install() para o motivo.
        self::convertTimestampColumnsToDatetime($table);

        // `glpi_plugin_analyticdesign_dashboarditems_visibility` (ItemVisibility)
        // existia até a 0.8.0 — retirada quando a visibilidade por card foi
        // substituída inteiramente por regras (VisibilityRule, aba
        // "Visibilidade"). Sem a classe: dropa direto pra limpar instalações
        // que já tinham essa tabela.
        if ($DB->tableExists('glpi_plugin_analyticdesign_dashboarditems_visibility')) {
            $DB->doQuery("DROP TABLE `glpi_plugin_analyticdesign_dashboarditems_visibility`");
        }

        VisibilityRule::install();
    }

    public static function uninstall(): void
    {
        global $DB;

        // Limpa os dashboards nativos auto-provisionados (ver
        // ModuleDashboard::syncNativeDashboard()) antes de derrubar a
        // própria tabela — depois de dropada não haveria mais como calcular
        // as chaves a partir dos ids dos itens. `glpi_dashboards_dashboards`
        // é tabela do core, não é limpa junto com o resto do plugin.
        if ($DB->tableExists('glpi_dashboards_dashboards')) {
            // Apaga direto via query builder, não `CommonDBTM::delete()`: esse
            // método refaz um `getFromDB($id)` internamente, e
            // `Glpi\Dashboard\Dashboard::getFromDB()` busca pela coluna `key`
            // (string), não pelo `id` numérico — nunca acharia a linha (ver
            // ModuleDashboard::deleteNativeDashboard(), mesmo bug corrigido lá).
            $ids = $DB->request([
                'SELECT' => 'id',
                'FROM'   => 'glpi_dashboards_dashboards',
                'WHERE'  => ['context' => 'plugingrafanaintegration'],
            ]);
            foreach ($ids as $row) {
                $DB->delete('glpi_dashboards_rights', ['dashboards_dashboards_id' => (int)$row['id']]);
                $DB->delete('glpi_dashboards_dashboards', ['id' => (int)$row['id']]);
            }
        }

        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
        VisibilityRule::uninstall();
    }
}
