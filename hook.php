<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Rotinas de instalação e desinstalação.
 */

use GlpiPlugin\Plugingrafanaintegration\Connection;
use GlpiPlugin\Plugingrafanaintegration\DashboardItem;

/**
 * Instalação: cria tabelas e direitos.
 */
function plugin_plugingrafanaintegration_install(): bool
{
    $migration = new Migration(PLUGIN_PLUGINGRAFANAINTEGRATION_VERSION);

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
    //
    // GLPI chama este install() de novo em toda atualização de versão do
    // plugin (não só na primeira instalação) — sem este guard,
    // addProfileRights() tenta inserir a mesma linha de novo e quebra com um
    // erro de chave duplicada (confirmado ao testar a atualização de 0.2.0
    // para 0.3.0 contra uma instância viva).
    if (countElementsInTable('glpi_profilerights', ['name' => Connection::RIGHTNAME]) === 0) {
        \ProfileRight::addProfileRights([Connection::RIGHTNAME]);
        foreach (\Profile::getSuperAdminProfilesId() as $profilesId) {
            \ProfileRight::updateProfileRights($profilesId, [Connection::RIGHTNAME => ALLSTANDARDRIGHT]);
        }
    }

    return true;
}

/**
 * Desinstalação: remove tabelas e direitos.
 */
function plugin_plugingrafanaintegration_uninstall(): bool
{
    Connection::uninstall();
    DashboardItem::uninstall();

    \ProfileRight::deleteProfileRights([Connection::RIGHTNAME]);

    return true;
}

/**
 * Direitos do plugin, exibidos na matriz de perfis.
 */
function plugin_plugingrafanaintegration_getAddSearchOptions($itemtype)
{
    return [];
}

/**
 * Registro do direito usado pelas classes (Connection / DashboardItem).
 * @return array<string, string>
 */
function plugin_plugingrafanaintegration_getrights(): array
{
    return [
        Connection::RIGHTNAME => __('Análise de Dados: fontes e dashboards', 'analyticdesign'),
    ];
}
