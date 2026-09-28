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

/** Resolve the field out of a freshly mounted test form. */
function multiSourceField(array $params = []): MultiSourceFileUpload
{
    /** @var MultiSourceFileUpload $field */
    $field = Livewire::test(MultiSourceUploadTestForm::class, $params)
        ->instance()
        ->form
        ->getComponent('logo-upload');

    return $field;
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

it('fetches a URL server-side and returns its bytes as a data URL with metadata', function (): void {
    Http::fake(['https://cdn.example.test/*' => fn () => Http::response(tinyPng(), 200, ['Content-Type' => 'image/png'])]);

    // This is the whole server-side contract now: fetch (SSRF/size guarded) and
    // hand the bytes back to the browser, which rebuilds a File and feeds it to
    // FilePond exactly like a local upload.
    $result = multiSourceField()->fetchRemoteFile('https://cdn.example.test/logo.png');

    expect($result)->toHaveKeys(['name', 'type', 'size', 'dataUrl'])
        ->and($result['type'])->toBe('image/png')
        ->and($result['size'])->toBe(strlen(tinyPng()))
        ->and($result['dataUrl'])->toStartWith('data:image/png;base64,')
        ->and(base64_decode(substr($result['dataUrl'], strlen('data:image/png;base64,'))))->toBe(tinyPng());
});

it('returns an error (not an exception) when the download fails', function (): void {
    // A non-http(s) scheme is rejected up front by the SSRF guard.
    $result = multiSourceField()->fetchRemoteFile('ftp://cdn.example.test/logo.png');

    expect($result)->toHaveKey('error')
        ->and($result)->not->toHaveKey('dataUrl')
        ->and($result['error'])->toBeString()->not->toBe('');
});

it('returns an error for an empty URL', function (): void {
    $result = multiSourceField()->fetchRemoteFile('   ');

    expect($result)->toHaveKey('error')
        ->and($result)->not->toHaveKey('dataUrl');
});

it('renders the source switch and drives FilePond from the URL pane', function (): void {
    $html = Livewire::test(MultiSourceUploadTestForm::class)
        ->assertSee('data-msu-tabs', escape: false)
        ->assertSee('From URL')
        ->html();

    expect($html)->toContain('fi-msu-switch')
        // The switch is an after-label component: Filament's own label row
        // stays, and so do the field's hint and hint actions beside it.
        ->and($html)->toContain('fi-fo-field-label-col')
        ->and($html)->toContain('Logo')
        ->and($html)->toContain('Square, please')
        ->and($html)->toContain('Look it up')
        ->and($html)->not->toContain('fi-msu-header')
        ->and(substr_count($html, 'fi-fo-field-label-content'))->toBe(1)
        ->and($html)->toContain('$dispatch(\'msu-tab\', { key: \'form.logo-upload\', tab: \'url\' })')
        ->and($html)->toContain('x-on:msu-tab.window="followSwitch($event)"')
        // Both panes live inside the field wrapper, below the label row —
        // so the row (and the switch in it) survives showing the URL pane.
        ->and(preg_match('/data-field-wrapper[\s\S]*fi-msu-switch[\s\S]*data-msu-tabs[\s\S]*wire:ignore/', $html))->toBe(1)
        ->and(strpos($html, 'data-field-wrapper'))->toBeLessThan(strpos($html, 'data-msu-tabs'))
        // The import button no longer lives in a companion Livewire state; it
        // fetches server-side then hands the file to FilePond's own pipeline.
        ->and($html)->not->toContain('logo_path__url')
        ->and($html)->toContain('pond.addFile')
        // @js() must compile to the real key (it does inside a plain element's
        // x-data — unlike inside a Blade component attribute). Guard the regression.
        ->and($html)->not->toContain('@js(')
        ->and($html)->toContain("callSchemaComponentMethod('form.logo-upload', 'fetchRemoteFile'");
});

it('imports a link or image dropped in from another browser tab', function (): void {
    $html = Livewire::test(MultiSourceUploadTestForm::class)->html();

    // The drop is intercepted in the capture phase, before FilePond's own
    // listener takes the (often extension-less, mistyped) File the browser
    // attaches next to the URL; only http(s) links from text/uri-list count.
    expect($html)->toContain('x-on:drop.capture="importDrop($event)"')
        ->and($html)->toContain("includes('text/uri-list')")
        ->and($html)->toContain('event.stopPropagation()')
        ->and($html)->toContain('this.importFromUrl()');
});

it('renders a plain FileUpload (no tabs) when url import is disabled', function (): void {
    Livewire::test(MultiSourceUploadTestForm::class, ['urlImport' => false])
        ->assertDontSee('data-msu-tabs', escape: false)
        ->assertDontSee('From URL');
});
