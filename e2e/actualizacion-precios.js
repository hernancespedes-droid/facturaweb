// Recorrido de navegador: actualización de precios por filtro (0.60.0, D-144…D-146): filtros desde/hasta, porcentaje sobre una lista,
// vista previa con el automático que pasa a manual, confirmar, deshacer y la pantalla en el celular.
//   node e2e/actualizacion-precios.js   levanta su propio servidor en el puerto 8086
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const PORT = process.env.E2E_ACTUALIZACION_PORT || '8086';
const BASE = 'http://127.0.0.1:' + PORT;
const ROOT = path.resolve(__dirname, '..');
const CAPTURAS = path.join(__dirname, 'capturas');
const CLAVE = 'E2e-Prueba-2026';
let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };
const sinDesborde = (p) => p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
const php = (codigo) => String(execFileSync('php', ['-r', 'require "' + ROOT + '/src/bootstrap.php"; App\\App::boot(); $pdo = App\\Core\\Db::pdo(); ' + codigo]));
const sql = (q) => php('$pdo->exec(' + JSON.stringify(q).replace(/\$/g, '\\$') + ');');
const uno = (q) => php('echo $pdo->query(' + JSON.stringify(q).replace(/\$/g, '\\$') + ')->fetchColumn();');

async function login(page, usuario) {
  await page.goto(BASE + '/login');
  await page.fill('input[name=usuario]', usuario);
  await page.fill('input[name=clave]', CLAVE);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}

function limpiar() {
  sql("DELETE FROM precios_actualizaciones WHERE id IN (SELECT * FROM (SELECT DISTINCT actualizacion_id FROM articulos_precios_historial WHERE articulo LIKE 'E2A-%' AND actualizacion_id IS NOT NULL) x)");
  sql("DELETE FROM articulos_precios_historial WHERE articulo LIKE 'E2A-%'");
  sql("DELETE FROM articulos WHERE codigo LIKE 'E2A-%'");
}

(async () => {
  const env = { ...process.env, PORT };
  const server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true, env });
  for (let i = 0; i < 40; i++) { try { if ((await fetch(BASE + '/salud')).ok) break; } catch (e) {} await new Promise(r => setTimeout(r, 250)); }
  execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), CLAVE], { stdio: 'inherit' });
  const red = uno('SELECT COALESCE(precio_redondeo, 0) FROM parametros WHERE id = 1');
  sql('UPDATE parametros SET precio_redondeo = 0 WHERE id = 1');
  limpiar();
  sql("INSERT INTO articulos (codigo, descripcion, estado, moneda, alicuota, grabado, costo, precio_de_venta_1, precio_de_venta_2, margen_l1, precio_automatico) VALUES "
    + "('E2A-1', 'ARTICULO MANUAL E2E', 'AC', '$', '21', 1, 100, 1000, 2000, NULL, 0), ('E2A-2', 'ARTICULO AUTOMATICO E2E', 'AC', '$', '21', 1, 100, 145.2, 0, 20, 1)");
  fs.mkdirSync(CAPTURAS, { recursive: true });
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    page.on('dialog', d => d.accept());
    const errores = [];
    page.on('pageerror', e => errores.push(e.message));
    await login(page, 'e2e_admin');

    await page.goto(BASE + '/articulos/actualizacion');
    ok(await page.isHidden('[data-solo-costo]'), 'con «Una lista» no se muestra la nota del costo');
    // lupa de «Desde»: abre la lista y al elegir completa el código
    await page.click('.field:has(input[name=codigo_desde]) [data-lista-desde-hasta]');
    await page.waitForSelector('dialog.ventana-lista[open]');
    await page.fill('dialog.ventana-lista .va-buscar', 'E2A-1');
    await page.waitForSelector('dialog.ventana-lista tbody tr:has-text("E2A-1")');
    await page.click('dialog.ventana-lista tbody tr:has-text("E2A-1")');
    ok(await page.inputValue('input[name=codigo_desde]') === 'E2A-1', 'lupa: eligió el artículo en «Desde»');
    // buscador: al escribir parte de la descripción sugiere debajo
    await page.fill('input[name=codigo_hasta]', 'AUTOMATICO E2E');
    await page.waitForSelector('ul.sugerencias:not([hidden]) li:has-text("E2A-2")');
    ok(true, 'buscador: sugiere al escribir la descripción');
    await page.fill('input[name=codigo_hasta]', 'E2A-9');
    await page.keyboard.press('Escape');
    await page.check('input[name=forma][value=porcentaje]');
    await page.fill('input[name=valor]', '10');
    await Promise.all([page.waitForNavigation(), page.click('.actualizacion-form button[type=submit]')]);
    const prev = await page.textContent('#vista-previa');
    ok(prev.includes('1.100,00'), 'vista previa: 1000 + 10 % = 1.100,00');
    ok(prev.includes('159,72'), 'vista previa: el automático 145,20 → 159,72');
    ok(prev.includes('pasa a manual'), 'vista previa: avisa que el automático pasa a manual');
    ok(uno("SELECT precio_de_venta_1 FROM articulos WHERE codigo = 'E2A-1'") === '1000.0000', 'la vista previa no graba');
    await page.screenshot({ path: path.join(CAPTURAS, 'actualizacion-precios.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('[data-confirmar-actualizacion]')]);
    ok((await page.textContent('body')).includes('Se actualizaron 2 artículos'), 'confirmar: avisa cuántos cambió');
    ok(uno("SELECT CONCAT(precio_de_venta_1, '|', precio_de_venta_2) FROM articulos WHERE codigo = 'E2A-1'") === '1100.0000|2000.0000', 'confirmar: grabó solo la lista 1');
    ok(uno("SELECT precio_automatico FROM articulos WHERE codigo = 'E2A-2'") === '0', 'confirmar: el automático quedó manual');
    await Promise.all([page.waitForNavigation(), page.click('[data-deshacer]')]);
    ok((await page.textContent('body')).includes('Se deshizo la actualización'), 'deshacer: avisa');
    ok(uno("SELECT CONCAT(precio_de_venta_1, '|', precio_automatico) FROM articulos WHERE codigo = 'E2A-2'") === '145.2000|1', 'deshacer: volvió precio y automático');
    ok((await page.textContent('#registro')).includes('Deshecha'), 'registro: figura como deshecha');
    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
    await ctx.close();

    const movil = await browser.newContext({ ...devices['Pixel 7'] });
    const p2 = await movil.newPage();
    await login(p2, 'e2e_admin');
    await p2.goto(BASE + '/articulos/actualizacion?vista=1&enviado=1&codigo_desde=E2A-1&codigo_hasta=E2A-9&que=todo&forma=coeficiente&valor=1,05&redondear=1');
    ok(await sinDesborde(p2), 'celular: la actualización de precios no se desborda');
    await p2.screenshot({ path: path.join(CAPTURAS, 'actualizacion-precios-celular.png'), fullPage: true });
    await movil.close();
  } finally {
    await browser.close();
    limpiar();
    sql('UPDATE parametros SET precio_redondeo = ' + Number(red) + ' WHERE id = 1');
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), '--limpiar'], { stdio: 'inherit' });
    try { process.kill(-server.pid); } catch (e) {}
  }
  console.log(fallas === 0 ? '\nRecorrido de actualización de precios completo sin fallas.' : `\n${fallas} falla(s).`);
  process.exit(fallas === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
