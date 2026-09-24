<?php

/**
 * Pellissari Grafana Integration
 * -----------------------------------------------------------------------------
 * Corrige `date_creation`/`date_mod` criadas como TIMESTAMP (padrão antigo do
 * GLPI, abandonado desde a 9.2 por causa do bug do ano 2038 e da conversão de
 * fuso horário implícita do tipo TIMESTAMP do MySQL/MariaDB) para DATETIME —
 * mesmo tipo usado por essas colunas no core (`glpi_*`.`date_creation`/
 * `date_mod`). Usado a partir de install(), que já roda de novo a cada
 * atualização de versão (ver nota de idempotência em hook.php) — sem custo
 * extra em instalações novas (CREATE TABLE já nasce com DATETIME).
 */

namespace GlpiPlugin\Plugingrafanaintegration\Traits;

trait HasTimestampMigration
{
    /** @param string[] $columns nomes das colunas a checar/corrigir, ex.: ['date_creation', 'date_mod'] */
    private static function convertTimestampColumnsToDatetime(string $table, array $columns = ['date_creation', 'date_mod']): void
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
            if ($field !== null && strtolower((string)$field['DATA_TYPE']) === 'timestamp') {
                $DB->doQuery("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` DATETIME NULL DEFAULT NULL");
            }
        }
    }
}
