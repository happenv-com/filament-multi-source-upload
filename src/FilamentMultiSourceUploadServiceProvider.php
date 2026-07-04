<?php

declare(strict_types=1);

namespace Happenv\FilamentMultiSourceUpload;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
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

    public function packageBooted(): void
    {
        FilamentAsset::register([
            Css::make(
                'filament-multi-source-upload',
                __DIR__.'/../resources/dist/filament-multi-source-upload.css',
            ),
        ], package: 'happenv-com/filament-multi-source-upload');
    }
}
