<?php

/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Base compartilhada pelas implementações de fonte. Guarda a Connection e
 * oferece utilidades comuns (decode de credenciais, montagem de iframe seguro).
 */

namespace GlpiPlugin\Analyticdesign\Source;

use GlpiPlugin\Analyticdesign\Connection;

abstract class AbstractDashboardSource implements DashboardSourceInterface
{
    protected Connection $connection;

    /** Credenciais já descriptografadas (array associativo). */
    protected array $credentials = [];

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->credentials = $connection->getDecryptedCredentials();
    }

    /**
     * Monta um iframe padronizado (usado por Grafana e pelo modo
     * publish-to-web do Power BI). Centraliza atributos de sandbox/tamanho.
     *
     * Só embeda URLs http(s): mesmo sendo um campo preenchido apenas por um
     * admin do plugin, um valor `javascript:`/`data:` em `src` ainda executa
     * no contexto da página do GLPI — barrado aqui como defesa em profundidade.
     */
    protected function buildIframe(string $url, array $context = []): string
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '<div class="analyticdesign-error" style="padding:1rem;color:#b00;">'
                . htmlspecialchars(__('URL de embed inválida (esperado http/https).', 'analyticdesign'), ENT_QUOTES)
                . '</div>';
        }

        $width  = htmlspecialchars((string)($context['width']  ?? '100%'), ENT_QUOTES);
        $height = htmlspecialchars((string)($context['height'] ?? '100%'), ENT_QUOTES);
        $src    = htmlspecialchars($url, ENT_QUOTES);

        // allow-same-origin + allow-scripts são necessários para dashboards
        // interativos; ajustar conforme a política de CSP da instância.
        return sprintf(
            '<iframe src="%s" width="%s" height="%s" frameborder="0" '
            . 'style="border:0;width:%s;height:%s;" '
            . 'sandbox="allow-same-origin allow-scripts allow-popups allow-forms" '
            . 'referrerpolicy="no-referrer"></iframe>',
            $src,
            $width,
            $height,
            $width,
            $height
        );
    }

    protected function getBaseUrl(): string
    {
        return rtrim($this->connection->fields['base_url'] ?? '', '/');
    }
}
