<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Rotinas de instalação e desinstalação.
 */

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\DashboardItem;

/**
 * Instalação: cria tabelas e direitos.
 */
function plugin_analyticdesign_install(): bool
{
    $migration = new Migration(PLUGIN_ANALYTICDESIGN_VERSION);

    Connection::install($migration);
    DashboardItem::install($migration);

    $migration->executeMigration();

    return true;
}

/**
 * Desinstalação: remove tabelas.
 */
function plugin_analyticdesign_uninstall(): bool
{
    Connection::uninstall();
    DashboardItem::uninstall();

    return true;
}

/**
 * Direitos do plugin, exibidos na matriz de perfis.
 */
function plugin_analyticdesign_getAddSearchOptions($itemtype)
{
    return [];
}

/**
 * Registro do direito usado pelas classes (Connection / DashboardItem).
 * @return array<string, string>
 */
function plugin_analyticdesign_getrights(): array
{
    return [
        'plugin_analyticdesign_connection' => __('Análise de Dados: fontes e dashboards', 'analyticdesign'),
    ];
}
