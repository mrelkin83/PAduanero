/**
 * Capturas de la landing en móvil y escritorio, con emulación real.
 *
 *   node bin/capturar.mjs [url] [directorio]
 *
 * Emula un móvil de verdad (390 px, DPR 2, touch) en vez de encoger la
 * ventana: el `--window-size` de Chrome deja el DPR en 1 y elige variantes de
 * imagen distintas a las que verá un teléfono, con lo que la captura miente
 * justo en lo que se quiere revisar.
 */

import puppeteer from 'puppeteer-core';
import { existsSync, mkdirSync } from 'node:fs';

const URL_BASE = process.argv[2] ?? 'http://127.0.0.1:8127/';
const DESTINO = process.argv[3] ?? 'storage/capturas';

const CANDIDATOS = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
];

const chrome = CANDIDATOS.find((r) => existsSync(r));
if (!chrome) {
    console.error('No se encontró Chrome.');
    process.exit(1);
}

mkdirSync(DESTINO, { recursive: true });

const navegador = await puppeteer.launch({
    executablePath: chrome,
    headless: true,
    args: ['--no-sandbox', '--disable-gpu'],
});

const vistas = [
    { nombre: 'movil', width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
    { nombre: 'escritorio', width: 1440, height: 900, deviceScaleFactor: 1, isMobile: false, hasTouch: false },
];

for (const vista of vistas) {
    const pagina = await navegador.newPage();
    const { nombre, ...viewport } = vista;

    await pagina.setViewport(viewport);
    await pagina.goto(URL_BASE, { waitUntil: 'networkidle0' });

    // Las imágenes perezosas no se descargan hasta que entran en cuadro, y
    // en una captura de página completa saldrían en blanco.
    await pagina.evaluate(async () => {
        await new Promise((listo) => {
            let y = 0;
            const paso = setInterval(() => {
                window.scrollBy(0, 400);
                y += 400;
                if (y >= document.body.scrollHeight) {
                    clearInterval(paso);
                    window.scrollTo(0, 0);
                    listo();
                }
            }, 40);
        });
    });

    await new Promise((r) => setTimeout(r, 600));

    const ruta = `${DESTINO}/${nombre}.png`;
    await pagina.screenshot({ path: ruta, fullPage: true });
    console.log(`${ruta}`);

    await pagina.close();
}

await navegador.close();
