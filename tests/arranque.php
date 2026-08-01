<?php

declare(strict_types=1);

/**
 * Bootstrap de PHPUnit.
 *
 * Lo primero que hace es la guarda de base de datos: NUNCA apuntar las
 * pruebas a producción (docs/PRUEBAS.md §6). Las pruebas de integración
 * truncan tablas — apuntarlas a `pedro_aduanero` borraría los casos reales.
 */

use App\Soporte\Entorno;
use App\Soporte\Fechas;

require dirname(__DIR__) . '/vendor/autoload.php';

/*
 * La zona horaria se fija AQUÍ, no en `Aplicacion`.
 *
 * `Aplicacion::__construct()` la fija también, y eso está bien en producción,
 * pero en pruebas significa que la zona depende de si alguna clase anterior
 * construyó la aplicación. Con esa dependencia, una prueba que compara tiempos
 * pasa aislada y falla en suite —o al revés— según el orden de ejecución.
 *
 * Eso ya pasó una vez, con `puedeResponderIa()`. El defecto era real, pero el
 * modo de falla es el problema: un fallo que depende del orden se declara
 * «flaky», alguien con prisa lo marca para saltarlo, y el defecto vuelve
 * invisible. Fijarla en el arranque quita esa posibilidad de la mesa.
 */
date_default_timezone_set(Fechas::ZONA);

// El .env.pruebas es opcional: en CI las variables llegan por el entorno.
$rutaPruebas = dirname(__DIR__) . '/.env.pruebas';

define('PRUEBAS_RUTA_ENV', is_readable($rutaPruebas) ? $rutaPruebas : dirname(__DIR__) . '/.env');

/**
 * Recarga el entorno de pruebas.
 *
 * ArranqueTest tiene que vaciar Entorno para comprobar que la aplicación no
 * arranca sin MASTER_KEY. Sin esto, las pruebas que corren después se
 * encuentran el entorno vacío y fallan por contaminación, no por su propio
 * defecto.
 */
function pruebas_cargar_entorno(): void
{
    Entorno::reiniciar();
    Entorno::cargar(PRUEBAS_RUTA_ENV);
}

Entorno::cargar(PRUEBAS_RUTA_ENV);

$base = Entorno::obtener('DB_NAME', '');

if (!is_string($base) || !str_ends_with($base, '_pruebas')) {
    fwrite(
        STDERR,
        PHP_EOL
        . 'ABORTADO: DB_NAME es «' . var_export($base, true) . '» y debe terminar en «_pruebas».' . PHP_EOL
        . 'Las pruebas de integración truncan tablas. Revisar phpunit.xml o .env.pruebas.' . PHP_EOL
        . PHP_EOL
    );
    exit(1);
}

// Claves de juguete para las pruebas que no fijan las suyas. Deterministas a
// propósito: una clave aleatoria por corrida haría irreproducible cualquier
// fallo de cifrado.
putenv('MASTER_KEY=' . base64_encode(str_repeat("\x01", 32)));
putenv('PEPPER_TELEFONO=' . base64_encode(str_repeat("\x02", 32)));
