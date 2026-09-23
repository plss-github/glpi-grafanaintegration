<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Pré-visualização de um dashboard exposto (ver "Pré-visualizar" na aba
 * "Pré-Visualização" da Connection — DashboardItem::showImportedSection()).
 * Página mínima, sem o menu/cabeçalho padrão do GLPI: só o embed em si, do
 * jeito que apareceria dentro de um card do dashboard nativo.
 *
 * Usa isPreviewableByCurrentUser() (não isVisibleForCurrentUser()): a
 * pré-visualização ignora deliberadamente a regra de visibilidade
 * (Perfil/Grupo/Usuário/Entidade) configurada na aba "Configurações" — quem
 * chega até aqui já tem direito de UPDATE na Connection dona, então não faz
 * sentido bloquear a própria pessoa que configurou o card de vê-lo.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Plugingrafanaintegration\DashboardItem;
use GlpiPlugin\Plugingrafanaintegration\Source\GrafanaSource;
use GlpiPlugin\Plugingrafanaintegration\Source\SourceFactory;

$id = (int)($_GET['id'] ?? 0);

$item = new DashboardItem();
if ($id <= 0 || !$item->getFromDB($id) || !$item->isPreviewableByCurrentUser()) {
    Html::displayRightError();
}

$connection = $item->getConnection();
if ($connection === null) {
    Html::displayErrorAndDie(__('Fonte de dados indisponível.', 'analyticdesign'));
}

Html::header(
    htmlspecialchars($item->fields['name'], ENT_QUOTES),
    $_SERVER['PHP_SELF'],
    '',
    '',
    false, // burguermenu
    false  // add_id_class
);

// Lembrete contextual só para Grafana: o iframe é uma requisição direta do
// navegador de quem está vendo, sem o token de API configurado na aba
// "Fonte de dados" (esse token só autentica as chamadas de backend do
// plugin) — sem auth.anonymous/Shared dashboard/SSO no Grafana, a tela de
// login do Grafana aparece aqui dentro no lugar do dashboard. Não é um
// defeito do plugin — ver seção 3 do guia de configuração.
if ($connection->fields['type'] === GrafanaSource::getType()) {
    echo "<div class='alert alert-info' style='margin-bottom:1rem;'>"
        . "<i class='ti ti-info-circle'></i> "
        . htmlspecialchars(
            __('Se aparecer uma tela de login do Grafana aqui em vez do dashboard, veja a seção "Cadastrar uma fonte Grafana" do guia de configuração — o token de API não autentica este iframe, só as chamadas de backend.', 'analyticdesign'),
            ENT_QUOTES
        )
        . "</div>";
}

try {
    echo SourceFactory::make($connection)->renderEmbed($item, ['width' => '100%', 'height' => '85vh']);
} catch (\Throwable $e) {
    echo "<div class='alert alert-important alert-danger'>"
        . htmlspecialchars(__('Falha ao renderizar o dashboard.', 'analyticdesign'), ENT_QUOTES)
        . "</div>";
}

Html::footer();
