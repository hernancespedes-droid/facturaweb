// Recorrido de navegador: facturación masiva (0.66.0, D-154…D-158).
//   node e2e/facturacion-masiva.js   levanta su propio servidor en el puerto 8088
//   E2E_CAPTURAS=<carpeta> guarda capturas (escritorio y celular).
// Sube una planilla (CSV) con tres facturas (una de dos renglones, una en efectivo y una con un cliente que no existe): quedan como pre-facturas sin tocar
// nada; se corrige la del cliente con «Modificar» (el mismo formulario de la factura), se elimina otra, y «Aprobar y facturar» factura de a una con el avance.
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const PORT = process.env.E2E_MASIVA_PORT || '8088';
const BASE = 'http://127.0.0.1:' + PORT;
const ROOT = path.resolve(__dirname, '..');
const CLAVE = 'E2e-Prueba-2026';
const CAPTURAS = process.env.E2E_CAPTURAS || '';
let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };
const php = (codigo, ...args) => String(execFileSync('php', ['-r', 'require "' + ROOT + '/src/bootstrap.php"; App\\App::boot(); $pdo = App\\Core\\Db::pdo(); ' + codigo, ...args]));

async function login(page, usuario) {
  await page.goto(BASE + '/login');
  await page.fill('input[name=usuario]', usuario);
  await page.fill('input[name=clave]', CLAVE);
  await Promise.all([page.waitForNavigation(), page.press('input[name=clave]', 'Enter')]);
}

(async () => {
  const env = { ...process.env, PORT };
  const server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true, env });
  for (let i = 0; i < 40; i++) { try { if ((await fetch(BASE + '/salud')).ok) break; } catch (e) {} await new Promise(r => setTimeout(r, 250)); }
  execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), CLAVE], { stdio: 'inherit' });
  // Datos: empresa, un punto de venta, un cliente inscripto y un consumidor final, y efectivo como forma de pago (sin tocar lo que ya haya)
  php(`
    $pdo->exec("UPDATE parametros SET empresa = IF(empresa = '' OR empresa IS NULL, 'EMPRESA DE PRUEBA E2E SA', empresa), cuit = COALESCE(NULLIF(cuit, 0), 30707656480), iva = COALESCE(NULLIF(iva, 0), 1) WHERE id = 1");
    if (!$pdo->query('SELECT COUNT(*) FROM sucursales WHERE prefijo > 0')->fetchColumn()) { $pdo->exec("INSERT INTO sucursales (codigo, descripcion, prefijo) VALUES (1, 'CASA CENTRAL', 1)"); }
    $pdo->exec("INSERT IGNORE INTO clientes (codigo, razon_social, domicilio, localidad, iva, cuit, forma_pago) VALUES (991001, 'E2E MASIVA INSCRIPTO SA', 'CALLE 1', 'CABA', 1, 30708805587, 2), (991002, 'E2E MASIVA CONSUMIDOR', 'CALLE 2', 'CABA', 3, NULL, 1)");
    $pdo->exec("INSERT IGNORE INTO forma_de_pagos (codigo, descripcion, tipo) VALUES ('EF', 'EFECTIVO', 1)");
    $pdo->exec("DELETE FROM prefacturas WHERE referencia LIKE 'E2E-M%'");
  `);
  const sucursales = Number(php("echo $pdo->query('SELECT COUNT(*) FROM sucursales WHERE prefijo > 0')->fetchColumn();"));
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'masiva-'));
  const csv = path.join(dir, 'facturas.csv');
  fs.writeFileSync(csv, [
    'Referencia;Cliente;Descripción;Cantidad;Precio;Forma de pago',
    'E2E-M1;991001;Abono mensual;1;1210;',
    'E2E-M1;;Flete;2;121;',
    'E2E-M2;991002;Venta mostrador;1;500;Efectivo',
    'E2E-M3;NO EXISTE E2E SA;Servicio técnico;1;3000;',
  ].join('\r\n'));

  const browser = await chromium.launch();
  const errores = [];
  try {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 860 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => errores.push(e.message));
    page.on('dialog', d => d.accept());
    await login(page, 'e2e_admin');
    await page.goto(BASE + '/facturacion-masiva');
    ok(await page.locator('h1', { hasText: 'Facturación masiva' }).count() === 1, 'la pantalla abre');
    const antes = Number(php("echo $pdo->query('SELECT COUNT(*) FROM subdiario')->fetchColumn();"));

    // 1) Subir la planilla
    if (!(await page.locator('#form-masiva-subir').isVisible())) await page.click('details.card > summary');
    await page.setInputFiles('#form-masiva-subir input[type=file]', csv);
    await Promise.all([page.waitForNavigation(), page.click('#form-masiva-subir button[type=submit]')]);
    ok((await page.textContent('.alerta')).includes('Se cargaron 3 pre-factura(s)'), 'la planilla arma 3 pre-facturas (la de dos renglones es una)');
    const fila = ref => page.locator('tr[data-pf]', { hasText: ref });
    ok(await fila('E2E-M3').evaluate(tr => tr.classList.contains('fila-error')) && (await fila('E2E-M3').textContent()).includes('NO EXISTE E2E SA'), 'la del cliente que no existe queda marcada para corregir');
    ok((await fila('E2E-M1').textContent()).includes('Lista para facturar') && (await fila('E2E-M1').textContent()).includes('1.452'), 'la de dos renglones queda lista, por $ 1.452');
    ok(Number(php("echo $pdo->query('SELECT COUNT(*) FROM subdiario')->fetchColumn();")) === antes, 'subir la planilla no factura nada');
    if (CAPTURAS) await page.screenshot({ path: path.join(CAPTURAS, '1-masiva-cargada.png'), fullPage: true });

    // 2) Modificar: elegir el cliente con el buscador de la factura
    await Promise.all([page.waitForNavigation(), fila('E2E-M3').locator('a', { hasText: 'Modificar' }).click()]);
    ok((await page.textContent('body')).includes('Para corregir'), 'el formulario muestra lo que hay que corregir');
    ok(await page.locator('[data-reserva]').count() === 0, 'modificar no reserva stock');
    await page.fill('#cliente-q', 'E2E MASIVA CONS');
    await page.locator('[data-cliente-lista] li', { hasText: 'E2E MASIVA CONSUMIDOR' }).first().click();
    ok((await page.textContent('[data-letra-titulo]')).trim() === 'B', 'el cliente elegido define la letra');
    if (CAPTURAS) await page.screenshot({ path: path.join(CAPTURAS, '2-modificar.png'), fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]:has-text("Guardar pre-factura")')]);
    ok((await fila('E2E-M3').textContent()).includes('Lista para facturar'), 'corregida queda lista para facturar');

    // 3) Eliminar una
    await fila('E2E-M2').locator('[data-masiva-check]').check();
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Eliminar las elegidas")')]);
    ok(await fila('E2E-M2').count() === 0, 'la eliminada sale de las pendientes');

    // 4) Elegir todas las listas y aprobar: se facturan de a una con el avance
    await page.locator('[data-masiva-todas]').check();
    const elegidas = Number(await page.textContent('[data-masiva-elegidas]'));
    ok(elegidas >= 2 && await fila('E2E-M1').locator('[data-masiva-check]').isChecked() && await fila('E2E-M3').locator('[data-masiva-check]').isChecked(), '«todas» elige las listas (' + elegidas + ')');
    await page.click('[data-masiva-procesar]');
    await page.waitForFunction(() => /^Listo/.test(document.querySelector('[data-masiva-avance-texto]').textContent), null, { timeout: 60000 });
    ok((await page.textContent('[data-masiva-avance-texto]')).includes(elegidas + ' facturada(s)'), 'el avance termina con todas facturadas');
    ok((await fila('E2E-M1').textContent()).includes('Facturada') && await fila('E2E-M1').locator('a[href*="/comprobantes/"]').count() === 1, 'cada fila muestra su factura');
    ok(Number(php("echo $pdo->query('SELECT COUNT(*) FROM subdiario')->fetchColumn();")) === antes + elegidas, 'se emitieron las facturas');
    ok(php("echo $pdo->query(\"SELECT COUNT(*) FROM ctact c JOIN prefacturas p ON p.subdiario_id = c.subdiario_id WHERE p.referencia = 'E2E-M1'\")->fetchColumn();").trim() === '1', 'la de cuenta corriente quedó en la cuenta del cliente');
    if (CAPTURAS) await page.screenshot({ path: path.join(CAPTURAS, '3-facturadas.png'), fullPage: true });
    await page.goto(BASE + '/facturacion-masiva?estado=emitidas');
    ok(await fila('E2E-M1').count() >= 1, 'el filtro «Facturadas» las muestra');

    // Celular
    const cel = await browser.newContext({ ...devices['iPhone 13'] });
    const pc = await cel.newPage();
    pc.on('pageerror', e => errores.push(e.message));
    await login(pc, 'e2e_admin');
    await pc.goto(BASE + '/facturacion-masiva?estado=todas');
    ok(await pc.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'celular: la pantalla no se corre de costado');
    if (CAPTURAS) await pc.screenshot({ path: path.join(CAPTURAS, '4-celular.png'), fullPage: true });

    const op = await (await browser.newContext()).newPage();
    await login(op, 'e2e_operador');
    ok((await op.goto(BASE + '/facturacion-masiva')).status() === 403, 'el operador no entra a la facturación masiva');
    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
    if (sucursales > 1) console.log('  (aviso: hay varias sucursales; se usó la primera)');
  } finally {
    await browser.close();
    fs.rmSync(dir, { recursive: true, force: true });
    try { process.kill(-server.pid); } catch (e) {}
  }
  console.log(fallas ? `\n${fallas} fallas en la facturación masiva.` : '\nFacturación masiva sin fallas.');
  process.exit(fallas ? 1 : 0);
})();
