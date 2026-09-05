<?php

// Override terjemahan Global Search panel Filament (namespace filament-panels).
// Hanya key "placeholder" yang dioverride; key lain tetap memakai bawaan
// package karena Laravel melakukan merge rekursif dengan file vendor.

return [

    'field' => [
        'placeholder' => 'Cari expense, kategori, atau budget...',
    ],

];
