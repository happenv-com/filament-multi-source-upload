{{-- The File / From URL switch, rendered in the field's label row (an
     after-label schema component). It keeps its own tab state and talks to
     the panes through a `msu-tab` event keyed by the field. --}}
<div
    class="fi-msu-switch"
    role="tablist"
    x-data="{ tab: 'file' }"
    x-on:msu-tab.window="if ($event.detail?.key === @js($key)) tab = $event.detail.tab"
>
    <button
        type="button"
        role="tab"
        class="fi-msu-switch-option"
        x-on:click="tab = 'file'; $dispatch('msu-tab', { key: @js($key), tab: 'file' })"
        x-bind:class="{ 'fi-active': tab === 'file' }"
        x-bind:aria-selected="tab === 'file'"
    >
        {{ $fileTabLabel }}
    </button>

    <button
        type="button"
        role="tab"
        class="fi-msu-switch-option"
        x-on:click="tab = 'url'; $dispatch('msu-tab', { key: @js($key), tab: 'url' })"
        x-bind:class="{ 'fi-active': tab === 'url' }"
        x-bind:aria-selected="tab === 'url'"
    >
        {{ $urlTabLabel }}
    </button>
</div>
