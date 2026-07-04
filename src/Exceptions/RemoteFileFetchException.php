<?php

declare(strict_types=1);

namespace Happenv\FilamentMultiSourceUpload\Exceptions;

use RuntimeException;

final class RemoteFileFetchException extends RuntimeException
{
    private const PREFIX = 'filament-multi-source-upload::multi-source-file-upload.';

    private function __construct(private readonly string $reasonKey, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidScheme(): self
    {
        return new self(self::PREFIX.'reason_invalid_scheme', 'Only http and https URLs are allowed.');
    }

    public static function blockedHost(): self
    {
        return new self(self::PREFIX.'reason_blocked_host', 'The host resolves to a blocked (private/reserved) address.');
    }

    public static function tooLarge(): self
    {
        return new self(self::PREFIX.'reason_too_large', 'The remote file exceeds the maximum allowed size.');
    }

    public static function unreachable(): self
    {
        return new self(self::PREFIX.'reason_unreachable', 'The remote file could not be downloaded.');
    }

    public static function invalidType(string $mime): self
    {
        return new self(self::PREFIX.'reason_invalid_type', "The remote file type [{$mime}] is not accepted.");
    }

    public function reason(): string
    {
        return $this->reasonKey;
    }

    public function translatedReason(): string
    {
        return __($this->reasonKey);
    }
}
