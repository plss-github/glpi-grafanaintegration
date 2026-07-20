<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Processa os POSTs da aba "Visibilidade" (adicionar/remover regra, critério
 * ou ação) e SEMPRE redireciona de volta para a mesma aba, na Connection dona
 * — nunca uma página própria (ver docblock de VisibilityRule/
 * ConnectionVisibilityRules). Cada ação já checa o direito de UPDATE na
 * Connection antes de mexer em qualquer coisa.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\ConnectionVisibilityRules;
use GlpiPlugin\Analyticdesign\DashboardItem;
use GlpiPlugin\Analyticdesign\VisibilityRule;

// Sem Session::checkCSRF() explícito — ver comentário equivalente em
// front/connection.form.php (o kernel do GLPI 11 já valida e consome o
// token antes deste script rodar).

// Este script só processa POST (ver docblock) — um GET aqui só pode vir de
// um link/aba em cache de antes da aba "Visibilidade" virar 100% inline
// (versões anteriores tinham uma página própria de exibição neste mesmo
// arquivo). Redireciona pra lista de fontes em vez de um erro cru, já que
// não há mais nenhum `id` de Connection conhecido nesse cenário.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Html::redirect(Connection::getSearchURL());
}

function analyticdesign_redirect_to_visibilidade(int $connectionsId): void
{
    Html::redirect(
        Connection::getFormURLWithID($connectionsId)
        . '&forcetab=' . rawurlencode(ConnectionVisibilityRules::class . '$1')
    );
}

/** Carrega a regra e garante direito de UPDATE na Connection dona — ou lança 403/404. */
function analyticdesign_load_authorized_rule(int $ruleId): VisibilityRule
{
    $rule = new VisibilityRule();
    if ($ruleId <= 0 || !$rule->getFromDB($ruleId)) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    if (Connection::loadAuthorized((int)$rule->fields['connections_id'], UPDATE) === null) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    return $rule;
}

/** Resolve o valor de UM critério a partir do POST — dropdown de dashboard/módulo quando aplicável, texto livre senão. */
function analyticdesign_resolve_criterion_value(string $field, string $condition, array $post): string
{
    if ($field === 'name' && $condition === 'equals') {
        $dashboard = new DashboardItem();
        return $dashboard->getFromDB((int)($post['value_dashboard'] ?? 0)) ? $dashboard->fields['name'] : '';
    }
    if ($field === 'category' && $condition === 'equals') {
        return (string)($post['value_module'] ?? '');
    }
    return (string)($post['value_text'] ?? '');
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add_rule':
        $connectionsId = (int)($_POST['connections_id'] ?? 0);
        if (Connection::loadAuthorized($connectionsId, UPDATE) === null) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $rule = new VisibilityRule();
        $rule->add(['connections_id' => $connectionsId, 'match' => 'AND']);
        VisibilityRule::resyncAffectedItems($connectionsId);
        analyticdesign_redirect_to_visibilidade($connectionsId);
        break;

    case 'delete_rule':
        $rule = analyticdesign_load_authorized_rule((int)($_POST['id'] ?? 0));
        $connectionsId = (int)$rule->fields['connections_id'];
        $rule->delete(['id' => $rule->fields['id']]);
        VisibilityRule::resyncAffectedItems($connectionsId);
        analyticdesign_redirect_to_visibilidade($connectionsId);
        break;

    case 'update_match':
        $rule = analyticdesign_load_authorized_rule((int)($_POST['id'] ?? 0));
        $connectionsId = (int)$rule->fields['connections_id'];
        $match = in_array($_POST['match'] ?? '', ['AND', 'OR'], true) ? $_POST['match'] : 'AND';
        $rule->update(['id' => $rule->fields['id'], 'match' => $match]);
        VisibilityRule::resyncAffectedItems($connectionsId);
        analyticdesign_redirect_to_visibilidade($connectionsId);
        break;

    case 'add_criterion':
        $rule = analyticdesign_load_authorized_rule((int)($_POST['rule_id'] ?? 0));
        $connectionsId = (int)$rule->fields['connections_id'];
        $field = (string)($_POST['field'] ?? '');
        $condition = (string)($_POST['condition'] ?? 'equals');
        $value = analyticdesign_resolve_criterion_value($field, $condition, $_POST);
        VisibilityRule::addCriterion((int)$rule->fields['id'], $field, $condition, $value);
        VisibilityRule::resyncAffectedItems($connectionsId);
        analyticdesign_redirect_to_visibilidade($connectionsId);
        break;

    case 'delete_criterion':
        $rule = analyticdesign_load_authorized_rule((int)($_POST['rule_id'] ?? 0));
        $connectionsId = (int)$rule->fields['connections_id'];
        VisibilityRule::deleteCriterion((int)($_POST['criterion_id'] ?? 0), (int)$rule->fields['id']);
        VisibilityRule::resyncAffectedItems($connectionsId);
        analyticdesign_redirect_to_visibilidade($connectionsId);
        break;

    case 'add_action':
        $rule = analyticdesign_load_authorized_rule((int)($_POST['rule_id'] ?? 0));
        $connectionsId = (int)$rule->fields['connections_id'];
        $itemtype = (string)($_POST['itemtype'] ?? '');
        // Um único campo (`value_item_id`) serve pra Perfil/Grupo/Usuário/
        // Entidade — ver docblock de VisibilityRule::showActionsSection().
        $itemsId = (int)($_POST['value_item_id'] ?? 0);
        VisibilityRule::addAction((int)$rule->fields['id'], $itemtype, $itemsId);
        VisibilityRule::resyncAffectedItems($connectionsId);
        analyticdesign_redirect_to_visibilidade($connectionsId);
        break;

    case 'delete_action':
        $rule = analyticdesign_load_authorized_rule((int)($_POST['rule_id'] ?? 0));
        $connectionsId = (int)$rule->fields['connections_id'];
        VisibilityRule::deleteAction((int)($_POST['action_id'] ?? 0), (int)$rule->fields['id']);
        VisibilityRule::resyncAffectedItems($connectionsId);
        analyticdesign_redirect_to_visibilidade($connectionsId);
        break;

    default:
        throw new \Glpi\Exception\Http\BadRequestHttpException();
}
