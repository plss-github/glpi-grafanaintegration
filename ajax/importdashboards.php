<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Recebe a seleção de dashboards marcados na aba "Dashboards" da Connection
 * (ver DashboardItem::showForConnection()) e cria os DashboardItem correspondentes.
 * Submissão de formulário normal (não fetch): redireciona de volta para a
 * Connection ao final.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\DashboardItem;

// Sem Session::checkCSRF() explícito — ver comentário em front/connection.form.php
// (o kernel do GLPI 11 já valida e consome o token antes deste script rodar).
$connectionsId = (int)($_POST['connections_id'] ?? 0);

// loadAuthorized() (em vez de checkRight() global + getFromDB() cru) garante
// que a Connection pertence a uma entidade onde o usuário tem direito de
// UPDATE. Html::displayRightError() está deprecated desde o GLPI 11.0.0 —
// por baixo dos panos ela só lança Symfony\...\AccessDeniedHttpException,
// mas essa exceção é pensada para o pipeline do HttpKernel
// (rotas/Controllers); como este arquivo é um front/ajax clássico (fora
// desse pipeline) e não há como validar aqui se a exceção não tratada
// renderiza um erro razoável nesse contexto, mantemos o wrapper deprecated
// (ainda funcional em 11.0.8) em vez de trocar por um comportamento não
// verificado.
$connection = Connection::loadAuthorized($connectionsId, UPDATE);
if ($connection === null) {
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
