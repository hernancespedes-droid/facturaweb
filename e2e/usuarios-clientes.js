// Recorrido de navegador: el Master del master resetea la contraseña de un usuario de un cliente (0.59.0, D-142…D-144).
//   node e2e/usuarios-clientes.js   levanta su propio servidor en el puerto 8086
// Necesita la base facturaweb_test_inst (la misma de tests/InstanciasTest.php); si no está, se saltea avisando.
// Desde el ticket «no puedo entrar» va a los usuarios de la instalación, resetea al Administrador bloqueado (queda la nota interna en el
// ticket, sin la contraseña) y al operador inactivo con «Reactivarlo»; ve el registro. También en celular.
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const PORT = process.env.E2E_USUCLI_PORT || '8086';
const BASE = 'http://127.0.0.1:' + PORT;
const ROOT = path.resolve(__dirname, '..');
const CAPTURAS = path.join(__dirname, 'capturas');
const CLAVE = 'E2e-Prueba-2026';
let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };
const sinDesborde = (p) => p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
const php = (codigo, ...args) => String(execFileSync('php', ['-r', 'require "' + ROOT + '/src/bootstrap.php"; App\\App::boot(); $pdo = App\\Core\\Db::pdo(); ' + codigo, ...args]));
const fixture = (arg) => String(execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_instancia.php'), arg]));

async function login(page, usuario) {
  await page.goto(BASE + '/login');
  await page.fill('input[name=usuario]', usuario);
  await page.fill('input[name=clave]', CLAVE);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}

(async () => {
  const inst = JSON.parse(fixture('crear'));
  if (!inst.ok) {
    console.log('  (se saltea: falta la base facturaweb_test_inst)');
    process.exit(0);
  }
  const env = { ...process.env, PORT };
  const server = spawn('bash', [path.join(ROOT, 'bin/dev-server.sh')], { stdio: 'ignore', detached: true, env });
  for (let i = 0; i < 40; i++) { try { if ((await fetch(BASE + '/salud')).ok) break; } catch (e) {} await new Promise(r => setTimeout(r, 250)); }
  php("$pdo->exec(\"DELETE FROM usuarios WHERE usuario = 'e2e_master'\"); $pdo->prepare(\"INSERT INTO usuarios (usuario, nombre, password_hash, rol_id) SELECT 'e2e_master', 'Master E2E', ?, id FROM roles WHERE codigo = 'master'\")->execute([password_hash($argv[1], PASSWORD_DEFAULT)]);", CLAVE);
  fs.mkdirSync(CAPTURAS, { recursive: true });
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', e => errores.push(e.message));
    page.on('dialog', d => d.accept());

    await login(page, 'e2e_master');
    await page.goto(BASE + '/soporte/bandeja/' + inst.ticket);
    await Promise.all([page.waitForNavigation(), page.click('a:has-text("Usuarios y contraseñas de esta instalación")')]);
    ok((await page.textContent('h1')).includes(inst.host), 'desde el ticket abre los usuarios de la instalación');
    ok((await page.inputValue('select[name=ticket]')) === String(inst.ticket), 'el ticket viene elegido');
    const filaAdmin = page.locator('form tbody tr', { has: page.locator('td b', { hasText: /^admin$/ }) });
    ok((await filaAdmin.textContent()).includes('Bloqueado'), 'el Administrador aparece bloqueado');
    await page.fill('[name=motivo]', 'Perdió la contraseña');
    await Promise.all([page.waitForNavigation(), filaAdmin.locator('button:has-text("Resetear contraseña")').click()]);
    const clave = (await page.textContent('[data-clave]')).trim();
    ok(clave.length === 12, 'muestra la contraseña temporal');
    ok((await page.textContent('.clave-temporal')).includes('ticket #' + inst.ticket), 'avisa que quedó anotado en el ticket');
    ok(!(await filaAdmin.textContent()).includes('Bloqueado') && (await filaAdmin.textContent()).includes('Contraseña temporal'), 'el Administrador queda desbloqueado y con contraseña temporal');
    ok((await page.textContent('section:has(h2:has-text("Registro"))')).includes('e2e_master'), 'el registro dice quién la reseteó');
    await page.screenshot({ path: path.join(CAPTURAS, 'usuarios-clientes-reseteo.png'), fullPage: true });

    const filaCajera = page.locator('form tbody tr', { has: page.locator('td b', { hasText: /^cajera$/ }) });
    await filaCajera.locator('input[type=checkbox]').check();
    await Promise.all([page.waitForNavigation(), filaCajera.locator('button:has-text("Resetear contraseña")').click()]);
    ok((await page.textContent('.clave-temporal')).includes('quedó activo'), 'el inactivo se reactiva');
    ok((await filaCajera.textContent()).includes('Activo'), 'la cajera queda activa');

    await page.goto(BASE + '/soporte/bandeja/' + inst.ticket);
    const conv = await page.textContent('.soporte-conversacion');
    ok(conv.includes('«admin»') && !conv.includes(clave), 'el ticket tiene la nota interna, sin la contraseña');
    await page.goto(BASE + '/instancias/editar?host=' + inst.host);
    ok(await page.locator('a:has-text("Usuarios y contraseñas")').count() === 1, 'Administrar tiene el botón «Usuarios y contraseñas»');
    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));

    const movil = await browser.newContext({ ...devices['Pixel 7'] });
    const p2 = await movil.newPage();
    await login(p2, 'e2e_master');
    await p2.goto(BASE + '/instancias/usuarios?host=' + inst.host);
    ok(await sinDesborde(p2), 'celular: la pantalla no se desborda');
    await p2.screenshot({ path: path.join(CAPTURAS, 'usuarios-clientes-celular.png'), fullPage: true });
    await movil.close();
  } finally {
    await browser.close();
    php("$pdo->exec(\"DELETE FROM usuarios WHERE usuario = 'e2e_master'\");");
    fixture('--limpiar');
    try { process.kill(-server.pid); } catch (e) {}
  }
  console.log(fallas === 0 ? '\nRecorrido de usuarios de clientes completo sin fallas.' : `\n${fallas} falla(s).`);
  process.exit(fallas === 0 ? 0 : 1);
})().catch(e => { console.error(e); try { fixture('--limpiar'); } catch (x) {} process.exit(1); });
