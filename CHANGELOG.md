# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
versionamento semântico.

## [0.9.8] - 2026-09-24

### Adicionado

- **Aba "Conexão"**: URL base, token de backend e um novo **usuário dedicado
  do Grafana** (Viewer, acesso a todos os dashboards a expor) ficam numa aba
  própria da fonte, separada da aba "Fonte de Dados" (nome/ferramenta/status).
- **Proxy reverso do embed**: o `<iframe>` de cada dashboard não fala mais
  direto com o Grafana — passa por `front/grafana_proxy.php`, autenticado com
  a sessão do usuário dedicado. Resolve o problema de login sem precisar de
  `auth.anonymous`/Public dashboard/SSO no Grafana. Limitação aceita:
  WebSocket (Grafana Live) não é proxeado; painéis com polling normal (a
  maioria) não são afetados.
- **Aba "Grafana" na Central (Home)**: lista os dashboards visíveis ao
  usuário atual direto na tela inicial do GLPI, no estilo do plugin Metabase
  — sem precisar entrar num módulo específico nem posicionar cards.
- **Direito de perfil separado ("Grafana")**: nova aba em Administração >
  Perfis, independente de "Análise de Dados", só para liberar a aba "Grafana"
  na Central por perfil.

### Removido

- **Substituição do dashboard nativo de um módulo** (`ModuleDashboard`) —
  ficou obsoleta com a aba "Grafana" na Central, que resolve o mesmo caso de
  uso de forma mais direta. Coluna `replaces_module` removida via migração.

## [0.9.7] - 2026-09-24

### Corrigido

- **`date_creation`/`date_mod` voltam para `TIMESTAMP`** nas tabelas de
  `Connection`, `DashboardItem` e `VisibilityRule`. A 0.9.6 converteu essas
  colunas para `DATETIME` por engano — o core do GLPI atual usa `TIMESTAMP`
  (ver `php bin/console migration:timestamps`). Instalações já na 0.9.6 são
  migradas automaticamente (`ALTER TABLE ... MODIFY`) na atualização.

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
