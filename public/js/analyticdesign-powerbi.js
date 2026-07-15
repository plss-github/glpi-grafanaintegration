/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Bootstrap do embed seguro do Power BI (Fase 2): hidrata os containers
 * `.analyticdesign-powerbi-secure` renderizados por PowerBiSource::renderEmbed()
 * usando a lib vendorizada em public/js/vendor/powerbi-client.min.js (expõe o
 * global `powerbi`). O container carrega report id / embed URL / embed token
 * (curta duração) via atributos data-*; nenhum token é lido de cookie/sessão.
 *
 * A VALIDAR: não há confirmação de qual evento (se algum) o GLPI dispara ao
 * recarregar cards de dashboard via AJAX (troca de aba, resize). Por isso,
 * além do embed inicial no DOMContentLoaded, um MutationObserver cobre
 * containers adicionados dinamicamente depois.
 */
(function () {
    function embedReport(el) {
        if (!window.powerbi) {
            el.textContent = 'powerbi-client não carregado.';
            return;
        }
        if (el.dataset.analyticdesignEmbedded === '1') {
            return;
        }

        var config = {
            type: 'report',
            tokenType: 1, // models.TokenType.Embed — enum estável da lib powerbi-client
            accessToken: el.dataset.embedToken,
            embedUrl: el.dataset.embedUrl,
            id: el.dataset.reportId,
            settings: {
                panes: {
                    filters: { visible: false },
                    pageNavigation: { visible: false },
                },
            },
        };

        try {
            window.powerbi.reset(el);
        } catch (e) {
            // Elemento ainda não tinha um embed anterior — ok, ignora.
        }

        try {
            window.powerbi.embed(el, config);
            el.dataset.analyticdesignEmbedded = '1';
        } catch (e) {
            el.textContent = 'Falha ao inicializar o embed do Power BI.';
        }
    }

    function embedAll(root) {
        (root || document).querySelectorAll('.analyticdesign-powerbi-secure').forEach(embedReport);
    }

    document.addEventListener('DOMContentLoaded', function () {
        embedAll(document);

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) {
                        return;
                    }
                    if (node.classList && node.classList.contains('analyticdesign-powerbi-secure')) {
                        embedReport(node);
                    }
                    if (node.querySelectorAll) {
                        embedAll(node);
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    });
})();
