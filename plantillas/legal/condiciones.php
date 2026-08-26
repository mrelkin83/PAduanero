<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Condiciones del servicio — `/condiciones`.
 *
 * ⚠ PENDIENTE DE APROBACIÓN DE PEDRO. Como toda pieza pública con su firma
 * profesional, este texto es un borrador hasta que él lo revise bajo el marco
 * de la Ley 1123 de 2007 (la cita vive en este comentario, no en la página).
 *
 * A diferencia de la política de privacidad, aquí NO se cita ninguna norma
 * con número: las reglas 1–3 del CLAUDE.md aplican de lleno, porque esto sí
 * es texto que un visitante puede leer como si le hablara de su caso.
 *
 * @var array{titulo:string,descripcion:string,indexable:bool,url:string,ruta:string} $meta
 * @var string $whatsapp
 */

$e = Vista::e(...);
$actualizada = '22 de agosto de 2026';

$cuerpoLegal = static function () use ($e, $whatsapp): void { ?>

    <section class="mt-12">
        <h2 class="titular-menor">1. Qué es este sitio</h2>
        <p class="cuerpo mt-4">
            Esta página informa sobre los servicios del despacho de Pedro, abogado
            especialista en derecho aduanero y comercio exterior. Su contenido es
            informativo.
        </p>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">2. Lo que este sitio no es</h2>
        <p class="cuerpo mt-4">
            Navegar este sitio, leer su contenido o completar el diagnóstico de
            <a href="/perfil" class="menu-enlace">/perfil</a> no constituye asesoría
            jurídica ni crea una relación abogado&#8209;cliente. El diagnóstico solo
            le ayuda a describir su situación con el vocabulario correcto antes de
            escribir; no es un concepto jurídico, no evalúa su caso y no sustituye
            la revisión del expediente por un abogado.
        </p>
        <p class="cuerpo mt-4">
            Ningún contenido de este sitio promete ni garantiza resultados: cada
            caso depende de sus propios hechos y de su expediente.
        </p>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">3. La asesoría</h2>
        <p class="cuerpo mt-4">
            La asesoría se coordina por WhatsApp<?= $whatsapp !== '' ? ' (+' . $e($whatsapp) . ')' : '' ?>.
            La modalidad, la duración y los honorarios vigentes son los publicados
            en este sitio al momento de agendar. La relación profesional con el
            abogado nace del acuerdo directo entre las partes al contratar la
            asesoría, no del uso de esta página.
        </p>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">4. Propiedad intelectual</h2>
        <p class="cuerpo mt-4">
            Los textos, la marca y el diseño de este sitio pertenecen al despacho.
            Puede citarlos con atribución; no puede reproducirlos con fines
            comerciales sin autorización.
        </p>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">5. Datos personales</h2>
        <p class="cuerpo mt-4">
            El tratamiento de datos personales se rige por la
            <a href="/privacidad" class="menu-enlace">política de tratamiento de datos personales</a>
            de este sitio.
        </p>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">6. Ley aplicable</h2>
        <p class="cuerpo mt-4">
            Estas condiciones se rigen por la ley colombiana. Cualquier cambio se
            publicará en esta página, con su fecha.
        </p>
    </section>

<?php };

require __DIR__ . '/_marco.php';
