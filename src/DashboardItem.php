<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Dashboard externo exposto como card no GLPI.
 * Tabela: glpi_plugin_analyticdesign_items
 */

namespace GlpiPlugin\Analyticdesign;

use CommonDBTM;
use CommonGLPI;
use Html;

class DashboardItem extends CommonDBTM
{
    public static $rightname = 'plugin_analyticdesign_connection';

    public static function getTypeName($nb = 0)
    {
        return _n('Dashboard exposto', 'Dashboards expostos', $nb, 'analyticdesign');
    }

    public static function getIcon()
    {
        return 'ti ti-layout-dashboard';
    }

    /** Devolve a Connection dona deste item. */
    public function getConnection(): ?Connection
    {
        $conn = new Connection();
        if ($conn->getFromDB((int)($this->fields['connections_id'] ?? 0))) {
            return $conn;
        }
        return null;
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

        return $tab;
    }

    /**
     * Aba "Dashboards" no formulário da Connection (ver Connection::defineTabs()).
     */
    public static function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
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
        global $CFG_GLPI;

        $connectionsId = (int)$connection->fields['id'];
        $imported = self::getForConnection($connectionsId);

        $importedExternalIds = array_map(
            static fn (self $i) => $i->fields['external_id'],
            $imported
        );

        $available = [];
        $listError = null;
        try {
            $source = $connection->getSource();
            foreach ($source->listDashboards() as $dash) {
                if (!in_array($dash['external_id'], $importedExternalIds, true)) {
                    $available[] = $dash;
                }
            }
        } catch (\Throwable $e) {
            $listError = __('Não foi possível listar dashboards da fonte. Verifique a conexão.', 'analyticdesign');
        }

        $ajaxRoot = $CFG_GLPI['root_doc'] . '/plugins/analyticdesign/ajax';

        echo "<div class='analyticdesign-imported'>";
        echo "<h3>" . __('Dashboards importados', 'analyticdesign') . "</h3>";
        if (empty($imported)) {
            echo "<p class='text-muted'>" . __('Nenhum dashboard importado ainda.', 'analyticdesign') . "</p>";
        } else {
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
                echo "<td><input type='checkbox' name='items[{$id}][is_active]' value='1'"
                    . ((int)$item->fields['is_active'] === 1 ? " checked" : "") . "></td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "<div class='mt-2'>";
            echo "<button type='submit' name='update' class='btn btn-primary'>" . __('Salvar') . "</button>";
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";

        echo "<div class='analyticdesign-available mt-4'>";
        echo "<h3>" . __('Dashboards disponíveis na fonte', 'analyticdesign') . "</h3>";
        if ($listError !== null) {
            echo "<p class='alert alert-important alert-warning'>" . htmlspecialchars($listError, ENT_QUOTES) . "</p>";
        } elseif (empty($available)) {
            echo "<p class='text-muted'>" . __('Nada novo para importar — todos os dashboards já foram importados, ou a fonte não retornou nenhum.', 'analyticdesign') . "</p>";
        } else {
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
        }
        echo "</div>";

        // Adição manual: única forma de cadastrar um dashboard no modo
        // publish_to_web (a API do Power BI não expõe essas URLs — ver
        // PowerBiSource::listDashboards()); também serve de válvula de escape
        // caso a listagem automática de outra fonte falhe ou fique incompleta.
        echo "<div class='analyticdesign-manual-add mt-4'>";
        echo "<h3>" . __('Adicionar manualmente', 'analyticdesign') . "</h3>";
        echo "<p class='text-muted'>" . __('Use esta opção quando a fonte não permite listar dashboards automaticamente (ex.: Power BI em modo "publish to web") — cole a URL pública/de embed diretamente.', 'analyticdesign') . "</p>";
        if ($connection->fields['type'] === 'powerbi' && ($connection->fields['embed_mode'] ?? '') === 'publish_to_web') {
            echo "<p class='alert alert-important alert-danger'>"
                . "<i class='ti ti-alert-triangle'></i> "
                . __('Atenção: a URL colada abaixo fica acessível a qualquer pessoa com o link, sem autenticação. Não use para dados confidenciais.', 'analyticdesign')
                . "</p>";
        }
        echo "<form name='analyticdesign_add_manual' method='post' action='"
            . htmlspecialchars($ajaxRoot . '/addmanualdashboard.php', ENT_QUOTES) . "'>";
        echo "<input type='hidden' name='connections_id' value='{$connectionsId}'>";
        echo "<table class='tab_cadre_fixe'><tr class='tab_bg_1'>";
        echo "<td>" . __('Nome') . "</td>";
        echo "<td>" . Html::input('name', ['value' => '']) . "</td>";
        echo "<td>" . __('Categoria', 'analyticdesign') . "</td>";
        echo "<td>" . Html::input('category', ['value' => '']) . "</td>";
        echo "</tr><tr class='tab_bg_1'>";
        echo "<td>" . __('URL de embed', 'analyticdesign') . "</td>";
        echo "<td colspan='3'>" . Html::input('embed_url', ['value' => '', 'size' => 60]) . "</td>";
        echo "</tr></table>";
        echo "<div class='mt-2'>";
        echo "<button type='submit' name='add' class='btn btn-primary'>" . __('Adicionar', 'analyticdesign') . "</button>";
        echo "</div>";
        Html::closeForm();
        echo "</div>";
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

        $connection = $this->getConnection();

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Nome') . "</td>";
        echo "<td>" . Html::input('name', ['value' => $this->fields['name']]) . "</td>";
        echo "<td>" . Connection::getTypeName(1) . "</td>";
        echo "<td>" . ($connection !== null ? htmlspecialchars($connection->fields['name'], ENT_QUOTES) : '-') . "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('ID externo', 'analyticdesign') . "</td>";
        echo "<td>" . htmlspecialchars($this->fields['external_id'], ENT_QUOTES) . "</td>";
        echo "<td>" . __('Categoria', 'analyticdesign') . "</td>";
        echo "<td>" . Html::input('category', ['value' => $this->fields['category']]) . "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('URL de embed', 'analyticdesign') . "</td>";
        echo "<td colspan='3'>" . Html::input('embed_url', ['value' => $this->fields['embed_url'], 'size' => 60]) . "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Ativo') . "</td>";
        echo "<td><input type='checkbox' name='is_active' value='1'"
            . ((int)($this->fields['is_active'] ?? 0) === 1 ? " checked" : "") . "></td>";
        echo "<td colspan='2'></td></tr>";

        $this->showFormButtons($options);

        return true;
    }

    /**
     * Cria os DashboardItem selecionados pelo admin na tela de importação.
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
                'connections_id' => (int)$connection->fields['id'],
                'external_id'    => $dash['external_id'],
                'name'           => $dash['name'] ?? $dash['external_id'],
                'category'       => $dash['category'] ?? '',
                'embed_url'      => $dash['embed_url'] ?? '',
                'is_active'      => 1,
            ]);
            if ($ok) {
                $created++;
            }
        }
        return $created;
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
                    `date_creation` TIMESTAMP NULL DEFAULT NULL,
                    `date_mod` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `connections_id` (`connections_id`),
                    KEY `category` (`category`)
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
