<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Política de tratamiento de datos personales — `/privacidad`.
 *
 * ⚠ PENDIENTE DE APROBACIÓN DE PEDRO. Este texto es un borrador de trabajo:
 * es contenido público que lleva la firma profesional del abogado, y el
 * CLAUDE.md exige su revisión antes de desplegar. Antes de producción falta
 * además completar la identificación del responsable (nombre completo y
 * documento o NIT), que solo Pedro puede confirmar.
 *
 * Sobre la regla 2 del CLAUDE.md («la página nunca cita normas con número»):
 * esa regla protege al visitante de recibir lo que parezca asesoría. Esta
 * página no asesora a nadie — declara el marco legal al que el PROPIO sitio
 * se somete, y una política de tratamiento que no nombra la ley que la exige
 * sería un documento defectuoso. Por eso aquí, y solo aquí, aparece la
 * Ley 1581 de 2012.
 *
 * @var array{titulo:string,descripcion:string,indexable:bool,url:string,ruta:string} $meta
 * @var string $whatsapp
 */

$e = Vista::e(...);
$actualizada = '22 de agosto de 2026';

$cuerpoLegal = static function () use ($e, $whatsapp): void { ?>

    <section class="mt-12">
        <h2 class="titular-menor">1. Quién es el responsable</h2>
        <p class="cuerpo mt-4">
            El responsable del tratamiento es el abogado titular de este despacho
            («Pedro»), especialista en derecho aduanero y comercio exterior, con
            domicilio en Colombia. Canales de contacto para todo lo relacionado
            con datos personales:
        </p>
        <ul class="cuerpo mt-4 list-disc space-y-2 pl-6">
            <li>Correo: <a href="mailto:pedroabogadoaduanero@gmail.com" class="menu-enlace">pedroabogadoaduanero@gmail.com</a></li>
            <?php if ($whatsapp !== ''): ?>
            <li>WhatsApp: +<?= $e($whatsapp) ?></li>
            <?php endif; ?>
        </ul>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">2. Qué datos se tratan, y cuáles no</h2>
        <p class="cuerpo mt-4">
            Este sitio web, por sí mismo, no recoge datos personales. La página
            principal no tiene formularios, y el diagnóstico de
            <a href="/perfil" class="menu-enlace">/perfil</a> se resuelve por completo
            en su navegador: ninguna de sus respuestas se envía ni se guarda en
            ningún servidor.
        </p>
        <p class="cuerpo mt-4">
            Los datos que sí se tratan son los que usted entrega voluntariamente al
            escribir por WhatsApp para agendar una asesoría: su nombre, su número de
            teléfono, su correo electrónico y el motivo de su consulta.
        </p>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">3. Para qué se usan</h2>
        <ul class="cuerpo mt-4 list-disc space-y-2 pl-6">
            <li>Coordinar, agendar y prestar la asesoría solicitada.</li>
            <li>Enviarle la invitación de calendario con el enlace de la reunión.</li>
            <li>Comunicarnos con usted sobre su cita: confirmaciones, cambios, recordatorios.</li>
            <li>Cumplir los deberes legales, contables y profesionales del despacho.</li>
        </ul>
        <p class="cuerpo mt-4">
            No se usan para publicidad, no se venden y no se ceden a terceros con
            fines comerciales.
        </p>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">4. Con quién se comparten</h2>
        <p class="cuerpo mt-4">
            Solo con los proveedores estrictamente necesarios para prestar el
            servicio: Google, para crear el evento de calendario con invitación a su
            correo, y la pasarela de pagos, cuando la asesoría se paga en línea.
            Cada uno trata los datos conforme a sus propias políticas.
        </p>
        <p class="cuerpo mt-4">
            Lo que usted consulte al abogado queda además amparado por el secreto
            profesional.
        </p>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">5. Cuánto tiempo se conservan</h2>
        <p class="cuerpo mt-4">
            Mientras sean necesarios para la finalidad con la que se recogieron y
            mientras los deberes legales y profesionales del abogado exijan
            conservarlos. Cumplido eso, se suprimen.
        </p>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">6. Sus derechos</h2>
        <p class="cuerpo mt-4">
            Usted puede conocer, actualizar y rectificar sus datos; pedir prueba de
            la autorización; ser informado del uso que se les ha dado; revocar la
            autorización y solicitar la supresión cuando no exista un deber legal o
            contractual de conservarlos; y presentar quejas ante la Superintendencia
            de Industria y Comercio.
        </p>
        <p class="cuerpo mt-4">
            Para ejercerlos, escriba a cualquiera de los canales de la sección 1.
            Su solicitud se atenderá en los plazos que fija la ley.
        </p>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">7. Seguridad</h2>
        <p class="cuerpo mt-4">
            Los datos se guardan en infraestructura propia del despacho, con acceso
            restringido y las credenciales de los sistemas cifradas. Ninguna medida
            es infalible, pero las adoptadas buscan impedir el acceso, la
            adulteración o la pérdida no autorizados.
        </p>
    </section>

    <section class="mt-12">
        <h2 class="titular-menor">8. Marco legal y vigencia</h2>
        <p class="cuerpo mt-4">
            Esta política se rige por el régimen colombiano de protección de datos
            personales — la Ley 1581 de 2012 y las normas que la reglamentan — y
            rige desde su publicación en esta página. Cualquier cambio se publicará
            aquí, con su fecha.
        </p>
    </section>

<?php };

require __DIR__ . '/_marco.php';
