-- =====================================================================
-- 0007 — SEMILLAS DE PROVEEDORES Y MODELOS DE IA
--
-- Punto de partida del catálogo. A partir de aquí lo mantiene solo el cron
-- `bin/cron-sincronizar-modelos.php`: lo que salga después aparece sin que
-- nadie edite este archivo.
--
-- DOS DECISIONES QUE PARECEN ERRATAS Y NO LO SON
--
--  · Todo entra con `activo = 0` y `es_primario = 0`. Ni un solo modelo
--    queda listo para usarse. El motor arranca en modo sombra (regla del PO
--    para la Etapa 4) y qué modelo habla con los clientes de Pedro es una
--    decisión suya, no el efecto secundario de aplicar una migración.
--
--  · Los costos vienen escritos pero `costos_verificados = 0`. Los precios
--    de abajo son los publicados por Anthropic, consultados el 2026-06-24, y
--    los precios de los LLM se mueven. Que estén precargados solo sirve para
--    que verificarlos sea comprobar un número contra la página del proveedor
--    y pulsar un botón, en vez de teclearlo. Hasta que alguien lo pulse, el
--    CHECK de `0006` impide que ese modelo sea primario — que es justo lo
--    que protege al corte por `presupuesto_ia_mensual_usd` de operar contra
--    un precio inventado.
--
-- Las credenciales NO van aquí. Van cifradas en `credenciales`, desde el
-- panel. Sin `api_key` para el proveedor, la sincronización falla con
-- «credencial rechazada» y lo dice en la pantalla.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- PROVEEDORES
--
-- `pais_servidor` no es adorno: si el proveedor está fuera de Colombia, el
-- aviso de habeas data tiene que declarar transferencia internacional
-- (ver `llm_pais_permitido` en 0003). Es dato de cumplimiento.
--
-- Solo Anthropic entra activo, porque es el único con el que se va a
-- trabajar en la Etapa 4. Los otros dos quedan registrados y apagados: son
-- la salida si hace falta, no la que se toma hoy.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO proveedores_ia (clave, nombre, base_url, formato_api, pais_servidor, activo) VALUES
 ('anthropic', 'Anthropic', 'https://api.anthropic.com', 'anthropic', 'Estados Unidos', 1),
 ('openai',    'OpenAI',    'https://api.openai.com/v1', 'openai_compatible', 'Estados Unidos', 0),
 -- Ollama en el propio VPS. La única opción sin transferencia internacional
 -- de datos, y por eso queda anotada aunque hoy no se use.
 ('ollama',    'Ollama (local)', 'http://127.0.0.1:11434', 'ollama', 'Colombia (VPS propio)', 0);

-- ---------------------------------------------------------------------
-- MODELOS DE ANTHROPIC
--
-- `origen = 'manual'` porque los escribió una persona, no el descubrimiento.
-- La primera sincronización los reconocerá por (proveedor, identificador,
-- propósito) y les pondrá `visto_en` sin duplicarlos ni tocar sus costos.
--
-- Precios en USD por millón de tokens (Anthropic, 2026-06-24).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO modelos_ia
 (proveedor_id, identificador, nombre_visible, proposito, origen,
  ventana_contexto, max_salida, costo_entrada_usd_1m, costo_salida_usd_1m,
  costos_verificados, temperatura_default, max_tokens_default,
  es_primario, orden_fallback, activo)
SELECT p.id, d.identificador, d.nombre_visible, 'conversacion', 'manual',
       d.ventana, d.salida, d.entrada_usd, d.salida_usd,
       0, 0.40, 600, 0, d.orden, 0
  FROM proveedores_ia p
  JOIN (
        SELECT 'claude-opus-5'    AS identificador, 'Claude Opus 5'   AS nombre_visible,
               1000000 AS ventana, 128000 AS salida,
                5.0000 AS entrada_usd, 25.0000 AS salida_usd, 0 AS orden
  UNION SELECT 'claude-sonnet-5',  'Claude Sonnet 5',  1000000, 128000,  3.0000, 15.0000, 1
  UNION SELECT 'claude-opus-4-8',  'Claude Opus 4.8',  1000000, 128000,  5.0000, 25.0000, 2
  UNION SELECT 'claude-haiku-4-5', 'Claude Haiku 4.5',  200000,  64000,  1.0000,  5.0000, 3
  ) d
 WHERE p.clave = 'anthropic';
