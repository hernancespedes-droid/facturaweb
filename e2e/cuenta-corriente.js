// Recorrido de navegador de la etapa 4: cuenta corriente y recibos (escritorio, celular y permisos del Operador).
//   node e2e/cuenta-corriente.js   levanta su propio servidor en el puerto 8082
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const PORT = process.env.E2E_CC_PORT || '8082';
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
const sinDesborde = (p) => p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);

// Factura a cuenta corriente de $ precio + IVA para el cliente "E2E CC"
async function factura(page, precio) {
  await page.goto(BASE + '/comprobantes/nuevo?tipo=FC');
  await page.fill('#cliente-q', 'E2E CC');
  await page.waitForSelector('[data-cliente-lista] li');
  await page.click('[data-cliente-lista] li');
  const r = page.locator('[data-renglon]').nth(0);
  await r.locator('[data-descripcion]').fill('Servicio E2E');
  await r.locator('[data-cantidad]').fill('1');
  await r.locator('[data-precio]').fill(precio);
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
}

(async () => {
  const server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true, env: { ...process.env, PORT } });
  for (let i = 0; i < 40; i++) { try { if ((await fetch(BASE + '/salud')).ok) break; } catch (e) {} await new Promise(r => setTimeout(r, 250)); }
  execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), CLAVE], { stdio: 'inherit' });
  fs.mkdirSync(CAPTURAS, { recursive: true });
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', e => errores.push(e.message));
    page.on('dialog', d => d.accept());
    await login(page, 'e2e_admin');

    await page.goto(BASE + '/tablas/parametros');
    await page.fill('#f-empresa', 'EMPRESA DE PRUEBA E2E SA');
    await page.fill('#f-cuit', '30-70765648-0');
    await page.selectOption('#f-iva', '1');
    await enviar(page);
    await page.goto(BASE + '/clientes/nuevo');
    await page.fill('#f-razon_social', 'CLIENTE E2E CC SRL');
    await page.selectOption('#f-iva', '1');
    await page.fill('#f-cuit', cuitAlAzar());
    await enviar(page);
    await factura(page, '1000'); // 1.210
    await factura(page, '500');  //   605

    // ---- Elegir el cliente con el buscador (siempre se elige a mano) y ver su cuenta
    await page.goto(BASE + '/cuenta-corriente');
    ok((await page.textContent('h1')).includes('Deuda de clientes'), 'abre Cuenta corriente');
    await page.fill('#cliente-q', 'E2E CC');
    await page.waitForSelector('[data-cliente-lista] li');
    await Promise.all([page.waitForNavigation(), page.click('[data-cliente-lista] li')]);
    ok(page.url().includes('/cuenta-corriente/cliente/'), 'al elegir el cliente va a su cuenta');
    const codigo = page.url().split('/').pop();
    ok((await page.textContent('body')).includes('1.815,00'), 'el saldo es la suma de las dos facturas (1.815,00)');
    await page.screenshot({ path: path.join(CAPTURAS, 'ctacte-cliente.png'), fullPage: true });

    // ---- Recibo: se cobra la primera factura completa y la segunda en parte, con efectivo y un cheque
    await page.goto(BASE + '/recibos/nuevo');
    await page.fill('#cliente-q', 'E2E CC');
    await page.waitForSelector('[data-cliente-lista] li');
    await Promise.all([page.waitForNavigation(), page.click('[data-cliente-lista] li')]);
    ok((await page.textContent('h1')).includes('CLIENTE E2E CC'), 'el recibo arranca con el cliente elegido');
    ok((await page.locator('[data-marca]').count()) === 2, 'lista las dos facturas pendientes');
    await page.locator('details summary').click();
    await page.click('[data-agregar-ret]');
    ok((await page.locator('[data-ret-fila]').count()) === 1, 'se puede agregar un renglón de retención (de la tabla)');
    ok((await page.locator('[data-ret-fila] select option').count()) > 4, 'las retenciones salen de la tabla, agrupadas por impuesto');
    await page.click('[data-quitar-ret]');
    ok((await page.locator('[data-ret-fila]').count()) === 0, 'se puede quitar el renglón');
    await page.locator('[data-marca]').nth(0).check();
    ok((await page.locator('[data-imputar]').nth(0).inputValue()).startsWith('1.210'), 'al tildar carga el saldo de la factura');
    await page.locator('[data-imputar]').nth(1).fill('105');
    ok(await page.locator('[data-marca]').nth(1).isChecked(), 'al escribir un importe se tilda solo');
    ok((await page.textContent('[data-t-neto]')).includes('1.315,00'), 'el total que se cobra se calcula (1.315,00)');
    ok((await page.textContent('[data-t-dif]')).includes('Faltan'), 'avisa que faltan medios de pago');
    const fp = page.locator('[data-fpago]').nth(0);
    await fp.selectOption({ label: 'EFECTIVO' });
    await page.locator('[data-pago-importe]').nth(0).fill('1.000');
    await page.click('[data-agregar-pago]');
    await page.locator('[data-fpago]').nth(1).selectOption({ label: 'CHEQUE E2E' });
    ok(await page.locator('[name="pagos[1][nro]"]').isVisible(), 'al elegir cheque pide número, banco y fecha');
    await page.locator('[data-pago-importe]').nth(1).fill('315');
    await page.fill('[name="pagos[1][nro]"]', '55501');
    await page.selectOption('[name="pagos[1][banco]"]', '998');
    const en30 = new Date(Date.now() + 30 * 864e5);
    await page.fill('[name="pagos[1][vto]"]', String(en30.getDate()).padStart(2, '0') + '/' + String(en30.getMonth() + 1).padStart(2, '0') + '/' + en30.getFullYear());
    ok((await page.textContent('[data-t-dif]')).includes('Cuadra'), 'cuando los medios suman lo que se cobra dice «Cuadra»');
    await page.screenshot({ path: path.join(CAPTURAS, 'recibo-form.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Grabar recibo")')]);
    ok(/\/recibos\/\d+$/.test(page.url()), 'el recibo se graba y se muestra');
    const cuerpo = await page.textContent('body');
    ok(cuerpo.includes('1.315,00') && cuerpo.includes('55501'), 'el recibo muestra lo cobrado y el cheque');
    const reciboUrl = page.url();
    await page.screenshot({ path: path.join(CAPTURAS, 'recibo-ver.png'), fullPage: true });
    await page.goto(reciboUrl + '/imprimir');
    ok((await page.textContent('body')).includes('RECIBO X'), 'se puede imprimir el recibo');

    await page.goto(BASE + '/cuenta-corriente/cliente/' + codigo);
    ok((await page.textContent('body')).includes('500,00'), 'quedan 500,00 de deuda (605 − 105)');
    await page.goto(BASE + '/cheques');
    ok((await page.textContent('body')).includes('55501'), 'el cheque está en cartera');
    await page.screenshot({ path: path.join(CAPTURAS, 'cheques.png'), fullPage: true });

    // ---- Anular: la deuda vuelve y el cheque desaparece de la cartera
    await page.goto(reciboUrl);
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Anular")')]);
    ok((await page.textContent('body')).includes('Recibo anulado'), 'el recibo se anula');
    await page.goto(BASE + '/cuenta-corriente/cliente/' + codigo);
    ok((await page.textContent('body')).includes('1.815,00'), 'la deuda vuelve a 1.815,00');
    await page.goto(BASE + '/cheques');
    ok(!(await page.textContent('body')).includes('55501'), 'el cheque ya no está');

    // ---- Excel
    const xl = await ctx.request.get(BASE + '/cuenta-corriente?excel=1');
    ok(xl.status() === 200 && (xl.headers()['content-type'] || '').includes('spreadsheetml'), 'el resumen de deuda se baja en Excel');

    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
    await ctx.close();

    // ---- Operador: cobra, pero no imputa ni ajusta
    const ctxOp = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const op = await ctxOp.newPage();
    await login(op, 'e2e_operador');
    await op.goto(BASE + '/recibos/nuevo');
    ok((await op.textContent('body')).includes('Nuevo recibo'), 'operador: puede cargar recibos');
    ok((await op.locator('nav.menu a:has-text("Imputación manual")').count()) === 0, 'operador: el menú no muestra Imputación manual');
    const r403 = await op.goto(BASE + '/cuenta-corriente/imputar');
    ok(r403.status() === 403, 'operador: Imputación manual responde 403');
    const r403b = await op.goto(BASE + '/cuenta-corriente/ajuste');
    ok(r403b.status() === 403, 'operador: Ajuste responde 403');
    await ctxOp.close();

    // ---- Celular
    const movil = await browser.newContext({ ...devices['Pixel 7'] });
    const p2 = await movil.newPage();
    await login(p2, 'e2e_admin');
    for (const [ruta, nombre] of [['/cuenta-corriente', 'Cuenta corriente'], ['/cuenta-corriente/cliente/' + codigo, 'el extracto'], ['/recibos/nuevo?cliente=' + codigo, 'el formulario de recibo'], ['/cheques', 'Cheques'], ['/recibos', 'Recibos']]) {
      await p2.goto(BASE + ruta);
      ok(await sinDesborde(p2), 'celular: ' + nombre + ' no se desborda');
    }
    await p2.screenshot({ path: path.join(CAPTURAS, 'celular-recibo.png'), fullPage: true });
    await movil.close();
  } finally {
    await browser.close();
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), '--limpiar'], { stdio: 'inherit' });
    process.kill(-server.pid);
  }
  console.log(fallas === 0 ? '\nRecorrido de cuenta corriente completo sin fallas.' : `\n${fallas} falla(s).`);
  process.exit(fallas === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
