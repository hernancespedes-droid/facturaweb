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
