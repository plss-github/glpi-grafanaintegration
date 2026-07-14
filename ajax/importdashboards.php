<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Recebe a seleção de dashboards marcados na aba "Dashboards" da Connection
 * (ver DashboardItem::showForConnection()) e cria os DashboardItem correspondentes.
 * Submissão de formulário normal (não fetch): redireciona de volta para a
 * Connection ao final.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\DashboardItem;

Session::checkCSRF($_POST);
Session::checkRight(Connection::$rightname, UPDATE);

$connectionsId = (int)($_POST['connections_id'] ?? 0);
$connection = new Connection();

if ($connectionsId <= 0 || !$connection->getFromDB($connectionsId)) {
    Html::displayNotFoundError();
}

$selection = [];
foreach ($_POST['import'] ?? [] as $row) {
    if (!empty($row['selected'])) {
        $selection[] = [
            'external_id' => $row['external_id'] ?? '',
            'name'        => $row['name'] ?? '',
            'embed_url'   => $row['embed_url'] ?? '',
            'category'    => $row['category'] ?? '',
        ];
    }
}

if (!empty($selection)) {
    DashboardItem::importSelection($connection, $selection);
}

Html::redirect(Connection::getFormURLWithID($connectionsId));
