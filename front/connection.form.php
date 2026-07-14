<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Formulário de cadastro/edição de uma fonte de dados (Connection).
 * Ver nota de arquitetura em front/connection.php.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\Menu;

$item = new Connection();

if (isset($_POST['add'])) {
    Session::checkCSRF($_POST);
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    Html::redirect(Connection::getFormURLWithID($newID));
} elseif (isset($_POST['update'])) {
    Session::checkCSRF($_POST);
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    Session::checkCSRF($_POST);
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
