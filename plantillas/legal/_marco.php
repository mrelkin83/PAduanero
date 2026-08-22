<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Marco de las páginas legales. Lo incluyen `privacidad.php` y
 * `condiciones.php` después de definir su `$cuerpoLegal`.
 *
 * Misma paleta Lex Aeterna y mismo pie que el resto del sitio público, y la
 * misma regla de la casa: cero JavaScript. Aquí ni siquiera hace falta la red
 * del revelado, porque nada se esconde — el documento se emite entero y
 * visible, que es exactamente lo que debe ser un texto legal.
 *
 * @var array{titulo:string,descripcion:string,indexable:bool,url:string,ruta:string} $meta
 * @var string   $whatsapp     número del negocio, para el canal de contacto
 * @var string   $actualizada  fecha visible de la última revisión del texto
 * @var callable $cuerpoLegal  las secciones propias de cada página
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($meta['titulo']) ?> · Pedro, abogado aduanero y tributario</title>
<meta name="description" content="<?= $e($meta['descripcion']) ?>">
<?php if (!$meta['indexable']): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<link rel="canonical" href="<?= $e($meta['url'] . $meta['ruta']) ?>">
<link rel="icon" href="/img/icono.svg" type="image/svg+xml">
<style><?= $css ?></style>
</head>

<body class="perfil-cuerpo">
<a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:bg-oro focus:px-4 focus:py-2 focus:text-tinta">
    Saltar al contenido
</a>

<header class="barra-perfil">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="/" class="boton-fantasma compacto shrink-0">
            <span aria-hidden="true">&larr;</span> Inicio
        </a>
    </div>
</header>

<main id="contenido" class="mx-auto max-w-3xl px-6 py-16 md:py-24">
    <p class="rotulo text-acero">Documento legal</p>
    <h1 class="titular-seccion mt-4"><?= $e($meta['titulo']) ?></h1>
    <p class="cuerpo mt-4 text-acero">Última actualización: <?= $e($actualizada) ?></p>

    <?php $cuerpoLegal(); ?>
</main>

<?php require dirname(__DIR__) . '/landing/bloques/pie.php'; ?>

</body>
</html>
