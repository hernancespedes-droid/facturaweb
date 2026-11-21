// Recorrido de navegador de los importadores (0.32.0): subir un CSV de clientes, columnas sugeridas solas, probar, importar,
// comprobantes con el formato de «Mis comprobantes» de ARCA y la pantalla de columnas en el celular.
//   node e2e/importacion.js   (usa e2e_admin de tests/fixtures/e2e_usuarios.php; limpia todo lo "E2E" al terminar)
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8080';
const ROOT = path.resolve(__dirname, '..');
const CAPTURAS = path.join(__dirname, 'capturas');
const CLAVE = 'E2e-Prueba-2026';
let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };

async function servidorArriba() {
  try { const r = await fetch(BASE + '/salud'); return r.ok; } catch (e) { return false; }
}
async function login(page, usuario) {
  await page.goto(BASE + '/login');
  await page.fill('input[name=usuario]', usuario);
  await page.fill('input[name=clave]', CLAVE);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}
async function subir(page, destino, archivo) {
  await page.goto(BASE + '/importar');
  await page.check(`input[name=destino][value=${destino}]`);
  await page.setInputFiles('input[name=archivo]', archivo);
  await Promise.all([page.waitForNavigation(), page.click('#form-importar button[type=submit]')]);
}
async function boton(page, valor) {
  await Promise.all([page.waitForNavigation(), page.click(`button[name=accion][value=${valor}]`)]);
}

(async () => {
  let server = null;
  if (!(await servidorArriba())) {
    server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true });
    for (let i = 0; i < 40 && !(await servidorArriba()); i++) await new Promise(r => setTimeout(r, 250));
  }
  execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), CLAVE], { stdio: 'inherit' });
  fs.mkdirSync(CAPTURAS, { recursive: true });
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'fw-e2e-imp-'));
  const clientes = path.join(tmp, 'e2e-clientes.csv');
  // Windows-1252 como lo guarda el Excel en castellano: «Razón» con la ó en un byte
  fs.writeFileSync(clientes, Buffer.from('Raz\xf3n social;CUIT;Condici\xf3n IVA;Tel\xe9fono\r\nE2E IMPORTADO UNO;;Consumidor final;4444-1111\r\nE2E IMPORTADO DOS;;CF;\r\n', 'latin1'));
  const cbtes = path.join(tmp, 'e2e-mis-comprobantes.csv');
  fs.writeFileSync(cbtes, '"Fecha","Tipo","Punto de Venta","Número Desde","Cód. Autorización","Nro. Doc. Receptor","Denominación Receptor","Imp. Neto Gravado","IVA","Imp. Total"\n'
    + '"02/01/2020","6 - Factura B","4","9000001","70011111111111","","E2E IMPORTADO UNO","1000","210","1210"\n');
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
    const page = await ctx.newPage();
    page.on('dialog', d => d.accept());
    const errores = [];
    page.on('pageerror', e => errores.push(e.message));
    await login(page, 'e2e_admin');
    await page.goto(BASE + '/');
    ok((await page.content()).includes('/importar"'), 'el menú de Sistema tiene «Importar datos»');

    await subir(page, 'clientes', clientes);
    ok(page.url().includes('/importar/columnas'), 'subido: pasa a elegir columnas');
    ok(await page.isVisible('text=E2E IMPORTADO UNO'), 'vista previa con los datos (acentos de Windows bien leídos)');
    ok(await page.$eval('select[name="col[razon_social]"]', s => s.value) === '0', 'razón social sugerida por el título');
    ok(await page.$eval('select[name="col[iva]"]', s => s.value) === '2', 'condición de IVA sugerida por el título');
    await page.screenshot({ path: path.join(CAPTURAS, 'importar-columnas.png'), fullPage: true });
    await boton(page, 'probar');
    ok((await page.textContent('h1')).includes('Prueba'), 'probar: no graba');
    ok((await page.content()).includes('<b>2</b> nuevas'), 'probar: 2 nuevas');
    await boton(page, 'importar');
    ok((await page.textContent('h1')).includes('Importación grabada'), 'importar ahora: grabado');
    await page.goto(BASE + '/clientes?q=E2E+IMPORTADO');
    ok((await page.content()).includes('E2E IMPORTADO DOS'), 'los clientes importados están en Clientes');

    await subir(page, 'subdiario', cbtes);
    ok(await page.$eval('select[name="col[iva_total]"]', s => s.value) === '8', '«Mis comprobantes»: el IVA va solo a «IVA total»');
    await boton(page, 'importar');
    ok((await page.textContent('h1')).includes('Importación grabada'), 'comprobante histórico importado');
    await page.goto(BASE + '/listados/iva-ventas?desde=2020-01-01&hasta=2020-01-31');
    ok((await page.content()).includes('E2E IMPORTADO UNO'), 'el comprobante importado está en el subdiario de IVA ventas');
    await page.goto(BASE + '/importar');
    ok((await page.content()).includes('e2e-mis-comprobantes.csv'), 'queda en el historial');
    await page.screenshot({ path: path.join(CAPTURAS, 'importar-inicio.png'), fullPage: true });
    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
    await ctx.close();

    const movil = await browser.newContext({ ...devices['Pixel 7'] });
    const p2 = await movil.newPage();
    p2.on('dialog', d => d.accept());
    await login(p2, 'e2e_admin');
    await p2.goto(BASE + '/importar');
    ok(await p2.evaluate(() => document.documentElement.scrollWidth <= screen.width + 1), 'celular: la pantalla de importar no se desborda');
    await subir(p2, 'clientes', clientes);
    ok(await p2.evaluate(() => document.documentElement.scrollWidth <= screen.width + 1), 'celular: elegir columnas no se desborda');
    await p2.screenshot({ path: path.join(CAPTURAS, 'celular-importar-columnas.png'), fullPage: true });
    await boton(p2, 'probar');
    ok((await p2.content()).includes('<b>2</b> actualizadas'), 'celular: volver a probar el mismo archivo no duplica (los encuentra por el nombre)');
    await movil.close();
  } finally {
    await browser.close();
    fs.rmSync(tmp, { recursive: true, force: true });
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), '--limpiar'], { stdio: 'inherit' });
    if (server) process.kill(-server.pid);
  }
  console.log(fallas === 0 ? '\nRecorrido de importación sin fallas.' : `\n${fallas} falla(s).`);
  process.exit(fallas === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
