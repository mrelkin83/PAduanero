<?php
/**
 * Placeholder de la Etapa 0. La landing real se construye en la Etapa 1
 * desde `landing_bloques`, con Tailwind y el botón de WhatsApp con UTMs.
 *
 * @var string $entorno
 */
?>
<!doctype html>
<html lang="es-CO">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Pedro · Abogado aduanero y tributario</title>
    <style>
        body { font: 16px/1.6 system-ui, sans-serif; max-width: 34rem;
               margin: 4rem auto; padding: 0 1.5rem; color: #1a1a1a; }
        code { background: #f2f2f2; padding: .1rem .35rem; border-radius: 3px; }
        .etapa { color: #666; font-size: .875rem; }
    </style>
</head>
<body>
    <h1>Plataforma en construcción</h1>

    <p class="etapa">
        Etapa 0 — cimientos. Entorno:
        <code><?= htmlspecialchars($entorno, ENT_QUOTES, 'UTF-8') ?></code>
    </p>

    <p>
        Si estás viendo esta página, el front controller, el autoload y la
        configuración del entorno están en pie. El estado de la base de datos
        se consulta aparte, en <a href="/salud">/salud</a>.
    </p>

    <p class="etapa">La landing pública llega en la Etapa 1.</p>
</body>
</html>
