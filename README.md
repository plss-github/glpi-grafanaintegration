# Analytic Design by Pellissari

Plugin GLPI 11.0.x que integra dashboards de ferramentas externas de BI
(**Grafana**, e nas Fases 2/3, **Power BI**) ao sistema nativo de dashboards do GLPI.

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
- **Stub documentado do Power BI** (`PowerBiSource`) provando que as Fases 2/3 são aditivas.

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

## Segurança

Revisão do que já está implementado, o que foi corrigido nesta revisão e o
que continua sendo um risco aceito/pendente de validação.

**CSRF.** O plugin se declara `CSRF_COMPLIANT` (`setup.php`) e todo endpoint
que muda estado chama `Session::checkCSRF($_POST)` explicitamente:
`front/connection.form.php` (add/update/purge), `front/dashboarditem.form.php`
(update/purge) e os três arquivos em `ajax/`.

**Credenciais.** `Connection::credentials` é gravado como JSON criptografado
via `GLPIKey` (nunca em texto plano), e os campos sensíveis (`api_token`,
`client_id`, `client_secret`, `tenant_id`) são removidos do `$input` antes de
chegar perto de qualquer log/log de auditoria do GLPI — só o blob
criptografado é persistido.
- **Corrigido nesta revisão:** `Connection::handleCredentialInput()` sobrescrevia
  *todas* as credenciais a cada update, mesmo quando só um campo era
  preenchido — a UI permite deixar campos em branco para "manter o valor
  salvo", mas o código antigo descartava silenciosamente os campos não
  reenviados (ex.: atualizar só o `client_secret` do Power BI apagaria
  `client_id`/`tenant_id` já salvos). Agora `handleCredentialInput()` parte de
  `getDecryptedCredentials()` (valores atuais) e só sobrescreve as chaves
  efetivamente reenviadas não-vazias.

**Autorização / IDOR (entidades).** O modelo de direitos usa um único
`$rightname` (`plugin_analyticdesign_connection`) para `Connection` e
`DashboardItem`, com `Connection` respeitando entidade (`entities_id` +
`is_recursive`, herdados de `CommonDBTM`).
- **Corrigido nesta revisão:** os três endpoints em `ajax/` faziam
  `Session::haveRight()`/`checkRight()` (checagem **global**, sem entidade)
  seguido de `getFromDB($id)` **sem** checar se aquele registro específico
  pertencia a uma entidade onde o usuário tem o direito — ou seja, um usuário
  com direito de leitura/edição na *sua* entidade podia testar conexão,
  importar dashboards ou editar itens de **qualquer outra entidade**, só
  adivinhando o ID (IDOR clássico). Agora os três usam
  `$connection->can($id, RIGHT)` (que verifica direito *e* escopo de entidade
  do registro), com mensagem genérica quando falha — não distinguir
  "não existe" de "sem permissão" evita confirmar a existência de registros de
  outras entidades. `DashboardItem` não tem `entities_id` próprio (é sempre
  filho de uma `Connection`), então `ajax/updatedashboarditems.php` agora
  resolve a `Connection` de cada item (`getConnection()`) e verifica `can()`
  nela antes de aplicar a alteração.
- Os formulários clássicos (`front/connection.form.php`,
  `front/dashboarditem.form.php`) já usavam `$item->check($id, $right)`, o
  idiom correto do CommonDBTM — não precisaram de correção.

**XSS.** Toda saída de dados do usuário/DB passa por `htmlspecialchars(...,
ENT_QUOTES)` antes de ir para o HTML (nomes, categorias, mensagens de erro).
- **Corrigido nesta revisão:** `AbstractDashboardSource::buildIframe()`
  escapava a URL para o atributo `src`, mas não validava o **esquema** —
  um `embed_url` como `javascript:...` ainda executaria no contexto da
  página do GLPI ao ser colocado num `src` de iframe em alguns navegadores.
  Mesmo sendo um campo preenchido só por um admin do plugin (privilégio já
  elevado), agora `buildIframe()` só renderiza URLs `http`/`https`; qualquer
  outro esquema vira uma mensagem de erro em vez do iframe. Isso vale tanto
  para o Grafana (Fase 1) quanto para o `publish_to_web` do Power BI (Fase 3).
- O iframe também já usa `sandbox` (`allow-same-origin allow-scripts
  allow-popups allow-forms`, sem `allow-top-navigation`) e
  `referrerpolicy="no-referrer"`.

**"Publish to web" (Power BI, Fase 3) — aviso obrigatório.** Este modo expõe o
conteúdo a qualquer pessoa com o link, sem nenhuma autenticação — não é uma
falha do plugin, é a natureza do recurso do Power BI, mas o plugin precisa
deixar isso óbvio para quem administra. Hoje o aviso vermelho e fixo já
aparece no formulário da `Connection` quando `embed_mode = publish_to_web`
é escolhido (`analyticdesign-publish-warning`, alternado por JS). **Pendente**
(ver Roadmap, Fase 3): replicar o mesmo aviso no formulário/import de
`DashboardItem` quando a fonte for Power BI, já que é ali que a URL pública
de cada dashboard é efetivamente colada.

**SSRF (risco aceito).** `testConnection()`/`listDashboards()` fazem
requisições HTTP de servidor para a `base_url` configurada pelo admin. Como
só quem já tem o direito administrativo do plugin configura essa URL, isto é
um risco *inerente* ao recurso (equivalente a qualquer integração
"adicione uma URL externa" administrada por um papel confiável) — não uma
vulnerabilidade a corrigir no código, mas vale considerar segmentação de rede
entre o servidor GLPI e redes internas sensíveis em ambientes onde o direito
do plugin não for restrito a administradores totalmente confiáveis.

**SQL.** Toda leitura/escrita usa o query builder do GLPI (`$DB->request()`,
`CommonDBTM::add()/update()/getFromDB()`) — nenhuma concatenação de input do
usuário em SQL cru. As únicas strings SQL literais são os `CREATE TABLE`/
`DROP TABLE` de instalação, sem interpolação de dados externos.

## Arquitetura

```
Dashboard (hook GLPI)  ──►  SourceFactory  ──►  DashboardSourceInterface
                                                   ├── GrafanaSource      (Fase 1 ✅)
                                                   └── PowerBiSource
                                                         ├── modo secure          (Fase 2 🔜)
                                                         └── modo publish_to_web  (Fase 3 🔜)
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

- **Fase 1 — Grafana + abstração** ✅ implementada (ver "Status" acima): CRUD
  de fontes, listagem, embed via iframe, cards.
- **Fase 2 — Power BI: embed seguro** (`embed_mode = secure`) — stub em
  `PowerBiSource`, não implementado:
  - Registro do app no **Entra ID** (service principal), geração de **embed
    token** no backend, lib `powerbi-client` no front.
  - Requer capacity/licença **Premium** (custo recorrente).
  - **Aceite:** admin configura tenant/client id/secret/workspace; o embed
    autentica via service principal e renderiza o relatório protegido, sem
    expor credenciais no client.
  - **Estimativa:** ~8-10 dias.
- **Fase 3 — Power BI: publish to web** (`embed_mode = publish_to_web`) —
  separada da Fase 2 por ser bem mais simples/barata (sem OAuth, sem backend
  novo) e por ter um requisito de segurança específico que merece foco próprio:
  - O admin cola manualmente, por dashboard (`DashboardItem.embed_url`), a URL
    pública gerada pelo Power BI (Arquivo > Publicar na Web). Não há listagem
    automática nesse modo — a API do Power BI não expõe esses links.
  - Reaproveita o mesmo `AbstractDashboardSource::buildIframe()` já usado pelo
    Grafana (Fase 1), incluindo a validação de esquema `http`/`https`.
  - ⚠️ **Requisito de segurança obrigatório:** conteúdo publicado assim fica
    acessível a **qualquer pessoa com o link, sem autenticação**. A UI já
    implementa esse aviso (fixo, não descartável, em vermelho) quando
    `embed_mode = publish_to_web` é escolhido no formulário da `Connection`
    (`.analyticdesign-publish-warning` em `Connection::showForm()`); falta
    replicar o mesmo aviso no formulário/import de `DashboardItem` quando a
    fonte for Power BI, e documentar na tela que este modo não deve ser usado
    para dados confidenciais — usar a Fase 2 (`secure`) nesses casos.
  - **Aceite:** ao escolher `publish_to_web`, o aviso aparece antes de salvar;
    o card renderiza o iframe da URL pública salva; nenhuma credencial é
    necessária ou solicitada nesse modo.
  - **Estimativa:** ~2-3 dias.

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
│       ├── PowerBiSource.php             # stub das Fases 2 (secure) / 3 (publish_to_web)
│       └── SourceFactory.php             # resolve type -> implementação
├── locales/
│   └── analyticdesign.pot    # template de tradução (gettext)
└── public/
    ├── js/analyticdesign.js  # toggle de campos por tipo + botão "Testar conexão"
    └── css/analyticdesign.css
```

## Licença

GPL-2.0-or-later (obrigatório para plugins GLPI).
