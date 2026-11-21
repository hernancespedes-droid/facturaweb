// Recorrido de navegador: alta de clientes desde el CUIT con el padrón de ARCA SIMULADO (0.37.0).
//   node e2e/alta-cliente.js   levanta su propio servidor en el puerto 8082 con AFIP_MODO=simulado
// CUIT del simulador: 30-71234567-1 sociedad RI · 20-12345670-0 monotributo CABA · 20-00000000-1 no existe.
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const PORT = process.env.E2E_ALTA_PORT || '8082';
const BASE = 'http://127.0.0.1:' + PORT;
const ROOT = path.resolve(__dirname, '..');
const CAPTURAS = path.join(__dirname, 'capturas');
const CLAVE = 'E2e-Prueba-2026';
let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };
const sinDesborde = (p) => p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
const sql = (q) => execFileSync('php', ['-r', 'require "' + ROOT + '/src/bootstrap.php"; App\\App::boot(); App\\Core\\Db::pdo()->exec($argv[1]);', q]);

async function login(page, usuario) {
  await page.goto(BASE + '/login');
  await page.fill('input[name=usuario]', usuario);
  await page.fill('input[name=clave]', CLAVE);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}

(async () => {
  const env = { ...process.env, AFIP_MODO: 'simulado', PORT };
  const server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true, env });
  for (let i = 0; i < 40; i++) { try { if ((await fetch(BASE + '/salud')).ok) break; } catch (e) {} await new Promise(r => setTimeout(r, 250)); }
  execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), CLAVE], { stdio: 'inherit' });
  const kioscoAntes = String(execFileSync('php', ['-r', 'require "' + ROOT + '/src/bootstrap.php"; App\\App::boot(); echo (int) App\\Core\\Db::pdo()->query("SELECT kiosco FROM parametros WHERE id = 1")->fetchColumn();']));
  sql("UPDATE parametros SET empresa = 'EMPRESA DE PRUEBA E2E SA', cuit = 30707656480, iva = 1, kiosco = 1 WHERE id = 1");
  sql("DELETE FROM padron_consultas WHERE entorno = 'S'");
  fs.mkdirSync(CAPTURAS, { recursive: true });
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', e => errores.push(e.message));
    await login(page, 'e2e_operador');

    // ---- factura: se escribe el CUIT en el buscador, no está, y la lista ofrece darlo de alta
    await page.goto(BASE + '/comprobantes/nuevo?tipo=FC');
    await page.fill('#cliente-q', '30-71234567-1');
    await page.waitForSelector('[data-cliente-lista] [data-alta-item]');
    ok((await page.textContent('[data-cliente-lista]')).includes('No hay clientes'), 'factura: el buscador dice que no hay clientes con ese CUIT');
    ok((await page.textContent('[data-alta-item]')).includes('Dar de alta el CUIT 30-71234567-1'), 'factura: ofrece «Dar de alta el CUIT …»');
    await page.click('[data-alta-item]');
    await page.waitForSelector('dialog.alta-cliente[open] .ac-ficha:not([hidden])');
    const d = page.locator('dialog.alta-cliente');
    ok((await d.locator('[name=razon_social]').inputValue()) === 'EMPRESA SIMULADA 4567 SA', 'ventana: la razón social viene de ARCA');
    ok((await d.locator('[name=iva]').inputValue()) === '1', 'ventana: condición Inscripto');
    ok((await d.locator('[name=localidad]').inputValue()) === 'MORON' && (await d.locator('[name=provincia]').inputValue()) === '02', 'ventana: domicilio y provincia de la tabla');
    ok((await d.textContent('.ac-ficha')).includes('Le corresponde factura A'), 'ventana: dice qué letra le corresponde');
    ok((await d.textContent('.ac-ficha')).includes('461011'), 'ventana: muestra la actividad principal');
    ok(await page.evaluate(() => document.activeElement && document.activeElement.name === 'telefono'), 'ventana: el cursor queda en lo que falta (teléfono)');
    await d.locator('[name=razon_social]').fill('EMPRESA SIMULADA 4567 SA E2E');
    await d.locator('[name=telefono]').fill('11 5555-0000');
    await d.locator('[name=emailfc]').fill('compras@e2e.com');
    await page.screenshot({ path: path.join(CAPTURAS, 'alta-cliente-ventana.png') });
    await d.locator('[data-ac-crear]').click();
    await page.waitForSelector('dialog.alta-cliente[open]', { state: 'detached' });
    ok((await page.inputValue('#cliente-q')) === 'EMPRESA SIMULADA 4567 SA E2E', 'factura: el cliente nuevo queda elegido');
    ok((await page.inputValue('[data-cliente]')) !== '', 'factura: con su código');
    ok((await page.inputValue('[data-email]')) === 'compras@e2e.com', 'factura: el mail pasa al comprobante');
    ok((await page.textContent('body')).includes('Factura A') || (await page.textContent('[data-letra-leyenda], body')).includes(' A'), 'factura: queda la letra A');

    // ---- el mismo CUIT otra vez: ahora el buscador lo encuentra y la ventana ofrece usar el que ya existe
    await page.goto(BASE + '/comprobantes/nuevo?tipo=FC');
    await page.click('[data-nuevo-cliente]');
    await page.waitForSelector('dialog.alta-cliente[open]');
    await d.locator('[name=cuit]').fill('30712345671');
    await page.waitForSelector('dialog.alta-cliente .ac-existe:not([hidden])');
    ok((await d.textContent('.ac-existe')).includes('Ya hay un cliente con este CUIT'), 'ventana: avisa que el CUIT ya existe');
    ok((await d.textContent('.ac-ficha')).includes('guardados'), 'ventana: la segunda consulta sale de lo guardado (con «volver a consultar»)');
    await d.locator('[data-ac-usar]').first().click();
    ok((await page.inputValue('#cliente-q')) === 'EMPRESA SIMULADA 4567 SA E2E', 'ventana: «Usar este cliente» lo elige sin duplicarlo');

    // ---- CUIT mal escrito y CUIT que ARCA no tiene
    await page.click('[data-nuevo-cliente]');
    await d.locator('[name=cuit]').fill('30712345670');
    ok((await d.textContent('[data-ac-err=cuit]')).includes('no es válido'), 'ventana: avisa al instante un CUIT con el verificador mal');
    await d.locator('[name=cuit]').fill('20000000001');
    await page.waitForFunction(() => /no tiene registrado/.test(document.querySelector('[data-ac-estado]').textContent));
    ok(true, 'ventana: un CUIT que no existe en ARCA se explica y deja cargar a mano');
    await page.keyboard.press('Escape');

    // ---- presupuesto: sin CUIT (consumidor final) por el botón +
    await page.goto(BASE + '/comprobantes/nuevo?tipo=PR');
    await page.fill('#cliente-q', 'Juan Perez E2E');
    await page.waitForSelector('[data-alta-item]');
    await page.click('[data-alta-item]');
    await page.waitForSelector('dialog.alta-cliente[open]');
    ok((await d.locator('[name=razon_social]').inputValue()) === 'JUAN PEREZ E2E', 'presupuesto: lo que se buscó pasa como razón social');
    await d.locator('[data-ac-sincuit]').check();
    ok((await d.locator('[name=iva]').inputValue()) === '3', 'presupuesto: sin CUIT queda consumidor final');
    await d.locator('[data-ac-crear]').click();
    await page.waitForSelector('dialog.alta-cliente[open]', { state: 'detached' });
    ok((await page.inputValue('#cliente-q')) === 'JUAN PEREZ E2E', 'presupuesto: el consumidor final nuevo queda elegido');

    // ---- kiosco: alta desde «Cambiar» cliente
    await page.goto(BASE + '/kiosco');
    await page.click('[data-cambiar-cliente]');
    await page.fill('[data-cliente-q]', '20-12345670-0');
    await page.waitForSelector('[data-cliente-lista] [data-alta-item]');
    await page.click('[data-cliente-lista] [data-alta-item]');
    await page.waitForSelector('dialog.alta-cliente[open] .ac-ficha:not([hidden])');
    ok((await d.textContent('.ac-ficha')).includes('Monotributo'), 'kiosco: trae que es monotributista');
    ok((await d.locator('[name=provincia]').inputValue()) === '23', 'kiosco: CABA va a Capital Federal de la tabla');
    await d.locator('[name=razon_social]').fill('SIMULADO 5670 MARIA E2E');
    await d.locator('[data-ac-crear]').click();
    await page.waitForSelector('dialog.alta-cliente[open]', { state: 'detached' });
    ok((await page.textContent('.kiosco-cliente [data-cliente-nombre]')).includes('SIMULADO 5670 MARIA E2E'), 'kiosco: el cliente nuevo queda en el carrito');

    // ---- ficha del cliente: alta normal empieza por el CUIT y completa sola
    await page.goto(BASE + '/clientes/nuevo');
    ok(await page.evaluate(() => { const f = document.querySelector('[data-ficha-cliente] .field'); return !!f && !!f.querySelector('[name=cuit]'); }), 'ficha: el alta empieza por el CUIT');
    await page.fill('#f-cuit', '20-12345679-4');
    await page.waitForFunction(() => document.querySelector('#f-razon_social').value !== '');
    ok((await page.inputValue('#f-razon_social')) === 'SIMULADO 5679 JUAN', 'ficha: con el CUIT completo trae la razón social sola');
    ok((await page.inputValue('#f-iva')) === '1' && (await page.inputValue('#f-localidad')) === 'CORDOBA', 'ficha: condición y domicilio');
    await page.fill('#f-razon_social', 'SIMULADO 5679 JUAN E2E');
    await Promise.all([page.waitForNavigation(), page.click('form[data-ficha-cliente] button[type=submit]')]);
    ok((await page.textContent('body')).includes('Datos de ARCA'), 'ficha: muestra los datos de ARCA guardados');
    await page.screenshot({ path: path.join(CAPTURAS, 'alta-cliente-ficha.png'), fullPage: true });

    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
    await ctx.close();

    // ---- celular
    const movil = await browser.newContext({ ...devices['Pixel 7'] });
    const p2 = await movil.newPage();
    await login(p2, 'e2e_operador');
    await p2.goto(BASE + '/comprobantes/nuevo?tipo=FC');
    await p2.click('[data-nuevo-cliente]');
    await p2.fill('dialog.alta-cliente [name=cuit]', '20-12345670-0');
    await p2.waitForSelector('dialog.alta-cliente[open] .ac-ficha:not([hidden])');
    ok(await p2.evaluate(() => { const d = document.querySelector('dialog.alta-cliente'); return d.scrollWidth <= d.clientWidth + 1; }), 'celular: la ventana no se desborda');
    ok(await sinDesborde(p2), 'celular: la pantalla no se desborda');
    await p2.screenshot({ path: path.join(CAPTURAS, 'alta-cliente-celular.png') });
    await movil.close();
  } finally {
    await browser.close();
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), '--limpiar'], { stdio: 'inherit' });
    sql('UPDATE parametros SET kiosco = ' + (kioscoAntes === '1' ? 1 : 0) + ' WHERE id = 1');
    try { process.kill(-server.pid); } catch (e) {}
  }
  console.log(fallas === 0 ? '\nRecorrido de alta de clientes completo sin fallas.' : `\n${fallas} falla(s).`);
  process.exit(fallas === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
