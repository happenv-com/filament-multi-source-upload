<?php

declare(strict_types=1);

use Filament\Forms\Components\FileUpload;
use Happenv\FilamentMultiSourceUpload\MultiSourceFileUpload;

it('registers the component as a FileUpload subclass', function (): void {
    $field = MultiSourceFileUpload::make('logo_path');

    expect($field)->toBeInstanceOf(FileUpload::class);
});

it('resolves the package translation namespace', function (): void {
    expect(__('filament-multi-source-upload::multi-source-file-upload.url_tab'))
        ->toBe('From URL');
});
