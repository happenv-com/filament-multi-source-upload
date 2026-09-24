<?php

declare(strict_types=1);

use Happenv\FilamentMultiSourceUpload\Support\RemoteFileFetcher;

arch()->preset()->php();

// The preset flags `tempnam()`. RemoteFileFetcher streams a download into a
// file it creates with `tempnam()` (atomically, mode 0600) and deletes it in
// `finally`; that is the safe way to get a temporary file.
arch()->preset()->security()->ignoring(RemoteFileFetcher::class);

arch('no debugging calls')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
