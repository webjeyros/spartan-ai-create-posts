<?php

namespace App\Jobs;

use App\Models\ArticleGeneration;
use App\Services\ArticleGenerationService;
use App\Services\OpenAIService;
use App\Services\GoogleGeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class GenerateArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private int $generationId,
        private string $scenario,
        private array $params
    ) {
        $this->onQueue('article-generation');
    }

    public function handle(
        ArticleGenerationService $articleService,
        OpenAIService $openAIService,
        GoogleGeminiService $googleGeminiService
    ): void
    {
        $generation = ArticleGeneration::findOrFail($this->generationId);

        try {
            Log::info('Generation job started', [
                'job_id' => $generation->job_id,
                'keyword' => $this->params['keyword'],
                'scenario' => $this->scenario
            ]);

            $generation->update(['status' => 'processing']);

            if (!empty($this->params['openai_api_key'])) {
                $openAIService->setApiKey($this->params['openai_api_key']);
            }
            if (!empty($this->params['google_api_key'])) {
                $googleGeminiService->setApiKey($this->params['google_api_key']);
            }

            $result = $articleService->generateArticle($this->params, $this->scenario);

            $generation->update([
                'status' => 'completed',
                'result' => $result,
                'tokens_used' => $result['tokens_used'],
                'completed_at' => now()
            ]);

            Log::info('Generation job completed successfully', [
                'job_id' => $generation->job_id,
                'keyword' => $this->params['keyword'],
                'word_count' => $result['word_count'],
                'generation_time' => $result['generation_time']
            ]);

        } catch (Exception $e) {
            Log::error('Generation job failed', [
                'job_id' => $generation->job_id,
                'keyword' => $this->params['keyword'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $generation->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        $generation = ArticleGeneration::find($this->generationId);
        
        if ($generation) {
            $generation->update([
                'status' => 'failed',
                'error_message' => 'Job failed after ' . $this->tries . ' attempts: ' . $exception->getMessage()
            ]);
        }

        Log::error('Generation job permanently failed', [
            'generation_id' => $this->generationId,
            'keyword' => $this->params['keyword'] ?? 'unknown',
            'error' => $exception->getMessage()
        ]);
    }
}
