<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Recebe o dashboard escolhido no dropdown da aba "Configurações" (ver
 * DashboardItem::showDashboardConfigurationSection()/showDropdownImportForm())
 * e cria o DashboardItem correspondente. Substitui o antigo fluxo de
 * checkboxes em lote (ajax/importdashboards.php, removido) — agora é sempre
 * um dashboard por vez, junto com o módulo. Visibilidade e substituição de
 * módulo são configuradas depois (aba "Visibilidade" e a tabela de
 * gerenciamento em Configurações).
 *
 * Nome e URL de embed são resolvidos aqui, a partir da listagem ao vivo da
 * fonte — nunca confiando em valores vindos do POST do navegador (o
 * dropdown só posta o `external_id` escolhido).
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\DashboardItem;

// Sem Session::checkCSRF() explícito — ver comentário em front/connection.form.php
// (o kernel do GLPI 11 já valida e consome o token antes deste script rodar).
$connectionsId = (int)($_POST['connections_id'] ?? 0);

$connection = Connection::loadAuthorized($connectionsId, UPDATE);
if ($connection === null) {
    Html::displayRightError();
}

$externalId = trim((string)($_POST['external_id'] ?? ''));
if ($externalId !== '') {
    $match = null;
    try {
        foreach ($connection->getSource()->listDashboards() as $dash) {
            if (($dash['external_id'] ?? '') === $externalId) {
                $match = $dash;
                break;
            }
        }
    } catch (\Throwable $e) {
        $match = null;
    }

    if ($match !== null) {
        DashboardItem::importSelection($connection, [[
            'external_id' => $match['external_id'],
            'name'        => $match['name'],
            'embed_url'   => $match['embed_url'] ?? '',
            'category'    => trim((string)($_POST['category'] ?? '')),
        ]]);
    }
}

Html::redirect(Connection::getFormURLWithID($connectionsId));
