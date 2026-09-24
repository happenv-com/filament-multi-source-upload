<?php

declare(strict_types=1);

use Happenv\FilamentMultiSourceUpload\Support\RemoteFileFetcher;

arch()->preset()->php();

// The preset flags `tempnam()`. RemoteFileFetcher streams a download into a
// file it creates with `tempnam()` (atomically, mode 0600) and deletes it in
// `finally`; that is the safe way to get a temporary file. It is exempt from
// the preset for that one function only — every other one stays forbidden.
arch()->preset()->security()->ignoring(RemoteFileFetcher::class);

arch('the remote file fetcher uses no insecure function except tempnam()')
    ->expect(RemoteFileFetcher::class)
    ->not->toUse([
        'md5', 'sha1', 'uniqid', 'rand', 'mt_rand', 'str_shuffle', 'shuffle', 'array_rand',
        'eval', 'exec', 'shell_exec', 'system', 'passthru', 'create_function', 'unserialize',
        'extract', 'mb_parse_str', 'dl', 'assert',
    ]);

arch('no debugging calls')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
