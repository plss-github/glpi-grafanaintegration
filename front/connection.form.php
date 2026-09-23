<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Formulário de cadastro/edição de uma fonte de dados (Connection).
 * Ver nota de arquitetura em front/connection.php.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Plugingrafanaintegration\Connection;
use GlpiPlugin\Plugingrafanaintegration\Menu;

$item = new Connection();

// Sem Session::checkCSRF() explícito aqui: o kernel do GLPI 11 já valida (e
// consome) o token via Glpi\Kernel\Listener\ControllerListener\CheckCsrfListener
// para toda requisição não-GET, antes deste script rodar — uma segunda
// checagem aqui falharia sempre (token de uso único já consumido). Confirmado
// contra front/profile.form.php e outros do core GLPI 11.0.8, que não chamam
// mais Session::checkCSRF() em lugar nenhum.
if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID === false) {
        // prepareInputForAdd() rejeitou o input (ex.: nenhuma ferramenta
        // selecionada) e já registrou o erro via addMessageAfterRedirect().
        Html::back();
    }
    Html::redirect(Connection::getFormURLWithID($newID));
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST);
    $item->redirectToList();
} else {
    $id = (int)($_GET['id'] ?? -1);
    Session::checkRight(Connection::$rightname, $id > 0 ? READ : CREATE);

    Html::header(Connection::getTypeName(2), $_SERVER['PHP_SELF'], 'admin', Menu::class, 'connection');

    $item->display(['id' => $id]);

    Html::footer();
}
