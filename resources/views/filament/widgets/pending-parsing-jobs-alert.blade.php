<x-filament-widgets::widget>
    <x-filament::section
        icon="heroicon-o-arrow-path"
        icon-color="warning"
        :heading="$heading"
        :description="$description"
    >
        <p style="font-size: 0.875rem; line-height: 1.25rem; color: var(--cb-muted);">
            {{ $message }}
        </p>

        <div style="margin-top: 0.75rem;">
            <x-filament::button
                tag="a"
                color="warning"
                :href="$indexUrl"
            >
                Buka daftar Expense
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
