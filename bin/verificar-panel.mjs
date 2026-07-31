/**
 * Recorrido del panel en un navegador real.
 *
 *   node bin/verificar-panel.mjs [url] [correo] [contraseña]
 *
 * Las pruebas de PHPUnit cubren la lógica; esto cubre lo que solo se ve
 * armando la petición de verdad: que la cookie de sesión sea HttpOnly, que el
 * CSRF rechace un POST sin token, que una credencial guardada no aparezca en
 * el HTML, y que «Probar conexión» diga algo útil.
 */

import puppeteer from 'puppeteer-core';
import { existsSync } from 'node:fs';

const BASE = process.argv[2] ?? 'http://127.0.0.1:8130';
const CORREO = process.argv[3] ?? 'admin@pruebas.local';
const CLAVE = process.argv[4] ?? 'contrasena-de-prueba-larga';
const SECRETO = 'prv_test_SECRETOqueNOdebeAparecerJamas';

const chrome = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    '/usr/bin/google-chrome',
].find((r) => existsSync(r));

if (!chrome) {
    console.error('No se encontró Chrome.');
    process.exit(1);
}

const navegador = await puppeteer.launch({ executablePath: chrome, headless: true, args: ['--no-sandbox'] });
const pagina = await navegador.newPage();
await pagina.setViewport({ width: 1280, height: 900 });

let fallos = 0;
const comprobar = (ok, texto) => {
    console.log(`  ${ok ? 'OK  ' : 'FALLA'} ${texto}`);
    if (!ok) fallos++;
};

console.log('\n═══ 1. Puerta de sesión ═══');

await pagina.goto(`${BASE}/panel/configuracion`, { waitUntil: 'networkidle0' });
comprobar(pagina.url().includes('/panel/entrar'), 'sin sesión redirige a entrar');

console.log('\n═══ 2. CSRF ═══');

const sinToken = await pagina.evaluate(async (base) => {
    const r = await fetch(`${base}/panel/entrar`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'email=a@b.co&password=x',
    });
    return r.status;
}, BASE);

comprobar(sinToken === 419, `POST sin token CSRF se rechaza (HTTP ${sinToken})`);

console.log('\n═══ 3. Entrada ═══');

await pagina.goto(`${BASE}/panel/entrar`, { waitUntil: 'networkidle0' });
await pagina.type('#email', CORREO);
await pagina.type('#password', CLAVE);
await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'networkidle0' }),
    pagina.click('button[type=submit]'),
]);

// El super_admin exige 2FA, así que lo manda a activarla.
comprobar(!pagina.url().includes('/panel/entrar'), `entró (${pagina.url().replace(BASE, '')})`);

const galletas = await pagina.browser().cookies();
const sesion = galletas.find((c) => c.name === 'pa_sesion');

comprobar(sesion !== undefined, 'se emitió la cookie de sesión');
comprobar(sesion?.httpOnly === true, 'la cookie de sesión es HttpOnly');
comprobar(sesion?.sameSite === 'Lax', 'la cookie de sesión es SameSite=Lax');

const csrfCookie = galletas.find((c) => c.name === 'pa_csrf');
comprobar(csrfCookie !== undefined && csrfCookie.httpOnly === false,
    'la cookie CSRF es legible por JS (doble envío) y distinta de la de sesión');

console.log('\n═══ 4. Credencial: solo máscara ═══');

await pagina.goto(`${BASE}/panel/pagos`, { waitUntil: 'networkidle0' });

const token = await pagina.$eval('input[name=_csrf]', (el) => el.value);

await pagina.evaluate(async (base, tok, secreto) => {
    await fetch(`${base}/panel/pagos/credenciales`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            _csrf: tok, servicio: 'wompi', clave: 'llave_privada',
            entorno: 'pruebas', valor: secreto,
        }).toString(),
    });
}, BASE, token, SECRETO);

await pagina.goto(`${BASE}/panel/pagos`, { waitUntil: 'networkidle0' });
const html = await pagina.content();

comprobar(!html.includes(SECRETO), 'el valor real NO aparece en el HTML');
comprobar(!html.includes('SECRETOqueNO'), 'ni siquiera parcialmente');
comprobar(html.includes('•'), 'se muestra la máscara');

if (!html.includes('•') && process.env.VOolCAR !== undefined) {
    const { writeFileSync } = await import('node:fs');
    writeFileSync('storage/pagos-dump.html', html);
    console.log('  (HTML volcado en storage/pagos-dump.html)');
}

console.log('\n═══ 5. Probar conexión ═══');

await pagina.goto(`${BASE}/panel/pagos`, { waitUntil: 'networkidle0' });
const token2 = await pagina.$eval('input[name=_csrf]', (el) => el.value);

// Se sigue la redirección y se lee la URL final: con `redirect: 'manual'` la
// respuesta es opaca y `fetch` no deja leer la cabecera Location.
const texto = await pagina.evaluate(async (base, tok) => {
    const r = await fetch(`${base}/panel/pagos/probar`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ _csrf: tok, servicio: 'wompi', entorno: 'pruebas' }).toString(),
    });
    return decodeURIComponent(r.url);
}, BASE, token2);

console.log(`  respuesta: ${texto.split('?').slice(1).join('?')}`);
comprobar(texto.includes('error=') || texto.includes('ok='), 'el probador respondió con un mensaje');

// Faltan la llave pública y las demás, así que debe fallar diciendo QUÉ falta,
// no un genérico. Es el valor del probador: que el mensaje sirva.
comprobar(
    texto.includes('Faltan+credenciales') || texto.includes('Faltan credenciales')
        || texto.includes('llave'),
    'el mensaje dice qué falta, no solo que falló',
);

console.log('\n═══ 6. Salida ═══');

await pagina.goto(`${BASE}/panel/seguridad`, { waitUntil: 'networkidle0' });
const token3 = await pagina.$eval('input[name=_csrf]', (el) => el.value);

await pagina.evaluate(async (base, tok) => {
    await fetch(`${base}/panel/salir`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ _csrf: tok }).toString(),
    });
}, BASE, token3);

await pagina.goto(`${BASE}/panel`, { waitUntil: 'networkidle0' });
comprobar(pagina.url().includes('/panel/entrar'), 'tras salir, la sesión ya no vale');

await navegador.close();

console.log(fallos === 0 ? '\nTodo correcto.\n' : `\n${fallos} comprobación(es) fallida(s).\n`);
process.exit(fallos === 0 ? 0 : 1);
