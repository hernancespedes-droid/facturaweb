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
    ok(await page.locator('.tile').count() === 13, 'Tablas muestra las 13 tablas (las 12 del VFP y Retenciones)');
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

    // Ventana de búsqueda: «Lista» muestra los artículos, se filtra y el usuario elige
    await page.goto(BASE + '/comprobantes/nuevo?tipo=FC');
    await page.locator('[data-renglon]').nth(0).locator('[data-lista-articulos]').click();
    await page.waitForSelector('dialog.ventana-articulos[open] .va-fila');
    await page.fill('.va-buscar', 'abono por mant');
    await page.waitForFunction(() => document.querySelectorAll('.va-fila').length >= 1 && [...document.querySelectorAll('.va-fila')].every((f) => f.textContent.toUpperCase().includes('MANTENIMIENTO')));
    await page.screenshot({ path: path.join(CAPTURAS, 'ventana-articulos.png') });
    await page.locator('.va-fila').first().click();
    ok(await page.locator('dialog.ventana-articulos').evaluate((d) => !d.open), 'al elegir se cierra la ventana');
    ok((await page.locator('[data-renglon]').nth(0).locator('[data-descripcion]').inputValue()).includes('ABONO'), 'la ventana carga el artículo elegido en el renglón');
    // Lupa del cliente: ventana con la lista de clientes
    await page.locator('[data-lista-clientes]').click();
    await page.waitForSelector('dialog.ventana-lista[open] .va-fila');
    await page.fill('.va-buscar', 'cliente e2e srl');
    await page.waitForFunction(() => document.querySelectorAll('.va-fila').length >= 1 && [...document.querySelectorAll('.va-fila')].every((f) => f.textContent.toUpperCase().includes('CLIENTE E2E SRL')));
    await page.screenshot({ path: path.join(CAPTURAS, 'ventana-clientes.png') });
    await page.locator('.va-fila').first().click();
    ok(await page.locator('dialog.ventana-lista').evaluate((d) => !d.open), 'al elegir el cliente se cierra la ventana');
    ok((await page.textContent('[data-cliente-info]')).includes('Inscripto'), 'la ventana carga el cliente elegido con su condición');
    ok((await page.textContent('[data-letra-titulo]')).trim() === 'A', 'el cliente elegido de la lista define la letra');
    await page.screenshot({ path: path.join(CAPTURAS, 'cliente-lupa.png') });


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
    ok((await page.locator('input[name=forma_pago]').count()) === 0, 'la forma de pago no se pide en el formulario');
    const b1 = page.locator('[data-renglon]').nth(0);
    await b1.locator('[data-descripcion]').fill('Consulta');
    await b1.locator('[data-cantidad]').fill('1');
    await b1.locator('[data-precio]').fill('1210');
    ok((await page.textContent('[data-t-total]')).includes('1.210,00'), 'en la B el total es el precio con IVA');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
    ok((await page.textContent('.totales-rev')).includes('IVA contenido: $ 210,00'), 'IVA contenido de la B');
    // la forma de pago se pide al terminar, con el total a la vista
    ok((await page.textContent('.total-cobrar')).includes('1.210,00'), 'revisión: muestra el total a cobrar');
    await page.check('input[name=forma_pago][value="1"]');
    ok(await page.isVisible('[data-fpago]'), 'contado: elige con qué paga');
    ok(await page.isVisible('[data-recibido]'), 'efectivo: pide lo recibido');
    await page.fill('[data-recibido]', '1.500');
    const aviso = await page.textContent('[data-vuelto-aviso]');
    ok(aviso.includes('DAR VUELTO AL CLIENTE') && aviso.includes('290,00'), 'avisa el vuelto (1.500 − 1.210 = 290)');
    await page.screenshot({ path: path.join(CAPTURAS, 'pago-vuelto.png'), fullPage: true });
    await page.selectOption('[data-fpago]', { label: 'BILLETERA VIRTUAL' });
    ok(!(await page.isVisible('[data-recibido]')), 'billetera virtual: no hay vuelto');
    await page.selectOption('[data-fpago]', { label: 'EFECTIVO' });
    await page.fill('[data-recibido]', '1.000');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    ok((await page.textContent('body')).includes('entregó menos'), 'si entrega menos del total, no emite y lo dice');
    await page.fill('[data-recibido]', '1.500');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    ok(/Factura B 0004-\d{8}/.test(await page.textContent('h1')), 'factura B emitida');
    ok((await page.textContent('body')).includes('DAR VUELTO AL CLIENTE: $ 290,00'), 'al emitir recuerda dar el vuelto');
    ok((await page.textContent('body')).includes('Contado'), 'queda como contado');
    // listado
    await page.goto(BASE + '/comprobantes?cliente=E2E');
    ok((await page.locator('tbody tr').count()) === 3, 'el listado muestra las tres (A, NC y B)');
    await page.screenshot({ path: path.join(CAPTURAS, 'comprobantes-lista.png'), fullPage: true });
    // stock: ingreso con bultos y consulta de saldos
    await page.goto(BASE + '/stock/ajuste');
    await page.fill('input[name=articulo]', 'articulo e2');   // se busca por texto: aparecen las coincidencias y se elige una
    await page.waitForSelector('.sugerencias li[role=option]');
    ok((await page.textContent('.sugerencias')).includes('E2E-001'), 'stock: el buscador muestra las coincidencias debajo del campo');
    await page.click('.sugerencias li[role=option]');
    ok((await page.inputValue('input[name=articulo]')) === 'E2E-001', 'stock: al elegir queda el código del artículo');
    await page.fill('input[name=cantidad]', '15');
    await page.fill('input[name=motivo]', 'stock inicial e2e');
    await enviar(page, 'form.card button[type=submit]');
    ok(!(await page.locator('.alerta-error').count()) && page.url().includes('/stock/articulo/'), 'el ingreso de stock se registra' + ((await page.locator('.alerta-error').count()) ? ': ' + await page.textContent('.alerta-error') : ''));
    await page.goto(BASE + '/stock?q=e2e');
    ok((await page.content()).includes('E2E-001'), 'saldos de stock: aparece el artículo con saldo');
    await page.screenshot({ path: path.join(CAPTURAS, 'stock-saldos.png'), fullPage: true });
    // el stock se ve en los buscadores de los comprobantes: una columna por depósito
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_stock.php'), 'on']);
    await page.goto(BASE + '/comprobantes/nuevo?tipo=FC');
    await page.fill('#cliente-q', 'CLIENTE E2E FINAL');
    await page.waitForSelector('[data-cliente-lista] li');
    await page.click('[data-cliente-lista] li');
    await page.fill('[data-renglon] [data-articulo]', 'articulo e2');
    await page.waitForSelector('#articulos-sugerencias li .stock-art');
    const sugStock = await page.textContent('#articulos-sugerencias');
    ok(sugStock.includes('Stock') && sugStock.includes('E2E SEGUNDO'), 'comprobante: el buscador muestra el stock de cada depósito');
    await page.screenshot({ path: path.join(CAPTURAS, 'buscador-stock.png') });
    await page.click('[data-lista-articulos]');
    await page.waitForSelector('dialog.ventana-lista td');
    const cab = await page.textContent('dialog.ventana-lista thead');
    ok(cab.includes('E2E SEGUNDO') && cab.includes('Total'), 'ventana «ver lista»: una columna de stock por depósito y el total');
    await page.screenshot({ path: path.join(CAPTURAS, 'lista-articulos-stock.png') });
    await page.keyboard.press('Escape');

    // ---- «Presupuestos sin IVA» (Sistema → Configuración): con la marca el presupuesto no calcula IVA sobre el valor de los productos
    await page.goto(BASE + '/configuracion');
    const marca = page.locator('input[type=checkbox][name="valor[presupuestos.sin_iva]"]');
    ok((await marca.count()) === 1, 'presupuestos sin IVA: la marca está en Configuración');
    await marca.check();
    await Promise.all([page.waitForNavigation(), page.click('form button[type=submit]:has-text("Guardar")')]);
    await page.goto(BASE + '/comprobantes/nuevo?tipo=PR');
    await page.fill('#cliente-q', 'CLIENTE E2E FINAL');
    await page.waitForSelector('[data-cliente-lista] li');
    await page.click('[data-cliente-lista] li');
    const rs = page.locator('[data-renglon]').nth(0);
    await rs.locator('[data-descripcion]').fill('Producto sin IVA');
    await rs.locator('[data-cantidad]').fill('2');
    await rs.locator('[data-precio]').fill('121');
    ok((await page.textContent('[data-leyenda-precio]')).includes('sin IVA'), 'presupuestos sin IVA: la leyenda lo dice');
    ok((await page.textContent('[data-t-total]')).includes('242,00') && (await page.locator('[data-t-iva-filas] div').count()) === 0, 'presupuestos sin IVA: el total es 2 x 121, sin IVA');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
    await page.check('input[name=forma_pago][value="2"]');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    const verSin = await page.textContent('body');
    ok(verSin.includes('sin IVA (no calcula IVA') && verSin.includes('$ 242,00'), 'presupuestos sin IVA: el presupuesto sale sin calcular IVA');
    await page.goto(BASE + '/configuracion');
    await page.locator('input[type=checkbox][name="valor[presupuestos.sin_iva]"]').uncheck();
    await Promise.all([page.waitForNavigation(), page.click('form button[type=submit]:has-text("Guardar")')]);

    // ---- ticket de cambio: presupuesto de contado, se lee el ticket y se cambia un producto cobrando solo la diferencia
    await page.goto(BASE + '/comprobantes/nuevo?tipo=PR');
    await page.fill('#cliente-q', 'CLIENTE E2E FINAL');
    await page.waitForSelector('[data-cliente-lista] li');
    await page.click('[data-cliente-lista] li');
    await page.fill('[data-renglon] [data-articulo]', 'E2E-001');
    await page.waitForSelector('#articulos-sugerencias li');
    await page.click('#articulos-sugerencias li');
    await page.fill('[data-renglon] [data-cantidad]', '2');
    await page.fill('[data-renglon] [data-precio]', '100');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
    // varias formas de pago: 120 en efectivo + 80 con billetera virtual
    await page.check('input[name=forma_pago][value="1"]');
    await page.fill('[data-importe-pago]', '120');
    await page.click('[data-agregar-pago]');
    ok((await page.locator('[data-linea]').count()) === 2, 'varias formas de pago: se agrega otra');
    await page.locator('[data-linea]').nth(1).locator('[data-fpago]').selectOption({ label: 'BILLETERA VIRTUAL' });
    ok((await page.textContent('[data-resumen-pago]')).includes('faltan'), 'varias formas de pago: avisa lo que falta');
    await page.click('[data-completar-pago]');
    ok((await page.textContent('[data-resumen-pago]')).includes('cuadra'), 'varias formas de pago: «Completar» reparte lo que falta y cuadra');
    await page.screenshot({ path: path.join(CAPTURAS, 'pago-varias-formas.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    const mixto = await page.textContent('body');
    ok(mixto.includes('Mixto: EFECTIVO $ 120,00') && mixto.includes('BILLETERA VIRTUAL $ 80,00'), 'varias formas de pago: el comprobante queda como mixto');
    const nroPr = (await page.textContent('h1')).match(/(\d{4}-\d{8})/)[1];
    ok((await page.textContent('body')).includes('Ticket de cambio'), 'ticket de cambio: el presupuesto ofrece el ticket');
    const ticket = await ctx.request.get(page.url().replace(/\/$/, '').replace(/(\/comprobantes\/)(\d+)$/, '/cambios/ticket/$2'));
    ok(ticket.status() === 200 && (ticket.headers()['content-type'] || '').includes('pdf'), 'ticket de cambio: sale en PDF');
    await page.goto(BASE + '/cambios');
    await page.fill('[data-ticket]', 'PR-X-' + nroPr);
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Buscar")')]);
    ok((await page.textContent('tbody')).includes('Presupuesto X'), 'ticket de cambio: se lee el código y aparece el presupuesto');
    await Promise.all([page.waitForNavigation(), page.click('a:has-text("Hacer el cambio")')]);
    await page.locator('[data-devolver]').first().fill('1');
    await page.fill('[data-nuevo] [data-art]', 'E2E-ABONO');
    await page.waitForSelector('.sugerencias:not([hidden]) li');
    await page.click('.sugerencias:not([hidden]) li');
    const dif = await page.textContent('[data-t-dif]');
    ok(dif.includes('900,00'), 'cambio: la diferencia es lo que lleva (1.000) menos lo que devuelve (100)');
    ok(await page.isVisible('[data-pago]'), 'cambio: con diferencia pide cómo se paga');
    await page.check('input[name=forma_pago][value="1"]');
    await page.fill('[data-recibido]', '1.000');
    ok((await page.textContent('[data-vuelto-aviso]')).includes('100,00'), 'cambio: avisa el vuelto');
    await page.screenshot({ path: path.join(CAPTURAS, 'cambio.png'), fullPage: true });
    page.once('dialog', (d) => d.accept()); // confirma el cambio
    await Promise.all([page.waitForNavigation(), page.click('[data-hacer]')]);
    const cp = await page.textContent('body');
    ok(/Cambio X 0004-\d{8}/.test(cp) && cp.includes('DAR VUELTO AL CLIENTE: $ 100,00') && cp.includes('DEVUELVE'), 'cambio: se hace, cobra solo la diferencia y recuerda el vuelto');
    await page.goto(BASE + '/stock?q=e2e-001');
    ok((await page.content()).includes('E2E-001'), 'cambio: el stock refleja el cambio');

    // ---- modo Kiosco: tarjetas con stock, carrito siempre visible y cobro como presupuesto
    await page.goto(BASE + '/kiosco');
    await page.waitForSelector('.k-tarjeta');
    const tar = page.locator('.k-tarjeta', { hasText: 'E2E-001' });
    ok((await tar.textContent()).includes('Stock'), 'kiosco: cada tarjeta muestra su stock');
    await tar.click();
    await tar.click();
    ok((await page.inputValue('.k-linea .k-cant')) === '2', 'kiosco: tocar la tarjeta suma de a uno en el carrito');
    await page.click('.k-linea button[aria-label="Una más"]');
    ok((await page.textContent('[data-total]')).includes('3.703,50'), 'kiosco: el total se actualiza al instante (3 × 1.234,50)');
    await page.locator('.k-tarjeta', { hasText: 'E2E-ABONO' }).click();
    ok((await page.locator('.k-linea').count()) === 2, 'kiosco: el carrito muestra los dos productos');
    await page.click('.k-linea:nth-child(2) button[aria-label^="Sacar"]');
    ok((await page.locator('.k-linea').count()) === 1, 'kiosco: se saca un producto del carrito');
    await page.reload();
    await page.waitForSelector('.k-linea');
    ok((await page.inputValue('.k-linea .k-cant')) === '3', 'kiosco: el carrito se conserva al recargar');
    await page.setViewportSize({ width: 390, height: 844 });
    ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'kiosco: en el celular no se desborda');
    await page.screenshot({ path: path.join(CAPTURAS, 'kiosco-celular.png') });
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.screenshot({ path: path.join(CAPTURAS, 'kiosco.png') });
    await Promise.all([page.waitForNavigation(), page.click('[data-cobrar]')]);
    const revK = await page.textContent('body');
    ok(revK.includes('Cómo se paga') && revK.includes('Volver al kiosco') && revK.includes('Presupuesto X'), 'kiosco: al cobrar arma un presupuesto y pide cómo se paga');
    await page.check('input[name=forma_pago][value="1"]');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    ok(page.url().includes('/kiosco?hecho='), 'kiosco: al emitir vuelve al kiosco');
    ok((await page.textContent('body')).includes('Venta registrada') && (await page.locator('.k-linea').count()) === 0, 'kiosco: avisa la venta hecha y el carrito queda vacío');
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_stock.php'), 'off']);
    // grillas: orden por columna (servidor con páginas, navegador sin páginas), Excel y alto que entra en la pantalla
    await page.goto(BASE + '/comprobantes?cliente=E2E');
    await Promise.all([page.waitForNavigation(), page.click('th a.th-orden:has-text("Total")')]);
    ok(page.url().includes('orden=total'), 'grillas: el título ordena (en el servidor)');
    const xls = await ctx.request.get(await page.locator('.grilla-barra a').first().evaluate(a => a.href));
    ok(xls.status() === 200 && (xls.headers()['content-type'] || '').includes('spreadsheetml'), 'grillas: el botón Excel baja la grilla');
    ok(await page.evaluate(() => { const w = document.querySelector('.table-wrap.grilla'); return w && w.style.maxHeight !== '' && getComputedStyle(w).overflowX === 'auto'; }),
      'grillas: la grilla entra en la pantalla y se desplaza adentro');
    await page.goto(BASE + '/usuarios');
    const ths = page.locator('th.th-ordenable');
    ok((await ths.count()) > 0, 'grillas sin páginas: títulos ordenables en el navegador');
    await ths.first().click();
    ok((await ths.first().getAttribute('aria-sort')) === 'ascending', 'grillas sin páginas: ordena al tocar el título');
    await page.screenshot({ path: path.join(CAPTURAS, 'grilla-usuarios.png') });
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
    const libro = await ctx.request.get(BASE + '/listados/iva-ventas/pdf?pruebas=1&folio=5');
    ok(libro.status() === 200 && (libro.headers()['content-type'] || '').includes('pdf'), 'subdiario: el PDF para el libro se genera');
    ok((await page.locator('form.form-libro button:has-text("Imprimir para el libro")').count()) === 1, 'subdiario: botón Imprimir para el libro con hoja inicial');
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
    // portal de clientes: el cliente entra con el link del mail y baja su factura (escritorio y celular)
    const token = execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_portal.php')]).toString().trim();
    const cli = await browser.newContext({ ...devices['Pixel 7'] });
    const pc = await cli.newPage();
    await pc.goto(BASE + '/portal');
    ok((await pc.textContent('h1')).includes('Mis comprobantes'), 'portal: pantalla de ingreso por mail');
    await pc.screenshot({ path: path.join(CAPTURAS, 'portal-ingreso.png'), fullPage: true });
    await pc.goto(BASE + '/portal/acceso/' + token);
    ok(pc.url().endsWith('/portal/comprobantes'), 'portal: el link del mail hace entrar');
    ok((await pc.textContent('h1')).includes('CLIENTE E2E SRL'), 'portal: ve sus datos');
    ok((await pc.locator('.portal-lista li').count()) >= 1, 'portal: lista sus comprobantes');
    const descarga = await cli.request.get(await pc.locator('.portal-lista a[download]').first().evaluate(a => a.href));
    ok(descarga.status() === 200 && (descarga.headers()['content-type'] || '').includes('pdf'), 'portal: descarga el PDF');
    ok(await pc.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'portal: celular sin scroll horizontal');
    await pc.screenshot({ path: path.join(CAPTURAS, 'portal-comprobantes.png'), fullPage: true });
    await pc.goto(BASE + '/portal/acceso/' + token);
    ok((await pc.textContent('body')).includes('ya se usó'), 'portal: el link no sirve dos veces');
    await cli.close();
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
