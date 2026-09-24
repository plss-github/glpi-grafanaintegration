<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Salva em lote a edição inline (módulo/status) dos dashboards já
 * importados, feita na aba "Configurações" da Connection — ver
 * DashboardItem::showImportedManagementSection().
 */

include('../../../inc/includes.php');

use GlpiPlugin\Plugingrafanaintegration\Connection;
use GlpiPlugin\Plugingrafanaintegration\DashboardItem;

// Sem Session::checkCSRF() explícito — ver comentário em front/connection.form.php
// (o kernel do GLPI 11 já valida e consome o token antes deste script rodar).
// Checagem grosseira do direito antes de iterar; o escopo por entidade é
// verificado abaixo, item a item, via a Connection dona de cada um —
// DashboardItem não tem entities_id próprio (ver DashboardItem::install()),
// então a autorização real (IDOR) depende da Connection pai.
Session::checkRight(DashboardItem::$rightname, UPDATE);

foreach ($_POST['items'] ?? [] as $id => $row) {
    $item = new DashboardItem();
    if (!$item->getFromDB((int)$id)) {
        continue;
    }

    $connection = $item->getConnection();
    if ($connection === null || !$connection->can((int)$connection->fields['id'], UPDATE)) {
        continue;
    }

    $item->update([
        'id'        => (int)$id,
        'category'  => $row['category'] ?? '',
        'is_active' => !empty($row['is_active']) ? 1 : 0,
    ]);
}

$connectionsId = (int)($_POST['connections_id'] ?? 0);
Html::redirect(Connection::getFormURLWithID($connectionsId));
