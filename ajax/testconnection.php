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

Session::checkCSRF($_POST);

$id = (int)($_POST['id'] ?? 0);
$connection = new Connection();

// can() verifica o direito READ *e* o escopo de entidade do item — ao
// contrário de Session::haveRight() (global) + getFromDB() cru, isso evita
// que um usuário com direito de leitura numa entidade teste conexões de
// outra entidade só por adivinhar o ID. Mensagem genérica nos dois casos
// (não existe / sem permissão) para não revelar a existência do registro.
if ($id <= 0 || !$connection->can($id, READ)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => __('Fonte de dados não encontrada ou acesso negado.', 'analyticdesign'),
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
