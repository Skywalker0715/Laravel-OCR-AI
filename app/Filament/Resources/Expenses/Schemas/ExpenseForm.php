<?php

namespace App\Filament\Resources\Expenses\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;
use Throwable;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        // Kolom `qty`/`price`/`subtotal` bertipe decimal(10,2) sehingga nilai
        // utuh seperti 5200 tersimpan sebagai "5200.00". Normalisasi tampilan
        // (hapus trailing ".00") supaya input item terlihat rapi; nilai asli
        // yang disimpan ke database tidak terpengaruh.
        $normalizeNumber = static fn (mixed $state): mixed => (is_numeric($state) && $state !== null && $state !== '')
            ? (string) ((float) $state)
            : $state;

        return $schema
            // Form schema ini dipakai bersama oleh halaman Create dan Edit.
            // Tata letak: dua card utama (Informasi Belanja & Foto Struk)
            // berdampingan dalam grid 2 kolom via ->columns(2) langsung pada
            // Schema (pendekatan native Filament, bukan komponen Grid
            // terpisah). Kolom kiri = Informasi Belanja, kolom kanan = Foto
            // Struk. Tombol aksi (Create / Save changes) & Cancel ditempatkan
            // di footer Section Informasi Belanja (lihat ->footerActions)
            // agar berada tepat di bawah isi card kolom kiri dan tidak
            // menunggu tinggi card Foto Struk (kolom kanan) yang jauh lebih
            // tinggi.
            //
            // Di bawahnya ada dua Section tambahan (masing-masing lebar penuh)
            // agar hasil OCR/AI bisa dikoreksi manual oleh user:
            //   - "Detail Pembayaran" : vendor, total, kembalian, tanggal belanja
            //   - "Daftar Item Belanja": Repeater relasi ke expense_items
            //     (edit nama/qty/harga/subtotal, tambah item, atau hapus item).
            // Subtotal dihitung otomatis dari qty×harga, tapi tetap bisa
            // di-override manual bila perlu.
            //
            // Kedua Section tersebut HANYA dirender pada operasi 'edit': saat
            // Create, data OCR/AI belum ada (OCR baru berjalan setelah save),
            // sehingga field kosongnya disembunyikan agar tidak membingungkan
            // user — halaman Create cukup menampilkan "Informasi Belanja" dan
            // "Foto Struk".
            ->columns(2)
            ->components([
                Section::make('Informasi Belanja')
                    ->columnSpan(1)
                    ->icon(Heroicon::OutlinedShoppingBag)
                    ->iconColor('success')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->label('Judul Belanja')
                            ->placeholder('Masukkan judul belanja...')
                            ->columnSpan(1),

                        Select::make('category_id')
                            ->label('Kategori')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder('Pilih kategori (opsional)')
                            ->columnSpan(1),
                    ])
                    // Tombol aksi & Cancel ditempatkan di footer Section ini
                    // (bukan di footer form paling bawah yang full-width
                    // mengambang) agar tombol muncul tepat di bawah isi card
                    // "Informasi Belanja" (kolom kiri) dan tidak menunggu
                    // tinggi card "Foto Struk" (kolom kanan) yang jauh lebih
                    // tinggi.
                    //
                    // Form schema ini dipakai bersama (shared) oleh halaman
                    // Create dan Edit. Agar tidak menumpuk / duplikat aksi di
                    // form footer:
                    //  - Edit  : aksi "Save changes" ($operation === 'edit').
                    //  - Create: aksi "Create" + "Create & create another"
                    //    ($operation === 'create') — pola yang sama dengan
                    //    Save/Cancel di Edit.
                    //  - "Cancel" dipakai bersama Create & Edit (handler
                    //    redirect-back-nya identik dengan aksi cancel bawaan
                    //    Filament).
                    //  - di CreateExpense::getFormActions() dan
                    //    EditExpense::getFormActions() dikembalikan [] untuk
                    //    menghilangkan aksi bawaan di form footer, sehingga
                    //    tombol HANYA ada di footer Section ini.
                    ->footerActions([
                        Action::make('save')
                            ->label('Save changes')
                            ->visible(fn ($operation) => $operation === 'edit')
                            ->action('save')
                            ->keyBindings(['mod+s']),

                        Action::make('create')
                            ->label('Create')
                            ->visible(fn ($operation) => $operation === 'create')
                            ->action('create')
                            ->keyBindings(['mod+s']),

                        Action::make('createAnother')
                            ->label('Create & create another')
                            ->color('gray')
                            ->visible(fn ($operation) => $operation === 'create')
                            ->action('createAnother')
                            ->keyBindings(['mod+shift+s']),

                        Action::make('cancel')
                            ->label('Cancel')
                            ->color('gray')
                            ->visible(fn ($operation) => $operation === 'create' || $operation === 'edit')
                            ->alpineClickHandler(fn ($livewire) => 'document.referrer ? window.history.back() : (window.location.href = '.Js::from($livewire->previousUrl ?? $livewire->getResourceUrl()).')'),
                    ]),

                Section::make('Foto Struk')
                    ->columnSpan(1)
                    ->icon(Heroicon::OutlinedPhoto)
                    ->iconColor('success')
                    ->schema([
                        FileUpload::make('receipt_image')
                            // Tanpa label field duplikat: cukup tampilkan
                            // area upload gambar langsung di bawah judul
                            // Section "Foto Struk".
                            ->label(null)
                            ->image()
                            // Batas ukuran file 5 MB (5120 KB) — guard DoS
                            // untuk foto struk; tanpa ini batasnya hanya
                            // default Livewire/php.ini di server.
                            ->maxSize(5120)
                            // Preview "contain" (tidak di-crop) dgn tinggi
                            // lumayan (500px) supaya struk panjang terlihat
                            // penuh. panelAspectRatio sengaja TIDAK dipakai
                            // (itu yang memaksa crop).
                            ->imagePreviewHeight('500')
                            ->loadingIndicatorPosition('left')
                            ->panelLayout('integrated')
                            ->removeUploadedFileButtonPosition('right')
                            ->uploadButtonPosition('left')
                            ->uploadProgressIndicatorPosition('left')
                            // Resize saat upload: 'contain' menjaga aspect
                            // ratio dalam kotak 1600x1600; upscale(false)
                            // tak pernah memperbesar gambar. (Kompresi file
                            // final tetap dilakukan server-side oleh
                            // ImageCompressor.)
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth(1600)
                            ->imageResizeTargetHeight(1600)
                            ->imageResizeUpscale(false)
                            // Disk privat 'receipts' (temuan audit #2): file
                            // fisik di storage/app/private/receipts, TIDAK
                            // bisa diakses lewat /storage (public). Preview
                            // file yang sudah tersimpan memakai URL route
                            // terotorisasi — lihat getUploadedFileUsing().
                            ->disk('receipts')
                            ->visibility('private')
                            ->getUploadedFileUsing(function (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array {
                                // Override hook preview file TERSIMPAN (default
                                // vendor memakai temporaryUrl()/Storage::url()
                                // yang tidak valid untuk disk privat lokal):
                                // URL preview dibangun lewat route terotorisasi
                                // /receipt-image/{expense} yang hanya bisa
                                // diakses pemilik expense. File yang baru
                                // diunggah (belum tersimpan) tetap ditangani
                                // Filament lewat URL sementara Livewire.
                                $storage = $component->getDisk();

                                try {
                                    if (! $storage->exists($file)) {
                                        return null;
                                    }
                                } catch (Throwable) {
                                    return null;
                                }

                                // Record expense hanya terisi di halaman Edit.
                                // Di halaman Create belum ada file tersimpan,
                                // sehingga hook ini tidak pernah terpanggil.
                                $livewire = $component->getLivewire();
                                $record = method_exists($livewire, 'getRecord')
                                    ? $livewire->getRecord()
                                    : null;

                                return [
                                    'name' => ($component->isMultiple() ? ($storedFileNames[$file] ?? null) : $storedFileNames) ?? basename($file),
                                    'size' => $storage->size($file),
                                    'type' => $storage->mimeType($file),
                                    'url' => $record !== null
                                        ? route('receipt-image.show', ['expense' => $record->getKey()])
                                        : null,
                                ];
                            })
                            ->required()
                            ->directory('receipts'),
                    ]),

                // Detail Pembayaran: field hasil OCR/AI (vendor, total, kembalian,
                // tanggal belanja) kini bisa dikoreksi manual oleh user. Kolom dibatasi
                // grid 2 kolom agar terlihat rapi:
                //   Baris 1 : Vendor            (2 kolom, mengisi penuh)
                //   Baris 2 : Total + Kembalian (masing-masing 1 kolom)
                //   Baris 3 : Tanggal Belanja   (2 kolom, mengisi penuh)
                Section::make('Detail Pembayaran')
                    // Hanya tampil di Edit: saat Create, data hasil OCR/AI
                    // belum ada (OCR baru berjalan setelah save), sehingga
                    // field kosong ini disembunyikan agar tidak membingungkan.
                    ->hidden(fn (string $operation): bool => $operation === 'create')
                    ->columnSpanFull()
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->iconColor('success')
                    ->columns(2)
                    ->schema([
                        TextInput::make('vendor')
                            ->label('Vendor')
                            ->placeholder('Nama toko/vendor asal struk (opsional)')
                            ->columnSpan(2),

                        TextInput::make('amount')
                            ->label('Total')
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0)
                            ->placeholder('0')
                            ->columnSpan(1),

                        TextInput::make('change')
                            ->label('Kembalian')
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0)
                            ->placeholder('0')
                            ->columnSpan(1),

                        DatePicker::make('date_shopping')
                            ->label('Tanggal Belanja')
                            ->displayFormat('d F Y')
                            ->placeholder('Pilih tanggal belanja')
                            ->columnSpan(2),
                    ]),

                // Daftar Item Belanja: item hasil OCR/AI kini bisa dikoreksi manual
                // lewat Repeater berelasi ke expense_items — edit nama/qty/harga,
                // tambah item baru, atau hapus item yang salah/tidak perlu.
                //
                // Subtotal tiap item dihitung otomatis dari qty×harga saat qty atau
                // harga diubah (onBlur), namun fieldnya tetap bisa diedit manual
                // oleh user jika perlu override.
                //
                // Hanya tampil di Edit — alasan sama dengan Section
                // "Detail Pembayaran" di atas.
                Section::make('Daftar Item Belanja')
                    ->hidden(fn (string $operation): bool => $operation === 'create')
                    ->columnSpanFull()
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->iconColor('success')
                    ->schema([
                        Repeater::make('items')
                            ->label(null)
                            ->relationship()
                            ->defaultItems(0)
                            ->addActionLabel('Tambah Item')
                            // Alokasi grid: layar besar (lg) memakai 12 kolom —
                            // Nama 4, Qty 2, Harga 3, Subtotal 3 (Harga & Subtotal
                            // diberi porsi lebih lebar supaya angka 6 digit ke
                            // atas, mis. 500000, tidak terpotong oleh prefix
                            // "Rp"); layar kecil menumpuk 1 kolom penuh agar
                            // semua angka tetap terbaca utuh.
                            ->columns(['default' => 1, 'lg' => 12])
                            ->itemLabel(fn (array $state): string => filled($state['name'] ?? null) ? $state['name'] : 'Item Baru')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nama Item')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->placeholder('Nama barang...')
                                    ->columnSpan(['lg' => 4]),

                                TextInput::make('qty')
                                    ->label('Qty')
                                    ->numeric()
                                    ->step(0.01)
                                    ->minValue(0)
                                    ->default(1)
                                    ->formatStateUsing($normalizeNumber)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Set $set, Get $get): void {
                                        $set('subtotal', round(((float) ($get('qty') ?? 0)) * ((float) ($get('price') ?? 0)), 2));
                                    })
                                    ->columnSpan(['lg' => 2]),

                                TextInput::make('price')
                                    ->label('Harga')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->step(0.01)
                                    ->minValue(0)
                                    ->default(0)
                                    ->formatStateUsing($normalizeNumber)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Set $set, Get $get): void {
                                        $set('subtotal', round(((float) ($get('qty') ?? 0)) * ((float) ($get('price') ?? 0)), 2));
                                    })
                                    ->columnSpan(['lg' => 3]),

                                TextInput::make('subtotal')
                                    ->label('Subtotal')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->step(0.01)
                                    ->minValue(0)
                                    ->default(0)
                                    ->formatStateUsing($normalizeNumber)
                                    ->placeholder('Otomatis dari qty × harga')
                                    ->columnSpan(['lg' => 3]),
                            ]),
                    ]),

                // Field teknis disembunyikan dari form, akan diisi otomatis oleh sistem
                Textarea::make('note')
                    ->hidden()
                    ->columnSpanFull(),

                TextInput::make('parsed_data')
                    ->hidden(),
            ]);
    }
}
