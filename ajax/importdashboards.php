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

$connectionsId = (int)($_POST['connections_id'] ?? 0);
$connection = new Connection();

// can() (em vez de checkRight() global + getFromDB() cru) garante que a
// Connection pertence a uma entidade onde o usuário tem direito de UPDATE.
if ($connectionsId <= 0 || !$connection->can($connectionsId, UPDATE)) {
    Html::displayRightError();
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
