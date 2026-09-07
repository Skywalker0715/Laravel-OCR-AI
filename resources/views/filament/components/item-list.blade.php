<div style="padding: 1.5rem;">
    <h2 style="font-size: 1.25rem; line-height: 1.75rem; font-weight: 700; color: var(--cb-strong); margin-bottom: 1rem;">
        Detail Item - {{ $expense->title }}
    </h2>

    @if($expense->vendor)
        <p style="margin-bottom: 0.5rem; color: var(--cb-strong);">
            <strong>Vendor:</strong> {{ $expense->vendor }}
        </p>
    @endif

    @if($expense->date_shopping)
        <p style="margin-bottom: 0.5rem; color: var(--cb-strong);">
            <strong>Tanggal Belanja:</strong> {{ $expense->date_shopping }}
        </p>
    @endif

    @if($expense->amount)
        <p style="margin-bottom: 1rem; color: var(--cb-strong);">
            <strong>Total:</strong> {{ \App\Support\MoneyFormatter::format($expense->amount) }}
        </p>
    @endif

    @if($items && count($items) > 0)
        <table style="width: 100%; border-collapse: collapse; border: 1px solid var(--cb-border); color: var(--cb-strong);">
            <thead>
                <tr style="background-color: var(--cb-surface);">
                    <th style="border: 1px solid var(--cb-border); padding: 0.5rem 1rem; text-align: left;">Nama Item</th>
                    <th style="border: 1px solid var(--cb-border); padding: 0.5rem 1rem; text-align: right;">Qty</th>
                    <th style="border: 1px solid var(--cb-border); padding: 0.5rem 1rem; text-align: right;">Harga</th>
                    <th style="border: 1px solid var(--cb-border); padding: 0.5rem 1rem; text-align: right;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td style="border: 1px solid var(--cb-border); padding: 0.5rem 1rem;">{{ $item->name }}</td>
                        <td style="border: 1px solid var(--cb-border); padding: 0.5rem 1rem; text-align: right;">{{ \App\Support\MoneyFormatter::number($item->qty) }}</td>
                        <td style="border: 1px solid var(--cb-border); padding: 0.5rem 1rem; text-align: right;">{{ \App\Support\MoneyFormatter::format($item->price) }}</td>
                        <td style="border: 1px solid var(--cb-border); padding: 0.5rem 1rem; text-align: right;">{{ \App\Support\MoneyFormatter::format($item->subtotal) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="color: var(--cb-muted);">Tidak ada item untuk struk ini.</p>
    @endif

    <div style="margin-top: 1.5rem;">
        <a href="{{ \Filament\Facades\Filament::getUrl() }}" style="color: var(--cb-link); text-decoration: underline; text-decoration-style: dotted;">← Kembali ke Daftar</a>
    </div>
</div>
