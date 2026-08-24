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
<section id="confianza" class="py-20 md:py-[8rem] relative isolate">
    <div class="mx-auto max-w-[84rem] px-6 md:px-12">

        <div class="revelar">
            <p class="rotulo mb-4 md:mb-6">Quién responde por esto</p>

            <h2 class="titular-seccion max-w-4xl">
                <?= $e($bloque->titulo) ?>
            </h2>

            <?php if ($bloque->subtitulo !== null): ?>
            <p class="mt-6 md:mt-8 text-[1.1rem] md:text-xl text-acero max-w-2xl leading-relaxed font-light">
                <?= $e($bloque->subtitulo) ?>
            </p>
            <?php endif; ?>
        </div>

        <?php if ($verificables !== []): ?>
        <?php /* Cada dato con su camino de comprobación al lado. El enlace es
                 la mitad del mensaje: decirle a alguien dónde verificarte es
                 justo lo que un estafador nunca hace. */ ?>
        <dl class="mt-12 md:mt-24 grid gap-6 md:gap-8 md:grid-cols-2">
            <?php foreach ($verificables as $i => $dato): ?>
                <?php
                $etiqueta = (string) ($dato['etiqueta'] ?? '');
                $valor = (string) ($dato['valor'] ?? '');
                $nota = (string) ($dato['nota'] ?? '');
                $url = (string) ($dato['url'] ?? '');
                $enlaceTexto = (string) ($dato['enlace_texto'] ?? '');
                $delay = 100 + ($i * 100);
                ?>
                <?php $pendiente = ($dato['pendiente'] ?? null) === true; ?>
                <?php /* El velo de oro del hover va en `before:` y no en un
                         <div>: dentro de un grupo del <dl> solo caben <dt> y
                         <dd>, y ese div de adorno era lo que tenía la
                         accesibilidad en 96 en vez de 100. */ ?>
                <div class="doble-bisel p-6 md:p-12 md:hover:bg-white/5 transition-colors duration-500 revelar group before:pointer-events-none before:absolute before:inset-0 before:bg-gradient-to-tr before:from-oro/5 before:to-transparent before:opacity-0 before:transition-opacity before:duration-700 md:hover:before:opacity-100" style="--retardo: <?= $delay ?>ms">
                    <dt class="rotulo text-acero mb-4 md:mb-6 relative z-10"><?= $e($etiqueta) ?></dt>

                    <dd class="relative z-10 flex flex-col h-full">
                        <span class="block break-words text-3xl md:text-5xl tracking-tight <?= $pendiente ? 'pendiente font-mono opacity-50' : 'text-transparent bg-clip-text bg-gradient-to-br from-white to-oro-claro drop-shadow-sm font-medium' ?>">
                            <?= $e($valor) ?>
                            <?php if ($pendiente): ?>
                            <span class="marca-pendiente text-[0.6rem] md:text-xs relative -top-2 md:-top-4 ml-2 md:ml-4">Pendiente</span>
                            <?php endif; ?>
                        </span>

                        <?php if ($nota !== ''): ?>
                        <span class="mt-4 md:mt-6 block text-[1rem] md:text-[1.1rem] text-acero leading-relaxed md:group-hover:text-papel transition-colors duration-500"><?= $e($nota) ?></span>
                        <?php endif; ?>

                        <?php if (!$pendiente && $url !== '' && $enlaceTexto !== ''): ?>
                        <div class="mt-auto pt-8">
                            <a href="<?= $e($url) ?>" class="menu-enlace inline-flex items-center gap-3 text-oro-claro md:group-hover:text-oro transition-colors duration-500 font-bold"
                               target="_blank" rel="noopener noreferrer">
                                <?= $e($enlaceTexto) ?>
                                <span aria-hidden="true" class="transform md:group-hover:translate-x-1 md:group-hover:-translate-y-1 transition-transform duration-300">↗</span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </dd>
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
        <div class="mt-6 md:mt-8 grid gap-6 md:gap-8 md:grid-cols-2">
            <?php foreach ($sedes as $i => $sede): ?>
                <?php
                $nombre = (string) ($sede['nombre'] ?? '');
                $direccion = (string) ($sede['direccion'] ?? '');
                $detalle = (string) ($sede['detalle'] ?? '');
                $horario = (string) ($sede['horario'] ?? '');
                $delay = 200 + ($i * 100);
                ?>
                <?php $pendiente = ($sede['pendiente'] ?? null) === true; ?>
                <div class="tarjeta p-6 md:p-12 md:hover:bg-white/5 transition-colors duration-500 flex flex-col justify-between revelar group" style="--retardo: <?= $delay ?>ms">
                    <div class="absolute inset-0 bg-gradient-to-tr from-white/5 to-transparent opacity-0 md:group-hover:opacity-100 transition-opacity duration-700 pointer-events-none"></div>
                    <div class="relative z-10">
                        <p class="rotulo text-acero mb-4 md:mb-6">Oficina</p>
                        <p class="text-2xl md:text-3xl font-medium tracking-tight text-white mb-3 md:mb-4 md:group-hover:text-oro-claro transition-colors duration-500"><?= $e($nombre) ?></p>

                        <address class="not-italic <?= $pendiente ? 'pendiente font-mono text-[0.95rem] md:text-[1rem]' : 'text-[1rem] md:text-[1.1rem] text-acero leading-relaxed' ?>">
                            <?= $e($direccion) ?>
                            <?php if ($pendiente): ?>
                            <span class="marca-pendiente text-xs ml-2 md:ml-3">Pendiente</span>
                            <?php endif; ?>
                        </address>
                    </div>

                    <div class="relative z-10 mt-6 md:mt-8">
                        <?php if ($horario !== ''): ?>
                        <p class="rotulo text-acero mb-4 md:mb-6"><?= $e($horario) ?></p>
                        <?php endif; ?>

                        <?php if ($detalle !== ''): ?>
                        <p class="pt-5 md:pt-6 border-t border-linea/30 text-[0.95rem] md:text-[1rem] text-acero/80 md:group-hover:text-acero transition-colors duration-500">
                            <?= $e($detalle) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php
        $sedesReales = array_filter($sedes, static fn (array $s): bool => ($s['pendiente'] ?? null) !== true);
        ?>
        <?php if ($invitacion !== '' && $sedesReales !== []): ?>
        <div class="mt-12 md:mt-16 max-w-3xl p-6 md:p-8 rounded-2xl bg-white/5 border border-white/10 backdrop-blur-md revelar">
            <p class="text-[1.05rem] md:text-[1.15rem] leading-relaxed text-papel border-l-2 border-oro pl-4 md:pl-6 font-medium">
                <?= $e($invitacion) ?>
            </p>
        </div>
        <?php endif; ?>
    </div>
</section>
