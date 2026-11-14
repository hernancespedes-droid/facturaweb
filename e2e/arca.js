// Recorrido de navegador de la etapa 3: factura electrónica contra el ARCA SIMULADO (sin internet ni certificado).
//   node e2e/arca.js   levanta su propio servidor en el puerto 8081 con AFIP_MODO=simulado
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const PORT = process.env.E2E_ARCA_PORT || '8081';
const BASE = 'http://127.0.0.1:' + PORT;
const ROOT = path.resolve(__dirname, '..');
const CAPTURAS = path.join(__dirname, 'capturas');
const CLAVE = 'E2e-Prueba-2026';
let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };

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

async function emitir(page, tipoCliente, precio) {
  await page.goto(BASE + '/comprobantes/nuevo?tipo=FC');
  await page.fill('#cliente-q', tipoCliente);
  await page.waitForSelector('[data-cliente-lista] li');
  await page.click('[data-cliente-lista] li');
  const r = page.locator('[data-renglon]').nth(0);
  await r.locator('[data-descripcion]').fill('Servicio ARCA');
  await r.locator('[data-cantidad]').fill('1');
  await r.locator('[data-precio]').fill(precio);
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
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

    // Datos de la empresa y dos clientes.
    await page.goto(BASE + '/tablas/parametros');
    await page.fill('#f-empresa', 'EMPRESA DE PRUEBA E2E SA');
    await page.fill('#f-cuit', '30-70765648-0');
    await page.selectOption('#f-iva', '1');
    await enviar(page);
    await page.goto(BASE + '/clientes/nuevo');
    await page.fill('#f-razon_social', 'CLIENTE E2E ARCA SRL');
    await page.selectOption('#f-iva', '1');
    await page.fill('#f-cuit', cuitAlAzar());
    await enviar(page);
    ok((await page.textContent('.alerta')).includes('creado'), 'cliente creado');

    // Con la sucursal sin "Factura electrónica" sigue siendo interno.
    await emitir(page, 'E2E ARCA', '100');
    ok((await page.textContent('body')).includes('interno'), 'sucursal sin tildar: la revisión dice que es interno');

    // Se tilda "Factura electrónica" en la sucursal.
    await page.goto(BASE + '/tablas/sucursales');
    await page.click('tbody tr:first-child a');
    await page.check('#f-fac_electronica');
    await enviar(page);
    await emitir(page, 'E2E ARCA', '100');
    ok((await page.textContent('body')).includes('ARCA SIMULADO'), 'la revisión avisa que es ARCA simulado');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    const url = page.url();
    const cuerpo = await page.textContent('body');
    ok(/CAE/.test(cuerpo) && /prueba|simulado/i.test(cuerpo), 'la factura queda con CAE y marcada como de prueba');
    await page.screenshot({ path: path.join(CAPTURAS, 'arca-factura-ver.png'), fullPage: true });
    await page.goto(url + '/imprimir');
    ok((await page.locator('.qr svg').count()) === 1, 'la página imprimible lleva el QR de ARCA');
    ok((await page.textContent('body')).includes('Comprobante Autorizado ARCA'), 'dice Comprobante Autorizado ARCA');
    await page.screenshot({ path: path.join(CAPTURAS, 'arca-factura-imprimir.png'), fullPage: true });
    const pdf = await ctx.request.get(url + '/pdf');
    ok(pdf.status() === 200 && (pdf.headers()['content-type'] || '').includes('application/pdf'), 'el PDF con QR se baja');

    // Rechazo: total que termina en ,13 (0,11 + IVA 21 % = 0,13).
    await emitir(page, 'E2E ARCA', '0,11');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    ok((await page.textContent('body')).includes('rechaz'), 'ARCA rechaza el total ,13 y se muestra el motivo');
    await page.goto(BASE + '/comprobantes?cliente=E2E');
    ok((await page.locator('tbody tr').count()) === 1, 'el rechazado no quedó grabado (queda solo la factura aprobada)');

    // Sistema → ARCA
    await page.goto(BASE + '/arca');
    ok((await page.textContent('h1')).includes('ARCA'), 'abre Sistema → ARCA');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Probar conexión")')]);
    ok(!(await page.textContent('body')).includes('✖'), 'el diagnóstico simulado pasa todos los pasos');
    await page.screenshot({ path: path.join(CAPTURAS, 'arca-sistema.png'), fullPage: true });
    ok(await page.locator('text=Borrar comprobantes de prueba').count() === 0, 'el Administrador no ve el borrado de pruebas');

    // Libro IVA Digital: los comprobantes de prueba no se informan a ARCA
    await page.goto(BASE + '/listados/libro-iva-digital');
    ok((await page.textContent('h1')).includes('Libro IVA Digital'), 'abre Listados → Libro IVA Digital');
    ok((await page.textContent('body')).includes('de prueba'), 'avisa que los comprobantes de prueba no entran al libro');
    ok((await page.textContent('body')).includes('No hay comprobantes con CAE'), 'sin comprobantes fiscales en el mes: sin movimiento');
    const rd = await ctx.request.get(BASE + '/listados/libro-iva-digital/descargar?periodo=2000-01&archivo=cbte');
    ok(rd.status() === 200 && (rd.headers()['content-disposition'] || '').includes('LIBRO_IVA_DIGITAL_VENTAS_CBTE'), 'se baja el archivo CBTE (vacío si no hay movimiento)');

    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
    await ctx.close();

    const movil = await browser.newContext({ ...devices['Pixel 7'] });
    const p2 = await movil.newPage();
    await login(p2, 'e2e_admin');
    await p2.goto(url + '/imprimir');
    ok(await p2.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'celular: la factura con QR no se desborda');
    await p2.goto(BASE + '/arca');
    ok(await p2.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'celular: Sistema → ARCA no se desborda');
    await p2.goto(BASE + '/listados/libro-iva-digital');
    ok(await p2.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'celular: Libro IVA Digital no se desborda');
    await p2.screenshot({ path: path.join(CAPTURAS, 'celular-arca.png'), fullPage: true });
    await movil.close();
  } finally {
    await browser.close();
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), '--limpiar'], { stdio: 'inherit' });
    process.kill(-server.pid);
  }
  console.log(fallas === 0 ? '\nRecorrido ARCA completo sin fallas.' : `\n${fallas} falla(s).`);
  process.exit(fallas === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
