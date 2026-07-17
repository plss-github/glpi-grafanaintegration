# Analytic Design — Guia de Configuração

Tutorial passo a passo para instalar, ativar e configurar o plugin depois que
ele já está copiado em `glpi/plugins/analyticdesign`. Testado ponta a ponta
contra GLPI 11.0.8.

## 1. Instalar e ativar o plugin

Pela interface web:

1. **Setup > Plugins**.
2. Localizar "Analytic Design" e clicar em **Instalar**.
3. Depois de instalado, clicar em **Ativar**.

Ou via linha de comando (dentro do container/servidor, na raiz do GLPI):

```
php bin/console plugin:install -u glpi analyticdesign
php bin/console plugin:activate analyticdesign
```

> Ao atualizar o plugin para uma versão nova, repita os dois comandos (o
> `plugin:install` roda de novo o hook de instalação/migração e o GLPI marca
> o plugin como desativado nesse tipo de atualização; `plugin:activate`
> reativa em seguida).

### Ambiente de desenvolvimento via Docker

O repositório inclui um `docker-compose.yml` que sobe GLPI 11.0.8 (imagem
oficial `glpi/glpi`) + MariaDB, com a pasta do plugin montada — só para
instalar e testar localmente (não é config de produção):

```
cp .env.example .env
docker compose up -d
# aguardar a instalação automática do GLPI (alguns minutos na 1ª vez)
docker compose exec glpi php bin/console plugin:install -u glpi analyticdesign
docker compose exec glpi php bin/console plugin:activate analyticdesign
```

Acessar `http://localhost:8080` (login padrão pós-instalação: `glpi` / `glpi`
— trocar a senha antes de qualquer uso além do teste local).

## 2. Conceder o direito do plugin a outros perfis (se necessário)

Na instalação, o plugin já **concede acesso completo automaticamente ao(s)
perfil(is) Super-Admin** — quem instala já consegue usar sem passo extra.
Para liberar o plugin a outros perfis (ex.: uma equipe específica que só
administra as fontes, ou um perfil que só deve visualizar):

1. **Administração > Perfis**, abrir o perfil desejado.
2. Uma aba própria **"Análise de Dados"** aparece no formulário do perfil
   (junto com Ativos, Assistência, Administração etc.) — não é preciso
   procurar dentro de outra seção genérica.
3. Marcar as permissões desejadas na linha "Análise de Dados: fontes e
   dashboards": **Ler** (só visualizar/testar), **Atualizar**, **Criar**,
   **Apagar** — marcar só "Ler" equivale a um perfil "somente leitura"; marcar
   tudo equivale a "gerenciamento completo". A coluna "Marcar/Desmarcar
   todos" facilita liberar tudo de uma vez.
4. Salvar.
5. Se o usuário já estava logado, ele precisa **sair e entrar de novo** (ou
   esperar a atualização de direitos da sessão) para a mudança valer.

## 3. Cadastrar uma fonte Grafana

O cadastro é em dois passos: primeiro o básico (nome/ferramenta/ativo), depois
as específicações da ferramenta escolhida numa aba própria — a URL e as
credenciais do Grafana não fazem sentido perguntar antes de saber que a fonte
é um Grafana.

1. **Administração > Análise de Dados > Fontes de dados > Adicionar novo
   item**.
2. Preencher só:
   - **Nome**: um nome livre para identificar a fonte (ex.: "Grafana
     Produção").
   - **Ferramenta**: `Grafana`. O dropdown nasce vazio ("-----") — é
     obrigatório escolher explicitamente antes de salvar.
   - **Ativo**: `Sim` ou `Não` (nasce em `Não`).
3. Salvar — a tela recarrega já na fonte criada, agora com abas.
4. Abrir a aba **"Características"** e preencher:
   - **URL base**: a URL da instância, ex. `https://grafana.suaempresa.com`
     (sem barra no final).
   - **API Token / Service account token**: um *service account token* do
     Grafana com permissão de leitura de dashboards (Grafana > Administration
     > Service accounts).

> ⚠️ **Importante — esse token não dá acesso aos usuários do GLPI ao
> dashboard embedado.** Ele só autentica as chamadas do *backend* do plugin
> (`GET /api/health`, `GET /api/search`) — o `<iframe>` que renderiza o
> dashboard de fato é uma requisição direta do navegador de cada usuário do
> GLPI para o Grafana, sem carregar esse token. Isso significa que, sem
> configuração adicional no Grafana, cada usuário do GLPI vai ver a tela de
> login do Grafana dentro do card em vez do dashboard. As opções (documentação
> oficial do Grafana):
> - **`[auth.anonymous]`** no `grafana.ini`: libera acesso sem login para
>   todo mundo que alcançar o Grafana, numa org/role fixos — simples, mas
>   vale para a instância/org inteira, não só para os dashboards deste
>   plugin.
> - **Shared/Public dashboards** (Grafana ≥ 9.1, disponível também na versão
>   open source): converte um dashboard específico para acesso público sem
>   login — a opção mais alinhada ao que este plugin embeda; nesse caso, a
>   URL colada aqui/importada deve ser a URL pública do dashboard, não a URL
>   interna. Tem limitações (kiosk mode fixo, sem variáveis de template,
>   sem anotações que não sejam nativas do Grafana).
> - Se os usuários do GLPI já têm login próprio no Grafana (SSO comum, ou
>   sessão de navegador já aberta), o iframe também funciona sem nada extra.
>
> `allow_embedding: true` (abaixo) só permite que o Grafana seja carregado
> dentro de um `<iframe>` (cabeçalho `X-Frame-Options`) — não resolve, sozinho,
> a questão de autenticação acima.
5. Salvar (botão **Salvar** da própria aba) e clicar em **Testar conexão**,
   ao lado (chama `GET /api/health` no Grafana) — deve responder "Conexão
   bem-sucedida.". Se falhar, os campos de configuração somem e só a
   mensagem de erro fica visível — clicar em **"Editar configuração"** para
   reabri-los e corrigir. Nesse caso, confirmar:
   - que o Grafana tem `allow_embedding: true` na seção `[security]` do
     `grafana.ini` (necessário para o iframe funcionar depois, mesmo que o
     teste de conexão em si não dependa disso);
   - que a URL base está correta e acessível a partir do servidor do GLPI
     (não do seu navegador — a chamada é feita pelo backend);
   - que o token tem permissão de leitura.

   Vale testar deliberadamente com uma URL/token errados uma vez, só para
   confirmar que a tela reage como esperado (campos escondidos + mensagem +
   botão "Editar configuração"), sem travar nem mostrar stack trace.

## 4. Importar dashboards do Grafana

Na aba **"Dashboards"** do registro salvo (aparece assim que a Connection é
criada):

1. A seção **"Dashboards disponíveis na fonte"** lista automaticamente os
   dashboards encontrados via API do Grafana (`GET /api/search?type=dash-db`).
2. Marcar os que devem virar cards, opcionalmente preencher uma **Categoria**
   por linha (ex.: "Ativos", "Indicadores gerais" — vira o agrupamento do
   card no catálogo de widgets do dashboard nativo).
3. Clicar em **Importar selecionados**.
4. Os itens aparecem em **"Dashboards importados"**, onde dá para ajustar
   categoria/ativo depois e salvar.

Se a listagem falhar (fonte fora do ar, token errado), a tela mostra apenas o
aviso — resolva a conexão na aba "Características" (passo 5 acima) e volte
aqui.

> A aba **"Dashboards"** é só um pré-visualizador: lista o que já foi
> importado (com um botão **"Ver"** por linha, que abre o card renderizado
> numa aba nova) e o que está disponível para importar na fonte. Cadastro
> manual, visibilidade e substituição de módulo (seções 6, 8 e 9) ficam na
> aba **"Características"**, em **"Configurações do dashboard"**.

## 5. Cadastrar uma fonte Power BI — modo "Embed seguro"

Uso recomendado para dados sensíveis. Este é o padrão oficial da Microsoft
chamado **"embed for your customers"** (ver
[documentação](https://learn.microsoft.com/power-bi/developer/embedded/embed-sample-for-customers)):
usuários do GLPI **não precisam de nenhuma conta ou licença do Power BI** —
só o *service principal* (aplicativo registrado no Entra ID) precisa de
acesso ao workspace. É necessária **alguma capacity** (SKU A/EM/P/F — não
precisa ser especificamente Premium/F64+; esse patamar maior só é exigido
para outros cenários de embed, como "embed for your organization") por trás
do workspace para uso em produção.

1. No **Entra ID** (Azure AD): registrar um aplicativo, gerar um **client
   secret** (não é preciso configurar nenhuma permissão de API no
   registro — a Microsoft recomenda explicitamente não adicionar nenhuma).
   No **admin portal do Power BI** (Configurações do locatário >
   Configurações do desenvolvedor), habilitar **"Embed content in apps"** e
   **"Service principals can call Fabric public APIs"**, e garantir que o
   service principal é **Membro** (ou Admin) do workspace de destino.
2. Anotar: **Tenant ID**, **Client ID**, **Client secret**, e o **Workspace
   ID** (GUID do workspace/group — está na URL do workspace no Power BI).
3. **Administração > Análise de Dados > Fontes de dados > Adicionar**:
   preencher só **Nome**, **Ferramenta** (`Power BI` — o dropdown nasce
   vazio, escolha obrigatória) e **Ativo** (nasce `Não`), e salvar (o modo de
   embed já nasce como "Embed seguro" por padrão para uma fonte Power BI
   nova — ajustável na aba seguinte).
4. Na fonte recém-criada, abrir a aba **"Características"**:
   - **Modo de embed**: confirmar `Embed seguro — Entra ID / Premium (Power BI)`.
   - Preencher Tenant ID, Client ID, Client secret e Workspace ID.
5. Salvar e clicar em **Testar conexão** (autentica no Entra ID e chama
   `GET /v1.0/myorg/groups` para verificar acesso ao workspace).
6. Aba **"Dashboards"**: os relatórios do workspace aparecem em "Dashboards
   disponíveis na fonte" (`GET /v1.0/myorg/groups/{id}/reports`) — importar
   normalmente.
7. Ao adicionar o card num dashboard do GLPI, o container
   `.analyticdesign-powerbi-secure` é hidratado pelo `powerbi-client`
   (`public/js/analyticdesign-powerbi.js`) usando um embed token gerado no
   render (`POST .../GenerateToken`) — vale conferir no DevTools que o
   relatório carrega e que não há token nenhum salvo em cookies/localStorage
   (só no atributo `data-` do próprio container, de curta duração).

> O embed token é gerado a cada carregamento do card (validade ~1h, nunca
> fica salvo). Se o dashboard ficar aberto na tela por mais de uma hora sem
> recarregar, é esperado que o card pare de atualizar — recarregar a página
> resolve.

Vale também testar deliberadamente com credenciais inválidas ou um workspace
sem acesso, para confirmar que os erros do Entra ID/Power BI aparecem como
mensagem, sem stack trace exposta ao usuário final.

## 6. Cadastrar uma fonte Power BI — modo "Publish to web"

⚠️ **Este modo deixa o conteúdo acessível a qualquer pessoa com o link, sem
login nenhum.** Só usar para dados que já seriam aceitáveis de tornar
públicos. O plugin exibe um aviso vermelho fixo nesse modo — não é possível
escondê-lo.

1. No Power BI (Desktop ou serviço): **Arquivo > Publicar na Web**, copiar a
   URL pública gerada para o relatório desejado.
2. Numa fonte Power BI (nova ou já existente), abrir a aba
   **"Características"** e trocar **Modo de embed** para `Publish to web —
   URL pública (Power BI)` — o aviso vermelho aparece imediatamente, antes
   mesmo de salvar. Este modo não usa nenhuma credencial (os campos de
   Tenant/Client/Workspace somem).
3. Salvar. Como a API do Power BI **não expõe** as URLs de publish-to-web,
   não há listagem automática — usar, na própria aba **"Características"**,
   a seção **"Configurações do dashboard"**:
   - **Nome**: nome livre para o card.
   - **Categoria**: opcional, define o agrupamento no catálogo de widgets.
   - **URL de embed**: a URL pública copiada do Power BI.
   - **Visibilidade**: opcional — ver seção 8 abaixo para restringir quem
     pode ver este card especificamente.
4. Salvar — o aviso de segurança aparece de novo nesta tela, como lembrete.

## 7. Posicionar os cards num dashboard do GLPI

Isso usa o sistema **nativo** de dashboards do GLPI — nenhuma tela extra do
plugin.

1. Ir a qualquer dashboard do GLPI (ex.: **Central**, ou os de Ativos/
   Assistência).
2. Entrar no modo de edição do dashboard (ícone de lápis/engrenagem, conforme
   a versão).
3. Abrir o catálogo de widgets e localizar os cards do plugin — aparecem
   agrupados pela **Categoria** definida na importação (ou em "Analytic
   Design", se a categoria ficou em branco).
4. Arrastar o card para a grade, posicionar/redimensionar como qualquer outro
   widget do GLPI.
5. Sair do modo de edição — o card deve renderizar o iframe do dashboard
   externo.

> **Cache:** dashboards novos aparecem no catálogo de widgets assim que
> importados, sem precisar limpar cache (os cards de plugins não são
> cacheados pelo GLPI — só os widgets nativos são). Se mesmo assim um card
> não aparecer, `php bin/console cache:clear` resolve na grande maioria dos
> casos.

## 8. Restringir quem vê um dashboard específico (visibilidade)

Por padrão, qualquer usuário com o direito de leitura do módulo (seção 2) vê
todos os cards ativos. Para restringir um card específico (ex.: um dashboard
financeiro que só o time Financeiro deve ver, mesmo que outros usuários
tenham o direito geral do plugin):

1. Abrir o card já importado — **Administração > Análise de Dados >
   Dashboards expostos** na busca geral, clicar no nome do item — ou
   configurar já na criação, na aba **"Características" > "Configurações do
   dashboard"**.
2. No campo **Visibilidade**, trocar de **"Todos com acesso ao módulo"**
   (padrão) para **"Restrito a..."**.
3. Um segundo campo aparece — buscar e adicionar **Perfil**, **Grupo**,
   **Usuário** e/ou **Entidade** (pode combinar vários; basta casar com um
   deles para ver o card — mesmo modelo de "compartilhamento" que o próprio
   GLPI usa para seus dashboards nativos).
4. Salvar.

> Um card marcado "Restrito a..." sem nenhum alvo adicionado fica invisível
> para todo mundo (inclusive quem tem o direito geral do plugin) — é o
> comportamento esperado (nega por padrão), não um bug; adicione ao menos um
> alvo para o card voltar a aparecer para alguém.

A restrição vale tanto para o catálogo de widgets (o card nem aparece para
adicionar) quanto para um card já posicionado num dashboard — desativar um
card (**Ativo** = `Não`) também para de renderizá-lo imediatamente, mesmo
que já esteja posicionado em algum dashboard.

Essa mesma restrição (Perfil/Grupo/Usuário/Entidade) é o pré-requisito para
**substituir o Dashboard nativo de um módulo** por este card — ver seção 9.

## 9. Substituir o dashboard nativo de um módulo

Além de virar um card avulso (seção 7), um dashboard exposto pode
**substituir inteiramente a tela "Dashboard" de um módulo do GLPI** para um
público específico. Exemplo: um dashboard com todos os indicadores de ativos
do Setor X, e um Grupo do GLPI que representa esse setor — todo usuário desse
Grupo passa a ver esse dashboard automaticamente ao abrir **Ativos >
Dashboard**, no lugar do dashboard nativo padrão.

Módulos suportados: **Ativos**, **Assistência**, **Gerência**,
**Ferramentas** e **Administração**. O módulo **Configurar** não é oferecido
— não é um módulo com uma tela de dashboard.

> **Ativos** e **Assistência** já têm uma tela "Dashboard" nativa no GLPI —
> a substituição troca o que aparece nela. **Gerência**, **Ferramentas** e
> **Administração** não têm essa tela por padrão; o plugin cria uma e só
> adiciona o link **"Dashboard"** no menu desses módulos quando existe, para
> o usuário atual, uma substituição ativa configurada — sem isso, o menu
> desses módulos continua exatamente como hoje.

Para configurar:

1. Abrir o card (**Administração > Análise de Dados > Dashboards expostos**,
   ou a aba **"Características" > "Configurações do dashboard"** ao
   cadastrar um novo).
2. Marcar **Visibilidade** como **"Restrito a..."** e adicionar ao menos um
   Perfil/Grupo/Usuário/Entidade (seção 8) — **obrigatório**: só é possível
   substituir o dashboard de um módulo para um público explícito e restrito,
   nunca para "todos com acesso ao módulo em geral". Tentar salvar sem isso
   reverte o campo abaixo para "Não substituir" e mostra uma mensagem
   explicando o motivo.
3. Em **"Substituir dashboard do módulo"**, escolher o módulo desejado (ou
   "Não substituir" para desligar).
4. Salvar.

> **A mudança só vale a partir do próximo login.** A checagem de qual
> dashboard mostrar para cada usuário roda uma vez por sessão — quem já
> estava logado quando a configuração foi criada/alterada precisa sair e
> entrar de novo para ver o efeito (mesma exigência da seção 2, para
> direitos de perfil).

> Se mais de uma configuração ativa mirar o mesmo módulo e o mesmo usuário
> (regras de visibilidade sobrepostas), vale a primeira cadastrada — não é
> um erro, mas evite sobreposição intencional para não depender dessa ordem.

## 10. Arquitetura e riscos de integração

Decisões relevantes para quem for manter ou estender o plugin:

- **`front/` + `ajax/` clássico, não Controllers.** O padrão Controller
  (roteamento por atributos) do GLPI 11 é o alvo recomendado a médio prazo,
  mas o padrão clássico é o único garantidamente funcional em qualquer
  11.0.8+ sem reescrever todo o roteamento.
- **Formulários em PHP/HTML puro**, não Twig — `showFormHeader()`/
  `showFormButtons()` + tabelas `tab_cadre_fixe`/grid (`HasFormFieldLayout`)
  são API madura e estável em todo o GLPI, ao custo de não reaproveitar os
  templates Twig do core.
- **Contrato do hook de dashboard** (`getCards()`/`provider`/`args`, ver
  docblock de `src/Dashboard.php`) é o ponto de integração mais específico e
  menos estável usado por este plugin — o mais provável de mudar numa versão
  futura do GLPI. O registro desses hooks em `setup.php` é condicional
  (`defined(Hooks::DASHBOARD_TYPES/...)`): se o contrato mudar de novo, só a
  integração com o dashboard nativo para, sem afetar CRUD/menu/assets do
  resto do plugin.
- **Substituição do Dashboard nativo de um módulo** (`ModuleDashboard`, seção
  9) não usa nenhum mecanismo nativo do GLPI para "forçar um dashboard para
  um Grupo" — porque ele não existe. A substituição funciona sobrepondo a
  "última visualização" da sessão (`$_SESSION['last_dashboards']`, o mesmo
  mecanismo que o GLPI usa para lembrar o último dashboard que você viu numa
  tela) e espelhando a regra de visibilidade do card no sistema nativo de
  compartilhamento de dashboards (`Glpi\Dashboard\Right`) — é por isso que a
  mudança só vale a partir do próximo login (a sessão é onde a sobreposição
  vive) e por que a visibilidade precisa ser explícita (só um conjunto
  enumerável de Perfil/Grupo/Usuário/Entidade pode ser espelhado; "todos com
  acesso ao módulo" não é um conjunto enumerável).
- **Direitos do plugin na tela de Perfis:** a matriz "nativa" de direitos de
  um perfil não tem ponto de extensão para plugins, então o plugin usa
  `Plugin::registerClass(ProfileRights::class, ['addtabon' =>
  Profile::class])` para adicionar sua própria aba "Análise de Dados" ao
  perfil — mesmo mecanismo que o core usa internamente para outras
  extensões.
- **Resiliência a atualizações do GLPI:** `plugin_analyticdesign_check_config()`
  e `plugin_analyticdesign_check_prerequisites()` (`setup.php`) verificam em
  runtime, antes da ativação, que as dependências do plugin ainda existem —
  se algo for removido/renomeado numa atualização futura, a ativação falha
  com mensagem clara em vez do plugin quebrar em produção.
- **`plugin_analyticdesign_install()` precisa continuar idempotente.** O
  GLPI chama essa função de novo em toda mudança de
  `PLUGIN_ANALYTICDESIGN_VERSION` (não só na primeira instalação, também em
  cada atualização de versão) — qualquer `INSERT` sem checagem prévia
  (`countElementsInTable()` ou equivalente) quebra a reativação com erro de
  chave duplicada.
- **Testado ponta a ponta** contra GLPI 11.0.8 real via Docker (ver seção
  "Ambiente de desenvolvimento via Docker" acima): instalação, ativação,
  CRUD, importação, visibilidade, substituição de módulo e o card
  renderizando de fato num dashboard.

## 11. Segurança

- **CSRF:** plugin `CSRF_COMPLIANT`. A validação em si **não** é feita
  chamando `Session::checkCSRF()` no código do plugin — no GLPI 11, o kernel
  já valida e **consome** o token `_glpi_csrf_token` automaticamente para
  toda requisição não-GET, antes do script rodar, seguindo o mesmo padrão do
  core (nenhum `front/*.php` do core chama `Session::checkCSRF()`). O JS
  continua enviando `_glpi_csrf_token` no corpo do `fetch()` para satisfazer
  essa checagem automática.
- **Credenciais:** criptografadas em repouso via `GLPIKey`, nunca em texto
  plano; nunca retornam ao navegador (campos de senha sempre em branco no
  formulário); update parcial faz merge com as credenciais já salvas, em vez
  de sobrescrever tudo; o campo `credentials` vindo direto do `$_POST` bruto
  é sempre descartado — só o bloco de criptografia pode populá-lo.
- **Autorização (IDOR/entidades):** todo endpoint usa `$connection->can($id,
  RIGHT)` (direito **e** escopo de entidade), não apenas checagem global de
  direito — evita que um usuário atue sobre registros de outra entidade só
  adivinhando o ID. `DashboardItem` (sem entidade própria) é autorizado
  através da `Connection` pai. O render do card
  (`DashboardItem::isVisibleForCurrentUser()`) aplica quatro camadas, todas
  obrigatórias: `is_active`, direito de leitura do módulo, escopo de
  entidade da `Connection` dona e, se privado, `ItemVisibility`.
- **Visibilidade restrita por card (`is_private`):** além do direito geral
  do módulo, cada `DashboardItem` pode ser restrito a Perfil/Grupo/Usuário/
  Entidade específicos (seção 8) — mesmo modelo de compartilhamento que o
  GLPI usa nos próprios dashboards nativos
  (`Glpi\Dashboard\Dashboard::checkRights()`). Sem nenhuma regra
  configurada, um card marcado como restrito fica invisível para todo mundo
  (nega por padrão, não abre por padrão).
- **XSS:** toda saída passa por `htmlspecialchars(..., ENT_QUOTES)`;
  `buildIframe()` só renderiza URLs `http`/`https` (bloqueia `javascript:`/
  `data:` em `embed_url`); iframe usa `sandbox` e
  `referrerpolicy="no-referrer"`.
- **Power BI (embed seguro):** embed token de curta duração (~1h), gerado a
  cada render e nunca persistido; `accessLevel: 'View'` (somente leitura);
  `tenant_id`/`client_id`/`workspace_id`/`report_id` validados como GUID
  antes de compor URLs ou chamar a API.
- **"Publish to web":** aviso obrigatório, fixo e em destaque na UI nos dois
  pontos onde a URL pública é definida (modo da `Connection` e
  "Configurações do dashboard") — esse conteúdo fica acessível a qualquer
  pessoa com o link, sem autenticação, por natureza do recurso do Power BI.
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
- **Usuário/conta dedicada em cada ferramenta de BI** (pesquisado contra a
  documentação oficial de ambas):
  - **Grafana:** o *service account token* configurado só autentica as
    chamadas de API do *backend* do plugin (`/api/health`, `/api/search`) —
    o `<iframe>` que embeda o dashboard é uma requisição direta do navegador
    de cada usuário do GLPI para o Grafana, **sem** esse token. Sem
    configuração adicional no Grafana (ver seção 3), cada usuário do GLPI
    cai na tela de login do Grafana dentro do card.
  - **Power BI, modo "secure":** padrão oficial da Microsoft ["embed for
    your customers"](https://learn.microsoft.com/power-bi/developer/embedded/embed-sample-for-customers) —
    usuários do GLPI **não precisam de conta nem licença do Power BI**; só o
    *service principal* precisa de acesso ao workspace, atrás de uma
    capacity (qualquer SKU A/EM/P/F).
  - **Power BI, modo "publish to web":** o oposto — nenhuma conta é
    necessária porque o conteúdo é público para qualquer pessoa com o link
    (por isso o aviso de segurança fixo na UI).

## Solução de problemas

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Menu/telas do plugin não aparecem, ou "Acesso negado" | Direito não concedido ao perfil (perfis diferentes do Super-Admin não recebem acesso automático) | Ver passo 2 acima (aba "Análise de Dados" dentro do perfil); sair e entrar de novo depois de salvar |
| "Testar conexão" falha e os campos da aba "Características" somem | Comportamento esperado (não é erro) — a falha esconde os campos e mostra só a mensagem | Clicar em "Editar configuração" para reabrir os campos e corrigir; confirmar que o servidor do GLPI (não seu navegador) alcança a URL configurada |
| Card aparece vazio/quebrado no dashboard | Política de CSP da instância GLPI, ou `X-Frame-Options`/CSP do Grafana/Power BI bloqueando ser enquadrado por outra origem | Verificar `allow_embedding` no Grafana; checar CSP da instância GLPI (fora do controle do plugin) |
| Card do Grafana pede login em vez de mostrar o dashboard | Comportamento esperado — o service account token só autentica as chamadas de API do backend, não o `<iframe>` do navegador (ver seção 4) | Habilitar `auth.anonymous` no Grafana, converter o dashboard para "Shared/Public dashboard", ou usar um Grafana com SSO/sessão já compartilhada |
| Card não aparece no catálogo de widgets depois de importar | Cache do GLPI (raro — cards de plugin normalmente não são cacheados) | `php bin/console cache:clear` |
| Card configurado como "Restrito a..." não aparece para ninguém | Nenhum alvo (Perfil/Grupo/Usuário/Entidade) foi adicionado — comportamento esperado, nega por padrão | Editar o card (seção 8) e adicionar ao menos um alvo de visibilidade |
| "Publish to web" com aviso vermelho | Comportamento esperado, não é erro | Não usar esse modo para dados confidenciais |
| Campo "Substituir dashboard do módulo" volta para "Não substituir" ao salvar | Visibilidade não estava em "Restrito a..." ou não tinha nenhum alvo adicionado (seção 9) | Marcar "Restrito a..." e adicionar ao menos um alvo antes de escolher o módulo |
| "Dashboard" de um módulo não mudou depois de configurar a substituição | A sobreposição é aplicada uma vez por sessão (seção 9) | Sair e entrar de novo |
| Botão "Ver" (pré-visualizar) na aba "Dashboards" não mostra nada / dá acesso negado | O card está inativo, ou o usuário logado não passa em `isVisibleForCurrentUser()` (mesma checagem do render real) | Confirmar **Ativo** = `Sim` e, se restrito, que o usuário atual casa com algum alvo de visibilidade |
