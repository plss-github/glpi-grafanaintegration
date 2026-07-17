<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Regras de visibilidade restrita de um DashboardItem (quando `is_private` =
 * 1): lista de Perfil/Grupo/Usuário/Entidade que enxergam o card, além de
 * quem já tem o direito de leitura do módulo — mesmo modelo de
 * "compartilhamento" que o GLPI usa para seus próprios dashboards nativos
 * (ver `Glpi\Dashboard\Right` e `Dashboard::checkRights()` no core: uma
 * linha por alvo, OR entre todas — casar qualquer uma já basta).
 *
 * Tabela simples, sem CommonDBTM/CommonDBChild: não precisa de aba, busca ou
 * formulário próprios — é gerenciada inteiramente através da aba
 * "Dashboards" da Connection (ver DashboardItem::showForm()), e o único
 * consumidor da checagem de visibilidade é o render do card
 * (Dashboard::renderEmbedWidget()/getCards()).
 */

namespace GlpiPlugin\Analyticdesign;

use Entity;
use Group;
use Profile;
use Session;
use User;

class ItemVisibility
{
    /**
     * Itemtypes suportados como alvo de uma regra de visibilidade — única
     * fonte de verdade (DashboardItem e VisibilityDropdown referenciam esta
     * constante em vez de repetir a lista).
     */
    public const TARGET_TYPES = [Profile::class, Group::class, User::class, Entity::class];

    public static function getTable(): string
    {
        return 'glpi_plugin_analyticdesign_dashboarditems_visibility';
    }

    /**
     * @return array<class-string, int[]> ex.: [Profile::class => [3], Group::class => [1, 2]]
     */
    public static function getForItem(int $itemId): array
    {
        global $DB;

        $rights = array_fill_keys(self::TARGET_TYPES, []);
        $it = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_analyticdesign_dashboarditems_id' => $itemId],
        ]);
        foreach ($it as $row) {
            if (isset($rights[$row['itemtype']])) {
                $rights[$row['itemtype']][] = (int)$row['items_id'];
            }
        }
        return $rights;
    }

    /**
     * Substitui todas as regras de um item pelas informadas (delete + insert
     * — mesma estratégia "sincronizar tudo de novo" usada para o resto do
     * plugin não precisar rastrear alterações incrementais).
     *
     * @param array<class-string, int[]> $rightsByItemtype
     */
    public static function replaceForItem(int $itemId, array $rightsByItemtype): void
    {
        global $DB;

        $DB->delete(self::getTable(), ['plugin_analyticdesign_dashboarditems_id' => $itemId]);

        foreach ($rightsByItemtype as $itemtype => $ids) {
            if (!in_array($itemtype, self::TARGET_TYPES, true)) {
                continue;
            }
            // array_filter() puro descartaria o alvo 0 (Entidade Raiz —
            // GLPI usa entities_id=0 para a entidade raiz, um alvo
            // perfeitamente válido) — filtra só valores realmente vazios.
            foreach (array_unique(array_filter($ids, static fn ($v) => $v !== null && $v !== '')) as $targetId) {
                $DB->insert(self::getTable(), [
                    'plugin_analyticdesign_dashboarditems_id' => $itemId,
                    'itemtype'                       => $itemtype,
                    'items_id'                       => (int)$targetId,
                ]);
            }
        }
    }

    /**
     * O item é privado e o usuário logado casa com pelo menos uma regra
     * (perfil ativo, um dos grupos, o próprio usuário, ou uma das
     * entidades ativas)? Mesma lógica de OR entre critérios que
     * `Glpi\Dashboard\Dashboard::checkRights()` usa no core.
     */
    public static function isVisibleForCurrentUser(int $itemId): bool
    {
        if (!Session::getLoginUserID()) {
            return false;
        }

        $rights = self::getForItem($itemId);

        // Comparação estrita (in_array com $strict=true) exige o mesmo tipo
        // dos dois lados — $_SESSION guarda id de perfil/usuário como string
        // em alguns fluxos de login; sem o (int) aqui, "4" !== 4 e a regra
        // nunca casava mesmo com o perfil certo selecionado (bug real
        // encontrado testando contra uma instância viva).
        //
        // Entidade usa Session::haveAccessToOneOfEntities(..., true) (mesma
        // semântica recursiva que DashboardItem::isVisibleForCurrentUser()
        // já usa para a Connection dona), não um array_intersect direto: um
        // alvo configurado numa entidade-mãe deve valer para quem está numa
        // sub-entidade, igual ao resto do GLPI — inconsistência real
        // encontrada na revisão de código (os dois pontos de checagem de
        // entidade usavam regras diferentes).
        return in_array((int)($_SESSION['glpiactiveprofile']['id'] ?? 0), $rights[Profile::class], true)
            || in_array((int)($_SESSION['glpiID'] ?? 0), $rights[User::class], true)
            || count(array_intersect($rights[Group::class], $_SESSION['glpigroups'] ?? [])) > 0
            || Session::haveAccessToOneOfEntities($rights[Entity::class], true);
    }

    public static function install(): void
    {
        global $DB;
        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $DB->doQuery("
                CREATE TABLE `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `plugin_analyticdesign_dashboarditems_id` INT UNSIGNED NOT NULL,
                    `itemtype` VARCHAR(100) NOT NULL,
                    `items_id` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `plugin_analyticdesign_dashboarditems_id` (`plugin_analyticdesign_dashboarditems_id`),
                    KEY `item` (`itemtype`, `items_id`)
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
