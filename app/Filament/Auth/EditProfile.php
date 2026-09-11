<?php

namespace App\Filament\Auth;

use App\Services\DeleteUserAccountService;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Halaman Profile kustom: memperluas halaman bawaan Filament v4 dengan
 * aksi destruktif "Hapus Akun" — user bisa menghapus permanen akunnya
 * beserta seluruh datanya (expenses, budget, kategori pribadi, notifikasi).
 *
 * Konfirmasi 2 langkah wajib: user harus mengetik ulang email DAN password
 * mereka sebelum penghapusan dieksekusi; ada pemeriksaan ulang (identik)
 * di closure action sebagai safety net terhadap pemanggilan yang
 * melewati validasi form.
 */
class EditProfile extends BaseEditProfile
{
    /**
     * Aksi form "Hapus Akun": buka modal konfirmasi destruktif. Field
     * konfirmasi divalidasi oleh rule kustom sebelum closure action
     * dijalankan (validasi form destruktif standar Filament).
     */
    public function deleteAccountAction(): Action
    {
        return Action::make('deleteAccount')
            ->label('Hapus Akun')
            ->color('danger')
            ->requiresConfirmation(false)
            ->modalHeading('Hapus Akun Permanen')
            ->modalWidth('xl')
            ->schema($this->deleteAccountConfirmationSchema())
            ->action(function (Action $action): void {
                $this->processDeleteAccount($action);
            });
    }

    /**
     * Daftarkan aksi "Hapus Akun" sebagai HEADER action halaman. Pada layout
     * "simple" (default di panel ini) Filament TIDAK merender header actions,
     * sehingga tombol DIREALISASI juga di body halaman via override content()
     * di bawah — lihat docblock class. Method tetap berguna sebagai fallback
     * jika kelak panel dipindah ke layout penuh (isSimple: false).
     *
     * @return array<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->deleteAccountAction(),
        ];
    }

    /**
     * Rendera konten halaman: form profil bawaan (Nama/Email, ubah Password)
     * dari BaseEditProfile DIIKUTI oleh zona "Hapus Akun" di paling bawah.
     *
     * PERBAIKAN (bug form hilang): Schema::components() bersifat REPLACING
     * (bukan append) — ia menimpa seluruh komponen yang sudah diset parent
     * (lihat Filament\Schemas\Concerns\HasComponents::components() yang melakukan
     * `$this->components = $components`). Pada versi sebelumnya override ini
     * memanggil parent::content($schema)->components([ Hanya Section Hapus Akun ]),
     * sehingga komponen form asli (Nama/Email/Password) DIGANTI TOTAL oleh
     * 1 card "Hapus Akun" — form hilang total.
     *
     * Solusi: baca komponen asli yang sudah di-set parent lewat
     * getComponents(), lalu GABUNGKAN (merge) dengan section "Hapus Akun"
     * baru. PHP mengevaluasi argumen $schema->getComponents() SEBELUM
     * ->components([...]) dieksekusi, jadi urutannya tepat — parent populate
     * dulu, kita baca, lalu kami ganti dengan array yang sudah digabung.
     *
     * Akibatnya urutan tampilan halaman profile (atas ke bawah):
     *   (a) form edit Nama & Email (dari BaseEditProfile),
     *   (b) form ubah Password (dari BaseEditProfile),
     *   (c) card "Hapus Akun" (ditambahkan di paling bawah).
     *
     * Section berbahaya dibuat sebagai BAGIAN BODY halaman (tombol aksi via
     * component Actions), bukan header — karena halaman profile di panel ini
     * memakai layout "simple" yang tidak merender header actions.
     */
    public function content(Schema $schema): Schema
    {
        return parent::content($schema)
            ->components([
                ...$schema->getComponents(),
                Section::make('Hapus Akun')
                    ->description('Menghapus akun Anda beserta seluruh data (expenses, budget, kategori pribadi, notifikasi). Tindakan ini permanen dan tidak dapat dibatalkan.')
                    ->icon('heroicon-o-trash')
                    ->schema([
                        Actions::make([
                            $this->deleteAccountAction(),
                        ]),
                    ]),
            ]);
    }

    /**
     * Schema modal konfirmasi: peringatan permanen + field ketik ulang
     * email & password. Tombol submit modal hanya dieksekusi bila kedua
     * rule kustom lolos (pola konfirmasi destruktif standar).
     *
     * @return array<\Filament\Schemas\Components\Component>
     */
    protected function deleteAccountConfirmationSchema(): array
    {
        return [
            Section::make('Tindakan ini tidak dapat dibatalkan')
                ->description('Semua data (expenses, budget, kategori pribadi, notifikasi) akan dihapus PERMANEN dan tidak bisa dikembalikan.')
                ->schema([
                    Placeholder::make('warningDetail')
                        ->content(new HtmlString(
                            '<span class="text-sm leading-relaxed block">'
                            .'Yang akan dihapus dari akun Anda:<br>'
                            .'• Seluruh <strong>expense</strong> beserta itemnya &amp; foto struk fisik<br>'
                            .'• Seluruh <strong>budget</strong> (anggaran)<br>'
                            .'• Seluruh <strong>kategori pribadi</strong> — kategori default sistem tetap utuh<br>'
                            .'• Seluruh <strong>notifikasi</strong><br>'
                            .'• Record <strong>akun</strong> itu sendiri — Anda akan otomatis keluar (logout)'
                            .'</span>'
                        )),
                    TextInput::make('email')
                        ->label('Ketik ulang email Anda untuk konfirmasi')
                        ->email()
                        ->required()
                        ->rule($this->emailMatchRule()),
                    TextInput::make('password')
                        ->label('Ketik password Anda untuk konfirmasi')
                        ->password()
                        ->revealable()
                        ->required()
                        ->rule($this->passwordMatchRule()),
                ]),
        ];
    }

    /**
     * Rule kustom: email yang diketik harus persis (case-insensitive)
     * dengan email akun yang sedang login.
     *
     * Pola Filament v4: closure luar tanpa parameter (dievaluasi oleh
     * komponen form) mengembalikan closure rule Laravel (attribute/value/fail)
     * yang dijalankan validator. Contoh pola dari Filament sendiri ada di
     * vendor/filament/filament/src/Auth/MultiFactor/App/Actions/.
     */
    protected function emailMatchRule(): \Closure
    {
        $expected = (string) auth()->user()?->email;

        return function () use ($expected): \Closure {
            return function (string $attribute, mixed $value, \Closure $fail) use ($expected): void {
                if (strcasecmp(trim((string) $value), $expected) !== 0) {
                    $fail('Email tidak cocok dengan email akun Anda.');
                }
            };
        };
    }

    /**
     * Rule kustom: password yang diketik harus cocok dengan hash password
     * akun yang sedang login (pola closure sama dengan emailMatchRule()).
     */
    protected function passwordMatchRule(): \Closure
    {
        $expectedHash = (string) auth()->user()?->password;

        return function () use ($expectedHash): \Closure {
            return function (string $attribute, mixed $value, \Closure $fail) use ($expectedHash): void {
                if (! Hash::check((string) $value, $expectedHash)) {
                    $fail('Password salah. Penghapusan akun dibatalkan.');
                }
            };
        };
    }

    /**
     * Eksekusi penghapusan akun: verifikasi ulang kredensial (safety net),
     * hapus seluruh data via service (transaction), lalu logout dan
     * redirect ke halaman login dengan pesan sukses.
     *
     * @param \Filament\Actions\Action $action Aksi mounted yang sedang jalan —
     *        data form validasi didapat via getData().
     */
    protected function processDeleteAccount(Action $action): void
    {
        $data = $action->getData();

        // Safety net: cek ulang kredensial identik dengan rule form, agar
        // service TIDAK PERNAH jalan tanpa konfirmasi email + password persis.
        if (strcasecmp(trim((string) ($data['email'] ?? '')), (string) auth()->user()?->email) !== 0
            || ! Hash::check((string) ($data['password'] ?? ''), (string) auth()->user()?->password)) {
            Notification::make()
                ->title('Konfirmasi tidak valid — penghapusan akun dibatalkan')
                ->danger()
                ->send();

            return;
        }

        $user = auth()->user();

        // Penting — logout harus terjadi SEBELUM record user dihapus. Perilaku
        // laravel logout(): SessionGuard memutar remember_token via
        // EloquentUserProvider::updateRememberToken() yang memanggil
        // $user->save(). Bila logout dipanggil SETELAH $user->delete() (model
        // sudah exists=false), save() itu akan melakukan INSERT ulang baris
        // user yang barusan dihapus (bug tersembunyi — terekam lewat query log
        // saat tes: `insert into "users" ... id` muncul tepat setelah `delete`
        // dari service). Dengan memanggil logout lebih dulu, save() hanya
        // memperbarui remember_token pada baris yang MASIH ADA, lalu service
        // menghapus baris itu permanen.
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        app(DeleteUserAccountService::class)->delete($user);

        Notification::make()
            ->title('Akun Anda berhasil dihapus')
            ->body('Semua data telah dihapus permanen. Terima kasih telah menggunakan Catatan Belanja.')
            ->success()
            ->send();

        $this->redirect(Filament::hasLogin() ? Filament::getLoginUrl() : Filament::getUrl());
    }
}
