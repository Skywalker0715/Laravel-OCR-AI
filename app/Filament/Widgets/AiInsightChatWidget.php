<?php

namespace App\Filament\Widgets;

use App\Models\AiInsightQuery;
use App\Services\FinancialInsightService;
use App\Exceptions\RateLimitExceededException;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class AiInsightChatWidget extends Widget
{
    protected int $maxHistory = 3;

    protected static ?string $heading = 'Tanya AI';

    protected function getView(): string
    {
        return 'filament.widgets.ai-insight-chat-widget';
    }

    public function getData(): array
    {
        $history = [];
        $user = Auth::user();

        // Handle POST action dari form chat (Alpine.js).
        if ($user && request()->method() === 'POST' && request()->input('action') === 'ask') {
            return [
                'history' => $history,
                'user' => $user,
                'csrfToken' => csrf_token(),
                'panelUrl' => filament()->panel()->url(),
                'answer' => $this->handleAsk(request()->input('question') ?? ''),
            ];
        }

        if ($user) {
            $history = AiInsightQuery::query()
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->take($this->maxHistory)
                ->get()
                ->toArray();
        }

        return [
            'history' => $history,
            'user' => $user,
            'csrfToken' => csrf_token(),
            'panelUrl' => filament()->panel()->url(),
        ];
    }

    public function handleAsk(string $question): ?string
    {
        $user = Auth::user();
        if (! $user) {
            return 'Harap login terlebih dahulu.';
        }

        try {
            $service = app(FinancialInsightService::class);
            $answer = $service->ask($user, $question);

            AiInsightQuery::create([
                'user_id' => $user->id,
                'question' => $question,
                'answer' => $answer,
            ]);

            return $answer;
        } catch (RateLimitExceededException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            return 'Maaf, terjadi kesalahan. Coba lagi nanti.';
        }
    }
}
