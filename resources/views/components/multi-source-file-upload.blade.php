<div
    data-msu-tabs
    class="fi-msu"
    x-data="{
        tab: 'file',

        // The source switch sits in the field's label row, outside this
        // scope: it announces its choice as a `msu-tab` event keyed by the
        // field, and hears back when the panes change tab on their own
        // (an import lands on the file pane).
        init() {
            this.$watch('tab', (tab) => this.$dispatch('msu-tab', { key: @js($key), tab }))
        },

        followSwitch(event) {
            if (event.detail?.key !== @js($key) || event.detail?.tab === this.tab) {
                return
            }

            this.tab = event.detail.tab
            this.error = null
        },
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

            let handedToFilePond = false

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
                handedToFilePond = true

                await fileUpload.pond.addFile(file)

                this.url = ''
            } catch (error) {
                // Once the file is in FilePond, FilePond shows why it was
                // refused on the item itself; a message here would only wait,
                // stale, on the URL pane — even if the user has switched back
                // to it while FilePond was still deciding.
                if (! handedToFilePond) {
                    this.error = this.error ?? @js($genericErrorMessage)
                }
            } finally {
                this.importing = false
            }
        },
    }"
    x-on:msu-tab.window="followSwitch($event)"
>
    <div x-ref="filePane" x-show="tab === 'file'">
        {!! $filePane !!}
    </div>

    <div x-show="tab === 'url'" x-cloak class="fi-msu-url">
        <div class="fi-msu-url-row">
            <x-filament::input.wrapper class="fi-msu-url-field">
                <x-filament::input
                    type="url"
                    x-model="url"
                    x-on:input="error = null"
                    x-bind:disabled="importing"
                    :placeholder="$urlPlaceholder"
                    x-on:keydown.enter.prevent="importFromUrl()"
                />
            </x-filament::input.wrapper>

            <x-filament::button
                x-bind:disabled="importing || url.trim() === ''"
                x-on:click="importFromUrl()"
            >
                <span x-show="! importing">{{ $importLabel }}</span>
                <span x-show="importing" x-cloak>…</span>
            </x-filament::button>
        </div>

        <p x-show="error" x-text="error" x-cloak class="fi-msu-error"></p>
    </div>
</div>
