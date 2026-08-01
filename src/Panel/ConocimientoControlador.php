<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;
use App\Servicios\BaseConocimiento;

/**
 * Base de conocimiento jurídico (Etapa 7).
 *
 * El ADR-007 parte esta pantalla en dos mitades que se ven juntas pero se
 * firman por separado:
 *
 *  · `kb.cargar` (técnico): crear documentos, pegar el texto, trocearlo,
 *    indexarlo. Nada de eso hace que el bot lo use.
 *  · `kb.verificar` (abogado): la firma. Solo un documento con
 *    `verificado_por` entra al RAG (regla 10), y quien firma responde por
 *    la vigencia de la norma — «la vigencia de cada norma la valida Pedro,
 *    no el desarrollador» (CLAUDE.md §6).
 */
final class ConocimientoControlador extends ControladorBase
{
    /** Tamaño objetivo del fragmento, en caracteres. */
    private const CHUNK = 900;

    public function __construct(
        private readonly BD $bd,
        private readonly BaseConocimiento $conocimiento,
        private readonly AuditoriaRepo $auditoria,
    ) {
    }

    public function inicio(Contexto $ctx): Respuesta
    {
        // `kb.cargar` como permiso de lectura: quien puede cargar puede ver,
        // y el abogado y el asistente lo tienen. No existe un `kb.ver`
        // aparte en la matriz sembrada.
        $ctx->permisos->exigir($ctx->usuario, 'kb.cargar');

        $documentos = $this->bd->pdo()->query(
            'SELECT d.*,
                    (SELECT COUNT(*) FROM kb_chunks c WHERE c.documento_id = d.id) AS chunks,
                    (SELECT COUNT(*) FROM kb_chunks c
                      WHERE c.documento_id = d.id AND c.embedding IS NOT NULL) AS indexados
               FROM kb_documentos d
              ORDER BY (d.verificado_por IS NULL) DESC, d.creado_en DESC'
        )->fetchAll();

        // El buscador de prueba: mismo camino que usará el bot, para que lo
        // que Pedro ve aquí sea exactamente lo que el motor recuperaría.
        $consulta = trim((string) ($ctx->peticion->consulta['q'] ?? ''));
        $resultados = $consulta !== ''
            ? $this->conocimiento->buscar($consulta, null, null, 4)
            : [];

        return $this->vista('panel/conocimiento', [
            'ctx' => $ctx,
            'documentos' => $documentos,
            'consulta' => $consulta,
            'resultados' => $resultados,
            'puedeCargar' => $ctx->puede('kb.cargar'),
            'puedeVerificar' => $ctx->puede('kb.verificar'),
            'avisos' => $this->avisos($ctx),
        ]);
    }

    /**
     * Crea un documento y lo trocea en chunks.
     *
     * Nace SIN verificar: cargarlo no lo mete al RAG. El troceo es por
     * párrafos con tope de tamaño — un fragmento de 900 caracteres cabe de
     * a cuatro en un prompt sin diluir la atención del modelo.
     */
    public function crear(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'kb.cargar');

        $titulo = trim($ctx->campo('titulo'));
        $contenido = trim($ctx->campo('contenido'));
        $area = $ctx->campo('area', 'ambos');
        $tipoCaso = trim($ctx->campo('tipo_caso'));

        if ($titulo === '' || mb_strlen($contenido) < 40) {
            return $this->redirigirCon(
                '/panel/conocimiento',
                'error',
                'Falta el título, o el contenido es demasiado corto para trocearse.',
            );
        }

        if (!in_array($area, ['aduanero', 'tributario', 'ambos'], true)) {
            return $this->redirigirCon('/panel/conocimiento', 'error', 'Área desconocida.');
        }

        // El tipo de caso se sanea contra el catálogo normativo (CLAUDE.md
        // §5), igual que hace el saneador de acciones del motor: un tipo
        // inventado aquí filtraría fragmentos que nunca se recuperarían.
        if ($tipoCaso !== '' && !in_array($tipoCaso, \App\Motor\Catalogo::tipos(), true)) {
            return $this->redirigirCon(
                '/panel/conocimiento',
                'error',
                "«{$tipoCaso}» no está en el catálogo de tipos de caso.",
            );
        }

        $pdo = $this->bd->pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'INSERT INTO kb_documentos (titulo, area, tipo_fuente, referencia, url_oficial, vigente)
                 VALUES (?, ?, ?, ?, ?, 1)'
            )->execute([
                $titulo,
                $area,
                $ctx->campo('tipo_fuente', 'escenario'),
                trim($ctx->campo('referencia')) !== '' ? trim($ctx->campo('referencia')) : null,
                trim($ctx->campo('url_oficial')) !== '' ? trim($ctx->campo('url_oficial')) : null,
            ]);

            $documentoId = (string) $pdo->query(
                'SELECT id FROM kb_documentos ORDER BY creado_en DESC, id DESC LIMIT 1'
            )->fetchColumn();

            $insertar = $pdo->prepare(
                'INSERT INTO kb_chunks (documento_id, orden, contenido, tipo_caso) VALUES (?, ?, ?, ?)'
            );

            foreach ($this->trocear($contenido) as $orden => $trozo) {
                $insertar->execute([
                    $documentoId,
                    $orden + 1,
                    $trozo,
                    $tipoCaso !== '' ? $tipoCaso : null,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        $this->auditoria->registrar(
            'kb_documento',
            $documentoId,
            'crear',
            $ctx->actor(),
            ['titulo' => $titulo, 'area' => $area],
            $ctx->ip(),
        );

        return $this->redirigirCon(
            '/panel/conocimiento',
            'ok',
            'Documento cargado y troceado. Queda PENDIENTE de verificación: '
            . 'el bot no lo usará hasta que el abogado lo firme.',
        );
    }

    /**
     * La firma del abogado (regla 10). Con ella el documento entra al RAG.
     */
    public function verificar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'kb.verificar');

        $id = $ctx->campo('id');
        $stmt = $this->bd->pdo()->prepare('SELECT titulo, verificado_por FROM kb_documentos WHERE id = ?');
        $stmt->execute([$id]);
        $documento = $stmt->fetch();

        if ($documento === false) {
            return $this->redirigirCon('/panel/conocimiento', 'error', 'Ese documento no existe.');
        }

        $this->bd->pdo()->prepare(
            'UPDATE kb_documentos SET verificado_por = ?, verificado_en = CURDATE() WHERE id = ?'
        )->execute([$ctx->usuario?->nombre ?? $ctx->actor(), $id]);

        // La indexación va DESPUÉS de la firma y no al cargar: vectorizar
        // cuesta dinero, y un documento que el abogado rechace no debería
        // haber gastado nada.
        try {
            $this->conocimiento->indexarDocumento($id);
            $mensaje = 'Verificado e indexado. Ya está disponible para el bot.';
        } catch (\Throwable $e) {
            $mensaje = 'Verificado. La indexación falló (' . mb_substr($e->getMessage(), 0, 120)
                . '): el documento sirve por búsqueda léxica y se indexará al reintentar.';
        }

        $this->auditoria->registrar(
            'kb_documento',
            $id,
            'verificar',
            $ctx->actor(),
            ['titulo' => $documento['titulo']],
            $ctx->ip(),
        );

        return $this->redirigirCon('/panel/conocimiento', 'ok', $mensaje);
    }

    /** Retira un documento del RAG sin borrarlo (vigencia, no eliminación). */
    public function alternarVigencia(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'kb.verificar');

        $id = $ctx->campo('id');
        $stmt = $this->bd->pdo()->prepare('SELECT vigente FROM kb_documentos WHERE id = ?');
        $stmt->execute([$id]);
        $vigente = $stmt->fetchColumn();

        if ($vigente === false) {
            return $this->redirigirCon('/panel/conocimiento', 'error', 'Ese documento no existe.');
        }

        $nuevo = ((int) $vigente) === 1 ? 0 : 1;

        $this->bd->pdo()
            ->prepare('UPDATE kb_documentos SET vigente = ? WHERE id = ?')
            ->execute([$nuevo, $id]);

        $this->auditoria->registrar(
            'kb_documento',
            $id,
            $nuevo === 1 ? 'reactivar' : 'retirar',
            $ctx->actor(),
            [],
            $ctx->ip(),
        );

        return $this->redirigirCon(
            '/panel/conocimiento',
            'ok',
            $nuevo === 1 ? 'Documento reactivado.' : 'Documento retirado del RAG. No se borró nada.',
        );
    }

    /**
     * Troceo por párrafos con tope de tamaño.
     *
     * Un párrafo que excede el tope se corta por frases. Nunca por mitad de
     * palabra: un fragmento que empieza en «...arancelaria» ya no encuentra
     * «clasificación arancelaria» en el FULLTEXT.
     *
     * @return list<string>
     */
    private function trocear(string $contenido): array
    {
        $parrafos = preg_split('/\n{2,}/u', $contenido) ?: [$contenido];
        $trozos = [];
        $actual = '';

        foreach ($parrafos as $parrafo) {
            $parrafo = trim($parrafo);

            if ($parrafo === '') {
                continue;
            }

            if ($actual !== '' && mb_strlen($actual) + mb_strlen($parrafo) + 2 > self::CHUNK) {
                $trozos[] = $actual;
                $actual = '';
            }

            if (mb_strlen($parrafo) > self::CHUNK) {
                foreach (preg_split('/(?<=[.!?])\s+/u', $parrafo) ?: [] as $frase) {
                    if ($actual !== '' && mb_strlen($actual) + mb_strlen($frase) + 1 > self::CHUNK) {
                        $trozos[] = $actual;
                        $actual = '';
                    }

                    $actual = $actual === '' ? $frase : $actual . ' ' . $frase;
                }

                continue;
            }

            $actual = $actual === '' ? $parrafo : $actual . "\n\n" . $parrafo;
        }

        if (trim($actual) !== '') {
            $trozos[] = $actual;
        }

        return $trozos;
    }
}
