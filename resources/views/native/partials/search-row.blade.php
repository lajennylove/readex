{{-- One row of the Search grid. virtual_list item views only get $index; Search::render() shares the window's tiles as $searchRows. --}}
@if (($searchRows[$index] ?? null) === \App\NativeComponents\Search::RATED_TITLE)
    <native:column class="w-full gap-3 px-4 pt-2 pb-4">
        <native:divider />
        <native:text class="text-xl font-bold text-theme-on-background">Rated content</native:text>
    </native:column>
@else
    <native:row class="w-full gap-4 px-4 pb-4">
        @foreach ($searchRows[$index] ?? [] as $tile)
            @include('native.partials.tile', $tile)
        @endforeach
    </native:row>
@endif
