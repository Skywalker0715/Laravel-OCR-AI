<div class="space-y-4">
    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
        <x-filament::icon family="regular" name="chat-bubble-left-ellipsis" class="w-5 h-5 text-primary" />
        Tanya AI tentang Pengeluaran
    </h3>

    <p class="text-sm text-gray-500">
        Bertanya tentang total pengeluaran, kategori paling boros, rata-rata harian.
        Jawaban dihasilkan AI berdasarkan data expense Anda.
    </p>

    @if($user)
        <div
            x-data="{
                question: '',
                answer: @json($answer ?? null),
                loading: false,
                error: null,
                ask: async function() {
                    if (!this.question.trim()) return;
                    this.loading = true;
                    this.error = null;
                    try {
                        const res = await fetch('{{ $panelUrl }}/filament/widgets/AiInsightChatWidget', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ $csrfToken }}',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                question: this.question,
                                action: 'ask',
                            }),
                        });
                        const data = await res.json();
                        this.answer = data.message || data.data || '';
                    } catch (e) {
                        this.error = 'Terjadi kesalahan. Coba lagi nanti.';
                    } finally {
                        this.loading = false;
                    }
                }
            }"
            class="rounded-xl border border-gray-200 bg-white shadow-sm p-4 space-y-3"
        >
            <div class="flex gap-2">
                <textarea
                    x-model="question"
                    rows="3"
                    required
                    class="flex-1 rounded-lg border-gray-300 bg-gray-50 text-gray-900 placeholder-gray-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary sm:text-sm"
                    placeholder="Contoh: Berapa total pengeluaran saya 90 hari terakhir?"
                ></textarea>
            </div>

            <div class="flex justify-between items-center">
                <p
                    x-show="error && !answer"
                    x-text="error"
                    class="text-sm text-red-600"
                ></p>
                <button
                    @click="ask()"
                    :disabled="loading || !question.trim()"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary/90 focus:outline-none focus:ring-1 focus:ring-primary disabled:opacity-50"
                >
                    <svg x-show="loading" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span x-show="!loading">Tanya AI</span>
                </button>
            </div>

            <div x-show="answer" class="pt-3 border-t border-gray-100">
                <div class="flex gap-2 mb-2">
                    <x-filament::icon family="regular" name="bot" class="w-5 h-5 text-indigo-500 flex-shrink-0" />
                    <p class="text-xs font-medium text-indigo-600 uppercase tracking-wide">AI Assistant</p>
                </div>
                <div class="text-gray-700 text-sm leading-relaxed whitespace-pre-wrap" x-text="answer"></div>
                <p class="mt-3 text-xs text-gray-400 flex items-start gap-1">
                    <svg class="w-3.5 h-3.5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <span>Jawaban dihasilkan AI berdasarkan data yang tercatat, mohon verifikasi untuk keputusan penting.</span>
                </p>
            </div>
        </div>

        @if(!empty($history))
            <div>
                <h4 class="text-sm font-medium text-gray-700 mb-2 flex items-center gap-1.5">
                    <x-filament::icon family="regular" name="clock" class="w-4 h-4" />
                    3 Pertanyaan Terakhir
                </h4>
                <div class="space-y-2">
                    @foreach($history as $item)
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm">
                            <p class="text-gray-400 text-xs mb-1">{{ $item['created_at'] }}</p>
                            <p class="font-medium text-gray-800">{{ $item['question'] }}</p>
                            <p class="text-gray-600 mt-1 text-xs leading-relaxed">{{ $item['answer'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-6 text-center">
            <p class="text-sm text-gray-500">Anda belum login. Silakan login terlebih dahulu.</p>
        </div>
    @endif
</div>
