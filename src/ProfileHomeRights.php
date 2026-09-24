<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Segunda aba de direitos do plugin em Administração > Perfis — separada de
 * `ProfileRights` (que cobre o CRUD de fontes/dashboards, aba "Análise de
 * Dados") de propósito: este direito (`Connection::HOME_RIGHTNAME`) só
 * libera a aba "Grafana" na Central (ver CentralGrafanaTab) para o perfil,
 * sem dar nenhum acesso de administração. Um perfil de atendente comum
 * normalmente tem este e não tem o outro; um perfil de administrador do
 * plugin tipicamente tem os dois.
 *
 * Mesmo mecanismo de sempre — `Plugin::registerClass(self::class,
 * ['addtabon' => Profile::class])` em setup.php — registrando uma SEGUNDA
 * aba própria sobre a mesma classe `Profile` (o GLPI suporta múltiplas abas
 * de plugins diferentes, ou do mesmo plugin, sobre o mesmo alvo — confirmado
 * pelo próprio `ProfileRights` já registrado assim antes deste).
 */

namespace GlpiPlugin\Plugingrafanaintegration;

use CommonGLPI;
use Profile;

class ProfileHomeRights extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Grafana', 'analyticdesign');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-dots';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    /**
     * Na prática só a coluna "Ler" é significativa para este direito (gate
     * binário "pode ver a aba" — não existe "Criar"/"Atualizar"/"Apagar" uma
     * aba), mas usa exatamente a mesma chamada de `displayRightsChoiceMatrix()`
     * que `ProfileRights` já usa (confirmado funcionando contra uma instância
     * real) — sem tentar adivinhar parâmetros extras da API do core pra
     * restringir colunas. As demais colunas (Criar/Atualizar/Apagar) ficam
     * visíveis mas sem nenhum efeito no plugin (checagens usam só
     * `Session::haveRight(HOME_RIGHTNAME, READ)` — ver CentralGrafanaTab).
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
                    'label'    => __('Análise de Dados: aba Grafana na Central', 'analyticdesign'),
                    'field'    => Connection::HOME_RIGHTNAME,
                ],
            ],
            [
                'title' => self::getTypeName(),
            ]
        );

        return true;
    }
}
