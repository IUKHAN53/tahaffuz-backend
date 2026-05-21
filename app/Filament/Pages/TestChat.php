<?php

namespace App\Filament\Pages;

use App\Models\Chat;
use App\Models\KnowledgeBase;
use App\Services\Rag\ChatPipeline;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Operator-facing playground for exercising the RAG assistant: text turns in
 * English / Urdu / Roman Urdu (or pure auto-detect), per knowledge base, with
 * retrieval citations, scores and latency surfaced for debugging.
 */
class TestChat extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Test Chat';

    protected static ?string $title = 'Test Chat';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.test-chat';

    public string $message = '';

    public string $language = 'auto';

    public ?int $knowledgeBaseId = null;

    public ?int $chatId = null;

    /** @var array<int, array<string, mixed>> */
    public array $turns = [];

    public function mount(): void
    {
        $kb = KnowledgeBase::where('is_default', true)->first() ?? KnowledgeBase::query()->first();
        $this->knowledgeBaseId = $kb?->id;
    }

    /** @return array<int, string> */
    public function getKnowledgeBaseOptions(): array
    {
        return KnowledgeBase::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    public function getLanguageOptions(): array
    {
        return [
            'auto' => 'Auto-detect',
            'en' => 'English',
            'ur' => 'Urdu',
            'rud' => 'Roman Urdu',
        ];
    }

    public function send(): void
    {
        $message = trim($this->message);
        if ($message === '') {
            return;
        }

        if (! $this->knowledgeBaseId) {
            Notification::make()->title('Select a knowledge base first.')->danger()->send();

            return;
        }

        $language = $this->language === 'auto' ? null : $this->language;

        try {
            $pipeline = app(ChatPipeline::class);
            $chat = $pipeline->findOrCreateChat('admin-playground', $this->knowledgeBaseId, $this->chatId);
            $this->chatId = $chat->id;

            $this->turns[] = ['role' => 'user', 'content' => $message];
            $this->message = '';

            $result = $pipeline->answerText($chat, $message, $language);
            $reply = $result['message'];

            $this->turns[] = [
                'role' => 'assistant',
                'content' => $reply->content,
                'citations' => $result['citations'],
                'latency' => $reply->latency_ms,
                'refused' => (bool) ($reply->meta['refused'] ?? false),
            ];
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Chat request failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function startNewChat(): void
    {
        if ($this->chatId) {
            Chat::where('id', $this->chatId)->where('device_id', 'admin-playground')->delete();
        }

        $this->chatId = null;
        $this->turns = [];
        $this->message = '';
    }
}
