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
A arquitetura, a estrutura de arquivos, os riscos de integração com o GLPI e
o modelo de segurança completo (antes descritos aqui) foram movidos para lá —
ver [seção "Arquitetura e riscos de integração"](docs/CONFIGURACAO.md#11-arquitetura-e-riscos-de-integração)
e [seção "Segurança"](docs/CONFIGURACAO.md#12-segurança). A aba
**"Visibilidade"** (regras de Critérios/Ação, alternativa ao ajuste direto
por card) está documentada na
[seção 10](docs/CONFIGURACAO.md#10-restringir-visibilidade-por-regras-aba-visibilidade).

## Licença

**AGPL-3.0** — ver arquivo `LICENSE`. O GLPI core é GPL-3.0-or-later; a GPLv3
§13 permite expressamente combinar um programa GPLv3 com código licenciado
sob a AGPLv3 num mesmo todo (foi essa mesma cláusula que permitiu ao próprio
GLPI incorporar código AGPL-3.0 do FusionInventory ao migrar de GPL-2.0 para
GPL-3.0-or-later na versão 10.0.1: https://www.glpi-project.org/en/glpi-gpl-3-0/).
Este plugin adota a AGPL-3.0 — mais restritiva que a licença do core —, o que
é compatível com rodar sobre e distribuir código derivado do GLPI 11.0.x.
