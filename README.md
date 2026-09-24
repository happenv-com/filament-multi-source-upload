# Filament Multi-Source Upload

<div class="filament-hidden">

![Filament Multi-Source Upload](art/banner.png)

</div>

[![Latest Version](https://img.shields.io/github/v/release/happenv-com/filament-multi-source-upload?style=flat-square&label=version)](https://github.com/happenv-com/filament-multi-source-upload/releases)
[![Tests](https://img.shields.io/github/actions/workflow/status/happenv-com/filament-multi-source-upload/tests.yml?label=tests&style=flat-square)](https://github.com/happenv-com/filament-multi-source-upload/actions/workflows/tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/happenv-com/filament-multi-source-upload/phpstan.yml?label=phpstan&style=flat-square)](https://github.com/happenv-com/filament-multi-source-upload/actions/workflows/phpstan.yml)
[![Quality](https://img.shields.io/github/actions/workflow/status/happenv-com/filament-multi-source-upload/quality.yml?label=code%20quality&style=flat-square)](https://github.com/happenv-com/filament-multi-source-upload/actions/workflows/quality.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/happenv-com/filament-multi-source-upload.svg?style=flat-square)](https://packagist.org/packages/happenv-com/filament-multi-source-upload)
[![License](https://img.shields.io/github/license/happenv-com/filament-multi-source-upload.svg?style=flat-square)](https://github.com/happenv-com/filament-multi-source-upload/blob/1.x/LICENSE.md)

A drop-in replacement for Filament's `FileUpload` field that lets users add a file **from their disk or from a URL** — and in **both** cases the file is downloaded and stored on your target disk, exactly as if it had been uploaded.

No second `*_url` column, no remote references to babysit. One column, one file, always on your storage.

```php
use Happenv\FilamentMultiSourceUpload\MultiSourceFileUpload;

MultiSourceFileUpload::make('logo_path')
    ->image()
    ->disk('s3')
    ->directory('logos');
```

### Why

Most "upload from URL" components store the link in a separate column (`image` **or** `image_url`) and leave the file on someone else's server. The moment that URL rots, moves, or blocks hotlinking, your data is gone.

`MultiSourceFileUpload` takes the opposite approach: a pasted URL is fetched server-side, turned into a real Livewire temporary upload, and then flows through Filament's **own** save pipeline. The result is byte-for-byte identical to a drag-and-drop upload — same disk, same directory, same visibility, same filename strategy, same single string column.

## Key features

- **A file from disk or from a link, in one field.** A compact *File / From URL* switch beside the label; the pasted link is imported with one click. See [Usage](#usage).
- **Always stored on your disk.** An imported file goes through the field's own uploader and save pipeline, so it lands on `disk()` / `directory()` exactly like a dragged-in file — one string column, no remote references.
- **Every `FileUpload` option still applies.** It **is** a `FileUpload`: `acceptedFileTypes()`, `maxSize()`, `multiple()`, image editing, file naming and visibility work unchanged. See [How it works](#how-it-works).
- **Instant preview.** The imported file drops into the dropzone as a live upload item with a thumbnail, progress bar and remove button. See [Instant preview](#instant-preview).
- **Safe server-side fetching.** http(s) only, private and internal addresses refused on every redirect, the connection pinned to the checked address, a size cap and a timeout. See [Security](#security).
- **Matches your panel.** Reuses Filament's component classes and design tokens, dark mode included; translated into all 64 languages Filament ships.
- **Tested.** Covered by a Pest suite on every supported version combination.

## Requirements

| Package  | Versions     |
|----------|--------------|
| PHP      | 8.5          |
| Laravel  | 13           |
| Filament | 5 (`^5.7.0`) |
| Livewire | 4            |

## Installation

Install the package via Composer:

```bash
composer require happenv-com/filament-multi-source-upload
```

The service provider is auto-discovered and the field's stylesheet is registered as a Filament asset — that's it, the field is ready to use. It does not use Tailwind utility classes of its own, so your panel theme needs no extra `@source` line.

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

### Publishing views & translations

To customise the tab labels, placeholders or error messages, or the view, publish them:

```bash
php artisan vendor:publish --tag="filament-multi-source-upload-translations"
php artisan vendor:publish --tag="filament-multi-source-upload-views"
```

The label and source switch reuse Filament's own component classes and design tokens, so they match the active panel theme (including dark mode) with no extra configuration.

## Usage

### Uploading a file or importing it from a URL

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

### Multiple files

```php
MultiSourceFileUpload::make('gallery')
    ->image()
    ->multiple()
    ->maxFiles(10)
    ->disk('s3')
    ->directory('gallery');
```

### Disabling URL import conditionally

```php
MultiSourceFileUpload::make('logo_path')
    ->urlImport(fn (): bool => auth()->user()->canImportFromUrl());
```

### How it works

1. The user pastes a URL and clicks **Import**.
2. The file is downloaded server-side (with SSRF, size and timeout guards) and its bytes are handed back to the browser.
3. The browser rebuilds a real `File` and feeds it to the field's FilePond instance via `addFile()` — so from that point it is indistinguishable from a file the user dragged in: FilePond validates it (type/size), previews it, uploads it to Livewire's temporary storage and, on submit, Filament promotes it onto the configured disk.

Because the URL and the local file converge on the **exact same uploader**, the imported file honours every setting you already use: `disk()`, `directory()`, `visibility()`, `acceptedFileTypes()`, `maxSize()`, `getUploadedFileNameForStorageUsing()`, `storeFileNamesIn()`, image editing, and so on — with no duplicated logic.

### Instant preview

The moment the user clicks **Import**, the file drops into the dropzone as a live upload item — image/video/audio thumbnails render immediately, other types (PDF, ZIP, …) show as a named file entry — with an upload progress bar and a remove button, just like a local upload.

### Security

Fetching a user-supplied URL server-side is an SSRF vector, so the download is guarded by default:

- **Scheme allow-list** — only `http` and `https` URLs are accepted.
- **Private-network blocking** — hosts resolving to private, reserved, loopback, link-local, shared (CGNAT) or multicast addresses are rejected, including the cloud metadata endpoints (`169.254.169.254`, `100.100.100.200`), IPv4-mapped IPv6 and IPv6 ranges that embed an IPv4 address (NAT64, 6to4, Teredo). Opt out per field with `allowPrivateNetworks()`.
- **Redirects are checked too** — at most three redirects are followed, and every target passes the same scheme and address checks as the URL the user entered.
- **Pinned connection** — the request connects to exactly the address that was checked, so a DNS answer that changes between the check and the download (DNS rebinding) cannot redirect it to an internal host. The Host header, TLS SNI and certificate verification still use the host name. This needs PHP's `curl` extension; without it URL imports are refused unless private networks are allowed.
- **Size cap** — the download is streamed and aborted the moment it exceeds `maxUrlImportSize()` (falling back to `maxSize()`, then 25 MB). A declared `Content-Length` over the cap is rejected up front.
- **Timeout** — a hard request timeout for every request.
- **MIME validation** — the downloaded file's type is sniffed from its bytes (not the server's `Content-Type`) and carried on the rebuilt `File`, so FilePond validates it against `acceptedFileTypes()` client-side exactly as it would a local upload, on top of Filament's usual save-time validation.

As with any `FileUpload`, always call `acceptedFileTypes()` (or `image()`) with an explicit type list when files land on a public, PHP-executing disk.

If your server sends outgoing HTTP through a proxy, the proxy connects to the target, so the address checks are only as strong as the proxy's own rules.

## Translations

The field ships in every locale Filament ships:

`am` `ar` `az` `bg` `bn` `bs` `ca` `ckb` `cs` `da` `de` `el` `en` `es` `et` `eu` `fa` `fi` `fil` `fr` `he` `hi` `hr` `hu` `hy` `id` `it` `ja` `ka` `km` `ko` `ku` `lt` `lus` `lv` `mk` `mn` `ms` `my` `nb` `ne` `nl` `pl` `pt` `pt_BR` `ro` `ru` `sk` `sl` `sq` `sr_Cyrl` `sr_Latn` `sv` `sw` `tg` `th` `tr` `uk` `ur` `uz` `vi` `zh_CN` `zh_HK` `zh_TW`

Publish them with `php artisan vendor:publish --tag="filament-multi-source-upload-translations"` to change the wording or add a language. `tests/Unit/TranslationsTest.php` checks that every locale has exactly the keys English has, and that every locale Filament ships has a translation.

## Development

```bash
composer test          # unit and feature tests
composer phpstan       # static analysis
composer cs            # fix code style: composer normalize, Rector, Pint
composer ci            # everything CI checks, locally
```

The compiled stylesheet in `resources/dist/` is committed, so installing the package needs no build step. It is authored with Tailwind (`@apply`) in `resources/css/index.css` and only emits the component's own rules (Filament's theme is pulled in via `@reference`, so no base styles are duplicated). The build reads that theme from `vendor/`, so install the Composer dependencies first. After changing `resources/css`, rebuild and commit the result — CI refuses outdated assets:

```bash
composer update
npm ci
npm run build   # or `npm run dev` to rebuild on change
npm run lint    # Prettier check, as in CI (`npm run format` fixes it)
```

## Upgrading

Breaking changes and how to migrate are described in [UPGRADING](https://github.com/happenv-com/filament-multi-source-upload/blob/1.x/UPGRADING.md) for every major version.

## Changelog

See [CHANGELOG](https://github.com/happenv-com/filament-multi-source-upload/blob/1.x/CHANGELOG.md) and [GitHub releases](https://github.com/happenv-com/filament-multi-source-upload/releases) for what has changed recently.

## Contributing

See [CONTRIBUTING](https://github.com/happenv-com/filament-multi-source-upload/blob/1.x/.github/CONTRIBUTING.md) for details.

## Security vulnerabilities

Please review [our security policy](https://github.com/happenv-com/filament-multi-source-upload/blob/1.x/.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Happenv sp. z o.o.](https://happenv.com)
- [webard](https://github.com/webard)
- [All contributors](https://github.com/happenv-com/filament-multi-source-upload/contributors)

## License

The MIT License (MIT). See [License File](https://github.com/happenv-com/filament-multi-source-upload/blob/1.x/LICENSE.md) for more information.

---

<p align="center">
    <a href="https://happenv.com">
        <img src="art/happenv-logo.png" alt="Happenv" width="400">
    </a>
</p>
