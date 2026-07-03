<div
    data-msu-tabs
    x-data="{ tab: 'file', url: '', importing: false }"
    class="fi-msu"
>
    <div
        class="fi-msu-tabs"
        role="tablist"
        style="display: flex; gap: 0.25rem; margin-bottom: 0.5rem"
    >
        <button
            type="button"
            role="tab"
            @click="tab = 'file'"
            x-bind:class="
                tab === 'file'
                    ? 'fi-btn fi-btn-color-primary fi-size-sm'
                    : 'fi-btn fi-btn-color-gray fi-btn-outlined fi-size-sm'
            "
        >
            {{ $fileTabLabel }}
        </button>

        <button
            type="button"
            role="tab"
            @click="tab = 'url'"
            x-bind:class="
                tab === 'url'
                    ? 'fi-btn fi-btn-color-primary fi-size-sm'
                    : 'fi-btn fi-btn-color-gray fi-btn-outlined fi-size-sm'
            "
        >
            {{ $urlTabLabel }}
        </button>
    </div>

    <div x-show="tab === 'file'">{!! $filePane !!}</div>

    <div
        x-show="tab === 'url'"
        x-cloak
        class="fi-msu-url"
        style="display: flex; gap: 0.5rem; align-items: center"
    >
        <x-filament::input.wrapper class="fi-msu-url-input" style="flex: 1">
            <x-filament::input
                type="url"
                x-model="url"
                placeholder="{{ $urlPlaceholder }}"
                x-on:keydown.enter.prevent="
                    if (url && !importing) $refs.importButton.click()
                "
            />
        </x-filament::input.wrapper>

        <x-filament::button
            x-ref="importButton"
            x-bind:disabled="importing || !url"
            x-on:click="
                importing = true
                await $wire.callSchemaComponentMethod(@js($key), 'importFromUrl', { url })
                url = ''
                tab = 'file'
                importing = false
            "
        >
            <span x-show="!importing">{{ $importLabel }}</span>
            <span x-show="importing" x-cloak>…</span>
        </x-filament::button>
    </div>
</div>
