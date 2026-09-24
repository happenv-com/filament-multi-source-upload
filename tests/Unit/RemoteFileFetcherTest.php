<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Happenv\FilamentMultiSourceUpload\Exceptions\RemoteFileFetchException;
use Happenv\FilamentMultiSourceUpload\Support\RemoteFileFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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

    $file = new RemoteFileFetcher(hostResolver: fn (): array => ['93.184.216.34'])
        ->fetch('https://cdn.example.test/logo.png', allowPrivateNetworks: false, maxSizeKb: 25600);

    expect($file->getClientOriginalName())->toBe('logo.png')
        ->and($file->getMimeType())->toBe('image/png')
        ->and($file->getSize())->toBe(strlen($png))
        ->and($file->get())->toBe($png);
});

it('reports a temporary upload it cannot store as unreachable', function (bool $throws): void {
    // Livewire keeps test uploads on this disk. A file where its upload
    // directory should be makes every write fail: storing returns false, or
    // throws when the disk is set to.
    $root = sys_get_temp_dir() . '/msu-unwritable-' . uniqid();
    mkdir($root);
    touch("{$root}/livewire-tmp");
    config(['filesystems.disks.tmp-for-tests' => ['driver' => 'local', 'root' => $root, 'throw' => $throws]]);
    Http::fake(['https://cdn.example.test/*' => Http::response(fetcherTestPng(), 200, ['Content-Type' => 'image/png'])]);

    try {
        expect(fetcherTestReason(fn (): TemporaryUploadedFile => new RemoteFileFetcher(hostResolver: fn (): array => ['93.184.216.34'])
            ->fetch('https://cdn.example.test/logo.png', allowPrivateNetworks: false, maxSizeKb: 25600)))
            ->toBe(FETCHER_TEST_UNREACHABLE);
    } finally {
        array_map(unlink(...), glob("{$root}/*") ?: []);
        rmdir($root);
    }
})->with(['returns false' => false, 'throws' => true]);

it('rejects a file larger than the cap (by streamed bytes)', function (): void {
    Storage::fake('tmp-for-tests');
    Http::fake(['https://cdn.example.test/*' => Http::response(str_repeat('A', 3000), 200, [
        'Content-Type' => 'image/png',
    ])]);

    new RemoteFileFetcher(hostResolver: fn (): array => ['93.184.216.34'])
        ->fetch('https://cdn.example.test/big.png', allowPrivateNetworks: false, maxSizeKb: 2); // 2 KB cap
})->throws(RemoteFileFetchException::class);

it('rejects when the declared Content-Length exceeds the cap', function (): void {
    Storage::fake('tmp-for-tests');
    Http::fake(['https://cdn.example.test/*' => Http::response('x', 200, [
        'Content-Type' => 'image/png',
        'Content-Length' => (string) (5 * 1024 * 1024),
    ])]);

    new RemoteFileFetcher(hostResolver: fn (): array => ['93.184.216.34'])
        ->fetch('https://cdn.example.test/big.png', allowPrivateNetworks: false, maxSizeKb: 1024);
})->throws(RemoteFileFetchException::class);

it('derives an extension from the mime type when the URL path has none', function (): void {
    Storage::fake('tmp-for-tests');
    Http::fake(['https://cdn.example.test/*' => Http::response(fetcherTestPng(), 200, [
        'Content-Type' => 'image/png',
    ])]);

    $file = new RemoteFileFetcher(hostResolver: fn (): array => ['93.184.216.34'])
        ->fetch('https://cdn.example.test/download', allowPrivateNetworks: false, maxSizeKb: 25600);

    expect($file->getClientOriginalExtension())->toBe('png');
});

/**
 * @param  array<string, array<string>>  $hosts
 * @return Closure(string): array<string>
 */
function fetcherTestResolver(array $hosts): Closure
{
    return fn (string $host): array => $hosts[$host] ?? [];
}

function fetcherTestReason(Closure $fetch): string
{
    try {
        $fetch();
    } catch (RemoteFileFetchException $exception) {
        return $exception->reason();
    }

    throw new RuntimeException('Expected a RemoteFileFetchException.');
}

const FETCHER_TEST_BLOCKED_HOST = 'filament-multi-source-upload::multi-source-file-upload.reason_blocked_host';
const FETCHER_TEST_INVALID_SCHEME = 'filament-multi-source-upload::multi-source-file-upload.reason_invalid_scheme';
const FETCHER_TEST_UNREACHABLE = 'filament-multi-source-upload::multi-source-file-upload.reason_unreachable';

it('blocks a redirect to a private or metadata address', function (string $target, array $hosts): void {
    Http::fake(['https://cdn.example.test/*' => Http::response('', 302, ['Location' => $target])]);

    $fetcher = new RemoteFileFetcher(hostResolver: fetcherTestResolver(['cdn.example.test' => ['93.184.216.34'], ...$hosts]));

    expect(fetcherTestReason(fn (): TemporaryUploadedFile => $fetcher->fetch('https://cdn.example.test/logo.png', allowPrivateNetworks: false, maxSizeKb: 25600)))
        ->toBe(FETCHER_TEST_BLOCKED_HOST);

    // The redirect target is never requested.
    Http::assertSentCount(1);
})->with([
    'cloud metadata' => ['http://169.254.169.254/latest/meta-data', []],
    'loopback' => ['http://127.0.0.1:8080/admin', []],
    'IPv6 loopback' => ['http://[::1]/x.png', []],
    'internal host name' => ['http://intranet.example.test/x.png', ['intranet.example.test' => ['10.0.0.8']]],
]);

it('reports a host name that does not resolve as unreachable, without requesting it', function (string $url): void {
    Http::fake(['https://cdn.example.test/*' => Http::response('', 302, ['Location' => 'http://nowhere.example.test/x.png'])]);

    $fetcher = new RemoteFileFetcher(hostResolver: fetcherTestResolver(['cdn.example.test' => ['93.184.216.34']]));

    expect(fetcherTestReason(fn (): TemporaryUploadedFile => $fetcher->fetch($url, allowPrivateNetworks: false, maxSizeKb: 25600)))
        ->toBe(FETCHER_TEST_UNREACHABLE);

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'nowhere.example.test'));
})->with([
    'entered' => ['http://nowhere.example.test/x.png'],
    'redirected to' => ['https://cdn.example.test/logo.png'],
]);

it('blocks a redirect to a non-http scheme', function (): void {
    Http::fake(['https://cdn.example.test/*' => Http::response('', 301, ['Location' => 'file:///etc/passwd'])]);

    $fetcher = new RemoteFileFetcher(hostResolver: fetcherTestResolver(['cdn.example.test' => ['93.184.216.34']]));

    expect(fetcherTestReason(fn (): TemporaryUploadedFile => $fetcher->fetch('https://cdn.example.test/logo.png', allowPrivateNetworks: false, maxSizeKb: 25600)))
        ->toBe(FETCHER_TEST_INVALID_SCHEME);

    Http::assertSentCount(1);
});

it('follows redirects between public hosts, relative ones included', function (): void {
    Storage::fake('tmp-for-tests');
    $png = fetcherTestPng();
    Http::fake([
        'https://short.example.test/*' => Http::response('', 301, ['Location' => 'https://cdn.example.test/assets/logo']),
        'https://cdn.example.test/assets/logo' => Http::response('', 302, ['Location' => '/assets/logo.png']),
        'https://cdn.example.test/assets/logo.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
    ]);

    $fetcher = new RemoteFileFetcher(hostResolver: fetcherTestResolver([
        'short.example.test' => ['93.184.216.34'],
        'cdn.example.test' => ['93.184.216.35'],
    ]));

    $file = $fetcher->fetch('https://short.example.test/logo.png', allowPrivateNetworks: false, maxSizeKb: 25600);

    expect($file->get())->toBe($png)
        // The name still comes from the URL the user entered.
        ->and($file->getClientOriginalName())->toBe('logo.png');

    Http::assertSentCount(3);
});

it('gives up after three redirects', function (): void {
    Http::fake(['https://cdn.example.test/*' => Http::response('', 302, ['Location' => 'https://cdn.example.test/again'])]);

    $fetcher = new RemoteFileFetcher(hostResolver: fetcherTestResolver(['cdn.example.test' => ['93.184.216.34']]));

    expect(fetcherTestReason(fn (): TemporaryUploadedFile => $fetcher->fetch('https://cdn.example.test/logo.png', allowPrivateNetworks: false, maxSizeKb: 25600)))
        ->toBe(FETCHER_TEST_UNREACHABLE);

    // The original request and three redirects.
    Http::assertSentCount(4);
});

it('follows a redirect to a private address when private networks are allowed', function (): void {
    Storage::fake('tmp-for-tests');
    Http::fake([
        'https://cdn.example.test/*' => Http::response('', 302, ['Location' => 'http://10.0.0.8/logo.png']),
        'http://10.0.0.8/*' => Http::response(fetcherTestPng(), 200, ['Content-Type' => 'image/png']),
    ]);

    $file = new RemoteFileFetcher(hostResolver: fetcherTestResolver([]))
        ->fetch('https://cdn.example.test/logo.png', allowPrivateNetworks: true, maxSizeKb: 25600);

    expect($file->get())->toBe(fetcherTestPng());
});

it('connects to the address it checked instead of resolving the host again', function (): void {
    Storage::fake('tmp-for-tests');

    // DNS rebinding: a public address for the check, a private one for any
    // later lookup.
    $lookups = 0;
    $resolver = function (string $host) use (&$lookups): array {
        $lookups++;

        return $lookups === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
    };

    $pins = [];
    Http::fake(function ($request, array $options) use (&$pins) {
        $pins[] = $options['curl'][CURLOPT_CONNECT_TO] ?? null;

        return Http::response(fetcherTestPng(), 200, ['Content-Type' => 'image/png']);
    });

    new RemoteFileFetcher(hostResolver: $resolver)
        ->fetch('https://rebind.example.test/logo.png', allowPrivateNetworks: false, maxSizeKb: 25600);

    expect($lookups)->toBe(1)
        ->and($pins)->toBe([['::93.184.216.34:']]);
});

it('pins every redirect hop to its own checked address', function (): void {
    Storage::fake('tmp-for-tests');

    $pins = [];
    Http::fake(function ($request, array $options) use (&$pins) {
        $pins[] = $options['curl'][CURLOPT_CONNECT_TO] ?? null;

        return count($pins) === 1
            ? Http::response('', 302, ['Location' => 'http://[2606:4700::1111]/logo.png'])
            : Http::response(fetcherTestPng(), 200, ['Content-Type' => 'image/png']);
    });

    new RemoteFileFetcher(hostResolver: fetcherTestResolver(['cdn.example.test' => ['93.184.216.34']]))
        ->fetch('https://cdn.example.test/logo.png', allowPrivateNetworks: false, maxSizeKb: 25600);

    expect($pins)->toBe([['::93.184.216.34:'], ['::[2606:4700::1111]:']]);
});

it('does not pin the connection when private networks are allowed', function (): void {
    Storage::fake('tmp-for-tests');

    $options = null;
    Http::fake(function ($request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(fetcherTestPng(), 200, ['Content-Type' => 'image/png']);
    });

    new RemoteFileFetcher(hostResolver: fetcherTestResolver([]))
        ->fetch('http://intranet.example.test/logo.png', allowPrivateNetworks: true, maxSizeKb: 25600);

    expect($options['curl'] ?? [])->not->toHaveKey(CURLOPT_CONNECT_TO);
});

it('sends every request through the curl handler, where the pin and the size cap apply', function (): void {
    // A `stream` request goes to Guzzle's PHP stream handler, which ignores
    // curl options: Guzzle 7 silently drops the pin, Guzzle 8 refuses the request.
    Storage::fake('tmp-for-tests');

    $options = [];
    Http::fake(function ($request, array $requestOptions) use (&$options) {
        $options[] = $requestOptions;

        return count($options) === 1
            ? Http::response('', 302, ['Location' => 'https://cdn.example.test/logo.png'])
            : Http::response(fetcherTestPng(), 200, ['Content-Type' => 'image/png']);
    });

    new RemoteFileFetcher(hostResolver: fetcherTestResolver(['cdn.example.test' => ['93.184.216.34']]))
        ->fetch('https://cdn.example.test/logo', allowPrivateNetworks: false, maxSizeKb: 2);

    expect($options)->toHaveCount(2)->each(function ($option): void {
        $option->not->toHaveKey('stream')
            ->and($option->value['sink'])->toBeString()
            ->and($option->value['curl'][CURLOPT_CONNECT_TO])->toBe(['::93.184.216.34:']);
    });

    // Guzzle 8 aborts when `progress` returns true and refuses curl's own
    // progress options; Guzzle 7 ignores what `progress` returns.
    if (version_compare((string) InstalledVersions::getVersion('guzzlehttp/guzzle'), '8.0.0.0-dev', '>=')) {
        expect($options[0]['curl'])->not->toHaveKey(CURLOPT_NOPROGRESS);
        $abort = fn (int $total, int $received): bool => $options[0]['progress']($total, $received, 0, 0);
    } else {
        expect($options[0])->not->toHaveKey('progress')
            ->and($options[0]['curl'][CURLOPT_NOPROGRESS])->toBeFalse();
        $abort = fn (int $total, int $received): bool => $options[0]['curl'][CURLOPT_XFERINFOFUNCTION](null, $total, $received, 0, 0) === 1;
    }

    expect($abort(0, 2048))->toBeFalse()
        ->and($abort(0, 2049))->toBeTrue()
        ->and($abort(2049, 0))->toBeTrue();
});

it('blocks addresses outside the public internet that PHP does not flag', function (string $ip): void {
    $fetcher = new RemoteFileFetcher(hostResolver: fetcherTestResolver(['host.example.test' => [$ip]]));

    expect(fetcherTestReason(fn (): TemporaryUploadedFile => $fetcher->fetch('http://host.example.test/x.png', allowPrivateNetworks: false, maxSizeKb: 25600)))
        ->toBe(FETCHER_TEST_BLOCKED_HOST);
})->with([
    'Alibaba Cloud metadata (CGNAT)' => '100.100.100.200',
    'CGNAT' => '100.64.0.1',
    'IETF protocol assignments' => '192.0.0.192',
    'benchmarking' => '198.18.0.1',
    'multicast' => '224.0.0.1',
    'NAT64 of 169.254.169.254' => '64:ff9b::a9fe:a9fe',
    'local-use NAT64' => '64:ff9b:1::a00:1',
    'Teredo' => '2001:0:4136:e378:8000:63bf:3fff:fdd2',
    '6to4 of 127.0.0.1' => '2002:7f00:1::1',
    'IPv6 site-local' => 'fec0::1',
    'IPv6 multicast' => 'ff02::1',
]);

it('blocks unspecified, IPv4-mapped and numeric forms of private addresses', function (string $url): void {
    // The default resolver: gethostbynamel() parses numeric host forms itself.
    expect(fetcherTestReason(fn (): TemporaryUploadedFile => new RemoteFileFetcher()->fetch($url, allowPrivateNetworks: false, maxSizeKb: 25600)))
        ->toBe(FETCHER_TEST_BLOCKED_HOST);

    Http::assertNothingSent();
})->with([
    'http://0.0.0.0/x.png',
    'http://[::]/x.png',
    'http://[::ffff:127.0.0.1]/x.png',
    'http://[::ffff:7f00:1]/x.png',
    'http://[::ffff:169.254.169.254]/x.png',
    'http://2130706433/x.png',
    'http://0177.0.0.1/x.png',
    'http://0x7f.0.0.1/x.png',
    'http://127.1/x.png',
]);

it('reads numeric IPv4 forms itself, whatever the resolver makes of them', function (string $url): void {
    // glibc's resolver does not resolve hex parts: without reading them itself the
    // fetcher would report a loopback address as unreachable instead of blocked.
    $fetcher = new RemoteFileFetcher(hostResolver: fn (string $host): array => []);

    expect(fetcherTestReason(fn (): TemporaryUploadedFile => $fetcher->fetch($url, allowPrivateNetworks: false, maxSizeKb: 25600)))
        ->toBe(FETCHER_TEST_BLOCKED_HOST);

    Http::assertNothingSent();
})->with([
    'http://0x7f.0.0.1/x.png',
    'http://0x7f000001/x.png',
    'http://0x7f.1/x.png',
    'http://0177.0.0.1/x.png',
    'http://2130706433/x.png',
    'http://127.1/x.png',
    'http://10.0x10.1/x.png',
    'http://0xa9.0xfe.0xa9.0xfe/latest/meta-data',
]);

it('leaves host names that only look numeric to the resolver', function (string $url): void {
    $fetcher = new RemoteFileFetcher(hostResolver: fn (string $host): array => []);

    expect(fetcherTestReason(fn (): TemporaryUploadedFile => $fetcher->fetch($url, allowPrivateNetworks: false, maxSizeKb: 25600)))
        ->toBe(FETCHER_TEST_UNREACHABLE);
})->with([
    'too many parts' => 'http://1.2.3.4.5/x.png',
    'part over a byte' => 'http://256.1.1.1/x.png',
    'last part too large' => 'http://1.2.3.256/x.png',
    'number over 32 bits' => 'http://4294967296/x.png',
    'bad octal digit' => 'http://08.0.0.1/x.png',
    'letters' => 'http://0x7g.0.0.1/x.png',
]);
