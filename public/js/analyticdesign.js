/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Comportamento das telas do plugin:
 *  - alterna os campos de credencial do Power BI conforme o modo de embed
 *    selecionado (secure vs publish_to_web), na aba "Características";
 *  - alerta quando o modo de embed "publish to web" é escolhido;
 *  - botão "Testar conexão" via fetch, sem recarregar a página; quando a
 *    conexão falha, esconde os campos e mostra só o erro (com um botão para
 *    reabrir os campos e corrigir a configuração);
 *  - no Grafana, "Testar conexão" (na linha de Salvar/Excluir da aba "Fonte
 *    de Dados") aparece quando já existe token salvo ou algo é digitado no
 *    campo;
 *  - esconde a seção de URL/API do Grafana quando o Status da fonte é "Não";
 *  - botão "Remover" de um dashboard já importado (aba "Configurações"), com
 *    confirmação, via fetch — some da tabela sem recarregar a página;
 *  - aba "Visibilidade": mostra o widget de Valor certo (dropdown de
 *    dashboards/módulos, ou texto livre) conforme Campo/Condição
 *    selecionados na linha de "adicionar critério"; mostra o dropdown de
 *    Perfil/Grupo/Usuário/Entidade certo (ou nenhum, para "Todos") conforme
 *    o alvo selecionado na linha de "adicionar ação".
 *
 * Tudo via *event delegation* em `document` (nada de
 * `document.querySelector(...).addEventListener(...)` direto): o GLPI carrega
 * o conteúdo de cada aba via AJAX depois do carregamento inicial da página
 * (confirmado testando contra uma instância real — inclusive a aba "$main"
 * do próprio formulário), então um listener preso a um elemento específico
 * no load da página não veria elementos que só existem depois desse AJAX.
 * Delegar em `document` funciona independente de quando o elemento apareceu.
 *
 * A delegação de 'change' usa `$(document).on('change', ...)` (jQuery), NÃO
 * `document.addEventListener('change', ...)` — ao contrário de 'click'/'input'
 * abaixo, que continuam vanilla. Achado em 2026-07-21, depois de DUAS
 * tentativas de corrigir o dropdown de Valor da aba "Visibilidade" (aba
 * Ação) não terem funcionado: todo `<select>` deste plugin passa por
 * select2 (`Dropdown::show()`/`showFromArray()` do core), e o select2
 * notifica mudança de valor chamando `this.$element.trigger('change')` —
 * um `.trigger()` do jQuery em um tipo de evento sem método nativo
 * equivalente (existe `elem.click()`/`elem.submit()`, não existe
 * `elem.change()`) NÃO dispara um Event nativo de verdade; o jQuery só
 * percorre a árvore chamando os handlers registrados via `.on()`/`.bind()`
 * do PRÓPRIO jQuery. Confirmado empiricamente (headless Edge com
 * `document.addEventListener('change', ..., true)` e
 * `$(document).on('change', ...)` lado a lado: só o segundo via o evento
 * depois de um `.select2()` do usuário trocar de opção) — um
 * `addEventListener('change', ...)` puro no `document` NUNCA reage a uma
 * troca feita através da UI do select2, só ao valor já vir certo desde o
 * render inicial do servidor (por isso só o widget PADRÃO — Perfil,
 * Dashboard como Campo — sempre "funcionava": nunca precisou de um toggle
 * disparado por evento). O comentário antigo aqui ("Vanilla JS sem depender
 * de jQuery") partia de uma premissa errada pra um plugin GLPI: o próprio
 * core já exige jQuery em toda página (select2 depende dele), então não há
 * ganho nenhum em evitá-lo — só o bug acima.
 */
window.jQuery(document).on('change', function (event) {
    var embedModeSelect = event.target.closest('select[name="embed_mode"]');
    if (embedModeSelect) {
        toggleEmbedModeFields(embedModeSelect);
        return;
    }

    var statusSelect = event.target.closest('select[name="is_active"]');
    if (statusSelect) {
        toggleGrafanaSectionVisibility(statusSelect);
        return;
    }

    var criterionSelect = event.target.closest('.analyticdesign-criterion-field, .analyticdesign-criterion-condition');
    if (criterionSelect) {
        toggleCriterionValueWidget(criterionSelect);
        return;
    }

    var actionItemtypeSelect = event.target.closest('.analyticdesign-action-itemtype');
    if (actionItemtypeSelect) {
        toggleActionValueWidget(actionItemtypeSelect);
    }
});

document.addEventListener('input', function (event) {
    var apiTokenInput = event.target.closest('input[name="api_token"]');
    if (apiTokenInput) {
        toggleTestButtonVisibility(apiTokenInput);
    }
});

document.addEventListener('click', function (event) {
    var testBtn = event.target.closest('.analyticdesign-test-connection');
    if (testBtn) {
        testConnection(testBtn);
        return;
    }

    var reopenBtn = event.target.closest('.analyticdesign-reopen-fields');
    if (reopenBtn) {
        showCharacteristicsFields(reopenBtn.closest('.analyticdesign-characteristics'));
        return;
    }

    var deleteBtn = event.target.closest('.analyticdesign-delete-item');
    if (deleteBtn) {
        deleteDashboardItem(deleteBtn);
    }
});

function toggleEmbedModeFields(embedModeSelect) {
    var selectedEmbedMode = embedModeSelect.value;
    var container = embedModeSelect.closest('.analyticdesign-fields-wrapper') || document;

    container.querySelectorAll('.analyticdesign-embed-mode-field').forEach(function (row) {
        row.style.display = (row.dataset.embedMode === selectedEmbedMode) ? '' : 'none';
    });

    var warning = container.querySelector('.analyticdesign-publish-warning');
    if (warning) {
        warning.style.display = (selectedEmbedMode === 'publish_to_web') ? '' : 'none';
    }
}

/**
 * Mostra "Testar conexão" quando algo é digitado no token de API, OU quando
 * já existe um token salvo (`data-has-credentials`, setado no servidor) —
 * sem essa segunda condição, o botão sumia de novo a cada reload, já que o
 * campo de senha sempre nasce vazio por segurança (ver
 * Connection::showGrafanaCredentialsSection()).
 */
function toggleTestButtonVisibility(apiTokenInput) {
    // Busca global (não escopada por closest()): o botão agora fica na
    // linha de botões padrão (Salvar/Excluir), fora de
    // .analyticdesign-characteristics — ver Connection::showForm(). Só
    // existe um desses por carregamento de página, então a busca global é
    // segura.
    var testBtn = document.querySelector('.analyticdesign-test-connection');
    if (testBtn) {
        var hasCredentials = testBtn.dataset.hasCredentials === '1';
        var hasTyped = apiTokenInput.value.trim() !== '';
        testBtn.style.display = (hasCredentials || hasTyped) ? '' : 'none';
    }
}

/** Esconde/mostra a seção de URL/API do Grafana conforme o Status (is_active) da fonte. */
function toggleGrafanaSectionVisibility(statusSelect) {
    document.querySelectorAll('.analyticdesign-status-toggle').forEach(function (section) {
        section.style.display = (statusSelect.value === '1') ? '' : 'none';
    });
}

/**
 * Aba "Visibilidade" — linha de "adicionar critério": mostra o widget de
 * Valor certo conforme Campo+Condição selecionados (dropdown de dashboards
 * importados quando Campo=Dashboard e Condição=é; dropdown de módulos
 * quando Campo=Módulo e Condição=é; texto livre nos demais casos, ex.:
 * "contém") — ver VisibilityRule::showCriteriaSection().
 */
function toggleCriterionValueWidget(selectEl) {
    var form = selectEl.closest('.analyticdesign-add-criterion');
    if (!form) {
        return;
    }

    var fieldSelect = form.querySelector('.analyticdesign-criterion-field');
    var conditionSelect = form.querySelector('.analyticdesign-criterion-condition');
    if (!fieldSelect || !conditionSelect) {
        return;
    }

    var showDashboard = fieldSelect.value === 'name' && conditionSelect.value === 'equals';
    var showModule = fieldSelect.value === 'category' && conditionSelect.value === 'equals';

    var widgets = {
        dashboard: form.querySelector('.analyticdesign-criterion-value-dashboard'),
        module: form.querySelector('.analyticdesign-criterion-value-module'),
        text: form.querySelector('.analyticdesign-criterion-value-text'),
    };
    if (widgets.dashboard) { widgets.dashboard.style.display = showDashboard ? '' : 'none'; }
    if (widgets.module) { widgets.module.style.display = showModule ? '' : 'none'; }
    if (widgets.text) { widgets.text.style.display = (!showDashboard && !showModule) ? '' : 'none'; }

    // select2 calcula a largura do combo na hora em que é inicializado —
    // um combo que nasceu escondido (display:none) fica com largura 0 até
    // algo disparar um recálculo; um 'resize' na window é o gatilho que o
    // select2 já escuta por padrão, sem precisar destruir/recriar o widget.
    window.dispatchEvent(new Event('resize'));
}

/**
 * Aba "Visibilidade" — linha de "adicionar ação": busca via fetch() o
 * dropdown de Valor certo (Perfil/Grupo/Usuário/Entidade) para o alvo
 * escolhido em "Conceder acesso a" (nada pra "Todos os usuários", que não
 * precisa de um ID específico) — ver ajax/getvisibilityactionvalue.php.
 *
 * Buscado sob demanda em vez de pré-renderizar os 4 e trocar via CSS
 * (tentativa anterior): um combo do select2 iniciado dentro de um container
 * `display:none` calcula largura 0 e não se recupera sozinho depois, mesmo
 * reexibindo o container — nem forçando `width` explícito nem disparando um
 * 'resize' corrige de forma confiável. Buscando HTML novo a cada troca, o
 * combo sempre nasce dentro de um container já visível.
 */
function toggleActionValueWidget(selectEl) {
    var form = selectEl.closest('.analyticdesign-add-action');
    if (!form) {
        return;
    }
    var container = form.querySelector('.analyticdesign-action-value-container');
    if (!container) {
        return;
    }

    var itemtype = selectEl.value;
    if (itemtype === 'All') {
        container.innerHTML = '<span class="text-muted">Nenhum valor necessário para "Todos os usuários".</span>';
        return;
    }

    container.innerHTML = '<span class="text-muted">Carregando...</span>';
    fetch('../ajax/getvisibilityactionvalue.php?itemtype=' + encodeURIComponent(itemtype), {
        credentials: 'same-origin',
    })
        .then(function (resp) { return resp.text(); })
        .then(function (html) {
            // innerHTML puro não executa os <script> do select2 embutidos na
            // resposta — jQuery.fn.html() executa, e jQuery já é garantido
            // aqui (select2/Dropdown::show() já dependem dele).
            if (window.jQuery) {
                window.jQuery(container).html(html);
            } else {
                container.innerHTML = html;
            }
        })
        .catch(function () {
            container.innerHTML = '<span class="text-danger">Erro ao carregar.</span>';
        });
}

/**
 * Token CSRF pra chamadas fetch() deste plugin (testConnection/
 * deleteDashboardItem) — ver docblock delas pra o porquê de usar isto em vez
 * do `_glpi_csrf_token` de um form específico.
 *
 * Manda igual ao hook `$(document).ajaxSend()` do próprio common.js do GLPI
 * (header `X-Glpi-Csrf-Token`, valor tirado da mesma tag `<meta>` que ele
 * usa) — só que lendo a tag direto, sem precisar de jQuery pra isso
 * especificamente. O pulo do gato é o header
 * `X-Requested-With`: SEM ele, o kernel do GLPI (CheckCsrfListener) não
 * reconhece a requisição como AJAX e cai no branch que lê
 * `_glpi_csrf_token` do corpo do POST e *consome* (remove da sessão) o
 * token depois de validar — e como `Html::closeForm()` reaproveita o MESMO
 * token pra todo form renderizado num mesmo carregamento de página/aba (ver
 * `Session::getNewCSRFToken()` no core), consumir o token aqui invalidava
 * silenciosamente qualquer OUTRO form ainda não recarregado na mesma aba
 * (ex.: registrar dashboard X, remover X, tentar registrar de novo — o
 * "remover" consumia o token que o form de "Adicionar" ainda ia usar,
 * resultando em AccessDeniedHttpException). Com os dois headers, o kernel
 * usa o branch de AJAX (`preserve_token: true`), que valida sem consumir.
 */
function getPluginAjaxCsrfToken() {
    var meta = document.querySelector('meta[property="glpi:csrf_token"]');
    return meta !== null ? meta.getAttribute('content') : '';
}

function testConnection(testBtn) {
    // Também global: no Grafana o botão (linha de Salvar/Excluir) e o
    // resultado/erro (dentro de .analyticdesign-characteristics) não são
    // mais parente/filho um do outro — ver toggleTestButtonVisibility().
    var resultEl = document.querySelector('.analyticdesign-test-result');
    var container = document.querySelector('.analyticdesign-characteristics');
    var id = testBtn.dataset.id;

    testBtn.disabled = true;
    if (resultEl) {
        resultEl.textContent = '...';
        resultEl.style.color = '';
    }

    var body = new URLSearchParams();
    body.set('id', id);

    fetch('../ajax/testconnection.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': getPluginAjaxCsrfToken(),
        },
        body: body.toString(),
        credentials: 'same-origin',
    })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            if (data.success) {
                if (resultEl) {
                    resultEl.textContent = data.message || 'OK';
                    resultEl.style.color = '#2fa84f';
                }
                return;
            }
            showCharacteristicsError(container, data.message || 'Falhou');
        })
        .catch(function () {
            showCharacteristicsError(container, 'Erro de rede ao testar a conexão.');
        })
        .finally(function () {
            testBtn.disabled = false;
        });
}

/**
 * Remove por completo um dashboard exposto (aba "Configurações" — ver
 * ajax/deletedashboarditem.php). Pede confirmação antes (ação irreversível);
 * em caso de sucesso, só tira a linha da tabela — sem recarregar a página.
 */
function deleteDashboardItem(deleteBtn) {
    var name = deleteBtn.dataset.name || '';
    if (!window.confirm('Remover "' + name + '"? Essa ação não pode ser desfeita.')) {
        return;
    }

    var id = deleteBtn.dataset.id;

    var body = new URLSearchParams();
    body.set('id', id);

    deleteBtn.disabled = true;
    fetch('../ajax/deletedashboarditem.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': getPluginAjaxCsrfToken(),
        },
        body: body.toString(),
        credentials: 'same-origin',
    })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            if (data.success) {
                var row = deleteBtn.closest('tr');
                if (row) {
                    row.remove();
                }
                return;
            }
            window.alert(data.message || 'Falha ao remover.');
            deleteBtn.disabled = false;
        })
        .catch(function () {
            window.alert('Erro de rede ao remover.');
            deleteBtn.disabled = false;
        });
}

/** Esconde os campos e mostra a mensagem de erro no lugar deles. */
function showCharacteristicsError(container, message) {
    if (!container) {
        return;
    }

    var errorEl = container.querySelector('.analyticdesign-error');
    var wrapperEl = container.querySelector('.analyticdesign-fields-wrapper');

    if (errorEl) {
        var messageEl = errorEl.querySelector('.analyticdesign-error-message');
        if (messageEl) {
            messageEl.textContent = message;
        }
        errorEl.style.display = '';
    }
    if (wrapperEl) {
        wrapperEl.style.display = 'none';
    }
}

/** Reverte showCharacteristicsError(): reabre os campos para o usuário corrigir a configuração. */
function showCharacteristicsFields(container) {
    if (!container) {
        return;
    }

    var errorEl = container.querySelector('.analyticdesign-error');
    var wrapperEl = container.querySelector('.analyticdesign-fields-wrapper');

    if (errorEl) {
        errorEl.style.display = 'none';
    }
    if (wrapperEl) {
        wrapperEl.style.display = '';
    }
}
