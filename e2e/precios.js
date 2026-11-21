// Recorrido de navegador: precios con margen sobre el costo (márgenes en Parámetros y rubro, ficha con precio automático,
// ingreso de stock con costo que pregunta por los precios y «Actualizar precios»).
//   node e2e/precios.js   levanta su propio servidor en el puerto 8085
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const PORT = process.env.E2E_PRECIOS_PORT || '8085';
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

(async () => {
  const env = { ...process.env, PORT };
  const server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true, env });
  for (let i = 0; i < 40; i++) { try { if ((await fetch(BASE + '/salud')).ok) break; } catch (e) {} await new Promise(r => setTimeout(r, 250)); }
  execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), CLAVE], { stdio: 'inherit' });
  const antes = JSON.parse(php('echo json_encode($pdo->query("SELECT iva, margen_l1, margen_l2, margen_l3, margen_oferta, precio_redondeo, precios_con_iva FROM parametros WHERE id = 1")->fetch(PDO::FETCH_ASSOC));'));
  sql("UPDATE parametros SET iva = 1, margen_l1 = 50, margen_l2 = 35, margen_l3 = NULL, margen_oferta = NULL, precio_redondeo = 10, precios_con_iva = 1 WHERE id = 1");
  sql("DELETE FROM rubros WHERE codigo = 'E2'");
  sql("INSERT INTO rubros (codigo, descripcion, margen_l2) VALUES ('E2', 'RUBRO E2E', 30)");
  fs.mkdirSync(CAPTURAS, { recursive: true });
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', e => errores.push(e.message));
    await login(page, 'e2e_admin');

    // ---- alta: nace con precio automático y los precios se calculan mientras se escribe el costo
    await page.goto(BASE + '/articulos/nuevo');
    ok(await page.isChecked('input[type=checkbox][name=precio_automatico]'), 'alta: nace con «Precio automático»');
    await page.fill('#f-codigo', 'E2E-PREC');
    await page.fill('#f-descripcion', 'ARTICULO PRECIOS E2E');
    await page.selectOption('#f-rubro', 'E2');
    await page.fill('#f-costo', '1000');
    await page.waitForFunction(() => document.querySelector('#f-precio_de_venta_1').value !== '');
    ok((await page.inputValue('#f-precio_de_venta_1')) === '1.820,00', 'ficha: lista 1 = 1000 × 1,5 × 1,21 = 1815 → redondeo a 1820');
    ok((await page.inputValue('#f-precio_de_venta_2')) === '1.580,00', 'ficha: lista 2 con el margen del rubro (1000 × 1,3 × 1,21 = 1573 → 1580)');
    ok(await page.$eval('#f-precio_de_venta_1', el => el.readOnly), 'ficha: el precio calculado no se escribe a mano');
    ok((await page.textContent('[data-precios-resumen]')).includes('30,00 % del rubro'), 'ficha: dice de dónde sale cada margen');
    await page.fill('#f-margen_l1', '20');
    ok((await page.inputValue('#f-precio_de_venta_1')) === '1.460,00', 'ficha: el margen propio del artículo manda (1452 → 1460)');
    await page.screenshot({ path: path.join(CAPTURAS, 'precios-ficha.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('form[data-precios] button[type=submit]')]);
    ok(uno("SELECT precio_de_venta_1 FROM articulos WHERE codigo = 'E2E-PREC'") === '1460.0000', 'ficha: el servidor graba el precio calculado');

    // ---- ingreso de stock con un costo nuevo: pregunta y muestra los precios nuevos
    await page.goto(BASE + '/stock/ajuste?articulo=E2E-PREC');
    await page.fill('input[name=cantidad]', '10');
    await page.fill('input[name=costo]', '1200');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]:has-text("Registrar")')]);
    ok((await page.textContent('.pregunta-precios')).includes('¿Actualizo también los precios'), 'stock: pregunta si actualiza los precios');
    ok((await page.textContent('.pregunta-precios')).includes('1.750,00'), 'stock: muestra el precio nuevo (1200 × 1,2 × 1,21 = 1742,4 → 1750)');
    await page.screenshot({ path: path.join(CAPTURAS, 'precios-stock.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('button[name=precios][value=precios]')]);
    ok(uno("SELECT CONCAT(costo, '|', precio_de_venta_1) FROM articulos WHERE codigo = 'E2E-PREC'") === '1200.0000|1750.0000', 'stock: grabó costo y precios');

    // ---- historial en la ficha
    await page.goto(BASE + '/articulos/ficha?codigo=E2E-PREC');
    ok((await page.textContent('#historial-precios')).includes('Ingreso de stock'), 'ficha: el historial muestra el cambio del ingreso de stock');

    // ---- actualizar precios en masa después de cambiar el margen general
    sql("UPDATE parametros SET margen_l2 = 35 WHERE id = 1");
    sql("UPDATE rubros SET margen_l2 = 40 WHERE codigo = 'E2'");
    await page.goto(BASE + '/articulos/precios?q=E2E-PREC');
    ok((await page.textContent('table')).includes('2.040,00'), 'actualizar: lista 2 con el 40 % nuevo del rubro (1200 × 1,4 × 1,21 = 2032,8 → 2040)');
    await page.screenshot({ path: path.join(CAPTURAS, 'precios-masivo.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('button[name=accion][value=si]')]);
    ok((await page.textContent('body')).includes('Se actualizó el precio de 1 artículo'), 'actualizar: avisa cuántos cambió');
    ok(uno("SELECT precio_de_venta_2 FROM articulos WHERE codigo = 'E2E-PREC'") === '2040.0000', 'actualizar: grabó la lista 2');
    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
    await ctx.close();

    // ---- celular
    const movil = await browser.newContext({ ...devices['Pixel 7'] });
    const p2 = await movil.newPage();
    await login(p2, 'e2e_admin');
    await p2.goto(BASE + '/articulos/ficha?codigo=E2E-PREC');
    ok(await sinDesborde(p2), 'celular: la ficha no se desborda');
    await p2.screenshot({ path: path.join(CAPTURAS, 'precios-celular.png'), fullPage: true });
    await p2.goto(BASE + '/articulos/precios?ver=todos&q=E2E');
    ok(await sinDesborde(p2), 'celular: «Actualizar precios» no se desborda');
    await movil.close();
  } finally {
    await browser.close();
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), '--limpiar'], { stdio: 'inherit' });
    const v = (x) => (x === null || x === undefined ? 'NULL' : String(Number(x)));
    sql('UPDATE parametros SET iva = ' + v(antes.iva) + ', margen_l1 = ' + v(antes.margen_l1) + ', margen_l2 = ' + v(antes.margen_l2) + ', margen_l3 = ' + v(antes.margen_l3)
      + ', margen_oferta = ' + v(antes.margen_oferta) + ', precio_redondeo = ' + v(antes.precio_redondeo) + ', precios_con_iva = ' + v(antes.precios_con_iva) + ' WHERE id = 1');
    sql("DELETE FROM rubros WHERE codigo = 'E2'");
    try { process.kill(-server.pid); } catch (e) {}
  }
  console.log(fallas === 0 ? '\nRecorrido de precios completo sin fallas.' : `\n${fallas} falla(s).`);
  process.exit(fallas === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
