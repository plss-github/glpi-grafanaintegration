<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Adiciona um DashboardItem manualmente (nome + URL de embed + categoria),
 * usado quando a fonte não permite listar dashboards automaticamente — caso
 * do Power BI em modo publish_to_web (ver DashboardItem::showForConnection()).
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\DashboardItem;

// Sem Session::checkCSRF() explícito — ver comentário em front/connection.form.php
// (o kernel do GLPI 11 já valida e consome o token antes deste script rodar).
$connectionsId = (int)($_POST['connections_id'] ?? 0);

// loadAuthorized() garante direito de UPDATE *e* escopo de entidade (ver
// notas de segurança no README) antes de aceitar a criação.
// Html::displayRightError() está deprecated no 11.0.8 mas ainda funcional —
// ver comentário equivalente em ajax/importdashboards.php sobre por que não
// trocamos pela exceção crua num front/ajax clássico.
$connection = Connection::loadAuthorized($connectionsId, UPDATE);
if ($connection === null) {
    Html::displayRightError();
}

$name     = trim((string)($_POST['name'] ?? ''));
$embedUrl = trim((string)($_POST['embed_url'] ?? ''));

if ($name !== '' && $embedUrl !== '') {
    DashboardItem::importSelection($connection, [[
        // Sem UI para "ID externo" aqui: neste fluxo manual não há um ID da
        // ferramenta externa para referenciar, então geramos um identificador
        // interno único só para preencher a coluna (usado apenas para evitar
        // reimportação automática de listagens de API — irrelevante aqui).
        'external_id' => 'manual-' . bin2hex(random_bytes(4)),
        'name'        => $name,
        'embed_url'   => $embedUrl,
        'category'    => trim((string)($_POST['category'] ?? '')),
    ]]);
}

Html::redirect(Connection::getFormURLWithID($connectionsId));
