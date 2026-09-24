# Pellissari Grafana Integration — Guia de Configuração

Tutorial passo a passo para instalar, ativar e configurar o plugin depois que
ele já está copiado em `glpi/plugins/plugingrafanaintegration` (a chave
técnica do plugin — ver nota abaixo sobre o nome da pasta). Testado ponta a
ponta contra GLPI 11.0.8.

> **Nome da pasta vs. chave técnica do plugin:** o GLPI exige que o nome da
> pasta dentro de `glpi/plugins/` seja exatamente a chave técnica do plugin
> em minúsculas, sem espaços (usada nos nomes de função `plugin_*_*()`, na
> constante `PLUGIN_*_VERSION` e no namespace PHP) — aqui, `plugingrafanaintegration`.
> O repositório de código-fonte deste projeto pode estar numa pasta com nome
> livre (ex.: `Pellissari Grafana Integration`), mas **ao instalar numa
> instância GLPI real, o conteúdo precisa estar em uma pasta chamada
> `plugingrafanaintegration`** dentro de `glpi/plugins/` — renomeie/copie a
> pasta (ou crie um link simbólico) na hora do deploy.

## 1. Instalar e ativar o plugin

Pela interface web:

1. **Setup > Plugins**.
2. Localizar "Pellissari Grafana Integration" e clicar em **Instalar**.
3. Depois de instalado, clicar em **Ativar**.

Ou via linha de comando (dentro do container/servidor, na raiz do GLPI):

```
php bin/console plugin:install -u glpi plugingrafanaintegration
php bin/console plugin:activate plugingrafanaintegration
```

> Ao atualizar o plugin para uma versão nova, repita os dois comandos (o
> `plugin:install` roda de novo o hook de instalação/migração e o GLPI marca
> o plugin como desativado nesse tipo de atualização; `plugin:activate`
> reativa em seguida).

### Ambiente de desenvolvimento via Docker

Suba localmente um GLPI 11.0.8 (imagem oficial `glpi/glpi`) + MariaDB, com a
pasta do plugin montada, usando um `docker-compose.yml` próprio (não
versionado — não é config de produção, e cada dev ajusta ao seu ambiente):

```
cp .env.example .env
docker compose up -d
# aguardar a instalação automática do GLPI (alguns minutos na 1ª vez)
docker compose exec glpi php bin/console plugin:install -u glpi plugingrafanaintegration
docker compose exec glpi php bin/console plugin:activate plugingrafanaintegration
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

O cadastro é em dois passos, na **mesma aba "Fonte de Dados"**: primeiro o
básico (nome/ferramenta/status), lado a lado numa única linha; depois de
salvar, a URL e o token do Grafana aparecem logo abaixo, na mesma tela — não
fazia sentido perguntá-los antes de saber que a fonte é um Grafana.

1. **Administração > Análise de Dados > Fontes de Dados > Adicionar novo
   item**.
2. Preencher (os três campos ficam lado a lado):
   - **Nome**: um nome livre para identificar a fonte (ex.: "Grafana
     Produção").
   - **Ferramenta**: `Grafana`. O dropdown nasce vazio ("-----") — é
     obrigatório escolher explicitamente antes de salvar.
   - **Status**: `Sim` ou `Não` (nasce em `Não`).
   - Um campo **Comentários** (opcional, texto livre, uma caixa compacta de
     duas linhas) fica logo abaixo, para anotações internas — não é
     interpretado pelo plugin.
3. Salvar — a tela recarrega já na fonte criada, agora com abas, e a aba
   **"Fonte de Dados"** já mostra dois campos novos:
   - **URL base**: a URL da instância, ex. `https://grafana.suaempresa.com`
     (sem barra no final).
   - **API Token / Service account token**: um *service account token* do
     Grafana com permissão de leitura de dashboards (Grafana > Administration
     > Service accounts). O campo tem o mesmo visual "revelável" da chave de
     licença do GLPI Network (segurar o ícone de olho mostra o valor em
     texto puro; o de clipe copia); depois de salvo, o campo mostra um
     placeholder de bolinhas (nunca o valor real) para indicar que já existe
     um token configurado. O botão **"Testar conexão"** fica na mesma linha
     dos botões **Salvar**/**Excluir permanentemente**, ao final do
     formulário — aparece quando já existe token salvo, ou assim que algo é
     digitado no campo — testar sem token não faz sentido.

> Se o **Status** for alterado para "Não" (fonte desativada), a seção
> inteira de URL base/API Token some da tela — volta a aparecer ao reativar.
> Os valores continuam salvos, só ficam escondidos enquanto a fonte está
> desativada.

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
5. Salvar (botão **Salvar** da própria tela) e clicar em **Testar conexão**,
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

Na aba **"Configurações"** do registro salvo (aparece assim que a Connection
é criada), seção **"Configurações do dashboard"** (vem primeiro na aba, antes
da tabela de itens já importados — ver abaixo):

1. O dropdown **"Dashboard"** (lado a lado com **Módulo**) lista
   automaticamente os dashboards encontrados via API do Grafana
   (`GET /api/search?type=dash-db`) que ainda não foram importados.
2. Escolher um e, opcionalmente, um **Módulo** (agrupamento do card no
   catálogo de widgets do dashboard nativo).
3. Clicar em **Importar**.
4. O item passa a aparecer na tabela **"Dashboards importados"**, logo
   abaixo (separada por uma linha divisória), onde dá para ajustar módulo/
   status depois, e na aba **"Pré-Visualização"**, de onde dá para
   pré-visualizar o card antes de decidir onde posicioná-lo.

Repita para cada dashboard — é sempre um por vez, junto com o módulo.
**Visibilidade** (quem vê o card) é configurada depois, numa aba própria —
ver seção 8 — já que uma regra sempre escolhe o dashboard a partir dos já
importados.

Se a listagem falhar (fonte fora do ar, token errado), a tela mostra o
formulário manual (nome + URL de embed colados à mão) em vez do dropdown —
resolva a conexão na aba "Fonte de Dados" (passo 5 acima) e volte aqui.

> Só quem tem direito de **Atualizar** nesta fonte (seção 2) vê o formulário
> de importação por completo — com apenas **Ler**, a seção mostra um aviso
> ("Você não tem direito de editar esta fonte de dados.") em vez do
> formulário, para não deixar preencher tudo só para levar "Acesso negado"
> ao clicar em Importar.

Logo abaixo do formulário de importação (separada por uma linha divisória),
a aba **"Configurações"** também mostra uma **tabela "Dashboards
importados"** com todos os itens já cadastrados naquela fonte — cada linha
tem **Módulo**, **Status** e **Substituir dashboard do módulo** (seção 7)
editáveis direto na tabela (um botão **Salvar** grava todos de uma vez) e um
botão **Remover** que apaga o item por completo (pede confirmação; some da
tabela sem recarregar a página). Remover é definitivo — se o item estivesse
substituindo o dashboard nativo de um módulo (seção 7), desfaz essa
substituição também (as regras de visibilidade da aba "Visibilidade", seção
10, continuam existindo — elas não referenciam o item por ID, e sim pelo
nome do dashboard, então removê-lo só faz a regra parar de casar com nada).

> A coluna **"Substituir dashboard do módulo"** só fica editável quando o
> item já tem uma regra na aba **"Visibilidade"** (seção 8) concedendo
> acesso a pelo menos um Perfil/Grupo/Usuário/Entidade específico — sem
> isso, mostra "Indisponível". Ver seção 7 para o motivo.

> A aba **"Pré-Visualização"** (antiga "Dashboards expostos") é hoje **só**
> um pré-visualizador somente-leitura: colunas Nome, ID externo, Módulo e
> **Pré-visualizar** (um botão **"Ver"** por linha que abre o card
> renderizado numa aba nova) — essa pré-visualização **ignora
> deliberadamente** a regra de visibilidade configurada (seção 8): quem
> chega até essa aba já tem direito de administrar a fonte, então não faz
> sentido a própria pessoa que configurou o card ficar bloqueada de vê-lo.
> Escolher o que importar, ajustar módulo/status, remover e substituição de
> módulo ficam na aba **"Configurações"**; visibilidade (quem vê cada
> dashboard) fica na aba **"Visibilidade"** (seção 8).

## 5. Posicionar os cards num dashboard do GLPI

Isso usa o sistema **nativo** de dashboards do GLPI — nenhuma tela extra do
plugin.

1. Ir a qualquer dashboard do GLPI (ex.: **Central**, ou os de Ativos/
   Assistência).
2. Entrar no modo de edição do dashboard (ícone de lápis/engrenagem, conforme
   a versão).
3. Abrir o catálogo de widgets e localizar os cards do plugin — aparecem
   agrupados pelo **Módulo** definido na importação (ou em "Analytic
   Design", se ficou em "Nenhum").
4. Arrastar o card para a grade, posicionar/redimensionar como qualquer outro
   widget do GLPI.
5. Sair do modo de edição — o card deve renderizar o iframe do dashboard
   externo.

> **Cache:** dashboards novos aparecem no catálogo de widgets assim que
> importados, sem precisar limpar cache (os cards de plugins não são
> cacheados pelo GLPI — só os widgets nativos são). Se mesmo assim um card
> não aparecer, `php bin/console cache:clear` resolve na grande maioria dos
> casos.

## 6. Restringir quem vê um dashboard específico (visibilidade)

Por padrão, qualquer usuário com o direito de leitura do módulo (seção 2) vê
todos os cards ativos — um dashboard importado nasce público. Para
restringir um card específico (ex.: um dashboard financeiro que só o time
Financeiro deve ver, mesmo que outros usuários tenham o direito geral do
plugin), cadastre uma **regra de visibilidade** na aba **"Visibilidade"** da
fonte — ver seção 8 para o passo a passo completo.

Resumo: uma regra escolhe **qual(is) dashboard(s)** ela alcança (Critérios)
e **quem** ganha acesso a eles (Ação — Perfil/Grupo/Usuário/Entidade
específicos, ou "Todos os usuários"). Um dashboard sem nenhuma regra
apontando pra ele continua público; assim que pelo menos uma regra o alcança,
ele só fica visível para quem essa regra conceder acesso.

A restrição vale tanto para o catálogo de widgets (o card nem aparece para
adicionar) quanto para um card já posicionado num dashboard — desativar um
card (**Status** = `Não`, aba "Configurações") também para de renderizá-lo
imediatamente, mesmo que já esteja posicionado em algum dashboard.

Ter pelo menos uma regra concedendo acesso a um Perfil/Grupo/Usuário/Entidade
específico (não só "Todos os usuários") é o pré-requisito para **substituir
o Dashboard nativo de um módulo** por este card — ver seção 7.

## 7. Substituir o dashboard nativo de um módulo

Além de virar um card avulso (seção 5), um dashboard exposto pode
**substituir inteiramente a tela "Dashboard" de um módulo do GLPI** para um
público específico. Exemplo: um dashboard com todos os indicadores de ativos
do Setor X, e um Grupo do GLPI que representa esse setor — todo usuário desse
Grupo passa a ver esse dashboard automaticamente ao abrir **Ativos >
Dashboard**, no lugar do dashboard nativo padrão.

Módulos suportados: **Ativos**, **Assistência**, **Gerência** e
**Ferramentas**. **Administração** e **Configurar** não são oferecidos —
não fazia sentido substituir a tela de administração do próprio GLPI por um
dashboard externo.

> Não confundir com o campo **"Módulo"** da seção 4 (era "Categoria"): aquele
> só agrupa o card no catálogo de widgets — cosmético, não exige nada. Este
> aqui, **"Substituir dashboard do módulo"**, troca de fato a tela
> "Dashboard" do módulo escolhido para quem a regra de visibilidade conceder
> acesso — por isso exige uma regra com pelo menos um alvo explícito
> (Perfil/Grupo/Usuário/Entidade — "Todos os usuários" não conta, ver seção
> 10).

> **Ativos** e **Assistência** já têm uma tela "Dashboard" nativa no GLPI —
> a substituição troca o que aparece nela. **Gerência** e **Ferramentas**
> não têm essa tela por padrão; o plugin cria uma e só adiciona o link
> **"Dashboard"** no menu desses módulos quando existe, para o usuário
> atual, uma substituição ativa configurada — sem isso, o menu desses
> módulos continua exatamente como hoje.

Para configurar:

1. Na aba **"Visibilidade"** da fonte (seção 8), cadastrar (ou já ter) uma
   regra cujo Critério seja este dashboard e cuja Ação conceda acesso a pelo
   menos um Perfil/Grupo/Usuário/Entidade específico — **obrigatório**: só é
   possível substituir o dashboard de um módulo para um público explícito,
   nunca para "todos com acesso ao módulo em geral" nem para uma Ação
   "Todos os usuários".
2. Na aba **"Configurações"**, na tabela **"Dashboards importados"**, a
   coluna **"Substituir dashboard do módulo"** desse item passa de
   "Indisponível" para um dropdown de módulos — escolher o desejado (ou
   "Não substituir" para desligar) e clicar em **Salvar**.

> Se a regra que concedia o acesso explícito for removida ou perder seu
> último alvo concreto depois, "Substituir dashboard do módulo" é desligado
> automaticamente (volta pra "Não substituir") — não é preciso lembrar de
> desfazer isso manualmente.

> **A mudança só vale a partir do próximo login.** A checagem de qual
> dashboard mostrar para cada usuário roda uma vez por sessão — quem já
> estava logado quando a configuração foi criada/alterada precisa sair e
> entrar de novo para ver o efeito (mesma exigência da seção 2, para
> direitos de perfil).

> Se mais de uma configuração ativa mirar o mesmo módulo e o mesmo usuário
> (regras de visibilidade sobrepostas), vale a primeira cadastrada — não é
> um erro, mas evite sobreposição intencional para não depender dessa ordem.

## 8. Restringir visibilidade por regras (aba "Visibilidade")

A aba **"Visibilidade"** da fonte é o único lugar onde se configura quem vê
cada dashboard. Por padrão (sem nenhuma regra), um dashboard importado é
público — visível a qualquer usuário com o direito de leitura do módulo
(seção 2). Cadastrar uma **regra** ali restringe um ou mais dashboards a um
público específico: de um lado **Critérios** (quais dashboards a regra
alcança), do outro **Ação** (quem ganha acesso) — o mesmo modelo conceitual
das Regras de negócio nativas do GLPI (lista de critérios/ações com uma
linha de "adicionar" no rodapé de cada), mas uma implementação própria e
simples do plugin (não uma subclasse de `Rule`/`RuleCollection` do core).
Útil quando a mesma condição de acesso (ex.: "todo dashboard cujo nome
contenha 'Suporte' deve ser visto pelo Grupo Suporte N1") deve valer para
vários cards sem configurar cada um manualmente.

Tudo acontece **dentro da própria aba** — nenhuma navegação para uma tela
separada; cada ação (adicionar/remover regra, critério ou ação) recarrega a
página de volta nesta mesma aba.

1. Abrir a fonte e ir à aba **"Visibilidade"** (existe em toda fonte, mesmo
   sem nenhuma regra cadastrada ainda).
2. Clicar em **"Adicionar regra"** — cria uma regra vazia (sem nome nem
   status: uma regra é identificada pelos próprios Critérios, e existir já
   significa estar ativa — removê-la é a forma de "desligá-la").
3. Em **Critérios**, na linha de "adicionar" no rodapé da lista: escolher
   **Campo** (`Dashboard` ou `Módulo`), **Condição** (`é` ou `contém`) e um
   **Valor** — quando Campo é `Dashboard` e Condição é `é`, o Valor vira um
   **dropdown com os dashboards já importados daquela fonte** (em vez de
   texto livre); para `contém` (ou Campo `Módulo` com `é`), o Valor
   correspondente aparece no lugar. Clicar em **Adicionar** grava a linha —
   repetir para mais critérios. Cada linha já salva tem um botão para
   remover.
4. Escolher **"Combinar critérios com"**: `E` (todos os critérios precisam
   casar) ou `OU` (qualquer um já basta) — só importa com 2+ critérios.
5. Em **Ação**, na linha de "adicionar" no rodapé: escolher **"Conceder
   acesso a"** — `Perfil`, `Grupo`, `Usuário`, `Entidade` (um dropdown de
   busca aparece para escolher qual) ou **"Todos os usuários"** (libera
   geral para quem casar com os Critérios, sem precisar listar ninguém).
   Clicar em **Adicionar** — repetir para mais ações (basta casar com uma
   delas). Cada linha já salva tem um botão para remover.

Exemplo (o mesmo do pedido original): Critério `Dashboard` `é` `"[TV] Kali"`;
Ação `Grupo` = "Suporte N1", `ou` `Entidade` = "Cliente X", `ou` `Perfil` =
"Atendente" — qualquer usuário que pertença ao Grupo Suporte N1, **ou**
esteja na Entidade Cliente X, **ou** tenha o Perfil Atendente, passa a ver
esse dashboard.

> **Uma regra sem nenhum Critério nunca casa com nada** (nega por padrão) —
> não é um bug, é para evitar que uma regra "vazia" acidentalmente libere
> acesso geral a um dashboard não intencional.

> **`is_private` (se o card está restrito) nunca é digitado em formulário —
> é sempre calculado.** Assim que uma regra é criada/alterada/removida, o
> plugin recalcula automaticamente: um dashboard com pelo menos uma regra
> cujos Critérios casem com ele fica restrito (só quem a Ação conceder o vê);
> sem nenhuma regra apontando pra ele, fica público de novo. Se **mais de
> uma** regra casar com o mesmo dashboard, basta **uma delas** conceder
> acesso ao usuário (OR entre regras).

> As regras ficam escopadas por fonte — uma regra cadastrada na aba
> "Visibilidade" de uma Connection só é avaliada para os dashboards
> **daquela mesma fonte**, nunca de outra.

## 9. Arquitetura e riscos de integração

### Visão geral

```
Dashboard (hook GLPI)  ──►  SourceFactory  ──►  DashboardSourceInterface
                                                   └── GrafanaSource      (Fase 1 ✅)
```

O hook de card nunca sabe qual ferramenta está por trás. Adicionar uma nova
ferramenta = nova implementação da interface + 1 linha na `SourceFactory`.

### Estrutura de arquivos

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
│   ├── dashboarditem.form.php # edição pontual (nome/módulo/URL/status)
│   ├── previewdashboarditem.php  # pré-visualização isolada de um card (aba "Pré-Visualização")
│   ├── visibilityrule.form.php   # processa os POSTs da aba "Visibilidade" (add/remover regra, critério, ação) — sem GET/display próprio, sempre redireciona de volta pra aba
│   ├── dashboard_management.php  # "Dashboard" de Gerência, quando há substituição ativa
│   └── dashboard_tools.php       # idem, Ferramentas
├── ajax/
│   ├── testconnection.php        # testa a conexão de uma fonte salva (JSON)
│   ├── importselecteddashboard.php # cria DashboardItem a partir do dropdown de dashboards disponíveis
│   ├── addmanualdashboard.php    # cria DashboardItem a partir de URL colada manualmente (fallback sem listagem)
│   ├── updatedashboarditems.php  # salva edição em lote (módulo/ativo/substituição de módulo)
│   ├── deletedashboarditem.php   # remove por completo um dashboard exposto (JSON, fetch())
│   └── getvisibilityactionvalue.php # devolve o dropdown de Valor (Perfil/Grupo/Usuário/Entidade) sob demanda, aba "Visibilidade"
├── src/
│   ├── Connection.php        # CommonDBTM: fontes cadastradas + showForm() (Nome/Ferramenta/Ativo + Comentários + URL/token do Grafana)
│   ├── ConnectionCharacteristics.php # aba "Configurações": config. do dashboard (seleção/importação)
│   ├── DashboardItem.php     # CommonDBTM: dashboards expostos + aba "Pré-Visualização" (somente-leitura) na Connection
│   ├── Dashboard.php         # hooks getTypes/getCards + provider + render do widget
│   ├── VisibilityRule.php    # CommonDBTM: regras de Critérios/Ação por Connection (aba "Visibilidade", ver seção 8) — `is_private` do DashboardItem é recalculado a partir daqui
│   ├── ConnectionVisibilityRules.php # aba "Visibilidade": lista as regras, cada uma renderizada inline (Critérios/Ação editáveis na própria aba)
│   ├── ModuleDashboard.php   # substituição do Dashboard nativo de um módulo (ver seção 7)
│   ├── Menu.php              # entrada em Administração
│   ├── ProfileRights.php     # aba "Análise de Dados" em Administração > Perfis
│   ├── Client/
│   │   └── GrafanaClient.php  # client da API REST do Grafana
│   ├── Source/
│   │   ├── DashboardSourceInterface.php  # o contrato comum + constante de embed_mode
│   │   ├── AbstractDashboardSource.php   # helpers (iframe, credenciais)
│   │   ├── GrafanaSource.php             # implementação Grafana (Fase 1)
│   │   └── SourceFactory.php             # resolve type -> implementação
│   └── Traits/
│       ├── HasCheckboxField.php          # helper HTML compartilhado (formulários em PHP puro)
│       └── HasFormFieldLayout.php        # helper de layout de campo (grid Twig do GLPI 11)
├── locales/
│   └── analyticdesign.pot    # template de tradução (gettext)
└── public/
    ├── js/
    │   └── analyticdesign.js           # "Testar conexão" + aba "Visibilidade" + gerenciamento de itens
    └── css/analyticdesign.css
```

### Decisões e riscos

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
- **`VisibilityRule` (aba "Visibilidade", seção 8) é uma implementação
  própria do plugin, não uma subclasse de `Rule`/`RuleCollection` do core.**
  Decisão deliberada: o motor de Regras nativo do GLPI é poderoso mas pouco
  documentado/mais arriscado de integrar corretamente numa primeira versão;
  três tabelas próprias (`..._visibilityrules`, `..._criteria`, `..._actions`)
  e uma avaliação simples em PHP (`VisibilityRule::matchesItem()`/
  `grantsCurrentUser()`) bastam para o caso de uso pedido (Critérios de
  Dashboard/Módulo combinados por E/OU; Ação = Perfil/Grupo/Usuário/Entidade
  — `Dropdown::show($itemtype, ...)`, o helper genérico "escolher 1 item de
  um itemtype" do próprio core — ou o alvo especial `GRANT_ALL`, "Todos os
  usuários"). `is_private` do `DashboardItem` não é mais um campo editável:
  é recalculado (`VisibilityRule::resyncAffectedItems()`) sempre que uma
  regra muda — 1 quando pelo menos uma regra casa com o item, 0 (público)
  quando nenhuma casa.
- **Embed via `<iframe>`, não "renderizar via API": decisão deliberada,
  revisitada e mantida em 2026-07-21.**
  A pergunta natural é: em vez de embutir a UI do Grafana, o plugin não
  deveria chamar a API do Grafana para buscar os dados brutos e desenhar o
  gráfico com os próprios widgets do GLPI? Decisão: não, pelos seguintes
  motivos:
  - **Reimplementar renderização de gráficos é o tipo de retrabalho que este
    plugin evita por princípio** (mesma lógica de não subclassear
    `Rule`/`RuleCollection` na aba "Visibilidade", seção 8): o Grafana já
    resolve consulta a datasource, cache, drill-down, filtros e tema —
    reconstruir uma fração disso com dados crus da API é um projeto por si só,
    frágil a cada mudança de painel na ferramenta de origem, sem pedido
    concreto do usuário para justificar o custo.
  - **Limitação real, documentada e avisada na UI** (ver
    `Connection::showGrafanaCredentialsSection()`): o token salvo só
    autentica as chamadas do *backend* do plugin (testar conexão, listar
    dashboards); o `<iframe>` em si é uma requisição direta
    navegador-do-usuário→Grafana, sem token nenhum. Sem uma das três saídas
    verificadas contra a documentação oficial do Grafana — (a)
    `auth.anonymous` habilitado no `grafana.ini`, (b) o dashboard convertido
    em "Public dashboard" (≥9.1, só modo kiosk, sem variáveis de template),
    ou (c) o usuário já ter sessão/SSO próprio no Grafana — o iframe mostra a
    tela de login do Grafana em vez do gráfico. **O Grafana não tem uma API
    de embed-token** (confirmado contra a documentação oficial), então não há
    como fechar esse gap com "mais backend". A alternativa real seria trocar
    de estratégia inteiramente para esse caso — chamar `/render/d/:uid`
    (plugin `grafana-image-renderer`) e devolver uma **imagem estática**
    (`<img>`, sem interatividade, sem drill-down, precisa recarregar
    periodicamente para não ficar desatualizada) — descartada por depender de
    um plugin externo do Grafana nem sempre instalado, degradar a experiência
    (perde toda a interatividade que o iframe tem hoje) e não ter sido
    pedida por ninguém; documentada aqui como opção conhecida e
    conscientemente não implementada, não como lacuna esquecida.
- **Cache de JS/CSS do plugin é por versão, não por conteúdo.** O `?v=` que
  o GLPI anexa a `public/js/analyticdesign.js`/`public/css/analyticdesign.css`
  (`Html::script()`/`Html::css()` → `Plugin::getPluginFilesVersion()`) é
  derivado só de `PLUGIN_PLUGINGRAFANAINTEGRATION_VERSION` — editar esses arquivos
  sem bump de versão faz o navegador de quem já visitou a página continuar
  servindo a cópia antiga do cache, indefinidamente, mesmo com o arquivo já
  atualizado no servidor. Sempre bump a versão (mesmo um patch) ao mexer em
  JS/CSS; se um comportamento de UI "não aparece" só para alguns usuários,
  suspeitar de cache de navegador antes de suspeitar do código.
- **Direitos do plugin na tela de Perfis:** a matriz "nativa" de direitos de
  um perfil não tem ponto de extensão para plugins, então o plugin usa
  `Plugin::registerClass(ProfileRights::class, ['addtabon' =>
  Profile::class])` para adicionar sua própria aba "Análise de Dados" ao
  perfil — mesmo mecanismo que o core usa internamente para outras
  extensões.
- **Resiliência a atualizações do GLPI:** `plugin_plugingrafanaintegration_check_config()`
  e `plugin_plugingrafanaintegration_check_prerequisites()` (`setup.php`) verificam em
  runtime, antes da ativação, que as dependências do plugin ainda existem —
  se algo for removido/renomeado numa atualização futura, a ativação falha
  com mensagem clara em vez do plugin quebrar em produção.
- **`plugin_plugingrafanaintegration_install()` precisa continuar idempotente.** O
  GLPI chama essa função de novo em toda mudança de
  `PLUGIN_PLUGINGRAFANAINTEGRATION_VERSION` (não só na primeira instalação, também em
  cada atualização de versão) — qualquer `INSERT` sem checagem prévia
  (`countElementsInTable()` ou equivalente) quebra a reativação com erro de
  chave duplicada.
- **Testado ponta a ponta** contra GLPI 11.0.8 real via Docker (ver seção
  "Ambiente de desenvolvimento via Docker" acima): instalação, ativação,
  CRUD, importação, visibilidade, substituição de módulo e o card
  renderizando de fato num dashboard.

## 10. Segurança

- **CSRF:** plugin `CSRF_COMPLIANT`. A validação em si **não** é feita
  chamando `Session::checkCSRF()` no código do plugin — no GLPI 11, o kernel
  (`Glpi\Kernel\Listener\ControllerListener\CheckCsrfListener`) já valida
  automaticamente para toda requisição não-GET, antes do script rodar,
  seguindo o mesmo padrão do core (nenhum `front/*.php` do core chama
  `Session::checkCSRF()`). Os formulários clássicos (`<form method="post">`,
  fechados sempre com `Html::closeForm()`, nunca `echo "</form>"` cru — ver
  histórico de bug corrigido em 2026-07-20) mandam `_glpi_csrf_token` no
  corpo, validado e **consumido** da sessão (`preserve_token: false`).
  - **Achado em 2026-07-21, corrigido**: `Session::getNewCSRFToken()` usa uma
    global (`$CURRENTCSRFTOKEN`) reaproveitada por TODOS os
    `Html::closeForm()` chamados no mesmo carregamento de página/aba — ou
    seja, vários formulários renderizados juntos (ex.: aba "Configurações":
    importar, adicionar manual, tabela de gerenciamento) compartilham o
    MESMO token. Isso por si só é inofensivo (é assim que o core inteiro
    funciona) — o problema é quando uma ação em `fetch()` que **não recarrega
    a página** (`testConnection()`/`deleteDashboardItem()`, em
    `public/js/analyticdesign.js`) manda esse mesmo token no CORPO do POST:
    sem o header `X-Requested-With: XMLHttpRequest`, o kernel não reconhece a
    chamada como AJAX (`Request::isXmlHttpRequest()` checa só esse header) e
    cai no branch que **consome** o token — invalidando, sem aviso, qualquer
    OUTRO formulário ainda aberto na mesma aba (ex.: registrar um dashboard,
    removê-lo, tentar registrar outro — o "remover" consumia o token que o
    "adicionar" ainda ia usar, resultando em
    `Glpi\Exception\Http\AccessDeniedHttpException`). Corrigido enviando
    `X-Requested-With: XMLHttpRequest` + `X-Glpi-Csrf-Token` (lido da mesma
    tag `<meta property="glpi:csrf_token">` que o `common.js` do core usa)
    como HEADERS nessas duas chamadas — o kernel passa a validar pelo branch
    de AJAX (`preserve_token: true`), que não consome o token da sessão. Não
    precisou mudar nenhum endpoint em `ajax/*.php`: a checagem inteira
    acontece no kernel, antes do script do plugin rodar.
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
  entidade da `Connection` dona e, se privado (`is_private` — sempre
  calculado, nunca digitado, ver seção 8), casar com pelo menos uma regra
  de `VisibilityRule` que conceda acesso ao usuário atual. A pré-visualização
  (aba "Pré-Visualização", `isPreviewableByCurrentUser()`) usa só as três
  primeiras — ignora `VisibilityRule` de propósito (ver seção 4) —, então
  nunca vaza um card fora da entidade/direito do módulo, só relaxa a regra
  fina de "para quem" o card foi restrito.
- **Visibilidade restrita por card (`is_private`):** além do direito geral
  do módulo, cada `DashboardItem` pode ficar restrito a Perfil/Grupo/Usuário/
  Entidade específicos, via uma regra de `VisibilityRule` (seção 8/6) — mesmo
  modelo de compartilhamento que o GLPI usa nos próprios dashboards nativos
  (`Glpi\Dashboard\Dashboard::checkRights()`). `is_private` é sempre
  calculado (nunca digitado): um item sem nenhuma regra apontando pra ele é
  público; uma regra que casa mas concede acesso a ninguém (nenhuma Ação, ou
  uma Ação que não inclui o usuário atual) deixa o item invisível para essa
  pessoa — nega por padrão, nunca abre por padrão.
- **XSS:** toda saída passa por `htmlspecialchars(..., ENT_QUOTES)`;
  `buildIframe()` só renderiza URLs `http`/`https` (bloqueia `javascript:`/
  `data:` em `embed_url`); iframe usa `sandbox` e
  `referrerpolicy="no-referrer"`.
- **SQL:** só via query builder do GLPI (`$DB->request()`,
  `CommonDBTM::add()/update()/getFromDB()`) — nenhuma concatenação de input
  em SQL cru.
- **Riscos aceitos / fora do controle do plugin:** SSRF via `base_url`
  configurada pelo admin do Grafana (inerente ao recurso — mitigado por
  exigir o direito administrativo do plugin); política de CSP/framing da
  instância e do Grafana (se restritiva, bloqueia o iframe — configuração
  externa, fora do escopo do plugin).
- **Usuário/conta dedicada no Grafana** (pesquisado contra a documentação
  oficial): o *service account token* configurado só autentica as chamadas
  de API do *backend* do plugin (`/api/health`, `/api/search`) — o
  `<iframe>` que embeda o dashboard é uma requisição direta do navegador de
  cada usuário do GLPI para o Grafana, **sem** esse token. Sem configuração
  adicional no Grafana (ver seção 3), cada usuário do GLPI cai na tela de
  login do Grafana dentro do card.

## Solução de problemas

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Menu/telas do plugin não aparecem, ou "Acesso negado" | Direito não concedido ao perfil (perfis diferentes do Super-Admin não recebem acesso automático) | Ver passo 2 acima (aba "Análise de Dados" dentro do perfil); sair e entrar de novo depois de salvar |
| "Testar conexão" falha e os campos de credenciais somem | Comportamento esperado (não é erro) — a falha esconde os campos e mostra só a mensagem | Clicar em "Editar configuração" para reabrir os campos e corrigir; confirmar que o servidor do GLPI (não seu navegador) alcança a URL configurada |
| Botão "Testar conexão" (Grafana) não aparece na aba "Fonte de Dados" | A fonte não tem token salvo ainda, e nada foi digitado no campo | Digitar/colar o token no campo — o botão fica visível permanentemente assim que existe um token salvo, mesmo depois de recarregar a página |
| Campo de API Token/credencial aparece vazio mesmo já configurado | Comportamento esperado — o valor real nunca é reenviado ao navegador por segurança | Olhar o placeholder: bolinhas (`••••••••••••`) indicam que já existe um valor salvo; deixar em branco para mantê-lo |
| Seção de URL/API do Grafana sumiu da aba "Fonte de Dados" | Comportamento esperado — some quando o **Status** da fonte é "Não" | Mudar Status para "Sim" para reexibir a seção (os valores continuam salvos) |
| Um ajuste de JS/CSS do plugin não parece ter efeito para alguns usuários | Cache do navegador — o `?v=` do arquivo é baseado na versão do plugin, não no conteúdo (ver seção 9) | Pedir para a pessoa recarregar a página com cache limpo (Ctrl+F5); confirmar que o plugin está na versão esperada |
| Na aba "Visibilidade", ao trocar Campo/Condição na linha de "adicionar critério" o Valor não muda para o dropdown certo | Quase sempre cache de navegador com uma cópia antiga de `public/js/analyticdesign.js` (ver linha acima) — o mecanismo foi testado e funciona com o JS atualizado | Confirmar que o plugin está na versão mais recente (reinstalar/reativar) e recarregar com Ctrl+F5 antes de reportar como bug |
| Card aparece vazio/quebrado no dashboard | Política de CSP da instância GLPI, ou `X-Frame-Options`/CSP do Grafana bloqueando ser enquadrado por outra origem | Verificar `allow_embedding` no Grafana; checar CSP da instância GLPI (fora do controle do plugin) |
| Card do Grafana (ou a pré-visualização) pede login em vez de mostrar o dashboard | Comportamento esperado — o service account token só autentica as chamadas de API do backend, não o `<iframe>` do navegador (ver seção 4); a tela de pré-visualização já mostra esse aviso quando a fonte é Grafana | Habilitar `auth.anonymous` no Grafana, converter o dashboard para "Shared/Public dashboard", ou usar um Grafana com SSO/sessão já compartilhada |
| Dropdown "Dashboard" na aba "Configurações" aparece vazio ou some (cai no formulário manual) | A fonte não respondeu à listagem, ou todos os dashboards já foram importados | Testar a conexão na aba "Fonte de Dados"/"Configurações"; se já importou tudo, é o comportamento esperado |
| Botão "Remover" (aba "Configurações") não aparece, ou o item continua na lista depois de confirmar | Sem direito de **Atualizar** no plugin, o botão nem aparece; se aparece mas falha, checar erro de rede no console do navegador | Confirmar direito de Atualizar (seção 2); tentar de novo — a remoção é via `fetch()`, sem recarregar a página |
| Aba "Visibilidade" da fonte não aparece, ou aparece vazia | Comportamento esperado se nenhuma regra foi cadastrada ainda — a aba sempre existe, só a lista fica vazia | Clicar em "Adicionar regra" para cadastrar a primeira |
| Regra em "Visibilidade" não parece ter efeito | Os Critérios da regra não casam com o dashboard esperado (nome/módulo digitado diferente do real), ou a Ação não inclui o usuário que está testando (Perfil/Grupo/Usuário/Entidade errado — "Todos os usuários" libera geral) | Conferir os Critérios (o dropdown de Dashboard só mostra os já importados) e a Ação da regra (seção 8) |
| Seção "Configurações do dashboard" mostra só um aviso de "sem direito" | O perfil atual só tem **Ler**, não **Atualizar**, no direito do plugin (seção 2) | Pedir para um perfil com direito de Atualizar conceder/ajustar o direito, ou logar com um usuário que já tenha |
| Card não aparece no catálogo de widgets depois de importar | Cache do GLPI (raro — cards de plugin normalmente não são cacheados) | `php bin/console cache:clear` |
| Um dashboard restrito por regra não aparece para ninguém | A regra que casa com ele não tem nenhuma Ação adicionada (ou nenhuma que inclua o usuário) — comportamento esperado, nega por padrão | Editar a regra (aba "Visibilidade", seção 8) e adicionar ao menos uma Ação |
| Campo "Substituir dashboard do módulo" volta para "Não substituir" ao salvar, ou aparece "Indisponível" | O item não tem nenhuma regra na aba "Visibilidade" concedendo acesso a um Perfil/Grupo/Usuário/Entidade específico (seção 7) — "Todos os usuários" não conta | Cadastrar (ou ajustar) uma regra com esse dashboard como Critério e uma Ação concreta antes de escolher o módulo |
| "Dashboard" de um módulo não mudou depois de configurar a substituição | A sobreposição é aplicada uma vez por sessão (seção 7) | Sair e entrar de novo |
| Botão "Ver" (pré-visualizar) na aba "Pré-Visualização" não mostra nada / dá acesso negado | O card está com Status "Não", ou o usuário logado não tem o direito/escopo de entidade do módulo (a visibilidade fina é ignorada de propósito nessa aba — ver seção 4) | Confirmar **Status** = `Sim` e que o usuário atual tem o direito do plugin na entidade da Connection |
