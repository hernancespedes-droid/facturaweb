// Recorrido de navegador del flujo principal de la etapa 1 (escritorio y celular).
//   node e2e/recorrido.js            contra http://127.0.0.1:8080 (levanta bin/dev-server.sh si no está)
// Usa el usuario e2e_admin / e2e_operador que crea tests/fixtures/e2e_usuarios.php (lo corre bin/test.sh).
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8080';
const ROOT = path.resolve(__dirname, '..');
const CAPTURAS = path.join(__dirname, 'capturas');
const CLAVE = 'E2e-Prueba-2026';

let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };

// CUIT válido al azar (prefijo 30), para no chocar con clientes ya cargados.
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

async function servidorArriba() {
  try { const r = await fetch(BASE + '/salud'); return r.ok; } catch (e) { return false; }
}

async function login(page, usuario) {
  await page.goto(BASE + '/login');
  await page.fill('input[name=usuario]', usuario);
  await page.fill('input[name=clave]', CLAVE);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}

async function enviar(page, selector = 'form.card button[type=submit]') {
  await Promise.all([page.waitForNavigation(), page.click(selector)]);
}

(async () => {
  let server = null;
  if (!(await servidorArriba())) {
    server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true });
    for (let i = 0; i < 40 && !(await servidorArriba()); i++) await new Promise(r => setTimeout(r, 250));
  }
  execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), CLAVE], { stdio: 'inherit' });
  fs.mkdirSync(CAPTURAS, { recursive: true });
  const browser = await chromium.launch();
  try {
    // ---- Escritorio, Administrador
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', e => errores.push(e.message));

    await page.goto(BASE + '/');
    ok(page.url().endsWith('/login'), 'sin sesión manda al login');
    await page.fill('input[name=usuario]', 'e2e_admin');
    await page.fill('input[name=clave]', 'mal');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    ok((await page.textContent('.alerta-error')).includes('incorrectos'), 'contraseña mala: avisa');
    await login(page, 'e2e_admin');
    ok((await page.textContent('h1')).includes('Facturación'), 'entra al inicio');
    await page.screenshot({ path: path.join(CAPTURAS, 'inicio.png'), fullPage: true });

    // Tablas: alta de un vendedor, edición y borrado.
    await page.goto(BASE + '/tablas');
    ok(await page.locator('.tile').count() === 12, 'Tablas muestra las 12 tablas');
    await page.goto(BASE + '/tablas/vendedores/nuevo');
    await page.fill('#f-codigo', 'zz');
    await page.fill('#f-descripcion', 'VENDEDOR E2E');
    await page.fill('#f-comision_1', '2,5');
    await enviar(page);
    ok((await page.content()).includes('VENDEDOR E2E'), 'vendedor creado (código en mayúsculas)');
    ok((await page.content()).includes('>ZZ<'), 'el código quedó en mayúsculas');
    await page.click('text=ZZ');
    ok(await page.isDisabled('#f-codigo'), 'el código no se edita');
    await page.fill('#f-descripcion', 'VENDEDOR E2E 2');
    await enviar(page);
    ok((await page.content()).includes('VENDEDOR E2E 2'), 'vendedor modificado');

    // Parámetros (registro único): datos de la empresa.
    await page.goto(BASE + '/tablas/parametros');
    await page.fill('#f-empresa', 'EMPRESA DE PRUEBA E2E SA');
    await page.fill('#f-cuit', '20-12345678-3');
    await enviar(page);
    ok((await page.textContent('.alerta-error, .field-error')).includes('CUIT'), 'CUIT inválido: avisa');
    await page.fill('#f-cuit', '30-70765648-0');
    await page.selectOption('#f-iva', '1');
    await enviar(page);
    ok((await page.textContent('.alerta')).includes('Cambios guardados'), 'parámetros guardados');

    // Clientes: alta con CUIT obligatorio para Inscripto.
    await page.goto(BASE + '/clientes/nuevo');
    await page.fill('#f-razon_social', 'CLIENTE E2E SRL');
    await page.selectOption('#f-iva', '1');
    await enviar(page);
    ok((await page.textContent('.field-error')).includes('CUIT es obligatorio'), 'Inscripto sin CUIT: avisa');
    const cuit = cuitAlAzar();
    await page.fill('#f-cuit', cuit);
    await page.fill('#f-emailfc', 'facturas@e2e.com; otro@e2e.com');
    await page.selectOption('#f-vendedor', 'ZZ');
    await enviar(page);
    ok((await page.textContent('.alerta')).includes('creado'), 'cliente creado');
    const urlCliente = page.url();
    await page.screenshot({ path: path.join(CAPTURAS, 'cliente.png'), fullPage: true });
    await page.goto(BASE + '/clientes?q=E2E');
    ok((await page.content()).includes('CLIENTE E2E SRL'), 'cliente en la grilla buscando');
    await page.goto(BASE + '/clientes?q=' + cuit);
    ok((await page.content()).includes('CLIENTE E2E SRL'), 'cliente encontrado por CUIT');

    // Artículos: alta y búsqueda.
    await page.goto(BASE + '/articulos/nuevo');
    await page.fill('#f-codigo', 'e2e-001');
    await page.fill('#f-descripcion', 'ARTICULO E2E');
    await page.fill('#f-precio_de_venta_1', '1234,5');
    await enviar(page);
    ok((await page.textContent('.alerta')).includes('E2E-001'), 'artículo creado con el código en mayúsculas');
    await page.goto(BASE + '/articulos?q=e2e');
    ok((await page.content()).includes('1.234,5'), 'artículo en la grilla con el precio');

    // ---- Facturación
    await page.goto(BASE + '/clientes/nuevo');
    await page.fill('#f-razon_social', 'CLIENTE E2E FINAL');
    await page.selectOption('#f-iva', '3');
    await enviar(page);
    ok((await page.textContent('.alerta')).includes('creado'), 'cliente consumidor final creado');

    await page.goto(BASE + '/comprobantes/nuevo?tipo=FC');
    ok((await page.textContent('h1')).includes('Factura'), 'abre el formulario de factura');
    await page.fill('#cliente-q', 'CLIENTE E2E SRL');
    await page.waitForSelector('[data-cliente-lista] li');
    await page.click('[data-cliente-lista] li');
    ok((await page.textContent('[data-cliente-info]')).includes('Inscripto'), 'elige el cliente y muestra su condición');
    ok((await page.textContent('[data-letra-titulo]')).trim() === 'A', 'cliente inscripto: factura A');
    // renglón 1: artículo del maestro
    const r1 = page.locator('[data-renglon]').nth(0);
    await r1.locator('[data-articulo]').fill('abono por mant');   // el buscador sugiere por descripción
    await page.waitForSelector('#articulos-sugerencias li');
    ok((await page.textContent('#articulos-sugerencias')).toUpperCase().includes('MANTENIMIENTO'), 'el buscador muestra coincidencias por descripción');
    await page.screenshot({ path: path.join(CAPTURAS, 'buscador-renglon.png') });
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => document.querySelector('[data-descripcion]').value.includes('MANTENIMIENTO'));
    ok(await page.locator('#articulos-sugerencias').isHidden(), 'al elegir se cierra la lista');
    await r1.locator('[data-texto]').fill('Servicio de febrero\nVisita en sucursal');
    ok((await r1.locator('[data-descripcion]').inputValue()).includes('ABONO'), 'el artículo trae su descripción');
    await r1.locator('[data-cantidad]').fill('2');
    await r1.locator('[data-precio]').fill('1000');
    // renglón 2: libre, al 10,5 %
    const r2 = page.locator('[data-renglon]').nth(1);
    await r2.locator('[data-descripcion]').fill('Servicio libre');
    await r2.locator('[data-cantidad]').fill('1');
    await r2.locator('[data-precio]').fill('500');
    await r2.locator('[data-alicuota]').selectOption({ label: '10,5 %' });
    ok((await page.textContent('[data-t-total]')).includes('2.972,50'), 'total en vivo: 2.000 + 500 + IVA 472,50 = 2.972,50');
    ok((await page.textContent('[data-t-iva-filas]')).includes('420,00'), 'IVA 21 % discriminado en la A');
    await page.screenshot({ path: path.join(CAPTURAS, 'factura-form.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
    ok((await page.textContent('h1')).includes('Factura A'), 'revisión de la factura A');
    ok((await page.textContent('.totales-rev')).includes('2.972,50'), 'la revisión recalcula en el servidor');
    await page.screenshot({ path: path.join(CAPTURAS, 'factura-revisar.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    ok((await page.textContent('.alerta')).includes('emitida'), 'factura emitida');
    const urlFactura = page.url();
    ok(/Factura A 0004-\d{8}/.test(await page.textContent('h1')), 'numerada con el punto de venta 0004');
    await page.screenshot({ path: path.join(CAPTURAS, 'factura-ver.png'), fullPage: true });
    // impresión y PDF
    const pdf = await ctx.request.get(urlFactura + '/pdf');
    ok(pdf.status() === 200 && (pdf.headers()['content-type'] || '').includes('application/pdf'), 'el PDF se baja');
    await page.goto(urlFactura + '/imprimir');
    ok((await page.textContent('body')).includes('COMPROBANTE INTERNO'), 'la página imprimible dice que es interno');
    await page.screenshot({ path: path.join(CAPTURAS, 'factura-imprimir.png'), fullPage: true });
    // nota de crédito desde la factura
    await page.goto(urlFactura);
    await Promise.all([page.waitForNavigation(), page.click('text=Hacer nota de crédito')]);
    ok((await page.textContent('h1')).includes('Nota de crédito'), 'abre la nota de crédito');
    ok((await page.inputValue('[data-renglon] [data-descripcion]')).includes('ABONO'), 'trae los renglones de la factura');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    ok(/Nota de crédito A 0004-\d{8}/.test(await page.textContent('h1')), 'nota de crédito emitida');
    await page.goto(urlFactura);
    ok(!(await page.content()).includes('Hacer nota de crédito'), 'la factura ya está acreditada por completo');
    // consumidor final, contado: factura B con IVA incluido
    await page.goto(BASE + '/comprobantes/nuevo?tipo=FC');
    await page.fill('#cliente-q', 'CLIENTE E2E FINAL');
    await page.waitForSelector('[data-cliente-lista] li');
    await page.click('[data-cliente-lista] li');
    ok((await page.textContent('[data-letra-titulo]')).trim() === 'B', 'consumidor final: factura B');
    await page.check('input[name=forma_pago][value="1"]');
    ok(await page.isVisible('[data-fpago]'), 'contado: elige con qué paga');
    const b1 = page.locator('[data-renglon]').nth(0);
    await b1.locator('[data-descripcion]').fill('Consulta');
    await b1.locator('[data-cantidad]').fill('1');
    await b1.locator('[data-precio]').fill('1210');
    ok((await page.textContent('[data-t-total]')).includes('1.210,00'), 'en la B el total es el precio con IVA');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
    ok((await page.textContent('.totales-rev')).includes('IVA contenido: $ 210,00'), 'IVA contenido de la B');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    ok(/Factura B 0004-\d{8}/.test(await page.textContent('h1')), 'factura B emitida');
    ok((await page.textContent('body')).includes('Contado'), 'queda como contado');
    // listado
    await page.goto(BASE + '/comprobantes?cliente=E2E');
    ok((await page.locator('tbody tr').count()) === 3, 'el listado muestra las tres (A, NC y B)');
    await page.screenshot({ path: path.join(CAPTURAS, 'comprobantes-lista.png'), fullPage: true });
    // stock: ingreso con bultos y consulta de saldos
    await page.goto(BASE + '/stock/ajuste');
    await page.fill('input[name=articulo]', 'e2e-001');
    await page.fill('input[name=cantidad]', '15');
    await page.fill('input[name=motivo]', 'stock inicial e2e');
    await enviar(page, 'form.card button[type=submit]');
    ok(!(await page.locator('.alerta-error').count()) && page.url().includes('/stock/articulo/'), 'el ingreso de stock se registra' + ((await page.locator('.alerta-error').count()) ? ': ' + await page.textContent('.alerta-error') : ''));
    await page.goto(BASE + '/stock?q=e2e');
    ok((await page.content()).includes('E2E-001'), 'saldos de stock: aparece el artículo con saldo');
    await page.screenshot({ path: path.join(CAPTURAS, 'stock-saldos.png'), fullPage: true });
    // agente de sincronización: crear una clave y verla una sola vez
    await page.goto(BASE + '/agente');
    await page.fill('input[name=nombre]', 'T e2e agente');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Crear clave")')]);
    ok(/fwk_[a-f0-9]{48}/.test(await page.textContent('.alerta-clave')), 'el agente: se muestra la clave nueva');
    await page.goto(BASE + '/agente');
    ok(!/fwk_[a-f0-9]{48}/.test(await page.textContent('body')), 'la clave no se vuelve a mostrar');
    await page.screenshot({ path: path.join(CAPTURAS, 'agente.png'), fullPage: true });
    // subdiario de IVA ventas (los comprobantes internos son de prueba: hay que tildar incluirlos)
    await page.goto(BASE + '/listados/iva-ventas?pruebas=1');
    ok((await page.textContent('h1')).includes('Subdiario de IVA ventas'), 'abre el subdiario de IVA ventas');
    ok((await page.locator('tbody tr').count()) >= 3, 'el subdiario lista los comprobantes emitidos');
    ok((await page.textContent('tfoot')).includes('Totales'), 'el subdiario muestra los totales del período');
    await page.screenshot({ path: path.join(CAPTURAS, 'iva-ventas.png'), fullPage: true });
    // sin mail configurado el envío está deshabilitado
    await page.goto(urlFactura);
    ok(await page.isDisabled('#mail button[type=submit]'), 'sin SMTP configurado el botón Enviar está deshabilitado');

    // Borrar el vendedor que usa un cliente: no deja.
    await page.goto(BASE + '/tablas/vendedores?q=E2E');
    await page.click('text=ZZ');
    page.once('dialog', d => d.accept());
    await Promise.all([page.waitForNavigation(), page.click('.zona-peligro button')]);
    ok((await page.textContent('.alerta')).includes('lo usan'), 'no borra un vendedor en uso');
    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
    await ctx.close();

    // ---- Celular, Operador: no ve Sistema ni Sucursales.
    const movil = await browser.newContext({ ...devices['Pixel 7'] });
    const p2 = await movil.newPage();
    await login(p2, 'e2e_operador');
    const menu = await p2.content();
    ok(!menu.includes('/usuarios"') && !menu.includes('/tablas/sucursales"'), 'operador: el menú no muestra Usuarios ni Sucursales');
    ok(menu.includes('/comprobantes/nuevo?tipo=FC'), 'operador: el menú muestra Nueva factura');
    await p2.goto(BASE + '/comprobantes/nuevo?tipo=FC');
    ok(await p2.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'celular: el formulario de factura no se desborda');
    await p2.screenshot({ path: path.join(CAPTURAS, 'celular-factura.png'), fullPage: true });
    const r = await p2.goto(BASE + '/tablas/sucursales');
    ok(r.status() === 403, 'operador: Sucursales responde 403');
    await p2.goto(urlCliente);
    ok((await p2.textContent('h1')).includes('CLIENTE E2E SRL'), 'operador: ve la ficha del cliente');
    await p2.click('#menu-toggle + label').catch(() => {});
    await p2.screenshot({ path: path.join(CAPTURAS, 'celular-cliente.png'), fullPage: true });
    const ancho = await p2.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
    ok(ancho, 'celular: sin scroll horizontal en la ficha');
    await movil.close();
  } finally {
    await browser.close();
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), '--limpiar'], { stdio: 'inherit' });
    if (server) process.kill(-server.pid);
  }
  console.log(fallas === 0 ? '\nRecorrido completo sin fallas.' : `\n${fallas} falla(s).`);
  process.exit(fallas === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
