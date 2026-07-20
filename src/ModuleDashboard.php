<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Substitui o Dashboard nativo de um módulo do GLPI (Ativos, Assistência,
 * Gerência, Ferramentas) pelo embed de BI de um DashboardItem específico —
 * só para os usuários que baterem com uma regra de visibilidade que aponte
 * pra aquele item (ver VisibilityRule, aba "Visibilidade"). "Configurar"
 * fica de fora (não cabe um dashboard ali); "Administração" também, a
 * pedido.
 *
 * Não existe um hook de "primeira classe" do GLPI pra isso — o mecanismo foi
 * montado a partir de 3 peças confirmadas lendo o código-fonte do GLPI
 * 11.0.8:
 *
 * 1. **Ativos e Assistência já têm uma tela de Dashboard nativa**
 *    (front/dashboard_assets.php, front/dashboard_helpdesk.php). As duas
 *    decidem qual dashboard mostrar via `Grid::getDefaultDashboardForMenu()`,
 *    que olha PRIMEIRO `$_SESSION['last_dashboards'][$target]` — a "última
 *    visualização" que o próprio GLPI grava quando o usuário troca de
 *    dashboard manualmente (`ajax/dashboard.php::set_last_dashboard`,
 *    `Grid::setLastDashboard()`). Forçando essa mesma chave de sessão, a
 *    tela abre direto no dashboard do plugin, sem o usuário escolher nada.
 *    `getDefaultDashboardForMenu()` NÃO valida que o `context` do dashboard
 *    bate com o "menu" pedido — só tenta carregar por chave — então o
 *    `context` dos dashboards auto-provisionados aqui pode ser qualquer
 *    string própria, sem precisar imitar o `context='core'` nativo.
 *
 * 2. **Gerência e Ferramentas não têm dashboard nativo.** O
 *    plugin cria a própria tela (ver front/dashboard_management.php e
 *    irmão) e injeta o link "Dashboard" no menu desses módulos via
 *    `Hooks::REDEFINE_MENUS`, usando a MESMA chave `default_dashboard` que o
 *    core usa pra Ativos/Assistência (ver
 *    templates/layout/parts/menu.html.twig: `firstlevel['default_dashboard']`
 *    vira um link fixo "Dashboard" no topo do dropdown do módulo).
 *
 * 3. **`Dashboard::canViewCurrent()` do core não usa o direito nativo
 *    "dashboard"** pra decidir se o usuário vê o dashboard auto-provisionado
 *    — confirmado que a maioria dos perfis padrão do GLPI NÃO tem esse
 *    direito (`glpi_profilerights` — só Super-Admin tem, testando contra uma
 *    instância viva). Em vez disso, o dashboard auto-provisionado é
 *    compartilhado via `Glpi\Dashboard\Right` (o mecanismo nativo de
 *    "compartilhar dashboard" do GLPI) com EXATAMENTE os alvos enumeráveis
 *    (Perfil/Grupo/Usuário/Entidade) das regras de VisibilityRule que casam
 *    com o item — por isso a substituição de módulo só é permitida quando o
 *    item já tem uma regra assim configurada (ver
 *    DashboardItem::validateModuleReplacement()) — sem isso não haveria como
 *    conceder acesso nativo a "todo mundo com o direito do plugin" (ou a
 *    "Todos" via a Ação especial da regra), já que nenhum dos dois é
 *    enumerável como linhas de compartilhamento.
 *
 * O dashboard nativo em si nunca aparece em nenhum seletor/catálogo do
 * GLPI: usa um `context` só do plugin (`analyticdesign`), que nenhuma tela
 * nativa filtra ao montar sua lista de dashboards disponíveis — só é
 * alcançado pela chave direta, via a sobreposição de sessão acima.
 */

namespace GlpiPlugin\Analyticdesign;

use Glpi\Dashboard\Dashboard as GlpiDashboard;
use Glpi\Dashboard\Right as GlpiDashboardRight;
use Session;

class ModuleDashboard
{
    /** Módulos que podem ser alvo de substituição — Configurar e Administração ficam de fora (a pedido, para este último). */
    public const MODULES = [
        'assets'     => 'Ativos',
        'helpdesk'   => 'Assistência',
        'management' => 'Gerência',
        'tools'      => 'Ferramentas',
    ];

    /**
     * Tela (relativa à raiz do GLPI) de Dashboard de cada módulo — sem
     * querystring: `Toolbox::cleanTarget()` exige que o caminho bata com um
     * arquivo real em disco (`file_exists()`), então um único front file
     * parametrizado por `?module=` quebraria a checagem para todos os
     * módulos que dependem dele.
     */
    private const TARGET_PATHS = [
        'assets'     => '/front/dashboard_assets.php',
        'helpdesk'   => '/front/dashboard_helpdesk.php',
        'management' => '/plugins/analyticdesign/front/dashboard_management.php',
        'tools'      => '/plugins/analyticdesign/front/dashboard_tools.php',
    ];

    /** Módulos sem dashboard nativo — precisam do link injetado no menu. */
    private const MODULES_WITHOUT_NATIVE_DASHBOARD = ['management', 'tools'];

    /** Contexto reservado do plugin para os dashboards auto-provisionados. */
    private const DASHBOARD_CONTEXT = 'analyticdesign';

    private static function dashboardKeyFor(int $itemId): string
    {
        return 'analyticdesign-item-' . $itemId;
    }

    /**
     * Cria/atualiza (ou remove) o dashboard nativo que envolve o card deste
     * item — chamado ao salvar o DashboardItem (post_addItem/post_updateItem),
     * não a cada visualização.
     */
    public static function syncNativeDashboard(DashboardItem $item): void
    {
        $itemId = (int)$item->fields['id'];
        $module = (string)($item->fields['replaces_module'] ?? '');
        $isPrivate = (bool)((int)($item->fields['is_private'] ?? 0));

        if ($module === '' || !isset(self::MODULES[$module]) || !$isPrivate) {
            self::deleteNativeDashboard($itemId);
            return;
        }

        $cardId = 'analyticdesign_item_' . $itemId;
        $key = self::dashboardKeyFor($itemId);

        $dashboard = new GlpiDashboard($key);
        $dashboard->saveNew(
            $key, // título estável (não usa o nome do item — evita trocar de chave a cada renomeação)
            self::DASHBOARD_CONTEXT,
            [
                $cardId => [
                    'gridstack_id' => $cardId,
                    'card_id'      => $cardId,
                    'x'            => 0,
                    'y'            => 0,
                    'width'        => 24,
                    'height'       => 20,
                    'card_options' => [],
                ],
            ]
        );
        $dashboard->getFromDB($key);
        $dashboard->saveTitle(__('Analytic Design', 'analyticdesign') . ' — ' . $item->fields['name']);

        // Espelha os alvos enumeráveis das regras de VisibilityRule que casam
        // com este item como compartilhamento nativo do dashboard — ver
        // docblock da classe (item 3).
        $rights = VisibilityRule::getConcreteGrantsForItem($item);
        $nativeRights = [];
        foreach ($rights as $itemtype => $ids) {
            if (!empty($ids)) {
                $nativeRights[$itemtype::getForeignKeyField()] = $ids;
            }
        }
        // Right::addForDashboard() só faz INSERT (sem delete antes) — chamar
        // de novo a cada sincronização acumularia linhas duplicadas; limpa
        // primeiro pra manter só o espelho atual das regras.
        global $DB;
        $DB->delete(GlpiDashboardRight::getTable(), ['dashboards_dashboards_id' => (int)$dashboard->fields['id']]);
        GlpiDashboardRight::addForDashboard((int)$dashboard->fields['id'], $nativeRights);
    }

    /**
     * Apaga direto via query builder, sem passar por `CommonDBTM::delete()`:
     * esse método refaz um `getFromDB($input['id'])` internamente, e
     * `Glpi\Dashboard\Dashboard::getFromDB()` é sobrescrito para buscar pela
     * coluna `key` (string), não pelo `id` numérico — passar o `id` ali
     * nunca encontra a linha, então `delete()` falha silenciosamente
     * (confirmado testando: o dashboard nativo nunca era removido ao desligar
     * a substituição de módulo). Limpa também o compartilhamento nativo
     * espelhado (`glpi_dashboards_rights`), mesma tabela zerada em
     * `syncNativeDashboard()` antes de recriar.
     *
     * Público (não `private`): também chamado por
     * `DashboardItem::post_purgeItem()` ao remover um dashboard exposto de
     * vez — sem isso, apagar o item deixava o dashboard nativo (e seu
     * compartilhamento espelhado) órfão para sempre.
     */
    public static function deleteNativeDashboard(int $itemId): void
    {
        $dashboard = new GlpiDashboard();
        if (!$dashboard->getFromDB(self::dashboardKeyFor($itemId))) {
            return;
        }

        global $DB;
        $dashboardId = (int)$dashboard->fields['id'];
        $DB->delete(GlpiDashboardRight::getTable(), ['dashboards_dashboards_id' => $dashboardId]);
        $DB->delete(GlpiDashboard::getTable(), ['id' => $dashboardId]);
    }

    /**
     * @return array<string, DashboardItem> módulo => item vencedor (o
     *         primeiro item ativo e visível encontrado para aquele módulo;
     *         se duas regras miram o mesmo módulo para o mesmo usuário, só a
     *         primeira em ordem de id vale — caso de configuração
     *         sobreposta, não tratado como erro).
     */
    private static function getActiveReplacementsForCurrentUser(): array
    {
        global $DB;

        $result = [];
        $it = $DB->request([
            'FROM'  => DashboardItem::getTable(),
            'WHERE' => [
                'is_active'        => 1,
                'is_private'       => 1,
                'replaces_module'  => array_keys(self::MODULES),
            ],
            'ORDER' => 'id',
        ]);
        foreach ($it as $row) {
            $module = $row['replaces_module'];
            if (isset($result[$module])) {
                continue;
            }
            $item = new DashboardItem();
            $item->fields = $row;
            if ($item->isVisibleForCurrentUser()) {
                $result[$module] = $item;
            }
        }
        return $result;
    }

    /**
     * Hook Glpi\Plugin\Hooks::POST_INIT — roda uma vez por sessão (guardado
     * por uma flag na própria sessão, pra não repetir a consulta a cada
     * página) e força `$_SESSION['last_dashboards']` pras telas de
     * Ativos/Assistência que o usuário atual deve ver substituídas.
     */
    public static function applySessionOverrides(): void
    {
        if (!Session::getLoginUserID() || !empty($_SESSION['analyticdesign_module_dashboards_applied'])) {
            return;
        }
        $_SESSION['analyticdesign_module_dashboards_applied'] = true;

        foreach (self::getActiveReplacementsForCurrentUser() as $module => $item) {
            $_SESSION['last_dashboards'][self::TARGET_PATHS[$module]] = self::dashboardKeyFor((int)$item->fields['id']);
        }
    }

    /**
     * Hook Glpi\Plugin\Hooks::REDEFINE_MENUS — injeta o link "Dashboard" no
     * topo do menu de Gerência/Ferramentas (mesma chave
     * `default_dashboard` que o core já usa para Ativos/Assistência), só
     * quando o usuário atual tem uma substituição ativa para aquele módulo.
     *
     * @param array $menu
     * @return array
     */
    public static function redefineMenus($menu)
    {
        if (!is_array($menu)) {
            return $menu;
        }

        $replacements = self::getActiveReplacementsForCurrentUser();
        foreach (self::MODULES_WITHOUT_NATIVE_DASHBOARD as $module) {
            if (isset($replacements[$module]) && isset($menu[$module])) {
                $menu[$module]['default_dashboard'] = self::TARGET_PATHS[$module];
            }
        }
        return $menu;
    }

    /**
     * Renderiza a tela de Dashboard própria do plugin para um módulo sem
     * dashboard nativo — ver front/dashboard_management.php e irmãos.
     */
    public static function showOwnDashboardPage(string $module): void
    {
        global $CFG_GLPI;

        \Session::checkCentralAccess();

        $replacements = self::getActiveReplacementsForCurrentUser();
        if (!isset($replacements[$module])) {
            \Html::redirect($CFG_GLPI['root_doc'] . '/front/central.php');
            return;
        }

        $key = self::dashboardKeyFor((int)$replacements[$module]->fields['id']);
        $dashboard = new GlpiDashboard($key);
        if (!$dashboard->canViewCurrent()) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        \Html::header(self::MODULES[$module], '', $module, 'dashboard');
        $grid = new \Glpi\Dashboard\Grid($key);
        $grid->showDefault();
        \Html::footer();
    }
}
