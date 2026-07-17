# Analytic Design

Plugin GLPI **11.0.8+** que integra dashboards de ferramentas externas de BI
(**Grafana** e **Power BI**, nos dois modos de embed) ao sistema nativo de
dashboards do GLPI.

Adiciona uma aba **"Análise de Dados"** em **Administração** onde se cadastram as
fontes; cada dashboard exposto vira um **card** que o admin posiciona em qualquer
grade do GLPI (principal, ativos, assistência...) pelo modo de edição nativo.

📘 **Guia de configuração passo a passo:** [docs/CONFIGURACAO.md](docs/CONFIGURACAO.md).

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
- **Testado ponta a ponta** contra GLPI 11.0.8 real via Docker (ver
  [docs/CONFIGURACAO.md](docs/CONFIGURACAO.md)): instalação, ativação, CRUD,
  e o card renderizando de fato num dashboard. `getTabNameForItem()` de
  `DashboardItem` também estava declarado `static` incorretamente (a base
  `CommonGLPI` o declara como método de instância — erro fatal de compilação
  ao sobrescrever); corrigido.
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
- **Falha real encontrada e corrigida (revisão de segurança):** o caminho de
  render do card (`Dashboard::getCards()`/`renderEmbedWidget()`) nunca
  checava o direito do plugin nem o escopo de entidade da `Connection` dona
  antes desta revisão — qualquer usuário que pudesse ver qualquer dashboard
  nativo do GLPI (um direito muito mais amplo e comum que o do plugin)
  enxergava o conteúdo de BI embedado, mesmo sem nenhum direito no plugin.
  Corrigido centralizando a checagem em
  `DashboardItem::isVisibleForCurrentUser()`, chamada nos dois pontos.
- **Visibilidade restrita por card (`is_private`):** além do direito geral
  do módulo, cada `DashboardItem` pode ser restrito a Perfil/Grupo/Usuário/
  Entidade específicos (ver `ItemVisibility`, seção 8 do
  [guia de configuração](docs/CONFIGURACAO.md)) — mesmo modelo de
  compartilhamento que o GLPI usa nos próprios dashboards nativos
  (`Glpi\Dashboard\Dashboard::checkRights()`). Sem nenhuma regra configurada,
  um card marcado como restrito fica invisível para todo mundo (nega por
  padrão, não abre por padrão).
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
- **Usuário/conta dedicada em cada ferramenta de BI (pesquisado contra a
  documentação oficial de ambas):**
  - **Grafana:** o *service account token* configurado só autentica as
    chamadas de API do *backend* do plugin (`/api/health`, `/api/search`) —
    o `<iframe>` que embeda o dashboard é uma requisição direta do navegador
    de cada usuário do GLPI para o Grafana, **sem** esse token. Sem
    configuração adicional no Grafana (`auth.anonymous`, converter o
    dashboard para *Shared/Public dashboard*, ou SSO/sessão já
    compartilhada), cada usuário do GLPI cai na tela de login do Grafana
    dentro do card — ver seção 4 do
    [guia de configuração](docs/CONFIGURACAO.md) para as opções.
  - **Power BI, modo "secure":** é o padrão oficial da Microsoft
    ["embed for your customers"](https://learn.microsoft.com/power-bi/developer/embedded/embed-sample-for-customers) —
    usuários do GLPI **não precisam de conta nem licença do Power BI**; só o
    *service principal* precisa de acesso ao workspace, atrás de uma
    capacity (qualquer SKU A/EM/P/F, não precisa ser Premium/F64+
    especificamente).
  - **Power BI, modo "publish to web":** o oposto — nenhuma conta é
    necessária porque o conteúdo é público para qualquer pessoa com o link
    (por isso o aviso de segurança fixo na UI).

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
