<?php

declare(strict_types=1);

namespace Happenv\FilamentMultiSourceUpload\Support;

use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Happenv\FilamentMultiSourceUpload\Exceptions\RemoteFileFetchException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

final readonly class RemoteFileFetcher
{
    private const int MAX_REDIRECTS = 3;

    /**
     * Ranges PHP's FILTER_FLAG_NO_PRIV_RANGE / FILTER_FLAG_NO_RES_RANGE let
     * through although they are not the public internet, or embed an IPv4
     * address that may be private.
     */
    private const array BLOCKED_RANGES = [
        '100.64.0.0/10',   // shared address space / CGNAT (RFC 6598); Alibaba Cloud metadata is 100.100.100.200
        '192.0.0.0/24',    // IETF protocol assignments (RFC 6890)
        '198.18.0.0/15',   // benchmarking (RFC 2544)
        '224.0.0.0/4',     // multicast
        '64:ff9b::/96',    // NAT64 (RFC 6052): 64:ff9b::a9fe:a9fe reaches 169.254.169.254
        '64:ff9b:1::/48',  // local-use NAT64 (RFC 8215)
        '2001::/32',       // Teredo, embeds an IPv4 address
        '2002::/16',       // 6to4, embeds an IPv4 address
        'fec0::/10',       // deprecated site-local
        'ff00::/8',        // multicast
    ];

    /** @param  (Closure(string): array<string>)|null  $hostResolver */
    public function __construct(private ?Closure $hostResolver = null) {}

    /**
     * @throws RemoteFileFetchException when the URL is disallowed, unreachable, or too large
     */
    public function fetch(string $url, bool $allowPrivateNetworks, int $maxSizeKb): TemporaryUploadedFile
    {
        $localPath = $this->download($url, $allowPrivateNetworks, $maxSizeKb * 1024);

        try {
            $mime = $this->detectMime($localPath);
            $name = $this->deriveFilename($url, $mime);

            $uploaded = new UploadedFile($localPath, $name, $mime, test: true);

            $storedPath = FileUploadConfiguration::storeTemporaryFile(
                $uploaded,
                FileUploadConfiguration::disk(),
            );

            return TemporaryUploadedFile::createFromLivewire(basename($storedPath));
        } finally {
            @unlink($localPath);
        }
    }

    /**
     * Validate a URL — the one the user entered, and every redirect target —
     * and return the address the request must connect to: the one that was
     * checked, so a second DNS lookup cannot swap it for a private one (DNS
     * rebinding). `null` when private networks are allowed and nothing is
     * checked.
     */
    private function assertAllowedUrl(string $url, bool $allowPrivateNetworks): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw RemoteFileFetchException::invalidScheme();
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], strict: true)) {
            throw RemoteFileFetchException::invalidScheme();
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw RemoteFileFetchException::invalidScheme();
        }

        if ($allowPrivateNetworks) {
            return null;
        }

        $ips = $this->resolveIps(trim($host, '[]'));

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw RemoteFileFetchException::blockedHost();
            }
        }

        return $ips[0];
    }

    /** @return array<string> */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $resolver = $this->hostResolver ?? static fn (string $h): array => gethostbynamel($h) ?: [];
        $ips = $resolver($host);

        // A name that does not resolve is refused before any request is sent,
        // but it is a typo or a dead domain, not an address we block.
        if ($ips === []) {
            throw RemoteFileFetchException::unreachable();
        }

        return $ips;
    }

    private function isBlockedIp(string $ip): bool
    {
        // filter_var returns false when the IP is in a private OR reserved range
        // (covers 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7,
        // fe80::/10, 0.0.0.0, IPv4-mapped IPv6, …). Invalid IPs are treated as blocked.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        return IpUtils::checkIp($ip, self::BLOCKED_RANGES);
    }

    /**
     * Send the GET, following up to MAX_REDIRECTS redirects by hand: every
     * target passes the same scheme and address checks as the URL the user
     * entered, and every request is pinned to the address that was checked.
     */
    private function request(string $url, bool $allowPrivateNetworks): Response
    {
        for ($redirects = 0; ; $redirects++) {
            $pin = $this->pinConnection($this->assertAllowedUrl($url, $allowPrivateNetworks));

            try {
                $response = Http::withOptions([
                    'stream' => true,
                    'allow_redirects' => false,
                    ...$pin,
                ])
                    ->timeout(20)
                    ->get($url);
            } catch (Throwable) {
                throw RemoteFileFetchException::unreachable();
            }

            $location = $response->header('Location');

            if (! $response->redirect() || $location === '') {
                return $response;
            }

            $response->toPsrResponse()->getBody()->close();

            if ($redirects >= self::MAX_REDIRECTS) {
                throw RemoteFileFetchException::unreachable();
            }

            try {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
            } catch (Throwable) {
                throw RemoteFileFetchException::unreachable();
            }
        }
    }

    /**
     * Make curl connect to exactly the address that was checked, whatever host
     * it reads from the URL, instead of resolving the host again. The URL is
     * unchanged, so the Host header, TLS SNI and certificate verification still
     * use the host name.
     *
     * @return array<string, mixed>
     */
    private function pinConnection(?string $ip): array
    {
        if ($ip === null) {
            return [];
        }

        // Guzzle falls back to PHP streams without ext-curl, where the
        // connection cannot be pinned. Refuse rather than fetch unprotected.
        if (! function_exists('curl_exec') || ! function_exists('curl_multi_exec')) {
            throw RemoteFileFetchException::unreachable();
        }

        $host = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? $ip : "[{$ip}]";

        // HOST:PORT:CONNECT-TO-HOST:CONNECT-TO-PORT; empty fields match any
        // host and keep the URL's port.
        return ['curl' => [CURLOPT_CONNECT_TO => ["::{$host}:"]]];
    }

    /**
     * Stream the response into a local temp file, aborting hard once the byte
     * cap is exceeded so a hostile/oversized source cannot exhaust memory.
     */
    private function download(string $url, bool $allowPrivateNetworks, int $maxBytes): string
    {
        $response = $this->request($url, $allowPrivateNetworks);

        if (! $response->successful()) {
            throw RemoteFileFetchException::unreachable();
        }

        $declared = $response->header('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $maxBytes) {
            throw RemoteFileFetchException::tooLarge();
        }

        $localPath = tempnam(sys_get_temp_dir(), 'msu_');
        if ($localPath === false) {
            throw RemoteFileFetchException::unreachable();
        }

        $handle = fopen($localPath, 'wb');
        if ($handle === false) {
            @unlink($localPath);

            throw RemoteFileFetchException::unreachable();
        }

        $stream = $response->toPsrResponse()->getBody();
        $written = 0;

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }

                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw RemoteFileFetchException::tooLarge();
                }

                fwrite($handle, $chunk);
            }
        } catch (RemoteFileFetchException $e) {
            fclose($handle);
            @unlink($localPath);

            throw $e;
        } catch (Throwable) {
            fclose($handle);
            @unlink($localPath);

            throw RemoteFileFetchException::unreachable();
        }

        fclose($handle);

        return $localPath;
    }

    private function detectMime(string $localPath): string
    {
        return (new MimeTypes)->guessMimeType($localPath) ?? 'application/octet-stream';
    }

    private function deriveFilename(string $url, string $mime): string
    {
        $basename = trim(basename((string) parse_url($url, PHP_URL_PATH)));

        if ($basename !== '' && str_contains($basename, '.')) {
            return Str::limit($basename, 200, '');
        }

        $extension = (new MimeTypes)->getExtensions($mime)[0] ?? 'bin';
        $stem = $basename !== '' ? Str::slug($basename) : 'download';

        return "{$stem}.{$extension}";
    }
}
