<?php

declare(strict_types=1);

/**
 * Confianza verificable.
 *
 * Responde a la única pregunta que se hace quien acaba de perder su mercancía
 * y encontró este despacho por internet: «¿esto existe?».
 *
 * Contra ese miedo los testimonios sirven poco —un estafador escribe veinte
 * en diez minutos, y quien teme estar ante uno lo sabe—. Lo que sirve es lo
 * que el visitante puede comprobar **sin pedirnos permiso ni creernos nada**:
 * un número de tarjeta profesional que consulta él mismo en el registro del
 * Consejo Superior de la Judicatura, un NIT en el RUES, y una oficina con
 * dirección a la que puede llegar caminando.
 *
 * De ahí la prueba de fuego para decidir si algo entra en `verificables`: si
 * para confirmarlo hay que preguntarnos a nosotros, no va aquí.
 *
 * **Todo campo vacío se omite, y si no queda nada comprobable la sección
 * entera no se pinta.** No es defensivo por costumbre: una dirección
 * inventada en la página de un abogado no es relleno pendiente de reemplazo,
 * es la prueba de que el visitante tenía razón en desconfiar. Y una sección
 * de confianza medio llena, con rayas y huecos donde deberían ir los datos,
 * hace más daño que no tenerla.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var string $waBase
 * @var callable $e
 */

/** Deja solo las entradas que traen su dato; el resto no existe para la vista. */
$conValor = static function (array $lista, string $campo): array {
    $limpias = [];

    foreach ($lista as $fila) {
        if (is_array($fila) && trim((string) ($fila[$campo] ?? '')) !== '') {
            $limpias[] = $fila;
        }
    }

    return $limpias;
};

$verificables = $conValor($bloque->lista('verificables'), 'valor');
$sedes = $conValor($bloque->lista('sedes'), 'direccion');

if ($verificables === [] && $sedes === []) {
    return;
}

$invitacion = $bloque->texto('invitacion');
?>
<section id="confianza" class="bg-tinta py-20 md:py-[5rem]">
    <div class="mx-auto max-w-[78rem] px-6 md:px-20">

        <p class="rotulo">Quién responde por esto</p>

        <h2 class="titular-seccion mt-8 max-w-3xl">
            <?= $e($bloque->titulo) ?>
        </h2>

        <?php if ($bloque->subtitulo !== null): ?>
        <p class="entrada mt-7 max-w-2xl">
            <?= $e($bloque->subtitulo) ?>
        </p>
        <?php endif; ?>

        <?php if ($verificables !== []): ?>
        <?php /* Cada dato con su camino de comprobación al lado. El enlace es
                 la mitad del mensaje: decirle a alguien dónde verificarte es
                 justo lo que un estafador nunca hace. */ ?>
        <dl class="mt-16 grid gap-6 md:mt-20 md:grid-cols-2">
            <?php foreach ($verificables as $dato): ?>
                <?php
                $etiqueta = (string) ($dato['etiqueta'] ?? '');
                $valor = (string) ($dato['valor'] ?? '');
                $nota = (string) ($dato['nota'] ?? '');
                $url = (string) ($dato['url'] ?? '');
                $enlaceTexto = (string) ($dato['enlace_texto'] ?? '');
                ?>
                <div class="tarjeta p-8 md:p-10">
                    <dt class="rotulo text-acero"><?= $e($etiqueta) ?></dt>

                    <dd class="cifra-oro mt-5 break-words text-[1.375rem] md:text-[1.75rem]">
                        <?= $e($valor) ?>
                    </dd>

                    <?php if ($nota !== ''): ?>
                    <p class="cuerpo mt-4 text-[0.9375rem]"><?= $e($nota) ?></p>
                    <?php endif; ?>

                    <?php if ($url !== '' && $enlaceTexto !== ''): ?>
                    <a href="<?= $e($url) ?>" class="menu-enlace mt-6 inline-flex items-center gap-2"
                       target="_blank" rel="noopener noreferrer">
                        <?= $e($enlaceTexto) ?>
                        <span aria-hidden="true">↗</span>
                    </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </dl>
        <?php endif; ?>

        <?php if ($sedes !== []): ?>
        <?php /* Las oficinas son el argumento más contundente de la sección y
                 el único que no exige que nadie abra un navegador. La de Zona
                 Franca dice además algo que ningún texto puede decir igual de
                 bien: nadie pone oficina ahí por casualidad, y quien tiene la
                 mercancía retenida en una lo sabe. */ ?>
        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <?php foreach ($sedes as $sede): ?>
                <?php
                $nombre = (string) ($sede['nombre'] ?? '');
                $direccion = (string) ($sede['direccion'] ?? '');
                $detalle = (string) ($sede['detalle'] ?? '');
                $horario = (string) ($sede['horario'] ?? '');
                ?>
                <div class="tarjeta p-8 md:p-10">
                    <p class="rotulo text-acero">Oficina</p>

                    <p class="titular-menor mt-5"><?= $e($nombre) ?></p>

                    <address class="cuerpo mt-3 not-italic">
                        <?= $e($direccion) ?>
                    </address>

                    <?php if ($horario !== ''): ?>
                    <p class="rotulo mt-6 text-acero"><?= $e($horario) ?></p>
                    <?php endif; ?>

                    <?php if ($detalle !== ''): ?>
                    <p class="cuerpo mt-5 border-t border-linea pt-5 text-[0.9375rem]">
                        <?= $e($detalle) ?>
                    </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($invitacion !== '' && $sedes !== []): ?>
        <?php /* Solo se invita si hay a dónde. Sin dirección, esta frase sería
                 justo la clase de promesa vacía que la sección desmiente. */ ?>
        <p class="entrada mt-12 max-w-2xl border-l-2 border-oro pl-6">
            <?= $e($invitacion) ?>
        </p>
        <?php endif; ?>
    </div>
</section>
