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
    server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true, env: { ...process.env, MP_MODO: 'simulado' } });
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
    ok((await page.textContent('h1')).length > 0 && page.url().replace(/\/$/, '') === BASE, 'entra al inicio');
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
    // calculadora de vuelto (D-129): cifras grandes, montos redondos y billetes para sumar
    ok((await page.textContent('[data-v-resultado]')).includes('290,00') && (await page.textContent('[data-v-etiqueta]')).includes('vuelto'), 'calculadora: muestra el vuelto en grande');
    ok((await page.textContent('[data-v-desglose]')).includes('1 de $ 200'), 'calculadora: sugiere con qué billetes darlo');
    await page.click('[data-v-borrar]');
    await page.click('[data-v-sumar="1000"]');
    await page.click('[data-v-sumar="1000"]');
    ok((await page.inputValue('[data-recibido]')) === '2.000,00' && (await page.textContent('[data-v-resultado]')).includes('790,00'), 'calculadora: tocar billetes suma lo recibido (2 × 1.000 → vuelto 790)');
    await page.click('[data-v-monto="1500"]');
    ok((await page.inputValue('[data-recibido]')) === '1.500,00', 'calculadora: los montos redondos cargan lo recibido');
    await page.click('[data-v-justo]');
    ok((await page.textContent('[data-v-resultado]')).includes('Sin vuelto'), 'calculadora: «Justo» es sin vuelto');
    await page.fill('[data-recibido]', '1.500');
    await page.screenshot({ path: path.join(CAPTURAS, 'pago-vuelto.png'), fullPage: true });
    await page.selectOption('[data-fpago]', { label: 'BILLETERA VIRTUAL' });
    ok(!(await page.isVisible('[data-recibido]')), 'billetera virtual: no hay vuelto');
    await page.selectOption('[data-fpago]', { label: 'EFECTIVO' });
    await page.fill('[data-recibido]', '1.000');
    ok((await page.textContent('[data-v-etiqueta]')) === 'Faltan' && (await page.textContent('[data-v-resultado]')).includes('210,00'), 'calculadora: si entrega menos, dice cuánto falta');
    const urlRevisar = page.url();
    await page.click('button:has-text("Emitir")');
    await page.waitForTimeout(300);
    ok(page.url() === urlRevisar && (await page.isVisible('[data-recibido]')), 'si entrega menos del total, no emite');
    await page.press('[data-recibido]', 'Enter');
    await page.waitForTimeout(300);
    ok(page.url() === urlRevisar, 'Enter con plata de menos tampoco emite');
    await page.fill('[data-recibido]', '1.500');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    ok(/Factura B 0004-\d{8}/.test(await page.textContent('h1')), 'factura B emitida');
    ok((await page.textContent('body')).includes('DAR VUELTO AL CLIENTE: $ 290,00'), 'al emitir recuerda dar el vuelto');
    ok(await page.isVisible('[data-vuelto-final]') && (await page.textContent('.vuelto-final-monto')).includes('290,00'), 'al emitir, cartel grande con el vuelto');
    await page.screenshot({ path: path.join(CAPTURAS, 'vuelto-final.png') });
    await page.keyboard.press('Enter');
    ok(!(await page.isVisible('[data-vuelto-final]')), 'el cartel del vuelto se cierra con Enter');
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

    // ---- Apariencia (0.31.0): nombre y color del cliente en todo el sistema; O&S firma al pie
    await page.goto(BASE + '/configuracion#apariencia');
    await page.fill('#ap-nombre', 'Negocio E2E');
    await page.check('[data-ap-color-propio]');
    await page.locator('[data-ap-color]').evaluate((el) => { el.value = '#c2185b'; el.dispatchEvent(new Event('input')); });
    ok((await page.locator('[data-ap-muestra]').getAttribute('style') || '').includes('194, 24, 91'), 'apariencia: el botón de muestra toma el color');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Guardar apariencia")')]);
    ok((await page.textContent('.brand')).includes('Negocio E2E'), 'apariencia: el nombre del cliente va en la barra');
    const fondoBtn = await page.locator('main .btn').first().evaluate((el) => getComputedStyle(el).backgroundColor);
    ok(fondoBtn === 'rgb(194, 24, 91)', 'apariencia: los botones toman el color del cliente');
    ok((await page.textContent('footer.pie')).includes('Organización & Sistemas'), 'apariencia: O&S firma al pie');
    await page.screenshot({ path: path.join(CAPTURAS, 'apariencia.png') });
    await page.fill('#ap-nombre', '');
    await page.uncheck('[data-ap-color-propio]');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Guardar apariencia")')]);
    ok(!(await page.textContent('.brand')).includes('Negocio E2E'), 'apariencia: al vaciar el nombre se deja de mostrar');

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
    if (await page.isVisible('[data-vuelto-listo]')) await page.click('[data-vuelto-listo]');
    await page.goto(BASE + '/stock?q=e2e-001');
    ok((await page.content()).includes('E2E-001'), 'cambio: el stock refleja el cambio');

    // ---- foto del artículo con zoom: se sube en la ficha, se ve en el kiosco y se amplía con el visor
    const fotoPng = execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_foto.php')]).toString().trim();
    await page.goto(BASE + '/articulos/ficha?codigo=E2E-001');
    await page.setInputFiles('input[name=foto]', fotoPng);
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Subir foto")')]);
    ok(await page.isVisible('a[data-ampliar] img.foto-previa'), 'foto: la ficha muestra la foto subida');
    await page.click('a[data-ampliar]');
    await page.waitForSelector('dialog.visor[open] img');
    ok((await page.textContent('[data-zoom-nivel]')).includes('100'), 'foto: el visor abre en 100 %');
    await page.click('[data-zoom-mas]');
    ok(parseInt(await page.textContent('[data-zoom-nivel]'), 10) > 100, 'foto: el botón + hace zoom');
    await page.dispatchEvent('.visor-lienzo', 'dblclick');
    await page.screenshot({ path: path.join(CAPTURAS, 'foto-zoom.png') });
    await page.keyboard.press('Escape');
    ok(!(await page.isVisible('dialog.visor[open]')), 'foto: Esc cierra el visor');

    // ---- modo Kiosco: tarjetas con stock, carrito siempre visible y cobro como presupuesto
    await page.goto(BASE + '/kiosco');
    await page.waitForSelector('.k-tarjeta');
    const tar = page.locator('.k-tarjeta', { hasText: 'E2E-001' });
    ok((await tar.textContent()).includes('Stock'), 'kiosco: cada tarjeta muestra su stock');
    ok(await tar.locator('.k-foto').count() > 0, 'kiosco: la tarjeta muestra la foto del producto');
    await tar.locator('.k-zoom').click();
    await page.waitForSelector('dialog.visor[open] img');
    ok(await page.isVisible('dialog.visor[open] img') && (await page.locator('.k-linea').count()) === 0, 'kiosco: la lupa amplía la foto sin agregar al carrito');
    await page.keyboard.press('Escape');
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
    const yaContado = await page.isChecked('input[name=forma_pago][value="1"]');
    if (yaContado) ok(await page.evaluate(() => document.activeElement && document.activeElement.matches('[data-recibido]')), 'kiosco: el cursor arranca en «Recibido»');
    await page.check('input[name=forma_pago][value="1"]');
    await page.fill('[data-recibido]', '5.000');
    ok((await page.textContent('[data-v-resultado]')).includes('1.296,50'), 'kiosco: la calculadora muestra el vuelto (5.000 − 3.703,50)');
    await page.setViewportSize({ width: 390, height: 844 });
    ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'calculadora de vuelto: en el celular no se desborda');
    await page.locator('[data-vuelto-caja]').screenshot({ path: path.join(CAPTURAS, 'vuelto-celular.png') });
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.locator('[data-pago]').screenshot({ path: path.join(CAPTURAS, 'vuelto-kiosco.png') });
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Emitir")')]);
    ok(page.url().includes('/kiosco?hecho='), 'kiosco: al emitir vuelve al kiosco');
    ok((await page.textContent('.vuelto-final-monto')).includes('1.296,50'), 'kiosco: cartel grande con el vuelto');
    await page.keyboard.press('Enter');
    ok(!(await page.isVisible('[data-vuelto-final]')) && await page.evaluate(() => document.activeElement && document.activeElement.matches('[data-buscar]')), 'kiosco: al cerrar el cartel vuelve al buscador');
    ok((await page.textContent('body')).includes('Venta registrada') && (await page.locator('.k-linea').count()) === 0, 'kiosco: avisa la venta hecha y el carrito queda vacío');
    // 0.26.0: el administrador (permiso kiosco.precio) cambia el precio en el carrito; los favoritos se ordenan desde la ficha del artículo
    await page.locator('.k-tarjeta', { hasText: 'E2E-001' }).click();
    ok((await page.locator('.k-precio-ed').count()) === 1, 'kiosco: con el permiso del precio, la línea trae el precio editable');
    await page.fill('.k-precio-ed', '1.000');
    await page.press('.k-precio-ed', 'Tab');
    ok((await page.textContent('[data-total]')).includes('1.000,00'), 'kiosco: al cambiar el precio el total se actualiza');
    page.once('dialog', (d) => d.accept());
    await page.click('[data-vaciar]');
    await page.goto(BASE + '/articulos/ficha?codigo=E2E-001');
    ok((await page.textContent('body')).includes('Posición en el kiosco'), 'artículo: la ficha permite definir la posición en el kiosco');
    await page.goto(BASE + '/stock/ventas-sin-stock');
    ok((await page.textContent('h1')).includes('Ventas sin stock'), 'stock: informe de ventas sin stock');
    ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'stock: el informe de ventas sin stock no se desborda');
    // ---- impresión en ticket (80 y 50 mm) con el mismo comprobante
    await page.goto(BASE + '/comprobantes?cliente=E2E');
    const hrefCbte = await page.locator('table a[href*="/comprobantes/"]').first().getAttribute('href');
    const idCbte = hrefCbte.match(/comprobantes\/(\d+)/)[1];
    await page.goto(BASE + '/comprobantes/' + idCbte + '/imprimir?papel=t80');
    const tk = await page.textContent('body');
    ok(tk.includes('TOTAL') && tk.includes('Imprimir ticket') && tk.includes('Bajar PDF (A4)'), 'ticket 80 mm: muestra el comprobante y los botones');
    ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'ticket 80 mm: no se desborda');
    await page.screenshot({ path: path.join(CAPTURAS, 'ticket-80.png'), fullPage: true });
    await page.goto(BASE + '/comprobantes/' + idCbte + '/imprimir?papel=t50');
    ok((await page.content()).includes('size: 50mm auto'), 'ticket 50 mm: usa el ancho de 50 mm');
    const pdfT = await ctx.request.get(BASE + '/comprobantes/' + idCbte + '/pdf?papel=t80');
    ok(pdfT.status() === 200 && (pdfT.headers()['content-type'] || '').includes('pdf'), 'ticket: el PDF sigue generándose');
    // ---- Mercado Pago (simulado): la venta no se emite hasta que se acredita el pago
    await page.goto(BASE + '/comprobantes/nuevo?tipo=PR');
    await page.fill('#cliente-q', 'CLIENTE E2E FINAL');
    await page.waitForSelector('[data-cliente-lista] li');
    await page.click('[data-cliente-lista] li');
    await page.fill('[data-renglon] [data-articulo]', 'E2E-001');
    await page.waitForSelector('#articulos-sugerencias li');
    await page.click('#articulos-sugerencias li');
    await page.fill('[data-renglon] [data-cantidad]', '1');
    await page.fill('[data-renglon] [data-precio]', '100');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Revisar")')]);
    ok(await page.isVisible('button:has-text("Cobrar con Mercado Pago")'), 'mercado pago: la revisión ofrece cobrar con QR / link');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Cobrar con Mercado Pago")')]);
    const cobroUrl = page.url();
    const cuerpoMp = await page.textContent('body');
    ok(cobroUrl.includes('/mercadopago/cobro/') && cuerpoMp.includes('Pendiente de pago') && cuerpoMp.includes('todavía no se emitió'), 'mercado pago: el cobro queda pendiente, sin emitir');
    ok((await page.locator('.mp-qr svg').count()) === 1 && (await page.locator('[data-mp-link]').count()) === 1, 'mercado pago: muestra el QR y el link de pago');
    ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'mercado pago: el cobro no se desborda');
    await page.screenshot({ path: path.join(CAPTURAS, 'mercadopago-cobro.png'), fullPage: true });
    await page.goto(BASE + '/mercadopago');
    ok((await page.textContent('body')).includes('Pendiente de pago'), 'mercado pago: el listado muestra el cobro pendiente');
    await page.goto(cobroUrl);
    await Promise.all([page.waitForNavigation(), page.click('a:has-text("Ir al simulador de pago")')]);
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Simular pago aprobado")')]);
    ok((await page.textContent('body')).includes('Pago acreditado: la venta se emitió'), 'mercado pago: al acreditarse se emite sola');
    await Promise.all([page.waitForNavigation(), page.click('a:has-text("Seguir")')]);
    ok((await page.textContent('body')).includes('MERCADO PAGO'), 'mercado pago: el comprobante quedó con Mercado Pago como forma de pago');
    // ---- ingresos de dinero a la cuenta de Mercado Pago: grilla, campanita y cartel al entrar plata (de una venta o no)
    await page.goto(BASE + '/mercadopago/ingresos');
    let cuerpoIng = await page.textContent('body');
    ok(cuerpoIng.includes('Ingresos de Mercado Pago') && cuerpoIng.includes('Ver comprobante'), 'ingresos mp: la venta cobrada aparece como ingreso con su comprobante');
    ok(await page.isVisible('[data-mp-campana]'), 'ingresos mp: la campanita está en la barra');
    if (await page.locator('[data-mp-vistos]').count()) {
      await Promise.all([page.waitForNavigation(), page.click('[data-mp-vistos]')]);
    }
    ok(!(await page.isVisible('[data-mp-n]')), 'ingresos mp: marcados como vistos, la campanita queda sin número');
    await page.goto(BASE + '/');
    {
      const otra = await ctx.newPage(); // entra plata mientras la pantalla de inicio está abierta
      await otra.goto(BASE + '/mercadopago/ingresos');
      await otra.fill('input[name=monto]', '12.500');
      await otra.fill('input[name=pagador]', 'Juan Transferencia');
      await Promise.all([otra.waitForNavigation(), otra.click('button:has-text("Simular ingreso")')]);
      await otra.close();
    }
    await page.evaluate(() => window.MpIngresos.ahora());
    await page.waitForSelector('.mp-aviso', { timeout: 5000 });
    ok((await page.textContent('.mp-aviso')).includes('Ingresó $ 12.500,00 de Juan Transferencia'), 'ingresos mp: sale el cartel con el ingreso nuevo');
    ok((await page.textContent('[data-mp-n]')).trim() === '1', 'ingresos mp: la campanita cuenta el ingreso sin ver');
    await page.screenshot({ path: path.join(CAPTURAS, 'mp-ingresos-campanita.png') });
    await page.goto(BASE + '/mercadopago/ingresos');
    cuerpoIng = await page.textContent('body');
    ok(cuerpoIng.includes('12.500,00') && cuerpoIng.includes('Juan Transferencia') && cuerpoIng.includes('Sin venta asociada'), 'ingresos mp: el ingreso sin venta queda en la grilla');
    ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'ingresos mp: la grilla no se desborda');
    await page.screenshot({ path: path.join(CAPTURAS, 'mp-ingresos.png'), fullPage: true });

    // ---- caja: turno con arqueo, ingreso y egreso manual, anulación, auditoría con hora y usuario
    await page.goto(BASE + '/caja');
    if (await page.locator('button:has-text("Abrir turno")').count()) {
      await page.fill('input[name=fondo]', '500');
      await Promise.all([page.waitForNavigation(), page.click('button:has-text("Abrir turno")')]);
    }
    ok((await page.textContent('body')).includes('Cerrar mi turno'), 'caja: se abre el turno');
    await page.selectOption('[data-mover] select[name=tipo]', 'I');
    await page.fill('[data-mover] input[name=importe]', '1.000');
    await page.fill('[data-mover] input[name=motivo]', 'Cambio para el cajón E2E');
    await Promise.all([page.waitForNavigation(), page.click('[data-mover] button')]);
    
    ok((await page.textContent('body')).includes('Ingreso de dinero') && (await page.textContent('body')).includes('Cambio para el cajón E2E'), 'caja: el ingreso de dinero queda en los movimientos del turno con su concepto');
    await page.selectOption('[data-mover] select[name=tipo]', 'E');
    await page.fill('[data-mover] input[name=importe]', '10.000.000,01');
    await page.fill('[data-mover] input[name=motivo]', 'Supera el tope');
    await Promise.all([page.waitForNavigation(), page.click('[data-mover] button')]);
    ok((await page.textContent('body')).includes('supera el tope'), 'caja: el tope de 10 millones frena el movimiento');
    page.once('dialog', (d) => d.accept('Carga repetida E2E'));
    await Promise.all([page.waitForNavigation(), page.locator('form[data-anular] button').first().click()]);
    ok((await page.textContent('body')).includes('anulado con un contra-asiento'), 'caja: un movimiento se anula con contra-asiento');
    await page.screenshot({ path: path.join(CAPTURAS, 'caja.png'), fullPage: true });
    const esperado = (await page.textContent('[data-esperado]')).replace('$', '').trim();
    await page.fill('[data-cerrar] input[name=contado]', esperado);
    await Promise.all([page.waitForNavigation(), page.click('[data-cerrar] button')]);
    ok(page.url().includes('/caja/turnos') && (await page.textContent('body')).includes('El arqueo cierra justo'), 'caja: el arqueo cierra justo y el turno queda en el historial');
    await page.goto(BASE + '/caja/auditoria');
    const aud = await page.textContent('body');
    ok(aud.includes('Registro de acciones íntegro') && aud.includes('anulacion') && aud.includes('apertura'), 'caja: la auditoría muestra las acciones y la cadena íntegra');
    ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'caja: la auditoría no se desborda');
    await page.goto(BASE + '/listados/caja-diaria');
    ok((await page.locator('th a.th-orden:has-text("Hora")').count()) > 0 && (await page.locator('th a.th-orden:has-text("Usuario")').count()) > 0, 'caja diaria: muestra hora y usuario de cada cobro');
    await page.screenshot({ path: path.join(CAPTURAS, 'caja-diaria.png'), fullPage: true });
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

    // Listados 0.32.0: estadística (con ranking y detalle), rentabilidad, costos, movimientos de stock y existencias
    await page.goto(BASE + '/listados/estadistica?agrupar=cliente');
    ok((await page.textContent('h1')).includes('Estadística de ventas'), 'estadística: abre');
    ok((await page.textContent('table')).includes('CLIENTE E2E SRL'), 'estadística por cliente: aparece el cliente de las ventas');
    ok((await page.textContent('thead')).includes('Margen'), 'estadística: el administrador ve el margen');
    await page.fill('input[name=articulo]', 'articulo e2');
    await page.waitForSelector('.sugerencias li[role=option]');
    await page.click('.sugerencias li[role=option]');
    ok((await page.inputValue('input[name=articulo]')) === 'E2E-001', 'estadística: el filtro de artículo es el buscador (el usuario elige)');
    await page.selectOption('select[name=agrupar]', 'articulo');
    await page.check('input[name=detalle]');
    await Promise.all([page.waitForNavigation(), page.click('form.filtros button[type=submit]')]);
    ok(page.url().includes('articulo=E2E-001'), 'estadística: filtra por el artículo elegido');
    ok((await page.textContent('body')).includes('Detalle: cada renglón vendido'), 'estadística: muestra el detalle');
    await page.screenshot({ path: path.join(CAPTURAS, 'estadistica.png'), fullPage: true });
    await page.goto(BASE + '/listados/rentabilidad?agrupar=rubro');
    ok((await page.textContent('thead')).includes('% del margen'), 'rentabilidad: abre con el margen');
    await page.goto(BASE + '/listados/costos?q=E2E');
    ok((await page.textContent('table')).includes('E2E-001'), 'costos y márgenes: lista los artículos');
    await page.screenshot({ path: path.join(CAPTURAS, 'costos.png'), fullPage: true });
    await page.goto(BASE + '/stock/movimientos?articulo=E2E-001&desde=01/01/2000');
    ok((await page.textContent('.imputador-resumen')).includes('Saldo inicial'), 'movimientos de stock: con un artículo muestra el saldo');
    ok((await page.textContent('table')).includes('stock inicial e2e'), 'movimientos de stock: el ingreso con su motivo');
    await page.screenshot({ path: path.join(CAPTURAS, 'movimientos-stock.png'), fullPage: true });
    await page.goto(BASE + '/stock/existencias?q=E2E&valorizar=costo');
    ok((await page.textContent('table')).includes('E2E-001'), 'existencias por depósito: aparece el artículo');
    {
      const cel = await browser.newContext({ ...devices['Pixel 7'] });
      const pe = await cel.newPage();
      await login(pe, 'e2e_admin');
      for (const ruta of ['/listados/estadistica', '/stock/movimientos', '/stock/existencias', '/listados/costos', '/mercadopago/ingresos']) {
        await pe.goto(BASE + ruta);
        ok(await pe.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'celular: ' + ruta + ' sin scroll horizontal');
      }
      await pe.goto(BASE + '/listados/estadistica');
      await pe.screenshot({ path: path.join(CAPTURAS, 'celular-estadistica.png'), fullPage: true });
      await pe.goto(BASE + '/mercadopago/ingresos');
      await pe.screenshot({ path: path.join(CAPTURAS, 'celular-mp-ingresos.png'), fullPage: true });
      await cel.close();
    }

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

    // ---- Tablero de inicio (0.40.0): números, gráficos con valores al pasar el mouse, claro/oscuro y celular.
    await page.goto(BASE + '/?periodo=mes');
    ok((await page.textContent('h1')).includes('Cómo viene el negocio'), 'tablero: el administrador ve cómo viene el negocio');
    ok(await page.locator('.tb-hero-n').count() === 1, 'tablero: número principal de ventas');
    ok(await page.locator('[data-tb-grafico] svg.g-svg').count() === 2, 'tablero: los dos gráficos');
    await page.locator('[data-tb-grafico="columnas"] .g-zona').last().hover();
    ok(await page.locator('[data-tb-grafico="columnas"] .tb-tip').isVisible(), 'tablero: al pasar el mouse por una columna muestra el valor');
    await page.locator('[data-tb-grafico="lineas"] .g-zona').nth(2).hover();
    ok((await page.textContent('[data-tb-grafico="lineas"] .tb-tip')).includes('Día 3'), 'tablero: la línea muestra el día y los dos meses');
    ok(await page.locator('[data-tb-grafico="lineas"] .g-guia:not([hidden])').count() === 1, 'tablero: aparece la línea guía');
    await page.screenshot({ path: path.join(CAPTURAS, 'tablero.png'), fullPage: true });
    await page.click('.tb-periodos a:has-text("12 meses")');
    ok((await page.textContent('.tb-hero .tb-label')).includes('12 meses'), 'tablero: cambia el período');
    await page.evaluate(() => document.documentElement.setAttribute('data-tema', 'claro'));
    await page.screenshot({ path: path.join(CAPTURAS, 'tablero-claro.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(BASE + '/');
    ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'tablero: en el celular no se desborda');
    await page.screenshot({ path: path.join(CAPTURAS, 'tablero-celular.png'), fullPage: true });
    await page.setViewportSize({ width: 1280, height: 860 });
    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
    await ctx.close();

    // ---- Celular, Operador: no ve Sistema ni Sucursales.
    const movil = await browser.newContext({ ...devices['Pixel 7'] });
    const p2 = await movil.newPage();
    await login(p2, 'e2e_operador');
    const menu = await p2.content();
    ok(!menu.includes('/usuarios"') && !menu.includes('/tablas/sucursales"'), 'operador: el menú no muestra Usuarios ni Sucursales');
    ok(menu.includes('/comprobantes/nuevo?tipo=FC'), 'operador: el menú muestra Nueva factura');
    ok(!menu.includes('Cómo viene el negocio') && !menu.includes('Ticket promedio'), 'operador: el inicio no muestra importes de ventas');
    ok(await p2.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'celular: el inicio del operador no se desborda');
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
