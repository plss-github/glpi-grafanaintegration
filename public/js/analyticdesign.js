/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Comportamento das telas do plugin:
 *  - alterna os campos de credencial do Power BI conforme o modo de embed
 *    selecionado (secure vs publish_to_web), na aba "Características";
 *  - alerta quando o modo de embed "publish to web" é escolhido;
 *  - botão "Testar conexão" via fetch, sem recarregar a página.
 *
 * Tudo via *event delegation* em `document` (nada de
 * `document.querySelector(...).addEventListener(...)` direto): o GLPI carrega
 * o conteúdo de cada aba via AJAX depois do carregamento inicial da página
 * (confirmado testando contra uma instância real — inclusive a aba "$main"
 * do próprio formulário), então um listener preso a um elemento específico
 * no load da página não veria elementos que só existem depois desse AJAX.
 * Delegar em `document` funciona independente de quando o elemento apareceu.
 *
 * Vanilla JS (sem depender de jQuery) para não presumir o que está carregado
 * na página em toda instalação GLPI 11.
 */
document.addEventListener('change', function (event) {
    var embedModeSelect = event.target.closest('select[name="embed_mode"]');
    if (!embedModeSelect) {
        return;
    }
    toggleEmbedModeFields(embedModeSelect);
});

document.addEventListener('click', function (event) {
    var testBtn = event.target.closest('.analyticdesign-test-connection');
    if (testBtn) {
        testConnection(testBtn);
    }
});

function toggleEmbedModeFields(embedModeSelect) {
    var selectedEmbedMode = embedModeSelect.value;
    var container = embedModeSelect.closest('table') || document;

    container.querySelectorAll('.analyticdesign-embed-mode-field').forEach(function (row) {
        row.style.display = (row.dataset.embedMode === selectedEmbedMode) ? '' : 'none';
    });

    var warning = container.querySelector('.analyticdesign-publish-warning');
    if (warning) {
        warning.style.display = (selectedEmbedMode === 'publish_to_web') ? '' : 'none';
    }
}

function testConnection(testBtn) {
    var resultEl = testBtn.parentElement.querySelector('.analyticdesign-test-result');
    var id = testBtn.dataset.id;
    var csrfInput = testBtn.closest('form')
        ? testBtn.closest('form').querySelector('input[name="_glpi_csrf_token"]')
        : document.querySelector('input[name="_glpi_csrf_token"]');

    testBtn.disabled = true;
    if (resultEl) {
        resultEl.textContent = '...';
        resultEl.style.color = '';
    }

    var body = new URLSearchParams();
    body.set('id', id);
    body.set('_glpi_csrf_token', csrfInput ? csrfInput.value : '');

    fetch('../ajax/testconnection.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin',
    })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            if (resultEl) {
                resultEl.textContent = data.message || (data.success ? 'OK' : 'Falhou');
                resultEl.style.color = data.success ? '#2fa84f' : '#c0392b';
            }
        })
        .catch(function () {
            if (resultEl) {
                resultEl.textContent = 'Erro de rede ao testar a conexão.';
                resultEl.style.color = '#c0392b';
            }
        })
        .finally(function () {
            testBtn.disabled = false;
        });
}
