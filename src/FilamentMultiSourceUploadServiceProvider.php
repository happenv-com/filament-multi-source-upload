<?php

declare(strict_types=1);

namespace Happenv\FilamentMultiSourceUpload;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class FilamentMultiSourceUploadServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-multi-source-upload')
            ->hasViews()
            ->hasTranslations();
    }
}
