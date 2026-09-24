<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Abre um dashboard em tela cheia a partir da aba "Grafana" na Central (ver
 * CentralGrafanaTab::showDashboardList()) — usuário final vendo, não admin
 * pré-visualizando.
 *
 * Praticamente idêntico a front/previewdashboarditem.php, mas com uma
 * diferença deliberada e importante: usa `isVisibleForCurrentUser()` (as
 * QUATRO camadas, incluindo a regra de visibilidade da aba "Visibilidade" —
 * ver docblock de DashboardItem) em vez de `isPreviewableByCurrentUser()`
 * (que ignora de propósito a regra de visibilidade, pensada só para quem já
 * tem direito de administrar a fonte). Aqui é o oposto: exatamente a
 * checagem que decide se ESTE usuário, navegando pela Central, pode ver ESTE
 * dashboard.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Plugingrafanaintegration\Connection;
use GlpiPlugin\Plugingrafanaintegration\DashboardItem;
use GlpiPlugin\Plugingrafanaintegration\Source\SourceFactory;

Session::checkRight(Connection::HOME_RIGHTNAME, READ);

$id = (int)($_GET['id'] ?? 0);

$item = new DashboardItem();
if ($id <= 0 || !$item->getFromDB($id) || !$item->isVisibleForCurrentUser()) {
    Html::displayRightError();
}

$connection = $item->getConnection();
if ($connection === null) {
    Html::displayErrorAndDie(__('Fonte de dados indisponível.', 'analyticdesign'));
}

Html::header(
    htmlspecialchars($item->fields['name'], ENT_QUOTES),
    $_SERVER['PHP_SELF'],
    'home',
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
