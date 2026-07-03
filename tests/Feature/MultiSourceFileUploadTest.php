<?php

declare(strict_types=1);

use Happenv\FilamentMultiSourceUpload\MultiSourceFileUpload;
use Happenv\FilamentMultiSourceUpload\Support\RemoteFileFetcher;
use Happenv\FilamentMultiSourceUpload\Tests\Fixtures\MultiSourceUploadTestForm;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

covers(MultiSourceFileUpload::class);

/** Real 1×1 transparent PNG, so mime sniffing yields image/png. */
function tinyPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    );
}

beforeEach(function (): void {
    // Resolve the test host to a fixed public IP so the SSRF guard passes
    // without real DNS; the actual download is intercepted by Http::fake().
    app()->bind(RemoteFileFetcher::class, fn (): RemoteFileFetcher => new RemoteFileFetcher(
        hostResolver: fn (): array => ['93.184.216.34'],
    ));

    Storage::fake('public');
    Storage::fake('tmp-for-tests');
});

it('imports a URL and stores the file on the target disk as a path (not a URL)', function (): void {
    Http::fake(['https://cdn.example.test/*' => fn () => Http::response(tinyPng(), 200, ['Content-Type' => 'image/png'])]);

    $component = Livewire::test(MultiSourceUploadTestForm::class)
        ->call('callSchemaComponentMethod', 'form.logo-upload', 'importFromUrl', ['url' => 'https://cdn.example.test/logo.png'])
        ->call('save');

    $stored = $component->get('saved')['logo_path'];
    $path = is_array($stored) ? array_values($stored)[0] : $stored;

    expect($path)->toBeString()
        ->and($path)->toStartWith('logos/')
        ->and($path)->not->toContain('http')
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('replaces the file in single mode and appends in multiple mode', function (bool $multiple, int $expected): void {
    Http::fake(['https://cdn.example.test/*' => fn () => Http::response(tinyPng(), 200, ['Content-Type' => 'image/png'])]);

    $component = Livewire::test(MultiSourceUploadTestForm::class, ['multiple' => $multiple])
        ->call('callSchemaComponentMethod', 'form.logo-upload', 'importFromUrl', ['url' => 'https://cdn.example.test/a.png'])
        ->call('callSchemaComponentMethod', 'form.logo-upload', 'importFromUrl', ['url' => 'https://cdn.example.test/b.png'])
        ->call('save');

    expect(count((array) $component->get('saved')['logo_path']))->toBe($expected);
})->with([
    'single replaces' => [false, 1],
    'multiple appends' => [true, 2],
]);

it('rejects a non-image URL, notifies, and leaves state empty', function (): void {
    Http::fake(['https://cdn.example.test/*' => fn () => Http::response('just plain text', 200, ['Content-Type' => 'text/plain'])]);

    $component = Livewire::test(MultiSourceUploadTestForm::class)
        ->call('callSchemaComponentMethod', 'form.logo-upload', 'importFromUrl', ['url' => 'https://cdn.example.test/evil.txt'])
        ->assertNotified()
        ->call('save');

    expect($component->get('saved')['logo_path'] ?? [])->toBeEmpty();
});

it('exposes a preview payload for a freshly imported (unsaved) temp file', function (): void {
    Http::fake(['https://cdn.example.test/*' => fn () => Http::response(tinyPng(), 200, ['Content-Type' => 'image/png'])]);

    $test = Livewire::test(MultiSourceUploadTestForm::class)
        ->call('callSchemaComponentMethod', 'form.logo-upload', 'importFromUrl', ['url' => 'https://cdn.example.test/logo.png']);

    /** @var MultiSourceFileUpload $field */
    $field = $test->instance()->form->getComponent('logo-upload');
    $uploaded = $field->getUploadedFiles();

    expect($uploaded)->toBeArray()->not->toBeEmpty();

    $entry = array_values($uploaded)[0];

    expect($entry)->not->toBeNull()
        ->and($entry['type'])->toBe('image/png')
        ->and($entry['url'])->toBeString();
});

it('renders the tabbed UI with a URL tab when url import is enabled', function (): void {
    $html = Livewire::test(MultiSourceUploadTestForm::class)
        ->assertSee('data-msu-tabs', escape: false)
        ->assertSee('From URL')
        ->html();

    // The Alpine wiring must reach the DOM: the import button triggers the
    // exposed importFromUrl method and the URL field binds to Alpine state.
    expect($html)->toContain('importFromUrl')
        ->and($html)->toContain('x-model="url"');
});

it('renders a plain FileUpload (no tabs) when url import is disabled', function (): void {
    Livewire::test(MultiSourceUploadTestForm::class, ['urlImport' => false])
        ->assertDontSee('data-msu-tabs', escape: false)
        ->assertDontSee('From URL');
});
