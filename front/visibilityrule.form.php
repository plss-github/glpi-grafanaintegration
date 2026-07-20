<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Cadastro/edição de UMA regra de visibilidade (Critérios | Ação) de uma
 * Connection. Página independente (não carregada via AJAX de aba, ao
 * contrário do resto do plugin): o layout de duas colunas (Critérios/Ação)
 * mais os dropdowns dinâmicos (VisibilityDropdown/select2) tornariam frágil
 * encaixar isso no mecanismo de abas via query string fixa do GLPI — ver
 * docblock de VisibilityRule.
 *
 * Chegada: pelo botão "Adicionar regra" ou pelo link "Editar" na aba
 * "Visibilidade" da Connection (ver ConnectionVisibilityRules).
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\Menu;
use GlpiPlugin\Analyticdesign\VisibilityRule;

$item = new VisibilityRule();

// Sem Session::checkCSRF() explícito — ver comentário equivalente em
// front/connection.form.php (o kernel do GLPI 11 já valida e consome o
// token antes deste script rodar).
if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $item->add($_POST);
    Html::redirect(Connection::getFormURLWithID((int)$_POST['connections_id']));
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $connectionsId = (int)$item->fields['connections_id'];
    $item->delete($_POST);
    Html::redirect(Connection::getFormURLWithID($connectionsId));
} else {
    $id = (int)($_GET['id'] ?? -1);
    $connectionsId = (int)($_GET['connections_id'] ?? 0);
    // UPDATE (não READ) mesmo para abrir em modo leitura: só quem já edita a
    // Connection acessa esta tela — não existe um modo "só visualizar regra"
    // separado, mesma decisão de escopo de DashboardItem::showForm().
    Session::checkRight(Connection::RIGHTNAME, UPDATE);

    Html::header(VisibilityRule::getTypeName(2), $_SERVER['PHP_SELF'], 'admin', Menu::class, 'connection');

    $item->display(['id' => $id, 'connections_id' => $connectionsId]);

    Html::footer();
}
