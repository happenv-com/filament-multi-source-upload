<?php

declare(strict_types=1);

namespace Happenv\FilamentMultiSourceUpload;

use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Happenv\FilamentMultiSourceUpload\Exceptions\RemoteFileFetchException;
use Happenv\FilamentMultiSourceUpload\Support\RemoteFileFetcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use League\Flysystem\UnableToCheckFileExistence;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class MultiSourceFileUpload extends FileUpload
{
    protected bool|Closure $hasUrlImport = true;

    protected bool|Closure $allowPrivateNetworks = false;

    protected int|Closure|null $maxUrlImportSize = null;

    protected string|Closure|null $urlTabLabel = null;

    protected string|Closure|null $fileTabLabel = null;

    protected function setUp(): void
    {
        parent::setUp();

        // We render our own label + source switch on one row inside the field
        // content (so we fully control the layout and share one Alpine scope),
        // so hide the wrapper's own visible label to avoid a duplicate. It stays
        // available to screen readers.
        $this->hiddenLabel(fn (): bool => $this->hasUrlImport());
    }

    public function urlImport(bool|Closure $condition = true): static
    {
        $this->hasUrlImport = $condition;

        return $this;
    }

    public function allowPrivateNetworks(bool|Closure $condition = true): static
    {
        $this->allowPrivateNetworks = $condition;

        return $this;
    }

    public function maxUrlImportSize(int|Closure|null $kilobytes): static
    {
        $this->maxUrlImportSize = $kilobytes;

        return $this;
    }

    public function urlTabLabel(string|Closure|null $label): static
    {
        $this->urlTabLabel = $label;

        return $this;
    }

    public function fileTabLabel(string|Closure|null $label): static
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

    public function toEmbeddedHtml(): string
    {
        if (! $this->hasUrlImport()) {
            return parent::toEmbeddedHtml();
        }

        return view('filament-multi-source-upload::components.multi-source-file-upload', [
            'filePane' => parent::toEmbeddedHtml(),
            'key' => $this->getKey(),
            'label' => $this->getLabel(),
            'isRequired' => $this->isMarkedAsRequired(),
            'fileTabLabel' => $this->getFileTabLabel(),
            'urlTabLabel' => $this->getUrlTabLabel(),
            'urlPlaceholder' => __('filament-multi-source-upload::multi-source-file-upload.url_placeholder'),
            'importLabel' => __('filament-multi-source-upload::multi-source-file-upload.import'),
            'genericErrorMessage' => __('filament-multi-source-upload::multi-source-file-upload.import_failed'),
        ])->render();
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
                'dataUrl' => 'data:'.($mimeType ?? 'application/octet-stream').';base64,'.base64_encode($file->get()),
            ];
        } finally {
            // We only needed the bytes; the browser re-uploads through FilePond's
            // own pipeline, so drop this server-side temporary copy right away.
            $file->delete();
        }
    }
}
