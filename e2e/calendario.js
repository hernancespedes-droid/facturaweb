// Recorrido de navegador: calendario en los campos de fecha (0.63.0, D-149; public/assets/calendario.js).
//   node e2e/calendario.js   levanta su propio servidor en el puerto 8087
//   E2E_CAPTURAS=<carpeta> guarda capturas (escritorio claro y oscuro, celular).
// Todo input[data-fecha] lleva un botón que abre un calendario: el día elegido queda como dd/mm/aaaa, en los filtros con
// desde/hasta hay atajos (hoy, este mes, mes pasado…) que completan los dos, se maneja con el teclado y en el celular es una hoja desde abajo.
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');

const PORT = process.env.E2E_CALENDARIO_PORT || '8087';
const BASE = 'http://127.0.0.1:' + PORT;
const ROOT = path.resolve(__dirname, '..');
const CLAVE = 'E2e-Prueba-2026';
const CAPTURAS = process.env.E2E_CAPTURAS || '';
let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };
const foto = async (page, nombre) => { if (CAPTURAS) await page.screenshot({ path: path.join(CAPTURAS, nombre + '.png') }); };
const dos = (n) => String(n).padStart(2, '0');
const txt = (d) => dos(d.getDate()) + '/' + dos(d.getMonth() + 1) + '/' + d.getFullYear();

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
  const browser = await chromium.launch();
  const errores = [];
  try {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => errores.push(e.message));
    await login(page, 'e2e_admin');

    // Consultas con desde/hasta: todas tienen el botón
    for (const ruta of ['/comprobantes', '/listados/caja-diaria', '/listados/ventas-diarias', '/listados/iva-ventas', '/listados/estadistica', '/recibos', '/cheques', '/cuenta-corriente', '/stock/existencias', '/stock/movimientos', '/caja/turnos']) {
      const r = await page.goto(BASE + ruta);
      if (!r || r.status() !== 200) { ok(false, ruta + ': abre (' + (r && r.status()) + ')'); continue; }
      const n = await page.locator('input[data-fecha]').count();
      const b = await page.locator('.cal-caja > input[data-fecha] + .cal-btn').count();
      ok(n >= 1 && b === n, ruta + ': los ' + n + ' campos de fecha tienen su calendario');
    }

    // Elegir un día con el mouse
    await page.goto(BASE + '/listados/caja-diaria');
    const desde = page.locator('input[name=desde]');
    const hasta = page.locator('input[name=hasta]');
    await desde.fill('');
    await page.locator('input[name=desde] + .cal-btn').click();
    ok(await page.locator('.cal-pop').isVisible(), 'el botón abre el calendario');
    const cajaIn = await desde.boundingBox(), cajaPop = await page.locator('.cal-pop').boundingBox();
    ok(cajaPop.y >= cajaIn.y + cajaIn.height - 1 || cajaPop.y + cajaPop.height <= cajaIn.y + 1, 'escritorio: se abre pegado al campo, sin taparlo');
    ok(await page.locator('.cal-dia.hoy').count() === 1, 'marca el día de hoy');
    await foto(page, '1-calendario-escritorio');
    const h = new Date();
    const elegido = new Date(h.getFullYear(), h.getMonth(), 10);
    await page.click('.cal-dia:not(.fuera)[data-dia="' + txt(elegido) + '"]');
    ok(await desde.inputValue() === txt(elegido), 'el día elegido queda escrito dd/mm/aaaa (' + txt(elegido) + ')');
    ok(await page.locator('.cal-pop').count() === 0, 'al elegir se cierra');

    // Cambiar de mes y de año
    await page.locator('input[name=desde] + .cal-btn').click();
    await page.click('.cal-nav[data-mes="-1"]');
    const mesAnt = new Date(h.getFullYear(), h.getMonth() - 1, 1);
    ok(Number(await page.locator('.cal-mes').inputValue()) === mesAnt.getMonth(), '«‹» va al mes anterior');
    await page.selectOption('.cal-anio', String(h.getFullYear() - 1));
    await page.selectOption('.cal-mes', '2');
    await page.click('.cal-dia:not(.fuera)[data-dia="15/03/' + (h.getFullYear() - 1) + '"]');
    ok(await desde.inputValue() === '15/03/' + (h.getFullYear() - 1), 'con los desplegables de mes y año se llega a otra fecha');

    // Atajos: «Mes pasado» completa desde y hasta
    await page.locator('input[name=hasta] + .cal-btn').click();
    ok(await page.locator('.cal-atajo').count() >= 5, 'en un filtro desde/hasta ofrece períodos rápidos');
    await page.click('.cal-atajo:has-text("Mes pasado")');
    const p1 = new Date(h.getFullYear(), h.getMonth() - 1, 1), p2 = new Date(h.getFullYear(), h.getMonth(), 0);
    ok(await desde.inputValue() === txt(p1) && await hasta.inputValue() === txt(p2), '«Mes pasado» pone ' + txt(p1) + ' a ' + txt(p2));

    // Teclado: Alt+↓ abre, flechas mueven, Enter elige (y no envía el filtro), Esc cierra
    await desde.fill('10/05/2026');
    await desde.focus();
    await page.keyboard.press('Alt+ArrowDown');
    ok(await page.locator('.cal-pop').isVisible(), 'Alt+↓ abre el calendario desde el campo');
    ok(await page.evaluate(() => document.activeElement.getAttribute('data-dia')) === '10/05/2026', 'el foco arranca en la fecha que tenía el campo');
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowDown');
    const url = page.url();
    await page.keyboard.press('Enter');
    await page.waitForTimeout(200);
    ok(await desde.inputValue() === '18/05/2026', 'flechas y Enter eligen el día (18/05/2026)');
    ok(page.url() === url, 'Enter en el calendario no envía el filtro');
    await page.keyboard.press('Alt+ArrowDown');
    await page.keyboard.press('PageDown');
    ok(await page.evaluate(() => document.activeElement.getAttribute('data-dia')) === '18/06/2026', 'AvPág pasa al mes siguiente');
    await page.keyboard.press('Escape');
    ok(await page.locator('.cal-pop').count() === 0 && await desde.inputValue() === '18/05/2026', 'Esc cierra sin cambiar la fecha');
    ok(await page.evaluate(() => document.activeElement.name) === 'desde', 'al cerrar vuelve el foco al campo');

    // Escribir a mano sigue andando y se completa al salir
    await desde.fill('1/3/26');
    await hasta.focus();
    ok(await desde.inputValue() === '01/03/2026', 'escribir «1/3/26» queda 01/03/2026 al salir del campo');

    // Clic afuera cierra
    await page.locator('input[name=desde] + .cal-btn').click();
    await page.mouse.click(5, 300);
    ok(await page.locator('.cal-pop').count() === 0, 'un clic afuera cierra el calendario');

    // El filtro usa lo elegido
    await hasta.fill('31/03/2026');
    await Promise.all([page.waitForNavigation(), page.click('form[method=get] button[type=submit]')]);
    ok(page.url().includes('desde=01%2F03%2F2026') && await page.locator('input[name=desde]').inputValue() === '01/03/2026', 'el filtro viaja con las fechas elegidas');

    // Campos que se agregan después (renglones nuevos) también tienen calendario
    await page.evaluate(() => { const l = document.createElement('label'); l.className = 'field'; l.innerHTML = '<span>Nueva</span><input name="x_fecha" data-fecha>'; document.querySelector('main').appendChild(l); });
    await page.waitForTimeout(50);
    ok(await page.locator('input[name=x_fecha] + .cal-btn').count() === 1, 'un campo agregado después también recibe el calendario');

    // Mercado Pago ingresos pasó de type=date a dd/mm/aaaa con calendario
    const rmp = await page.goto(BASE + '/mercadopago/ingresos?desde=01/03/2026&hasta=31/03/2026');
    if (rmp && rmp.status() === 200) {
      ok(await page.locator('input[name=desde]').inputValue() === '01/03/2026', 'Ingresos de Mercado Pago: lee dd/mm/aaaa y lo muestra igual');
    }

    // Tema oscuro
    await page.goto(BASE + '/listados/caja-diaria');
    await page.click('[data-tema-cambiar]');
    await page.locator('input[name=hasta] + .cal-btn').click();
    await page.waitForTimeout(100);
    await foto(page, '2-calendario-oscuro');
    await page.keyboard.press('Escape');
    await page.click('[data-tema-cambiar]');
    await ctx.close();

    // Celular
    const cel = await browser.newContext({ ...devices['iPhone 13'] });
    const pc = await cel.newPage();
    pc.on('pageerror', e => errores.push(e.message));
    await login(pc, 'e2e_admin');
    await pc.goto(BASE + '/comprobantes');
    await pc.locator('input[name=desde] + .cal-btn').tap();
    const hoja = await pc.locator('.cal-pop').boundingBox();
    const vp = pc.viewportSize();
    ok(hoja && Math.abs(hoja.x) < 1 && Math.abs(hoja.width - vp.width) < 2 && Math.abs(hoja.y + hoja.height - vp.height) < 2, 'celular: se abre como hoja desde abajo, a todo el ancho');
    const dia = await pc.locator('.cal-dia').first().boundingBox();
    ok(dia && dia.height >= 40, 'celular: los días se tocan cómodos (' + Math.round(dia.height) + ' px)');
    ok(await pc.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'celular: sin scroll horizontal');
    await foto(pc, '3-calendario-celular');
    await pc.locator('.cal-atajo:has-text("Este mes")').tap();
    const m1 = new Date(h.getFullYear(), h.getMonth(), 1);
    ok(await pc.locator('input[name=desde]').inputValue() === txt(m1) && await pc.locator('input[name=hasta]').inputValue() === txt(h), 'celular: «Este mes» completa desde y hasta');
    await pc.locator('input[name=desde] + .cal-btn').tap();
    await pc.locator('.cal-fondo').tap({ position: { x: 20, y: 20 } });
    ok(await pc.locator('.cal-pop').count() === 0, 'celular: tocar el fondo cierra la hoja');
    await cel.close();

    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
  } finally {
    await browser.close();
    try { process.kill(-server.pid); } catch (e) {}
  }
  console.log(fallas ? `\n${fallas} falla(s) en el calendario.` : '\nCalendario sin fallas.');
  process.exit(fallas ? 1 : 0);
})();
