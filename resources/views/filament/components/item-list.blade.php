<div class="space-y-2 text-sm">
    @forelse ($items as $item)
        <div class="flex justify-between">
            <span>{{ $item->qty }}x {{ $item->name }}</span>
            <span class="text-right">Rp{{ number_format(num: $item->subtotal) }}</span>
        </div>
    @empty
        <p class="text-gray-500">Belum ada items tercatat.</p>
    @endforelse
</div>