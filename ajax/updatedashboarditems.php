<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Salva em lote a edição inline (categoria/ativo) dos dashboards já importados,
 * feita na aba "Dashboards" da Connection.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\DashboardItem;

Session::checkCSRF($_POST);
Session::checkRight(DashboardItem::$rightname, UPDATE);

foreach ($_POST['items'] ?? [] as $id => $row) {
    $item = new DashboardItem();
    if ($item->getFromDB((int)$id)) {
        $item->update([
            'id'        => (int)$id,
            'category'  => $row['category'] ?? '',
            'is_active' => !empty($row['is_active']) ? 1 : 0,
        ]);
    }
}

$connectionsId = (int)($_POST['connections_id'] ?? 0);
Html::redirect(Connection::getFormURLWithID($connectionsId));
