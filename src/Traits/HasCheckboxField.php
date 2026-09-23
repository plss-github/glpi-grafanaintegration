<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Pequeno helper HTML compartilhado por Connection/DashboardItem, cujos
 * formulários são renderizados em PHP puro (ver decisão documentada em
 * Connection::showForm()). Evita repetir o mesmo trecho de checkbox em cada
 * classe/loop que precisa de um campo booleano ("Ativo" etc.).
 */

namespace GlpiPlugin\Plugingrafanaintegration\Traits;

trait HasCheckboxField
{
    private static function renderCheckbox(string $name, bool $checked): string
    {
        return "<input type='checkbox' name='" . htmlspecialchars($name, ENT_QUOTES) . "' value='1'"
            . ($checked ? ' checked' : '')
            . '>';
    }
}
