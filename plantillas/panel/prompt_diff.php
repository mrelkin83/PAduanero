<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var array{id:string,version:int,contenido:string} $a
 * @var array{id:string,version:int,contenido:string} $b
 * @var list<array{signo:string,texto:string}> $lineas
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Diferencias entre versiones';

$contenido = static function () use ($e, $a, $b, $lineas): void {
    ?>

    <section>
        <p class="text-sm text-acero">
            Comparando <span class="font-mono">v<?= (int) $a['version'] ?></span>
            con <span class="font-mono">v<?= (int) $b['version'] ?></span>.
            Es lo que hay que mirar cuando el bot empieza a comportarse distinto:
            casi siempre es una frase que se añadió o se quitó.
        </p>
    </section>

    <?php if ($lineas === []): ?>
        <p class="mt-6 text-sm text-acero">Las dos versiones tienen el mismo texto.</p>
    <?php else: ?>
    <section class="tarjeta mt-6 p-4">
        <div class="overflow-x-auto">
        <pre class="font-mono text-xs leading-relaxed"><?php foreach ($lineas as $linea): ?><span class="<?= $linea['signo'] === '+' ? 'text-verde' : 'text-sello' ?>"><?= $e($linea['signo'] . ' ' . $linea['texto']) ?></span>
<?php endforeach; ?></pre>
        </div>
    </section>
    <?php endif; ?>

    <p class="mt-6">
        <a href="/panel/prompts" class="boton-secundario">Volver</a>
    </p>

    <?php
};

require __DIR__ . '/_disposicion.php';
