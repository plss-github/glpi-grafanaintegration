<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Edição pontual de um dashboard exposto (acessado a partir da busca geral).
 * O fluxo principal de importação/edição é a aba "Dashboards" da Connection.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\DashboardItem;
use GlpiPlugin\Analyticdesign\Menu;

$item = new DashboardItem();

if (isset($_POST['update'])) {
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
    Session::checkRight(DashboardItem::$rightname, READ);

    Html::header(DashboardItem::getTypeName(2), $_SERVER['PHP_SELF'], 'admin', Menu::class, 'item');

    $item->display(['id' => $id]);

    Html::footer();
}
