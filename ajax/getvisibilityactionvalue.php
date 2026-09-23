<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Devolve o HTML do dropdown de Valor (Perfil/Grupo/Usuário/Entidade) para a
 * linha de "adicionar ação" da aba "Visibilidade" — chamado via fetch()
 * quando o tipo em "Conceder acesso a" muda (ver
 * toggleActionValueWidget() em public/js/analyticdesign.js).
 *
 * Por que sob demanda em vez de pré-renderizar os 4 e trocar via CSS: um
 * `Dropdown::show()` iniciado dentro de um container `display:none` (o caso
 * dos 3 que não são o tipo padrão) calcula largura 0 no select2 e não se
 * recupera sozinho depois, mesmo reexibindo o container — ver docblock de
 * VisibilityRule::showActionsSection(). Buscando o widget certo só quando
 * necessário, ele nasce dentro de um container já visível, sem esse problema.
 *
 * GET, não POST: é uma leitura pura (nenhum dado é alterado), então cai fora
 * da checagem de CSRF do kernel (que só se aplica a métodos com corpo) —
 * sem precisar management de token nenhum no JS que chama isto.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Plugingrafanaintegration\Connection;

Session::checkRight(Connection::RIGHTNAME, READ);

header('Content-Type: text/html; charset=UTF-8');
Html::header_nocache();

$itemtype = (string)($_GET['itemtype'] ?? '');
$validTypes = [\Profile::class, \Group::class, \User::class, \Entity::class];
if (!in_array($itemtype, $validTypes, true)) {
    http_response_code(400);
    exit;
}

Dropdown::show($itemtype, ['name' => 'value_item_id', 'width' => '100%']);
