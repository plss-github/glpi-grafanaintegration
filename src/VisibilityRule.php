<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Regra de visibilidade baseada em critério/ação: de um lado **Critérios**
 * (quais dashboards a regra alcança, combinados por E/OU), do outro **Ação**
 * (quem ganha acesso) — mesmo modelo conceitual das Regras de negócio nativas
 * do GLPI, mas uma implementação própria e simples do plugin (não uma
 * subclasse de `Rule`/`RuleCollection` do core — decisão explícita para
 * manter esta primeira versão pequena e totalmente sob controle do plugin).
 *
 * Escopada por Connection (uma regra vale só para os dashboards daquela
 * fonte) — vira uma aba no formulário da Connection (ver
 * ConnectionVisibilityRules), renderizada **inteiramente dentro da própria
 * aba** (lista de regras + tabela de Critérios/Ações com uma linha de
 * "adicionar" no rodapé, ao estilo das Regras de negócio do GLPI) — sem
 * página própria: front/visibilityrule.form.php só processa os POSTs
 * (adicionar/remover regra, critério ou ação) e redireciona de volta para a
 * mesma aba via `forcetab`.
 *
 * Sem campo "Nome" nem "Status" por regra: uma regra é identificada pelos
 * próprios Critérios (que já é sempre sobre "qual Dashboard"), e existir já
 * significa estar ativa — remover a regra é a forma de "desativá-la".
 *
 * Uma regra sem nenhum Critério nunca casa com nada (nega por padrão).
 *
 * **`is_private` de um DashboardItem agora é somente calculado**, nunca mais
 * digitado em formulário: sempre que uma regra (ou seus Critérios/Ações)
 * muda, `resyncAffectedItems()` recalcula `is_private` de cada item da
 * Connection (1 = pelo menos uma regra casa com o item) — ver
 * DashboardItem::isVisibleForCurrentUser(). Um item sem nenhuma regra
 * apontando pra ele fica público (visível a quem já tem o direito de leitura
 * do módulo); uma regra com Ação "Todos os usuários" (`GRANT_ALL`) também
 * libera geral, mas por dentro da própria regra (útil para "restrinja
 * Critérios sem restringir quem vê").
 */

namespace GlpiPlugin\Plugingrafanaintegration;

use CommonDBTM;
use Dropdown;
use Entity;
use GlpiPlugin\Plugingrafanaintegration\Traits\HasTimestampMigration;
use Group;
use Html;
use Profile;
use Session;
use User;

class VisibilityRule extends CommonDBTM
{
    use HasTimestampMigration;

    public static $rightname = Connection::RIGHTNAME;

    /**
     * Itemtypes suportados como alvo "enumerável" de uma Ação (além do alvo
     * especial GRANT_ALL) — única fonte de verdade (era `ItemVisibility::
     * TARGET_TYPES`, retirada junto com o resto daquela classe quando a
     * visibilidade por card foi substituída por regras).
     */
    public const TARGET_TYPES = [Profile::class, Group::class, User::class, Entity::class];

    /**
     * Chaves válidas de campo/condição — usadas para validar o que vem do
     * POST. Os RÓTULOS (traduzíveis) ficam em métodos (fieldLabels()/
     * conditionLabels()), não aqui: uma constante de classe não pode chamar
     * __() (não é uma expressão constante em tempo de compilação).
     */
    public const FIELD_KEYS = ['name', 'category'];
    public const CONDITION_KEYS = ['equals', 'contains'];

    /**
     * "Alvo" especial de Ação — concede acesso a todo mundo que já tem o
     * direito de leitura do módulo, sem precisar listar Perfil/Grupo/
     * Usuário/Entidade um por um. Guardado como uma linha comum na tabela de
     * ações (`itemtype='All'`, `items_id=0`) — nunca colide com um itemtype
     * de verdade (todos são nomes de classe PHP reais).
     */
    public const GRANT_ALL = 'All';

    /** @return array<class-string|self::GRANT_ALL, int[]> alvos possíveis de uma Ação, vazios. */
    private static function emptyActionRights(): array
    {
        return array_fill_keys(array_merge(self::TARGET_TYPES, [self::GRANT_ALL]), []);
    }

    /** @return array<string, string> campo => rótulo, dos critérios possíveis. */
    public static function fieldLabels(): array
    {
        return [
            'name'     => __('Dashboard', 'analyticdesign'),
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

    /** @return array<class-string|self::GRANT_ALL, string> alvo de ação => rótulo. */
    public static function actionTypeLabels(): array
    {
        return [
            Profile::class => __('Perfil', 'analyticdesign'),
            Group::class   => __('Grupo', 'analyticdesign'),
            User::class    => __('Usuário', 'analyticdesign'),
            Entity::class  => __('Entidade', 'analyticdesign'),
            self::GRANT_ALL => __('Todos os usuários', 'analyticdesign'),
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

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_plugingrafanaintegration_visibilityrules';
    }

    public static function criteriaTable(): string
    {
        return 'glpi_plugin_plugingrafanaintegration_visibilityrules_criteria';
    }

    public static function actionsTable(): string
    {
        return 'glpi_plugin_plugingrafanaintegration_visibilityrules_actions';
    }

    /** @return self[] todas as regras de uma Connection. */
    public static function getForConnection(int $connectionsId): array
    {
        global $DB;
        $rules = [];
        $it = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['connections_id' => $connectionsId],
            'ORDER' => ['id'],
        ]);
        foreach ($it as $row) {
            $rule = new self();
            $rule->fields = $row;
            $rules[] = $rule;
        }
        return $rules;
    }

    /** @return array<int, array{id:int, field:string, condition:string, value:string}> */
    public static function getCriteria(int $ruleId): array
    {
        global $DB;
        $rows = [];
        $it = $DB->request([
            'FROM'  => self::criteriaTable(),
            'WHERE' => ['plugin_plugingrafanaintegration_visibilityrules_id' => $ruleId],
            'ORDER' => ['id'],
        ]);
        foreach ($it as $row) {
            $rows[] = [
                'id'        => (int)$row['id'],
                'field'     => $row['field'],
                'condition' => $row['condition'],
                'value'     => $row['value'],
            ];
        }
        return $rows;
    }

    /** Adiciona UM critério — ver docblock da classe (lista + linha de adicionar, não um formulário único). */
    public static function addCriterion(int $ruleId, string $field, string $condition, string $value): void
    {
        $value = trim($value);
        if ($value === '' || !in_array($field, self::FIELD_KEYS, true)) {
            return;
        }
        $condition = in_array($condition, self::CONDITION_KEYS, true) ? $condition : 'equals';

        global $DB;
        $DB->insert(self::criteriaTable(), [
            'plugin_plugingrafanaintegration_visibilityrules_id' => $ruleId,
            'field'     => $field,
            'condition' => $condition,
            'value'     => $value,
        ]);
    }

    public static function deleteCriterion(int $criterionId, int $ruleId): void
    {
        global $DB;
        $DB->delete(self::criteriaTable(), ['id' => $criterionId, 'plugin_plugingrafanaintegration_visibilityrules_id' => $ruleId]);
    }

    /** @return array<int, array{id:int, itemtype:string, items_id:int}> linhas cruas — usado pra renderizar a tabela de Ações. */
    public static function getActionRows(int $ruleId): array
    {
        global $DB;
        $rows = [];
        $it = $DB->request([
            'FROM'  => self::actionsTable(),
            'WHERE' => ['plugin_plugingrafanaintegration_visibilityrules_id' => $ruleId],
            'ORDER' => ['id'],
        ]);
        foreach ($it as $row) {
            $rows[] = ['id' => (int)$row['id'], 'itemtype' => $row['itemtype'], 'items_id' => (int)$row['items_id']];
        }
        return $rows;
    }

    /** @return array<class-string|self::GRANT_ALL, int[]> agrupado por alvo — usado na avaliação (grantsCurrentUser()) e no resumo. */
    public static function getActions(int $ruleId): array
    {
        $rights = self::emptyActionRights();
        foreach (self::getActionRows($ruleId) as $row) {
            if (isset($rights[$row['itemtype']])) {
                $rights[$row['itemtype']][] = $row['items_id'];
            }
        }
        return $rights;
    }

    /** Adiciona UMA ação — ver docblock da classe. `itemsId` é ignorado (gravado como 0) para GRANT_ALL. */
    public static function addAction(int $ruleId, string $itemtype, int $itemsId): void
    {
        if (!isset(self::emptyActionRights()[$itemtype])) {
            return;
        }
        if ($itemtype === self::GRANT_ALL) {
            $itemsId = 0;
        } elseif ($itemtype !== Entity::class && $itemsId <= 0) {
            // Entidade 0 = raiz, um alvo válido; os demais exigem um ID real selecionado.
            return;
        }

        global $DB;
        $DB->insert(self::actionsTable(), [
            'plugin_plugingrafanaintegration_visibilityrules_id' => $ruleId,
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
        ]);
    }

    public static function deleteAction(int $actionId, int $ruleId): void
    {
        global $DB;
        $DB->delete(self::actionsTable(), ['id' => $actionId, 'plugin_plugingrafanaintegration_visibilityrules_id' => $ruleId]);
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
     * O usuário logado casa com pelo menos uma ação (Todos, ou Perfil/Grupo/
     * Usuário/Entidade) desta regra? OR entre todos os alvos configurados —
     * basta casar com um deles.
     */
    public function grantsCurrentUser(): bool
    {
        $rights = self::getActions((int)$this->fields['id']);
        if (!empty($rights[self::GRANT_ALL])) {
            return true;
        }
        return in_array((int)($_SESSION['glpiactiveprofile']['id'] ?? 0), $rights[Profile::class], true)
            || in_array((int)($_SESSION['glpiID'] ?? 0), $rights[User::class], true)
            || count(array_intersect($rights[Group::class], $_SESSION['glpigroups'] ?? [])) > 0
            || Session::haveAccessToOneOfEntities($rights[Entity::class], true);
    }

    /** @return self[] regras da Connection dona do item que casam com ele (Critérios). */
    private static function matchingRulesForItem(DashboardItem $item): array
    {
        $connectionsId = (int)($item->fields['connections_id'] ?? 0);
        $matches = [];
        foreach (self::getForConnection($connectionsId) as $rule) {
            if ($rule->matchesItem($item)) {
                $matches[] = $rule;
            }
        }
        return $matches;
    }

    /**
     * Ponto único usado por DashboardItem::isVisibleForCurrentUser(): alguma
     * regra da Connection dona deste item casa com ele E concede acesso ao
     * usuário atual?
     */
    public static function isVisibleForCurrentUserViaRules(DashboardItem $item): bool
    {
        if (!Session::getLoginUserID()) {
            return false;
        }

        foreach (self::matchingRulesForItem($item) as $rule) {
            if ($rule->grantsCurrentUser()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Alguma regra que casa com este item concede um alvo ENUMERÁVEL
     * (Perfil/Grupo/Usuário/Entidade — não "Todos")? Pré-requisito de
     * "Substituir dashboard do módulo" (ver DashboardItem::
     * validateModuleReplacement()): "todos com acesso ao módulo" (seja por
     * não ter regra nenhuma, seja por uma regra com Ação "Todos") não é um
     * conjunto enumerável de compartilhamento nativo do GLPI.
     */
    public static function hasConcreteGrantForItem(DashboardItem $item): bool
    {
        foreach (self::matchingRulesForItem($item) as $rule) {
            $rights = self::getActions((int)$rule->fields['id']);
            foreach (self::TARGET_TYPES as $itemtype) {
                if (!empty($rights[$itemtype])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<class-string, int[]> união dos alvos enumeráveis de todas as regras que casam com o item — usado por ModuleDashboard::syncNativeDashboard(). */
    public static function getConcreteGrantsForItem(DashboardItem $item): array
    {
        $result = array_fill_keys(self::TARGET_TYPES, []);
        foreach (self::matchingRulesForItem($item) as $rule) {
            $rights = self::getActions((int)$rule->fields['id']);
            foreach (self::TARGET_TYPES as $itemtype) {
                $result[$itemtype] = array_values(array_unique(array_merge($result[$itemtype], $rights[$itemtype])));
            }
        }
        return $result;
    }

    /**
     * Recalcula `is_private` de TODOS os itens da Connection (1 = pelo menos
     * uma regra casa com o item) e re-sincroniza o dashboard nativo
     * espelhado de quem usa "Substituir dashboard do módulo" — chamado
     * sempre que uma regra (ou seus Critérios/Ações) muda. Escrita direta via
     * query builder (não `CommonDBTM::update()`): evita disparar hooks
     * não relacionados (ex.: `post_updateItem()` de DashboardItem) para uma
     * atualização que é puramente derivada, não uma edição de verdade feita
     * pelo usuário.
     */
    public static function resyncAffectedItems(int $connectionsId): void
    {
        global $DB;
        foreach (DashboardItem::getForConnection($connectionsId) as $item) {
            $hasConcreteGrant = self::hasConcreteGrantForItem($item);
            $isPrivate = !empty(self::matchingRulesForItem($item)) ? 1 : 0;

            $update = ['is_private' => $isPrivate];
            if ((string)$item->fields['replaces_module'] !== '' && !$hasConcreteGrant) {
                $update['replaces_module'] = '';
            }
            $DB->update(DashboardItem::getTable(), $update, ['id' => (int)$item->fields['id']]);

            $item->fields['is_private'] = $isPrivate;
            $item->fields['replaces_module'] = $update['replaces_module'] ?? $item->fields['replaces_module'];
            if ($item->fields['replaces_module'] !== '') {
                ModuleDashboard::syncNativeDashboard($item);
            } else {
                ModuleDashboard::deleteNativeDashboard((int)$item->fields['id']);
            }
        }
    }

    /**
     * Renderiza UMA regra inteira (E/OU + Critérios | Ação), inline dentro da
     * aba "Visibilidade" da Connection — ver docblock da classe. Tudo posta
     * para `front/visibilityrule.form.php`, que redireciona de volta para a
     * mesma aba (`forcetab`) — nunca uma página própria.
     */
    public static function showRuleBlock(self $rule, string $formUrl, bool $canEdit): void
    {
        $ruleId = (int)$rule->fields['id'];

        echo "<div class='analyticdesign-visibility-rule card mb-3'>";

        echo "<div class='card-header d-flex justify-content-between align-items-center flex-wrap gap-2'>";
        echo "<span class='card-title mb-0 d-flex align-items-center gap-2'><i class='ti ti-shield-check'></i> "
            . __('Regra', 'analyticdesign') . "</span>";

        if ($canEdit) {
            echo "<div class='d-flex align-items-center flex-wrap gap-3'>";

            echo "<form method='post' action='" . htmlspecialchars($formUrl, ENT_QUOTES) . "' class='d-flex align-items-center gap-2 mb-0'>";
            echo "<input type='hidden' name='action' value='update_match'>";
            echo "<input type='hidden' name='id' value='{$ruleId}'>";
            echo "<label class='mb-0 text-nowrap'>" . __('Combinar critérios com', 'analyticdesign') . "</label>";
            Dropdown::showFromArray('match', [
                'AND' => __('E (todos)', 'analyticdesign'),
                'OR'  => __('OU (qualquer um)', 'analyticdesign'),
            ], [
                'value'     => $rule->fields['match'] ?? 'AND',
                'rand'      => $ruleId,
                'width'     => '160px',
                'on_change' => 'this.form.submit()',
            ]);
            Html::closeForm();

            echo "<form method='post' action='" . htmlspecialchars($formUrl, ENT_QUOTES) . "' class='mb-0'"
                . " onsubmit=\"return confirm('" . htmlspecialchars(__('Remover esta regra? Essa ação não pode ser desfeita.', 'analyticdesign'), ENT_QUOTES) . "');\">";
            echo "<input type='hidden' name='action' value='delete_rule'>";
            echo "<input type='hidden' name='id' value='{$ruleId}'>";
            echo "<button type='submit' class='btn btn-sm btn-outline-danger' title='" . htmlspecialchars(__('Remover regra', 'analyticdesign'), ENT_QUOTES) . "'>"
                . "<i class='ti ti-trash'></i> " . __('Remover regra', 'analyticdesign') . "</button>";
            Html::closeForm();

            echo "</div>";
        } else {
            echo "<span class='badge bg-blue-lt'>" . __('Combinar critérios com', 'analyticdesign') . ": "
                . (($rule->fields['match'] ?? 'AND') === 'OR' ? __('OU', 'analyticdesign') : __('E', 'analyticdesign')) . "</span>";
        }
        echo "</div>"; // .card-header

        echo "<div class='card-body'>";
        echo "<div class='row g-3'>";

        echo "<div class='col-12 col-lg-6'>";
        echo "<div class='analyticdesign-rule-section h-100'>";
        echo "<h4 class='d-flex align-items-center gap-2'><i class='ti ti-filter text-blue'></i> " . __('Critérios', 'analyticdesign') . "</h4>";
        self::showCriteriaSection($rule, $formUrl, $canEdit);
        echo "</div>";
        echo "</div>";

        echo "<div class='col-12 col-lg-6'>";
        echo "<div class='analyticdesign-rule-section h-100'>";
        echo "<h4 class='d-flex align-items-center gap-2'><i class='ti ti-users text-green'></i> " . __('Ação', 'analyticdesign') . "</h4>";
        self::showActionsSection($rule, $formUrl, $canEdit);
        echo "</div>";
        echo "</div>";

        echo "</div>"; // .row
        echo "</div>"; // .card-body
        echo "</div>"; // .analyticdesign-visibility-rule
    }

    /** Tabela dos Critérios já salvos + linha de "adicionar" (Campo/Condição/Valor dinâmico) — ver docblock da classe. */
    private static function showCriteriaSection(self $rule, string $formUrl, bool $canEdit): void
    {
        $ruleId = (int)$rule->fields['id'];
        $fieldLabels = self::fieldLabels();
        $conditionLabels = self::conditionLabels();
        $criteria = self::getCriteria($ruleId);

        if (empty($criteria)) {
            echo "<p class='text-muted'>" . __('(nenhum critério — nunca casa)', 'analyticdesign') . "</p>";
        } else {
            echo "<table class='table table-sm table-vcenter mb-0'><tr>";
            echo "<th>" . __('Campo', 'analyticdesign') . "</th>";
            echo "<th>" . __('Condição', 'analyticdesign') . "</th>";
            echo "<th>" . __('Valor', 'analyticdesign') . "</th>";
            if ($canEdit) {
                echo "<th class='text-end'></th>";
            }
            echo "</tr>";
            foreach ($criteria as $criterion) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($fieldLabels[$criterion['field']] ?? $criterion['field'], ENT_QUOTES) . "</td>";
                echo "<td>" . htmlspecialchars($conditionLabels[$criterion['condition']] ?? $criterion['condition'], ENT_QUOTES) . "</td>";
                echo "<td><strong>" . htmlspecialchars($criterion['value'], ENT_QUOTES) . "</strong></td>";
                if ($canEdit) {
                    echo "<td class='text-end'>";
                    echo "<form method='post' action='" . htmlspecialchars($formUrl, ENT_QUOTES) . "' class='d-inline'"
                        . " onsubmit=\"return confirm('" . htmlspecialchars(__('Remover este critério?', 'analyticdesign'), ENT_QUOTES) . "');\">";
                    echo "<input type='hidden' name='action' value='delete_criterion'>";
                    echo "<input type='hidden' name='rule_id' value='{$ruleId}'>";
                    echo "<input type='hidden' name='criterion_id' value='{$criterion['id']}'>";
                    echo "<button type='submit' class='btn btn-sm btn-icon btn-ghost-danger' title='" . htmlspecialchars(__('Remover este critério?', 'analyticdesign'), ENT_QUOTES) . "'><i class='ti ti-x'></i></button>";
                    Html::closeForm();
                    echo "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        }

        if (!$canEdit) {
            return;
        }

        $connectionsId = (int)$rule->fields['connections_id'];
        $dashboardOptions = [];
        foreach (DashboardItem::getForConnection($connectionsId) as $item) {
            $dashboardOptions[(int)$item->fields['id']] = $item->fields['name'];
        }

        echo "<form method='post' action='" . htmlspecialchars($formUrl, ENT_QUOTES) . "' class='analyticdesign-add-criterion analyticdesign-add-row'>";
        echo "<input type='hidden' name='action' value='add_criterion'>";
        echo "<input type='hidden' name='rule_id' value='{$ruleId}'>";

        echo "<div class='analyticdesign-field'><label class='form-label mb-0'>" . __('Campo', 'analyticdesign') . "</label>";
        Dropdown::showFromArray('field', $fieldLabels, [
            'value' => 'name',
            'rand'  => $ruleId,
            'width' => '100%',
            'class' => 'form-select form-select-sm analyticdesign-criterion-field',
        ]);
        echo "</div>";

        echo "<div class='analyticdesign-field'><label class='form-label mb-0'>" . __('Condição', 'analyticdesign') . "</label>";
        Dropdown::showFromArray('condition', $conditionLabels, [
            'value' => 'equals',
            'rand'  => $ruleId,
            'width' => '100%',
            'class' => 'form-select form-select-sm analyticdesign-criterion-condition',
        ]);
        echo "</div>";

        echo "<div class='analyticdesign-field analyticdesign-field-value analyticdesign-criterion-value-dashboard'><label class='form-label mb-0'>" . __('Valor', 'analyticdesign') . "</label>";
        if (empty($dashboardOptions)) {
            echo "<div class='form-text text-muted'>" . __('Nenhum dashboard importado ainda.', 'analyticdesign') . "</div>";
        } else {
            Dropdown::showFromArray('value_dashboard', $dashboardOptions, [
                'rand'                => $ruleId,
                'width'               => '100%',
                'display_emptychoice' => true,
                'class'               => 'form-select form-select-sm',
            ]);
        }
        echo "</div>";

        echo "<div class='analyticdesign-field analyticdesign-field-value analyticdesign-criterion-value-module' style='display:none'><label class='form-label mb-0'>" . __('Valor', 'analyticdesign') . "</label>";
        Dropdown::showFromArray('value_module', ModuleDashboard::MODULES, [
            'rand'                => $ruleId,
            'width'               => '100%',
            'display_emptychoice' => true,
            'class'               => 'form-select form-select-sm',
        ]);
        echo "</div>";

        echo "<div class='analyticdesign-field analyticdesign-field-value analyticdesign-criterion-value-text' style='display:none'><label class='form-label mb-0'>" . __('Valor', 'analyticdesign') . "</label>";
        echo "<input type='text' name='value_text' class='form-control form-control-sm'></div>";

        echo "<button type='submit' class='btn btn-sm btn-primary'><i class='ti ti-plus'></i> " . __('Adicionar', 'analyticdesign') . "</button>";
        Html::closeForm();
    }

    /** Tabela das Ações já salvas + linha de "adicionar" (Alvo/Valor dinâmico) — ver docblock da classe. */
    private static function showActionsSection(self $rule, string $formUrl, bool $canEdit): void
    {
        $ruleId = (int)$rule->fields['id'];
        $actionTypeLabels = self::actionTypeLabels();
        $rows = self::getActionRows($ruleId);

        if (empty($rows)) {
            echo "<p class='text-muted'>" . __('(nenhuma ação)', 'analyticdesign') . "</p>";
        } else {
            echo "<table class='table table-sm table-vcenter mb-0'><tr>";
            echo "<th>" . __('Conceder acesso a', 'analyticdesign') . "</th>";
            echo "<th>" . __('Valor', 'analyticdesign') . "</th>";
            if ($canEdit) {
                echo "<th class='text-end'></th>";
            }
            echo "</tr>";
            foreach ($rows as $row) {
                $itemtype = $row['itemtype'];
                $label = $actionTypeLabels[$itemtype] ?? $itemtype;
                $value = $itemtype === self::GRANT_ALL ? '-' : Dropdown::getDropdownName($itemtype::getTable(), $row['items_id']);
                echo "<tr>";
                echo "<td>" . htmlspecialchars($label, ENT_QUOTES) . "</td>";
                echo "<td><strong>" . htmlspecialchars($value, ENT_QUOTES) . "</strong></td>";
                if ($canEdit) {
                    echo "<td class='text-end'>";
                    echo "<form method='post' action='" . htmlspecialchars($formUrl, ENT_QUOTES) . "' class='d-inline'"
                        . " onsubmit=\"return confirm('" . htmlspecialchars(__('Remover esta ação?', 'analyticdesign'), ENT_QUOTES) . "');\">";
                    echo "<input type='hidden' name='action' value='delete_action'>";
                    echo "<input type='hidden' name='rule_id' value='{$ruleId}'>";
                    echo "<input type='hidden' name='action_id' value='{$row['id']}'>";
                    echo "<button type='submit' class='btn btn-sm btn-icon btn-ghost-danger' title='" . htmlspecialchars(__('Remover esta ação?', 'analyticdesign'), ENT_QUOTES) . "'><i class='ti ti-x'></i></button>";
                    Html::closeForm();
                    echo "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        }

        if (!$canEdit) {
            return;
        }

        echo "<form method='post' action='" . htmlspecialchars($formUrl, ENT_QUOTES) . "' class='analyticdesign-add-action analyticdesign-add-row'>";
        echo "<input type='hidden' name='action' value='add_action'>";
        echo "<input type='hidden' name='rule_id' value='{$ruleId}'>";

        echo "<div class='analyticdesign-field'><label class='form-label mb-0'>" . __('Conceder acesso a', 'analyticdesign') . "</label>";
        Dropdown::showFromArray('itemtype', $actionTypeLabels, [
            'value' => Profile::class,
            'rand'  => $ruleId,
            'width' => '100%',
            'class' => 'form-select form-select-sm analyticdesign-action-itemtype',
        ]);
        echo "</div>";

        // Só o widget do tipo já selecionado (Perfil, por padrão) é renderizado
        // aqui — os demais são buscados sob demanda via fetch() quando o tipo
        // muda (ver toggleActionValueWidget() em analyticdesign.js e
        // ajax/getvisibilityactionvalue.php). Pré-renderizar os 4 de uma vez
        // (versão anterior) e só trocar a visibilidade via CSS parecia mais
        // simples, mas esbarra num bug real do select2: um combo iniciado
        // dentro de um container `display:none` calcula largura 0 e nunca se
        // recupera sozinho depois — mesmo escondendo/reexibindo o container.
        echo "<div class='analyticdesign-field analyticdesign-field-value'>";
        echo "<label class='form-label mb-0'>" . __('Valor', 'analyticdesign') . "</label>";
        echo "<div class='analyticdesign-action-value-container'>";
        Dropdown::show(Profile::class, ['name' => 'value_item_id', 'width' => '100%']);
        echo "</div>";
        echo "</div>";

        echo "<button type='submit' class='btn btn-sm btn-primary'><i class='ti ti-plus'></i> " . __('Adicionar', 'analyticdesign') . "</button>";
        Html::closeForm();
    }

    /** Resumo legível dos critérios, para a listagem (ex.: "Dashboard é '[TV] Kali'"). */
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
        if (!empty($rights[self::GRANT_ALL])) {
            return __('Todos os usuários', 'analyticdesign');
        }
        $parts = [];
        foreach (self::TARGET_TYPES as $itemtype) {
            foreach ($rights[$itemtype] as $id) {
                $parts[] = $itemtype::getTypeName(1) . ': ' . Dropdown::getDropdownName($itemtype::getTable(), $id);
            }
        }
        return empty($parts) ? __('(nenhuma ação)', 'analyticdesign') : implode(' ' . __('ou', 'analyticdesign') . ' ', $parts);
    }

    public function prepareInputForAdd($input)
    {
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

    public function post_purgeItem()
    {
        parent::post_purgeItem();
        global $DB;
        $DB->delete(self::criteriaTable(), ['plugin_plugingrafanaintegration_visibilityrules_id' => (int)$this->fields['id']]);
        $DB->delete(self::actionsTable(), ['plugin_plugingrafanaintegration_visibilityrules_id' => (int)$this->fields['id']]);
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
                    `match` VARCHAR(10) NOT NULL DEFAULT 'AND',
                    `date_creation` DATETIME NULL DEFAULT NULL,
                    `date_mod` DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `connections_id` (`connections_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        // `name`/`is_active` existiam na 0.8.0 — removidos: a regra passou a
        // não precisar de nome (identificada pelos próprios Critérios) nem de
        // status (existir já é estar ativa) — ver docblock da classe.
        if ($DB->fieldExists($table, 'name')) {
            $DB->doQuery("ALTER TABLE `{$table}` DROP COLUMN `name`");
        }
        if ($DB->fieldExists($table, 'is_active')) {
            $DB->doQuery("ALTER TABLE `{$table}` DROP COLUMN `is_active`");
        }
        // `date_creation`/`date_mod` nasceram como TIMESTAMP — ver
        // HasTimestampMigration e Connection::install() para o motivo.
        self::convertTimestampColumnsToDatetime($table);

        $criteriaTable = self::criteriaTable();
        if (!$DB->tableExists($criteriaTable)) {
            $DB->doQuery("
                CREATE TABLE `{$criteriaTable}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `plugin_plugingrafanaintegration_visibilityrules_id` INT UNSIGNED NOT NULL,
                    `field` VARCHAR(50) NOT NULL,
                    `condition` VARCHAR(20) NOT NULL DEFAULT 'equals',
                    `value` VARCHAR(255) NOT NULL DEFAULT '',
                    PRIMARY KEY (`id`),
                    KEY `plugin_plugingrafanaintegration_visibilityrules_id` (`plugin_plugingrafanaintegration_visibilityrules_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $actionsTable = self::actionsTable();
        if (!$DB->tableExists($actionsTable)) {
            $DB->doQuery("
                CREATE TABLE `{$actionsTable}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `plugin_plugingrafanaintegration_visibilityrules_id` INT UNSIGNED NOT NULL,
                    `itemtype` VARCHAR(100) NOT NULL,
                    `items_id` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `plugin_plugingrafanaintegration_visibilityrules_id` (`plugin_plugingrafanaintegration_visibilityrules_id`),
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
