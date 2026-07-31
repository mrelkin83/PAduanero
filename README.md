# Plataforma Digital — Pedro Abogado Aduanero

Paquete de especificación completo. **Leer en este orden.**

| # | Archivo | Qué contiene |
|---|---|---|
| 1 | `CLAUDE.md` | Documento maestro: decisiones, arquitectura, ADRs, reglas inviolables |
| 2 | `docs/CONTRATOS.md` | Firmas exactas de cada clase y servicio. **Normativo** |
| 3 | `docs/PANEL_ADMIN.md` | Panel administrativo, roles, frontera con Chatwoot |
| 4 | `docs/PLAN_BUILD.md` | Nueve etapas con criterios de cierre |
| 5 | `docs/PRUEBAS.md` | Qué se prueba, con qué severidad |
| 6 | `docs/RUNBOOK.md` | Operación e incidentes |
| 7 | `docs/RESPALDOS.md` | Respaldos, cifrado y recuperación |
| 8 | `db/*.sql` | Esquemas MySQL 8 y semillas con los datos reales |
| 9 | `motor/index.js` | Referencia conceptual. **Se entrega en la Etapa 4, no antes** |

---

## En una página

Un abogado especialista en derecho aduanero y tributario quiere captar clientes que
**ya tienen un problema con la DIAN**. Landing → WhatsApp → asesoría paga de
$400.000. El sistema tiene tres piezas:

- **Chatwoot** centraliza WhatsApp, Instagram, Messenger, web y correo en una bandeja.
- **Evolution API** conecta el WhatsApp.
- **Motor propio en PHP** clasifica el caso, filtra riesgo, cobra y agenda.

Lo que hace especial a este proyecto no es la tecnología: es que el bot **no puede
dar asesoría jurídica**. Nada de plazos, nada de artículos, nada de estrategia,
nada de prometer resultados. Demuestra dominio suficiente para generar confianza y
conduce a la consulta. Esa frontera está en `CLAUDE.md` §4 y se verifica con
`tests/golden/conversaciones.json`.

---

## Stack

PHP 8.2+ · MySQL 8 · TailwindCSS · JavaScript vanilla con fetch.
Sin frameworks, sin ORM. `index.php` en la raíz.
Chatwoot y Evolution en Docker, como cajas negras.

---

## Arranque

```bash
cp .env.example .env
openssl rand -base64 32          # → MASTER_KEY. Guardar copia FUERA del servidor.

mysql -u root -p -e "CREATE DATABASE pedro_aduanero
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -p pedro_aduanero < db/schema.sql
mysql -u root -p pedro_aduanero < db/schema_admin.sql
mysql -u root -p pedro_aduanero < db/seeds.sql

composer install
chmod +x bin/*.sh
bin/salud.sh
```

---

## Reglas que no se negocian

1. El bot nunca da plazos, cita normas numeradas, redacta memoriales ni promete resultados.
2. Sin consentimiento de habeas data, no se persiste nada del caso.
3. Una asesoría solo pasa a `pagada` por webhook con firma verificada.
4. El panel no reimplementa la bandeja de Chatwoot.
5. La `MASTER_KEY` nunca va a la base de datos ni al respaldo automático.
6. La columna generada `slot_unico` es lo único que impide agendar dos clientes a
   la misma hora. No se elimina.
7. Ningún fragmento de la base de conocimiento entra al RAG sin verificación de Pedro.
8. La IA arranca en modo sombra. Dos semanas limpias antes del envío automático.

---

## Pendiente de Pedro

- [ ] Inventario de los 130+ escenarios jurídicos.
- [ ] Aviso de habeas data y política de tratamiento de datos.
- [ ] Política de reembolso.
- [ ] Segundo número de WhatsApp para alertas internas.
- [ ] Confirmación del catálogo tributario (`CLAUDE.md` §5).
- [ ] Revisión del copy bajo el marco de publicidad del abogado (Ley 1123 de 2007).
- [ ] Nombres reales de los archivos en `/public/img`.

Nada de esto bloquea las etapas 0, 1 y 2.
