<?php

declare(strict_types=1);

namespace Happenv\FilamentMultiSourceUpload\Support;

use Closure;
use Happenv\FilamentMultiSourceUpload\Exceptions\RemoteFileFetchException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

final class RemoteFileFetcher
{
    /** @param  (Closure(string): array<string>)|null  $hostResolver */
    public function __construct(private readonly ?Closure $hostResolver = null) {}

    /**
     * @throws RemoteFileFetchException when the URL is disallowed, unreachable, or too large
     */
    public function fetch(string $url, bool $allowPrivateNetworks, int $maxSizeKb): TemporaryUploadedFile
    {
        $this->assertAllowedUrl($url, $allowPrivateNetworks);

        $localPath = $this->download($url, $maxSizeKb * 1024);

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

    private function assertAllowedUrl(string $url, bool $allowPrivateNetworks): void
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
            return;
        }

        foreach ($this->resolveIps(trim($host, '[]')) as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw RemoteFileFetchException::blockedHost();
            }
        }
    }

    /** @return array<string> */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $resolver = $this->hostResolver ?? static fn (string $h): array => gethostbynamel($h) ?: [];
        $ips = $resolver($host);

        if ($ips === []) {
            throw RemoteFileFetchException::blockedHost();
        }

        return $ips;
    }

    private function isBlockedIp(string $ip): bool
    {
        // filter_var returns false when the IP is in a private OR reserved range
        // (covers 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7,
        // fe80::/10, 0.0.0.0, …). Invalid IPs are treated as blocked.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Stream the response into a local temp file, aborting hard once the byte
     * cap is exceeded so a hostile/oversized source cannot exhaust memory.
     */
    private function download(string $url, int $maxBytes): string
    {
        try {
            $response = Http::withOptions(['stream' => true, 'allow_redirects' => ['max' => 3]])
                ->timeout(20)
                ->get($url);
        } catch (Throwable) {
            throw RemoteFileFetchException::unreachable();
        }

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
