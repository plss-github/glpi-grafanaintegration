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

    // ProfileRight::addProfileRights() cria a linha do direito (rights=0, ou
    // seja, "sem acesso") para TODOS os perfis existentes — sem isso, a
    // matriz de direitos mostraria o valor como ausente/indefinido em vez de
    // "sem acesso" explicitamente. Em seguida, concede acesso completo já
    // ao(s) perfil(is) Super-Admin: sem isso, nem quem instalou o plugin
    // consegue usá-lo até entrar manualmente em Administração > Perfis e
    // marcar as permissões (confirmado como uma armadilha real testando
    // contra uma instância viva — ver docs/CONFIGURACAO.md).
    \ProfileRight::addProfileRights([Connection::RIGHTNAME]);
    foreach (\Profile::getSuperAdminProfilesId() as $profilesId) {
        \ProfileRight::updateProfileRights($profilesId, [Connection::RIGHTNAME => ALLSTANDARDRIGHT]);
    }

    return true;
}

/**
 * Desinstalação: remove tabelas e direitos.
 */
function plugin_analyticdesign_uninstall(): bool
{
    Connection::uninstall();
    DashboardItem::uninstall();

    \ProfileRight::deleteProfileRights([Connection::RIGHTNAME]);

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
        Connection::RIGHTNAME => __('Análise de Dados: fontes e dashboards', 'analyticdesign'),
    ];
}
