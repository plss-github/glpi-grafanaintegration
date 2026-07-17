<?php

/**
 * Analytic Design
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

use GlpiPlugin\Analyticdesign\DashboardItem;
use GlpiPlugin\Analyticdesign\Source\SourceFactory;

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

try {
    echo SourceFactory::make($connection)->renderEmbed($item, ['width' => '100%', 'height' => '85vh']);
} catch (\Throwable $e) {
    echo "<div class='alert alert-important alert-danger'>"
        . htmlspecialchars(__('Falha ao renderizar o dashboard.', 'analyticdesign'), ENT_QUOTES)
        . "</div>";
}

Html::footer();
