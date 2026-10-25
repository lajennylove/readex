<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full gap-6 p-4 pb-24">
        @forelse ($groups as $group)
            <native:column class="w-full gap-2" native:key="dl-group-{{ $group['mangaId'] }}">
                <native:row class="w-full gap-3 items-center">
                    {{-- Fixed height, not aspect-[2/3]: iOS rows measure children with no height, so an aspect-only cover overflows the row. Matches manga-detail header. --}}
                    <native:stack class="w-12 h-16 rounded-md bg-[#2C2C2E]">
                        @if ($group['cover'])
                            <native:image src="{{ $group['cover'] }}" class="w-full h-full rounded-md object-cover" alt="{{ $group['title'] }}" />
                        @endif
                        @include('native.partials.skeleton', ['key' => "dl-cover-{$group['mangaId']}", 'class' => 'w-full h-full rounded-md', 'loaded' => $group['cover'] !== null])
                    </native:stack>
                    <native:column class="flex-1 gap-0">
                        <native:text class="text-lg font-bold text-theme-on-background" max-lines="2">{{ $group['title'] }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $group['count'] }}</native:text>
                    </native:column>
                </native:row>
                <native:column class="w-full gap-1" native:key="dl-rows-{{ $group['mangaId'] }}">
                    @foreach ($group['rows'] as $row)
                        <native:row class="w-full items-center justify-between gap-3 py-2" native:key="dl-row-{{ $row['chapterId'] }}">
                            <native:column class="flex-1 gap-0">
                                <native:text class="text-base text-theme-on-background">{{ $row['label'] }}</native:text>
                                <native:text class="text-sm text-theme-on-surface-variant">{{ $row['chip'] }}</native:text>
                            </native:column>
                            <native:row class="items-center gap-3">
                                @foreach ($row['actions'] as $action)
                                    @if ($action['disabled'])
                                        <native:icon native:key="dl-{{ $row['chapterId'] }}-{{ $action['icon'] }}" name="{{ $action['icon'] }}" size="20" color="#8E8E93" />
                                    @else
                                        <native:pressable
                                            native:key="dl-{{ $row['chapterId'] }}-{{ $action['action'] }}"
                                            @press="{{ $action['action'] }}('{{ $row['chapterId'] }}')"
                                            a11y-label="{{ $action['label'] }} {{ $row['label'] }}">
                                            <native:icon name="{{ $action['icon'] }}" size="20" color="#FFFFFF" />
                                        </native:pressable>
                                    @endif
                                @endforeach
                            </native:row>
                        </native:row>
                    @endforeach
                </native:column>
            </native:column>
            @if (! $loop->last)
                <native:divider />
            @endif
        @empty
            <native:column class="w-full items-center gap-3 p-6">
                <native:text class="text-lg font-bold text-theme-on-background">No downloads queued</native:text>
                <native:text class="text-sm text-center text-theme-on-surface-variant">Chapters you queue from a series' Detail will show here with per-chapter controls.</native:text>
            </native:column>
        @endforelse
    </native:column>
</native:scroll-view>
