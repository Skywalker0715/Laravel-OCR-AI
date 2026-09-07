<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'dev:logs')]
class DevLogs extends Command
{
    protected $signature = 'dev:logs';

    protected $description = 'Pelengkap "composer run dev": menampilkan log aplikasi real-time lewat pail. Otomatis dilewati di platform tanpa ekstensi pcntl (mis. Windows/Laragon).';

    /**
     * Pelengkap "composer run dev": meneruskan ke `php artisan pail` bila pcntl
     * tersedia, atau keluar dengan peringatan di platform tanpa pcntl (Windows).
     */
    public function handle(): int
    {
        if (! function_exists('pcntl_fork')) {
            $this->components->warn(
                'Pail tidak didukung di platform ini (butuh ekstensi pcntl) - dilewati. '
                .'Log bisa dipantau lewat storage/logs/laravel.log atau jalankan php artisan pail manual di lingkungan Linux/Mac.'
            );

            return self::SUCCESS;
        }

        return $this->call('pail');
    }
}
