<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Catatan Belanja — Catat pengeluaran otomatis, cukup foto struk belanja</title>
    <meta name="description" content="Catatan Belanja adalah aplikasi pencatat pengeluaran otomatis berbasis OCR dan AI. Cukup foto struk belanja, data tersimpan rapi.">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" href="/favicon.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    },
                },
            },
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
</head>
<body class="font-sans antialiased bg-white text-slate-800">

    <!-- ===== NAVBAR ===== -->
    <header class="sticky top-0 z-50 bg-white/80 backdrop-blur-md border-b border-slate-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="/" class="flex items-center gap-2.5">
                    <img src="/icon.jpg" alt="Logo Catatan Belanja" class="w-9 h-9 rounded-xl object-cover shadow-lg shadow-emerald-500/20 ring-2 ring-emerald-100">
                    <span class="text-lg font-bold text-slate-900">Catatan<span class="text-emerald-600">Belanja</span></span>
                </a>
                <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-600">
                    <a href="#" data-scroll-target="cara-kerja" class="hover:text-emerald-600 transition-colors">Cara Kerja</a>
                    <a href="#" data-scroll-target="fitur" class="hover:text-emerald-600 transition-colors">Fitur</a>
                </nav>
                <a href="/admin" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 rounded-lg shadow-md shadow-emerald-600/20 transition-all duration-200 hover:shadow-lg hover:shadow-emerald-600/30 hover:-translate-y-0.5">
                    Masuk
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </a>
            </div>
        </div>
    </header>

    <!-- ===== HERO ===== -->
    <section class="relative overflow-hidden">
        <!-- Background decoration -->
        <div class="absolute inset-0 bg-gradient-to-b from-emerald-50/70 via-white to-white pointer-events-none"></div>
        <div class="absolute -top-24 -right-24 w-96 h-96 bg-emerald-200/40 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute top-40 -left-24 w-80 h-80 bg-teal-200/40 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 lg:py-32">
            <div class="text-center max-w-4xl mx-auto">
                <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-emerald-100/80 text-emerald-800 text-xs sm:text-sm font-semibold border border-emerald-200 mb-6">
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                    </span>
                    Didukung Teknologi OCR & AI
                </span>
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight text-slate-900 leading-tight">
                    Catat pengeluaran otomatis,<br class="hidden sm:block">
                    cukup <span class="text-transparent bg-clip-text bg-gradient-to-r from-emerald-600 to-teal-600">foto struk belanja</span>
                </h1>
                <p class="mt-6 text-lg sm:text-xl text-slate-600 max-w-2xl mx-auto">
                    Catatan Belanja membantu Anda mengelola keuangan dengan mudah. Unggah foto struk, biarkan OCR & AI membaca otomatis, dan semua pengeluaran tercatat rapi.
                </p>
                <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/admin" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 text-base font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 rounded-xl shadow-lg shadow-emerald-600/25 transition-all duration-200 hover:shadow-xl hover:shadow-emerald-600/30 hover:-translate-y-0.5">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                        </svg>
                        Masuk ke Dashboard
                    </a>
                    <a href="#" data-scroll-target="cara-kerja" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 text-base font-semibold text-slate-700 bg-white border border-slate-200 hover:border-emerald-300 hover:text-emerald-700 rounded-xl shadow-sm transition-all duration-200 hover:shadow-md">
                        Pelajari Cara Kerja
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                        </svg>
                    </a>
                </div>
                <p class="mt-6 text-sm text-slate-400">Gratis digunakan • Tanpa ribet • Data aman</p>
            </div>
        </div>
    </section>

    <!-- ===== CARA KERJA ===== -->
    <section id="cara-kerja" class="py-20 lg:py-28 bg-slate-50/70">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16">
                <span class="text-sm font-bold text-emerald-600 uppercase tracking-wider">Cara Kerja</span>
                <h2 class="mt-3 text-3xl sm:text-4xl font-extrabold text-slate-900">Hanya 3 Langkah Mudah</h2>
                <p class="mt-4 text-lg text-slate-600">Mulai mencatat pengeluaran dalam hitungan detik, bukan menit.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-8 lg:gap-10">
                <!-- Step 1 -->
                <div class="relative bg-white rounded-2xl p-8 shadow-sm border border-slate-100 hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                    <div class="absolute -top-5 left-8 w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center text-xl font-bold shadow-lg shadow-emerald-500/30">1</div>
                    <div class="mt-8">
                        <div class="w-14 h-14 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center mb-5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3">Upload Foto Struk</h3>
                        <p class="text-slate-600 leading-relaxed">Ambil foto struk belanja Anda menggunakan kamera HP atau unggah dari galeri. Mudah dan cepat.</p>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="relative bg-white rounded-2xl p-8 shadow-sm border border-slate-100 hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                    <div class="absolute -top-5 left-8 w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center text-xl font-bold shadow-lg shadow-emerald-500/30">2</div>
                    <div class="mt-8">
                        <div class="w-14 h-14 rounded-xl bg-teal-100 text-teal-600 flex items-center justify-center mb-5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v9a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3">OCR & AI Membaca Otomatis</h3>
                        <p class="text-slate-600 leading-relaxed">Teknologi OCR dan AI kami membaca setiap detail struk secara otomatis dan akurat.</p>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="relative bg-white rounded-2xl p-8 shadow-sm border border-slate-100 hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                    <div class="absolute -top-5 left-8 w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center text-xl font-bold shadow-lg shadow-emerald-500/30">3</div>
                    <div class="mt-8">
                        <div class="w-14 h-14 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center mb-5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4V9m3 11H6a2 2 0 01-2-2V6a2 2 0 012-2h12a2 2 0 012 2v12a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3">Data Tersimpan Rapi</h3>
                        <p class="text-slate-600 leading-relaxed">Semua pengeluaran tercatat otomatis dan tersimpan rapi. Pantau keuangan kapan saja, di mana saja.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== FITUR ===== -->
    <section id="fitur" class="py-20 lg:py-28">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <div>
                    <span class="text-sm font-bold text-emerald-600 uppercase tracking-wider">Fitur Unggulan</span>
                    <h2 class="mt-3 text-3xl sm:text-4xl font-extrabold text-slate-900 leading-tight">Kelola Keuangan Lebih Cerdas dengan Teknologi Terkini</h2>
                    <p class="mt-4 text-lg text-slate-600">Kami menggabungkan kekuatan OCR dan kecerdasan buatan untuk memberikan pengalaman pencatatan keuangan terbaik.</p>
                    <ul class="mt-8 space-y-4">
                        <li class="flex items-start gap-3">
                            <span class="flex-shrink-0 w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mt-0.5">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </span>
                            <span class="text-slate-700"><strong class="font-semibold text-slate-900">Akurasi Tinggi</strong> — Teknologi OCR canggih memastikan setiap angka terbaca dengan tepat.</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="flex-shrink-0 w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mt-0.5">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </span>
                            <span class="text-slate-700"><strong class="font-semibold text-slate-900">Hemat Waktu</strong> — Cukup beberapa detik, pengeluaran langsung tercatat tanpa mengetik manual.</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="flex-shrink-0 w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mt-0.5">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </span>
                            <span class="text-slate-700"><strong class="font-semibold text-slate-900">Aman & Terpercaya</strong> — Data keuangan Anda tersimpan aman dan terenkripsi.</span>
                        </li>
                    </ul>
                </div>
                <div class="relative">
                    <div class="absolute inset-0 bg-gradient-to-br from-emerald-200 to-teal-200 rounded-3xl rotate-3"></div>
                    <div class="relative bg-white rounded-3xl shadow-2xl p-8 border border-slate-100">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-bold text-slate-900">Ringkasan Pengeluaran</h3>
                            <span class="px-3 py-1 text-xs font-semibold text-emerald-700 bg-emerald-100 rounded-full">Bulan Ini</span>
                        </div>
                        <div class="space-y-4">
                            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                                <p class="text-sm text-slate-500">Total Pengeluaran</p>
                                <p class="text-3xl font-extrabold text-slate-900 mt-1">Rp 1.250.000</p>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-100">
                                    <p class="text-xs text-slate-500">Transaksi</p>
                                    <p class="text-xl font-bold text-slate-900 mt-1">24</p>
                                </div>
                                <div class="p-4 rounded-2xl bg-teal-50 border border-teal-100">
                                    <p class="text-xs text-slate-500">Rata-rata</p>
                                    <p class="text-xl font-bold text-slate-900 mt-1">Rp 52rb</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== CTA ===== -->
    <section class="py-20 lg:py-28 bg-slate-900 relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-r from-emerald-900/50 to-teal-900/50"></div>
        <div class="absolute -top-24 -left-24 w-96 h-96 bg-emerald-500/20 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-24 -right-24 w-96 h-96 bg-teal-500/20 rounded-full blur-3xl"></div>
        <div class="relative max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white leading-tight">Siap Mengelola Keuangan Lebih Baik?</h2>
            <p class="mt-6 text-lg sm:text-xl text-slate-300 max-w-2xl mx-auto">Mulai catat pengeluaran Anda hari ini dan rasakan kemudahan mengelola keuangan dengan Catatan Belanja.</p>
            <a href="/admin" class="mt-10 inline-flex items-center gap-2 px-10 py-4 text-lg font-semibold text-emerald-900 bg-white hover:bg-emerald-50 rounded-xl shadow-xl transition-all duration-200 hover:shadow-2xl hover:-translate-y-0.5">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                Masuk ke Dashboard
            </a>
        </div>
    </section>

    <!-- ===== FOOTER ===== -->
    <footer class="bg-slate-950 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <img src="/icon.jpg" alt="Logo Catatan Belanja" class="inline-block w-7 h-7 rounded-lg object-cover ring-2 ring-white/20">
                <span class="text-sm font-semibold text-white">Catatan<span class="text-emerald-400">Belanja</span></span>
            </div>
            <p class="text-sm text-slate-500">&copy; {{ date('Y') }} Catatan Belanja. Semua hak dilindungi.</p>
        </div>
    </footer>

    <script>
        // Smooth scroll tanpa mengubah URL (tanpa hash)
        document.querySelectorAll('[data-scroll-target]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var target = document.getElementById(this.getAttribute('data-scroll-target'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    </script>

</body>
</html>