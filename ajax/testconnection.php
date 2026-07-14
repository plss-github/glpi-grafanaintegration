<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Endpoint AJAX: testa a conexão de uma fonte já salva e devolve JSON.
 * Chamado pelo botão "Testar conexão" (public/js/analyticdesign.js).
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\Connection;

header('Content-Type: application/json; charset=UTF-8');

if (!Session::haveRight(Connection::$rightname, READ)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Acesso negado.', 'analyticdesign')]);
    exit;
}

Session::checkCSRF($_POST);

$id = (int)($_POST['id'] ?? 0);
$connection = new Connection();

if ($id <= 0 || !$connection->getFromDB($id)) {
    echo json_encode([
        'success' => false,
        'message' => __('Fonte de dados não encontrada.', 'analyticdesign'),
    ]);
    exit;
}

try {
    $ok = $connection->getSource()->testConnection();
    echo json_encode([
        'success' => $ok,
        'message' => $ok
            ? __('Conexão bem-sucedida.', 'analyticdesign')
            : __('Não foi possível conectar. Verifique URL e credenciais.', 'analyticdesign'),
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => __('Erro ao testar a conexão.', 'analyticdesign'),
    ]);
}
