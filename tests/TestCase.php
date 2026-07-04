<?php

declare(strict_types=1);

namespace Happenv\FilamentMultiSourceUpload\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Happenv\FilamentMultiSourceUpload\FilamentMultiSourceUploadServiceProvider;
use Happenv\FilamentMultiSourceUpload\Tests\Fixtures\TestPanelProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\DataStore;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fail fast on any HTTP call that a test forgot to fake, instead of
        // hitting the network (the RemoteFileFetcher tests rely on this).
        Http::preventStrayRequests();

        // Livewire seeds a component's error bag from the view-shared `errors`
        // bag, which the web middleware group normally provides. Bare
        // Livewire::test() runs without that middleware, so share an empty one.
        $this->app['view']->share('errors', new ViewErrorBag);

        Filament::setCurrentPanel('admin');
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            InfolistsServiceProvider::class,
            WidgetsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            FilamentServiceProvider::class,
            FilamentMultiSourceUploadServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('msu-test-key-32b', 2)));
        $app['config']->set('filesystems.default', 'local');

        // Livewire binds its mechanisms as shared container instances during
        // registration; under testbench that binding does not stick, so the
        // WeakMap-backed DataStore is re-created on every resolve and component
        // state (e.g. the validation error bag) is lost mid-render. Force it to
        // be a real singleton.
        $app->singleton(DataStore::class);
    }
}
