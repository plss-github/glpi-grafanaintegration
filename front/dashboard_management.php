<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Tela de "Dashboard" do módulo Gerência — só existe pra quem tem uma
 * substituição de módulo ativa e visível (ver ModuleDashboard); do
 * contrário, redireciona para a Central. Espelha front/dashboard_assets.php
 * do core, mas delega tudo pra ModuleDashboard (mesma lógica reaproveitada
 * pelos 3 módulos sem Dashboard nativo).
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\ModuleDashboard;

ModuleDashboard::showOwnDashboardPage('management');
