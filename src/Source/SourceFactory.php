<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Resolve uma Connection (com seu 'type') para a implementação de fonte correta.
 * Este é o ÚNICO ponto que precisa mudar ao adicionar uma nova ferramenta de BI.
 */

namespace GlpiPlugin\Analyticdesign\Source;

use GlpiPlugin\Analyticdesign\Connection;

class SourceFactory
{
    /** @var array<string, class-string<DashboardSourceInterface>> */
    private const SOURCES = [
        'grafana' => GrafanaSource::class,
        'powerbi' => PowerBiSource::class,
    ];

    public static function make(Connection $connection): DashboardSourceInterface
    {
        $type  = $connection->fields['type'] ?? '';
        $class = self::SOURCES[$type] ?? null;

        if ($class === null) {
            throw new \RuntimeException("Tipo de fonte desconhecido: {$type}");
        }

        return new $class($connection);
    }

    /**
     * Tipos disponíveis para o dropdown de criação de fonte.
     * @return array<string, string> [type => label]
     */
    public static function getAvailableTypes(): array
    {
        $out = [];
        foreach (self::SOURCES as $type => $class) {
            $out[$type] = $class::getLabel();
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> campos de config do tipo dado */
    public static function getConfigFieldsFor(string $type): array
    {
        $class = self::SOURCES[$type] ?? null;
        return $class ? $class::getConfigFields() : [];
    }

    /**
     * Campos de config de TODOS os tipos, indexados por type. Usado para montar
     * o formulário dinamicamente no JS (mostra/esconde conforme o tipo escolhido)
     * sem precisar de uma chamada AJAX extra.
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function getAllConfigFields(): array
    {
        $out = [];
        foreach (self::SOURCES as $type => $class) {
            $out[$type] = $class::getConfigFields();
        }
        return $out;
    }
}
