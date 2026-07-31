/**
 * Auditoría de la landing: desbordamiento horizontal, peso y Lighthouse.
 *
 *   node bin/auditar-landing.mjs [url]
 *
 * El criterio de cierre de la Etapa 1 son números concretos —Lighthouse ≥ 95
 * en móvil, LCP < 2 s, peso < 300 KB, CLS < 0.1— y sin medirlos no se puede
 * decir que la etapa esté cerrada. Este script los mide.
 *
 * Usa el Chrome ya instalado (puppeteer-core), no descarga un Chromium.
 */

import puppeteer from 'puppeteer-core';
import lighthouse from 'lighthouse';
import { existsSync } from 'node:fs';

const URL_BASE = process.argv[2] ?? 'http://127.0.0.1:8127/';

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

const navegador = await puppeteer.launch({
    executablePath: chrome,
    headless: true,
    args: ['--no-sandbox', '--disable-gpu'],
});

// ── 1. Desbordamiento horizontal ────────────────────────────────────────
//
// El cuerpo de la página NUNCA debe hacer scroll lateral. Cuando pasa, el
// texto se corta por la derecha y en un móvil real el usuario ve media
// frase. Esto localiza al elemento culpable en vez de dejarlo a la
// adivinanza.
const pagina = await navegador.newPage();
await pagina.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true });
await pagina.goto(URL_BASE, { waitUntil: 'networkidle0' });

const desborde = await pagina.evaluate(() => {
    const doc = document.documentElement;
    const culpables = [];

    if (doc.scrollWidth > doc.clientWidth) {
        for (const el of document.querySelectorAll('*')) {
            const caja = el.getBoundingClientRect();
            if (caja.right > doc.clientWidth + 1 || caja.left < -1) {
                culpables.push({
                    etiqueta: el.tagName.toLowerCase(),
                    clase: (el.className?.baseVal ?? el.className ?? '').toString().slice(0, 70),
                    izquierda: Math.round(caja.left),
                    derecha: Math.round(caja.right),
                    ancho: Math.round(caja.width),
                    texto: (el.textContent ?? '').trim().slice(0, 40),
                });
            }
        }
    }

    return {
        scrollWidth: doc.scrollWidth,
        clientWidth: doc.clientWidth,
        // Solo los antepasados más externos: si un <p> desborda, su <div> y
        // su <section> también salen, y la lista se vuelve inútil.
        culpables: culpables.slice(0, 12),
    };
});

console.log('\n═══ Desbordamiento horizontal (390 px) ═══');
console.log(`scrollWidth ${desborde.scrollWidth} · clientWidth ${desborde.clientWidth}`);

if (desborde.culpables.length === 0) {
    console.log('Sin desbordamiento.');
} else {
    for (const c of desborde.culpables) {
        console.log(
            `  <${c.etiqueta}> ancho=${c.ancho} [${c.izquierda}…${c.derecha}]  ${c.clase}  «${c.texto}»`,
        );
    }
}

// ── 2. Peso de la carga inicial ─────────────────────────────────────────
const recursos = await pagina.evaluate(() =>
    performance.getEntriesByType('resource').map((r) => ({
        url: r.name.replace(location.origin, ''),
        tipo: r.initiatorType,
        bytes: r.transferSize || r.encodedBodySize || 0,
    })),
);

const htmlBytes = await pagina.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    return nav ? nav.transferSize || nav.encodedBodySize || 0 : 0;
});

const total = htmlBytes + recursos.reduce((s, r) => s + r.bytes, 0);

console.log('\n═══ Peso de la carga inicial ═══');
console.log(`  documento           ${(htmlBytes / 1024).toFixed(1)} KB`);
for (const r of recursos.filter((r) => r.bytes > 0).sort((a, b) => b.bytes - a.bytes)) {
    console.log(`  ${r.url.padEnd(34)} ${(r.bytes / 1024).toFixed(1)} KB`);
}
console.log(`  ${'TOTAL'.padEnd(34)} ${(total / 1024).toFixed(1)} KB   (presupuesto: 300 KB)`);

await pagina.close();

// ── 3. Lighthouse móvil ─────────────────────────────────────────────────
const puerto = Number(new URL(navegador.wsEndpoint()).port);

const { lhr } = await lighthouse(
    URL_BASE,
    { port: puerto, output: 'json', logLevel: 'error' },
    undefined,
);

console.log('\n═══ Lighthouse (móvil, 4G simulado) ═══');
for (const [clave, cat] of Object.entries(lhr.categories)) {
    const nota = Math.round((cat.score ?? 0) * 100);
    console.log(`  ${cat.title.padEnd(18)} ${String(nota).padStart(3)}`);
}

console.log('\n  Métricas:');
for (const id of ['first-contentful-paint', 'largest-contentful-paint', 'cumulative-layout-shift', 'total-blocking-time', 'speed-index']) {
    const a = lhr.audits[id];
    if (a) {
        console.log(`    ${a.title.padEnd(28)} ${a.displayValue ?? '—'}`);
    }
}

const fallos = Object.values(lhr.audits).filter(
    (a) => a.score !== null && a.score < 0.9 && a.scoreDisplayMode === 'binary',
);

if (fallos.length > 0) {
    console.log('\n  Auditorías no superadas:');
    for (const a of fallos) {
        console.log(`    · ${a.title}`);

        // Los elementos concretos, no solo el titular: «contraste
        // insuficiente» sin decir dónde obliga a revisar la página entera.
        for (const item of a.details?.items ?? []) {
            const nodo = item.node;
            if (!nodo) {
                continue;
            }
            console.log(`        ${nodo.selector}`);
            if (nodo.explanation) {
                console.log(`        ${nodo.explanation}`);
            }
        }
    }
}

await navegador.close();
