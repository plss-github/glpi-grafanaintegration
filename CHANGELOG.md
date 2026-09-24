# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
versionamento semântico.

## [0.9.6] - 2026-09-24

### Corrigido

- **`date_creation`/`date_mod` usavam `TIMESTAMP`** (padrão antigo do GLPI,
  abandonado desde a 9.2 por causa do bug do ano 2038 e da conversão de fuso
  horário implícita do tipo `TIMESTAMP` do MySQL/MariaDB) nas tabelas de
  `Connection`, `DashboardItem` e `VisibilityRule`. Corrigido para `DATETIME`
  (mesmo tipo usado pelo core), com migração automática (`ALTER TABLE ...
  MODIFY`) para quem já tinha essas tabelas instaladas — sem perda de dado.
- Cabeçalhos de `public/css/analyticdesign.css` e `public/js/analyticdesign.js`
  ainda referenciavam o nome antigo do plugin ("Analytic Design") — corrigido
  para "Pellissari Grafana Integration".

### Infraestrutura

- `docker-compose.yml` deixou de ser versionado — é ambiente de dev local,
  específico de cada máquina.
- Adicionado `.github/workflows/main.yml` (lint + release automática a
  partir da versão em `setup.php`).
