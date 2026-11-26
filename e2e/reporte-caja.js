// Recorrido de navegador: reporte de caja para imprimir (Listados → Caja diaria → «Imprimir reporte detallado», D-152).
//   node e2e/reporte-caja.js   levanta su propio servidor en el puerto 8087
//   E2E_CAPTURAS=<carpeta> guarda capturas (escritorio y celular).
// El botón abre la hoja aparte con el mismo período; el efectivo que tiene que haber en el cajón es el mismo que muestra la caja diaria;
// las salidas de dinero aparecen con su motivo; en el celular la hoja no se corre de costado.
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');

const PORT = process.env.E2E_REPORTE_PORT || '8087';
const BASE = 'http://127.0.0.1:' + PORT;
const ROOT = path.resolve(__dirname, '..');
const CLAVE = 'E2e-Prueba-2026';
const CAPTURAS = process.env.E2E_CAPTURAS || '';
const MOTIVO = 'E2E reporte: pago al flete';
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
  php("$id = (int) $pdo->query(\"SELECT id FROM usuarios WHERE usuario = 'e2e_admin'\")->fetchColumn(); $pdo->prepare(\"INSERT INTO caja_movimientos (creado_en, usuario_id, tipo, importe, motivo) VALUES (NOW(), ?, 'E', 1234.5, ?)\")->execute([$id, $argv[1]]);", MOTIVO);
  const browser = await chromium.launch();
  const errores = [];
  try {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => errores.push(e.message));
    await login(page, 'e2e_admin');
    await page.goto(BASE + '/listados/caja-diaria');
    const enCaja = (await page.locator('section[aria-label="Efectivo en caja"] div').last().locator('b').innerText()).trim();
    const [hoja] = await Promise.all([ctx.waitForEvent('page'), page.click('[data-reporte-imprimir]')]);
    await hoja.waitForLoadState();
    hoja.on('pageerror', e => errores.push(e.message));
    ok(await hoja.locator('[data-reporte-caja]').count() === 1, 'el botón abre el reporte en una hoja aparte');
    ok((await hoja.title()).startsWith('Reporte de caja del '), 'el título dice el período');
    ok(await hoja.locator('header.topbar').count() === 0, 'la hoja no tiene el menú del sistema');
    ok((await hoja.locator('[data-en-cajon]').innerText()).trim() === enCaja, 'lo que tiene que haber en el cajón coincide con la caja diaria (' + enCaja + ')');
    const titulos = await hoja.locator('h2').allInnerTexts();
    for (const t of ['Resumen', '¿Qué se vendió?', '¿Cómo se cobró y a dónde fue cada peso?', 'El efectivo, paso a paso', 'Lo que entró y salió del cajón', 'Lo que quedó debiendo', 'Detalle de cada cobro']) {
      ok(titulos.some(x => x.includes(t)), 'tiene la sección «' + t + '»');
    }
    ok(await hoja.locator('[data-salidas]', { hasText: MOTIVO }).count() === 1, 'la salida de dinero aparece con su motivo');
    ok(await hoja.locator('.acciones button', { hasText: 'Imprimir' }).isVisible(), 'tiene el botón Imprimir');
    await hoja.emulateMedia({ media: 'print' });
    ok(!(await hoja.locator('.acciones').isVisible()), 'al imprimir no salen los botones');
    await hoja.emulateMedia({ media: 'screen' });
    if (CAPTURAS) await hoja.screenshot({ path: path.join(CAPTURAS, '1-reporte.png'), fullPage: true });

    const cel = await browser.newContext({ ...devices['iPhone 13'] });
    const pc = await cel.newPage();
    pc.on('pageerror', e => errores.push(e.message));
    await login(pc, 'e2e_admin');
    await pc.goto(BASE + '/listados/caja-diaria/imprimir');
    ok(await pc.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1 && [...document.querySelectorAll('table')].every(t => t.closest('.desliza') || t.getBoundingClientRect().right <= window.innerWidth + 1)), 'celular: la hoja no se corre de costado');
    if (CAPTURAS) await pc.screenshot({ path: path.join(CAPTURAS, '2-celular.png'), fullPage: true });

    const op = await (await browser.newContext()).newPage();
    await login(op, 'e2e_operador');
    const r = await op.goto(BASE + '/listados/caja-diaria/imprimir');
    ok(r.status() === 403, 'el operador no puede ver el reporte');
    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
  } finally {
    await browser.close();
    php("$pdo->prepare('DELETE FROM caja_movimientos WHERE motivo = ?')->execute([$argv[1]]);", MOTIVO);
    try { process.kill(-server.pid); } catch (e) {}
  }
  console.log(fallas ? `\n${fallas} fallas en el reporte de caja.` : '\nReporte de caja sin fallas.');
  process.exit(fallas ? 1 : 0);
})();
