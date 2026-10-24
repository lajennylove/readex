<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full gap-4 p-4 pb-8">
        <native:text class="text-lg font-bold text-theme-destructive">Draft — pending attorney review</native:text>
        <native:text class="text-sm text-theme-on-surface-variant">This is a draft of ReaDex's Terms for user-added content sources. It is not final legal text and has not been reviewed by a lawyer yet. It exists so there is something concrete in place while the app is being built.</native:text>

        <native:text class="text-sm text-theme-on-background">{{ $clause }}</native:text>

        <native:button label="I understand, continue" variant="primary" @press="agree" />
    </native:column>
</native:scroll-view>
