<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Garante `date_creation`/`date_mod` como TIMESTAMP — mesmo tipo usado por essas
 * colunas no core do GLPI atual (`glpi_*`.`date_creation`/`date_mod`, ver
 * `php bin/console migration:timestamps`). A 0.9.6 criou/converteu essas
 * colunas para DATETIME por engano; este trait desfaz isso. Usado a partir de
 * install(), que já roda de novo a cada atualização de versão (ver nota de
 * idempotência em hook.php) — sem custo extra em instalações novas (CREATE
 * TABLE já nasce com TIMESTAMP).
 */

namespace GlpiPlugin\Plugingrafanaintegration\Traits;

trait HasTimestampMigration
{
    /** @param string[] $columns nomes das colunas a checar/corrigir, ex.: ['date_creation', 'date_mod'] */
    private static function convertDatetimeColumnsToTimestamp(string $table, array $columns = ['date_creation', 'date_mod']): void
    {
        global $DB;
        foreach ($columns as $column) {
            if (!$DB->fieldExists($table, $column)) {
                continue;
            }
            $field = $DB->request(['SELECT' => 'DATA_TYPE', 'FROM' => 'information_schema.COLUMNS', 'WHERE' => [
                'TABLE_SCHEMA' => $DB->dbdefault,
                'TABLE_NAME'   => $table,
                'COLUMN_NAME'  => $column,
            ]])->current();
            if ($field !== null && strtolower((string)$field['DATA_TYPE']) === 'datetime') {
                $DB->doQuery("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` TIMESTAMP NULL DEFAULT NULL");
            }
        }
    }
}
