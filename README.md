# Sitio público — Pedro, abogado aduanero

Un abogado especialista en derecho aduanero y comercio exterior quiere captar clientes
que **ya tienen un problema con la DIAN**. Este repositorio es su sitio:

```
Meta Ads · Google Ads · SEO  →  landing  →  diagnóstico  →  WhatsApp
```

Todo termina en un enlace de `wa.me` con el mensaje ya redactado. **La
conversación ocurre en el teléfono de Pedro, fuera de este sistema.**

> **¿Primera vez que levantas esto?** → **[`docs/ARRANQUE_LOCAL.md`](docs/ARRANQUE_LOCAL.md)**.
> De cero al panel: base de datos, llave, migraciones, usuario y Laragon.

---

## Qué hay dentro

| Ruta | Qué es |
|---|---|
| `/` | La landing. Su contenido se edita desde el panel |
| `/perfil` | El diagnóstico: seis preguntas, dos ramas, **cero persistencia** |
| `/panel` | Entrar con 2FA, usuarios, configuración, tarifas, bitácora, métricas |
| `/salud` | Chequeo para el cron y el despliegue |

## Documentación

| Archivo | Qué contiene |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | **Documento maestro.** Decisiones, ADRs, reglas, trampas conocidas |
| [`docs/ARRANQUE_LOCAL.md`](docs/ARRANQUE_LOCAL.md) | Levantar el proyecto en Windows y entrar al panel |
| [`docs/CONTRATOS.md`](docs/CONTRATOS.md) | Firmas exactas de cada clase y servicio. **Normativo** |
| [`docs/PANEL_ADMIN.md`](docs/PANEL_ADMIN.md) | Panel administrativo y matriz de roles |
| [`docs/PRUEBAS.md`](docs/PRUEBAS.md) | Qué se prueba y con qué severidad |
| [`docs/RUNBOOK.md`](docs/RUNBOOK.md) | Operación, despliegue e incidentes |
| [`docs/RESPALDOS.md`](docs/RESPALDOS.md) | Respaldos, cifrado y recuperación |
| [`db/migraciones/`](db/migraciones/) | Esquema MySQL 8 y semillas con los datos reales |
| [`stitch_customs_law_digital_experience/`](stitch_customs_law_digital_experience/) | Especificación del sistema visual **Lex Aeterna** |

---

## Lo que hace especial a este proyecto

No es la tecnología: es lo que la página **no puede decir**. Nada de plazos,
nada de artículos con número, nada de estrategia, nada de prometer resultados
— lo exige el marco de publicidad del abogado en Colombia (Ley 1123 de 2007).

La página demuestra dominio suficiente del vocabulario técnico para generar
confianza, y conduce a la consulta. Esa frontera está en `CLAUDE.md` §3 y **se
verifica con una prueba**, no con un comentario:
`CuestionarioTest::elCopyNoNombraPlazosNiNormas()`.

La segunda cosa que lo define es que **el diagnóstico no guarda nada**. Ni una
fila. Es lo que lo mantiene fuera del alcance de la ley de datos personales, y
es la primera tentación que alguien va a querer romper.

---

## Historia

Hasta agosto de 2026 esto fue una plataforma de captación completa: motor
conversacional sobre WhatsApp con triage jurídico y escalamiento, capa de IA
con RAG sobre MySQL, Chatwoot como bandeja omnicanal, Evolution API como
pasarela y Wompi para cobrar.

El PO decidió retirarlo todo (commit `3fcea6e`, −22.739 líneas). Las tablas de
aquel sistema **siguen en la base, vacías e intactas** — migraciones siempre
aditivas, ADR-013 — por si algún día vuelve. No las uses para nada nuevo.

---

## Arrancar

```bash
composer install
npm install
cp .env.example .env          # completar MASTER_KEY, DB_USER, DB_PASS
php bin/migrar.php
npm run build:landing
php -S 127.0.0.1:8000 bin/servidor-dev.php
```

```bash
vendor/bin/phpunit                              # 200 pruebas
node bin/auditar-landing.mjs http://127.0.0.1:8000/   # presupuesto y Lighthouse
```
