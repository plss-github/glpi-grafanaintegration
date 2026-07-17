<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Reproduz em PHP puro o mesmo HTML/CSS que o GLPI 11 gera para campos de
 * formulário via Twig (ver templates/components/form/fields_macros.html.twig,
 * macro verticalField() no core do GLPI): rótulo em `col-form-label` numa
 * linha própria, campo logo abaixo em `field-container`, o par ocupando
 * metade da linha (`col-sm-6`) ou a linha inteira. Usa as MESMAS classes
 * Bootstrap que o core já carrega globalmente — nenhuma classe nova precisa
 * ser definida.
 *
 * A variante horizontalField() do core (rótulo ao LADO do campo, via
 * col-xxl-5/col-xxl-7) foi tentada primeiro e descartada: só funciona a
 * partir do breakpoint xxl (1400px — confirmado em lib/tabler.css), abaixo
 * disso rótulo e campo quebram linha de qualquer forma, e nesse modo
 * quebrado um <select> (Dropdown::showFromArray) e um <input> de texto não
 * ficam com o mesmo espaçamento vertical em relação ao próprio rótulo —
 * confirmado visualmente contra a instância viva. verticalField() não tem
 * esse problema: rótulo e campo estão sempre em blocos empilhados, nunca
 * lado a lado, então não há quebra de layout dependente da largura da tela.
 *
 * Reproduzido em PHP em vez de chamar o Twig diretamente porque o restante
 * do plugin já é PHP/HTML puro (ver docblock de Connection::showForm()).
 */

namespace GlpiPlugin\Analyticdesign\Traits;

trait HasFormFieldLayout
{
    private static function openFieldsRow(): void
    {
        echo "<div class='row'>";
    }

    private static function closeFieldsRow(): void
    {
        echo "</div>";
    }

    /**
     * @param bool|string $width      `true` = linha inteira (col-12), `false` =
     *                                 metade (col-12 col-sm-6, padrão) — ou uma
     *                                 classe Bootstrap literal (ex.: `'col-12
     *                                 col-sm-4'`, três campos por linha — ver
     *                                 Connection::showNameAndToolFields()).
     * @param string $extraClass classes adicionais no `<div class='form-field ...'>`
     *                           (ex.: para toggle de JS por tipo/modo — ver
     *                           ConnectionCharacteristics::showCredentialFieldRow()).
     * @param string $extraAttr  atributos HTML brutos adicionais no mesmo `<div>`
     *                           (ex.: `data-embed-mode='...' style='display:none;'`).
     */
    private static function openField(
        string $name,
        string $label,
        string $forId,
        bool|string $width = false,
        string $extraClass = '',
        string $extraAttr = ''
    ): void {
        $widthClass = is_string($width) ? $width : ($width ? 'col-12' : 'col-12 col-sm-6');
        echo "<div class='form-field {$widthClass} {$extraClass} mb-2' data-testid='form-field-"
            . htmlspecialchars($name, ENT_QUOTES) . "'{$extraAttr}>";
        echo "<div class='d-flex align-items-center'>";
        echo "<label class='col-form-label' for='" . htmlspecialchars($forId, ENT_QUOTES) . "'>" . $label . "</label>";
        echo "</div>";
        echo "<div class='field-container'>";
    }

    private static function closeField(): void
    {
        echo "</div></div>";
    }

    /**
     * Campo de senha "revelável" — mesmo padrão que o GLPI usa para a chave
     * de licença do GLPI Network (`fields.passwordField(..., {is_disclosable:
     * true})` em fields_macros.html.twig, que por baixo chama o macro
     * `input()` de basic_inputs_macros.html.twig com `is_disclosable`/
     * `is_copyable`): segurar o botão do olho mostra o valor em texto puro
     * (`showDisclosablePasswordField()`/`hideDisclosablePasswordField()`), e
     * um botão de copiar manda pra área de transferência
     * (`copyDisclosablePasswordFieldToClipboard()`) — as três já existem em
     * `public/js/common.js` do core, carregado globalmente; não precisamos
     * declarar nada em JS próprio para isso funcionar.
     */
    /**
     * Placeholder "já configurado" — não revela o valor nem seu tamanho real
     * (mesmo número de bolinhas sempre). Traits não podem ter constantes
     * antes do PHP 8.3 (este plugin mira 8.2+), daí ser um método.
     */
    private static function configuredPlaceholder(): string
    {
        return '••••••••••••';
    }

    private static function showDisclosablePasswordInput(string $name, string $id, string $value = '', string $placeholder = ''): void
    {
        echo "<div class='btn-group btn-group-sm d-flex'>";
        echo "<input type='password' id='" . htmlspecialchars($id, ENT_QUOTES) . "'"
            . " class='form-control rounded-end-0' name='" . htmlspecialchars($name, ENT_QUOTES) . "'"
            . " placeholder='" . htmlspecialchars($placeholder, ENT_QUOTES) . "'"
            . " value='" . htmlspecialchars($value, ENT_QUOTES) . "' />";
        echo "<button type='button' class='btn btn-outline-secondary'"
            . " onmousedown=\"showDisclosablePasswordField('" . htmlspecialchars($id, ENT_QUOTES) . "')\""
            . " onmouseup=\"hideDisclosablePasswordField('" . htmlspecialchars($id, ENT_QUOTES) . "')\""
            . " onmouseout=\"hideDisclosablePasswordField('" . htmlspecialchars($id, ENT_QUOTES) . "')\">"
            . "<i class='ti ti-eye disclose'></i></button>";
        echo "<button type='button' class='btn btn-outline-secondary'"
            . " onclick=\"copyDisclosablePasswordFieldToClipboard('" . htmlspecialchars($id, ENT_QUOTES) . "')\">"
            . "<i class='ti ti-clipboard-copy disclose'></i></button>";
        echo "</div>";
    }
}
