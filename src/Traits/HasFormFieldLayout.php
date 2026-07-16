<?php

/**
 * Analytic Design
 * -----------------------------------------------------------------------------
 * Reproduz em PHP puro o mesmo HTML/CSS que o GLPI 11 gera para campos de
 * formulário via Twig (ver templates/components/form/fields_macros.html.twig,
 * macros field()/horizontalField() no core do GLPI): um `<div class="row">`
 * de campos independentes, cada um com rótulo em `col-form-label` alinhado à
 * direita e o campo em `field-container`, ocupando metade da linha
 * (`col-sm-6`) ou a linha inteira. Usa as MESMAS classes Bootstrap que o
 * core já carrega globalmente — nenhuma classe nova precisa ser definida.
 *
 * Reproduzido em PHP em vez de chamar o Twig diretamente porque o restante
 * do plugin já é PHP/HTML puro (ver docblock de Connection::showForm()) e
 * porque cada campo daqui tem um texto de exemplo abaixo (`.form-text`), algo
 * que os formulários nativos do GLPI não fazem (eles usam tooltip no
 * rótulo) — por isso cada campo é independente (não compartilha uma linha
 * de tabela com outro campo), o que evita o rótulo de um campo "flutuar" no
 * meio da altura de um campo vizinho mais alto.
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
        bool $fullWidth = false,
        string $extraClass = '',
        string $extraAttr = ''
    ): void {
        // align-items-start (não align-items-center, usado pelo GLPI): quando
        // dois campos ficam lado a lado (col-sm-6) e um deles tem texto de
        // exemplo abaixo do input, o Bootstrap estica os dois para a mesma
        // altura (flex, mesma linha) — com "center" o rótulo do campo mais
        // curto fica flutuando no meio dessa altura extra; com "start" ele
        // sempre fica colado no topo, alinhado com o rótulo do campo vizinho.
        $widthClass = $fullWidth ? 'col-12' : 'col-12 col-sm-6';
        echo "<div class='form-field row align-items-start {$widthClass} {$extraClass} mb-2' data-testid='form-field-"
            . htmlspecialchars($name, ENT_QUOTES) . "'{$extraAttr}>";
        echo "<label class='col-form-label col-xxl-5 text-xxl-end' for='"
            . htmlspecialchars($forId, ENT_QUOTES) . "'>" . $label . "</label>";
        echo "<div class='col-xxl-7 field-container'>";
    }

    private static function closeField(): void
    {
        echo "</div></div>";
    }
}
