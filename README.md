# Analytic Design

Plugin GLPI **11.0.8+** que integra dashboards de ferramentas externas de BI
(**Grafana** e **Power BI**, nos dois modos de embed) ao sistema nativo de
dashboards do GLPI.

Adiciona uma aba **"Análise de Dados"** em **Administração** onde se cadastram as
fontes; cada dashboard exposto vira um **card** que o admin posiciona em qualquer
grade do GLPI (principal, ativos, assistência...) pelo modo de edição nativo.

📘 **Guia de configuração passo a passo:** [docs/CONFIGURACAO.md](docs/CONFIGURACAO.md).

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
- CRUD de `Connection` via `front/connection.php` + `front/connection.form.php`
  em dois passos: criar pede só Nome/Ferramenta/Ativo. Ferramenta nasce vazio
  (obrigatório escolher explicitamente, sem herdar o tipo padrão da coluna) e
  Ativo nasce **"Não"** (o cadastrante ativa a fonte depois de configurá-la);
  URL base, modo de embed e credenciais de cada tipo ficam na aba própria
  **"Características"** (`ConnectionCharacteristics`, com ícone próprio na
  aba), que só existe depois que a fonte já está salva. Botão "Testar
  conexão" via AJAX, sem recarregar a página — se a conexão falhar, os campos
  de configuração são escondidos e só a mensagem de erro aparece, com um botão
  para reabri-los e corrigir.
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
- Aba própria **"Análise de Dados"** em Administração > Perfis (`ProfileRights`,
  via `Plugin::registerClass(..., ['addtabon' => Profile::class])`) com a
  matriz de direitos do plugin (Ler/Atualizar/Criar/Apagar). Na instalação, o
  direito já é concedido automaticamente ao(s) perfil(is) Super-Admin.
- Assets estáticos (`public/js`, `public/css`) e `locales/analyticdesign.pot`.
- Licença **AGPL-3.0** (ver seção Licença).

> ✅ **Testado ponta a ponta contra uma instância GLPI 11.0.8 real** (Docker,
> imagem oficial `glpi/glpi`, ver `docker-compose.yml`): instalação/ativação
> via `bin/console`, CRUD completo de `Connection`/`DashboardItem`, e o card
> renderizando de fato num dashboard do GLPI (`ajax/dashboard.php`, ações
> `get_card`/`get_cards`). Cinco bugs reais só visíveis rodando contra o core
> de verdade foram encontrados e corrigidos nesse processo — ver "Notas de
> arquitetura e riscos".

## Notas de arquitetura e riscos

- **`front/` + `ajax/` clássico, não Controllers.** A especificação original
  pede o padrão Controller (roteamento por atributos) do GLPI 11; este plugin
  usa o padrão clássico porque é o único garantidamente funcional em qualquer
  11.0.8+ sem reescrever todo o roteamento. Migrar para Controllers continua
  sendo o alvo recomendado a médio prazo (seção 3 da especificação original),
  não um bloqueador atual.
- **Formulários em PHP/HTML puro**, não Twig — mesma decisão de foco acima;
  `showFormHeader()`/`showFormButtons()` + tabelas `tab_cadre_fixe` são API
  madura e estável em todo o GLPI.
- **Contrato do hook de dashboard** (`getCards()`/`provider`/`args`, ver
  docblock de `src/Dashboard.php`) é o ponto de integração mais específico e
  menos estável usado por este plugin — o mais provável de mudar numa versão
  futura do GLPI. Dois bugs reais nele só apareceram testando contra uma
  instância viva (nunca deram erro de sintaxe/lint): `[Dashboard::class =>
  'getTypes']` **não é um callable PHP válido** (é um array associativo;
  `Plugin::doHookFunction()` chama o valor via `call_user_func()`, então
  precisa ser `Dashboard::class . '::getTypes'` ou `[Dashboard::class,
  'getTypes']`) — sem isso, os cards do plugin nunca apareciam no catálogo,
  falhando 100% silenciosamente. E `Plugin::doHookFunction(DASHBOARD_CARDS)`
  chama `getCards()` passando `null` (não omite o argumento), então o
  parâmetro não podia ser um `array` não-anulável. Ambos corrigidos.
- **Direitos do plugin na tela de Perfis:** `Profile::getRightsForForm()` (a
  matriz "nativa" de direitos exibida nas abas Ativos/Administração/etc. de
  um perfil) é uma estrutura grande, cacheada e **sem nenhum ponto de
  extensão para plugins** — confirmado lendo o código-fonte. Por isso o
  plugin usa `Plugin::registerClass(ProfileRights::class, ['addtabon' =>
  Profile::class])` para adicionar sua própria aba "Análise de Dados" ao
  perfil (mesmo mecanismo — `CommonGLPI::registerStandardTab()` — usado
  internamente pelo core para diversas outras extensões).
- **Resiliência a atualizações do GLPI:** `plugin_analyticdesign_check_config()`
  e `plugin_analyticdesign_check_prerequisites()` (`setup.php`) verificam em
  runtime, antes da ativação, que as dependências do plugin (GLPIKey,
  `CommonDBTM::can()/check()`, `Html`/`Dropdown`, Guzzle, `sodium`,
  `Glpi\Plugin\Hooks`) ainda existem — se algo for removido/renomeado numa
  atualização futura, a ativação falha com mensagem clara em vez do plugin
  quebrar em produção. O registro dos hooks de dashboard também é condicional
  (`defined(...)`): se esse contrato mudar de novo, só a integração com o
  dashboard nativo para, sem afetar o CRUD/menu/assets do resto do plugin.
- **Testado ponta a ponta** contra GLPI 11.0.8 real via Docker (ver "Como
  testar"): instalação, ativação, CRUD, e o card renderizando de fato num
  dashboard. `getTabNameForItem()` de `DashboardItem` também estava
  declarado `static` incorretamente (a base `CommonGLPI` o declara como
  método de instância — erro fatal de compilação ao sobrescrever); corrigido.
- **`plugin_analyticdesign_install()` não era idempotente em atualizações.**
  O GLPI chama essa função de novo em toda mudança de
  `PLUGIN_ANALYTICDESIGN_VERSION` (não só na primeira instalação) — sem um
  guard, `ProfileRight::addProfileRights()` tentava inserir a mesma linha de
  direito de novo e quebrava com erro de chave duplicada, deixando o plugin
  preso no estado "precisa atualizar" sem conseguir reativar. Confirmado ao
  testar a atualização de 0.2.0 para 0.3.0 contra uma instância viva;
  corrigido com uma checagem de `countElementsInTable()` antes de
  `addProfileRights()` (ver `hook.php`).

## Segurança

- **CSRF:** plugin `CSRF_COMPLIANT`. A validação em si **não** é feita chamando
  `Session::checkCSRF()` no código do plugin — no GLPI 11, o kernel
  (`Glpi\Kernel\Listener\ControllerListener\CheckCsrfListener`) já valida e
  **consome** o token `_glpi_csrf_token` automaticamente para toda requisição
  não-GET, antes do script rodar. Confirmado testando ponta a ponta contra
  uma instância real: uma segunda chamada explícita a `Session::checkCSRF()`
  no nosso código falhava sempre (token de uso único já consumido pelo
  kernel) — removida de todos os `front/*.form.php` e `ajax/*.php`, seguindo
  o mesmo padrão do core do GLPI 11 (nenhum `front/*.php` do core chama
  `Session::checkCSRF()`). O JS continua enviando `_glpi_csrf_token` no corpo
  do `fetch()` para satisfazer essa checagem automática.
- **Credenciais:** criptografadas em repouso via `GLPIKey`, nunca em texto
  plano; nunca retornam ao navegador (campos de senha sempre em branco no
  formulário); update parcial faz merge com as credenciais já salvas, em vez
  de sobrescrever tudo; o campo `credentials` vindo direto do `$_POST` bruto
  é sempre descartado — só o bloco de criptografia pode populá-lo.
- **Autorização (IDOR/entidades):** todo endpoint usa `$connection->can($id,
  RIGHT)` (direito **e** escopo de entidade), não apenas checagem global de
  direito — evita que um usuário atue sobre registros de outra entidade só
  adivinhando o ID. `DashboardItem` (sem entidade própria) é autorizado
  através da `Connection` pai.
- **XSS:** toda saída passa por `htmlspecialchars(..., ENT_QUOTES)`;
  `buildIframe()` só renderiza URLs `http`/`https` (bloqueia `javascript:`/
  `data:` em `embed_url`); iframe usa `sandbox` e
  `referrerpolicy="no-referrer"`.
- **Power BI (embed seguro):** embed token de curta duração (~1h), gerado a
  cada render e nunca persistido; `accessLevel: 'View'` (somente leitura);
  `tenant_id`/`client_id`/`workspace_id`/`report_id` validados como GUID
  antes de compor URLs ou chamar a API.
- **"Publish to web":** aviso obrigatório, fixo e em destaque na UI nos dois
  pontos onde a URL pública é definida (modo da `Connection` e adição manual
  do `DashboardItem`) — esse conteúdo fica acessível a qualquer pessoa com o
  link, sem autenticação, por natureza do recurso do Power BI.
- **Biblioteca de terceiros:** `powerbi-client` (Microsoft, MIT) vendorizada
  e fixada em versão (`public/js/vendor/`, ver `NOTICE.md`), não carregada de
  um CDN em tempo de execução.
- **SQL:** só via query builder do GLPI (`$DB->request()`,
  `CommonDBTM::add()/update()/getFromDB()`) — nenhuma concatenação de input
  em SQL cru.
- **Riscos aceitos / fora do controle do plugin:** SSRF via `base_url`
  configurada pelo admin do Grafana (inerente ao recurso — mitigado por
  exigir o direito administrativo do plugin; o Power BI não tem essa
  exposição, seus endpoints são fixos no código); política de CSP/framing da
  instância e do Grafana/Power BI (se restritiva, bloqueia o iframe —
  configuração externa, fora do escopo do plugin).

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
2. Setup > Plugins > instalar e ativar "Analytic Design".
3. Administração > Análise de Dados > adicionar uma fonte Grafana.

### Ambiente local via Docker

Este repositório inclui um `docker-compose.yml` que sobe GLPI 11.0.8 (imagem
oficial `glpi/glpi`) + MariaDB, com esta pasta montada como o plugin — só
para instalar e testar localmente (não é config de produção):

```
cp .env.example .env
docker compose up -d
# aguardar a instalação automática do GLPI (alguns minutos na 1ª vez)
docker compose exec glpi php bin/console plugin:install -u glpi analyticdesign
docker compose exec glpi php bin/console plugin:activate analyticdesign
```

> Ao atualizar o plugin (nova versão de `PLUGIN_ANALYTICDESIGN_VERSION` em
> `setup.php`), o GLPI marca o plugin como desativado automaticamente na
> próxima vez que a tela Setup > Plugins for aberta (estado "precisa
> atualizar") — repita os dois comandos acima (`plugin:install` de novo, para
> rodar o hook de instalação/migração, seguido de `plugin:activate`) para
> voltar a ativá-lo.

Acessar `http://localhost:8080` (login padrão pós-instalação: `glpi` / `glpi`
— trocar a senha antes de qualquer uso além do teste local).

A instalação já concede acesso completo ao(s) perfil(is) Super-Admin
automaticamente. Para liberar o plugin a outros perfis, há uma aba própria
**"Análise de Dados"** dentro de Administração > Perfis — ver
[docs/CONFIGURACAO.md](docs/CONFIGURACAO.md#2-conceder-o-direito-do-plugin-a-outros-perfis-se-necessário).

## Como testar o fluxo completo (Fase 1 — Grafana)

1. Ter um Grafana acessível com `allow_embedding: true` (seção `[security]` do
   `grafana.ini`) e um **service account token** com permissão de leitura.
2. Administração > Análise de Dados > Fontes de dados > adicionar: Nome,
   Ferramenta = **Grafana** (o dropdown nasce vazio — escolha explícita
   obrigatória), Ativo = Sim (nasce "Não" por padrão), salvar.
3. Na fonte recém-criada, aba **"Características"**: URL base
   `https://seu-grafana`, token no campo "API Token / Service account token",
   salvar e clicar em **"Testar conexão"** — deve responder "Conexão
   bem-sucedida." (chama `GET /api/health`).
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
   Na aba "Características", clicar em "Testar conexão" com dados inválidos
   deve esconder os campos e mostrar só a mensagem de erro, com um botão
   "Editar configuração" para reabri-los e corrigir.

## Como testar o fluxo completo (Fase 2 — Power BI, modo `secure`)

1. No Entra ID: registrar um aplicativo (service principal), gerar um client
   secret, e conceder a ele acesso ao workspace do Power BI (como membro/admin
   do workspace, ou via as configurações de "Service principals can use
   Fabric APIs" no admin portal do Power BI). Anotar `tenant_id`, `client_id`,
   `client_secret` e o `workspace_id` (GUID do workspace/group).
2. Administração > Análise de Dados > Fontes de dados > adicionar: Nome,
   Ferramenta = **Power BI** (o dropdown nasce vazio — escolha explícita
   obrigatória), Ativo = Sim (nasce "Não" por padrão), salvar (o modo de
   embed já nasce `secure` por padrão numa fonte Power BI nova).
3. Na fonte recém-criada, aba **"Características"**: confirmar Modo de embed
   = **Embed seguro**, preencher Tenant ID, Client ID, Client secret e
   Workspace ID, salvar e clicar em **"Testar conexão"** — deve responder
   "Conexão bem-sucedida." (autentica no Entra ID e chama
   `GET /v1.0/myorg/groups`).
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
2. Numa `Connection` Power BI, aba **"Características"**, mudar o Modo de
   embed para **Publish to web** — o aviso vermelho deve aparecer
   imediatamente (antes mesmo de salvar).
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
├── LICENSE                   # AGPL-3.0 (texto integral)
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
│   ├── Connection.php        # CommonDBTM: fontes cadastradas + showForm() (Nome/Ferramenta/Ativo)
│   ├── ConnectionCharacteristics.php # aba "Características" (URL/embed_mode/credenciais)
│   ├── DashboardItem.php     # CommonDBTM: dashboards expostos + aba na Connection
│   ├── Dashboard.php         # hooks getTypes/getCards + provider + render do widget
│   ├── Menu.php              # entrada em Administração
│   ├── ProfileRights.php     # aba "Análise de Dados" em Administração > Perfis
│   ├── Client/
│   │   ├── GrafanaClient.php  # client da API REST do Grafana
│   │   └── PowerBiClient.php  # OAuth2 Entra ID + API REST do Power BI
│   ├── Source/
│   │   ├── DashboardSourceInterface.php  # o contrato comum + constantes de embed_mode
│   │   ├── AbstractDashboardSource.php   # helpers (iframe, credenciais)
│   │   ├── GrafanaSource.php             # implementação Grafana (Fase 1)
│   │   ├── PowerBiSource.php             # implementação Power BI (Fases 2 e 3)
│   │   └── SourceFactory.php             # resolve type -> implementação
│   └── Traits/
│       └── HasCheckboxField.php          # helper HTML compartilhado (formulários em PHP puro)
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

**AGPL-3.0** — ver arquivo `LICENSE`. O GLPI core é GPL-3.0-or-later; a GPLv3
§13 permite expressamente combinar um programa GPLv3 com código licenciado
sob a AGPLv3 num mesmo todo (foi essa mesma cláusula que permitiu ao próprio
GLPI incorporar código AGPL-3.0 do FusionInventory ao migrar de GPL-2.0 para
GPL-3.0-or-later na versão 10.0.1: https://www.glpi-project.org/en/glpi-gpl-3-0/).
Este plugin adota a AGPL-3.0 — mais restritiva que a licença do core —, o que
é compatível com rodar sobre e distribuir código derivado do GLPI 11.0.x.
