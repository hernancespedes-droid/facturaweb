<?php
declare(strict_types=1);

// Servidor SMTP de mentira para probar el envío de mails: php smtp_falso.php <puerto> <archivo de salida>
// Acepta una conexión, guarda en <archivo>.json los datos del sobre y en <archivo> el mensaje recibido.
[, $puerto, $salida] = $argv;
$srv = stream_socket_server('tcp://127.0.0.1:' . $puerto, $errno, $errstr);
if ($srv === false) {
    fwrite(STDERR, "$errstr\n");
    exit(1);
}
echo "LISTO\n";
$c = stream_socket_accept($srv, 15);
if ($c === false) {
    exit(2);
}
$sobre = ['auth' => [], 'de' => null, 'para' => []];
$estado = 'cmd';
$msg = '';
fwrite($c, "220 fake ESMTP\r\n");
while (($l = fgets($c)) !== false) {
    if ($estado === 'datos') {
        if ($l === ".\r\n") {
            file_put_contents($salida, $msg);
            $estado = 'cmd';
            fwrite($c, "250 recibido\r\n");
        } else {
            $msg .= $l;
        }
        continue;
    }
    if ($estado === 'usuario') {
        $sobre['auth'][] = base64_decode(trim($l));
        $estado = 'clave';
        fwrite($c, "334 UGFzc3dvcmQ6\r\n");
        continue;
    }
    if ($estado === 'clave') {
        $sobre['auth'][] = base64_decode(trim($l));
        $estado = 'cmd';
        fwrite($c, in_array('mal', $sobre['auth'], true) ? "535 autenticacion fallida\r\n" : "235 ok\r\n");
        continue;
    }
    $u = strtoupper(trim($l));
    if (str_starts_with($u, 'EHLO')) {
        fwrite($c, "250-fake\r\n250 AUTH LOGIN\r\n");
    } elseif ($u === 'AUTH LOGIN') {
        $estado = 'usuario';
        fwrite($c, "334 VXNlcm5hbWU6\r\n");
    } elseif (str_starts_with($u, 'MAIL FROM:')) {
        $sobre['de'] = trim(substr(trim($l), 10));
        fwrite($c, "250 ok\r\n");
    } elseif (str_starts_with($u, 'RCPT TO:')) {
        $dest = trim(substr(trim($l), 8));
        if (str_contains($dest, 'rechazado@')) {
            fwrite($c, "550 no existe\r\n");
        } else {
            $sobre['para'][] = $dest;
            fwrite($c, "250 ok\r\n");
        }
    } elseif ($u === 'DATA') {
        $estado = 'datos';
        fwrite($c, "354 siga\r\n");
    } elseif ($u === 'QUIT') {
        fwrite($c, "221 chau\r\n");
        break;
    } else {
        fwrite($c, "250 ok\r\n");
    }
}
file_put_contents($salida . '.json', json_encode($sobre));
