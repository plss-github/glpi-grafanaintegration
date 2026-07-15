# Analytic Design — Guia de Configuração

Tutorial passo a passo para instalar, ativar e configurar o plugin depois que
ele já está copiado em `glpi/plugins/analyticdesign` (ou montado via
`docker-compose.yml` — ver README). Testado ponta a ponta contra GLPI 11.0.8.

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
   - **Ferramenta**: `Grafana`. O dropdown nasce vazio ("Selecione uma
     ferramenta") — é obrigatório escolher explicitamente antes de salvar.
   - **Ativo**: `Sim` ou `Não` (nasce em `Não`).
3. Salvar — a tela recarrega já na fonte criada, agora com abas.
4. Abrir a aba **"Características"** e preencher:
   - **URL base**: a URL da instância, ex. `https://grafana.suaempresa.com`
     (sem barra no final).
   - **API Token / Service account token**: um *service account token* do
     Grafana com permissão de leitura de dashboards (Grafana > Administration
     > Service accounts).
5. Salvar (botão **Salvar** da própria aba) e clicar em **Testar conexão**,
   ao lado — deve responder "Conexão bem-sucedida.". Se falhar, os campos de
   configuração somem e só a mensagem de erro fica visível — clicar em
   **"Editar configuração"** para reabri-los e corrigir. Nesse caso,
   confirmar:
   - que o Grafana tem `allow_embedding: true` na seção `[security]` do
     `grafana.ini` (necessário para o iframe funcionar depois, mesmo que o
     teste de conexão em si não dependa disso);
   - que a URL base está correta e acessível a partir do servidor do GLPI
     (não do seu navegador — a chamada é feita pelo backend);
   - que o token tem permissão de leitura.

## 4. Importar dashboards do Grafana

Na aba **"Dashboards"** do registro salvo (aparece assim que a Connection é
criada):

1. A seção **"Dashboards disponíveis na fonte"** lista automaticamente os
   dashboards encontrados via API do Grafana.
2. Marcar os que devem virar cards, opcionalmente preencher uma **Categoria**
   por linha (ex.: "Ativos", "Indicadores gerais" — vira o agrupamento do
   card no catálogo de widgets do dashboard nativo).
3. Clicar em **Importar selecionados**.
4. Os itens aparecem em **"Dashboards importados"**, onde dá para ajustar
   categoria/ativo depois e salvar.

Se a listagem falhar (fonte fora do ar, token errado), a tela mostra um aviso
e cai automaticamente na seção **"Adicionar manualmente"** abaixo — dá para
colar a URL de embed de um dashboard específico à mão.

## 5. Cadastrar uma fonte Power BI — modo "Embed seguro"

Uso recomendado para dados sensíveis (requer licença/capacity **Premium** no
workspace do Power BI).

1. No **Entra ID** (Azure AD): registrar um aplicativo, gerar um **client
   secret**, e no **admin portal do Power BI**, garantir que o service
   principal tem acesso ao workspace (como membro, ou habilitando "Service
   principals can use Fabric APIs").
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
5. Salvar e clicar em **Testar conexão** (autentica no Entra ID e verifica
   acesso ao workspace).
6. Aba **"Dashboards"**: os relatórios do workspace aparecem em "Dashboards
   disponíveis na fonte" — importar normalmente.

> O embed token é gerado a cada carregamento do card (validade ~1h, nunca
> fica salvo). Se o dashboard ficar aberto na tela por mais de uma hora sem
> recarregar, é esperado que o card pare de atualizar — recarregar a página
> resolve.

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
   não há listagem automática — usar a aba **"Dashboards" > "Adicionar
   manualmente"**:
   - **Nome**: nome livre para o card.
   - **Categoria**: opcional, define o agrupamento no catálogo de widgets.
   - **URL de embed**: a URL pública copiada do Power BI.
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

## Solução de problemas

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Menu/telas do plugin não aparecem, ou "Acesso negado" | Direito não concedido ao perfil (perfis diferentes do Super-Admin não recebem acesso automático) | Ver passo 2 acima (aba "Análise de Dados" dentro do perfil); sair e entrar de novo depois de salvar |
| "Testar conexão" falha e os campos da aba "Características" somem | Comportamento esperado (não é erro) — a falha esconde os campos e mostra só a mensagem | Clicar em "Editar configuração" para reabrir os campos e corrigir; confirmar que o servidor do GLPI (não seu navegador) alcança a URL configurada |
| Card aparece vazio/quebrado no dashboard | Política de CSP da instância GLPI, ou `X-Frame-Options`/CSP do Grafana/Power BI bloqueando ser enquadrado por outra origem | Verificar `allow_embedding` no Grafana; checar CSP da instância GLPI (fora do controle do plugin) |
| Card não aparece no catálogo de widgets depois de importar | Cache do GLPI (raro — cards de plugin normalmente não são cacheados) | `php bin/console cache:clear` |
| "Publish to web" com aviso vermelho | Comportamento esperado, não é erro | Não usar esse modo para dados confidenciais |
