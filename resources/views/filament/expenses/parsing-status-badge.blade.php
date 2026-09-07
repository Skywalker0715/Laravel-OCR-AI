@if ($isWaitingForParsing)
    <div wire:poll.3s="checkParsingStatus" class="flex w-full">
        <x-filament::badge
            color="warning"
            icon="heroicon-o-arrow-path"
            class="animate-pulse"
        >
            <span class="inline-flex items-center gap-1.5">
                <x-filament::loading-indicator class="h-4 w-4" />
                Sedang diproses...
            </span>
        </x-filament::badge>
    </div>
@endif
