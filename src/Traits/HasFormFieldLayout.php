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
        $widthClass = $fullWidth ? 'col-12' : 'col-12 col-sm-6';
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
}
