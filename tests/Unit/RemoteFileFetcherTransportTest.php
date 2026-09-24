<?php

declare(strict_types=1);

use Happenv\FilamentMultiSourceUpload\Exceptions\RemoteFileFetchException;
use Happenv\FilamentMultiSourceUpload\Support\RemoteFileFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

covers(RemoteFileFetcher::class);

/*
 * The other fetcher tests fake the HTTP client, so they never reach Guzzle's
 * handlers — which is how a request the curl handler never saw (its pin and
 * size cap silently dropped on Guzzle 7, refused outright on Guzzle 8) went
 * unnoticed. These run the real handler against a local `php -S` server.
 */

beforeEach(function (): void {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $this->serverLog = (string) tempnam(sys_get_temp_dir(), 'msu_test_log_');
    $this->server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/../Fixtures/transport-server.php'],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
        env_vars: ['TRANSPORT_SERVER_LOG' => $this->serverLog] + getenv(),
    );
    $this->baseUrl = "http://127.0.0.1:{$port}";

    for ($attempt = 0; $attempt < 50 && ! @fsockopen('127.0.0.1', $port); $attempt++) {
        usleep(100_000);
    }

    Http::preventStrayRequests(false);
    Storage::fake('tmp-for-tests');
});

afterEach(function (): void {
    proc_terminate($this->server);
    proc_close($this->server);
    @unlink($this->serverLog);
});

/** @return array<string> */
function transportTestPartialFiles(): array
{
    return glob(sys_get_temp_dir() . '/msu_*') ?: [];
}

it('downloads through the real curl handler', function (): void {
    $file = new RemoteFileFetcher()
        ->fetch("{$this->baseUrl}/logo.png", allowPrivateNetworks: true, maxSizeKb: 64);

    expect($file)->toBeInstanceOf(TemporaryUploadedFile::class)
        ->and($file->getMimeType())->toBe('image/png');
});

it('aborts a transfer without a Content-Length once it passes the cap, and removes the partial file', function (): void {
    $before = transportTestPartialFiles();

    try {
        new RemoteFileFetcher()->fetch("{$this->baseUrl}/unbounded", allowPrivateNetworks: true, maxSizeKb: 64);
        $this->fail('Expected the transfer to be aborted.');
    } catch (RemoteFileFetchException $exception) {
        expect($exception->reason())->toBe('filament-multi-source-upload::multi-source-file-upload.reason_too_large');
    }

    // Wait for the server to notice the client is gone and log how much it sent.
    for ($attempt = 0; $attempt < 50 && filesize($this->serverLog) === 0; $attempt++) {
        usleep(100_000);
        clearstatcache();
    }

    expect((int) file_get_contents($this->serverLog))->toBeGreaterThan(0)->toBeLessThan(64 * 1024 * 1024)
        ->and(transportTestPartialFiles())->toEqualCanonicalizing($before);
});

it('aborts a transfer whose declared Content-Length is over the cap before the body arrives', function (): void {
    $started = microtime(true);

    try {
        new RemoteFileFetcher()->fetch("{$this->baseUrl}/declared", allowPrivateNetworks: true, maxSizeKb: 64);
        $this->fail('Expected the transfer to be aborted.');
    } catch (RemoteFileFetchException $exception) {
        expect($exception->reason())->toBe('filament-multi-source-upload::multi-source-file-upload.reason_too_large');
    }

    // The server holds the body back for 10 s; only an abort on the header returns sooner.
    expect(microtime(true) - $started)->toBeLessThan(5);
});

it('connects to the pinned address through the real curl handler', function (): void {
    // `pin.invalid` never resolves; the request only arrives if curl connects
    // to the pinned address instead of looking the host up.
    $pin = new ReflectionMethod(RemoteFileFetcher::class, 'pinConnection')->invoke(new RemoteFileFetcher, '127.0.0.1');
    $port = parse_url($this->baseUrl, PHP_URL_PORT);

    $response = Http::withOptions(['allow_redirects' => false, 'curl' => $pin])
        ->get("http://pin.invalid:{$port}/logo.png");

    expect($response->status())->toBe(200)
        ->and($response->header('Content-Type'))->toStartWith('image/png');
});
