{{-- One row of the Reader. virtual_list item views only get $index; Reader::render() shares the window's rows as $readerRows. --}}
@php($row = $readerRows[$index] ?? null)
@if ($row === null)
    <native:stack native:key="row-{{ $index }}" class="w-full h-12 bg-black" />
@elseif ($row['kind'] === 'header')
    <native:column native:key="reader-header" class="w-full gap-1 px-4 pt-4 pb-3 bg-black">
        <native:text class="text-base font-bold text-white" max-lines="2">{{ $row['label'] }}</native:text>
        <native:text class="text-sm text-theme-on-surface-variant" max-lines="1">{{ $row['credit'] }}</native:text>
        @if ($row['before'])
            <native:pressable class="w-full items-center mt-2 py-3 rounded-lg bg-[#2C2C2E]" @press="fromStart" a11y-label="{{ $row['before'] }}, read from page 1">
                <native:text class="text-base text-white">{{ $row['before'] }}</native:text>
            </native:pressable>
        @endif
    </native:column>
@elseif ($row['kind'] === 'end')
    <native:column native:key="reader-end" class="w-full items-center gap-2 px-4 pt-6 pb-12 bg-black">
        <native:text class="text-base font-bold text-white">{{ $row['label'] }}</native:text>
        <native:text class="text-sm text-theme-on-surface-variant">{{ $row['credit'] }}</native:text>
        @if ($row['next'])
            <native:button label="{{ $row['next'] }}" @press="nextChapter" />
        @else
            <native:text class="text-sm text-theme-on-surface-variant">You're caught up</native:text>
        @endif
    </native:column>
@elseif ($row['src'] !== null)
    <native:image
        native:key="page-{{ $row['number'] }}"
        src="{{ $row['src'] }}"
        class="w-full aspect-[{{ $row['ratio'] }}]"
        alt="Page {{ $row['number'] }}"
        @press="toggleBar"
        @doubleTap="openPage({{ $row['index'] }})" />
@elseif ($row['failed'])
    <native:pressable native:key="page-{{ $row['number'] }}" class="w-full aspect-[{{ $row['ratio'] }}] items-center justify-center bg-black" @press="retryPage({{ $row['index'] }})" a11y-label="Page {{ $row['number'] }} didn't load, tap to retry">
        <native:text class="text-sm text-white">Page {{ $row['number'] }} didn't load · Tap to retry</native:text>
    </native:pressable>
@elseif ($row['waiting'])
    <native:pressable native:key="page-{{ $row['number'] }}" class="w-full aspect-[{{ $row['ratio'] }}] items-center justify-center bg-black" @press="toggleBar" a11y-label="Page {{ $row['number'] }}, waiting for a connection">
        <native:text class="text-sm text-theme-on-surface-variant">Page {{ $row['number'] }} · Waiting for a connection</native:text>
    </native:pressable>
@elseif ($row['trouble'])
    <native:pressable native:key="page-{{ $row['number'] }}" class="w-full aspect-[{{ $row['ratio'] }}] items-center justify-center bg-black" @press="toggleBar" a11y-label="Page {{ $row['number'] }}, source is having trouble, retrying">
        <native:text class="text-sm text-theme-on-surface-variant">Page {{ $row['number'] }} · The provider is having trouble, retrying</native:text>
    </native:pressable>
@else
    <native:pressable native:key="page-{{ $row['number'] }}-tap" class="w-full" @press="toggleBar" a11y-label="Page {{ $row['number'] }}, loading">
        @include('native.partials.skeleton', ['key' => "page-{$row['number']}", 'class' => "w-full aspect-[{$row['ratio']}]"])
    </native:pressable>
@endif
