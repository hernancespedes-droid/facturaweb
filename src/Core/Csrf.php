<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Request;

/** Token CSRF por sesión. La App lo exige en todo POST (ver App::handle). */
final class Csrf
{
    public const CAMPO = '_csrf';

    public static function token(): string
    {
        $t = Session::get('_csrf_token');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf_token', $t);
        }
        return $t;
    }

    public static function valid(Request $req): bool
    {
        $t = Session::get('_csrf_token');
        $given = $req->post[self::CAMPO] ?? '';
        return is_string($t) && $t !== '' && is_string($given) && hash_equals($t, $given);
    }
}
