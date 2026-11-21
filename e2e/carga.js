// Recorrido de navegador: cargar sin que la página salte (0.51.0, public/assets/carga.js).
//   node e2e/carga.js   levanta su propio servidor en el puerto 8085
// Enter pasa al campo siguiente en los formularios que graban (no envía ni recarga), desde el último va al botón,
// el ingreso sigue entrando con Enter, y al grabar la página vuelve a la altura donde se estaba con el aviso a la vista.
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');

const PORT = process.env.E2E_CARGA_PORT || '8085';
const BASE = 'http://127.0.0.1:' + PORT;
const ROOT = path.resolve(__dirname, '..');
const CLAVE = 'E2e-Prueba-2026';
let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };
const php = (codigo, ...args) => String(execFileSync('php', ['-r', 'require "' + ROOT + '/src/bootstrap.php"; App\\App::boot(); $pdo = App\\Core\\Db::pdo(); ' + codigo, ...args]));
const sql = (q) => php('$pdo->exec($argv[1]);', q);
const foco = (p) => p.evaluate(() => { const a = document.activeElement; return a ? (a.name || a.id || a.tagName) + '|' + a.tagName : ''; });

async function login(page) {
  await page.goto(BASE + '/login');
  await page.fill('input[name=usuario]', 'e2e_admin');
  await page.fill('input[name=clave]', CLAVE);
  await Promise.all([page.waitForNavigation(), page.press('input[name=clave]', 'Enter')]);
}

async function recorrer(page, nombre) {
  const errores = [];
  page.on('pageerror', e => errores.push(e.message));
  await login(page);
  ok(!page.url().includes('/login'), nombre + ': el ingreso sigue entrando con Enter');

  // ---- Configuración: un formulario largo que graba y vuelve a la misma pantalla
  await page.goto(BASE + '/configuracion');
  const textos = page.locator('form[action$="/configuracion"] input:not([type=hidden]):not([type=checkbox])');
  const n = await textos.count();
  ok(n >= 2, nombre + ': la configuración tiene varios campos de texto');
  let navego = false;
  const marca = () => { navego = true; };
  page.on('framenavigated', marca);
  await textos.nth(0).focus();
  const antes = await foco(page);
  await page.keyboard.press('Enter');
  await page.waitForTimeout(300);
  ok(!navego, nombre + ': Enter en un campo no envía ni recarga la página');
  ok((await foco(page)) !== antes, nombre + ': Enter pasa al campo siguiente');
  await textos.nth(n - 1).focus();
  await page.keyboard.press('Enter');
  await page.waitForTimeout(200);
  ok(!navego, nombre + ': Enter en el último campo tampoco envía');
  ok(await page.evaluate(() => document.activeElement && document.activeElement.tagName === 'BUTTON' && /Guardar/.test(document.activeElement.textContent)),
    nombre + ': desde el último campo el foco queda en «Guardar»');
  page.off('framenavigated', marca);

  const boton = page.locator('form[action$="/configuracion"] button[type=submit]');
  await boton.scrollIntoViewIfNeeded();
  const y = await page.evaluate(() => window.scrollY);
  await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]); // segundo Enter, sobre el botón: graba
  await page.waitForLoadState('load');
  await page.waitForTimeout(200);
  const y2 = await page.evaluate(() => window.scrollY);
  ok(y > 100 && Math.abs(y2 - y) < 40, nombre + `: al grabar vuelve a la misma altura (${Math.round(y)} → ${Math.round(y2)})`);
  ok(await page.locator('.avisos-flotantes .alerta').first().isVisible(), nombre + ': el aviso de grabado se ve sin subir');
  await page.click('.avisos-flotantes .aviso-cerrar');
  ok((await page.locator('.avisos-flotantes').count()) === 0, nombre + ': el aviso se cierra con ✕');

  // ---- Otra pantalla: arranca arriba, como siempre
  await page.goto(BASE + '/comprobantes/nuevo');
  ok((await page.evaluate(() => window.scrollY)) === 0, nombre + ': una pantalla nueva arranca arriba');

  // ---- Factura: Enter en cantidad no lleva a «Revisar»
  const cant = page.locator('[data-cantidad]').first();
  if (await cant.count()) {
    await cant.fill('2');
    await cant.press('Enter');
    await page.waitForTimeout(300);
    ok(page.url().includes('/comprobantes/nuevo'), nombre + ': Enter en la cantidad de un renglón no envía la factura');
    ok((await foco(page)).includes('[precio]'), nombre + ': y pasa al precio');
  } else ok(false, nombre + ': la factura tiene renglones');
  // ---- «Cobrar venta»: Enter con plata de menos no emite la factura
  if (nombre === 'escritorio') {
    await page.goto(BASE + '/clientes/nuevo');
    await page.fill('#f-razon_social', 'CLIENTE E2E CARGA');
    await page.selectOption('#f-iva', '3');
    await Promise.all([page.waitForNavigation(), page.click('form.card button[type=submit]')]);
    await page.goto(BASE + '/comprobantes/nuevo?tipo=FC');
    await page.fill('#cliente-q', 'CLIENTE E2E CARGA');
    await page.waitForSelector('[data-cliente-lista] li');
    await page.click('[data-cliente-lista] li');
    const r = page.locator('[data-renglon]').nth(0);
    await r.locator('[data-descripcion]').fill('Consulta');
    await r.locator('[data-cantidad]').fill('1');
    await r.locator('[data-precio]').fill('1210');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
    await page.check('input[name=forma_pago][value="1"]');
    const urlRev = page.url();
    await page.click('[data-abrir-cobro]');
    await page.fill('[data-cobro] [data-importe-pago]', '1.000');
    await page.press('[data-cobro] [data-importe-pago]', 'Enter');
    await page.waitForTimeout(400);
    ok(page.url() === urlRev && (await page.locator('#form-emitir').count()) === 1, nombre + ': Enter en el importe con plata de menos no emite: sigue en «Cobrar venta»');
    ok((await page.textContent('[data-r-vuelto]')).includes('210,00'), nombre + ': y lo que falta queda a la vista');
    sql("DELETE FROM clientes WHERE razon_social = 'CLIENTE E2E CARGA'");
  }
  ok(errores.length === 0, nombre + ': sin errores de JavaScript' + (errores.length ? ' (' + errores.join('; ') + ')' : ''));
}

(async () => {
  const env = { ...process.env, PORT };
  const server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true, env });
  for (let i = 0; i < 40; i++) { try { if ((await fetch(BASE + '/salud')).ok) break; } catch (e) {} await new Promise(r => setTimeout(r, 250)); }
  execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), CLAVE], { stdio: 'inherit' });
  sql("UPDATE parametros SET empresa = 'EMPRESA DE PRUEBA E2E SA', cuit = 30707656480, iva = 1 WHERE id = 1"); // el fixture la restaura con --limpiar
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 600 } });
    await recorrer(await ctx.newPage(), 'escritorio');
    await ctx.close();
    const cel = await browser.newContext({ ...devices['Pixel 7'] });
    await recorrer(await cel.newPage(), 'celular');
    await cel.close();
  } finally {
    await browser.close();
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), '--limpiar'], { stdio: 'inherit' });
    try { process.kill(-server.pid); } catch (e) {}
  }
  if (fallas) { console.error(`\n${fallas} falla(s) en el recorrido de carga.`); process.exit(1); }
  console.log('\nRecorrido de carga completo sin fallas.');
})();
