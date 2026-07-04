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
- Filament v5.7+
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

The field renders a compact **File / From URL** switch beside the label. On *From URL* the user pastes a link and clicks **Import**: the file is fetched server-side (SSRF/size guarded) and handed to the field's own uploader, so it appears in the dropzone as a normal upload — thumbnail, type/size validation, progress bar, remove button and all — and saves exactly like a dragged-in file.

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

1. The user pastes a URL and clicks **Import**.
2. The file is downloaded server-side (with SSRF, size and timeout guards) and its bytes are handed back to the browser.
3. The browser rebuilds a real `File` and feeds it to the field's FilePond instance via `addFile()` — so from that point it is indistinguishable from a file the user dragged in: FilePond validates it (type/size), previews it, uploads it to Livewire's temporary storage and, on submit, Filament promotes it onto the configured disk.

Because the URL and the local file converge on the **exact same uploader**, the imported file honours every setting you already use: `disk()`, `directory()`, `visibility()`, `acceptedFileTypes()`, `maxSize()`, `getUploadedFileNameForStorageUsing()`, `storeFileNamesIn()`, image editing, and so on — with no duplicated logic.

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

The moment the user clicks **Import**, the file drops into the dropzone as a live upload item — image/video/audio thumbnails render immediately, other types (PDF, ZIP, …) show as a named file entry — with an upload progress bar and a remove button, just like a local upload.

## Security

Fetching a user-supplied URL server-side is an SSRF vector, so the download is guarded by default:

- **Scheme allow-list** — only `http` and `https` URLs are accepted.
- **Private-network blocking** — hosts resolving to private, reserved, loopback or link-local addresses are rejected, including the cloud metadata endpoint (`169.254.169.254`). Opt out per field with `allowPrivateNetworks()`.
- **Size cap** — the download is streamed and aborted the moment it exceeds `maxUrlImportSize()` (falling back to `maxSize()`, then 25 MB). A declared `Content-Length` over the cap is rejected up front.
- **Timeout & redirect limit** — a hard request timeout and a bounded number of redirects.
- **MIME validation** — the downloaded file's type is sniffed from its bytes (not the server's `Content-Type`) and carried on the rebuilt `File`, so FilePond validates it against `acceptedFileTypes()` client-side exactly as it would a local upload, on top of Filament's usual save-time validation.

As with any `FileUpload`, always call `acceptedFileTypes()` (or `image()`) with an explicit type list when files land on a public, PHP-executing disk.

## Publishing views & translations

The component ships English and Polish translations. To customise the tab labels, placeholders or error messages, publish them:

```bash
php artisan vendor:publish --tag="filament-multi-source-upload-translations"
php artisan vendor:publish --tag="filament-multi-source-upload-views"
```

The label and source switch reuse Filament's own component classes and design tokens, so they match the active panel theme (including dark mode) with no extra configuration.

## Building the stylesheet

The compiled stylesheet in `resources/dist/` is committed, so installing the package needs no build step. It is authored with Tailwind (`@apply`) in `resources/css/index.css` and only emits the component's own rules (Filament's theme is pulled in via `@reference`, so no base styles are duplicated). To rebuild after editing it:

```bash
npm install
npm run build
```

## Testing

```bash
composer test
```

## Credits

- [webard](https://github.com/webard)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
