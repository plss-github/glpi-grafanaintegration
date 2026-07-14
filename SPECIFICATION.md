# Analytic Design by Pellissari — Especificação de Desenvolvimento

> Documento-prompt para desenvolvimento de um plugin GLPI 11.0.x que integra
> ferramentas externas de BI (Grafana e Power BI) ao sistema nativo de
> dashboards do GLPI. Pode ser entregue a um desenvolvedor ou usado como
> prompt para um agente de código.

---

## 1. Visão geral

**Nome do projeto:** Analytic Design by Pellissari
**Chave do plugin (diretório/namespace):** `analyticdesign`
**Alvo:** GLPI 11.0.x
**Licença:** GPL-2.0 (obrigatório para plugins GLPI)
**Linguagem principal:** PHP (backend), Twig (templates), JS (embed do Power BI seguro)

O plugin adiciona uma aba **"Análise de Dados"** em **Administração**, onde o
administrador cadastra conexões com ferramentas externas de BI. Os dashboards
dessas ferramentas ficam disponíveis como **cards** no sistema nativo de
dashboards do GLPI, e o admin escolhe (pelo modo de edição nativo do GLPI) em
qual grade/aba cada card aparece.

## 2. Objetivo funcional

1. Cadastrar uma ou mais **fontes** (Grafana e/ou Power BI).
2. Listar os dashboards disponíveis em cada fonte (via API quando possível).
3. Marcar quais dashboards expor e opcionalmente atribuir uma **categoria**
   (ex.: "Ativos", "Assistência", "Indicadores gerais").
4. Cada dashboard exposto vira um **card** no catálogo de dashboards do GLPI.
5. O admin posiciona cada card na grade que quiser (principal, ativos,
   assistência etc.) usando o modo de edição nativo do GLPI — nenhuma UI
   customizada de posicionamento é necessária.
6. Visibilidade por perfil respeitada.

### Exemplo de uso alvo
Num Power BI com vários relatórios, o admin marca os relatórios de ativos com a
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

Todo o código que fala com o GLPI **não sabe** qual ferramenta de BI está por
trás. Ele conversa apenas com um contrato comum:

```
interface DashboardSourceInterface
    testConnection(): bool
    listDashboards(): DashboardDescriptor[]
    renderEmbed(item, context): string   // devolve HTML do embed
    getConfigFields(): array             // campos de config específicos da fonte
```

- **GrafanaSource** é a primeira implementação (Fase 1).
- **PowerBiSource** é a segunda (Fase 2), plugando no mesmo ponto.
- Uma **SourceFactory** resolve o tipo salvo (`grafana` | `powerbi`) para a
  implementação correta.

O hook de card do GLPI só chama `renderEmbed()` na fonte dona do card. Assim,
adicionar Power BI é **aditivo**, não uma reescrita.

## 5. Modelo de dados

**`glpi_plugin_analyticdesign_connections`** — as fontes cadastradas
- `id`
- `name`
- `type` (`grafana` | `powerbi`)
- `base_url`
- `credentials` (JSON criptografado — token/API key, ou client_id/secret/tenant)
- `embed_mode` (`iframe` | `publish_to_web` | `secure`) — relevante ao Power BI
- `is_active`
- entidade / datas padrão do CommonDBTM

**`glpi_plugin_analyticdesign_items`** — dashboards expostos
- `id`
- `connections_id` (FK)
- `external_id` (id do dashboard/relatório na ferramenta)
- `name`
- `category` (texto livre ou dropdown)
- `embed_url` (quando publish-to-web/iframe direto)
- `is_active`

## 6. Integração com o dashboard do GLPI

Registrar no `plugin_init_analyticdesign()`:

```php
$PLUGIN_HOOKS[Hooks::DASHBOARD_TYPES]['analyticdesign'] = [Dashboard::class => 'getTypes'];
$PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['analyticdesign'] = [Dashboard::class => 'getCards'];
```

- `getTypes()` registra um widget `analyticdesign_embed` cujo render devolve o
  HTML do embed.
- `getCards()` percorre os `items` ativos e devolve um card por dashboard
  exposto, agrupado pela categoria.
- O render do widget carrega o item, resolve a fonte via factory e chama
  `renderEmbed()`.

> **A validar contra o código do GLPI 11:** a assinatura exata do array de
> widget/card e do callback de render. O scaffold traz a modelagem documentada;
> ajustar após testar numa instância real.

## 7. Embedding — modos suportados

### Grafana (Fase 1)
- Embedding por `<iframe>`. Requer `allow_embedding = true` no Grafana e uma
  estratégia de auth (anônima, proxy reverso, ou similar).
- API REST para listar dashboards e montar as URLs de embed.

### Power BI (Fase 2) — escolha explícita por fonte/dashboard
Toggle `embed_mode` na configuração:

1. **Publish to web** — cola-se a URL pública gerada pelo Power BI; o render
   devolve um iframe simples. Barato, reusa o padrão do Grafana.
   - ⚠️ **AVISO OBRIGATÓRIO NA UI:** conteúdo publicado assim fica acessível a
     **qualquer pessoa com o link, sem autenticação**. Não usar para dados
     confidenciais. Exibir alerta vermelho claro quando o admin selecionar
     este modo.
2. **Embed seguro** ("for your organization") — registro no Entra ID, service
   principal, geração de *embed token* no servidor, lib `powerbi-client` no
   front. Requer capacity/licença Premium (custo recorrente).

O toggle em si é trivial; o custo é ter as duas trilhas implementadas.

## 8. Fases e critérios de aceitação

### Fase 1 — Grafana + abstração (entregável usável)
Escopo: scaffold, aba em Administração, CRUD de conexões, interface
`DashboardSourceInterface`, `GrafanaSource`, `SourceFactory`, integração de card.
**Aceite:** admin cadastra um Grafana, testa a conexão, marca dashboards, e eles
aparecem como cards posicionáveis nas grades do GLPI.
**Estimativa:** ~2,5 a 3,5 semanas de dev.

### Fase 2 — Power BI (publish-to-web + embed seguro)
Escopo: `PowerBiSource` com os dois modos, toggle, UI de aviso, OAuth Entra ID,
embed token, powerbi-client.
**Aceite:** admin escolhe o modo por fonte; publish-to-web funciona com URL
pública (com aviso); embed seguro autentica via service principal e renderiza
relatório protegido.
**Estimativa:** ~2 a 2,5 semanas de dev.
- Publish to web: ~2-3 dias
- Embed seguro: ~8-10 dias
- Toggle + aviso + docs: ~1-2 dias

## 9. Fora de escopo (v1)
- Filtragem dinâmica dos dashboards externos pelo contexto do GLPI
  (entidade/usuário atual) — o v1 escolhe *qual* dashboard aparece em cada aba,
  não aplica filtros dinâmicos. Row-level security ficaria para uma fase futura.
- Sincronização automática/provisionamento de dashboards na ferramenta externa.

## 10. Riscos / pontos a validar
- Assinatura exata da API de widgets/cards no GLPI 11 (validar em instância real).
- Política de CSP/iframe do GLPI para permitir framing de origens externas.
- Grafana: modelo de auth para embedding sem expor a instância.
- Power BI: disponibilidade de licença Premium/capacity para embed seguro.
