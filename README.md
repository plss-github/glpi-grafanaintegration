# Analytic Design by Pellissari

Plugin GLPI 11.0.x que integra dashboards de ferramentas externas de BI
(**Grafana** e, na Fase 2, **Power BI**) ao sistema nativo de dashboards do GLPI.

Adiciona uma aba **"Análise de Dados"** em **Administração** onde se cadastram as
fontes; cada dashboard exposto vira um **card** que o admin posiciona em qualquer
grade do GLPI (principal, ativos, assistência...) pelo modo de edição nativo.

## Status

**Fase 1 (Grafana) implementada como scaffold completo e navegável.** O que já
está estruturado:

- Ciclo de vida do plugin (`setup.php`, `hook.php`, install/uninstall, direitos).
- Abstração de fonte (`DashboardSourceInterface` + `AbstractDashboardSource`).
- Implementação **Grafana** (`GrafanaSource` + `GrafanaClient`).
- Factory de fontes (`SourceFactory`).
- Entidades de dados (`Connection`, `DashboardItem`) com credenciais
  criptografadas (`GLPIKey`), `rawSearchOptions()` e abas (`defineTabs`).
- CRUD completo de `Connection` via `front/connection.php` +
  `front/connection.form.php`, com campos de credenciais dinâmicos por tipo
  de fonte e botão "Testar conexão" (AJAX, sem recarregar a página).
- Aba **"Dashboards"** no formulário da `Connection`: lista os dashboards já
  importados (edição inline de categoria/ativo) e os disponíveis na fonte
  (importação seletiva via `listDashboards()`).
- Endpoints em `ajax/` para testar conexão, importar dashboards selecionados
  e salvar edição em lote — ver "Notas de arquitetura" abaixo.
- Ponte com o dashboard do GLPI (`Dashboard` — hooks `getTypes`/`getCards`).
- Entrada de menu em Administração (`Menu`).
- Assets estáticos (`public/js`, `public/css`) e `locales/analyticdesign.pot`.
- **Stub documentado do Power BI** (`PowerBiSource`) provando que a Fase 2 é aditiva.

> ⚠️ Este código **não foi testado contra uma instância GLPI 11 real** (o
> ambiente de desenvolvimento não tinha acesso a uma instalação GLPI para
> validar as APIs internas). Pontos marcados com `TODO` / `A VALIDAR` no
> código precisam de verificação manual antes de ir para produção — ver a
> seção "Notas de arquitetura e riscos" abaixo antes de instalar.

## Notas de arquitetura e riscos (ler antes de instalar)

A especificação original pede o padrão **Controller** (roteamento moderno por
atributos) em vez de arquivos soltos em `front/`/`ajax/`. Esta implementação
**usa deliberadamente o padrão clássico `front/` + `ajax/`**, pelo seguinte
motivo: a assinatura exata da API de Controllers do GLPI 11.0.x (namespace,
atributo de rota, mecanismo de auto-descoberta) não pôde ser validada contra
o código-fonte real do GLPI nesta sessão de desenvolvimento (sem acesso a uma
instância/checkout do GLPI). O padrão `front/`+`ajax/` clássico, por outro
lado, é estável e continua funcional em todas as versões do GLPI, incluindo a
11.x — é a via de menor risco para entregar algo que **realmente funciona**.

Pelo mesmo motivo, os formulários (`Connection::showForm()`,
`DashboardItem::showForm()`, `DashboardItem::showForConnection()`) são
renderizados em **PHP/HTML puro** (`showFormHeader()`/`showFormButtons()` +
tabelas `tab_cadre_fixe`), em vez de templates Twig com os macros de
`components/form/fields_macros.html.twig` — cuja assinatura exata também não
pôde ser confirmada.

**Antes de considerar a Fase 1 pronta para produção**, validar num GLPI 11
real e ajustar se necessário:

1. A assinatura exata do array de `DASHBOARD_TYPES`/`DASHBOARD_CARDS` em
   `src/Dashboard.php` (já sinalizado no código original).
2. Se `Html::input()`, `Dropdown::showFromArray()`, `Html::submit()`,
   `Html::closeForm()` e `showFormHeader()/showFormButtons()` produzem a
   marcação esperada na versão exata do GLPI 11 instalada.
3. Se migrar para Controllers, o roteamento por atributos e o Twig
   `generic_show_form.html.twig` continuam sendo o alvo recomendado a médio
   prazo — ver seção 3 da especificação original.
4. Testar o fluxo completo ponta a ponta (ver "Como testar" abaixo).

## Arquitetura

```
Dashboard (hook GLPI)  ──►  SourceFactory  ──►  DashboardSourceInterface
                                                   ├── GrafanaSource      (Fase 1 ✅)
                                                   └── PowerBiSource       (Fase 2 🔜)
```

O hook de card nunca sabe qual ferramenta está por trás. Adicionar uma nova
ferramenta = nova implementação da interface + 1 linha na `SourceFactory`.

## Instalação (dev)

1. Copiar a pasta `analyticdesign/` para `glpi/plugins/`.
2. Setup > Plugins > instalar e ativar "Analytic Design by Pellissari".
3. Administração > Análise de Dados > adicionar uma fonte Grafana.

## Como testar o fluxo completo (Fase 1 — Grafana)

1. Ter um Grafana acessível com `allow_embedding: true` (seção `[security]` do
   `grafana.ini`) e um **service account token** com permissão de leitura.
2. Administração > Análise de Dados > Fontes de dados > adicionar:
   - Ferramenta: Grafana; URL base: `https://seu-grafana`; token no campo
     "API Token / Service account token".
3. Salvar e, na própria tela, clicar em **"Testar conexão"** — deve responder
   "Conexão bem-sucedida." (chama `GET /api/health`).
4. Abrir a aba **"Dashboards"** do registro salvo: a lista "Dashboards
   disponíveis na fonte" deve trazer os dashboards do Grafana
   (`GET /api/search?type=dash-db`). Marcar um ou mais e clicar em
   "Importar selecionados".
5. Os itens importados aparecem em "Dashboards importados"; ajustar
   categoria/ativo e "Salvar" se necessário.
6. Ir a um dashboard do GLPI (ex.: principal), entrar no modo de edição e
   adicionar um card — o card do dashboard importado deve aparecer agrupado
   pela categoria escolhida. Posicionar e sair do modo de edição: o iframe do
   Grafana deve renderizar no card.
7. Repetir o teste com uma fonte inválida (URL/token errados) para confirmar
   que "Testar conexão" e a listagem tratam a falha com uma mensagem, sem
   quebrar a tela (ver `list_error`/try-catch em `DashboardItem::showForConnection()`).

## Roadmap

- **Fase 1 — Grafana:** CRUD de fontes, listagem, embed via iframe, cards. (em andamento)
- **Fase 2 — Power BI:** dois modos de embed selecionáveis:
  - `publish_to_web` (URL pública, iframe simples — ⚠️ **sem autenticação**, aviso na UI)
  - `secure` (Entra ID + service principal + embed token + powerbi-client)

## Estrutura de arquivos

```
analyticdesign/
├── setup.php                 # metadados + init (menu, hooks de dashboard, assets)
├── hook.php                  # install/uninstall + direitos
├── composer.json             # autoload PSR-4
├── front/
│   ├── connection.php         # listagem (Search::show) de fontes
│   ├── connection.form.php    # add/edit/delete de fonte (CommonDBTM padrão)
│   ├── dashboarditem.php      # listagem geral de dashboards expostos
│   └── dashboarditem.form.php # edição pontual (fluxo principal é a aba na Connection)
├── ajax/
│   ├── testconnection.php        # testa a conexão de uma fonte salva (JSON)
│   ├── importdashboards.php      # cria DashboardItem a partir da seleção
│   └── updatedashboarditems.php  # salva edição em lote (categoria/ativo)
├── src/
│   ├── Connection.php        # CommonDBTM: fontes cadastradas + showForm()
│   ├── DashboardItem.php     # CommonDBTM: dashboards expostos + aba na Connection
│   ├── Dashboard.php         # hooks getTypes/getCards + render do widget
│   ├── Menu.php              # entrada em Administração
│   ├── Client/
│   │   └── GrafanaClient.php # client da API REST do Grafana
│   └── Source/
│       ├── DashboardSourceInterface.php  # o contrato comum
│       ├── AbstractDashboardSource.php   # helpers (iframe, credenciais)
│       ├── GrafanaSource.php             # implementação Grafana
│       ├── PowerBiSource.php             # stub da Fase 2
│       └── SourceFactory.php             # resolve type -> implementação
├── locales/
│   └── analyticdesign.pot    # template de tradução (gettext)
└── public/
    ├── js/analyticdesign.js  # toggle de campos por tipo + botão "Testar conexão"
    └── css/analyticdesign.css
```

## Licença

GPL-2.0-or-later (obrigatório para plugins GLPI).
