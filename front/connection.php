<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Listagem das fontes de dados cadastradas (Grafana / Power BI).
 *
 * NOTA DE ARQUITETURA: a especificação pede o padrão "Controller" (moderno)
 * em vez de arquivos soltos em front/. Optamos pelo padrão clássico front/+ajax
 * porque é o único garantidamente estável em qualquer instalação GLPI 11.0.x
 * sem acesso a uma instância real para validar a assinatura exata da API de
 * roteamento por atributos. Migrar para Controllers é recomendado como
 * follow-up — ver README.md, seção "Notas de arquitetura".
 */

include('../../../inc/includes.php');

use GlpiPlugin\Analyticdesign\Connection;
use GlpiPlugin\Analyticdesign\Menu;

Session::checkRight(Connection::$rightname, READ);

Html::header(Connection::getTypeName(2), $_SERVER['PHP_SELF'], 'admin', Menu::class, 'connection');

Search::show(Connection::class);

Html::footer();
