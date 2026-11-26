// Recorrido de navegador: factura con fecha distinta a hoy contra el ARCA SIMULADO (D-149).
//   node e2e/fecha-factura.js   levanta su propio servidor en el puerto 8083 con AFIP_MODO=simulado
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const PORT = process.env.E2E_FECHA_PORT || '8083';
const BASE = 'http://127.0.0.1:' + PORT;
const ROOT = path.resolve(__dirname, '..');
const CAPTURAS = path.join(__dirname, 'capturas');
const CLAVE = 'E2e-Prueba-2026';
let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };
const dia = n => { const d = new Date(); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const dma = iso => iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4);

function cuitAlAzar() {
  for (;;) {
    const base = '30' + String(Math.floor(Math.random() * 1e8)).padStart(8, '0');
    const pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    let dv = 11 - (base.split('').reduce((a, d, i) => a + Number(d) * pesos[i], 0) % 11);
    if (dv === 10) continue;
    if (dv === 11) dv = 0;
    return base + dv;
  }
}

async function login(page, usuario) {
  await page.goto(BASE + '/login');
  await page.fill('input[name=usuario]', usuario);
  await page.fill('input[name=clave]', CLAVE);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}
const enviar = (page, sel = 'form.card button[type=submit]') => Promise.all([page.waitForNavigation(), page.click(sel)]);

async function cargar(page, fecha, concepto) {
  await page.goto(BASE + '/comprobantes/nuevo?tipo=FC');
  await page.fill('#cliente-q', 'E2E FECHA');
  await page.waitForSelector('[data-cliente-lista] li');
  await page.click('[data-cliente-lista] li');
  const r = page.locator('[data-renglon]').nth(0);
  await r.locator('[data-descripcion]').fill('Servicio con fecha');
  await r.locator('[data-cantidad]').fill('1');
  await r.locator('[data-precio]').fill('100');
  if (concepto) await page.selectOption('[data-concepto]', concepto);
  if (fecha) await page.fill('[data-fecha-cbte]', fecha);
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
}
async function emitirCobro(page) {
  await page.click('button:has-text("Emitir")');
  let abierta = false;
  try { abierta = await page.isVisible('dialog[data-cobro][open]'); } catch (e) { abierta = false; }
  if (abierta) await page.click('[data-confirmar-cobro]');
}

(async () => {
  const env = { ...process.env, AFIP_MODO: 'simulado' };
  const server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true, env: { ...env, PORT } });
  for (let i = 0; i < 40; i++) { try { if ((await fetch(BASE + '/salud')).ok) break; } catch (e) {} await new Promise(r => setTimeout(r, 250)); }
  execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), CLAVE], { stdio: 'inherit' });
  fs.mkdirSync(CAPTURAS, { recursive: true });
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', e => errores.push(e.message));
    await login(page, 'e2e_admin');

    await page.goto(BASE + '/tablas/parametros');
    await page.fill('#f-empresa', 'EMPRESA DE PRUEBA E2E SA');
    await page.fill('#f-cuit', '30-70765648-0');
    await page.selectOption('#f-iva', '1');
    await enviar(page);
    await page.goto(BASE + '/clientes/nuevo');
    await page.fill('#f-razon_social', 'CLIENTE E2E FECHA SRL');
    await page.selectOption('#f-iva', '1');
    await page.fill('#f-cuit', cuitAlAzar());
    await enviar(page);
    await page.goto(BASE + '/tablas/sucursales');
    await page.click('tbody tr:first-child a');
    await page.check('#f-fac_electronica');
    await enviar(page);

    // El calendario limita a 5 días con productos y a 10 con servicios
    await page.goto(BASE + '/comprobantes/nuevo?tipo=FC');
    const lim = async () => page.$eval('[data-fecha-cbte]', i => [i.min, i.max, i.value]);
    let [min, max, val] = await lim();
    ok(val === dia(0) && min === dia(-5) && max === dia(5), 'la fecha arranca en hoy y el calendario va de -5 a +5 días');
    await page.selectOption('[data-concepto]', '2');
    [min, max] = await lim();
    ok(min === dia(-10) && max === dia(10), 'con servicios el calendario va de -10 a +10 días');
    await page.screenshot({ path: path.join(CAPTURAS, 'fecha-factura-form.png'), fullPage: true });

    // Fuera de rango: el servidor lo frena con el motivo
    await cargar(page, dia(8), '1');
    ok((await page.textContent('body')).includes('hasta 5 días después de hoy'), 'productos con +8 días: avisa el límite de ARCA');

    // Fecha adelantada: aviso en la revisión y ARCA la aprueba con esa fecha
    await cargar(page, dia(2), null);
    ok((await page.textContent('body')).includes('Fecha adelantada'), 'la revisión avisa de la fecha adelantada');
    await page.screenshot({ path: path.join(CAPTURAS, 'fecha-factura-revisar.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), emitirCobro(page)]);
    const cuerpo = await page.textContent('body');
    ok(/CAE/.test(cuerpo) && cuerpo.includes(dma(dia(2))), 'la factura queda con CAE y con la fecha elegida');

    // Correlatividad: hoy ya no se puede otra A en ese punto de venta
    await cargar(page, null, null);
    ok((await page.textContent('body')).includes('fecha anterior al último'), 'con fecha de hoy avisa que las fechas tienen que ir en orden');
    await page.screenshot({ path: path.join(CAPTURAS, 'fecha-factura-orden.png'), fullPage: true });
    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
    await ctx.close();

    const movil = await browser.newContext({ ...devices['Pixel 7'] });
    const p2 = await movil.newPage();
    await login(p2, 'e2e_admin');
    await p2.goto(BASE + '/comprobantes/nuevo?tipo=FC');
    ok(await p2.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'celular: el formulario con la fecha no se desborda');
    await p2.locator('[data-fecha-cbte]').scrollIntoViewIfNeeded();
    await p2.screenshot({ path: path.join(CAPTURAS, 'fecha-factura-celular.png') });
    await movil.close();
  } finally {
    await browser.close();
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), '--limpiar'], { stdio: 'inherit' });
    process.kill(-server.pid);
  }
  console.log(fallas === 0 ? '\nRecorrido de fecha de factura completo sin fallas.' : `\n${fallas} falla(s).`);
  process.exit(fallas === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
