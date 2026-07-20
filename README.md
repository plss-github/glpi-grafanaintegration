# Analytic Design

Plugin GLPI **11.0.8+** que integra dashboards de ferramentas externas de BI
(**Grafana** e **Power BI**, nos dois modos de embed) ao sistema nativo de
dashboards do GLPI.

Adiciona uma aba **"Análise de Dados"** em **Administração** onde se cadastram as
fontes; cada dashboard exposto vira um **card** que o admin posiciona em qualquer
grade do GLPI (principal, ativos, assistência...) pelo modo de edição nativo —
ou, opcionalmente, **substitui** a tela "Dashboard" nativa de um módulo inteiro
para um público restrito (Perfil/Grupo/Usuário/Entidade).

📘 **Guia de configuração passo a passo:** [docs/CONFIGURACAO.md](docs/CONFIGURACAO.md).
As decisões de arquitetura, riscos de integração com o GLPI e o modelo de
segurança completo (antes descritos aqui) foram movidos para lá — ver
[seção "Arquitetura e riscos de integração"](docs/CONFIGURACAO.md#11-arquitetura-e-riscos-de-integração)
e [seção "Segurança"](docs/CONFIGURACAO.md#12-segurança). A aba
**"Visibilidade"** (regras de Critérios/Ação, alternativa ao ajuste direto
por card) está documentada na
[seção 10](docs/CONFIGURACAO.md#10-restringir-visibilidade-por-regras-aba-visibilidade).

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
│   └── deletedashboarditem.php   # remove por completo um dashboard exposto (JSON, fetch())
├── src/
│   ├── Connection.php        # CommonDBTM: fontes cadastradas + showForm() (Nome/Ferramenta/Status + Comentários + URL/token do Grafana)
│   ├── ConnectionCharacteristics.php # aba "Configurações" (Power BI: URL/embed_mode/credenciais; ambos: config. do dashboard)
│   ├── DashboardItem.php     # CommonDBTM: dashboards expostos + aba "Pré-Visualização" (somente-leitura) na Connection
│   ├── Dashboard.php         # hooks getTypes/getCards + provider + render do widget
│   ├── VisibilityRule.php    # CommonDBTM: regras de Critérios/Ação por Connection (aba "Visibilidade", ver seção 10 do guia) — `is_private` do DashboardItem é recalculado a partir daqui
│   ├── ConnectionVisibilityRules.php # aba "Visibilidade": lista as regras, cada uma renderizada inline (Critérios/Ação editáveis na própria aba)
│   ├── ModuleDashboard.php   # substituição do Dashboard nativo de um módulo (ver seção 9 do guia)
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
│       ├── HasCheckboxField.php          # helper HTML compartilhado (formulários em PHP puro)
│       └── HasFormFieldLayout.php        # helper de layout de campo (grid Twig do GLPI 11)
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
