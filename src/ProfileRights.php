<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Aba dedicada de direitos do plugin em Administração > Perfis.
 *
 * Não é uma entidade de banco (não estende CommonDBTM) — existe só para
 * plugar no sistema de abas do GLPI sobre `Profile`, via
 * `Plugin::registerClass(self::class, ['addtabon' => Profile::class])`
 * (ver setup.php). `Profile::getRightsForForm()` (a matriz de direitos
 * "nativa" do GLPI, exibida nas abas Ativos/Administração/etc.) é uma
 * estrutura grande, cacheada e sem nenhum ponto de extensão para plugins —
 * confirmado lendo o código-fonte do GLPI 11.0.8. Uma aba própria, em vez de
 * tentar se encaixar nessa estrutura, é o caminho documentado e usado pelo
 * próprio core (ver `CommonGLPI::registerStandardTab()`).
 */

namespace GlpiPlugin\Analyticdesign;

use CommonGLPI;
use Profile;

class ProfileRights extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Análise de Dados', 'analyticdesign');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            return self::getTypeName();
        }
        return '';
    }

    /**
     * `Profile::displayRightsChoiceMatrix()` gera a mesma grade de checkboxes
     * (Criar/Ler/Atualizar/Excluir/Remover definitivamente) usada em todo o
     * resto do GLPI, a partir de `Connection::getRights()` (herdado sem
     * alterações de `CommonDBTM::getRights()`) — não precisa de rótulos
     * customizados como "pode gerenciar"/"visualizar apenas": marcar só
     * "Ler" já corresponde a "visualizar apenas"; marcar tudo corresponde a
     * "gerenciar completamente". `DashboardItem` compartilha o mesmo direito
     * (ver `Connection::RIGHTNAME`), então uma linha já cobre os dois.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Profile)) {
            return false;
        }

        $item->displayRightsChoiceMatrix(
            [
                [
                    'itemtype' => Connection::class,
                    'label'    => __('Análise de Dados: fontes e dashboards', 'analyticdesign'),
                    'field'    => Connection::RIGHTNAME,
                ],
            ],
            [
                'title' => self::getTypeName(),
            ]
        );

        return true;
    }
}
