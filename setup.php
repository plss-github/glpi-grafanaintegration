<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Plugin GLPI 11.0.x — integração de dashboards de BI externos (Grafana / Power BI)
 * ao sistema nativo de dashboards do GLPI.
 *
 * Licença: GPL-3.0-or-later (GLPI passou de GPL-2.0 para GPL-3.0 a partir da
 * versão 10.0.1; plugins devem acompanhar a licença do core — ver LICENSE).
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Analyticdesign\Dashboard;
use GlpiPlugin\Analyticdesign\Menu;

define('PLUGIN_ANALYTICDESIGN_VERSION', '0.2.0');
// Alvo: GLPI 11.0.8 em diante (última patch release da série 11.0.x na data
// desta revisão). CommonDBTM::can()/check() nesta versão tipam `int $right`
// e `?array &$input` — sem impacto no uso feito por este plugin, mas registrado
// aqui pois é a versão contra a qual as assinaturas foram conferidas.
define('PLUGIN_ANALYTICDESIGN_MIN_GLPI', '11.0.8');
define('PLUGIN_ANALYTICDESIGN_MAX_GLPI', '11.9.99');

/**
 * Init: registrado a cada carregamento. Declara hooks na API interna do GLPI.
 *
 * Os hooks de dashboard (DASHBOARD_TYPES/DASHBOARD_CARDS) são o subsistema
 * mais específico/menos estável do GLPI usado por este plugin — foi o único
 * ponto onde a modelagem inicial estava errada e precisou ser corrigida
 * contra o código-fonte real (ver docblock de src/Dashboard.php). Por isso
 * o registro deles é protegido por `defined()`: se uma versão futura do
 * GLPI renomear/remover essas constantes, o restante do plugin (CRUD de
 * Connection/DashboardItem, menu, assets) continua funcionando — só a
 * integração com o dashboard nativo fica indisponível, em vez de um fatal
 * error na carga do plugin inteiro.
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

    // --- Integração com o sistema de dashboards (ver docblock acima) ---
    // IMPORTANTE: `Plugin::doHookFunction()` chama o valor registrado
    // diretamente via `call_user_func($function, ...)` — precisa ser um
    // callable PHP de verdade. `[Dashboard::class => 'getTypes']` é um array
    // ASSOCIATIVO (chave => valor), não o array indexado `[classe, método]`
    // que PHP reconhece como callable (`is_callable(['C' => 'm'])` é sempre
    // `false`; confirmado testando contra uma instância GLPI 11.0.8 real —
    // sem essa correção, os cards do plugin nunca apareciam no catálogo do
    // dashboard, falhando silenciosamente para um array vazio). A string
    // "Classe::metodo" é a forma mais clara de declarar isso.
    if (defined(Hooks::class . '::DASHBOARD_TYPES') && defined(Hooks::class . '::DASHBOARD_CARDS')) {
        // Novo tipo de widget (embed de BI externo).
        $PLUGIN_HOOKS[Hooks::DASHBOARD_TYPES]['analyticdesign'] = Dashboard::class . '::getTypes';
        // Novos cards (um por dashboard exposto).
        $PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['analyticdesign'] = Dashboard::class . '::getCards';
    }

    // Assets do plugin: toggle de campos por tipo/modo de fonte, botão "Testar
    // conexão" e o bootstrap do embed seguro do Power BI (Fase 2) — a lib
    // powerbi-client vem antes do bootstrap que a usa.
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['analyticdesign'] = 'public/css/analyticdesign.css';
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['analyticdesign'] = [
        'public/js/analyticdesign.js',
        'public/js/vendor/powerbi-client.min.js',
        'public/js/analyticdesign-powerbi.js',
    ];
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
        'license'        => 'GPL-3.0-or-later',
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
 * Pré-requisitos verificados antes de sequer listar o plugin como instalável
 * (dependências de ambiente, não do GLPI em si — essas o próprio GLPI já
 * valida via `requirements` em plugin_version_analyticdesign()).
 */
function plugin_analyticdesign_check_prerequisites(): bool
{
    // Guzzle é usado por GrafanaClient e PowerBiClient. O GLPI já traz Guzzle
    // como dependência própria, mas checar aqui evita um fatal error tardio
    // (só ao clicar em "Testar conexão") caso uma instalação atípica não o
    // tenha disponível.
    if (!class_exists(\GuzzleHttp\Client::class)) {
        return false;
    }

    // GLPIKey (criptografia de credenciais) usa a extensão sodium.
    if (!extension_loaded('sodium')) {
        return false;
    }

    return true;
}

/**
 * Verificação de configuração antes da ativação: confirma que as classes e
 * métodos do GLPI dos quais este plugin depende diretamente ainda existem
 * com a assinatura esperada. Isto é uma rede de segurança para futuras
 * atualizações do GLPI: se uma dessas dependências for renomeada/removida,
 * a ativação falha aqui com uma mensagem clara em vez de o plugin quebrar
 * de forma imprevisível em produção (fatal error numa tela aleatória).
 *
 * Lista não exaustiva — cobre os pontos mais específicos/menos estáveis
 * identificados na revisão contra o GLPI 11.0.8 (ver README, seção
 * "Notas de arquitetura e riscos"), não cada chamada do plugin.
 */
function plugin_analyticdesign_check_config($verbose = false): bool
{
    $checks = [
        '\\GLPIKey (criptografia de credenciais)' => class_exists(\GLPIKey::class),
        '\\CommonDBTM::can()' => method_exists(\CommonDBTM::class, 'can'),
        '\\CommonDBTM::check()' => method_exists(\CommonDBTM::class, 'check'),
        '\\Html::input()' => method_exists(\Html::class, 'input'),
        '\\Dropdown::showFromArray()' => method_exists(\Dropdown::class, 'showFromArray'),
        '\\Session::checkCSRF()' => method_exists(\Session::class, 'checkCSRF'),
        '\\Glpi\\Plugin\\Hooks (constantes de hook)' => class_exists(\Glpi\Plugin\Hooks::class),
    ];

    $missing = array_keys(array_filter($checks, static fn ($ok) => !$ok));

    if (!empty($missing)) {
        if ($verbose) {
            echo __('Analytic Design: dependências do GLPI não encontradas ou incompatíveis:', 'analyticdesign')
                . ' ' . implode(', ', $missing);
        }
        return false;
    }

    return true;
}
