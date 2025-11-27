<?php

use App\Http\Controllers\Api\ArticleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.key', 'throttle:60,1'])->prefix('articles')->group(function () {
    Route::post('/generate', [ArticleController::class, 'generate']);
    Route::get('/status/{jobId}', [ArticleController::class, 'status']);
    Route::get('/history', [ArticleController::class, 'history']);
    Route::post('/test', [ArticleController::class, 'test']);
});

Route::middleware(['api.key'])->get('/stats', function () {
    $stats = [
        'total_generations' => \App\Models\ArticleGeneration::count(),
        'completed' => \App\Models\ArticleGeneration::completed()->count(),
        'failed' => \App\Models\ArticleGeneration::failed()->count(),
        'in_progress' => \App\Models\ArticleGeneration::pending()->count(),
        'total_words_generated' => \App\Models\ArticleGeneration::completed()
            ->get()
            ->sum(fn($gen) => $gen->result['word_count'] ?? 0),
        'total_tokens_used' => \App\Models\ArticleGeneration::completed()
            ->get()
            ->sum(fn($gen) => $gen->tokens_used['total'] ?? 0),
    ];

    return response()->json([
        'success' => true,
        'data' => $stats
    ]);
});
