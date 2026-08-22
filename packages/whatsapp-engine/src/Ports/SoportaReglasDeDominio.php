<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * Reglas del DOMINIO que van en las capas no editables del prompt.
 *
 * Existe por un negocio concreto: un despacho de abogados donde lo que el bot
 * NO puede decir —plazos, normas citadas con número, promesas de resultado—
 * está regulado por ley y compromete la firma profesional del dueño. Esas
 * reglas no pueden vivir en `wa_agentes.instrucciones`, que cualquier
 * administrador edita desde el panel: viven en el código del adaptador, y el
 * PromptComposer las inserta junto a las capas 1-3, donde nada las pisa.
 *
 * Un negocio sin reglas de este calibre simplemente no implementa la interfaz
 * y el prompt queda como estaba.
 */
interface SoportaReglasDeDominio
{
    /**
     * Texto Markdown que se inserta como capa fija del prompt, después de las
     * reglas del negocio y antes del rol del agente. Debe ser corto: viaja en
     * cada mensaje de cada conversación.
     */
    public function reglasDeDominio(): string;
}
