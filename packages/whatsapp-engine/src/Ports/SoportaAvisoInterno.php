<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * Avisar al equipo DENTRO del sistema del negocio cuando una conversación pasa
 * a una persona.
 *
 * Existe porque el motor tenía escrito un `INSERT INTO alertas (mesa_id, …)`:
 * la tabla de avisos de un bar, con una columna que en una tienda no significa
 * nada. Iba envuelto en un try/catch, así que en cualquier otro proyecto
 * fallaba en silencio — y el resultado era justo lo que no puede pasar: al
 * cliente se le dice «le paso tu caso a una persona» y nadie se entera.
 *
 * El negocio que no lo implemente sigue teniendo el aviso por WhatsApp al
 * número de guardia; lo que no tendrá es el aviso dentro del panel.
 */
interface SoportaAvisoInterno
{
    /**
     * @param array  $conversacion fila de wa_conversaciones
     * @param string $motivo       por qué se transfiere, en una frase
     */
    public function avisarEquipo(array $conversacion, string $motivo): void;
}
