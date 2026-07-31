<?php

declare(strict_types=1);

namespace App\Soporte;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Toda la lógica de fechas del sistema pasa por aquí.
 *
 * Nunca `date()` ni `new DateTime()` desnudos para lógica de agenda: la
 * aplicación piensa en America/Bogota y la base guarda UTC, y mezclar las dos
 * cosas a mano es como se agenda una asesoría con una hora de diferencia.
 *
 * Los meses y días van en un array a mano, no con IntlDateFormatter, para no
 * depender de que la extensión intl esté compilada en el servidor
 * (docs/CONTRATOS.md).
 */
final class Fechas
{
    public const ZONA = 'America/Bogota';

    private const DIAS = [
        1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves',
        5 => 'viernes', 6 => 'sábado', 7 => 'domingo',
    ];

    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /** Congelable en pruebas: sin esto no se puede probar la agenda. */
    private static ?DateTimeImmutable $ahoraFijo = null;

    public static function zona(): DateTimeZone
    {
        return new DateTimeZone(self::ZONA);
    }

    public static function ahora(): DateTimeImmutable
    {
        if (self::$ahoraFijo instanceof DateTimeImmutable) {
            return self::$ahoraFijo;
        }

        return new DateTimeImmutable('now', self::zona());
    }

    /** Solo para pruebas. `null` devuelve el reloj real. */
    public static function congelar(?DateTimeImmutable $momento): void
    {
        self::$ahoraFijo = $momento?->setTimezone(self::zona());
    }

    public static function hoy(): string
    {
        return self::ahora()->format('Y-m-d');
    }

    /** 'martes 4 de agosto' */
    public static function fechaNatural(string $fecha): string
    {
        $d = self::desdeFecha($fecha);

        return sprintf(
            '%s %d de %s',
            self::DIAS[(int) $d->format('N')],
            (int) $d->format('j'),
            self::MESES[(int) $d->format('n')],
        );
    }

    /** 'martes 4 de agosto de 2026' — para correos y confirmaciones */
    public static function fechaNaturalConAnio(string $fecha): string
    {
        return self::fechaNatural($fecha) . ' de ' . self::desdeFecha($fecha)->format('Y');
    }

    /** '2:30 p. m.' — con el espacio fino que usa el español, no 'PM' */
    public static function horaNatural(string $hora): string
    {
        $h = DateTimeImmutable::createFromFormat('!H:i:s', self::normalizarHora($hora), self::zona());

        if ($h === false) {
            throw new \InvalidArgumentException("Hora inválida: {$hora}");
        }

        $sufijo = (int) $h->format('G') < 12 ? 'a. m.' : 'p. m.';
        $minutos = $h->format('i');
        $reloj = $minutos === '00' ? $h->format('g') : $h->format('g:i');

        return $reloj . ' ' . $sufijo;
    }

    public static function sumarMinutos(string $hora, int $min): string
    {
        $h = DateTimeImmutable::createFromFormat('!H:i:s', self::normalizarHora($hora), self::zona());

        if ($h === false) {
            throw new \InvalidArgumentException("Hora inválida: {$hora}");
        }

        return $h->modify("+{$min} minutes")->format('H:i:s');
    }

    /** Momento absoluto de (fecha, hora) menos N horas. Para recordatorios. */
    public static function restarHoras(string $fecha, string $hora, int $n): DateTimeImmutable
    {
        return self::combinar($fecha, $hora)->modify("-{$n} hours");
    }

    public static function combinar(string $fecha, string $hora): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $fecha . ' ' . self::normalizarHora($hora),
            self::zona(),
        );

        if ($d === false) {
            throw new \InvalidArgumentException("Fecha u hora inválida: {$fecha} {$hora}");
        }

        return $d;
    }

    /** Para escribir en base: la base guarda UTC (ADR de esquema). */
    public static function aUtc(DateTimeImmutable $d): DateTimeImmutable
    {
        return $d->setTimezone(new DateTimeZone('UTC'));
    }

    /** Para leer de base: llega UTC, la aplicación piensa en Bogotá. */
    public static function deUtc(string $datetimeUtc): DateTimeImmutable
    {
        $d = new DateTimeImmutable($datetimeUtc, new DateTimeZone('UTC'));

        return $d->setTimezone(self::zona());
    }

    /** Formato que espera MySQL en DATETIME. */
    public static function paraBd(DateTimeImmutable $d): string
    {
        return self::aUtc($d)->format('Y-m-d H:i:s');
    }

    private static function desdeFecha(string $fecha): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, self::zona());

        if ($d === false) {
            throw new \InvalidArgumentException("Fecha inválida: {$fecha}");
        }

        return $d;
    }

    /** Acepta 'H:i' además de 'H:i:s': el LLM y los formularios mandan ambas. */
    private static function normalizarHora(string $hora): string
    {
        return preg_match('/^\d{1,2}:\d{2}$/', $hora) === 1 ? $hora . ':00' : $hora;
    }
}
