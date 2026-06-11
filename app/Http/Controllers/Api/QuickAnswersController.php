<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class QuickAnswersController extends Controller
{
    /**
     * Get popular Q&A pairs for offline caching.
     * Returns the most frequently asked questions with their answers.
     */
    public function index(Request $request)
    {
        $limit = min((int) $request->query('limit', 20), 50);
        $language = $request->query('language', 'ur');

        // Cache for 1 hour
        $cacheKey = "quick_answers_{$language}_{$limit}";

        $quickAnswers = Cache::remember($cacheKey, 3600, function () use ($limit, $language) {
            // Get most common user questions asked in the requested language
            $popularQuestions = Message::where('role', 'user')
                ->whereHas('chat', fn ($q) => $q->where('language', $language))
                ->selectRaw('content, COUNT(*) as question_count')
                ->groupBy('content')
                ->orderByDesc('question_count')
                ->having('question_count', '>=', 2)
                ->limit($limit * 2) // Get more to filter
                ->get();

            $qaPairs = [];

            foreach ($popularQuestions as $question) {
                if (count($qaPairs) >= $limit) break;

                // Find the best answer for this question
                $answer = Message::where('role', 'assistant')
                    ->whereHas('chat.messages', function ($q) use ($question) {
                        $q->where('role', 'user')
                            ->where('content', $question->content);
                    })
                    ->whereNotNull('citations')
                    ->where('content', 'not like', '%معذرت%')
                    ->where('content', 'not like', '%sorry%')
                    ->orderByDesc('created_at')
                    ->first();

                if ($answer && strlen($answer->content) > 50) {
                    $qaPairs[] = [
                        'id' => md5($question->content),
                        'question' => $question->content,
                        'answer' => $answer->content,
                        'popularity' => $question->question_count,
                    ];
                }
            }

            return $qaPairs;
        });

        return response()->json([
            'success' => true,
            'data' => $quickAnswers,
            'cached_at' => now()->toIso8601String(),
        ]);
    }
}
