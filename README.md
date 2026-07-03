# Filament Multi-Source Upload

[![Latest Version on Packagist](https://img.shields.io/packagist/v/happenv-com/filament-multi-source-upload.svg?style=flat-square)](https://packagist.org/packages/happenv-com/filament-multi-source-upload)
[![Total Downloads](https://img.shields.io/packagist/dt/happenv-com/filament-multi-source-upload.svg?style=flat-square)](https://packagist.org/packages/happenv-com/filament-multi-source-upload)
[![License](https://img.shields.io/packagist/l/happenv-com/filament-multi-source-upload.svg?style=flat-square)](LICENSE.md)

A drop-in replacement for Filament's `FileUpload` field that lets users add a file **from their disk or from a URL** — and in **both** cases the file is downloaded and stored on your target disk, exactly as if it had been uploaded.

No second `*_url` column, no remote references to babysit. One column, one file, always on your storage.

```php
use Happenv\FilamentMultiSourceUpload\MultiSourceFileUpload;

MultiSourceFileUpload::make('logo_path')
    ->image()
    ->disk('s3')
    ->directory('logos');
```

## Why

Most "upload from URL" components store the link in a separate column (`image` **or** `image_url`) and leave the file on someone else's server. The moment that URL rots, moves, or blocks hotlinking, your data is gone.

`MultiSourceFileUpload` takes the opposite approach: a pasted URL is fetched server-side, turned into a real Livewire temporary upload, and then flows through Filament's **own** save pipeline. The result is byte-for-byte identical to a drag-and-drop upload — same disk, same directory, same visibility, same filename strategy, same single string column.

## Requirements

- PHP 8.5+
- Filament v5
- Livewire v4

## Installation

```bash
composer require happenv-com/filament-multi-source-upload
```

The service provider is auto-discovered. That's it — the field is ready to use.

## Usage

Use it anywhere you would use `FileUpload`. Every `FileUpload` method works unchanged, because it **is** a `FileUpload`:

```php
use Happenv\FilamentMultiSourceUpload\MultiSourceFileUpload;

MultiSourceFileUpload::make('avatar')
    ->image()
    ->avatar()
    ->disk('public')
    ->directory('avatars')
    ->maxSize(2048);
```

The field renders two tabs — **File** and **From URL**. On the *From URL* tab the user pastes a link and clicks import; the file appears in the dropzone immediately and is committed to the target disk when the form is saved.

Multiple files work too:

```php
MultiSourceFileUpload::make('gallery')
    ->image()
    ->multiple()
    ->maxFiles(10)
    ->disk('s3')
    ->directory('gallery');
```

## How it works

1. The user pastes a URL and clicks import.
2. The file is downloaded server-side (with SSRF, size and timeout guards) into a genuine Livewire `TemporaryUploadedFile` — the same object a real browser upload produces.
3. That temp file is injected into the field's state, so Filament's inherited `saveUploadedFiles()` promotes it onto the configured disk on form submit.

Because the URL and the local file converge on the exact same code path, the imported file honours every setting you already use: `disk()`, `directory()`, `visibility()`, `getUploadedFileNameForStorageUsing()`, `storeFileNamesIn()`, image editing, and so on.

## Configuration

In addition to the full `FileUpload` API, the field adds:

| Method | Default | Description |
| --- | --- | --- |
| `urlImport(bool \| Closure)` | `true` | Enable/disable the *From URL* tab. When `false`, the field behaves like a plain `FileUpload`. |
| `allowPrivateNetworks(bool \| Closure)` | `false` | Allow importing from private/loopback/link-local addresses (relaxes the SSRF guard). |
| `maxUrlImportSize(int \| Closure \| null)` | `maxSize()` or 25 MB | Hard cap (in **kilobytes**) for URL downloads. |
| `fileTabLabel(string \| Closure \| null)` | translated | Label of the *File* tab. |
| `urlTabLabel(string \| Closure \| null)` | translated | Label of the *From URL* tab. |

```php
MultiSourceFileUpload::make('document')
    ->acceptedFileTypes(['application/pdf'])
    ->disk('documents')
    ->maxUrlImportSize(10 * 1024)   // 10 MB
    ->urlTabLabel('Paste a link');
```

### Disabling URL import conditionally

```php
MultiSourceFileUpload::make('logo_path')
    ->urlImport(fn (): bool => auth()->user()->canImportFromUrl());
```

## Instant preview

An imported image, video or audio file previews in the dropzone the moment it is imported — no need to save the form first. Other file types (PDF, ZIP, …) are imported correctly but their thumbnail/entry appears after the record is saved.

## Security

Fetching a user-supplied URL server-side is an SSRF vector, so the download is guarded by default:

- **Scheme allow-list** — only `http` and `https` URLs are accepted.
- **Private-network blocking** — hosts resolving to private, reserved, loopback or link-local addresses are rejected, including the cloud metadata endpoint (`169.254.169.254`). Opt out per field with `allowPrivateNetworks()`.
- **Size cap** — the download is streamed and aborted the moment it exceeds `maxUrlImportSize()` (falling back to `maxSize()`, then 25 MB). A declared `Content-Length` over the cap is rejected up front.
- **Timeout & redirect limit** — a hard request timeout and a bounded number of redirects.
- **MIME validation** — the downloaded file's type is sniffed from its bytes (not the server's `Content-Type`) and checked against `acceptedFileTypes()` immediately, in addition to Filament's usual save-time validation.

As with any `FileUpload`, always call `acceptedFileTypes()` (or `image()`) with an explicit type list when files land on a public, PHP-executing disk.

## Publishing views & translations

The component ships English and Polish translations. To customise the tab labels, placeholders or error messages, publish them:

```bash
php artisan vendor:publish --tag="filament-multi-source-upload-translations"
php artisan vendor:publish --tag="filament-multi-source-upload-views"
```

## Testing

```bash
composer test
```

## Credits

- [webard](https://github.com/webard)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
