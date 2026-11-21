<?php
declare(strict_types=1);

namespace App\Modules\Importacion;

use App\Core\Valida;
use App\Core\Validador;
use App\Modules\Clientes\ClientesService;
use App\Modules\Comprobantes\Tipos;
use App\Modules\Stock\StockService;
use PDO;

/**
 * Importa las filas de una planilla (ya leída por Lector) a clientes, artículos, stock o el subdiario, con el mapa columna → campo que eligió el usuario.
 *
 *  - Todo en una transacción. Con $simular se hace todo y al final se deshace (para ver los errores antes). Si hay filas con error no se graba
 *    nada, salvo que se pida «importar igual las filas sin error» (cada fila va en su propio SAVEPOINT: una fila con error no deja nada a medias).
 *  - Clientes y artículos se validan con las mismas reglas de sus pantallas (Validador + campos()). Un campo opcional que no es válido no frena la
 *    fila: se deja sin cargar y se avisa (DECISIÓN D-66). Al actualizar solo se cambian las columnas elegidas.
 *  - Los códigos de las tablas (provincia, rubro, vendedor…) se aceptan por código o por nombre; las condiciones (IVA, forma de pago) por código o texto.
 *  - Comprobantes: canal 'I', con importacion_id, sin caja ni stock; la cuenta corriente solo si viene el saldo. Un comprobante que ya está
 *    (tipo + letra + punto de venta + número + fecha) se saltea, así se puede volver a importar el mismo archivo.
 */
final class Importador
{
    public const MAX_MENSAJES = 500;
    /** Condición de IVA escrita (normalizada) => código de clientes.iva. */
    private const IVA_TEXTO = [
        'ri' => 1, 'inscripto' => 1, 'responsableinscripto' => 1, 'respinscripto' => 1, 'iva' => 1, 'ivaresponsableinscripto' => 1,
        'cf' => 3, 'consumidorfinal' => 3, 'final' => 3, 'consumidor' => 3,
        'ex' => 4, 'exento' => 4, 'ivaexento' => 4, 'exentob' => 4, 'exentoa' => 5,
        'monotributo' => 7, 'monotributista' => 7, 'mt' => 7, 'm' => 7, 'responsablemonotributo' => 7, 'monotributosocial' => 7, 'pequenocontribuyenteeventual' => 7,
        'noinscripto' => 2, 'ni' => 2, 'liberado' => 6, 'noalcanzado' => 6, 'ivanoalcanzado' => 6,
    ];
    /** Códigos de ARCA => [tipo, letra]. */
    private const TIPOS_ARCA = [
        1 => ['FC', 'A'], 2 => ['ND', 'A'], 3 => ['NC', 'A'], 6 => ['FC', 'B'], 7 => ['ND', 'B'], 8 => ['NC', 'B'],
        11 => ['FC', 'C'], 12 => ['ND', 'C'], 13 => ['NC', 'C'], 51 => ['FC', 'M'], 52 => ['ND', 'M'], 53 => ['NC', 'M'],
        19 => ['FC', 'E'], 20 => ['ND', 'E'], 21 => ['NC', 'E'],
        201 => ['FC', 'A'], 202 => ['ND', 'A'], 203 => ['NC', 'A'], 206 => ['FC', 'B'], 207 => ['ND', 'B'], 208 => ['NC', 'B'],
        211 => ['FC', 'C'], 212 => ['ND', 'C'], 213 => ['NC', 'C'],
    ];
    private const LETRAS = ['A', 'B', 'C', 'M', 'E'];

    /** @var array<string,array<string,mixed>> */
    private array $campos;
    /** @var array{filas:int,nuevas:int,actualizadas:int,salteadas:int,con_error:int,errores:array<int,array{0:int,1:string}>,avisos:array<int,array{0:int,1:string}>,grabado:bool,importacion_id:?int} */
    private array $r;
    private int $filaActual = 0;
    /** @var array<string,int> clave ya vista en el archivo => fila */
    private array $vistas = [];
    private ?int $importacionId = null;
    private ?int $proximoCliente = null;
    /** @var array<string,array<string,string>> tabla => nombre normalizado => código */
    private array $porNombre = [];

    /**
     * @param array<string,int> $mapa campo => índice de la columna
     * @param array<string,mixed> $op existentes (actualizar|saltear), parcial (bool), modo_stock (dejar|sumar), deposito (id), motivo,
     *                                 crear_clientes (bool), cliente_defecto (código), crear_faltantes (bool)
     */
    public function __construct(private PDO $pdo, private string $destino, private array $mapa, private array $op = [])
    {
        if (!Destinos::existe($destino)) {
            throw new \InvalidArgumentException('Destino desconocido.');
        }
        $this->campos = Destinos::campos($destino);
        $this->mapa = array_filter($mapa, fn ($i, $k) => isset($this->campos[$k]) && is_int($i) && $i >= 0, ARRAY_FILTER_USE_BOTH);
    }

    /** Campos obligatorios que no tienen columna elegida. @return string[] labels */
    public function faltanObligatorios(): array
    {
        $faltan = [];
        foreach ($this->campos as $k => $c) {
            // En clientes y artículos los obligatorios se controlan solo en las altas (para actualizar precios alcanza con código y precio)
            $exigir = in_array($this->destino, ['clientes', 'articulos'], true) ? !empty($c['clave']) && !empty($c['requerido']) : !empty($c['requerido']);
            if ($exigir && !isset($this->mapa[$k])) {
                $faltan[] = (string) $c['label'];
            }
        }
        if ($this->destino === 'clientes' && !isset($this->mapa['codigo']) && !isset($this->mapa['cuit']) && !isset($this->mapa['razon_social'])) {
            $faltan[] = 'Razón social (o el código o el CUIT para actualizar)';
        }
        if ($this->destino === 'subdiario' && !isset($this->mapa['cliente']) && !isset($this->mapa['cuit']) && !isset($this->mapa['razon_social'])
            && (int) ($this->op['cliente_defecto'] ?? 0) <= 0) {
            $faltan[] = 'Cliente (código, CUIT o razón social, o un cliente para todos)';
        }
        return $faltan;
    }

    /**
     * @param array{titulos:string[],filas:array<int,array{n:int,celdas:string[]}>} $tabla
     * @return array<string,mixed> resultado (ver $r)
     */
    public function ejecutar(array $tabla, bool $simular, ?int $usuarioId, string $archivo): array
    {
        $this->r = ['filas' => 0, 'nuevas' => 0, 'actualizadas' => 0, 'salteadas' => 0, 'con_error' => 0, 'errores' => [], 'avisos' => [], 'grabado' => false, 'importacion_id' => null];
        $faltan = $this->faltanObligatorios();
        if ($faltan !== []) {
            throw new \RuntimeException('Elegí la columna de: ' . implode(', ', $faltan) . '.');
        }
        if ($this->destino === 'stock' && !\App\Core\Modulos::activo('stock')) {
            throw new \RuntimeException('El módulo de stock no está habilitado en esta instalación.');
        }
        $parcial = !empty($this->op['parcial']);
        @set_time_limit(900);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO importaciones (destino, archivo, usuario_id, creado_en) VALUES (?, ?, ?, NOW())')
                ->execute([$this->destino, mb_substr($archivo, 0, 255), $usuarioId]);
            $this->importacionId = (int) $this->pdo->lastInsertId();
            foreach ($tabla['filas'] as $f) {
                $this->filaActual = (int) $f['n'];
                $this->r['filas']++;
                $valores = [];
                foreach ($this->mapa as $campo => $i) {
                    $valores[$campo] = trim((string) ($f['celdas'][$i] ?? ''));
                }
                $this->pdo->exec('SAVEPOINT fila');
                try {
                    $res = match ($this->destino) {
                        'clientes' => $this->cliente($valores, $usuarioId),
                        'articulos' => $this->articulo($valores),
                        'stock' => $this->stock($valores, $usuarioId),
                        'subdiario' => $this->comprobante($valores, $usuarioId),
                    };
                    $this->r[$res]++;
                    $this->pdo->exec('RELEASE SAVEPOINT fila');
                } catch (FilaInvalida $e) {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT fila');
                    $this->r['con_error']++;
                    foreach ($e->mensajes as $m) {
                        $this->mensaje('errores', $m);
                    }
                }
            }
            if ($this->destino === 'clientes' && $this->r['nuevas'] > 0) {
                $this->pdo->exec('UPDATE parametros SET ultimo_cliente = GREATEST(ultimo_cliente, (SELECT COALESCE(MAX(codigo), 0) FROM clientes)) WHERE id = 1');
            }
            $grabar = !$simular && ($this->r['con_error'] === 0 || $parcial) && ($this->r['nuevas'] + $this->r['actualizadas']) > 0;
            if ($grabar) {
                $this->pdo->prepare('UPDATE importaciones SET filas = ?, nuevas = ?, actualizadas = ?, salteadas = ?, con_error = ?, resumen = ? WHERE id = ?')
                    ->execute([$this->r['filas'], $this->r['nuevas'], $this->r['actualizadas'], $this->r['salteadas'], $this->r['con_error'],
                        json_encode(['errores' => array_slice($this->r['errores'], 0, 100), 'avisos' => array_slice($this->r['avisos'], 0, 100), 'mapa' => $this->mapa, 'opciones' => $this->op], JSON_UNESCAPED_UNICODE),
                        $this->importacionId]);
                $this->pdo->commit();
                $this->r['grabado'] = true;
                $this->r['importacion_id'] = $this->importacionId;
            } else {
                $this->pdo->rollBack();
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            Validador::olvidar(); // si se crearon códigos de tablas y se deshicieron
        }
        return $this->r;
    }

    private function mensaje(string $tipo, string $texto): void
    {
        if (count($this->r[$tipo]) < self::MAX_MENSAJES) {
            $this->r[$tipo][] = [$this->filaActual, $texto];
        }
    }

    private function aviso(string $texto): void
    {
        $this->mensaje('avisos', $texto);
    }

    /** Una sola vez por archivo: la misma clave en dos filas. */
    private function repetida(string $clave, string $que): void
    {
        if (isset($this->vistas[$clave])) {
            throw new FilaInvalida(["{$que} ya está en la fila {$this->vistas[$clave]} del archivo."]);
        }
        $this->vistas[$clave] = $this->filaActual;
    }

    // ------------------------------------------------------------------ conversión de valores

    /**
     * Lleva lo que vino en la planilla al formato que espera el Validador para el campo (texto de formulario). Los nombres de las tablas
     * pasan a su código, las condiciones escritas a su número, sí/no a 1/0. Devuelve [valor, error].
     *
     * @param array<string,mixed> $c
     * @return array{0:string,1:?string}
     */
    private function preparar(string $campo, array $c, string $v): array
    {
        if ($v === '') {
            return ['', null];
        }
        switch ($c['tipo']) {
            case 'si_no':
                $n = Destinos::normalizar($v);
                if (in_array($n, ['1', 's', 'si', 'x', 'v', 'verdadero', 'true', 'y', 'yes'], true)) {
                    return ['1', null];
                }
                if (in_array($n, ['0', 'n', 'no', 'f', 'falso', 'false'], true)) {
                    return ['0', null];
                }
                return [$v, '«' . $v . '» no es sí ni no'];
            case 'opciones':
                if (array_key_exists($v, $c['opciones']) || array_key_exists(mb_strtoupper($v), $c['opciones'])) {
                    return [array_key_exists($v, $c['opciones']) ? $v : mb_strtoupper($v), null];
                }
                $n = Destinos::normalizar($v);
                foreach ($c['opciones'] as $k => $t) {
                    if (Destinos::normalizar((string) $t) === $n || Destinos::normalizar((string) $k) === $n) {
                        return [(string) $k, null];
                    }
                }
                if ($campo === 'iva' && isset(self::IVA_TEXTO[$n])) {
                    return [(string) self::IVA_TEXTO[$n], null];
                }
                if ($campo === 'estado') {
                    $e = match ($n) { 'activo', 'alta', 'a', 'si', '1' => 'AC', 'baja', 'inactivo', 'b', 'no', '0' => 'BA', default => null };
                    if ($e !== null) {
                        return [$e, null];
                    }
                }
                if ($campo === 'moneda') {
                    $m = match ($n) { 'pesos', 'ars', 'pes', 'peso' => '$', 'dolares', 'usd', 'us', 'dolar', 'dol', 'u' => 'u$s', default => null };
                    if ($m !== null) {
                        return [$m, null];
                    }
                }
                return [$v, '«' . $v . '» no es una opción válida para ' . mb_strtolower((string) $c['label'])];
            case 'ref':
                $cod = $this->codigoRef((string) $c['ref'], $v);
                return $cod === null ? [$v, '«' . $v . '» no existe en Tablas → ' . $this->tituloTabla((string) $c['ref'])] : [$cod, null];
            case 'cuit':
                return [preg_replace('/\D/', '', $v) ?? '', null];
            case 'entero':
                $n = Valida::decimal($v);
                return $n !== null && floor($n) === $n ? [(string) (int) $n, null] : [$v, null];
            case 'texto':
                $largo = (int) ($c['largo'] ?? 255);
                if (mb_strlen($v) > $largo && empty($c['clave']) && empty($c['digitos'])) {
                    $this->aviso(mb_strtolower((string) $c['label']) . ': se cortó a ' . $largo . ' caracteres.');
                    return [mb_substr($v, 0, $largo), null];
                }
                if (!empty($c['digitos'])) {
                    $v = preg_replace('/\D/', '', $v) ?? $v; // EAN que viene como número
                }
                return [$v, null];
            default:
                return [$v, null];
        }
    }

    /** Código de una tabla (provincias, rubros…) por código o por nombre. Las tasas de IVA también por porcentaje. */
    private function codigoRef(string $tabla, string $v): ?string
    {
        $ops = Validador::opciones($tabla);
        foreach ([$v, mb_strtoupper($v), ltrim($v, '0')] as $x) {
            if ($x !== '' && isset($ops[$x])) {
                return (string) $x;
            }
        }
        if (!isset($this->porNombre[$tabla])) {
            $this->porNombre[$tabla] = [];
            foreach ($ops as $k => $t) {
                $this->porNombre[$tabla][Destinos::normalizar($t)] ??= (string) $k;
            }
        }
        $n = Destinos::normalizar($v);
        if (isset($this->porNombre[$tabla][$n])) {
            return $this->porNombre[$tabla][$n];
        }
        if ($tabla === 'tasas_iva') {
            $p = Valida::decimal(str_replace('%', '', $v));
            if ($p !== null) {
                $st = $this->pdo->prepare('SELECT codigo FROM tasas_iva WHERE ABS(porcentaje - ?) < 0.001 ORDER BY codigo LIMIT 1');
                $st->execute([$p]);
                $cod = $st->fetchColumn();
                return $cod === false ? null : (string) $cod;
            }
        }
        if (!empty($this->op['crear_faltantes']) && in_array($tabla, ['rubros', 'vendedores', 'transportes', 'condicionpago'], true)) {
            return $this->crearCodigo($tabla, $v);
        }
        return null;
    }

    /** Agrega a la tabla un código que falta (con el nombre que vino), con el próximo número libre. DECISIÓN D-67. */
    private function crearCodigo(string $tabla, string $nombre): ?string
    {
        $largo = (int) (\App\Modules\Tablas\Definiciones::de($tabla)['campos']['codigo']['largo'] ?? 3);
        $ops = Validador::opciones($tabla);
        for ($i = 1; $i < 10 ** $largo; $i++) {
            if (!isset($ops[(string) $i])) {
                break;
            }
        }
        if ($i >= 10 ** $largo) {
            return null;
        }
        $this->pdo->prepare("INSERT INTO `{$tabla}` (codigo, descripcion) VALUES (?, ?)")->execute([(string) $i, mb_strtoupper(mb_substr($nombre, 0, 30))]);
        Validador::olvidar();
        $this->porNombre[$tabla][Destinos::normalizar($nombre)] = (string) $i;
        $this->aviso('se agregó «' . mb_strtoupper($nombre) . '» a Tablas → ' . $this->tituloTabla($tabla) . " con el código {$i}.");
        return (string) $i;
    }

    private function tituloTabla(string $tabla): string
    {
        return (string) (\App\Modules\Tablas\Definiciones::de($tabla)['titulo'] ?? $tabla);
    }

    /**
     * Prepara y valida los campos elegidos de clientes o artículos. Un error en un obligatorio frena la fila; en uno opcional, se avisa y el campo
     * no se carga.
     *
     * @param array<string,string> $valores
     * @param string[] $omitir
     * @return array<string,mixed> valores listos para grabar
     */
    private function validarCampos(array $valores, bool $alta, array $omitir): array
    {
        $datos = [];
        $campos = [];
        $errores = [];
        foreach ($valores as $k => $v) {
            if (in_array($k, $omitir, true)) {
                continue;
            }
            $c = $this->campos[$k];
            [$pv, $err] = $this->preparar($k, $c, $v);
            if ($err !== null) {
                if (!empty($c['requerido']) && $alta) {
                    $errores[] = $err . '.';
                } else {
                    $this->aviso($err . ': quedó sin cargar.');
                }
                continue;
            }
            if ($pv === '' && !$alta) {
                continue; // al actualizar, una celda vacía no borra lo que hay (DECISIÓN D-68)
            }
            $datos[$k] = $pv;
            $campos[$k] = $c;
        }
        [$v, $err] = Validador::validar($campos, $datos);
        foreach ($err as $k => $m) {
            if (!empty($campos[$k]['requerido']) && $alta) {
                $errores[] = $campos[$k]['label'] . ': ' . $m;
            } else {
                $this->aviso($campos[$k]['label'] . ': ' . rtrim($m, '.') . ' («' . $valores[$k] . '»); quedó sin cargar.');
                unset($v[$k]);
            }
        }
        if ($errores !== []) {
            throw new FilaInvalida($errores);
        }
        return $v;
    }

    /** @param array<string,mixed> $v */
    private function insertar(string $tabla, array $v): void
    {
        $cols = array_keys($v);
        $this->pdo->prepare("INSERT INTO `{$tabla}` (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($v));
    }

    /** @param array<string,mixed> $v */
    private function actualizar(string $tabla, array $v, string $clave, mixed $valorClave): void
    {
        if ($v === []) {
            return;
        }
        $cols = array_keys($v);
        $this->pdo->prepare("UPDATE `{$tabla}` SET " . implode(', ', array_map(static fn ($c) => "`{$c}` = ?", $cols)) . " WHERE `{$clave}` = ?")
            ->execute([...array_values($v), $valorClave]);
    }

    // ------------------------------------------------------------------ clientes

    /** @param array<string,string> $f */
    private function cliente(array $f, ?int $usuarioId): string
    {
        $codigo = null;
        if (($f['codigo'] ?? '') !== '') {
            $n = Valida::decimal($f['codigo']);
            if ($n === null || floor($n) !== $n || $n < 1 || $n > 99999999) {
                throw new FilaInvalida(['El código de cliente «' . $f['codigo'] . '» no es un número válido.']);
            }
            $codigo = (int) $n;
            $this->repetida('c' . $codigo, "El cliente {$codigo}");
        }
        $existente = null;
        if ($codigo !== null) {
            $st = $this->pdo->prepare('SELECT codigo, iva, cuit FROM clientes WHERE codigo = ?');
            $st->execute([$codigo]);
            $existente = $st->fetch() ?: null;
        } elseif (($f['cuit'] ?? '') !== '' && Valida::cuit($f['cuit'])) {
            $cuit = (int) preg_replace('/\D/', '', $f['cuit']);
            $this->repetida('u' . $cuit, 'El CUIT ' . cuit_texto($cuit));
            $st = $this->pdo->prepare('SELECT codigo, iva, cuit FROM clientes WHERE cuit = ? ORDER BY activo DESC, codigo LIMIT 2');
            $st->execute([$cuit]);
            $hay = $st->fetchAll();
            if (count($hay) > 1) {
                $this->aviso('hay más de un cliente con el CUIT ' . cuit_texto($cuit) . ': se actualiza el ' . $hay[0]['codigo'] . '.');
            }
            $existente = $hay[0] ?? null;
        }
        if ($existente === null && $codigo === null && ($f['razon_social'] ?? '') !== '' && (($f['cuit'] ?? '') === '' || !Valida::cuit($f['cuit']))) {
            // Sin código ni CUIT: el mismo nombre es el mismo cliente (así volver a importar el archivo no lo duplica). DECISIÓN D-71.
            $this->repetida('r' . mb_strtolower($f['razon_social']), 'El cliente «' . $f['razon_social'] . '»');
            $st = $this->pdo->prepare('SELECT codigo, iva, cuit FROM clientes WHERE razon_social = ? AND cuit IS NULL ORDER BY activo DESC, codigo LIMIT 1');
            $st->execute([$f['razon_social']]);
            $existente = $st->fetch() ?: null;
        }
        if ($existente !== null && ($this->op['existentes'] ?? 'actualizar') === 'saltear') {
            return 'salteadas';
        }
        $alta = $existente === null;
        $v = $this->validarCampos($f, $alta, ['codigo']);
        if ($alta) {
            if (!isset($v['iva']) || $v['iva'] === null) {
                $v['iva'] = 3;
                if (($v['cuit'] ?? null) !== null) {
                    $this->aviso('sin condición de IVA: se cargó como consumidor final.');
                }
            }
            $v['forma_pago'] ??= 2;
        }
        // CUIT obligatorio salvo consumidor final / no inscripto (la misma regla de la pantalla)
        $iva = (int) ($v['iva'] ?? ($existente['iva'] ?? 3));
        $cuit = array_key_exists('cuit', $v) ? $v['cuit'] : ($existente['cuit'] ?? null);
        if ($cuit === null && !in_array($iva, ClientesService::SIN_CUIT, true)) {
            throw new FilaInvalida(['Para ' . (ClientesService::CONDICIONES_IVA[$iva] ?? 'esa condición') . ' el CUIT es obligatorio' . (($f['cuit'] ?? '') !== '' ? ' (el «' . $f['cuit'] . '» no es válido).' : '.')]);
        }
        if ($cuit !== null && ($v['cuit'] ?? null) !== null) {
            $st = $this->pdo->prepare('SELECT codigo FROM clientes WHERE cuit = ? AND activo = 1 AND codigo <> ? LIMIT 1');
            $st->execute([$cuit, (int) ($existente['codigo'] ?? $codigo ?? 0)]);
            $otro = $st->fetchColumn();
            if ($otro !== false) {
                $this->aviso('el CUIT ' . cuit_texto($cuit) . " también lo tiene el cliente {$otro}.");
            }
        }
        $v['actualizacion'] = date('Y-m-d H:i:s');
        if ($alta) {
            if (!isset($v['razon_social'])) {
                throw new FilaInvalida(['Falta la razón social.']);
            }
            if ($codigo === null) {
                $this->proximoCliente ??= $this->ultimoCliente();
                do {
                    $codigo = ++$this->proximoCliente;
                    $st = $this->pdo->prepare('SELECT 1 FROM clientes WHERE codigo = ?');
                    $st->execute([$codigo]);
                } while ($st->fetchColumn() !== false);
            }
            $this->insertar('clientes', ['codigo' => $codigo, 'fecha_alta' => date('Y-m-d')] + $v);
            return 'nuevas';
        }
        $this->actualizar('clientes', $v, 'codigo', (int) $existente['codigo']);
        return 'actualizadas';
    }

    /** El último código usado (parámetros bloqueados hasta el commit: un alta a mano en paralelo no toma el mismo número). */
    private function ultimoCliente(): int
    {
        $ultimo = (int) $this->pdo->query('SELECT ultimo_cliente FROM parametros WHERE id = 1 FOR UPDATE')->fetchColumn();
        return max($ultimo, (int) $this->pdo->query('SELECT COALESCE(MAX(codigo), 0) FROM clientes')->fetchColumn());
    }

    // ------------------------------------------------------------------ artículos

    /** @param array<string,string> $f */
    private function articulo(array $f): string
    {
        $codigo = mb_strtoupper(trim($f['codigo'] ?? ''));
        if ($codigo === '' || mb_strlen($codigo) > 15 || preg_match('/^[^\s\/\\\\?#%]+( [^\s\/\\\\?#%]+)*$/u', $codigo) !== 1) {
            throw new FilaInvalida(['El código «' . ($f['codigo'] ?? '') . '» no sirve: hasta 15 caracteres, sin barras ni signos ? # %.']);
        }
        $this->repetida('a' . $codigo, "El artículo {$codigo}");
        $st = $this->pdo->prepare('SELECT codigo, rubro FROM articulos WHERE codigo = ?');
        $st->execute([$codigo]);
        $existente = $st->fetch() ?: null;
        if ($existente !== null && ($this->op['existentes'] ?? 'actualizar') === 'saltear') {
            return 'salteadas';
        }
        $alta = $existente === null;
        $v = $this->validarCampos($f, $alta, ['codigo']);
        if ($alta) {
            if (!isset($v['descripcion'])) {
                throw new FilaInvalida(['Falta la descripción.']);
            }
            $v['estado'] ??= 'AC';
            $v['moneda'] ??= '$';
            if (!isset($v['alicuota'])) {
                $v['alicuota'] = $this->codigoRef('tasas_iva', '21');
                if ($v['alicuota'] === null) {
                    throw new FilaInvalida(['Falta la alícuota de IVA (y no hay una de 21 % en Tablas → Tasas de IVA).']);
                }
            }
            if (!isset($v['grabado'])) {
                $st = $this->pdo->prepare('SELECT porcentaje FROM tasas_iva WHERE codigo = ?');
                $st->execute([$v['alicuota']]);
                $v['grabado'] = (float) $st->fetchColumn() > 0 ? 1 : 0;
            }
        }
        if (isset($v['subrubro'])) { // el subrubro tiene que existir en el rubro
            $v['subrubro'] = mb_strtoupper((string) $v['subrubro']);
            $st = $this->pdo->prepare('SELECT 1 FROM subrubros WHERE rubro = ? AND codigo = ?');
            $st->execute([(string) ($v['rubro'] ?? ($existente['rubro'] ?? '')), $v['subrubro']]);
            if ($st->fetchColumn() === false) {
                $this->aviso('el subrubro «' . $v['subrubro'] . '» no existe en ese rubro: quedó sin cargar.');
                unset($v['subrubro']);
            }
        }
        $v['actualizacion'] = date('Y-m-d H:i:s');
        if ($alta) {
            $this->insertar('articulos', ['codigo' => $codigo] + $v);
            return 'nuevas';
        }
        $this->actualizar('articulos', $v, 'codigo', $codigo);
        return 'actualizadas';
    }

    // ------------------------------------------------------------------ stock

    /** @var array<string,int>|null código y nombre normalizados => id */
    private ?array $depositos = null;

    /** @param array<string,string> $f */
    private function stock(array $f, ?int $usuarioId): string
    {
        $svc = new StockService($this->pdo);
        $art = $svc->resolverArticulo($f['articulo']);
        if ($art === null) {
            throw new FilaInvalida(['No hay un artículo único con el código, código de barras o SKU «' . $f['articulo'] . '».']);
        }
        $cant = Valida::decimal($f['cantidad']);
        if ($cant === null || abs($cant) > 999999999) {
            throw new FilaInvalida(['La cantidad «' . $f['cantidad'] . '» no es un número.']);
        }
        if ($this->depositos === null) {
            $this->depositos = [];
            foreach ($svc->depositos() as $d) {
                $this->depositos[Destinos::normalizar((string) $d['codigo'])] = (int) $d['id'];
                $this->depositos[Destinos::normalizar((string) $d['nombre'])] ??= (int) $d['id'];
            }
        }
        $dep = (int) ($this->op['deposito'] ?? 0) ?: $svc->depositoPrincipal();
        if (($f['deposito'] ?? '') !== '') {
            $dep = $this->depositos[Destinos::normalizar($f['deposito'])] ?? 0;
            if ($dep === 0) {
                throw new FilaInvalida(['No existe el depósito «' . $f['deposito'] . '» (Stock → Depósitos).']);
            }
        }
        if ((int) $art['controla_stock'] === 0) {
            $this->aviso("el artículo {$art['codigo']} no controla stock (es un servicio): igual se cargó.");
        }
        $modo = ($this->op['modo_stock'] ?? 'dejar') === 'sumar' ? 'sumar' : 'dejar';
        if ($modo === 'dejar') {
            $this->repetida('s' . $art['codigo'] . '|' . $dep, "El artículo {$art['codigo']} en ese depósito");
            if ($cant < 0) {
                throw new FilaInvalida(['Para dejar el stock en una cantidad, la cantidad no puede ser negativa.']);
            }
            $st = $this->pdo->prepare('SELECT saldo FROM stock_saldos WHERE articulo = ? AND deposito_id = ? FOR UPDATE');
            $st->execute([$art['codigo'], $dep]);
            $mov = round($cant - (float) $st->fetchColumn(), 3);
            $origen = 'AJUSTE';
        } else {
            $mov = round($cant, 3);
            $origen = $mov >= 0 ? 'INGRESO' : 'EGRESO';
        }
        if (abs($mov) < 0.0005) {
            return 'salteadas'; // ya tenía esa cantidad
        }
        $motivo = trim((string) ($this->op['motivo'] ?? '')) ?: 'Importación de stock';
        $svc->mover((string) $art['codigo'], $dep, $mov, $origen, null, $usuarioId, $motivo, 'IMP' . $this->importacionId);
        return 'actualizadas';
    }

    // ------------------------------------------------------------------ comprobantes (subdiario)

    /** @param array<string,string> $f */
    private function comprobante(array $f, ?int $usuarioId): string
    {
        $err = [];
        $fecha = self::fecha($f['fecha'] ?? '');
        if ($fecha === null) {
            $err[] = 'La fecha «' . ($f['fecha'] ?? '') . '» no es válida.';
        }
        [$tipo, $letra, $tipoAfip] = self::tipoYLetra($f['tipo'] ?? '', $f['letra'] ?? '');
        if ($tipo === null) {
            $err[] = 'No entiendo el tipo de comprobante «' . ($f['tipo'] ?? '') . '» (FC, NC, ND o el código de ARCA).';
        }
        if ($tipo !== null && $letra === null) {
            $err[] = 'Falta la letra (A, B, C, M o E).';
        }
        [$pv, $numero] = self::puntoYNumero($f['punto_venta'] ?? '', $f['numero'] ?? '');
        if ($numero === null) {
            $err[] = 'El número «' . ($f['numero'] ?? '') . '» no es válido.';
        }
        if ($pv === null) {
            $err[] = 'Falta el punto de venta (columna Punto de venta o el número como 0004-00001234).';
        }
        $imp = [];
        foreach (['neto_gravado', 'exento', 'no_gravado', 'otros_tributos', 'total', 'saldo', 'iva_total', ...array_keys(Destinos::ALICUOTAS)] as $k) {
            if (($f[$k] ?? '') === '') {
                continue;
            }
            $n = Valida::decimal(str_replace(['(', ')'], ['-', ''], $f[$k]));
            if ($n === null) {
                $err[] = $this->campos[$k]['label'] . ': «' . $f[$k] . '» no es un importe.';
                continue;
            }
            $imp[$k] = $n;
        }
        if ($err !== []) {
            throw new FilaInvalida($err);
        }
        // Las notas de crédito pueden venir en negativo (así las muestra ARCA en algunos listados): se guardan en positivo, como el VFP
        $negativos = array_filter($imp, static fn ($x) => $x < 0);
        if ($negativos !== []) {
            if ($tipo !== 'NC') {
                throw new FilaInvalida(['Hay importes negativos en un comprobante que no es nota de crédito.']);
            }
            $imp = array_map('abs', $imp);
        }
        $imp = array_map(static fn ($x) => round($x, 2), $imp);

        // IVA por alícuota
        $iva = [];
        foreach (Destinos::ALICUOTAS as $k => $a) {
            if (($imp[$k] ?? 0) > 0) {
                $iva[(string) $a] = $imp[$k];
            }
        }
        $gravado = $imp['neto_gravado'] ?? null;
        if (($imp['iva_total'] ?? 0) > 0) {
            if ($iva !== []) {
                throw new FilaInvalida(['Elegiste el IVA total y también columnas por alícuota: dejá una sola de las dos formas.']);
            }
            if (($gravado ?? 0) <= 0) {
                throw new FilaInvalida(['Con el IVA total hace falta el neto gravado para saber la alícuota.']);
            }
            $a = self::alicuotaDe($gravado, $imp['iva_total']);
            if ($a === null) {
                throw new FilaInvalida(['El IVA (' . numero($imp['iva_total']) . ') no corresponde a una sola alícuota del neto (' . numero($gravado)
                    . '): usá columnas de IVA separadas por alícuota.']);
            }
            $iva[(string) $a] = $imp['iva_total'];
        }
        if (in_array($letra, ['C'], true) && $iva !== []) {
            throw new FilaInvalida(['Un comprobante C no discrimina IVA: revisá la letra o las columnas de IVA.']);
        }
        $bases = [];
        $sumaIva = array_sum($iva);
        if ($iva !== []) {
            if (count($iva) === 1 && $gravado !== null) {
                $bases[array_key_first($iva)] = $gravado;
            } else {
                foreach ($iva as $a => $x) {
                    $bases[$a] = round($x * 100 / (float) $a, 2);
                }
                $suma = round(array_sum($bases), 2);
                if ($gravado !== null && abs($suma - $gravado) > 0.05 * count($bases)) {
                    throw new FilaInvalida(['El neto gravado (' . numero($gravado) . ') no coincide con el que sale del IVA por alícuota (' . numero($suma) . ').']);
                }
                $gravado ??= $suma;
            }
        }
        $exento = $imp['exento'] ?? 0.0;
        $otros = ($imp['no_gravado'] ?? 0.0) + ($imp['otros_tributos'] ?? 0.0);
        if ($gravado === null && $letra === 'C' && isset($imp['total'])) {
            $gravado = round($imp['total'] - $exento - $otros, 2); // en la C el neto es el total
        }
        if ($gravado === null && $exento <= 0 && !isset($imp['total'])) {
            throw new FilaInvalida(['Faltan los importes (neto gravado, exento o total).']);
        }
        $gravado ??= 0.0;
        if ($gravado > 0 && $iva === [] && in_array($letra, ['A', 'B', 'M'], true)) {
            throw new FilaInvalida(["Un comprobante {$letra} con neto gravado necesita el IVA."]);
        }
        $calculado = round($gravado + $sumaIva + $exento + $otros, 2);
        $total = $imp['total'] ?? $calculado;
        if ($total + 0.02 < $calculado) {
            throw new FilaInvalida(['El total (' . numero($total) . ') es menor que la suma de neto, IVA y exento (' . numero($calculado) . ').']);
        }
        if ($total - $calculado > 0.02 && !isset($imp['no_gravado']) && !isset($imp['otros_tributos'])) {
            $this->aviso('el total supera en ' . numero($total - $calculado) . ' a la suma de neto, IVA y exento: la diferencia queda como no gravado / otros tributos.');
        }
        if ($total <= 0) {
            throw new FilaInvalida(['El comprobante tiene total cero.']);
        }
        $saldo = $imp['saldo'] ?? 0.0;
        if ($saldo - $total > 0.009) {
            throw new FilaInvalida(['El saldo (' . numero($saldo) . ') es mayor que el total (' . numero($total) . ').']);
        }

        // Ya cargado: se saltea (volver a importar el mismo archivo no duplica)
        $this->repetida("{$tipo}|{$letra}|{$pv}|{$numero}|{$fecha}", 'El comprobante ' . $tipo . ' ' . $letra . ' ' . sprintf('%04d-%08d', $pv, $numero));
        $st = $this->pdo->prepare("SELECT 1 FROM subdiario WHERE tipo = ? AND letra = ? AND prefijo = ? AND fecha = ?
            AND (comprobante = ? OR (fe_nrocbte = ? AND fe_ccae NOT IN ('', '.'))) LIMIT 1");
        $st->execute([$tipo, $letra, $pv, $fecha, $numero, $numero]);
        if ($st->fetchColumn() !== false) {
            return 'salteadas';
        }
        $sucursal = $this->sucursal($pv);
        $st = $this->pdo->prepare('SELECT 1 FROM subdiario WHERE tipo = ? AND letra = ? AND sucursal = ? AND comprobante = ? AND fecha = ?');
        $st->execute([$tipo, $letra, $sucursal, $numero, $fecha]);
        if ($st->fetchColumn() !== false) {
            throw new FilaInvalida(['Ya hay un ' . $tipo . ' ' . $letra . " número {$numero} del mismo día en otro punto de venta de la misma sucursal."]);
        }

        $cli = $this->clienteDelComprobante($f, $letra, $usuarioId);
        $cae = preg_replace('/\D/', '', $f['cae'] ?? '') ?? '';
        if ($cae !== '' && strlen($cae) !== 14) {
            $this->aviso('el CAE «' . $f['cae'] . '» no tiene 14 dígitos: se guardó igual.');
        }
        $vtoCae = ($f['vto_cae'] ?? '') !== '' ? self::fecha($f['vto_cae']) : null;
        $tipoAfip = $tipoAfip ?? Tipos::tipoAfip($tipo, $letra);
        $ahora = date('Y-m-d H:i:s');
        $obs = ($f['observaciones'] ?? '') !== '' ? mb_substr($f['observaciones'], 0, 255) : null;
        $this->insertar('subdiario', [
            'fecha' => $fecha, 'tipo' => $tipo, 'letra' => $letra, 'sucursal' => $sucursal, 'prefijo' => $pv, 'comprobante' => $numero, 'cliente' => $cli['codigo'],
            'bruto' => round($gravado + $exento, 2), 'neto_grabado' => $gravado, 'neto_exento' => $exento, 'iva_1' => round($sumaIva, 2), 'neto' => $total,
            'observaciones' => $obs, 'canal' => 'I', 'terminal' => 0, 'iva_cliente' => (int) $cli['iva'], 'cuit_cliente' => $cli['cuit'],
            'forma_pago' => $saldo > 0.004 ? 2 : 1, 'actualizacion' => $ahora, 'nrointerno' => $numero, 'tipoafipoys' => $tipoAfip,
            'electronica' => $cae !== '' ? 1 : 0, 'fe_nrocbte' => $cae !== '' ? $numero : 0, 'fe_ccae' => $cae, 'fe_dfecha' => $cae !== '' ? $fecha : null,
            'fe_dvtocae' => $vtoCae, 'fe_ntipoafip' => $tipoAfip, 'cliente_razon_social' => $cli['razon_social'], 'cliente_domicilio' => $cli['domicilio'],
            'cliente_localidad' => $cli['localidad'], 'creado_por' => $usuarioId, 'es_prueba' => 0, 'fe_entorno' => $cae !== '' ? 'P' : '',
            'importacion_id' => $this->importacionId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        foreach ($iva as $a => $x) {
            $this->insertar('iva', ['subdiario_id' => $id, 'tipo' => $tipo, 'letra' => $letra, 'sucursal' => $sucursal, 'comprobante' => $numero,
                'alicuota' => (float) $a, 'monto' => $bases[$a], 'importe' => $x, 'actualizacion' => $ahora]);
        }
        if ($saldo > 0.004) {
            $this->insertar('ctact', ['subdiario_id' => $id, 'cliente' => $cli['codigo'], 'fecha' => $fecha, 'tipo' => $tipo, 'tico' => Tipos::tico($tipo), 'letra' => $letra,
                'sucursal' => $sucursal, 'prefijo' => $pv, 'numero' => $numero, 'neto' => $saldo, 'descuento' => 0, 'saldo' => $saldo, 'vencimiento' => $fecha,
                'valor_dolar' => 1, 'actualizacion' => $ahora]);
        }
        $this->pdo->prepare('UPDATE clientes SET ultima_compra = GREATEST(COALESCE(ultima_compra, ?), ?) WHERE codigo = ?')->execute([$fecha, $fecha, $cli['codigo']]);
        return 'nuevas';
    }

    /** @var array<int,int> punto de venta => sucursal */
    private array $sucursales = [];

    /** La sucursal que tiene ese punto de venta; si ninguna lo tiene, la primera (DECISIÓN D-69: el punto de venta queda en prefijo). */
    private function sucursal(int $pv): int
    {
        if (!isset($this->sucursales[$pv])) {
            $st = $this->pdo->prepare('SELECT codigo FROM sucursales WHERE prefijo = ? ORDER BY codigo LIMIT 1');
            $st->execute([$pv]);
            $s = $st->fetchColumn();
            if ($s === false) {
                $s = $this->pdo->query('SELECT MIN(codigo) FROM sucursales')->fetchColumn();
            }
            if ($s === false || $s === null) {
                throw new \RuntimeException('No hay ninguna sucursal cargada (Tablas → Sucursales).');
            }
            $this->sucursales[$pv] = (int) $s;
        }
        return $this->sucursales[$pv];
    }

    /**
     * El cliente del comprobante: por código, después por CUIT, después por razón social (exacta); si no está y se pidió, se crea. DECISIÓN D-70.
     *
     * @param array<string,string> $f
     * @return array<string,mixed>
     */
    private function clienteDelComprobante(array $f, string $letra, ?int $usuarioId): array
    {
        $sel = 'SELECT codigo, iva, cuit, razon_social, domicilio, localidad FROM clientes';
        $buscar = function (string $where, array $p) use ($sel): ?array {
            $st = $this->pdo->prepare("{$sel} WHERE {$where} ORDER BY activo DESC, codigo LIMIT 1");
            $st->execute($p);
            return $st->fetch() ?: null;
        };
        $cod = Valida::decimal($f['cliente'] ?? '');
        if (($f['cliente'] ?? '') !== '') {
            $c = $cod !== null ? $buscar('codigo = ?', [(int) $cod]) : null;
            if ($c === null) {
                throw new FilaInvalida(['No existe el cliente con código «' . $f['cliente'] . '».']);
            }
            return $c;
        }
        $cuitTxt = preg_replace('/\D/', '', $f['cuit'] ?? '') ?? '';
        $cuit = strlen($cuitTxt) === 11 && Valida::cuit($cuitTxt) ? (int) $cuitTxt : null;
        if ($cuit !== null && ($c = $buscar('cuit = ?', [$cuit])) !== null) {
            return $c;
        }
        $nombre = trim($f['razon_social'] ?? '');
        if ($nombre !== '' && ($c = $buscar('razon_social = ?', [$nombre])) !== null) {
            if ($cuit === null || $c['cuit'] === null) {
                return $c;
            }
        }
        if ($cuit === null && $nombre === '' && (int) ($this->op['cliente_defecto'] ?? 0) > 0) {
            $c = $buscar('codigo = ?', [(int) $this->op['cliente_defecto']]);
            if ($c !== null) {
                return $c;
            }
            throw new \RuntimeException('El cliente elegido para los comprobantes sin cliente no existe.');
        }
        if (empty($this->op['crear_clientes']) || $nombre === '') {
            throw new FilaInvalida(['No encontré el cliente' . ($cuit !== null ? ' con CUIT ' . cuit_texto($cuit) : '') . ($nombre !== '' ? ' «' . $nombre . '»' : '')
                . ($nombre === '' ? ' (no hay razón social para darlo de alta).' : ' (tildá «dar de alta los clientes que no estén»).')]);
        }
        // Alta: inscripto si es A con CUIT; consumidor final en el resto (se puede corregir en la ficha)
        $iva = $letra === 'A' && $cuit !== null ? 1 : 3;
        if ($letra !== 'A' && $cuit !== null) {
            $this->aviso('el cliente «' . $nombre . '» se dio de alta como consumidor final: revisá su condición de IVA.');
        }
        $this->proximoCliente ??= $this->ultimoCliente();
        $codigo = ++$this->proximoCliente;
        $this->insertar('clientes', ['codigo' => $codigo, 'razon_social' => mb_substr($nombre, 0, 100), 'iva' => $iva, 'cuit' => $cuit, 'forma_pago' => 2,
            'fecha_alta' => date('Y-m-d'), 'actualizacion' => date('Y-m-d H:i:s')]);
        $this->pdo->exec('UPDATE parametros SET ultimo_cliente = GREATEST(ultimo_cliente, ' . $codigo . ') WHERE id = 1');
        $this->aviso("se dio de alta el cliente {$codigo} «{$nombre}».");
        return ['codigo' => $codigo, 'iva' => $iva, 'cuit' => $cuit, 'razon_social' => $nombre, 'domicilio' => null, 'localidad' => null];
    }

    // ------------------------------------------------------------------ interpretaciones (públicas para probarlas)

    /** Fecha en dd/mm/aaaa, dd-mm-aaaa, dd.mm.aaaa, dd/mm/aa, aaaa-mm-dd, aaaammdd o número de serie del Excel. */
    public static function fecha(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{1,2}:\d{2}.*|)$/', $v) === 1) {
            return null;
        }
        $v = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?$/', '', $v) ?? $v; // "15/03/2024 00:00"
        if (preg_match('/^\d{8}$/', $v) === 1) {
            $v = substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2);
        } elseif (preg_match('/^\d{5}([.,]\d+)?$/', $v) === 1) {
            $v = Lector::fechaExcel((float) str_replace(',', '.', $v));
        } elseif (preg_match('#^(\d{1,2})[-.](\d{1,2})[-.](\d{4})$#', $v, $m) === 1) {
            $v = "{$m[1]}/{$m[2]}/{$m[3]}";
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2})$#', $v, $m) === 1) {
            $v = "{$m[1]}/{$m[2]}/" . ((int) $m[3] > 70 ? '19' : '20') . $m[3];
        }
        return Valida::fecha($v);
    }

    /** @return array{0:?string,1:?string,2:?int} tipo (FC/NC/ND), letra, código de ARCA si vino */
    public static function tipoYLetra(string $tipo, string $letra): array
    {
        $letra = mb_strtoupper(trim($letra));
        $letra = in_array($letra, self::LETRAS, true) ? $letra : null;
        $t = mb_strtoupper(trim($tipo));
        $t = strtr($t, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
        if (preg_match('/^0*(\d{1,3})\b/', $t, $m) === 1 && isset(self::TIPOS_ARCA[(int) $m[1]])) { // "1 - Factura A", "011"
            [$tp, $l] = self::TIPOS_ARCA[(int) $m[1]];
            return [$tp, $letra ?? $l, (int) $m[1]];
        }
        $t = trim(preg_replace('/[^A-Z ]/', ' ', $t) ?? '');
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        $patrones = [
            'NC' => '/^(NOTA (DE )?CREDITO|NOTA CRED|N C|NC|NCR|CREDITO|NOTA DE CRED)( ELECTRONICA)?/',
            'ND' => '/^(NOTA (DE )?DEBITO|NOTA DEB|N D|ND|NDB|DEBITO)( ELECTRONICA)?/',
            'FC' => '/^(FACTURA|FACT|FAC|FC|F)( DE CREDITO)?( ELECTRONICA)?( MIPYMES?| FCE)?/',
        ];
        foreach ($patrones as $tp => $re) {
            if (preg_match($re, $t, $m) === 1) {
                $resto = trim(substr($t, strlen($m[0])));
                if ($letra === null && preg_match('/^([ABCME])$/', $resto, $ml) === 1) {
                    $letra = $ml[1]; // "Factura A", "FCA", "FB", "NCA"
                }
                if ($resto !== '' && preg_match('/^[ABCME]$/', $resto) !== 1) {
                    continue;
                }
                return [$tp, $letra, null];
            }
        }
        if (preg_match('/^(FC|NC|ND|F)([ABCME])$/', str_replace(' ', '', $t), $m) === 1) { // "FCA", "NCB"
            return [$m[1] === 'F' ? 'FC' : $m[1], $letra ?? $m[2], null];
        }
        return [null, $letra, null];
    }

    /** @return array{0:?int,1:?int} punto de venta y número ("0004-00001234", "4-1234", "A-0004-00001234" o solo el número) */
    public static function puntoYNumero(string $pv, string $numero): array
    {
        $numero = trim($numero);
        $p = Valida::decimal(trim($pv));
        $p = $p !== null && floor($p) === $p && $p >= 0 && $p <= 99999 ? (int) $p : null;
        if (preg_match('/(\d{1,5})\s*-\s*(\d{1,8})\s*$/', $numero, $m) === 1) {
            return [$p ?? (int) $m[1], (int) $m[2]];
        }
        $n = Valida::decimal($numero);
        if ($n === null || floor($n) !== $n || $n < 1 || $n > 99999999) {
            return [$p, null];
        }
        return [$p, (int) $n];
    }

    /** La alícuota que da ese IVA sobre ese neto (con tolerancia de redondeo), o null si no es ninguna. */
    public static function alicuotaDe(float $neto, float $iva): ?float
    {
        foreach (Destinos::ALICUOTAS as $a) {
            if (abs(round($neto * $a / 100, 2) - $iva) <= max(0.05, $neto * 0.0002)) {
                return $a;
            }
        }
        return null;
    }
}
