// Recorrido de navegador: menú horizontal o lateral (0.52.0, D-136; Configuración → Apariencia → Menú).
//   node e2e/menu.js   levanta su propio servidor en el puerto 8086
//   E2E_CAPTURAS=<carpeta> guarda capturas de cada variante.
// El administrador elige «Lateral» y lo ve todo el mundo; en escritorio es una columna fija a la izquierda con grupos que se
// pliegan (y se recuerdan), en el celular sigue el botón ☰; vuelve a «Horizontal» con desplegables.
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');

const PORT = process.env.E2E_MENU_PORT || '8086';
const BASE = 'http://127.0.0.1:' + PORT;
const ROOT = path.resolve(__dirname, '..');
const CLAVE = 'E2e-Prueba-2026';
const CAPTURAS = process.env.E2E_CAPTURAS || '';
let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };
const php = (codigo, ...args) => String(execFileSync('php', ['-r', 'require "' + ROOT + '/src/bootstrap.php"; App\\App::boot(); $pdo = App\\Core\\Db::pdo(); ' + codigo, ...args]));
const sql = (q) => php('$pdo->exec($argv[1]);', q);
const foto = async (page, nombre) => { if (CAPTURAS) await page.screenshot({ path: path.join(CAPTURAS, nombre + '.png') }); };

async function login(page, usuario) {
  await page.goto(BASE + '/login');
  await page.fill('input[name=usuario]', usuario);
  await page.fill('input[name=clave]', CLAVE);
  await Promise.all([page.waitForNavigation(), page.press('input[name=clave]', 'Enter')]);
}

async function elegirMenu(page, valor) {
  await page.goto(BASE + '/configuracion#apariencia');
  await page.check('input[name=menu][value=' + valor + ']');
  await Promise.all([page.waitForNavigation(), page.click('form[action$="/configuracion/apariencia"] button[type=submit]')]);
}

(async () => {
  const env = { ...process.env, PORT };
  const server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true, env });
  for (let i = 0; i < 40; i++) { try { if ((await fetch(BASE + '/salud')).ok) break; } catch (e) {} await new Promise(r => setTimeout(r, 250)); }
  execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), CLAVE], { stdio: 'inherit' });
  const antes = php("echo (string) $pdo->query(\"SELECT COALESCE((SELECT valor FROM configuracion WHERE clave = 'apariencia.menu'), '')\")->fetchColumn();");
  const browser = await chromium.launch();
  const errores = [];
  try {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => errores.push(e.message));
    await login(page, 'e2e_admin');

    await elegirMenu(page, 'lateral');
    ok(await page.evaluate(() => document.body.classList.contains('menu-lateral')), 'escritorio: al guardar «Lateral» el menú pasa a la izquierda');
    await page.goto(BASE + '/');
    const caja = await page.locator('nav.menu').boundingBox();
    ok(caja && caja.x === 0 && caja.width < 260 && caja.height > 400, 'escritorio: el menú es una columna fija a la izquierda');
    const main = await page.locator('main').boundingBox();
    ok(main && main.x >= caja.width - 1, 'escritorio: el contenido empieza al lado del menú, no debajo');
    const grupo = page.locator('.menu-grupo').first();
    const nombreGrupo = await grupo.getAttribute('data-grupo');
    const plegadoAntes = await grupo.evaluate(g => g.classList.contains('plegado'));
    await grupo.locator('.menu-grupo-btn').click();
    ok((await grupo.evaluate(g => g.classList.contains('plegado'))) !== plegadoAntes, 'escritorio: el grupo «' + nombreGrupo + '» se pliega o despliega con su título');
    const ahora = await grupo.evaluate(g => g.classList.contains('plegado'));
    if (ahora) { await grupo.locator('.menu-grupo-btn').click(); }
    await page.reload();
    ok((await page.locator('.menu-grupo').first().evaluate(g => g.classList.contains('plegado'))) === false, 'escritorio: lo que se abrió queda abierto al recargar');
    ok((await page.locator('.menu-grupo-btn .menu-grupo-ico svg').count()) === (await page.locator('.menu-grupo').count()), 'escritorio: cada grupo tiene su ícono');
    ok((await page.locator('.menu-sub a svg.menu-ico').count()) === (await page.locator('.menu-sub a').count()), 'escritorio: cada opción tiene su ícono');
    const estilos = await page.evaluate(() => {
      const g = getComputedStyle(document.querySelector('.menu-grupo-btn'));
      const o = getComputedStyle(document.querySelector('.menu-grupo:not(.plegado) .menu-sub a'));
      return { gPeso: g.fontWeight, gMay: g.textTransform, oPeso: o.fontWeight, oMay: o.textTransform, gCol: g.color, oCol: o.color };
    });
    ok(estilos.gMay === 'none' && estilos.oMay === 'none', 'escritorio: grupos y opciones sin mayúsculas sostenidas');
    ok(Number(estilos.gPeso) > Number(estilos.oPeso) && estilos.gCol !== estilos.oCol, 'escritorio: el título del grupo se distingue de las opciones (peso y color)');
    await page.goto(BASE + '/clientes');
    ok(await page.locator('.menu-sub a.activo').isVisible(), 'escritorio: la opción de la pantalla actual queda resaltada y a la vista');
    await foto(page, '1-lateral-claro');
    await page.click('[data-tema-cambiar]');
    await page.waitForTimeout(150);
    await foto(page, '2-lateral-oscuro');
    await page.click('[data-tema-cambiar]');

    // otro usuario (operador) de la misma instalación también lo ve lateral
    const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 720 } });
    const p2 = await ctx2.newPage();
    await login(p2, 'e2e_operador');
    ok(await p2.evaluate(() => document.body.classList.contains('menu-lateral')), 'escritorio: el operador también ve el menú lateral');
    await ctx2.close();

    const cel = await browser.newContext({ ...devices['Pixel 7'] });
    const pc = await cel.newPage();
    pc.on('pageerror', e => errores.push(e.message));
    await login(pc, 'e2e_admin');
    ok(!(await pc.locator('nav.menu').isVisible()), 'celular: con «Lateral» el menú sigue escondido detrás de ☰');
    await pc.click('label.menu-btn');
    ok(await pc.locator('nav.menu').isVisible(), 'celular: ☰ lo abre');
    const mb = await pc.locator('main').boundingBox();
    ok(mb && mb.x < 5, 'celular: el contenido usa todo el ancho');
    ok(await pc.locator('.menu-grupo-btn .menu-grupo-ico svg').first().isVisible(), 'celular: los grupos muestran su ícono');
    await foto(pc, '3-celular');
    await cel.close();

    await elegirMenu(page, 'horizontal');
    ok(await page.evaluate(() => document.body.classList.contains('menu-horizontal')), 'escritorio: al volver a «Horizontal» el menú queda arriba');
    await page.goto(BASE + '/');
    const g = page.locator('.menu-grupo').first();
    await g.locator('.menu-grupo-btn').click();
    ok(await g.locator('.menu-sub').isVisible(), 'escritorio: el agrupador horizontal despliega sus opciones');
    await foto(page, '4-horizontal');
    await page.keyboard.press('Escape');
    await ctx.close();
  } finally {
    await browser.close();
    if (antes === '') sql("DELETE FROM configuracion WHERE clave = 'apariencia.menu'");
    else php("$pdo->prepare(\"UPDATE configuracion SET valor = ? WHERE clave = 'apariencia.menu'\")->execute([$argv[1]]);", antes);
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), '--limpiar'], { stdio: 'inherit' });
    try { process.kill(-server.pid); } catch (e) {}
  }
  ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ' (' + errores.join('; ') + ')' : ''));
  if (fallas) { console.error(`\n${fallas} falla(s) en el recorrido del menú.`); process.exit(1); }
  console.log('\nRecorrido del menú completo sin fallas.');
})();
