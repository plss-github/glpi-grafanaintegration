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
[seção "Arquitetura e riscos de integração"](docs/CONFIGURACAO.md#10-arquitetura-e-riscos-de-integração)
e [seção "Segurança"](docs/CONFIGURACAO.md#11-segurança).

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
│   ├── dashboarditem.form.php # edição pontual (visibilidade/substituição de módulo)
│   ├── previewdashboarditem.php  # pré-visualização isolada de um card (aba "Dashboards")
│   ├── dashboard_management.php  # "Dashboard" de Gerência, quando há substituição ativa
│   ├── dashboard_tools.php       # idem, Ferramentas
│   └── dashboard_admin.php       # idem, Administração
├── ajax/
│   ├── testconnection.php        # testa a conexão de uma fonte salva (JSON)
│   ├── importdashboards.php      # cria DashboardItem a partir da seleção listada
│   ├── addmanualdashboard.php    # cria DashboardItem a partir de URL colada manualmente
│   ├── updatedashboarditems.php  # salva edição em lote (categoria/ativo)
│   └── getvisibilitydropdownvalue.php # endpoint do AbstractRightsDropdown (Perfil/Grupo/Usuário/Entidade)
├── src/
│   ├── Connection.php        # CommonDBTM: fontes cadastradas + showForm() (Nome/Ferramenta/Ativo)
│   ├── ConnectionCharacteristics.php # aba "Características" (URL/embed_mode/credenciais/config. do dashboard)
│   ├── DashboardItem.php     # CommonDBTM: dashboards expostos + aba "Dashboards" (pré-visualizador) na Connection
│   ├── Dashboard.php         # hooks getTypes/getCards + provider + render do widget
│   ├── ItemVisibility.php    # regras de visibilidade (Perfil/Grupo/Usuário/Entidade) por DashboardItem
│   ├── VisibilityDropdown.php # UI do seletor de visibilidade (reaproveita AbstractRightsDropdown do GLPI)
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
