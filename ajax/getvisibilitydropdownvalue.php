<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Endpoint AJAX de busca do dropdown de visibilidade (Perfil/Grupo/Usuário/
 * Entidade) de um DashboardItem — ver docblock de VisibilityDropdown sobre
 * por que não reaproveita o endpoint nativo de dashboards do GLPI.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\VisibilityDropdown;

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

// Só quem pode editar fontes/dashboards deste plugin busca alvos de
// compartilhamento — mesmo direito exigido para salvar a própria regra
// (ver DashboardItem::post_addItem()/post_updateItem()). Session::haveRight()
// (não checkRight()) porque o consumidor é o select2 do widget de
// visibilidade: uma resposta JSON de erro (não o redirect/HTML padrão de
// checkRight()) deixa o front-end lidar com a negação de forma previsível,
// em vez de um parse error na busca (achado na revisão de código).
if (!Session::haveRight(Connection::RIGHTNAME, UPDATE)) {
    http_response_code(403);
    echo json_encode(['results' => [], 'count' => 0]);
    exit;
}

echo json_encode(VisibilityDropdown::fetchValues((string)($_POST['searchText'] ?? '')));
