# Pellissari Grafana Integration — Especificação de Desenvolvimento

> Documento-prompt para desenvolvimento de um plugin GLPI 11.0.x que integra
> dashboards do Grafana ao sistema nativo de dashboards do GLPI. Pode ser
> entregue a um desenvolvedor ou usado como prompt para um agente de código.

---

## 1. Visão geral

**Nome do projeto:** Pellissari Grafana Integration
**Chave do plugin (diretório/namespace):** `plugingrafanaintegration`
**Alvo:** GLPI 11.0.x
**Licença:** AGPL-3.0
**Autor:** Pellissari
**Linguagem principal:** PHP (backend), Twig (templates), JS (interações da UI)

O plugin adiciona uma aba **"Análise de Dados"** em **Administração**, onde o
administrador cadastra conexões com o Grafana. Os dashboards dessa ferramenta
ficam disponíveis como **cards** no sistema nativo de dashboards do GLPI, e o
admin escolhe (pelo modo de edição nativo do GLPI) em qual grade/aba cada card
aparece.

## 2. Objetivo funcional

1. Cadastrar uma ou mais **fontes** Grafana.
2. Listar os dashboards disponíveis em cada fonte via API.
3. Marcar quais dashboards expor e opcionalmente atribuir uma **categoria**
   (ex.: "Ativos", "Assistência", "Indicadores gerais").
4. Cada dashboard exposto vira um **card** no catálogo de dashboards do GLPI.
5. O admin posiciona cada card na grade que quiser (principal, ativos,
   assistência etc.) usando o modo de edição nativo do GLPI — nenhuma UI
   customizada de posicionamento é necessária.
6. Visibilidade por perfil respeitada.

### Exemplo de uso alvo
Num Grafana com vários dashboards, o admin marca os dashboards de ativos com a
categoria "Ativos" e os posiciona na grade de dashboard de Ativos do GLPI; os
indicadores gerais vão para a grade principal. O usuário final, ao abrir cada
aba, vê os dashboards externos correspondentes.

## 3. Requisitos não-funcionais (GLPI 11)

- Usar **Controllers** (padrão moderno) em vez de arquivos soltos em `front/`
  e `ajax/`. Assets estáticos em `/public`.
- Endpoints sem sessão (se houver) registrados via
  `SessionManager::registerPluginStatelessPath()` no boot.
- **Proibido SQL cru** — usar os métodos do framework (query builder / iterator).
- Credenciais/segredos **criptografados** no banco (usar `GLPIKey`).
- Proteção CSRF/IDOR em todos os formulários e endpoints.
- i18n via `locales/` (gettext), textos com domínio `analyticdesign`.
- Respeitar separação por entidades quando aplicável.

## 4. Arquitetura — a decisão-chave

Todo o código que fala com o GLPI **não sabe** os detalhes da fonte de BI. Ele
conversa apenas com um contrato comum:

```
interface DashboardSourceInterface
    testConnection(): bool
    listDashboards(): DashboardDescriptor[]
    renderEmbed(item, context): string   // devolve HTML do embed
    getConfigFields(): array             // campos de config específicos da fonte
```

- **GrafanaSource** é a única implementação hoje.
- Uma **SourceFactory** resolve o tipo salvo (`grafana`) para a implementação
  correta — se uma nova fonte de BI precisar ser suportada no futuro, basta
  criar uma nova implementação e registrá-la ali.

O hook de card do GLPI só chama `renderEmbed()` na fonte dona do card.

## 5. Modelo de dados

**`glpi_plugin_plugingrafanaintegration_connections`** — as fontes cadastradas
- `id`
- `name`
- `type` (`grafana`)
- `base_url`
- `credentials` (JSON criptografado — API token)
- `embed_mode` (`iframe`)
- `is_active`
- entidade / datas padrão do CommonDBTM

**`glpi_plugin_plugingrafanaintegration_dashboarditems`** — dashboards expostos
- `id`
- `connections_id` (FK)
- `external_id` (id do dashboard na ferramenta)
- `name`
- `category` (dropdown de módulos do GLPI)
- `embed_url`
- `is_active`

## 6. Integração com o dashboard do GLPI

Registrar no `plugin_init_plugingrafanaintegration()`:

```php
$PLUGIN_HOOKS[Hooks::DASHBOARD_TYPES]['plugingrafanaintegration'] = Dashboard::class . '::getTypes';
$PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['plugingrafanaintegration'] = Dashboard::class . '::getCards';
```

- `getTypes()` registra um widget cujo render devolve o HTML do embed.
- `getCards()` percorre os dashboards ativos e devolve um card por dashboard
  exposto, agrupado pela categoria.
- O render do widget carrega o item, resolve a fonte via factory e chama
  `renderEmbed()`.

## 7. Embedding

Embedding por `<iframe>`. Requer `allow_embedding = true` no Grafana e uma
estratégia de auth do usuário final para acessar o Grafana diretamente
(login/SSO próprio, acesso anônimo, ou dashboard público) — o token
configurado na Connection só autentica as chamadas de backend do plugin
(testar conexão, listar dashboards), não o `<iframe>` em si.

## 8. Fases e critérios de aceitação

### Fase 1 — Grafana + abstração (entregável usável)
Escopo: scaffold, aba em Administração, CRUD de conexões, interface
`DashboardSourceInterface`, `GrafanaSource`, `SourceFactory`, integração de card.
**Aceite:** admin cadastra um Grafana, testa a conexão, marca dashboards, e eles
aparecem como cards posicionáveis nas grades do GLPI.

## 9. Fora de escopo (v1)
- Filtragem dinâmica dos dashboards externos pelo contexto do GLPI
  (entidade/usuário atual) — o v1 escolhe *qual* dashboard aparece em cada aba,
  não aplica filtros dinâmicos. Row-level security ficaria para uma fase futura.
- Sincronização automática/provisionamento de dashboards na ferramenta externa.
- Suporte a outras ferramentas de BI (a abstração via `DashboardSourceInterface`
  permite adicioná-las no futuro, mas nenhuma outra fonte é implementada hoje).

## 10. Riscos / pontos a validar
- Assinatura exata da API de widgets/cards no GLPI 11 (validado contra o
  GLPI 11.0.8 — ver docs/CONFIGURACAO.md).
- Política de CSP/iframe do GLPI para permitir framing de origens externas.
- Grafana: modelo de auth para embedding sem expor a instância.
