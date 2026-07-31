/**
 * Verifica el criterio de cierre de la Etapa 1 en un navegador real:
 * un clic en el botón de WhatsApp registra el evento con su `utm_campaign`.
 *
 *   node bin/verificar-atribucion.mjs [url]
 *
 * Las pruebas de PHPUnit comprueban el lado del servidor. Esto comprueba lo
 * otro: que el JavaScript lea los UTM de la URL, los meta en el mensaje
 * prellenado y dispare el beacon antes de que el navegador se vaya a
 * WhatsApp. Es la parte que ninguna prueba unitaria puede ver.
 */

import puppeteer from 'puppeteer-core';
import { existsSync } from 'node:fs';

const BASE = process.argv[2] ?? 'http://127.0.0.1:8127';
const CAMPANA = 'aprehension-agosto';
const URL_CON_UTM = `${BASE}/?utm_source=facebook&utm_medium=cpc&utm_campaign=${CAMPANA}&utm_content=video-30s`;

const chrome = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    '/usr/bin/google-chrome',
].find((r) => existsSync(r));

if (!chrome) {
    console.error('No se encontró Chrome.');
    process.exit(1);
}

const navegador = await puppeteer.launch({
    executablePath: chrome,
    headless: true,
    args: ['--no-sandbox', '--disable-gpu'],
});

const pagina = await navegador.newPage();
await pagina.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true });

// El cuerpo de un `sendBeacon` con Blob no se puede leer desde
// `request.postData()`: puppeteer lo deja vacío. Se envuelve la función en
// el propio navegador —llamando igualmente a la original, para que la fila
// llegue a la base de verdad— y se anota lo que se envía.
await pagina.evaluateOnNewDocument(() => {
    window.__beacons = [];
    const original = navigator.sendBeacon.bind(navigator);

    navigator.sendBeacon = (url, datos) => {
        if (datos instanceof Blob) {
            datos.text().then((t) => window.__beacons.push(t));
        } else {
            window.__beacons.push(String(datos));
        }
        return original(url, datos);
    };
});

const leerEnviados = async () => {
    const crudos = await pagina.evaluate(() => window.__beacons ?? []);
    return crudos.map((c) => {
        try {
            return JSON.parse(c);
        } catch {
            return { error: 'payload ilegible' };
        }
    });
};

await pagina.goto(URL_CON_UTM, { waitUntil: 'networkidle0' });

let fallos = 0;
const comprobar = (ok, texto) => {
    console.log(`  ${ok ? 'OK  ' : 'FALLA'} ${texto}`);
    if (!ok) { fallos++; }
};

console.log('\n═══ 1. El enlace arrastra la campaña ═══');

const href = await pagina.$eval('a.js-wa', (a) => a.href);
const texto = decodeURIComponent(new URL(href).searchParams.get('text') ?? '');

console.log(`  mensaje prellenado: ${JSON.stringify(texto)}`);
comprobar(href.startsWith('https://wa.me/573159923676'), 'apunta al número del negocio');
comprobar(texto.includes(CAMPANA), 'el mensaje lleva la referencia de campaña');
comprobar(texto.includes('DIAN'), 'conserva el mensaje configurado');

console.log('\n═══ 2. La vista se registra ═══');
comprobar(
    (await leerEnviados()).some((e) => e.tipo === 'vista' && e.utm_campaign === CAMPANA),
    'evento «vista» con utm_campaign',
);

console.log('\n═══ 3. El clic se registra antes de salir a WhatsApp ═══');

// Se cancela la navegación en fase de captura: el listener de la página va
// en burbujeo, así que sigue recibiendo el evento y disparando el beacon.
// Sin esto el navegador se iría a wa.me y no habría nada que medir.
await pagina.evaluate(() => {
    document.addEventListener('click', (e) => e.preventDefault(), true);
});

await pagina.click('a.js-wa');
await new Promise((r) => setTimeout(r, 800));

const clic = (await leerEnviados()).find((e) => e.tipo === 'click_whatsapp');

comprobar(clic !== undefined, 'evento «click_whatsapp» enviado');
comprobar(clic?.utm_campaign === CAMPANA, `utm_campaign = ${CAMPANA}`);
comprobar(clic?.utm_source === 'facebook', 'utm_source = facebook');
comprobar(clic?.dispositivo === 'movil', 'dispositivo detectado');
comprobar(/^[a-f0-9]{32}$/.test(clic?.sesion ?? ''), 'identificador de sesión aleatorio');

console.log('\n═══ 4. La campaña sobrevive a una recarga sin parámetros ═══');

await pagina.goto(`${BASE}/`, { waitUntil: 'networkidle0' });
const hrefTrasRecarga = await pagina.$eval('a.js-wa', (a) => a.href);

comprobar(
    decodeURIComponent(hrefTrasRecarga).includes(CAMPANA),
    'la atribución persiste en sessionStorage',
);

await navegador.close();

console.log(fallos === 0 ? '\nTodo correcto.\n' : `\n${fallos} comprobación(es) fallida(s).\n`);
process.exit(fallos === 0 ? 0 : 1);
