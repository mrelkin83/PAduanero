<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/** Identidad del negocio que atiende esta conversación. */
interface TenantPort
{
    /** Id del negocio, o null si el proyecto no es multi-negocio. */
    public function id(): ?int;

    /** Nombre para presentarse en el chat («Trabajas para …»). */
    public function nombre(): string;

    /** Nombre de su base de datos, o null. Lo necesita el enrutado del webhook. */
    public function baseDatos(): ?string;

    /** ¿El proyecto atiende a varios negocios a la vez? */
    public function esMultiNegocio(): bool;

    /**
     * Cómo separa este proyecto los datos de un negocio de los del vecino.
     *
     * Hay dos formas de ser multi-negocio y el motor tropezaba con la segunda:
     *
     *   · UNA BASE POR NEGOCIO (ControlBarMax). La conexión ya apunta a la base
     *     correcta y no hay nada que filtrar. Aquí se devuelve **null**.
     *   · UNA SOLA BASE Y UNA COLUMNA (MayTech POS: `empresa_id`). La misma
     *     tabla `wa_conversaciones` guarda las conversaciones de todos los
     *     negocios, y una consulta sin filtrar las ve TODAS. Aquí se devuelve
     *     `['columna' => 'empresa_id', 'valor' => 7]`.
     *
     * No es una optimización: sin esto, en un proyecto del segundo tipo un
     * negocio leería las conversaciones de otro y `wa_config` devolvería la
     * configuración —y las claves— de quien tuviera la fila 1.
     *
     * @return array{columna:string,valor:int}|null
     */
    public function scopeFila(): ?array;
}
