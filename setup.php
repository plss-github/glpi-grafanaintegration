<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Plugin GLPI 11.0.x — integração de dashboards de BI externos (Grafana / Power BI)
 * ao sistema nativo de dashboards do GLPI.
 *
 * Licença: GPL-2.0
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Analyticdesign\Dashboard;
use GlpiPlugin\Analyticdesign\Menu;

define('PLUGIN_ANALYTICDESIGN_VERSION', '0.1.0');
define('PLUGIN_ANALYTICDESIGN_MIN_GLPI', '11.0.0');
define('PLUGIN_ANALYTICDESIGN_MAX_GLPI', '11.9.99');

/**
 * Init: registrado a cada carregamento. Declara hooks na API interna do GLPI.
 */
function plugin_init_analyticdesign(): void
{
    global $PLUGIN_HOOKS;

    // Plugin em conformidade com CSRF.
    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['analyticdesign'] = true;

    // Menu sob Administração.
    $PLUGIN_HOOKS['menu_toadd']['analyticdesign'] = [
        'admin' => Menu::class,
    ];

    // --- Integração com o sistema de dashboards ---
    // Novo tipo de widget (embed de BI externo).
    $PLUGIN_HOOKS[Hooks::DASHBOARD_TYPES]['analyticdesign'] = [
        Dashboard::class => 'getTypes',
    ];
    // Novos cards (um por dashboard exposto).
    $PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['analyticdesign'] = [
        Dashboard::class => 'getCards',
    ];

    // Assets do plugin: toggle de campos por tipo de fonte + botão "Testar conexão".
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['analyticdesign'] = 'public/css/analyticdesign.css';
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['analyticdesign'] = 'public/js/analyticdesign.js';
}

/**
 * Metadados do plugin (exibidos na tela de plugins).
 */
function plugin_version_analyticdesign(): array
{
    return [
        'name'           => 'Analytic Design by Pellissari',
        'version'        => PLUGIN_ANALYTICDESIGN_VERSION,
        'author'         => 'Pellissari',
        'license'        => 'GPL-2.0',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_ANALYTICDESIGN_MIN_GLPI,
                'max' => PLUGIN_ANALYTICDESIGN_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Pré-requisitos verificados antes de instalar/ativar.
 */
function plugin_analyticdesign_check_prerequisites(): bool
{
    return true;
}

/**
 * Verificação de configuração antes da ativação.
 */
function plugin_analyticdesign_check_config($verbose = false): bool
{
    return true;
}
