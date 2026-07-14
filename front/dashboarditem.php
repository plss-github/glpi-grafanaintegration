<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Listagem geral de dashboards expostos (todas as fontes). A importação e a
 * edição do dia a dia acontecem na aba "Dashboards" da Connection; esta tela
 * serve para uma visão consolidada e busca/filtro.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\DashboardItem;
use GlpiPlugin\Analyticdesign\Menu;

Session::checkRight(DashboardItem::$rightname, READ);

Html::header(DashboardItem::getTypeName(2), $_SERVER['PHP_SELF'], 'admin', Menu::class, 'item');

Search::show(DashboardItem::class);

Html::footer();
