<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Regra de visibilidade baseada em critério/ação, pedida como alternativa ao
 * ajuste manual por card (ItemVisibility): de um lado **Critérios** (que
 * dashboards a regra alcança, combinados por E/OU), do outro **Ação**
 * (quem ganha acesso) — mesmo modelo conceitual das Regras de negócio
 * nativas do GLPI, mas uma implementação própria e simples do plugin (não
 * uma subclasse de `Rule`/`RuleCollection` do core — decisão explícita para
 * manter esta primeira versão pequena e totalmente sob controle do plugin).
 *
 * Escopada por Connection (uma regra vale só para os dashboards daquela
 * fonte) — vira uma 4ª aba no formulário da Connection (ver
 * ConnectionVisibilityRules).
 *
 * "Ação" reaproveita o MESMO widget de ItemVisibility (VisibilityDropdown —
 * Perfil/Grupo/Usuário/Entidade, várias seleções combinadas com OU já por
 * natureza do componente) em vez de uma UI nova: uma regra com 3 ações
 * "atribuir visibilidade a X" é exatamente "casar com qualquer uma das 3",
 * que é como ItemVisibility já funciona.
 *
 * "Critérios" é a parte genuinamente nova: até
 * self::CRITERIA_ROWS linhas fixas (Campo/Condição/Valor) por regra — linhas
 * com Valor vazio são ignoradas ao salvar. Optou-se por linhas fixas em vez
 * de adicionar/remover dinâmico via JS para manter a primeira versão simples;
 * se algum dia precisar de mais que `CRITERIA_ROWS` critérios por regra, dá
 * pra salvar e editar de novo (as linhas já preenchidas continuam lá) ou
 * aumentar a constante.
 *
 * Uma regra só é avaliada quando o card está com Visibilidade "Restrito a..."
 * (`is_private=1`) — ver DashboardItem::isVisibleForCurrentUser(): serve como
 * uma segunda forma (além do ItemVisibility direto no card) de satisfazer
 * essa restrição, nunca para abrir um card que já está "Todos com acesso ao
 * módulo" (`is_private=0`), que já é público por natureza.
 */

namespace GlpiPlugin\Analyticdesign;

use CommonDBTM;
use Dropdown;
use Entity;
use GlpiPlugin\Analyticdesign\Traits\HasFormFieldLayout;
use Group;
use Html;
use Profile;
use Session;
use User;

class VisibilityRule extends CommonDBTM
{
    use HasFormFieldLayout;

    public static $rightname = Connection::RIGHTNAME;

    /** Quantas linhas de critério renderizar/aceitar por regra — ver docblock da classe. */
    public const CRITERIA_ROWS = 5;

    /**
     * Chaves válidas de campo/condição — usadas para validar o que vem do
     * POST. Os RÓTULOS (traduzíveis) ficam em métodos (fieldLabels()/
     * conditionLabels()), não aqui: uma constante de classe não pode chamar
     * __() (não é uma expressão constante em tempo de compilação).
     */
    private const FIELD_KEYS = ['name', 'category'];
    private const CONDITION_KEYS = ['equals', 'contains'];

    /** @return array<string, string> campo => rótulo, dos itemtypes que podem ser critério. */
    public static function fieldLabels(): array
    {
        return [
            'name'     => __('Dashboard (nome)', 'analyticdesign'),
            'category' => __('Módulo', 'analyticdesign'),
        ];
    }

    /** @return array<string, string> condição => rótulo. */
    public static function conditionLabels(): array
    {
        return [
            'equals'   => __('é', 'analyticdesign'),
            'contains' => __('contém', 'analyticdesign'),
        ];
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Regra de visibilidade', 'Regras de visibilidade', $nb, 'analyticdesign');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-check';
    }

    /**
     * Formulário de UMA regra — layout de duas colunas (Critérios | Ação),
     * servido por front/visibilityrule.form.php (página independente, não
     * uma aba — ver docblock da classe). `connections_id` chega tanto de um
     * item novo (querystring `?connections_id=X`, mesclado em
     * `$this->fields` por `CommonDBTM::can()` — ver
     * `ConnectionVisibilityRules::displayTabContentForItem()`) quanto de um
     * item existente (já salvo na própria linha).
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);
        echo "</td></tr><tr><td colspan='4'>";

        $connectionsId = (int)$this->fields['connections_id'];
        $ruleId = (int)($this->fields['id'] ?? 0);
        echo "<input type='hidden' name='connections_id' value='{$connectionsId}'>";

        self::openFieldsRow();
        self::openField('name', __('Nome'), 'analyticdesign_rule_name', 'col-12 col-sm-6');
        echo Html::input('name', ['id' => 'analyticdesign_rule_name', 'value' => $this->fields['name'] ?? '']);
        echo "<div class='form-text text-muted'>" . __('Ex.: Suporte N1 vê o dashboard de Chamados', 'analyticdesign') . "</div>";
        self::closeField();

        self::openField('is_active', __('Status'), 'dropdown_is_active1', 'col-12 col-sm-3');
        Dropdown::showYesNo('is_active', (int)($this->fields['is_active'] ?? 1), -1, ['rand' => 1]);
        self::closeField();

        self::openField('match', __('Combinar critérios com', 'analyticdesign'), 'dropdown_match2', 'col-12 col-sm-3');
        Dropdown::showFromArray('match', [
            'AND' => __('E (todos os critérios)', 'analyticdesign'),
            'OR'  => __('OU (qualquer critério)', 'analyticdesign'),
        ], ['value' => $this->fields['match'] ?? 'AND', 'rand' => 2]);
        self::closeField();
        self::closeFieldsRow();

        echo "<div class='row mt-4'>";
        echo "<div class='col-12 col-lg-6'>";
        echo "<h3>" . __('Critérios', 'analyticdesign') . "</h3>";
        echo "<p class='text-muted'>" . __('Quais dashboards esta regra alcança. Linhas em branco (sem valor) são ignoradas.', 'analyticdesign') . "</p>";
        self::showCriteriaRows($ruleId);
        echo "</div>";

        echo "<div class='col-12 col-lg-6'>";
        echo "<h3>" . __('Ação', 'analyticdesign') . "</h3>";
        echo "<p class='text-muted'>" . __('Quem ganha acesso quando os critérios ao lado forem satisfeitos.', 'analyticdesign') . "</p>";
        self::showActionField($ruleId);
        echo "</div>";
        echo "</div>";

        echo "</td></tr>";
        $this->showFormButtons($options);

        return true;
    }

    /** @param int $ruleId 0 para um item novo (linhas em branco). */
    private static function showCriteriaRows(int $ruleId): void
    {
        $existing = $ruleId > 0 ? self::getCriteria($ruleId) : [];
        $fieldOptions = [0 => Dropdown::EMPTY_VALUE] + self::fieldLabels();
        $conditionLabels = self::conditionLabels();

        for ($i = 0; $i < self::CRITERIA_ROWS; $i++) {
            $row = $existing[$i] ?? ['field' => '', 'condition' => 'equals', 'value' => ''];
            echo "<div class='d-flex gap-2 mb-2'>";

            echo "<div style='flex:2'>";
            Dropdown::showFromArray("criteria[{$i}][field]", $fieldOptions, [
                'value'               => $row['field'] !== '' ? $row['field'] : 0,
                'display_emptychoice' => false,
            ]);
            echo "</div>";

            echo "<div style='flex:1'>";
            Dropdown::showFromArray("criteria[{$i}][condition]", $conditionLabels, ['value' => $row['condition']]);
            echo "</div>";

            echo "<div style='flex:2'>";
            echo Html::input("criteria[{$i}][value]", [
                'value'       => $row['value'],
                'placeholder' => __('Valor', 'analyticdesign'),
            ]);
            echo "</div>";

            echo "</div>";
        }
    }

    /** @param int $ruleId 0 para um item novo (nada selecionado ainda). */
    private static function showActionField(int $ruleId): void
    {
        $currentRights = $ruleId > 0 ? self::getActions($ruleId) : array_fill_keys(ItemVisibility::TARGET_TYPES, []);
        $dropdownValues = [];
        foreach ($currentRights as $itemtype => $ids) {
            if (!empty($ids)) {
                $dropdownValues[$itemtype::getForeignKeyField()] = $ids;
            }
        }
        echo VisibilityDropdown::show('visibility', $dropdownValues);
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_analyticdesign_visibilityrules';
    }

    private static function criteriaTable(): string
    {
        return 'glpi_plugin_analyticdesign_visibilityrules_criteria';
    }

    private static function actionsTable(): string
    {
        return 'glpi_plugin_analyticdesign_visibilityrules_actions';
    }

    /** @return self[] todas as regras (ativas ou não) de uma Connection. */
    public static function getForConnection(int $connectionsId): array
    {
        global $DB;
        $rules = [];
        $it = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['connections_id' => $connectionsId],
            'ORDER' => ['name'],
        ]);
        foreach ($it as $row) {
            $rule = new self();
            $rule->fields = $row;
            $rules[] = $rule;
        }
        return $rules;
    }

    /** @return self[] só as ativas — usadas na avaliação de visibilidade. */
    private static function getActiveForConnection(int $connectionsId): array
    {
        global $DB;
        $rules = [];
        $it = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['connections_id' => $connectionsId, 'is_active' => 1],
        ]);
        foreach ($it as $row) {
            $rule = new self();
            $rule->fields = $row;
            $rules[] = $rule;
        }
        return $rules;
    }

    /** @return array<int, array{field:string, condition:string, value:string}> */
    public static function getCriteria(int $ruleId): array
    {
        global $DB;
        $rows = [];
        $it = $DB->request([
            'FROM'  => self::criteriaTable(),
            'WHERE' => ['plugin_analyticdesign_visibilityrules_id' => $ruleId],
            'ORDER' => ['id'],
        ]);
        foreach ($it as $row) {
            $rows[] = ['field' => $row['field'], 'condition' => $row['condition'], 'value' => $row['value']];
        }
        return $rows;
    }

    /** @param array<int, array{field?:string, condition?:string, value?:string}> $rows */
    public static function saveCriteria(int $ruleId, array $rows): void
    {
        global $DB;
        $DB->delete(self::criteriaTable(), ['plugin_analyticdesign_visibilityrules_id' => $ruleId]);
        foreach ($rows as $row) {
            $field = (string)($row['field'] ?? '');
            $value = trim((string)($row['value'] ?? ''));
            if ($value === '' || !in_array($field, self::FIELD_KEYS, true)) {
                continue;
            }
            $condition = in_array($row['condition'] ?? '', self::CONDITION_KEYS, true) ? $row['condition'] : 'equals';
            $DB->insert(self::criteriaTable(), [
                'plugin_analyticdesign_visibilityrules_id' => $ruleId,
                'field'     => $field,
                'condition' => $condition,
                'value'     => $value,
            ]);
        }
    }

    /** @return array<class-string, int[]> mesmo formato de ItemVisibility::getForItem(). */
    public static function getActions(int $ruleId): array
    {
        global $DB;
        $rights = array_fill_keys(ItemVisibility::TARGET_TYPES, []);
        $it = $DB->request([
            'FROM'  => self::actionsTable(),
            'WHERE' => ['plugin_analyticdesign_visibilityrules_id' => $ruleId],
        ]);
        foreach ($it as $row) {
            if (isset($rights[$row['itemtype']])) {
                $rights[$row['itemtype']][] = (int)$row['items_id'];
            }
        }
        return $rights;
    }

    /** @param array<class-string, int[]> $rightsByItemtype */
    public static function saveActions(int $ruleId, array $rightsByItemtype): void
    {
        global $DB;
        $DB->delete(self::actionsTable(), ['plugin_analyticdesign_visibilityrules_id' => $ruleId]);
        foreach ($rightsByItemtype as $itemtype => $ids) {
            if (!in_array($itemtype, ItemVisibility::TARGET_TYPES, true)) {
                continue;
            }
            foreach (array_unique(array_filter($ids, static fn ($v) => $v !== null && $v !== '')) as $targetId) {
                $DB->insert(self::actionsTable(), [
                    'plugin_analyticdesign_visibilityrules_id' => $ruleId,
                    'itemtype' => $itemtype,
                    'items_id' => (int)$targetId,
                ]);
            }
        }
    }

    /**
     * Este item (dashboard) casa com os critérios da regra? Uma regra sem
     * nenhum critério salvo nunca casa com nada (nega por padrão) — evita
     * uma regra "vazia" acidentalmente valer para todo mundo.
     */
    public function matchesItem(DashboardItem $item): bool
    {
        $criteria = self::getCriteria((int)$this->fields['id']);
        if (empty($criteria)) {
            return false;
        }

        $isOr = ($this->fields['match'] ?? 'AND') === 'OR';
        foreach ($criteria as $criterion) {
            $itemValue = (string)($item->fields[$criterion['field']] ?? '');
            $ruleValue = (string)$criterion['value'];
            $rowMatches = $criterion['condition'] === 'contains'
                ? mb_stripos($itemValue, $ruleValue) !== false
                : mb_strtolower($itemValue) === mb_strtolower($ruleValue);

            if ($isOr && $rowMatches) {
                return true;
            }
            if (!$isOr && !$rowMatches) {
                return false;
            }
        }
        // AND: nenhuma linha reprovou -> casou. OR: nenhuma aprovou -> não casou.
        return !$isOr;
    }

    /**
     * O usuário logado casa com pelo menos uma ação (Perfil/Grupo/Usuário/
     * Entidade) desta regra? Mesma lógica de OR entre alvos que
     * `ItemVisibility::isVisibleForCurrentUser()` já usa.
     */
    public function grantsCurrentUser(): bool
    {
        $rights = self::getActions((int)$this->fields['id']);
        return in_array((int)($_SESSION['glpiactiveprofile']['id'] ?? 0), $rights[Profile::class], true)
            || in_array((int)($_SESSION['glpiID'] ?? 0), $rights[User::class], true)
            || count(array_intersect($rights[Group::class], $_SESSION['glpigroups'] ?? [])) > 0
            || Session::haveAccessToOneOfEntities($rights[Entity::class], true);
    }

    /**
     * Ponto único usado por DashboardItem::isVisibleForCurrentUser(): alguma
     * regra ATIVA da Connection dona deste item casa com ele E concede
     * acesso ao usuário atual?
     */
    public static function isVisibleForCurrentUserViaRules(DashboardItem $item): bool
    {
        if (!Session::getLoginUserID()) {
            return false;
        }

        $connectionsId = (int)($item->fields['connections_id'] ?? 0);
        foreach (self::getActiveForConnection($connectionsId) as $rule) {
            if ($rule->matchesItem($item) && $rule->grantsCurrentUser()) {
                return true;
            }
        }
        return false;
    }

    /** Resumo legível dos critérios, para a listagem (ex.: "Dashboard (nome) é 'X'"). */
    public static function summarizeCriteria(int $ruleId, string $match): string
    {
        $rows = self::getCriteria($ruleId);
        if (empty($rows)) {
            return __('(nenhum critério — nunca casa)', 'analyticdesign');
        }
        $glue = $match === 'OR' ? ' ' . __('ou', 'analyticdesign') . ' ' : ' ' . __('e', 'analyticdesign') . ' ';
        $fieldLabels = self::fieldLabels();
        $conditionLabels = self::conditionLabels();
        $parts = array_map(
            static fn (array $row) => ($fieldLabels[$row['field']] ?? $row['field'])
                . ' ' . ($conditionLabels[$row['condition']] ?? $row['condition'])
                . " \"{$row['value']}\"",
            $rows
        );
        return implode($glue, $parts);
    }

    /** Resumo legível das ações, para a listagem (ex.: "Grupo: Suporte N1 ou Entidade: Cliente X"). */
    public static function summarizeActions(int $ruleId): string
    {
        $rights = self::getActions($ruleId);
        $parts = [];
        foreach ($rights as $itemtype => $ids) {
            foreach ($ids as $id) {
                $parts[] = $itemtype::getTypeName(1) . ': ' . Dropdown::getDropdownName($itemtype::getTable(), $id);
            }
        }
        return empty($parts) ? __('(nenhuma ação)', 'analyticdesign') : implode(' ' . __('ou', 'analyticdesign') . ' ', $parts);
    }

    public function prepareInputForAdd($input)
    {
        if (empty($input['name'])) {
            $input['name'] = __('Regra sem nome', 'analyticdesign');
        }
        if (!in_array($input['match'] ?? '', ['AND', 'OR'], true)) {
            $input['match'] = 'AND';
        }
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['match']) && !in_array($input['match'], ['AND', 'OR'], true)) {
            $input['match'] = 'AND';
        }
        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->saveCriteriaAndActionsFromInput();
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->saveCriteriaAndActionsFromInput();
    }

    /**
     * Critérios e ação são tabelas filhas próprias (não colunas de
     * `glpi_plugin_analyticdesign_visibilityrules`) — persistidos aqui, à
     * parte do add()/update() padrão, igual ao mesmo padrão já usado por
     * DashboardItem::saveVisibilityFromInput().
     */
    private function saveCriteriaAndActionsFromInput(): void
    {
        $ruleId = (int)$this->fields['id'];

        if (array_key_exists('criteria', $this->input)) {
            self::saveCriteria($ruleId, (array)$this->input['criteria']);
        }

        $posted = $this->input['visibility'] ?? [];
        $rights = [];
        foreach (ItemVisibility::TARGET_TYPES as $itemtype) {
            $rights[$itemtype] = VisibilityDropdown::getPostedIds($posted, $itemtype);
        }
        self::saveActions($ruleId, $rights);
    }

    public function post_purgeItem()
    {
        parent::post_purgeItem();
        global $DB;
        $DB->delete(self::criteriaTable(), ['plugin_analyticdesign_visibilityrules_id' => (int)$this->fields['id']]);
        $DB->delete(self::actionsTable(), ['plugin_analyticdesign_visibilityrules_id' => (int)$this->fields['id']]);
    }

    public static function install(): void
    {
        global $DB;

        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $DB->doQuery("
                CREATE TABLE `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `connections_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `name` VARCHAR(255) NOT NULL DEFAULT '',
                    `match` VARCHAR(10) NOT NULL DEFAULT 'AND',
                    `is_active` TINYINT NOT NULL DEFAULT 1,
                    `date_creation` TIMESTAMP NULL DEFAULT NULL,
                    `date_mod` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `connections_id` (`connections_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $criteriaTable = self::criteriaTable();
        if (!$DB->tableExists($criteriaTable)) {
            $DB->doQuery("
                CREATE TABLE `{$criteriaTable}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `plugin_analyticdesign_visibilityrules_id` INT UNSIGNED NOT NULL,
                    `field` VARCHAR(50) NOT NULL,
                    `condition` VARCHAR(20) NOT NULL DEFAULT 'equals',
                    `value` VARCHAR(255) NOT NULL DEFAULT '',
                    PRIMARY KEY (`id`),
                    KEY `plugin_analyticdesign_visibilityrules_id` (`plugin_analyticdesign_visibilityrules_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $actionsTable = self::actionsTable();
        if (!$DB->tableExists($actionsTable)) {
            $DB->doQuery("
                CREATE TABLE `{$actionsTable}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `plugin_analyticdesign_visibilityrules_id` INT UNSIGNED NOT NULL,
                    `itemtype` VARCHAR(100) NOT NULL,
                    `items_id` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `plugin_analyticdesign_visibilityrules_id` (`plugin_analyticdesign_visibilityrules_id`),
                    KEY `item` (`itemtype`, `items_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public static function uninstall(): void
    {
        global $DB;
        foreach ([self::criteriaTable(), self::actionsTable(), self::getTable()] as $table) {
            if ($DB->tableExists($table)) {
                $DB->doQuery("DROP TABLE `{$table}`");
            }
        }
    }
}
