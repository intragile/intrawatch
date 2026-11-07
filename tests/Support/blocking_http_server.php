<?php

// Minimal blocking HTTP server for tests. Usage: php blocking_http_server.php <port> <hit-log-file>
/** @var list<string> $args */
$args = $_SERVER['argv'];
[, $port, $log] = $args;
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}

/**
 * @param  resource  $conn
 * @param  array<string, string>  $headers
 */
function respond($conn, int $status, string $body = '', array $headers = []): void
{
    $head = "HTTP/1.1 {$status} X\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n";
    foreach ($headers as $k => $v) {
        $head .= "{$k}: {$v}\r\n";
    }
    fwrite($conn, $head."\r\n".$body);
}

while (true) {
    $conn = @stream_socket_accept($server, 60);
    if ($conn === false) {
        exit(0);
    }
    $request = '';
    while (! str_contains($request, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $request .= $chunk;
    }
    preg_match('#^(\S+) (\S+)#', $request, $m);
    $path = $m[2] ?? '/';
    file_put_contents($log, $path."\n", FILE_APPEND);

    if ($path === '/hang') {
        sleep(30);
    } elseif ($path === '/redirect') {
        respond($conn, 302, '', ['Location' => '/other']);
    } elseif ($path === '/big') {
        respond($conn, 200, str_repeat('a', 1048576));
    } elseif ($path === '/ratelimit') {
        respond($conn, 429, '', ['Retry-After' => '30']);
    } elseif ($path === '/ratelimit-long') {
        respond($conn, 429, '', ['Retry-After' => '9999']);
    } elseif (preg_match('#^/status/(\d+)$#', $path, $s)) {
        respond($conn, (int) $s[1]);
    } elseif ($path === '/json') {
        respond($conn, 200, '{"ok":true}', ['Content-Type' => 'application/json']);
    } else {
        respond($conn, 200, 'ok');
    }
    fclose($conn);
}
