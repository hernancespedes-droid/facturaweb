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
  await page.check('input[name=forma_pago][value="2"]'); // la forma de pago se elige al terminar
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
    ok((await page.locator('th.th-ordenable').count()) > 0 && (await page.locator('.grilla-barra a').count()) > 0, 'grilla del recibo: orden por columna y Excel');
    ok(await page.evaluate(() => { const w = document.querySelector('.table-wrap.grilla'); return !!w && w.style.maxHeight !== ''; }), 'grilla del recibo: entra en la pantalla');
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
    ok(await page.isVisible('[data-recibido]'), 'recibo en efectivo: pide lo que entrega el cliente (D-134)');
    await page.click('[data-v-sumar="2000"]');
    ok((await page.textContent('[data-v-resultado]')).includes('1.000,00'), 'recibo: la calculadora muestra el vuelto (2.000 − 1.000)');
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
    ok((await page.textContent('.vuelto-final-monto')).includes('1.000,00'), 'recibo: al grabar, cartel grande con el vuelto');
    await page.click('[data-vuelto-listo]');
    ok((await page.textContent('body')).includes('vuelto $ 1.000,00'), 'recibo: muestra lo recibido y el vuelto');
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

    // ---- Imputador en dos paneles: un ajuste de crédito de 1.000 se aplica a las facturas de a una
    await page.goto(BASE + '/cuenta-corriente/ajuste?cliente=' + codigo);
    await page.selectOption('[name="tipo"]', 'AC');
    await page.fill('[name="importe"]', '1000');
    await page.fill('[name="observaciones"]', 'Saldo a favor E2E');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Grabar ajuste")')]);
    await page.goto(BASE + '/cuenta-corriente/imputar?cliente=' + codigo);
    ok((await page.locator('[data-imputador] .imputador > section').count()) === 2, 'imputador: dos paneles (saldos a favor y deuda)');
    ok(await page.locator('[data-imputar-btn]').isDisabled(), 'imputador: sin nada tildado no se puede imputar');
    await page.locator('[data-marca-db]').nth(0).check({ force: true }).catch(() => {});
    ok((await page.locator('[data-imp-db]').nth(0).inputValue()) === '', 'imputador: sin saldos a favor tildados no se cancela deuda');
    await page.locator('[data-marca-cr]').nth(0).check();
    ok((await page.textContent('[data-t-aimputar]')).includes('1.000,00'), 'imputador: tildar el saldo a favor suma el total a imputar');
    await page.locator('[data-marca-db]').nth(0).check();
    const impA = await page.locator('[data-imp-db]').nth(0).inputValue();
    ok(impA.startsWith('1.000'), 'imputador: la factura se cancela hasta agotar lo que hay (1.000 de 1.210)');
    ok((await page.textContent('[data-t-falta]')).includes('0,00') && await page.locator('[data-imputar-btn]').isEnabled(), 'imputador: queda en 0 y se habilita Imputar');
    ok(await page.locator('[data-marca-db]').nth(1).isDisabled(), 'imputador: sin nada por imputar no deja tildar más deuda');
    await page.screenshot({ path: path.join(CAPTURAS, 'imputador.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('[data-imputar-btn]')]);
    ok((await page.textContent('body')).includes('Imputación hecha'), 'imputador: se imputa');
    await page.goto(BASE + '/cuenta-corriente/cliente/' + codigo);
    ok((await page.textContent('body')).includes('815,00'), 'imputador: la deuda queda en 815,00');

    // ---- Presupuesto: se carga como una factura (letra X), se cobra o va a cuenta corriente y genera UNA factura solo al subdiario
    await page.goto(BASE + '/comprobantes/nuevo?tipo=PR&cliente=' + codigo);
    ok((await page.textContent('h1')).includes('Presupuesto'), 'presupuesto: abre el formulario');
    ok((await page.textContent('[data-letra-titulo]')).trim() === 'X', 'presupuesto: letra X');
    ok(await page.locator('[data-concepto]').count() === 0, 'presupuesto: no pide concepto de ARCA');
    const rp = page.locator('[data-renglon]').nth(0);
    await rp.locator('[data-descripcion]').fill('Presupuesto E2E');
    await rp.locator('[data-cantidad]').fill('2');
    await rp.locator('[data-precio]').fill('121');
    ok((await page.textContent('[data-t-total]')).includes('242,00'), 'presupuesto: el precio lleva el IVA incluido (2 x 121 = 242)');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
    ok((await page.textContent('body')).includes('no va a ARCA ni al subdiario'), 'presupuesto: la revisión aclara que no es fiscal');
    await page.check('input[name=forma_pago][value="2"]');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    const prUrl = page.url();
    ok(/\/comprobantes\/\d+$/.test(prUrl), 'presupuesto: se emite');
    let cp = await page.textContent('body');
    ok(cp.includes('Presupuesto X') && cp.includes('Generar factura') && cp.includes('WhatsApp') && cp.includes('Enviar por mail'), 'presupuesto: botones de facturar, WhatsApp y mail');
    const pdfPr = await ctx.request.get(prUrl + '/pdf');
    ok(pdfPr.status() === 200 && (pdfPr.headers()['content-type'] || '').includes('pdf'), 'presupuesto: sale en PDF');
    await page.screenshot({ path: path.join(CAPTURAS, 'presupuesto-ver.png'), fullPage: true });
    await page.goto(BASE + '/cuenta-corriente/cliente/' + codigo);
    ok((await page.textContent('body')).includes('Presupuesto'), 'presupuesto: está en la cuenta corriente del cliente');
    await page.goto(BASE + '/recibos/nuevo?cliente=' + codigo);
    ok((await page.locator('tr:has-text("Presupuesto") [data-marca]').count()) === 1, 'presupuesto: se puede cobrar con un recibo como una factura');
    await page.goto(prUrl);
    await Promise.all([page.waitForNavigation(), page.click('a:has-text("Generar factura")')]);
    ok((await page.textContent('body')).includes('solo al subdiario'), 'presupuesto: la factura va solo al subdiario');
    await page.screenshot({ path: path.join(CAPTURAS, 'presupuesto-facturar.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    cp = await page.textContent('body');
    ok(cp.includes('Sale del presupuesto') && cp.includes('solo al subdiario'), 'presupuesto: la factura queda ligada al presupuesto');
    await page.goto(prUrl);
    cp = await page.textContent('body');
    ok(cp.includes('Factura generada') && !cp.includes('Generar factura'), 'presupuesto: ya no se puede facturar otra vez');
    await page.goto(BASE + '/listados/ventas-diarias');
    cp = await page.textContent('body');
    ok(cp.includes('Presupuesto X') && cp.includes('Ventas diarias'), 'ventas diarias: muestra el presupuesto');
    ok(!cp.includes('Factura A 0004') || (cp.match(/Factura A 0004/g) || []).length <= 2, 'ventas diarias: la factura del presupuesto no se repite');
    await page.screenshot({ path: path.join(CAPTURAS, 'ventas-diarias.png'), fullPage: true });
    await page.goto(BASE + '/listados/iva-ventas');
    ok(!(await page.textContent('body')).includes('Presupuesto X'), 'presupuesto: no está en el subdiario de IVA');

    // ---- Caja diaria: lo vendido, lo facturado a cuenta corriente y lo cobrado por forma de pago
    await page.goto(BASE + '/listados/caja-diaria');
    cp = await page.textContent('body');
    ok(cp.includes('Caja diaria') && cp.includes('Facturado en cuenta corriente') && cp.includes('Billeteras virtuales') && cp.includes('Tarjeta'), 'caja diaria: muestra lo vendido, la cuenta corriente y lo cobrado por tarjeta y billeteras');
    ok(cp.includes('Cobrado por forma de pago') && cp.includes('Cobros (ventas y cobranzas)') && cp.includes('Efectivo total en caja'), 'caja diaria: detalle por forma de pago y movimientos');
    await page.screenshot({ path: path.join(CAPTURAS, 'caja-diaria.png'), fullPage: true });

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
    for (const [ruta, nombre] of [['/cuenta-corriente', 'Cuenta corriente'], ['/cuenta-corriente/cliente/' + codigo, 'el extracto'], ['/recibos/nuevo?cliente=' + codigo, 'el formulario de recibo'], ['/cheques', 'Cheques'], ['/recibos', 'Recibos'], ['/listados/ventas-diarias', 'Ventas diarias'], ['/listados/caja-diaria', 'Caja diaria'], ['/comprobantes/nuevo?tipo=PR', 'el presupuesto']]) {
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
  if (fallas === 0 && !process.env.E2E_SIN_EXTRAS) {
    // bin/pruebas.sh está congelado: los recorridos nuevos van en e2e/extras.js.
    try { execFileSync('node', [path.join(__dirname, 'extras.js')], { stdio: 'inherit' }); } catch (e) { process.exit(1); }
  }
  process.exit(fallas === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
