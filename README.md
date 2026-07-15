# Analytic Design by Pellissari

Plugin GLPI **11.0.8+** que integra dashboards de ferramentas externas de BI
(**Grafana** e **Power BI**, nos dois modos de embed) ao sistema nativo de
dashboards do GLPI.

Adiciona uma aba **"Análise de Dados"** em **Administração** onde se cadastram as
fontes; cada dashboard exposto vira um **card** que o admin posiciona em qualquer
grade do GLPI (principal, ativos, assistência...) pelo modo de edição nativo.

## Status

**Fases 1, 2 e 3 implementadas.** O que já está estruturado:

- Ciclo de vida do plugin (`setup.php`, `hook.php`, install/uninstall, direitos).
- Abstração de fonte (`DashboardSourceInterface` + `AbstractDashboardSource`).
- Implementação **Grafana** (`GrafanaSource` + `GrafanaClient`) — Fase 1.
- Implementação **Power BI** (`PowerBiSource` + `PowerBiClient`) — Fases 2 e 3,
  com os dois modos de `embed_mode`:
  - `secure` (Fase 2): OAuth2 client-credentials contra o Entra ID, geração de
    embed token de curta duração a cada render, e `powerbi-client` (vendorizado
    em `public/js/vendor/`) hidratando o iframe no navegador.
  - `publish_to_web` (Fase 3): reaproveita o mesmo `buildIframe()` do Grafana;
    sem listagem automática (a API do Power BI não expõe esses links) — usa o
    formulário de adição manual (ver abaixo). Aviso obrigatório de segurança
    fixo na UI sempre que este modo está selecionado.
- Factory de fontes (`SourceFactory`).
- Entidades de dados (`Connection`, `DashboardItem`) com credenciais
  criptografadas (`GLPIKey`), `rawSearchOptions()` e abas (`defineTabs`).
- CRUD completo de `Connection` via `front/connection.php` +
  `front/connection.form.php`, com campos de credenciais dinâmicos por tipo
  **e** modo de embed (secure vs publish_to_web) e botão "Testar conexão"
  (AJAX, sem recarregar a página).
- Aba **"Dashboards"** no formulário da `Connection`: lista os dashboards já
  importados (edição inline de categoria/ativo), os disponíveis na fonte
  (importação seletiva via `listDashboards()`, quando a fonte suporta listagem)
  e um formulário de **adição manual** (nome + URL de embed + categoria),
  necessário para o modo `publish_to_web`.
- Endpoints em `ajax/` para testar conexão, importar dashboards selecionados,
  adicionar manualmente e salvar edição em lote — ver "Notas de arquitetura"
  abaixo.
- Ponte com o dashboard do GLPI (`Dashboard` — hooks `getTypes`/`getCards`,
  contrato confirmado contra o código-fonte real do GLPI 11.0.8).
- Entrada de menu em Administração (`Menu`).
- Assets estáticos (`public/js`, `public/css`) e `locales/analyticdesign.pot`.
- Licença **GPL-3.0-or-later** (acompanhando o GLPI core — ver seção Licença).

> ⚠️ Este código foi escrito e revisado com base no código-fonte real do GLPI
> 11.0.8 (ver "Notas de arquitetura e riscos"), mas **não foi executado contra
> uma instância GLPI viva** nesta sessão de desenvolvimento — não havia uma
> instalação disponível para rodar/clicar através do fluxo. Testar
> ponta a ponta (ver "Como testar") antes de ir para produção.

## Notas de arquitetura e riscos (ler antes de instalar)

Parte da incerteza original deste plugin foi resolvida nesta revisão
consultando diretamente o código-fonte real do GLPI na tag `11.0.8`
(`github.com/glpi-project/glpi`) — o que foi confirmado e o que ainda é uma
decisão deliberada de design está detalhado abaixo.

**Confirmado contra o GLPI 11.0.8 (não é mais "a validar"):**

1. **Contrato de `DASHBOARD_TYPES`/`DASHBOARD_CARDS`** — a modelagem original
   deste plugin estava **errada** e foi corrigida: `getCards()` não aceita uma
   chave `card_options` (isso não existe nesse hook). O jeito correto de
   associar dados fixos (o `item_id` de cada `DashboardItem`) a um card é via
   `'provider' => "Classe::metodo"` (sempre uma **string**, nunca uma closure
   — o array de cards inteiro é serializado em cache por
   `Grid::getAllDasboardCards()`) + `'args'` (dados fixos, passados
   posicionalmente ao provider via `array_values()`). Ver o docblock de
   `src/Dashboard.php` para o detalhe exato do fluxo
   (`getCards()` → `provideItem()` → `renderEmbedWidget()`).
   **Nota operacional:** como essa lista é cacheada pelo GLPI, pode ser
   necessário limpar o cache da instância após importar novos dashboards para
   o card novo aparecer no catálogo de widgets.
2. `Html::input()`, `Dropdown::showFromArray()`, `Html::closeForm()`,
   `showFormHeader()/showFormButtons()`, `CommonDBTM::can()/check()`,
   `initForm()` — todos confirmados existentes em `src/CommonDBTM.php` /
   `src/Html.php` / `src/Dropdown.php` com as assinaturas usadas aqui.
   `Html::submit()` não é mais usado no código (trocado por `<button>` simples
   antes desta confirmação, sem necessidade de reverter).
3. **`GLPIKey` estava com o namespace errado** — o código assumia
   `Glpi\Security\GLPIKey`; a classe real é `\GLPIKey` (namespace global,
   `src/GLPIKey.php`). Corrigido em `Connection.php`.
4. **Licença**: GLPI é `GPL-3.0-or-later` desde a versão 10.0.1 (era
   `GPL-2.0-or-later` antes disso) — ver seção Licença.
5. PHP mínimo do GLPI 11.0.x é `8.2` — já era o que este plugin assumia.
6. `Html::displayRightError()` está **deprecated desde o GLPI 11.0.0**
   (por baixo dos panos hoje só lança
   `Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException`) mas
   **continua funcional** em 11.0.8. Mantido deliberadamente neste código:
   lançar a exceção diretamente é pensado para o pipeline do HttpKernel
   (rotas/Controllers), e não há confirmação de que produz um erro amigável
   quando não tratada num front/ajax clássico (fora desse pipeline) — ver
   comentário em `ajax/importdashboards.php`.

**Decisão deliberada, não uma lacuna de pesquisa:**

A especificação original pede o padrão **Controller** (roteamento moderno por
atributos) em vez de arquivos soltos em `front/`/`ajax/`. Esta implementação
**usa o padrão clássico `front/` + `ajax/`**: é o único garantidamente
funcional para todo o CRUD sem uma reescrita completa do roteamento, e a
pesquisa desta revisão focou em confirmar o contrato do hook de dashboard
(o ponto mais arriscado/específico do plugin) em vez de investigar o sistema
de Controllers do GLPI 11 — migrar para Controllers continua sendo o alvo
recomendado a médio prazo (seção 3 da especificação original), mas não é um
bloqueador: o padrão clássico é estável e plenamente suportado em 11.0.8.
Pelo mesmo racional de foco, os formulários continuam em **PHP/HTML puro**
(`showFormHeader()`/`showFormButtons()` + tabelas `tab_cadre_fixe`) em vez de
templates Twig com `components/form/fields_macros.html.twig`.

**Ainda pendente de teste real** (ver "Como testar" abaixo): rodar o fluxo
ponta a ponta numa instância GLPI 11.0.8 viva — o código foi revisado contra
o código-fonte, não executado.

## Segurança

Revisão do que já está implementado, o que foi corrigido nesta revisão e o
que continua sendo um risco aceito/pendente de validação.

**CSRF.** O plugin se declara `CSRF_COMPLIANT` (`setup.php`) e todo endpoint
que muda estado chama `Session::checkCSRF($_POST)` explicitamente:
`front/connection.form.php` (add/update/purge), `front/dashboarditem.form.php`
(update/purge) e os quatro arquivos em `ajax/`.

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
- **Corrigido nesta revisão:** três dos quatro endpoints em `ajax/` (todos
  menos `addmanualdashboard.php`, escrito depois já com a correção) faziam
  `Session::haveRight()`/`checkRight()` (checagem **global**, sem entidade)
  seguido de `getFromDB($id)` **sem** checar se aquele registro específico
  pertencia a uma entidade onde o usuário tem o direito — ou seja, um usuário
  com direito de leitura/edição na *sua* entidade podia testar conexão,
  importar dashboards ou editar itens de **qualquer outra entidade**, só
  adivinhando o ID (IDOR clássico). Agora os quatro usam
  `$connection->can($id, RIGHT)` (que verifica direito *e* escopo de entidade
  do registro), com mensagem genérica quando falha — não distinguir
  "não existe" de "sem permissão" evita confirmar a existência de registros de
  outras entidades. `DashboardItem` não tem `entities_id` próprio (é sempre
  filho de uma `Connection`), então `ajax/updatedashboarditems.php` resolve a
  `Connection` de cada item (`getConnection()`) e verifica `can()` nela antes
  de aplicar a alteração.
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
deixar isso óbvio para quem administra. O aviso vermelho e fixo aparece em
dois pontos: no formulário da `Connection` quando `embed_mode =
publish_to_web` é escolhido (`analyticdesign-publish-warning`, alternado por
JS), e no formulário de **adição manual** de `DashboardItem`
(`DashboardItem::showForConnection()`) quando a `Connection` já é Power BI
nesse modo — é ali que a URL pública de cada dashboard é efetivamente colada.

**Embed token do Power BI (Fase 2, modo `secure`).** `PowerBiClient::generateEmbedToken()`
gera um token de curta duração (padrão da API, ~1h) a cada render — nunca
persistido em banco nem em sessão, sempre gerado sob demanda em
`PowerBiSource::renderEmbed()`. `accessLevel: 'View'` restringe o token a
leitura. As credenciais do service principal (`tenant_id`/`client_id`/
`client_secret`/`workspace_id`) seguem o mesmo tratamento de criptografia em
repouso e merge-on-update do restante da `Connection`.

**Biblioteca de terceiros vendorizada (`powerbi-client.min.js`, Fase 2).** O
plugin embute a lib oficial da Microsoft (`powerbi-client` 2.23.10, MIT) em
`public/js/vendor/`, baixada uma vez do jsDelivr e fixada nessa versão (ver
`public/js/vendor/NOTICE.md`) em vez de referenciada via `<script>` de CDN em
tempo de execução — evita depender da disponibilidade do jsDelivr em produção
e torna o conteúdo servido auditável (o arquivo está no repositório). Ao
atualizar a versão, baixar o novo `dist/powerbi.min.js` do pacote e substituir
o arquivo — sem pipeline de build/npm neste plugin.

**SSRF (risco aceito).** `testConnection()`/`listDashboards()` do Grafana
fazem requisições HTTP de servidor para a `base_url` configurada pelo admin —
como só quem já tem o direito administrativo do plugin configura essa URL,
isto é um risco *inerente* ao recurso (equivalente a qualquer integração
"adicione uma URL externa" administrada por um papel confiável), não uma
vulnerabilidade a corrigir no código; vale considerar segmentação de rede
entre o servidor GLPI e redes internas sensíveis em ambientes onde esse
direito não for restrito a administradores totalmente confiáveis. O Power BI
(modo `secure`) **não** tem essa exposição adicional: os endpoints chamados
(`login.microsoftonline.com`, `api.powerbi.com`) são fixos no código, não
configuráveis pelo admin.

**SQL.** Toda leitura/escrita usa o query builder do GLPI (`$DB->request()`,
`CommonDBTM::add()/update()/getFromDB()`) — nenhuma concatenação de input do
usuário em SQL cru. As únicas strings SQL literais são os `CREATE TABLE`/
`DROP TABLE` de instalação, sem interpolação de dados externos.

## Arquitetura

```
Dashboard (hook GLPI)  ──►  SourceFactory  ──►  DashboardSourceInterface
                                                   ├── GrafanaSource      (Fase 1 ✅)
                                                   └── PowerBiSource
                                                         ├── modo secure          (Fase 2 ✅)
                                                         └── modo publish_to_web  (Fase 3 ✅)
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

## Como testar o fluxo completo (Fase 2 — Power BI, modo `secure`)

1. No Entra ID: registrar um aplicativo (service principal), gerar um client
   secret, e conceder a ele acesso ao workspace do Power BI (como membro/admin
   do workspace, ou via as configurações de "Service principals can use
   Fabric APIs" no admin portal do Power BI). Anotar `tenant_id`, `client_id`,
   `client_secret` e o `workspace_id` (GUID do workspace/group).
2. Administração > Análise de Dados > Fontes de dados > adicionar:
   - Ferramenta: Power BI; Modo de embed: **Embed seguro**; preencher Tenant
     ID, Client ID, Client secret e Workspace ID.
3. Salvar e clicar em **"Testar conexão"** — deve responder "Conexão
   bem-sucedida." (autentica no Entra ID e chama `GET /v1.0/myorg/groups`).
4. Aba **"Dashboards"**: "Dashboards disponíveis na fonte" deve listar os
   relatórios do workspace (`GET /v1.0/myorg/groups/{id}/reports`). Importar
   um ou mais.
5. Adicionar o card num dashboard do GLPI (mesmo fluxo do Grafana). Ao abrir,
   o container `.analyticdesign-powerbi-secure` deve ser hidratado pelo
   `powerbi-client` (`public/js/analyticdesign-powerbi.js`) usando o embed
   token gerado no render — confirmar no DevTools que o relatório carrega e
   que não há token nenhum em cookies/localStorage (só no atributo `data-`
   do próprio container, de curta duração).
6. Repetir o teste com credenciais inválidas/workspace sem acesso para
   confirmar que os erros do Entra ID/Power BI aparecem como mensagem, sem
   stack trace exposta ao usuário final.

## Como testar o modo `publish_to_web` (Fase 3)

1. No Power BI Desktop/serviço: Arquivo > Publicar na Web, copiar a URL
   pública gerada para um relatório.
2. Numa `Connection` Power BI, mudar o Modo de embed para **Publish to web**
   — o aviso vermelho deve aparecer imediatamente (antes mesmo de salvar).
3. Na aba "Dashboards", usar **"Adicionar manualmente"** (a listagem
   automática não se aplica a este modo) colando a URL pública — o mesmo
   aviso de segurança deve aparecer nesta seção quando a Connection está
   nesse modo.
4. Adicionar o card num dashboard do GLPI — deve renderizar via iframe direto
   (mesmo `buildIframe()` do Grafana).

## Roadmap

- **Fase 1 — Grafana + abstração** ✅ implementada: CRUD de fontes, listagem,
  embed via iframe, cards.
- **Fase 2 — Power BI: embed seguro** (`embed_mode = secure`) ✅ implementada:
  - `PowerBiClient` autentica no **Entra ID** (OAuth2 client-credentials) e
    fala com a API REST do Power BI (`listReports`, `generateEmbedToken`).
  - `PowerBiSource` gera um embed token novo a cada render (nunca persistido)
    e devolve um container que `public/js/analyticdesign-powerbi.js` hidrata
    com a lib vendorizada `powerbi-client` (ver `public/js/vendor/`).
  - Requer capacity/licença **Premium** no workspace do Power BI (não
    verificável pelo plugin — falha na chamada da API se não houver).
  - **Conhecido/pendente:** o token expira (~1h); para uma dashboard aberta
    além disso, o `powerbi-client` reporta erro de token expirado — não há
    (ainda) um mecanismo de refresh automático no front. Recarregar a página
    gera um token novo.
- **Fase 3 — Power BI: publish to web** (`embed_mode = publish_to_web`) ✅
  implementada:
  - Sem listagem automática (a API do Power BI não expõe esses links) — usa o
    formulário de **adição manual** em `DashboardItem::showForConnection()`.
  - Reaproveita `AbstractDashboardSource::buildIframe()` (Fase 1), incluindo a
    validação de esquema `http`/`https`.
  - ⚠️ Aviso obrigatório de segurança replicado nos dois pontos onde a URL
    pública é definida: formulário da `Connection` (modo) e formulário de
    adição manual do `DashboardItem` (URL em si).

## Estrutura de arquivos

```
analyticdesign/
├── LICENSE                   # GPL-3.0-or-later (texto integral)
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
│   ├── importdashboards.php      # cria DashboardItem a partir da seleção listada
│   ├── addmanualdashboard.php    # cria DashboardItem a partir de URL colada manualmente
│   └── updatedashboarditems.php  # salva edição em lote (categoria/ativo)
├── src/
│   ├── Connection.php        # CommonDBTM: fontes cadastradas + showForm()
│   ├── DashboardItem.php     # CommonDBTM: dashboards expostos + aba na Connection
│   ├── Dashboard.php         # hooks getTypes/getCards + provider + render do widget
│   ├── Menu.php              # entrada em Administração
│   ├── Client/
│   │   ├── GrafanaClient.php  # client da API REST do Grafana
│   │   └── PowerBiClient.php  # OAuth2 Entra ID + API REST do Power BI
│   └── Source/
│       ├── DashboardSourceInterface.php  # o contrato comum
│       ├── AbstractDashboardSource.php   # helpers (iframe, credenciais)
│       ├── GrafanaSource.php             # implementação Grafana (Fase 1)
│       ├── PowerBiSource.php             # implementação Power BI (Fases 2 e 3)
│       └── SourceFactory.php             # resolve type -> implementação
├── locales/
│   └── analyticdesign.pot    # template de tradução (gettext)
└── public/
    ├── js/
    │   ├── analyticdesign.js           # toggle de campos por tipo/modo + "Testar conexão"
    │   ├── analyticdesign-powerbi.js   # bootstrap do embed seguro (powerbi-client)
    │   └── vendor/
    │       ├── powerbi-client.min.js   # lib oficial da Microsoft, vendorizada (MIT)
    │       └── NOTICE.md               # origem/versão/licença da lib vendorizada
    └── css/analyticdesign.css
```

## Licença

**GPL-3.0-or-later** — ver arquivo `LICENSE`. O GLPI core migrou de GPL-2.0
para GPL-3.0-or-later na versão 10.0.1 (o motivo foi uma incompatibilidade de
licença com código do FusionInventory, AGPL-3.0, incorporado ao core:
https://www.glpi-project.org/en/glpi-gpl-3-0/); como este plugin roda sobre e
distribui código derivado do GLPI 11.0.x, acompanha a mesma licença do core.
