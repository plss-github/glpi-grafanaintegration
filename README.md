# Pellissari Grafana Integration

Plugin GLPI **11.0.8+** que integra dashboards **Grafana** ao sistema nativo de
dashboards do GLPI.

Adiciona uma aba **"Análise de Dados"** em **Administração** onde se cadastram as
fontes; cada dashboard exposto vira um **card** que o admin posiciona em qualquer
grade do GLPI (principal, ativos, assistência...) pelo modo de edição nativo, e
também aparece automaticamente numa aba própria **"Grafana"** na tela inicial
(Central) do GLPI — no estilo do plugin Metabase — para quem o perfil libera.
O embed é autenticado por um **proxy reverso** (o navegador do usuário final
fala só com o GLPI, nunca direto com o Grafana), usando a sessão de um
usuário dedicado (Viewer) configurado na aba "Conexão" da fonte.

📘 **Guia de configuração passo a passo:** [docs/CONFIGURACAO.md](docs/CONFIGURACAO.md).
A arquitetura, a estrutura de arquivos, os riscos de integração com o GLPI e
o modelo de segurança completo (antes descritos aqui) foram movidos para lá —
ver [seção "Arquitetura e riscos de integração"](docs/CONFIGURACAO.md#9-arquitetura-e-riscos-de-integração)
e [seção "Segurança"](docs/CONFIGURACAO.md#10-segurança). A aba
**"Visibilidade"** (regras de Critérios/Ação, alternativa ao ajuste direto
por card) está documentada na
[seção 8](docs/CONFIGURACAO.md#8-restringir-visibilidade-por-regras-aba-visibilidade).

## Autor

**Pellissari**

## Licença

**AGPL-3.0** — ver arquivo `LICENSE`. O GLPI core é GPL-3.0-or-later; a GPLv3
§13 permite expressamente combinar um programa GPLv3 com código licenciado
sob a AGPLv3 num mesmo todo (foi essa mesma cláusula que permitiu ao próprio
GLPI incorporar código AGPL-3.0 do FusionInventory ao migrar de GPL-2.0 para
GPL-3.0-or-later na versão 10.0.1: https://www.glpi-project.org/en/glpi-gpl-3-0/).
Este plugin adota a AGPL-3.0 — mais restritiva que a licença do core —, o que
é compatível com rodar sobre e distribuir código derivado do GLPI 11.0.x.
