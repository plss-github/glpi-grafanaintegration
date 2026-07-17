<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Dashboard externo exposto como card no GLPI.
 * Tabela: glpi_plugin_analyticdesign_dashboarditems (nome derivado da classe
 * por CommonDBTM::getTable() — não é `..._items`, apesar do que sugeriria
 * uma leitura rápida do nome da classe).
 */

namespace GlpiPlugin\Analyticdesign;

use CommonDBTM;
use CommonGLPI;
use Dropdown;
use Session;
use GlpiPlugin\Analyticdesign\Source\DashboardSourceInterface;
use GlpiPlugin\Analyticdesign\Source\PowerBiSource;
use GlpiPlugin\Analyticdesign\Traits\HasCheckboxField;
use GlpiPlugin\Analyticdesign\Traits\HasFormFieldLayout;
use Html;

class DashboardItem extends CommonDBTM
{
    use HasCheckboxField;
    use HasFormFieldLayout;

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

    /**
     * Exemplo de URL de embed mostrado abaixo do campo — varia pela
     * ferramenta da Connection dona, já que o formato é bem diferente entre
     * Grafana (link direto do dashboard) e Power BI (URL de "publish to
     * web", que embute um token opaco em `?r=`).
     */
    private static function embedUrlExample(?Connection $connection): string
    {
        if ($connection !== null && $connection->fields['type'] === PowerBiSource::getType()) {
            return __('Ex.: https://app.powerbi.com/view?r=eyJrIjoiMTIz...', 'analyticdesign');
        }
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
     * ItemVisibility sobre o modelo de visibilidade.
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
     *  4. Se `is_private`, casar com pelo menos uma regra de visibilidade
     *     (ItemVisibility) — a restrição fina pedida (Perfil/Grupo/Usuário/
     *     Entidade específicos), além de quem já passou pelas de cima.
     */
    public function isVisibleForCurrentUser(): bool
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
        if (!Session::haveAccessToEntity((int)$connection->fields['entities_id'], (bool)$connection->fields['is_recursive'])) {
            return false;
        }

        if (!(bool)((int)($this->fields['is_private'] ?? 0))) {
            return true;
        }
        return ItemVisibility::isVisibleForCurrentUser((int)$this->fields['id']);
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
            'name'     => __('Categoria', 'analyticdesign'),
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

        return $tab;
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
            return self::createTabEntry(self::getTypeName(2), $count);
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
     * Renderiza, dentro da aba "Dashboards" da Connection:
     *  - os dashboards já importados (edição inline de categoria/ativo);
     *  - a lista de dashboards disponíveis na fonte, prontos para importar.
     * A listagem ao vivo (testConnection/listDashboards) é best-effort: se a
     * fonte não responder, mostramos apenas o que já foi importado.
     *
     * Renderizado em PHP/HTML puro (mesma decisão de Connection::showForm()) —
     * sem depender de templates Twig cuja integração exata com o GLPI 11 não
     * pôde ser validada contra uma instância real.
     */
    public static function showForConnection(Connection $connection): void
    {
        $connectionsId = (int)$connection->fields['id'];
        $imported = self::getForConnection($connectionsId);
        [$available, $listError] = self::resolveAvailableDashboards($connection, $imported);
        $ajaxRoot = self::ajaxRoot();

        self::showImportedSection($connectionsId, $imported, $ajaxRoot);
        self::showAvailableSection($connectionsId, $available, $listError, $ajaxRoot);

        // Quando a listagem falha por erro de conexão, não cai mais no
        // formulário de adição manual: o problema é a própria conexão (URL/
        // credenciais), resolvido na aba "Características" — não faz
        // sentido oferecer para digitar um dashboard à mão nesse caso.
        // "Adicionar manualmente" continua disponível quando o tipo/modo
        // simplesmente não lista automaticamente por design (ex.: Power BI
        // publish_to_web), que não passa por aqui como erro (ver
        // PowerBiSource::listDashboards()).
        if ($listError === null) {
            self::showManualAddSection($connection, $connectionsId, $ajaxRoot);
        }
    }

    private static function ajaxRoot(): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/analyticdesign/ajax';
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

    /** @param DashboardItem[] $imported */
    private static function showImportedSection(int $connectionsId, array $imported, string $ajaxRoot): void
    {
        echo "<div class='analyticdesign-imported'>";
        echo "<h3>" . __('Dashboards importados', 'analyticdesign') . "</h3>";

        if (empty($imported)) {
            echo "<p class='text-muted'>" . __('Nenhum dashboard importado ainda.', 'analyticdesign') . "</p>";
            echo "</div>";
            return;
        }

        echo "<form name='analyticdesign_update_items' method='post' action='"
            . htmlspecialchars($ajaxRoot . '/updatedashboarditems.php', ENT_QUOTES) . "'>";
        echo "<input type='hidden' name='connections_id' value='{$connectionsId}'>";
        echo "<table class='tab_cadre_fixe'><tr class='tab_bg_1'>";
        echo "<th>" . __('Nome') . "</th>";
        echo "<th>" . __('ID externo', 'analyticdesign') . "</th>";
        echo "<th>" . __('Categoria', 'analyticdesign') . "</th>";
        echo "<th>" . __('Ativo') . "</th>";
        echo "</tr>";
        foreach ($imported as $item) {
            $id = (int)$item->fields['id'];
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . htmlspecialchars($item->fields['name'], ENT_QUOTES) . "</td>";
            echo "<td>" . htmlspecialchars($item->fields['external_id'], ENT_QUOTES) . "</td>";
            echo "<td>" . Html::input("items[{$id}][category]", ['value' => $item->fields['category']]) . "</td>";
            echo "<td>" . self::renderCheckbox("items[{$id}][is_active]", (int)$item->fields['is_active'] === 1) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<div class='mt-2'>";
        echo "<button type='submit' name='update' class='btn btn-primary'>" . __('Salvar') . "</button>";
        echo "</div>";
        Html::closeForm();
        echo "</div>";
    }

    /** @param array<int, array{external_id:string, name:string, embed_url:?string}> $available */
    private static function showAvailableSection(int $connectionsId, array $available, ?string $listError, string $ajaxRoot): void
    {
        echo "<div class='analyticdesign-available mt-4'>";
        echo "<h3>" . __('Dashboards disponíveis na fonte', 'analyticdesign') . "</h3>";

        if ($listError !== null) {
            echo "<p class='alert alert-important alert-warning'>" . htmlspecialchars($listError, ENT_QUOTES) . "</p>";
            echo "</div>";
            return;
        }
        if (empty($available)) {
            echo "<p class='text-muted'>" . __('Nada novo para importar — todos os dashboards já foram importados, ou a fonte não retornou nenhum.', 'analyticdesign') . "</p>";
            echo "</div>";
            return;
        }

        echo "<form name='analyticdesign_import_items' method='post' action='"
            . htmlspecialchars($ajaxRoot . '/importdashboards.php', ENT_QUOTES) . "'>";
        echo "<input type='hidden' name='connections_id' value='{$connectionsId}'>";
        echo "<table class='tab_cadre_fixe'><tr class='tab_bg_1'>";
        echo "<th>" . __('Importar') . "</th>";
        echo "<th>" . __('Nome') . "</th>";
        echo "<th>" . __('ID externo', 'analyticdesign') . "</th>";
        echo "<th>" . __('Categoria', 'analyticdesign') . "</th>";
        echo "</tr>";
        foreach ($available as $i => $dash) {
            $extId = htmlspecialchars($dash['external_id'], ENT_QUOTES);
            $name  = htmlspecialchars($dash['name'], ENT_QUOTES);
            $embed = htmlspecialchars($dash['embed_url'] ?? '', ENT_QUOTES);
            echo "<tr class='tab_bg_1'>";
            echo "<td><input type='checkbox' name='import[{$i}][selected]' value='1'></td>";
            echo "<td>{$name}"
                . "<input type='hidden' name='import[{$i}][name]' value='{$name}'>"
                . "<input type='hidden' name='import[{$i}][external_id]' value='{$extId}'>"
                . "<input type='hidden' name='import[{$i}][embed_url]' value='{$embed}'>"
                . "</td>";
            echo "<td>{$extId}</td>";
            echo "<td>" . Html::input("import[{$i}][category]", ['value' => '']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<div class='mt-2'>";
        echo "<button type='submit' name='import' class='btn btn-primary'>"
            . __('Importar selecionados', 'analyticdesign') . "</button>";
        echo "</div>";
        Html::closeForm();
        echo "</div>";
    }

    /**
     * Única forma de cadastrar um dashboard no modo publish_to_web (a API do
     * Power BI não expõe essas URLs — ver PowerBiSource::listDashboards()).
     * Só é chamada quando a fonte simplesmente não suporta listagem
     * automática por design (`$listError === null` no chamador) — quando a
     * listagem FALHA (fonte fora do ar, credenciais erradas), esta seção
     * fica escondida de propósito: o problema é a própria conexão, resolvido
     * na aba "Características", não digitando um dashboard à mão (ver
     * showForConnection()).
     */
    private static function showManualAddSection(Connection $connection, int $connectionsId, string $ajaxRoot): void
    {
        echo "<div class='analyticdesign-manual-add mt-4'>";
        echo "<h3>" . __('Adicionar manualmente', 'analyticdesign') . "</h3>";
        echo "<p class='text-muted'>" . __('Use esta opção quando a fonte não permite listar dashboards automaticamente (ex.: Power BI em modo "publish to web") — cole a URL pública/de embed diretamente.', 'analyticdesign') . "</p>";

        $isPublishToWeb = $connection->fields['type'] === PowerBiSource::getType()
            && ($connection->fields['embed_mode'] ?? '') === DashboardSourceInterface::EMBED_MODE_PUBLISH_TO_WEB;
        if ($isPublishToWeb) {
            echo "<p class='alert alert-important alert-danger'>"
                . "<i class='ti ti-alert-triangle'></i> "
                . __('Atenção: a URL colada abaixo fica acessível a qualquer pessoa com o link, sem autenticação. Não use para dados confidenciais.', 'analyticdesign')
                . "</p>";
        }

        echo "<form name='analyticdesign_add_manual' method='post' action='"
            . htmlspecialchars($ajaxRoot . '/addmanualdashboard.php', ENT_QUOTES) . "'>";
        echo "<input type='hidden' name='connections_id' value='{$connectionsId}'>";

        self::openFieldsRow();
        self::openField('name', __('Nome'), 'analyticdesign_manual_name');
        echo Html::input('name', ['id' => 'analyticdesign_manual_name', 'value' => '']);
        echo "<div class='form-text text-muted'>" . __('Ex.: Indicadores de chamados', 'analyticdesign') . "</div>";
        self::closeField();

        self::openField('category', __('Categoria', 'analyticdesign'), 'analyticdesign_manual_category');
        echo Html::input('category', ['id' => 'analyticdesign_manual_category', 'value' => '']);
        echo "<div class='form-text text-muted'>" . __('Ex.: Infraestrutura de TI', 'analyticdesign') . "</div>";
        self::closeField();
        self::closeFieldsRow();

        self::openFieldsRow();
        self::openField('embed_url', __('URL de embed', 'analyticdesign'), 'analyticdesign_manual_embed_url', true);
        echo Html::input('embed_url', ['id' => 'analyticdesign_manual_embed_url', 'value' => '']);
        echo "<div class='form-text text-muted'>" . self::embedUrlExample($connection) . "</div>";
        self::closeField();
        self::closeFieldsRow();

        self::showVisibilityField(false, self::emptyVisibilityRights());

        echo "<div class='mt-2'>";
        echo "<button type='submit' name='add' class='btn btn-primary'>" . __('Adicionar', 'analyticdesign') . "</button>";
        echo "</div>";
        Html::closeForm();
        echo "</div>";
    }

    /** @return array<class-string, int[]> todas as regras vazias — item novo, nada configurado ainda. */
    private static function emptyVisibilityRights(): array
    {
        return array_fill_keys(ItemVisibility::TARGET_TYPES, []);
    }

    /**
     * Campo "Visibilidade": Todos (quem já tem o direito de leitura do
     * módulo) ou Restrito a perfis/grupos/usuários/entidades específicos —
     * ver docblock de ItemVisibility e DashboardItem::isVisibleForCurrentUser().
     * Reaproveitado tanto na criação (Adicionar manualmente) quanto na
     * edição de um item já importado (showForm()).
     *
     * @param array<class-string, int[]> $currentRights
     */
    private static function showVisibilityField(bool $isPrivate, array $currentRights): void
    {
        self::openFieldsRow();
        self::openField('is_private', __('Visibilidade', 'analyticdesign'), 'analyticdesign_item_is_private', true);
        Dropdown::showFromArray('is_private', [
            0 => __('Todos com acesso ao módulo', 'analyticdesign'),
            1 => __('Restrito a...', 'analyticdesign'),
        ], ['value' => $isPrivate ? 1 : 0]);

        $dropdownValues = [];
        foreach ($currentRights as $itemtype => $ids) {
            if (!empty($ids)) {
                $dropdownValues[$itemtype::getForeignKeyField()] = $ids;
            }
        }
        echo "<div class='analyticdesign-visibility-targets' style='margin-top:.5rem;"
            . ($isPrivate ? '' : 'display:none;') . "'>";
        echo VisibilityDropdown::show('visibility', $dropdownValues);
        echo "<div class='form-text text-muted'>"
            . __('Além de quem já tem o direito de leitura do módulo, restringe este card a perfis/grupos/usuários/entidades específicos.', 'analyticdesign')
            . "</div>";
        echo "</div>";
        self::closeField();
        self::closeFieldsRow();
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

        self::openField('category', __('Categoria', 'analyticdesign'), 'analyticdesign_item_category');
        echo Html::input('category', ['id' => 'analyticdesign_item_category', 'value' => $this->fields['category']]);
        echo "<div class='form-text text-muted'>" . __('Ex.: Infraestrutura de TI', 'analyticdesign') . "</div>";
        self::closeField();

        self::closeFieldsRow();

        self::openFieldsRow();
        self::openField('embed_url', __('URL de embed', 'analyticdesign'), 'analyticdesign_item_embed_url', true);
        echo Html::input('embed_url', ['id' => 'analyticdesign_item_embed_url', 'value' => $this->fields['embed_url']]);
        echo "<div class='form-text text-muted'>" . self::embedUrlExample($connection) . "</div>";
        self::closeField();
        self::closeFieldsRow();

        self::openFieldsRow();
        self::openField('is_active', __('Ativo'), 'analyticdesign_item_is_active');
        echo self::renderCheckbox('is_active', (int)($this->fields['is_active'] ?? 0) === 1);
        self::closeField();
        self::closeFieldsRow();

        self::showVisibilityField(
            (bool)((int)($this->fields['is_private'] ?? 0)),
            ItemVisibility::getForItem((int)$this->fields['id'])
        );

        echo "</td></tr>";
        $this->showFormButtons($options);

        return true;
    }

    /**
     * Cria os DashboardItem selecionados pelo admin na tela de importação.
     *
     * `is_private`/`visibility` são opcionais: a listagem em lote não tem UI
     * para configurá-los por linha (ver showAvailableSection()) — nascem
     * "Todos" e ficam editáveis depois via showForm(). O fluxo de adição
     * manual (ver addmanualdashboard.php) já informa os dois desde a criação.
     *
     * `visibility`, quando informado, é o array "achatado" que o
     * AbstractRightsDropdown posta (ex.: `['profiles_id-3', 'groups_id-1']`)
     * — convertido por itemtype só em saveVisibilityFromInput() (post_addItem).
     *
     * @param array<int, array{external_id:string, name:string, embed_url?:string, category?:string, is_private?:bool, visibility?:string[]}> $selection
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
                'is_private'          => !empty($dash['is_private']) ? 1 : 0,
                'visibility'          => $dash['visibility'] ?? [],
            ]);
            if ($ok) {
                $created++;
            }
        }
        return $created;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->saveVisibilityFromInput();
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->saveVisibilityFromInput();
    }

    /**
     * Sincroniza as regras de ItemVisibility — só quando o formulário
     * realmente incluía o campo `is_private` (presença da própria chave no
     * input, não um marcador à parte: todo formulário que renderiza
     * showVisibilityField() sempre posta `is_private`, e o único que não
     * inclui esse campo é a edição em lote de categoria/ativo em
     * showImportedSection() — não roda para essa, para não apagar regras já
     * configuradas por engano).
     */
    private function saveVisibilityFromInput(): void
    {
        if (!array_key_exists('is_private', $this->input)) {
            return;
        }

        $posted = $this->input['visibility'] ?? [];
        $rights = [];
        foreach (ItemVisibility::TARGET_TYPES as $itemtype) {
            $rights[$itemtype] = VisibilityDropdown::getPostedIds($posted, $itemtype);
        }
        ItemVisibility::replaceForItem((int)$this->fields['id'], $rights);
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
                    `date_creation` TIMESTAMP NULL DEFAULT NULL,
                    `date_mod` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `connections_id` (`connections_id`),
                    KEY `category` (`category`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        // Coluna adicionada depois da 0.3.0: em upgrade, a tabela já existe
        // sem `is_private` — ver nota de idempotência de install() em
        // hook.php (este método também roda de novo em toda atualização de
        // versão, não só na primeira instalação).
        if (!$DB->fieldExists($table, 'is_private')) {
            $DB->doQuery("ALTER TABLE `{$table}` ADD COLUMN `is_private` TINYINT NOT NULL DEFAULT 0 AFTER `is_active`");
        }

        ItemVisibility::install();
    }

    public static function uninstall(): void
    {
        global $DB;
        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
        ItemVisibility::uninstall();
    }
}
