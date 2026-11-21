// Recorrido de navegador de compras y centros de costos (0.43.0): tablero, proveedor, compra con reparto, pago, listados, celular y permisos.
//   node e2e/compras.js   levanta su propio servidor en el puerto 8083
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const PORT = process.env.E2E_COMPRAS_PORT || '8083';
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
async function arriba() { try { return (await fetch(BASE + '/salud')).ok; } catch (e) { return false; } }
async function sinDesborde(page, texto) {
  const w = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  ok(w <= 1, texto + ' (sin desborde horizontal: ' + w + ' px)');
}

(async () => {
  const server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true, env: { ...process.env, PORT } });
  for (let i = 0; i < 40 && !(await arriba()); i++) await new Promise(r => setTimeout(r, 250));
  execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), CLAVE], { stdio: 'inherit' });
  // empresa responsable inscripta (el fixture guardó los parámetros y los devuelve en la próxima corrida)
  execFileSync('php', ['-r', "require '" + ROOT + "/src/bootstrap.php'; App\\App::boot(); App\\Core\\Db::pdo()->exec('UPDATE parametros SET iva = 1 WHERE id = 1');"], { stdio: 'inherit' });
  fs.mkdirSync(CAPTURAS, { recursive: true });
  const browser = await chromium.launch();
  const errores = [];
  try {
    console.log('Compras: escritorio, Administrador');
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => errores.push(e.message));
    await login(page, 'e2e_admin');
    ok(await page.locator('nav.menu a[href$="/compras"]').count() > 0, 'el menú tiene el grupo Compras');
    await page.goto(BASE + '/compras');
    ok(await page.locator('h1', { hasText: 'Tablero de compras' }).count() === 1, 'abre el tablero');

    // Alta de proveedor desde "Nueva compra" (vuelve a la carga con el proveedor elegido)
    const nombre = 'E2E PROVEEDOR ' + Date.now();
    await page.goto(BASE + '/compras/nueva');
    await Promise.all([page.waitForNavigation(), page.click('text=+ Nuevo proveedor')]);
    await page.fill('input[name=razon_social]', nombre);
    const cuitProv = cuitAlAzar();
    await page.fill('input[name=cuit]', cuitProv);
    await page.fill('input[name=plazo_dias]', '30');
    await Promise.all([page.waitForNavigation(), page.click('form.card button[type=submit]')]);
    ok(page.url().includes('/compras/nueva?proveedor='), 'después del alta vuelve a la carga');
    ok((await page.inputValue('#prov-proveedor')) === nombre, 'con el proveedor elegido');
    ok((await page.inputValue('select[name=letra]')) === 'A', 'letra A para un responsable inscripto');

    // Buscador: escribir y elegir (nunca se toma el primero solo)
    await page.fill('#prov-proveedor', '');
    await page.type('#prov-proveedor', nombre.slice(0, 18));
    await page.waitForSelector('[data-cliente-lista] li');
    await page.click('[data-cliente-lista] li >> nth=0');
    ok((await page.inputValue('input[name=proveedor]')) !== '', 'elige el proveedor de la lista');

    await page.fill('input[name=punto_venta]', '3');
    await page.fill('input[name=numero]', String(Date.now() % 90000000));
    await page.fill('input[name="neto[21]"]', '1000');
    ok((await page.inputValue('input[name="iva[21]"]')) === '210,00', 'propone el IVA al 21 %');
    await page.fill('input[name="trib[0][importe]"]', '30');
    await page.fill('input[name=total_impreso]', '1240');
    ok(await page.locator('[data-control] .pill-ok').count() === 1, 'el total coincide con el impreso');
    ok((await page.textContent('[data-costo]')).includes('1.000,00'), 'el costo es sin IVA ni percepciones');
    await page.click('[data-repartir]');
    const pct = page.locator('[data-pct]');
    await pct.nth(0).fill('60');
    await pct.nth(1).fill('40');
    ok(await page.locator('[data-estado-reparto] .pill-ok').count() === 1, 'el reparto suma 100 %');
    ok((await page.locator('[data-imp]').nth(1).textContent()) === '400,00', 'reparte el costo por porcentaje');
    ok((await page.inputValue('[data-vence]')) !== '', 'propone el vencimiento con el plazo del proveedor');
    await page.screenshot({ path: path.join(CAPTURAS, 'compras-nueva.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Grabar compra")')]);
    ok(page.url().includes('/compras/ver/'), 'graba y muestra la compra');
    ok(await page.locator('text=Compra grabada').count() > 0, 'avisa que se grabó');
    ok(await page.locator('table >> text=60 %').count() > 0, 'muestra el reparto');

    // Orden de pago desde la compra: queda tildada con su saldo; se paga una parte por transferencia
    await Promise.all([page.waitForNavigation(), page.click('a:has-text("Nueva orden de pago")')]);
    ok(page.url().includes('/compras/pagos/nueva'), 'abre la orden de pago');
    ok(await page.locator('[data-tilde]').first().isChecked(), 'la compra viene tildada');
    ok((await page.textContent('[data-op-apagar]')).includes('1.240,00'), 'a pagar = saldo de la compra');
    ok((await page.inputValue('[data-medio-imp]')) === '1.240,00', 'con un solo medio propone lo que hay que pagar');
    await page.fill('[data-imp="deb"]', '1000');
    ok((await page.inputValue('[data-medio-imp]')) === '1.000,00', 'si cambia lo que se paga, el medio acompaña');
    await page.selectOption('[data-medio]', 'T');
    ok(await page.locator('[data-cheque]').isHidden(), 'la transferencia no pide cheque');
    await page.click('[data-agregar-medio]');
    await page.selectOption('[data-medio] >> nth=1', 'R');
    ok(await page.locator('select[name="medios[1][retencion]"]').isVisible(), 'la retención pide cuál es');
    await page.selectOption('select[name="medios[1][retencion]"]', 'GAN');
    await page.fill('input[name="medios[1][numero]"]', 'CERT-1');
    await page.fill('input[name="medios[1][importe]"]', '100');
    await page.fill('input[name="medios[0][importe]"]', '900');
    ok(await page.locator('[data-op-dif] .pill-ok').count() === 1, 'cierra justo');
    await page.screenshot({ path: path.join(CAPTURAS, 'compras-orden-pago.png'), fullPage: true });
    const provId = await page.inputValue('form#form-op input[name=proveedor]');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir orden de pago")')]);
    ok(page.url().includes('/compras/pagos/ver/'), 'emite y muestra la orden');
    ok(await page.locator('text=emitida').count() > 0, 'avisa que se emitió');
    const pdf = await page.request.get(new URL(await page.locator('a:has-text("PDF")').first().getAttribute('href'), page.url()).href);
    ok((pdf.headers()['content-type'] || '').includes('pdf'), 'la orden sale en PDF');
    await page.goto(BASE + '/compras/pagos');
    ok(await page.locator('td', { hasText: nombre }).count() > 0, 'el listado de órdenes de pago la muestra');
    await page.goto(BASE + '/compras/proveedores/cuenta?proveedor=' + provId);
    ok((await page.textContent('main')).includes('240,00'), 'la cuenta corriente muestra lo que falta pagar');

    // Compra con artículos: el buscador propone y el usuario elige; la suma no puede pasar el neto
    await page.goto(BASE + '/compras/nueva?proveedor=' + provId);
    await page.fill('input[name=punto_venta]', '4');
    await page.fill('input[name=numero]', String((Date.now() + 7) % 90000000));
    await page.fill('input[name="neto[21]"]', '500');
    await page.check('[data-con-articulos]');
    ok(await page.locator('[data-articulos]').isVisible(), 'tildar «Con artículos» muestra los renglones');
    await page.type('[data-item-articulo]', 'E2E-ABO');
    await page.waitForSelector('.sugerencias li');
    await page.click('.sugerencias li >> nth=0');
    ok((await page.inputValue('[data-item-articulo]')) === 'E2E-ABONO', 'elige el artículo de la lista');
    ok((await page.inputValue('[data-item-cant]')) === '1', 'propone cantidad 1');
    await page.fill('[data-item-cant]', '5');
    await page.fill('[data-item-costo]', '100');
    ok((await page.textContent('[data-items-suma]')).includes('500,00'), 'suma los artículos');
    await page.check('input[name=forma][value=C]');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Grabar compra")')]);
    // la compra cambió el costo: pregunta si se actualizan los precios (0.49) y «No» vuelve a la compra
    if (page.url().includes('/articulos/precios?pendientes=1')) { // con márgenes cargados pregunta por los precios; «No» vuelve a la compra
      ok((await page.textContent('.alerta-info')).includes('La compra cambió el costo de 1 artículo'), 'graba la compra con artículos y pregunta por los precios');
      await Promise.all([page.waitForNavigation(), page.click('button[name=accion][value=no]')]);
    }
    ok(page.url().includes('/compras/ver/'), 'graba la compra con artículos');
    ok(await page.locator('td', { hasText: 'E2E-ABONO' }).count() > 0, 'la compra muestra sus artículos');

    // QR de la factura: el enlace pegado abre la compra con los datos (en una compu sin cámara queda pegar el enlace)
    await page.goto(BASE + '/compras/qr');
    const qrDatos = { ver: 1, fecha: new Date().toISOString().slice(0, 10), cuit: Number(cuitProv.replace(/\D/g, '')), ptoVta: 12, tipoCmp: 6, nroCmp: 4567,
      importe: 2420, moneda: 'PES', ctz: 1, tipoDocRec: 80, nroDocRec: 0, tipoCodAut: 'E', codAut: 70417054367476 };
    await page.fill('[data-qr-texto]', 'https://www.afip.gob.ar/fe/qr/?p=' + Buffer.from(JSON.stringify(qrDatos)).toString('base64'));
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Abrir la compra")')]);
    ok(await page.locator('text=Datos leídos del QR').count() === 1, 'el QR abre la compra');
    ok((await page.inputValue('input[name=numero]')) === '4567' && (await page.inputValue('select[name=letra]')) === 'B', 'con el número y la letra del QR');
    ok((await page.inputValue('input[name=importe]')) === '2.420,00', 'en una factura B propone el total');
    await page.screenshot({ path: path.join(CAPTURAS, 'compras-qr.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Grabar compra")')]);
    ok(page.url().includes('/compras/ver/'), 'graba la compra leída del QR');
    await page.goto(BASE + '/compras/resultado');
    ok(await page.locator('h1', { hasText: 'Resultado del mes' }).count() === 1 && await page.locator('svg.g-svg rect').count() > 0, 'resultado del mes con gráfico');
    await page.screenshot({ path: path.join(CAPTURAS, 'compras-resultado.png'), fullPage: true });
    await page.goto(BASE + '/compras/mis-comprobantes');
    ok(await page.locator('input[type=file][name=archivo]').count() === 1, 'Mis Comprobantes pide el archivo de ARCA');

    // Gasto sin comprobante, pagado por otro medio
    await page.goto(BASE + '/compras/nueva');
    await page.type('#prov-proveedor', nombre.slice(0, 18));
    await page.waitForSelector('[data-cliente-lista] li');
    await page.click('[data-cliente-lista] li >> nth=0');
    await page.selectOption('select[name=tipo]', 'GS');
    ok(await page.locator('input[name=numero]').isHidden(), 'el gasto sin comprobante no pide número');
    await page.fill('input[name=importe]', '500');
    await page.check('input[name=forma][value=O]');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Grabar compra")')]);
    ok(page.url().includes('/compras/ver/'), 'graba el gasto');

    // Listados
    await page.goto(BASE + '/compras/a-pagar');
    ok(await page.locator('td', { hasText: nombre }).count() > 0, 'a pagar muestra la deuda del proveedor');
    await page.goto(BASE + '/compras/analisis');
    ok(await page.locator('table.table tbody tr').count() > 0, 'el análisis de gastos tiene filas');
    await page.goto(BASE + '/compras/iva');
    ok(await page.locator('td', { hasText: nombre }).count() > 0, 'el subdiario de IVA compras tiene la factura');
    await page.goto(BASE + '/compras/centros');
    ok(await page.locator('#presupuesto').count() === 1, 'abre centros y presupuesto');
    await page.goto(BASE + '/compras');
    const barras = await page.locator('[data-graf] svg').count();
    ok(barras >= 3, 'el tablero dibuja los gráficos (' + barras + ')');
    await page.screenshot({ path: path.join(CAPTURAS, 'compras-tablero.png'), fullPage: true });

    console.log('Compras: celular');
    const cel = await browser.newContext({ ...devices['iPhone 13'] });
    const pc = await cel.newPage();
    pc.on('pageerror', e => errores.push(e.message));
    await login(pc, 'e2e_admin');
    for (const r of ['/compras', '/compras/nueva', '/compras/lista', '/compras/a-pagar', '/compras/centros', '/compras/pagos', '/compras/pagos/nueva', '/compras/proveedores/cuenta', '/compras/qr', '/compras/mis-comprobantes', '/compras/resultado']) {
      await pc.goto(BASE + r);
      await sinDesborde(pc, 'celular ' + r);
    }
    await pc.goto(BASE + '/compras');
    await pc.screenshot({ path: path.join(CAPTURAS, 'compras-tablero-celular.png'), fullPage: true });
    await pc.goto(BASE + '/compras/nueva');
    await pc.screenshot({ path: path.join(CAPTURAS, 'compras-nueva-celular.png'), fullPage: true });

    console.log('Compras: Operador sin permisos');
    const op = await (await browser.newContext()).newPage();
    await login(op, 'e2e_operador');
    ok(await op.locator('nav.menu a[href$="/compras"]').count() === 0, 'el operador no ve Compras en el menú');
    const r = await op.goto(BASE + '/compras/nueva');
    ok(r.status() === 403, 'y no entra por la dirección');
    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
  } finally {
    await browser.close();
    try { process.kill(-server.pid); } catch (e) { /* ya cerró */ }
  }
  console.log(fallas ? `\n${fallas} fallas` : '\nRecorrido de compras sin fallas');
  process.exit(fallas ? 1 : 0);
})();
