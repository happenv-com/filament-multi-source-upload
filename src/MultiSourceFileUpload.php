<?php

declare(strict_types=1);

namespace Happenv\FilamentMultiSourceUpload;

use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
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
    protected bool | Closure $hasUrlImport = true;

    protected bool | Closure $allowPrivateNetworks = false;

    protected int | Closure | null $maxUrlImportSize = null;

    protected string | Closure | null $urlTabLabel = null;

    protected string | Closure | null $fileTabLabel = null;

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
        return (bool) $this->evaluate($this->hasUrlImport) && ! $this->isDisabled();
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
            'fileTabLabel' => $this->getFileTabLabel(),
            'urlTabLabel' => $this->getUrlTabLabel(),
            'urlPlaceholder' => __('filament-multi-source-upload::multi-source-file-upload.url_placeholder'),
            'importLabel' => __('filament-multi-source-upload::multi-source-file-upload.import'),
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

    #[ExposedLivewireMethod]
    public function importFromUrl(string $url): void
    {
        if (! $this->hasUrlImport()) {
            return;
        }

        try {
            $file = app(RemoteFileFetcher::class)->fetch(
                url: $url,
                allowPrivateNetworks: $this->allowsPrivateNetworks(),
                maxSizeKb: $this->getEffectiveMaxUrlImportSize(),
            );
        } catch (RemoteFileFetchException $exception) {
            $this->notifyImportFailure($exception->translatedReason());

            return;
        }

        $rejection = $this->rejectionReason($file);
        if ($rejection !== null) {
            $file->delete();
            $this->notifyImportFailure($rejection);

            return;
        }

        $state = $this->isMultiple() ? ($this->getRawState() ?? []) : [];
        $state[(string) Str::uuid()] = $file;

        $this->rawState($state);
        $this->callAfterStateUpdated();
    }

    private function rejectionReason(TemporaryUploadedFile $file): ?string
    {
        $types = $this->getAcceptedFileTypes();
        if ($types !== null && $types !== [] && ! $this->mimeMatches($file->getMimeType(), $types)) {
            return __('filament-multi-source-upload::multi-source-file-upload.reason_invalid_type');
        }

        $maxKb = $this->getMaxSize();
        if ($maxKb !== null && $file->getSize() > $maxKb * 1024) {
            return __('filament-multi-source-upload::multi-source-file-upload.reason_too_large');
        }

        return null;
    }

    /**
     * @param  array<string>  $accepted
     */
    private function mimeMatches(string $mime, array $accepted): bool
    {
        foreach ($accepted as $pattern) {
            if ($pattern === $mime) {
                return true;
            }

            if (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    private function notifyImportFailure(string $body): void
    {
        Notification::make()
            ->danger()
            ->title(__('filament-multi-source-upload::multi-source-file-upload.import_failed'))
            ->body($body)
            ->send();
    }
}
