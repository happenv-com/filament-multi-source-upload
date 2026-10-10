<?php

declare(strict_types=1);

namespace Happenv\FilamentMultiSourceUpload;

use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\View;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Enums\VerticalAlignment;
use Happenv\FilamentMultiSourceUpload\Exceptions\RemoteFileFetchException;
use Happenv\FilamentMultiSourceUpload\Support\RemoteFileFetcher;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use League\Flysystem\UnableToCheckFileExistence;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class MultiSourceFileUpload extends FileUpload
{
    protected bool | Closure $hasUrlImport = true;

    protected bool | Closure $allowPrivateNetworks = false;

    protected int | Closure | null $maxUrlImportSize = null;

    protected string | Closure | null $urlTabLabel = null;

    protected string | Closure | null $fileTabLabel = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The source switch joins Filament's own label row, after the hint,
        // hint icon and hint actions the field already renders there — so a
        // `->hint()` or `->hintAction()` on the field keeps its place and the
        // label stays the wrapper's (accessible, inline-label aware). The
        // switch talks to the panes below through a `msu-tab` event keyed by
        // the field, since the two live in different Alpine scopes.
        $hints = $this->childComponents[static::AFTER_LABEL_SCHEMA_KEY] ?? [];

        $this->afterLabel(fn (MultiSourceFileUpload $component): array => [
            ...($component->evaluate($hints) ?? []),
            ...($component->hasUrlImport() ? [$component->makeSourceSwitch()] : []),
        ]);
    }

    protected function makeSourceSwitch(): View
    {
        return View::make('filament-multi-source-upload::components.source-switch')
            ->viewData([
                'key' => $this->getKey(),
                'fileTabLabel' => $this->getFileTabLabel(),
                'urlTabLabel' => $this->getUrlTabLabel(),
            ]);
    }

    public function urlImport(bool | Closure $condition = true): static
    {
        $this->hasUrlImport = $condition;

        return $this;
    }

    public function allowPrivateNetworks(bool | Closure $condition = true): static
    {
        $this->allowPrivateNetworks = $condition;

        return $this;
    }

    public function maxUrlImportSize(int | Closure | null $kilobytes): static
    {
        $this->maxUrlImportSize = $kilobytes;

        return $this;
    }

    public function urlTabLabel(string | Closure | null $label): static
    {
        $this->urlTabLabel = $label;

        return $this;
    }

    public function fileTabLabel(string | Closure | null $label): static
    {
        $this->fileTabLabel = $label;

        return $this;
    }

    public function hasUrlImport(): bool
    {
        return (bool) $this->evaluate($this->hasUrlImport);
    }

    public function allowsPrivateNetworks(): bool
    {
        return (bool) $this->evaluate($this->allowPrivateNetworks);
    }

    public function getEffectiveMaxUrlImportSize(): int
    {
        return $this->evaluate($this->maxUrlImportSize)
            ?? $this->getMaxSize()
            ?? 25600;
    }

    public function getFileTabLabel(): string
    {
        return $this->evaluate($this->fileTabLabel)
            ?? __('filament-multi-source-upload::multi-source-file-upload.file_tab');
    }

    public function getUrlTabLabel(): string
    {
        return $this->evaluate($this->urlTabLabel)
            ?? __('filament-multi-source-upload::multi-source-file-upload.url_tab');
    }

    /**
     * The file pane is the field's own content; the URL pane joins it here,
     * inside the field wrapper, so the label row (label, hints, hint actions
     * and the source switch) stays put whichever pane is showing.
     *
     * @internal Filament marks wrapEmbeddedHtml() internal; it is the one
     * seam between a field's content and its wrapper, hence overridden here.
     *
     * @param  array<string, mixed>  $extraWrapperAttributes
     */
    public function wrapEmbeddedHtml(
        string $html,
        array $extraWrapperAttributes = [],
        ?VerticalAlignment $inlineLabelVerticalAlignment = null,
        string | Htmlable | null $labelPrefix = null,
        string | Htmlable | null $labelSuffix = null,
        string $labelTag = 'label',
    ): string {
        if ($this->hasUrlImport()) {
            $html = view('filament-multi-source-upload::components.multi-source-file-upload', [
                'filePane' => $html,
                'key' => $this->getKey(),
                'urlPlaceholder' => __('filament-multi-source-upload::multi-source-file-upload.url_placeholder'),
                'importLabel' => __('filament-multi-source-upload::multi-source-file-upload.import'),
                'genericErrorMessage' => __('filament-multi-source-upload::multi-source-file-upload.import_failed'),
            ])->render();
        }

        return parent::wrapEmbeddedHtml($html, $extraWrapperAttributes, $inlineLabelVerticalAlignment, $labelPrefix, $labelSuffix, $labelTag);
    }

    /**
     * The base implementation returns `null` for TemporaryUploadedFile entries,
     * so a freshly imported file has no preview until the form is saved. Patch
     * those entries with a preview payload (using Livewire's signed temp URL for
     * previewable types) so the thumbnail appears immediately.
     *
     * @return array<string, array{name: string, size: int, type: ?string, url: ?string, openableUrl?: string, downloadableUrl?: string}|null>|null
     */
    public function getUploadedFiles(): ?array
    {
        $files = parent::getUploadedFiles();

        if ($files === null) {
            return null;
        }

        foreach ($this->getRawState() ?? [] as $fileKey => $file) {
            if ($file instanceof TemporaryUploadedFile && ($files[$fileKey] ?? null) === null) {
                $files[$fileKey] = $this->previewPayloadForTemporaryFile($file);
            }
        }

        return $files;
    }

    /**
     * @return array{name: string, size: int, type: ?string, url: ?string}
     */
    private function previewPayloadForTemporaryFile(TemporaryUploadedFile $file): array
    {
        $url = null;

        try {
            $url = $file->temporaryUrl();
        } catch (Throwable) {
            // Not a previewable type (e.g. pdf/zip): fall back to no thumbnail
            // until the file is saved and served from the target disk.
            $url = null;
        }

        return [
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'type' => $file->getMimeType(),
            'url' => $url === null ? null : Str::sanitizeUrl($url),
        ];
    }

    /**
     * The base implementation coerces every state entry to a string and drops
     * any that do not exist on the target disk. A freshly imported (or uploaded)
     * TemporaryUploadedFile lives on the temporary disk, so that check would
     * discard it whenever the schema re-hydrates between requests — breaking
     * multi-file accumulation. Keep temp files unconditionally; only validate
     * already-stored string paths against the disk.
     */
    public function hydrateFiles(): void
    {
        $shouldFetchFileInformation = $this->shouldFetchFileInformation();

        $this->rawState(
            array_filter(Arr::wrap($this->getRawState()), function (mixed $file) use ($shouldFetchFileInformation): bool {
                if ($file instanceof TemporaryUploadedFile) {
                    return true;
                }

                if (blank($file)) {
                    return false;
                }

                if (! $shouldFetchFileInformation) {
                    return true;
                }

                try {
                    return $this->getDisk()->exists($file);
                } catch (UnableToCheckFileExistence) {
                    return false;
                }
            }),
        );
    }

    /**
     * Fetch a user-supplied URL server-side (SSRF-, size- and timeout-guarded)
     * and hand its bytes back to the browser as a base64 data URL, together with
     * the sniffed filename, MIME type and size. The client turns this into a real
     * File and feeds it to FilePond via `addFile()`, so a URL-sourced file flows
     * through the exact same pipeline as a local upload — client-side type/size
     * validation, preview, the temporary upload and the eventual save included.
     * No file state is written here; FilePond owns the file from this point on.
     *
     * @return array{name: string, type: ?string, size: int, dataUrl: string}|array{error: string}
     */
    #[ExposedLivewireMethod]
    public function fetchRemoteFile(string $url): array
    {
        if (! $this->hasUrlImport() || $this->isDisabled()) {
            return ['error' => __('filament-multi-source-upload::multi-source-file-upload.import_failed')];
        }

        $url = trim($url);

        if ($url === '') {
            return ['error' => __('filament-multi-source-upload::multi-source-file-upload.reason_empty_url')];
        }

        try {
            $file = app(RemoteFileFetcher::class)->fetch(
                url: $url,
                allowPrivateNetworks: $this->allowsPrivateNetworks(),
                maxSizeKb: $this->getEffectiveMaxUrlImportSize(),
            );
        } catch (RemoteFileFetchException $exception) {
            return ['error' => $exception->translatedReason()];
        }

        try {
            $mimeType = $file->getMimeType();

            return [
                'name' => $file->getClientOriginalName(),
                'type' => $mimeType,
                'size' => $file->getSize(),
                'dataUrl' => 'data:' . $mimeType . ';base64,' . base64_encode($file->get()),
            ];
        } finally {
            // We only needed the bytes; the browser re-uploads through FilePond's
            // own pipeline, so drop this server-side temporary copy right away.
            $file->delete();
        }
    }
}
