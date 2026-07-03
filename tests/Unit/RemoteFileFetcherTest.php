<?php

declare(strict_types=1);

use Happenv\FilamentMultiSourceUpload\Exceptions\RemoteFileFetchException;
use Happenv\FilamentMultiSourceUpload\Support\RemoteFileFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(RemoteFileFetcher::class);

/** Real 1×1 transparent PNG, so content-based MIME sniffing yields image/png. */
function fetcherTestPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    );
}

it('rejects non-http(s) schemes', function (string $url): void {
    (new RemoteFileFetcher)->fetch($url, allowPrivateNetworks: false, maxSizeKb: 25600);
})->throws(RemoteFileFetchException::class)->with([
    'file:///etc/passwd',
    'data:text/plain;base64,SGk=',
    'ftp://example.com/x.png',
    'not-a-url',
]);

it('blocks loopback / private / link-local hosts by IP', function (string $url): void {
    (new RemoteFileFetcher)->fetch($url, allowPrivateNetworks: false, maxSizeKb: 25600);
})->throws(RemoteFileFetchException::class)->with([
    'http://127.0.0.1/x.png',
    'http://10.0.0.5/x.png',
    'http://192.168.1.1/x.png',
    'http://169.254.169.254/latest/meta-data',   // cloud metadata
    'http://[::1]/x.png',
]);

it('blocks a public hostname that resolves to a private IP', function (): void {
    $fetcher = new RemoteFileFetcher(hostResolver: fn (string $host): array => ['10.1.2.3']);

    $fetcher->fetch('http://sneaky.example.com/x.png', allowPrivateNetworks: false, maxSizeKb: 25600);
})->throws(RemoteFileFetchException::class);

it('allows a private IP when private networks are explicitly permitted', function (): void {
    // Passes the guard, then fails to actually download (no HTTP fake for this
    // host) — proving the block was skipped: the reason is NOT blocked_host.
    Storage::fake('tmp-for-tests');
    $fetcher = new RemoteFileFetcher(hostResolver: fn (string $host): array => ['10.1.2.3']);

    try {
        $fetcher->fetch('http://internal.example.com/x.png', allowPrivateNetworks: true, maxSizeKb: 25600);
        $this->fail('Expected an exception.');
    } catch (RemoteFileFetchException $e) {
        expect($e->reason())->not->toBe('filament-multi-source-upload::multi-source-file-upload.reason_blocked_host');
    }
});

it('downloads a URL into a TemporaryUploadedFile with correct metadata', function (): void {
    Storage::fake('tmp-for-tests');
    $png = fetcherTestPng();
    Http::fake(['https://cdn.example.test/*' => Http::response($png, 200, [
        'Content-Type' => 'image/png',
        'Content-Length' => (string) strlen($png),
    ])]);

    $file = (new RemoteFileFetcher(hostResolver: fn () => ['93.184.216.34']))
        ->fetch('https://cdn.example.test/logo.png', allowPrivateNetworks: false, maxSizeKb: 25600);

    expect($file->getClientOriginalName())->toBe('logo.png')
        ->and($file->getMimeType())->toBe('image/png')
        ->and($file->getSize())->toBe(strlen($png))
        ->and($file->get())->toBe($png);
});

it('rejects a file larger than the cap (by streamed bytes)', function (): void {
    Storage::fake('tmp-for-tests');
    Http::fake(['https://cdn.example.test/*' => Http::response(str_repeat('A', 3000), 200, [
        'Content-Type' => 'image/png',
    ])]);

    (new RemoteFileFetcher(hostResolver: fn () => ['93.184.216.34']))
        ->fetch('https://cdn.example.test/big.png', allowPrivateNetworks: false, maxSizeKb: 2); // 2 KB cap
})->throws(RemoteFileFetchException::class);

it('rejects when the declared Content-Length exceeds the cap', function (): void {
    Storage::fake('tmp-for-tests');
    Http::fake(['https://cdn.example.test/*' => Http::response('x', 200, [
        'Content-Type' => 'image/png',
        'Content-Length' => (string) (5 * 1024 * 1024),
    ])]);

    (new RemoteFileFetcher(hostResolver: fn () => ['93.184.216.34']))
        ->fetch('https://cdn.example.test/big.png', allowPrivateNetworks: false, maxSizeKb: 1024);
})->throws(RemoteFileFetchException::class);

it('derives an extension from the mime type when the URL path has none', function (): void {
    Storage::fake('tmp-for-tests');
    Http::fake(['https://cdn.example.test/*' => Http::response(fetcherTestPng(), 200, [
        'Content-Type' => 'image/png',
    ])]);

    $file = (new RemoteFileFetcher(hostResolver: fn () => ['93.184.216.34']))
        ->fetch('https://cdn.example.test/download', allowPrivateNetworks: false, maxSizeKb: 25600);

    expect($file->getClientOriginalExtension())->toBe('png');
});
