<native:column class="w-full h-full bg-theme-background safe-area">
    <native:scroll-view class="w-full flex-1">
        <native:column class="w-full gap-4 p-4 pb-8">
            <native:text class="text-lg font-bold text-theme-on-background">Terms for user-added sources</native:text>

            <native:text class="text-sm text-theme-on-background">{{ $clause }}</native:text>

            <native:button label="I understand, continue" variant="primary" @press="agree" />
        </native:column>
    </native:scroll-view>
</native:column>
