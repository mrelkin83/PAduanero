<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\UsuarioRepo;
use App\Servicios\Autenticacion;
use App\Servicios\ChatwootAgentes;
use App\Soporte\Logger;

final class UsuariosControlador extends ControladorBase
{
    public function __construct(
        private readonly UsuarioRepo $usuarios,
        private readonly Autenticacion $auth,
        private readonly AuditoriaRepo $auditoria,
        private readonly BD $bd,
        private readonly Logger $log,
        private readonly ?ChatwootAgentes $chatwoot = null,
    ) {
    }

    public function listar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'usuarios.ver');

        return $this->vista('panel/usuarios', [
            'ctx' => $ctx,
            'usuarios' => $this->usuarios->listar(),
            'roles' => $this->bd->pdo()->query('SELECT id, clave, nombre FROM roles ORDER BY id')->fetchAll(),
            'puedeEditar' => $ctx->puede('usuarios.editar'),
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function crear(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'usuarios.editar');

        $email = mb_strtolower($ctx->campo('email'));
        $nombre = $ctx->campo('nombre');
        $password = $ctx->campo('password');
        $rolId = (int) $ctx->campo('rol_id');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->redirigirCon('/panel/usuarios', 'error', 'El correo no es válido.');
        }

        if ($nombre === '') {
            return $this->redirigirCon('/panel/usuarios', 'error', 'El nombre no puede estar vacío.');
        }

        // 12 caracteres para una cuenta que puede ver credenciales de una
        // pasarela de pagos. Es el mismo mínimo que bin/crear-usuario.php.
        if (mb_strlen($password) < 12) {
            return $this->redirigirCon('/panel/usuarios', 'error', 'La contraseña debe tener al menos 12 caracteres.');
        }

        $rol = $this->rol($rolId);

        if ($rol === null) {
            return $this->redirigirCon('/panel/usuarios', 'error', 'Ese rol no existe.');
        }

        try {
            $id = $this->usuarios->crear($email, $nombre, $password, $rolId);
        } catch (\PDOException $e) {
            return $this->redirigirCon(
                '/panel/usuarios',
                'error',
                BD::esDuplicado($e) ? 'Ya existe un usuario con ese correo.' : 'No se pudo crear el usuario.',
            );
        }

        $this->auditoria->registrar('usuario', $id, 'crear', $ctx->actor(), [
            'rol' => $rol['clave'],
        ], $ctx->ip());

        $mensaje = "Usuario creado con rol «{$rol['clave']}».";

        // Aprovisionamiento del agente en Chatwoot: una sola alta, no dos
        // (docs/PANEL_ADMIN.md §2.9).
        if (in_array($rol['clave'], ['abogado', 'asistente'], true)) {
            $mensaje .= ' ' . $this->aprovisionar($id, $email, $nombre, $ctx);
        }

        if (in_array($rol['clave'], ['super_admin', 'abogado'], true)) {
            $mensaje .= ' Este rol exige verificación en dos pasos: se le pedirá activarla al entrar.';
        }

        return $this->redirigirCon('/panel/usuarios', 'ok', $mensaje);
    }

    /**
     * Crea el agente en Chatwoot.
     *
     * **Nunca tumba el alta del usuario.** Chatwoot puede no estar desplegado
     * todavía (la Etapa 2 no cierra hasta que el PO ejecute el despliegue), o
     * estar caído. Que eso impida crear un usuario del panel sería acoplar
     * dos sistemas que no tienen por qué caerse juntos: se avisa y se puede
     * reintentar después.
     */
    private function aprovisionar(string $usuarioId, string $email, string $nombre, Contexto $ctx): string
    {
        if ($this->chatwoot === null) {
            return 'Chatwoot no está configurado todavía: el agente habrá que crearlo cuando lo esté.';
        }

        try {
            $agenteId = $this->chatwoot->crearAgente($email, $nombre);

            if ($agenteId === null) {
                return 'No se pudo crear el agente en Chatwoot; revisar y reintentar.';
            }

            $this->usuarios->guardarChatwootAgentId($usuarioId, $agenteId);

            $this->auditoria->registrar('usuario', $usuarioId, 'agente_chatwoot_creado', $ctx->actor(), [
                'chatwoot_agent_id' => $agenteId,
            ], $ctx->ip());

            return "Agente creado en Chatwoot (id {$agenteId}).";
        } catch (\Throwable $e) {
            $this->log->error('panel.chatwoot_aprovisionar_fallido', ['excepcion' => $e::class]);

            return 'El usuario quedó creado, pero falló el alta del agente en Chatwoot.';
        }
    }

    // ── Seguridad de la propia cuenta ────────────────────────────────────

    public function seguridad(Contexto $ctx): Respuesta
    {
        return $this->vista('panel/seguridad', [
            'ctx' => $ctx,
            'secreto' => $ctx->peticion->consulta['secreto'] ?? null,
            'uri' => $ctx->peticion->consulta['uri'] ?? null,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function prepararTotp(Contexto $ctx): Respuesta
    {
        $datos = $this->auth->prepararTotp($ctx->usuario);

        // El secreto viaja por la query solo hasta que se confirme; es de un
        // solo uso y queda inservible en cuanto se active o se regenere.
        return $this->redirigir('/panel/seguridad?' . http_build_query($datos));
    }

    public function confirmarTotp(Contexto $ctx): Respuesta
    {
        if (!$this->auth->confirmarTotp($ctx->usuario, $ctx->campo('codigo'), $ctx->ip())) {
            return $this->redirigirCon(
                '/panel/seguridad',
                'error',
                'El código no coincide. Revise que la hora del teléfono esté sincronizada.',
            );
        }

        return $this->redirigirCon('/panel/seguridad', 'ok', 'Verificación en dos pasos activada.');
    }

    /** @return array<string,mixed>|null */
    private function rol(int $id): ?array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT id, clave, nombre FROM roles WHERE id = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }
}
