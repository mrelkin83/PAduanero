<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\AuditoriaRepo;
use App\Repositorios\CasoRepo;
use App\Repositorios\ConsentimientoRepo;
use App\Repositorios\ContactoRepo;
use App\Repositorios\ConversacionEstadoRepo;
use App\Motor\Estados;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * Contactos, consentimientos, casos y estado conversacional.
 *
 * Lo que se defiende aquí, por orden de gravedad si falla: el hash del
 * teléfono (ADR-012), el radicado consecutivo (ADR-014), y el apagado de la
 * IA que no se revierte solo (regla 8).
 */
#[Group('critica')]
final class RepositoriosMotorTest extends CasoBaseBd
{
    private ContactoRepo $contactos;
    private ConsentimientoRepo $consentimientos;
    private CasoRepo $casos;
    private ConversacionEstadoRepo $conversaciones;

    protected function setUp(): void
    {
        parent::setUp();

        $cifrado = Cifrado::desdeEntorno();

        $this->contactos = new ContactoRepo($this->bd, $cifrado, new AuditoriaRepo($this->bd));
        $this->consentimientos = new ConsentimientoRepo($this->bd);
        $this->casos = new CasoRepo($this->bd);
        $this->conversaciones = new ConversacionEstadoRepo($this->bd);
    }

    // ── Contactos ────────────────────────────────────────────────────────

    #[Test]
    public function elTelefonoSeIndexaConHmacYNoConSha256Pelado(): void
    {
        // Un número de doce dígitos con SHA-256 a secas se rompe por fuerza
        // bruta en segundos. Con el pepper, no (ADR-012).
        $contacto = $this->contactos->crear('573159923676', 'whatsapp');

        $stmt = $this->bd->pdo()->prepare('SELECT telefono_hash FROM contactos WHERE id = ?');
        $stmt->execute([$contacto->id]);
        $hash = (string) $stmt->fetchColumn();

        self::assertNotSame(hash('sha256', '573159923676'), $hash);
        self::assertSame(Cifrado::desdeEntorno()->hashTelefono('573159923676'), $hash);
    }

    #[Test]
    public function crearDosVecesElMismoNumeroDevuelveElMismoContacto(): void
    {
        // En WhatsApp el mismo número escribe meses después, y dos mensajes
        // simultáneos de un contacto nuevo llegan a la vez.
        $primero = $this->contactos->crear('573001112233', 'whatsapp');
        $segundo = $this->contactos->crear('573001112233', 'instagram');

        self::assertSame($primero->id, $segundo->id);
        self::assertSame('whatsapp', $segundo->canalOrigen, 'no se pisa el canal original');
    }

    #[Test]
    public function seBuscaPorTelefonoAunqueLaColumnaEnClaroExista(): void
    {
        $creado = $this->contactos->crear('573001112233', 'whatsapp');

        self::assertSame($creado->id, $this->contactos->porTelefono('573001112233')?->id);
        self::assertNull($this->contactos->porTelefono('573009998877'));
    }

    #[Test]
    public function elNitNoViajaEnElDtoYSuLecturaDejaHuella(): void
    {
        // Regla 13. El DTO va al prompt, a los registros y a las vistas: si
        // el NIT estuviera dentro, saldría por alguno de los tres.
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');
        $this->contactos->guardarNit($contacto->id, '900123456-7');

        $releido = $this->contactos->porId($contacto->id);

        self::assertTrue($releido->tieneNit);
        self::assertObjectNotHasProperty('nit', $releido);

        // Y en base no está en claro.
        $stmt = $this->bd->pdo()->prepare('SELECT nit_cifrado FROM contactos WHERE id = ?');
        $stmt->execute([$contacto->id]);
        $blob = (string) $stmt->fetchColumn();
        self::assertStringNotContainsString('900123456', $blob);

        self::assertSame('900123456-7', $this->contactos->nit($contacto->id, 'pruebas'));

        $stmt = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM auditoria WHERE entidad = ? AND entidad_id = ? AND accion = ?'
        );
        $stmt->execute(['contacto', $contacto->id, 'leer_nit']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    // ── Consentimiento (regla 1) ─────────────────────────────────────────

    #[Test]
    public function laNegativaTambienSeRegistra(): void
    {
        // Sin la fila, el motor no distingue «dijo que no» de «todavía no le
        // he preguntado», y volvería a preguntarle a quien ya se negó.
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');

        $this->consentimientos->registrar($contacto->id, 'v1', 'Aviso…', otorgado: false);

        self::assertFalse($this->consentimientos->tieneVigente($contacto->id));
        self::assertNotNull($this->consentimientos->ultimoPorContacto($contacto->id));
        self::assertFalse($this->consentimientos->ultimoPorContacto($contacto->id)->otorgado);
    }

    #[Test]
    public function seGuardaElTextoExactoQueSeMostro(): void
    {
        // Lo que hay que poder demostrar dentro de dos años es qué decía el
        // aviso el día que esta persona respondió, no qué dice hoy.
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');
        $texto = 'Autorizo el tratamiento de mis datos conforme a la política vigente.';

        $this->consentimientos->registrar($contacto->id, 'v1', $texto, otorgado: true);

        self::assertSame($texto, $this->consentimientos->vigentePorContacto($contacto->id)?->textoMostrado);
    }

    #[Test]
    public function revocarDejaDeSerVigenteYVuelveASerloSiAceptaOtraVez(): void
    {
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');

        $this->consentimientos->registrar($contacto->id, 'v1', 'Aviso v1', otorgado: true);
        self::assertTrue($this->consentimientos->tieneVigente($contacto->id));

        self::assertSame(1, $this->consentimientos->revocar($contacto->id));
        self::assertFalse($this->consentimientos->tieneVigente($contacto->id));

        $this->consentimientos->registrar($contacto->id, 'v2', 'Aviso v2', otorgado: true);
        self::assertSame('v2', $this->consentimientos->vigentePorContacto($contacto->id)?->versionPolitica);
    }

    #[Test]
    public function revocarAlcanzaATodosLosVigentesYNoSoloAlUltimo(): void
    {
        // Si quedara uno abierto, el gate de la regla 1 seguiría dejando pasar.
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');

        $this->consentimientos->registrar($contacto->id, 'v1', 'Aviso', otorgado: true);
        $this->consentimientos->registrar($contacto->id, 'v1', 'Aviso', otorgado: true);

        self::assertSame(2, $this->consentimientos->revocar($contacto->id));
        self::assertFalse($this->consentimientos->tieneVigente($contacto->id));
    }

    // ── Radicado (ADR-014) ───────────────────────────────────────────────

    #[Test]
    public function elRadicadoEsConsecutivoYConFormato(): void
    {
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');
        $anio = date('Y');

        $primero = $this->casos->crear($contacto->id, ['tipo_caso' => 'decomiso']);
        $segundo = $this->casos->crear($contacto->id, ['tipo_caso' => 'aprehension_mercancia']);

        self::assertSame("PA-{$anio}-000001", $primero->radicadoInterno);
        self::assertSame("PA-{$anio}-000002", $segundo->radicadoInterno);
    }

    #[Test]
    public function elRadicadoNoSaleDeMaxIdMasUno(): void
    {
        // Con MAX(id)+1, borrar el último caso haría que el siguiente
        // reutilizara su radicado. La secuencia no retrocede.
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');
        $anio = date('Y');

        $this->casos->crear($contacto->id, ['tipo_caso' => 'decomiso']);
        $segundo = $this->casos->crear($contacto->id, ['tipo_caso' => 'decomiso']);

        $this->bd->pdo()->prepare('DELETE FROM casos WHERE id = ?')->execute([$segundo->id]);

        $tercero = $this->casos->crear($contacto->id, ['tipo_caso' => 'decomiso']);

        self::assertSame("PA-{$anio}-000003", $tercero->radicadoInterno);
    }

    // ── Casos ────────────────────────────────────────────────────────────

    #[Test]
    public function unTipoTributarioNoSeClasificaComoAduanero(): void
    {
        // El handler de fuera de alcance decía «el despacho se dedica
        // exclusivamente a derecho aduanero». Con esa premisa se rechaza a un
        // cliente de requerimiento especial, que es negocio (CLAUDE.md §5).
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');

        $caso = $this->casos->crear($contacto->id, ['tipo_caso' => 'requerimiento_especial']);

        self::assertSame('tributario', $caso->area);
    }

    #[Test]
    public function unTipoComunAAmbasRamasNoSeFuerzaAAduanero(): void
    {
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');

        $caso = $this->casos->crear($contacto->id, ['tipo_caso' => 'recurso_reconsideracion']);

        self::assertSame('mixto', $caso->area);
    }

    #[Test]
    public function unTipoInventadoCaeEnOtroYSeMarcaParaRevision(): void
    {
        // No se descarta: puede ser negocio que el catálogo no cubre.
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');

        $caso = $this->casos->crear($contacto->id, ['tipo_caso' => 'divorcio_express']);

        self::assertSame('otro', $caso->tipoCaso);
        self::assertTrue($caso->requiereRevision);
    }

    #[Test]
    public function elPuntajeSeCalculaAlCrearYSeRecalculaAlActualizar(): void
    {
        // Cambiar el valor sin recalcular deja la bandeja ordenada por un
        // número obsoleto, que es peor que no ordenarla porque parece bien.
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');

        $caso = $this->casos->crear($contacto->id, [
            'tipo_caso' => 'decomiso',
            'tiene_acto_admin' => true,
            'urgencia' => 'alta',
            'entidad' => 'dian',
            'valor_estimado_cop' => 30_000_000,
        ]);

        // 25 (acto) + 15 (alta) + 14 (≥20 M) + 5 (DIAN) = 59
        self::assertSame(59, $caso->puntajeLead);

        $actualizado = $this->casos->actualizar($caso->id, ['valor_estimado_cop' => 600_000_000]);

        // el tramo de valor sube de 14 a 30
        self::assertSame(75, $actualizado->puntajeLead);
    }

    #[Test]
    public function laBandejaOrdenaPorUrgenciaAntesQuePorPuntaje(): void
    {
        // Un caso crítico de poco valor no puede quedar detrás de uno
        // tranquilo de mucho (CLAUDE.md §3.2).
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');

        $this->casos->crear($contacto->id, [
            'tipo_caso' => 'decomiso',
            'urgencia' => 'baja',
            'valor_estimado_cop' => 900_000_000,
            'tiene_acto_admin' => true,
        ]);
        $critico = $this->casos->crear($contacto->id, [
            'tipo_caso' => 'operativo_polfa',
            'urgencia' => 'critica',
        ]);

        $bandeja = $this->casos->listarParaPanel();

        self::assertSame($critico->id, $bandeja[0]['id']);
    }

    // ── Estado conversacional ────────────────────────────────────────────

    #[Test]
    public function buscarOCrearEsIdempotente(): void
    {
        $primero = $this->conversaciones->buscarOCrear(42);
        $segundo = $this->conversaciones->buscarOCrear(42);

        self::assertSame($primero->id, $segundo->id);
        self::assertSame(Estados::NUEVO, $segundo->nodo());
        self::assertTrue($segundo->iaActiva);
    }

    #[Test]
    public function apagarLaIaNoSeRevierteSolo(): void
    {
        // Regla 8. Ningún camino del motor la vuelve a encender: solo
        // `reactivarIa()`, que invoca una persona.
        $this->conversaciones->buscarOCrear(42);
        $this->conversaciones->apagarIa(42);

        $estado = $this->conversaciones->porConversacion(42);

        self::assertFalse($estado->iaActiva);
        self::assertFalse($estado->puedeResponderIa());
        self::assertSame(Estados::HUMANO, $estado->nodo());

        // Guardar turnos después no la reactiva.
        $this->conversaciones->guardarTurno(42, [['role' => 'user', 'content' => 'hola']]);

        self::assertFalse($this->conversaciones->porConversacion(42)->iaActiva);
    }

    #[Test]
    public function reactivarEsExplicitoYVuelveATriage(): void
    {
        $this->conversaciones->buscarOCrear(42);
        $this->conversaciones->apagarIa(42);
        $this->conversaciones->reactivarIa(42);

        $estado = $this->conversaciones->porConversacion(42);

        self::assertTrue($estado->iaActiva);
        self::assertTrue($estado->puedeResponderIa());
    }

    #[Test]
    public function unaPausaTemporalNoEsUnApagado(): void
    {
        // Son dos mecanismos distintos: la pausa vuelve sola, el apagado no.
        $this->conversaciones->buscarOCrear(42);
        $this->conversaciones->pausar(42, 10);

        $estado = $this->conversaciones->porConversacion(42);

        self::assertTrue($estado->iaActiva);
        self::assertFalse($estado->puedeResponderIa());
        self::assertTrue($estado->puedeResponderIa('+20 minutes'));
    }

    #[Test]
    public function laPausaSeLeeEnUtcAunquePhpPienseEnBogota(): void
    {
        // La conexión fija `time_zone = '+00:00'`: la base guarda UTC y la
        // aplicación convierte al presentar. Leer `pausada_hasta` con
        // `strtotime()` a secas lo interpretaría en la zona de PHP y erraría
        // cinco horas — y hacia el lado peligroso, porque la pausa parecería
        // haber vencido cuando no.
        //
        // Sin fijar la zona a mano, esta prueba pasa o falla según qué otra
        // clase haya construido `Aplicacion` antes. Se fija.
        $anterior = date_default_timezone_get();
        date_default_timezone_set('America/Bogota');

        try {
            $this->conversaciones->buscarOCrear(42);
            $this->conversaciones->pausar(42, 30);

            self::assertFalse($this->conversaciones->porConversacion(42)->puedeResponderIa());

            date_default_timezone_set('UTC');

            self::assertFalse(
                $this->conversaciones->porConversacion(42)->puedeResponderIa(),
                'el veredicto no puede depender de la zona horaria de PHP',
            );
        } finally {
            date_default_timezone_set($anterior);
        }
    }

    #[Test]
    public function elBufferDeRafagaAcumulaYNoMueveLaVentana(): void
    {
        // Si `buffer_hasta` se moviera con cada mensaje, alguien escribiendo
        // sin parar dejaría al bot mudo indefinidamente (CLAUDE.md §7.5).
        $this->conversaciones->buscarOCrear(42);

        self::assertSame(1, $this->conversaciones->acumularBuffer(42, 'me aprehendieron', 8));
        $primeraVentana = $this->conversaciones->porConversacion(42)->bufferHasta;

        self::assertSame(2, $this->conversaciones->acumularBuffer(42, 'la mercancía', 8));
        self::assertSame(3, $this->conversaciones->acumularBuffer(42, 'ayer en Buenaventura', 8));

        $estado = $this->conversaciones->porConversacion(42);

        self::assertSame($primeraVentana, $estado->bufferHasta);
        self::assertSame(['me aprehendieron', 'la mercancía', 'ayer en Buenaventura'], $estado->buffer);
    }

    #[Test]
    public function guardarElTurnoVaciaElBufferYCuentaTurnosEnSql(): void
    {
        // El incremento va en SQL: leído y reescrito desde PHP, dos
        // peticiones a la vez perderían una cuenta y el tope de turnos
        // dejaría de cortar sin que nadie lo note (CLAUDE.md §7.7).
        $this->conversaciones->buscarOCrear(42);
        $this->conversaciones->acumularBuffer(42, 'hola', 8);

        $this->conversaciones->guardarTurno(
            42,
            [['role' => 'user', 'content' => 'hola'], ['role' => 'assistant', 'content' => 'buenas']],
            tokens: 120,
        );

        $estado = $this->conversaciones->porConversacion(42);

        self::assertSame([], $estado->buffer);
        self::assertNull($estado->bufferHasta);
        self::assertSame(1, $estado->turnos);
        self::assertSame(120, $estado->tokensConsumidos);
        self::assertCount(2, $estado->historial);

        $this->conversaciones->guardarTurno(42, [], tokens: 80);

        self::assertSame(2, $this->conversaciones->porConversacion(42)->turnos);
        self::assertSame(200, $this->conversaciones->porConversacion(42)->tokensConsumidos);
    }

    #[Test]
    public function elWorkerEncuentraLasRafagasVencidas(): void
    {
        $this->conversaciones->buscarOCrear(42);
        $this->conversaciones->acumularBuffer(42, 'hola', 8);

        self::assertFalse($this->conversaciones->bufferListo(42));
        self::assertSame([], $this->conversaciones->conBufferVencido());

        $this->bd->pdo()->exec(
            'UPDATE conversacion_estado SET buffer_hasta = DATE_SUB(NOW(), INTERVAL 1 SECOND)'
        );

        self::assertTrue($this->conversaciones->bufferListo(42));
        self::assertSame([42], $this->conversaciones->conBufferVencido());
    }
}
