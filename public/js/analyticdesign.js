/**
 * Analytic Design by Pellissari
 * -----------------------------------------------------------------------------
 * Comportamento do formulário de Connection:
 *  - mostra apenas os campos de credenciais do tipo de fonte selecionado;
 *  - alerta quando o modo de embed "publish to web" é escolhido (Power BI);
 *  - botão "Testar conexão" via fetch, sem recarregar a página.
 *
 * Vanilla JS (sem depender de jQuery) para não presumir o que está carregado
 * na página em toda instalação GLPI 11.
 */
document.addEventListener('DOMContentLoaded', function () {
    var typeSelect = document.querySelector('select[name="type"]');
    var embedModeSelect = document.querySelector('select[name="embed_mode"]');

    function csrfToken() {
        var input = document.querySelector('input[name="_glpi_csrf_token"]');
        return input ? input.value : '';
    }

    function toggleFieldsForType() {
        if (!typeSelect) {
            return;
        }
        var selected = typeSelect.value;
        document.querySelectorAll('.analyticdesign-fields-for-type').forEach(function (row) {
            row.style.display = (row.dataset.sourceType === selected) ? '' : 'none';
        });
    }

    function togglePublishWarning() {
        var warning = document.querySelector('.analyticdesign-publish-warning');
        if (!warning) {
            return;
        }
        var isPublishToWeb = embedModeSelect && embedModeSelect.value === 'publish_to_web';
        warning.style.display = isPublishToWeb ? '' : 'none';
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', function () {
            toggleFieldsForType();
            togglePublishWarning();
        });
        toggleFieldsForType();
    }

    if (embedModeSelect) {
        embedModeSelect.addEventListener('change', togglePublishWarning);
        togglePublishWarning();
    }

    var testBtn = document.querySelector('.analyticdesign-test-connection');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            var resultEl = document.querySelector('.analyticdesign-test-result');
            var id = testBtn.dataset.id;

            testBtn.disabled = true;
            if (resultEl) {
                resultEl.textContent = '...';
                resultEl.style.color = '';
            }

            var body = new URLSearchParams();
            body.set('id', id);
            body.set('_glpi_csrf_token', csrfToken());

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
        });
    }
});
