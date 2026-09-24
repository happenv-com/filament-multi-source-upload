<?php

declare(strict_types=1);

/*
 * Router for `php -S`, used by RemoteFileFetcherTransportTest to exercise the
 * real Guzzle handler without touching the network.
 */

$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/logo.png':
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        break;

    case '/unbounded':
        // No Content-Length: only the received byte count can stop it. Sends
        // up to 64 MB, far more than any test cap, and records how much went
        // out before the client hung up.
        ignore_user_abort(true);
        header('Content-Type: application/octet-stream');
        $chunk = str_repeat('A', 65536);
        $sent = 0;

        while ($sent < 64 * 1024 * 1024 && ! connection_aborted()) {
            echo $chunk;
            flush();
            $sent += strlen($chunk);
        }

        file_put_contents((string) getenv('TRANSPORT_SERVER_LOG'), (string) $sent);

        break;

    case '/declared':
        // A Content-Length over the cap, and a body that never arrives.
        header('Content-Type: application/octet-stream');
        header('Content-Length: 104857600');
        echo 'x';
        flush();
        sleep(10);

        break;

    default:
        http_response_code(404);
}
