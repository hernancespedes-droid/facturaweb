// Recorridos de navegador que se suman después de los tres de bin/pruebas.sh.
//
// bin/pruebas.sh quedó CONGELADO (igual que los demás .sh de bin/): Ferozo les cambia los permisos y,
// si una versión nueva los modifica, GIT se niega a sincronizar («local changes would be overwritten»).
// Pasó el 21/11/2026: sumar tres renglones a pruebas.sh dejó el servidor trabado en 0.32.
// Un recorrido nuevo se agrega en esta lista, nunca en un .sh.
// e2e/cuenta-corriente.js (el último de pruebas.sh) llama a este archivo al terminar sin fallas.
const { execFileSync } = require('child_process');
const path = require('path');

const RECORRIDOS = [
  'importacion.js',
  'alta-cliente.js',
  'compras.js',
  'soporte.js',
];

for (const archivo of RECORRIDOS) {
  console.log(`\n==> e2e/${archivo}`);
  try {
    execFileSync('node', [path.join(__dirname, archivo)], { stdio: 'inherit' });
  } catch (e) {
    console.error(`\ne2e/${archivo} falló.`);
    process.exit(1);
  }
}
console.log('\nRecorridos extra completos sin fallas.');
