<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Entrada de menu "Análise de Dados" sob Administração.
 * Registrada via $PLUGIN_HOOKS['menu_toadd']['analyticdesign']['admin'].
 */

namespace GlpiPlugin\Analyticdesign;

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
        $menu['page']  = '/plugins/analyticdesign/front/connection.php';
        $menu['icon']  = self::getIcon();

        $menu['options']['connection'] = [
            'title' => Connection::getTypeName(2),
            'page'  => '/plugins/analyticdesign/front/connection.php',
            'links' => [
                'search' => '/plugins/analyticdesign/front/connection.php',
                'add'    => '/plugins/analyticdesign/front/connection.form.php',
            ],
        ];
        $menu['options']['item'] = [
            'title' => DashboardItem::getTypeName(2),
            'page'  => '/plugins/analyticdesign/front/dashboarditem.php',
            'links' => [
                'search' => '/plugins/analyticdesign/front/dashboarditem.php',
            ],
        ];

        return $menu;
    }
}
