<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            // retry_after = berapa detik Laravel menunggu sebelum menganggap
            // job "hilang" dan membawanya kembali ke antrean.
            //
            // ATURAN WAJIB: retry_after HARUS lebih besar dari $timeout job
            // terpanjang (App\Jobs\AIParserJob::$timeout = 150 detik). Kalau
            // kebalikan, job yang masih berjalan di worker pertama dianggap
            // mati, lalu DIAMBIL worker kedua dan dieksekusi ganda: parsing
            // struk jalan dua kali, pengguna menerima notifikasi kembar, dan
            // tabel expense_items saling delete-create antar dua proses.
            //
            // 180 dipilih karena memberi jarak 30 detik di atas timeout job
            // (150) — jarak ini penting, bukan sekadar angka bulat: OCR Tesseract
            // + request Cohere + penyimpanan DB bisa menyentuh batas timeout,
            // dan retry_after yang hanya sedikit di atasnya akan memicu eksekusi
            // ganda persis di detik-detik paling menegangkan.
            //
            // Worker juga WAJIB dijalankan dengan `--timeout` yang >= timeout
            // job (lihat README bagian "Menjalankan Aplikasi").
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 180),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            // Nilai yang sama dengan koneksi 'database' di atas: aturan
            // retry_after > $timeout job berlaku untuk driver apa pun yang
            // menyatan job lewat database, bukan hanya koneksi database.
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 180),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // Sama seperti koneksi database: retry_after > $timeout job.
            // Penting khusus untuk Redis karena worker Redis menandai job
            // dengan "visibility timeout" — job yang masih diproses tapi sudah
            // melewati retry_after akan kembali ke antrean dan dieksekusi ganda.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 180),
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
