<div
    data-msu-tabs
    class="fi-msu"
    x-data="{
        tab: 'file',
        importing: false,
        url: '',
        error: null,

        async importFromUrl() {
            const url = this.url.trim()

            if (url === '' || this.importing) {
                return
            }

            this.importing = true
            this.error = null

            try {
                // Fetch the bytes server-side (SSRF/size/timeout guarded) and
                // receive them as a data URL, then rebuild a real File so it can
                // go through FilePond's normal pipeline — no duplicated logic.
                const result = await $wire.callSchemaComponentMethod(@js($key), 'fetchRemoteFile', { url })

                if (! result || result.error) {
                    this.error = result?.error ?? @js($genericErrorMessage)

                    return
                }

                const blob = await (await fetch(result.dataUrl)).blob()
                const file = new File([blob], result.name, { type: result.type ?? blob.type })

                const uploadEl = this.$refs.filePane.querySelector('[wire\\:ignore]')
                const fileUpload = uploadEl ? Alpine.$data(uploadEl) : null

                if (! fileUpload || ! fileUpload.pond) {
                    this.error = @js($genericErrorMessage)

                    return
                }

                // Show the upload pane, then let FilePond validate (type/size),
                // preview, upload and manage the file exactly like a local one.
                // Any validation failure surfaces inline on the FilePond item.
                this.tab = 'file'

                await fileUpload.pond.addFile(file)

                this.url = ''
            } catch (error) {
                this.error = this.error ?? @js($genericErrorMessage)
            } finally {
                this.importing = false
            }
        },
    }"
>
    {{-- Our own label + source switch on one row. The field's native label is
         hidden (kept for screen readers), so this is the only visible label. --}}
    <div class="fi-msu-header">
        @if (filled($label))
            <span class="fi-fo-field-label" aria-hidden="true">
                <span class="fi-fo-field-label-content">
                    {{ $label }}@if ($isRequired)<sup class="fi-fo-field-label-required-mark">*</sup>@endif
                </span>
            </span>
        @endif

        <div class="fi-msu-switch" role="tablist">
            <button
                type="button"
                role="tab"
                class="fi-msu-switch-option"
                x-on:click="tab = 'file'"
                x-bind:class="{ 'fi-active': tab === 'file' }"
                x-bind:aria-selected="tab === 'file'"
            >
                {{ $fileTabLabel }}
            </button>

            <button
                type="button"
                role="tab"
                class="fi-msu-switch-option"
                x-on:click="tab = 'url'"
                x-bind:class="{ 'fi-active': tab === 'url' }"
                x-bind:aria-selected="tab === 'url'"
            >
                {{ $urlTabLabel }}
            </button>
        </div>
    </div>

    <div x-ref="filePane" x-show="tab === 'file'">
        {!! $filePane !!}
    </div>

    <div x-show="tab === 'url'" x-cloak class="fi-msu-url">
        <div class="fi-msu-url-row">
            <x-filament::input.wrapper class="fi-msu-url-field">
                <x-filament::input
                    type="url"
                    x-model="url"
                    x-bind:disabled="importing"
                    :placeholder="$urlPlaceholder"
                    x-on:keydown.enter.prevent="importFromUrl()"
                />
            </x-filament::input.wrapper>

            <x-filament::button
                x-bind:disabled="importing"
                x-on:click="importFromUrl()"
            >
                <span x-show="! importing">{{ $importLabel }}</span>
                <span x-show="importing" x-cloak>…</span>
            </x-filament::button>
        </div>

        <p x-show="error" x-text="error" x-cloak class="fi-msu-error"></p>
    </div>
</div>
