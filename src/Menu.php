<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Entrada de menu "Análise de Dados" sob Administração.
 * Registrada via $PLUGIN_HOOKS['menu_toadd']['plugingrafanaintegration']['admin'].
 */

namespace GlpiPlugin\Plugingrafanaintegration;

use CommonGLPI;

class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Análise de Dados', 'analyticdesign');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-chart-dots';
    }

    /**
     * Conteúdo do menu: leva à listagem de fontes cadastradas e permite
     * adicionar novas. Submenu com Connection e DashboardItem.
     */
    public static function getMenuContent()
    {
        $menu = [];
        $menu['title'] = self::getMenuName();
        $menu['page']  = '/plugins/plugingrafanaintegration/front/connection.php';
        $menu['icon']  = self::getIcon();

        // 'icon' aqui também alimenta o breadcrumb (ver
        // templates/layout/parts/breadcrumbs.html.twig do core:
        // menu[sector]['content'][item]['icon']) — sem essa chave, o último
        // item do breadcrumb ("Fontes de dados") ficava sem ícone, deixando
        // a trilha visualmente desalinhada com os itens anteriores
        // (Home/Administração/Análise de Dados), que sempre têm ícone.
        $menu['options']['connection'] = [
            'title' => Connection::getTypeName(2),
            'page'  => '/plugins/plugingrafanaintegration/front/connection.php',
            'icon'  => Connection::getIcon(),
            'links' => [
                'search' => '/plugins/plugingrafanaintegration/front/connection.php',
                'add'    => '/plugins/plugingrafanaintegration/front/connection.form.php',
            ],
        ];
        $menu['options']['item'] = [
            'title' => DashboardItem::getTypeName(2),
            'page'  => '/plugins/plugingrafanaintegration/front/dashboarditem.php',
            'icon'  => DashboardItem::getIcon(),
            'links' => [
                'search' => '/plugins/plugingrafanaintegration/front/dashboarditem.php',
            ],
        ];

        return $menu;
    }
}
