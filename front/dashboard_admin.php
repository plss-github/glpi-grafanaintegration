<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Tela de "Dashboard" do módulo Administração — ver docblock de
 * front/dashboard_management.php (mesmo padrão, delega pra ModuleDashboard).
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\ModuleDashboard;

ModuleDashboard::showOwnDashboardPage('admin');
