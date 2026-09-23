<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Tela de "Dashboard" do módulo Ferramentas — ver docblock de
 * front/dashboard_management.php (mesmo padrão, delega pra ModuleDashboard).
 */

include('../../../inc/includes.php');

use GlpiPlugin\Plugingrafanaintegration\ModuleDashboard;

ModuleDashboard::showOwnDashboardPage('tools');
