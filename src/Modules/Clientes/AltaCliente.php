<?php
declare(strict_types=1);

namespace App\Modules\Clientes;

use App\Core\Db;
use App\Core\Valida;
use App\Modules\Arca\Padron;
use App\Modules\Arca\PadronError;
use App\Modules\Comprobantes\ComprobantesController;
use App\Modules\Comprobantes\ComprobantesService;
use App\Modules\Comprobantes\Tipos;

/**
 * Alta de clientes a partir del CUIT: busca el CUIT entre los clientes, consulta el padrón de ARCA y devuelve los datos listos para el
 * formulario (razón social, condición de IVA, domicilio fiscal, provincia de la tabla) más la ficha impositiva para mostrar.
 * La usan la ventana «Nuevo cliente» de las ventas (factura, presupuesto, kiosco) y el botón «Traer datos de ARCA» de la ficha.
 *
 * DECISIÓN D-71 (Claude, a confirmar por Hernán): el alta rápida desde una venta pide lo mínimo (CUIT, razón social, condición de IVA,
 * domicilio, teléfono y mail) y deja el resto como el alta normal (forma de pago contado salvo que se elija cuenta corriente, sin vendedor,
 * sin límite de crédito). El cliente queda elegido en la venta. Lo puede hacer quien tiene `clientes.alta` o `clientes.editar`.
 */
final class AltaCliente
{
    /** Campos que se pueden mandar en el alta rápida (los demás quedan como en el alta normal). */
    public const CAMPOS_RAPIDOS = ['razon_social', 'iva', 'cuit', 'domicilio', 'localidad', 'codigo_postal', 'provincia', 'telefono', 'emailfc', 'forma_pago'];

    /**
     * @return array{ok:bool,cuit:string,existentes:array<int,array<string,mixed>>,arca:bool,datos:array<string,mixed>,ficha:?array<string,mixed>,
     *   avisos:string[],error:?string,motivo:?string,letra:string}
     */
    public function propuesta(string $cuit, bool $forzar = false): array
    {
        $digitos = preg_replace('/\D/', '', $cuit) ?? '';
        $out = ['ok' => false, 'cuit' => cuit_texto($digitos), 'existentes' => [], 'arca' => false, 'datos' => [], 'ficha' => null, 'avisos' => [],
            'error' => null, 'motivo' => null, 'letra' => ''];
        if (!Valida::cuit($digitos)) {
            $out['error'] = 'El CUIT no es válido (revisá los 11 números).';
            $out['motivo'] = 'cuit';
            return $out;
        }
        $out['ok'] = true;
        $out['existentes'] = $this->existentes((int) $digitos);
        $out['datos'] = ['cuit' => cuit_texto($digitos)];
        try {
            $p = Padron::consultar($digitos, $forzar);
            $out['arca'] = $p['encontrado'];
            $out['ficha'] = $p;
            $out['avisos'] = $p['avisos'];
            $prov = $this->provinciaCodigo((string) $p['provincia']);
            if ($p['provincia'] !== '' && $prov === null) {
                $out['avisos'][] = 'La provincia «' . $p['provincia'] . '» no está en Tablas → Provincias: elegila a mano.';
            }
            $out['datos'] += array_filter([
                'razon_social' => $p['razon_social'], 'iva' => (string) $p['iva'], 'domicilio' => $p['domicilio'], 'localidad' => $p['localidad'],
                'codigo_postal' => $p['codigo_postal'], 'provincia' => $prov,
            ], static fn ($x) => $x !== null && $x !== '');
            $out['letra'] = $this->letra((int) $p['iva']);
        } catch (PadronError $e) {
            $out['error'] = $e->getMessage();
            $out['motivo'] = $e->motivo;
            $out['datos']['iva'] = in_array(substr($digitos, 0, 2), ['30', '33', '34'], true) ? '1' : '';
        }
        return $out;
    }

    /**
     * Alta rápida. Devuelve el cliente como lo devuelve el buscador de las ventas (para elegirlo en el momento).
     *
     * @param array<string,mixed> $post
     * @return array{ok:bool,errores:array<string,string>,cliente:?array<string,mixed>}
     */
    public function crear(array $post): array
    {
        $datos = array_intersect_key($post, array_flip(self::CAMPOS_RAPIDOS));
        $datos = array_map(static fn ($x) => is_string($x) ? trim($x) : '', $datos);
        $datos += ['forma_pago' => '1'];
        if (!in_array($datos['forma_pago'], ['1', '2'], true)) {
            $datos['forma_pago'] = '1';
        }
        if (($post['cuit_repetido'] ?? '') === '1') {
            $datos['cuit_repetido'] = '1';
        }
        $r = (new ClientesService())->guardar(null, $datos);
        if (!$r['ok']) {
            return ['ok' => false, 'errores' => $r['errores'], 'cliente' => null];
        }
        $c = (new ClientesService())->obtener((int) $r['codigo']);
        return ['ok' => true, 'errores' => [], 'cliente' => ComprobantesController::clientesParaElegir([$c])[0]];
    }

    /** Clientes que ya tienen ese CUIT (activos primero), con la forma del buscador. @return array<int,array<string,mixed>> */
    public function existentes(int $cuit): array
    {
        $st = Db::pdo()->prepare('SELECT codigo, activo FROM clientes WHERE cuit = ? ORDER BY activo DESC, codigo LIMIT 5');
        $st->execute([$cuit]);
        $filas = $st->fetchAll();
        if ($filas === []) {
            return [];
        }
        $out = ComprobantesController::clientesParaElegir($filas);
        foreach ($out as $i => $c) {
            $out[$i]['activo'] = (int) $filas[$i]['activo'] === 1;
        }
        return $out;
    }

    /** Código de la provincia en Tablas → Provincias a partir del nombre que da ARCA (sin tildes ni mayúsculas; CABA = Capital Federal). */
    public function provinciaCodigo(string $nombre): ?string
    {
        $n = self::normal($nombre);
        if ($n === '') {
            return null;
        }
        $caba = ['CIUDAD AUTONOMA BUENOS AIRES', 'CIUDAD AUTONOMA DE BUENOS AIRES', 'CAPITAL FEDERAL', 'CABA', 'C A B A'];
        $filas = Db::pdo()->query('SELECT codigo, descripcion FROM provincias')->fetchAll();
        foreach ($filas as $f) {
            if (self::normal((string) $f['descripcion']) === $n) {
                return (string) $f['codigo'];
            }
        }
        if (in_array($n, $caba, true)) {
            foreach ($filas as $f) {
                if (in_array(self::normal((string) $f['descripcion']), $caba, true)) {
                    return (string) $f['codigo'];
                }
            }
        }
        if (str_starts_with($n, 'TIERRA DEL FUEGO')) {
            foreach ($filas as $f) {
                if (str_starts_with(self::normal((string) $f['descripcion']), 'TIERRA DEL FUEGO')) {
                    return (string) $f['codigo'];
                }
            }
        }
        return null;
    }

    /** Letra de factura que le corresponde a esa condición (A, B o C), o ''. */
    public function letra(int $iva): string
    {
        $svc = new ComprobantesService();
        $emisor = $svc->emisor();
        $cond = $svc->condicionArca($iva);
        return $emisor['condicion'] !== null && $cond !== null ? Tipos::letra((string) $emisor['condicion'], $cond) : '';
    }

    private static function normal(string $s): string
    {
        $s = strtr(mb_strtoupper(trim($s)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N', '.' => ' ', ',' => ' ']);
        $s = preg_replace('/\bDE\b|\bLA\b/', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }
}
