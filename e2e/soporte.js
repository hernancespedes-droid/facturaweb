// Recorrido de navegador: tickets de soporte (0.42.0) en la instalación principal.
//   node e2e/soporte.js   levanta su propio servidor en el puerto 8084
// El operador abre un ticket con captura, O&S (Master) lo ve en la bandeja, deja una nota interna y responde; el operador ve la respuesta
// (no la nota), contesta y lo cierra. También en celular.
const { chromium, devices } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const PORT = process.env.E2E_SOPORTE_PORT || '8084';
const BASE = 'http://127.0.0.1:' + PORT;
const ROOT = path.resolve(__dirname, '..');
const CAPTURAS = path.join(__dirname, 'capturas');
const CLAVE = 'E2e-Prueba-2026';
let fallas = 0;
const ok = (cond, texto) => { console.log((cond ? '  OK    ' : '  FALLA ') + texto); if (!cond) fallas++; };
const sinDesborde = (p) => p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
const php = (codigo, ...args) => String(execFileSync('php', ['-r', 'require "' + ROOT + '/src/bootstrap.php"; App\\App::boot(); $pdo = App\\Core\\Db::pdo(); ' + codigo, ...args]));
const sql = (q) => php('$pdo->exec($argv[1]);', q);
const limpiar = () => {
  sql("DELETE a FROM soporte_adjuntos a JOIN soporte_tickets t ON t.id = a.ticket_id WHERE t.asunto LIKE 'E2E %'");
  sql("DELETE m FROM soporte_mensajes m JOIN soporte_tickets t ON t.id = m.ticket_id WHERE t.asunto LIKE 'E2E %'");
  sql("DELETE FROM soporte_tickets WHERE asunto LIKE 'E2E %'");
  sql("DELETE FROM usuarios WHERE usuario = 'e2e_master'");
};

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
  limpiar();
  php("$pdo->prepare(\"INSERT INTO usuarios (usuario, nombre, email, password_hash, rol_id) SELECT 'e2e_master', 'Master E2E', NULL, ?, id FROM roles WHERE codigo = 'master'\")->execute([password_hash($argv[1], PASSWORD_DEFAULT)]);", CLAVE);
  sql("UPDATE usuarios SET email = 'operador@e2e.com' WHERE usuario = 'e2e_operador'");
  const mailsAntes = php("echo (string) $pdo->query(\"SELECT valor FROM configuracion WHERE clave = 'soporte.mail_aviso'\")->fetchColumn();");
  fs.mkdirSync(CAPTURAS, { recursive: true });
  const captura = path.join(CAPTURAS, 'soporte-adjunto.png');
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', e => errores.push(e.message));

    // ---- O&S configura el mail de aviso
    await login(page, 'e2e_master');
    await page.goto(BASE + '/soporte/bandeja');
    ok((await page.textContent('h1')).includes('Tickets de soporte'), 'master: abre la bandeja');
    await page.fill('[name=mail_aviso]', 'soporte@e2e.com');
    await Promise.all([page.waitForNavigation(), page.click('form[action$="/soporte/bandeja/configuracion"] button')]);
    ok((await page.textContent('body')).includes('Mails de aviso guardados'), 'master: guarda el mail de aviso');
    await page.screenshot({ path: captura });
    await ctx.clearCookies();

    // ---- el operador abre un ticket desde una pantalla
    await login(page, 'e2e_operador');
    await page.goto(BASE + '/clientes');
    ok(await page.locator('.menu-grupo[data-grupo="Ayuda"]').count() === 1, 'operador: el menú tiene Ayuda → Soporte');
    await page.goto(BASE + '/soporte/nuevo?pantalla=' + encodeURIComponent('/clientes'));
    ok((await page.inputValue('[name=usuario_mail]')) === 'operador@e2e.com', 'nuevo: el mail del usuario viene completo');
    await page.fill('[name=asunto]', 'E2E No encuentro un cliente');
    await page.selectOption('[name=tipo]', 'consulta');
    await page.fill('[name=descripcion]', 'Busco por CUIT y no aparece.');
    await page.setInputFiles('[name=adjunto]', captura);
    await Promise.all([page.waitForNavigation(), page.click('form[action$="/soporte/nuevo"] button[type=submit]')]);
    ok(/\/soporte\/\d+$/.test(page.url()), 'nuevo: queda en el ticket');
    const id = page.url().split('/').pop();
    ok((await page.textContent('body')).includes('enviado a O&S'), 'nuevo: avisa que se envió');
    ok(await page.locator('img.soporte-captura').count() === 1, 'ticket: muestra la captura');
    ok(await page.evaluate(() => { const i = document.querySelector('img.soporte-captura'); return i && i.complete && i.naturalWidth > 0; }), 'ticket: la captura se ve (adjunto servido)');
    await page.screenshot({ path: path.join(CAPTURAS, 'soporte-cliente-ticket.png'), fullPage: true });
    await ctx.clearCookies();

    // ---- O&S lo ve, deja una nota interna y responde
    await login(page, 'e2e_master');
    await page.goto(BASE + '/soporte/bandeja');
    ok((await page.textContent('table')).includes('E2E No encuentro un cliente'), 'bandeja: aparece el ticket');
    ok(await page.locator('tr.fila-destacada').count() >= 1, 'bandeja: marcado «para contestar»');
    await page.screenshot({ path: path.join(CAPTURAS, 'soporte-bandeja.png'), fullPage: true });
    await page.goto(BASE + '/soporte/bandeja/' + id);
    ok((await page.textContent('.soporte-datos')).includes('/clientes'), 'master: ve la pantalla donde estaba');
    ok((await page.textContent('.soporte-datos')).includes('El envío de mails no está configurado'), 'master: avisa que el mail no salió (sin SMTP en desarrollo)');
    await page.fill('[name=texto]', 'Revisar el índice por CUIT.');
    await page.check('[name=interno]');
    await Promise.all([page.waitForNavigation(), page.click('form[action$="/responder"] button[type=submit]')]);
    await page.fill('[name=texto]', 'Escribí el CUIT sin guiones y elegilo de la lista.');
    await Promise.all([page.waitForNavigation(), page.click('form[action$="/responder"] button[type=submit]')]);
    ok(await page.locator('.soporte-msg.interno').count() === 1, 'master: la nota interna se ve marcada');
    ok((await page.textContent('.soporte-datos')).includes('Esperando al cliente'), 'master: queda esperando al cliente');
    await page.screenshot({ path: path.join(CAPTURAS, 'soporte-master-ticket.png'), fullPage: true });
    await ctx.clearCookies();

    // ---- el operador ve la respuesta (no la nota), contesta y cierra
    await login(page, 'e2e_operador');
    await page.goto(BASE + '/soporte');
    ok((await page.textContent('table')).includes('Esperando tu respuesta'), 'cliente: la lista dice «esperando tu respuesta»');
    await page.goto(BASE + '/soporte/' + id);
    const cuerpo = await page.textContent('.soporte-conversacion');
    ok(cuerpo.includes('sin guiones') && !cuerpo.includes('índice por CUIT'), 'cliente: ve la respuesta y no la nota interna');
    await page.fill('[name=texto]', 'Gracias, funcionó.');
    await Promise.all([page.waitForNavigation(), page.click('form[action$="/responder"] button[type=submit]')]);
    await Promise.all([page.waitForNavigation(), page.click('form[action$="/estado"] button:has-text("Cerrar ticket")')]);
    ok((await page.textContent('.soporte-datos')).includes('Cerrado'), 'cliente: lo cierra');
    ok(errores.length === 0, 'sin errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));

    // ---- celular
    const movil = await browser.newContext({ ...devices['Pixel 7'] });
    const p2 = await movil.newPage();
    await login(p2, 'e2e_operador');
    await p2.goto(BASE + '/soporte/nuevo');
    ok(await sinDesborde(p2), 'celular: el formulario no se desborda');
    await p2.screenshot({ path: path.join(CAPTURAS, 'soporte-celular-nuevo.png'), fullPage: true });
    await p2.goto(BASE + '/soporte/' + id);
    ok(await sinDesborde(p2), 'celular: el ticket no se desborda');
    await p2.screenshot({ path: path.join(CAPTURAS, 'soporte-celular-ticket.png'), fullPage: true });
    await movil.close();
  } finally {
    await browser.close();
    limpiar();
    sql("UPDATE configuracion SET valor = '" + mailsAntes.replace(/'/g, '') + "' WHERE clave = 'soporte.mail_aviso'");
    execFileSync('php', [path.join(ROOT, 'tests/fixtures/e2e_usuarios.php'), '--limpiar'], { stdio: 'inherit' });
    try { process.kill(-server.pid); } catch (e) {}
  }
  console.log(fallas === 0 ? '\nRecorrido de soporte completo sin fallas.' : `\n${fallas} falla(s).`);
  process.exit(fallas === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
