{{-- One row of the Search grid. virtual_list item views only get $index; Search::render() shares the window's tiles as $searchRows. --}}
<native:row class="w-full gap-4 px-4 pb-4">
    @foreach ($searchRows[$index] ?? [] as $tile)
        @include('native.partials.tile', $tile)
    @endforeach
</native:row>
