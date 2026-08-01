<?php

declare(strict_types=1);

namespace App\Motor;

/**
 * Detección de urgencia crítica **antes** de llamar al modelo (regla 5).
 *
 * Es texto plano y no una llamada al LLM a propósito, por tres razones que
 * apuntan en la misma dirección:
 *
 *  · **Corrección.** Si la POLFA está en la bodega ahora mismo, lo que menos
 *    importa es que la respuesta esté bien redactada. Preguntarle al modelo
 *    si esto es urgente introduce una probabilidad de que diga que no.
 *  · **Latencia.** Un escalamiento que tarda dos segundos en salir es un
 *    escalamiento peor.
 *  · **Coste y superficie.** No se manda al proveedor del LLM el texto de
 *    alguien que está en medio de un allanamiento.
 *
 * Falsos positivos aceptados de buena gana: escalar de más cuesta una
 * notificación a Pedro; escalar de menos cuesta un cliente y posiblemente
 * su caso.
 */
final class SenalesCriticas
{
    /**
     * Se comparan sin tildes y en minúsculas, porque la gente escribe desde
     * el celular y con prisa: «esta detenido», «me aprehendieron», «polfa
     * esta aca».
     *
     * @var list<string>
     */
    private const FRASES = [
        // Operativo en curso
        'polfa esta', 'polfa llego', 'polfa en mi', 'estan en mi bodega',
        'esta en mi bodega', 'operativo en', 'allanamiento', 'allanaron',
        // Privación de la libertad
        'me detuvieron', 'esta detenido', 'estan detenidos', 'lo detuvieron',
        'detuvieron a mi', 'captura', 'capturaron', 'en la carcel',
        'esta preso', 'judicializ',
        // Inminencia
        'se vence hoy', 'se vence manana', 'ultimo dia', 'vence hoy',
        'audiencia manana', 'audiencia hoy',
        // Incautación en curso
        'se estan llevando', 'estan sacando la mercancia', 'me van a rematar',
    ];

    public static function detecta(string $texto): bool
    {
        $normal = self::normalizar($texto);

        foreach (self::FRASES as $frase) {
            if (str_contains($normal, $frase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cuál disparó, para el registro.
     *
     * Se guarda **la frase del catálogo**, nunca el texto del contacto: en un
     * escalamiento sin consentimiento no se puede persistir contenido del
     * mensaje (regla 14), y aun con consentimiento no hace falta duplicarlo.
     */
    public static function cual(string $texto): ?string
    {
        $normal = self::normalizar($texto);

        foreach (self::FRASES as $frase) {
            if (str_contains($normal, $frase)) {
                return $frase;
            }
        }

        return null;
    }

    private static function normalizar(string $texto): string
    {
        $minusculas = mb_strtolower(trim($texto), 'UTF-8');

        return strtr($minusculas, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ñ' => 'n', 'ü' => 'u',
        ]);
    }
}
