<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Proxy reverso: repassa ao Grafana, autenticado com a sessão do usuário
 * dedicado (ver GrafanaSource::proxySession()), TUDO que o navegador do
 * usuário final pede para renderizar um dashboard — o HTML inicial, mas
 * também todo JS/CSS/API/avatar que o próprio Grafana referencia depois. O
 * navegador do usuário final nunca fala direto com o Grafana; só com este
 * endpoint do GLPI. Isso é o que resolve o problema de autenticação que o
 * plugin tinha até a versão anterior (iframe direto pro Grafana, sem
 * nenhuma sessão — cada usuário caía na tela de login do Grafana).
 *
 * `GET/POST ?id={connectionsId}&path={caminho relativo à raiz do Grafana}`
 * — `renderEmbed()` (GrafanaSource) monta a URL inicial do iframe já assim;
 * o HTML devolvido por este proxy reescreve toda URL absoluta (`/algo`) que
 * o Grafana gerou para passar de novo por aqui (ver analyticdesign_rewrite_html()),
 * então o navegador nunca precisa descobrir o domínio real do Grafana.
 *
 * Limitação aceita e documentada (ver docs/CONFIGURACAO.md): WebSocket
 * (Grafana Live, atualização de painel em tempo real) não é proxeado — PHP
 * puro não faz upgrade de conexão HTTP para WebSocket. Painéis com polling
 * normal (a grande maioria) não são afetados.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Plugingrafanaintegration\Connection;
use GlpiPlugin\Plugingrafanaintegration\Source\GrafanaSource;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

// Sessão do GLPI não precisa mais ficar travada depois da checagem de
// autorização abaixo: o navegador dispara DEZENAS de requisições em
// paralelo pra montar um dashboard (JS, CSS, várias chamadas de API) — sem
// fechar a sessão PHP, elas serializariam uma atrás da outra (lock do
// arquivo de sessão), deixando o dashboard visivelmente lento pra carregar.
function analyticdesign_authorize_and_close_session(): Connection
{
    $connectionId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $connection = Connection::loadAuthorized($connectionId, READ);
    if ($connection === null || (int)$connection->fields['is_active'] !== 1) {
        http_response_code(403);
        exit;
    }
    session_write_close();
    return $connection;
}

/**
 * `path` só pode ser um caminho relativo à raiz do próprio Grafana da
 * Connection (nunca outro host) — mitiga SSRF via manipulação do parâmetro.
 * Aceita apenas o que começa com `/`; rejeita esquema/host embutido
 * (`http://`, `//host/...`) e qualquer tentativa de sair da raiz (`../`).
 */
function analyticdesign_sanitize_path(string $path): ?string
{
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        return null;
    }
    if (str_contains($path, '://') || str_contains($path, '..')) {
        return null;
    }
    return $path;
}

/** Reescreve `src="/..."`/`href="/..."` no HTML pra passar de novo por este proxy — ver docblock do arquivo. */
function analyticdesign_rewrite_html(string $html, int $connectionId): string
{
    return preg_replace_callback(
        '/(src|href)="(\/(?!\/)[^"]*)"/',
        static function (array $m) use ($connectionId) {
            return $m[1] . '="' . htmlspecialchars(
                GrafanaSource::buildProxyUrl($connectionId, html_entity_decode($m[2], ENT_QUOTES)),
                ENT_QUOTES
            ) . '"';
        },
        $html
    );
}

$connection = analyticdesign_authorize_and_close_session();
$connectionId = (int)$connection->fields['id'];

$path = analyticdesign_sanitize_path((string)($_GET['path'] ?? ''));
if ($path === null) {
    http_response_code(400);
    exit;
}

/** @var GrafanaSource $source */
$source = $connection->getSource();
if (!($source instanceof GrafanaSource)) {
    http_response_code(404);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'POST' : 'GET';
$body = $method === 'POST' ? file_get_contents('php://input') : null;

$http = new Client([
    'base_uri'        => rtrim($connection->fields['base_url'], '/') . '/',
    'timeout'         => 30,
    'allow_redirects' => false,
    'http_errors'     => false,
]);

$attemptForward = static function (string $cookie) use ($http, $method, $path, $body) {
    $options = [
        'headers' => [
            'Cookie'       => $cookie,
            'Accept'       => $_SERVER['HTTP_ACCEPT'] ?? '*/*',
            'Content-Type' => $_SERVER['CONTENT_TYPE'] ?? 'application/json',
        ],
    ];
    if ($body !== null && $body !== '') {
        $options['body'] = $body;
    }
    return $http->request($method, ltrim($path, '/'), $options);
};

$cookie = $source->proxySession();
if ($cookie === null) {
    http_response_code(502);
    echo __('Não foi possível autenticar o usuário dedicado no Grafana. Verifique as credenciais na aba "Conexão".', 'analyticdesign');
    exit;
}

try {
    $response = $attemptForward($cookie);
    // 401/403: sessão do usuário dedicado expirou/foi revogada no Grafana —
    // faz login de novo UMA vez e repete a requisição, antes de desistir.
    if (in_array($response->getStatusCode(), [401, 403], true)) {
        $cookie = $source->proxySession(true);
        if ($cookie !== null) {
            $response = $attemptForward($cookie);
        }
    }
} catch (GuzzleException $e) {
    http_response_code(502);
    echo __('Falha ao conectar ao Grafana.', 'analyticdesign');
    exit;
}

$status = $response->getStatusCode();
http_response_code($status);

$contentType = $response->getHeaderLine('Content-Type');
if ($contentType !== '') {
    header('Content-Type: ' . $contentType);
}

// Redirecionamentos do Grafana (ex.: /d/uid -> /d/uid/slug-canonico) também
// precisam passar pelo proxy — sem isso, o navegador tentaria navegar direto
// pro Grafana e cairia sem sessão nenhuma.
if (in_array($status, [301, 302, 303, 307, 308], true)) {
    $location = $response->getHeaderLine('Location');
    if ($location !== '') {
        $relative = analyticdesign_sanitize_path(
            str_starts_with($location, 'http') ? (parse_url($location, PHP_URL_PATH) ?: '/') : $location
        ) ?? '/';
        header('Location: ' . GrafanaSource::buildProxyUrl($connectionId, $relative));
    }
    exit;
}

// `Set-Cookie` do Grafana NUNCA chega ao navegador do usuário final — a
// sessão pertence só ao backend do plugin (ver proxySession()).
$isHtml = str_starts_with(strtolower($contentType), 'text/html');
if ($isHtml) {
    echo analyticdesign_rewrite_html((string)$response->getBody(), $connectionId);
    exit;
}

// Demais tipos (JS/CSS/JSON/imagens): repassa em stream, sem reescrita nem
// buffer completo em memória.
$stream = $response->getBody();
while (!$stream->eof()) {
    echo $stream->read(8192);
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}
