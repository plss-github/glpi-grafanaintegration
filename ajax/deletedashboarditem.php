<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Remove por completo um dashboard exposto (botão "Remover" na aba
 * "Configurações" — DashboardItem::showImportedManagementSection()). Chamado
 * via fetch() (não submissão de formulário clássica), devolve JSON — mesmo
 * padrão de ajax/testconnection.php.
 *
 * Sempre PURGA (força `$force=true` em delete()): a tabela não tem coluna
 * `is_deleted`, então o soft-delete padrão do CommonDBTM não se aplica aqui;
 * sem forçar, o comportamento fica imprevisível.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Plugingrafanaintegration\DashboardItem;

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

// Checagem grosseira do direito antes de carregar o item; o escopo por
// entidade é verificado abaixo via a Connection dona (DashboardItem não tem
// entities_id próprio — ver DashboardItem::install()).
if (!Session::haveRight(DashboardItem::$rightname, UPDATE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Você não tem direito de remover este dashboard.', 'analyticdesign')]);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$item = new DashboardItem();
if ($id <= 0 || !$item->getFromDB($id)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => __('Dashboard não encontrado.', 'analyticdesign')]);
    exit;
}

$connection = $item->getConnection();
if ($connection === null || !$connection->can((int)$connection->fields['id'], UPDATE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Você não tem direito de remover este dashboard.', 'analyticdesign')]);
    exit;
}

$ok = $item->delete(['id' => $id], true);
echo json_encode(['success' => (bool)$ok]);
