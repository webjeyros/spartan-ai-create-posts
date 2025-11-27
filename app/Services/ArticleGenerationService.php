<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class ArticleGenerationService
{
    public function __construct(
        private OpenAIService $openAIService,
        private GoogleGeminiService $googleGeminiService,
        private RateLimiterService $rateLimiter
    ) {}

    public function generateArticle(array $params, string $scenario): array
    {
        Log::info('Article generation started', [
            'scenario' => $scenario,
            'keyword' => $params['keyword'],
            'word_count' => $params['word_count']
        ]);

        $startTime = microtime(true);

        try {
            $result = match($scenario) {
                'three_tier_both' => $this->generateThreeTierBoth($params),
                'three_tier_gpt' => $this->generateThreeTierGPT($params),
                'three_tier_gemini' => $this->generateThreeTierGemini($params),
                'simple_gpt' => $this->generateSimpleGPT($params),
                'simple_gemini' => $this->generateSimpleGemini($params),
                default => throw new Exception("Unknown scenario: {$scenario}")
            };

            $result['generation_time'] = round(microtime(true) - $startTime, 2);
            $result['scenario'] = $scenario;

            Log::info('Article generation completed', [
                'scenario' => $scenario,
                'keyword' => $params['keyword'],
                'word_count' => $result['word_count'],
                'generation_time' => $result['generation_time']
            ]);

            return $result;

        } catch (Exception $e) {
            Log::error('Article generation failed', [
                'scenario' => $scenario,
                'keyword' => $params['keyword'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function generateThreeTierBoth(array $params): array
    {
        $tokensUsed = [];

        $this->rateLimiter->checkLimit('google');
        $seoAnalysis = $this->googleGeminiService->analyzeSEO($params);
        $tokensUsed['stage_1'] = $seoAnalysis['tokens_used'];
        $this->rateLimiter->recordRequest('google');

        $this->rateLimiter->checkLimit('openai');
        $promptResult = $this->openAIService->generateArticlePrompt($seoAnalysis, $params);
        $tokensUsed['stage_2'] = $promptResult['tokens_used'];
        $this->rateLimiter->recordRequest('openai');

        $this->rateLimiter->checkLimit('openai');
        $article = $this->openAIService->generateArticle($promptResult['prompt'], $params);
        $tokensUsed['stage_3'] = $article['tokens_used'];
        $this->rateLimiter->recordRequest('openai');

        $article['tokens_used'] = array_merge($tokensUsed, [
            'total' => array_sum($tokensUsed)
        ]);

        return $article;
    }

    private function generateThreeTierGPT(array $params): array
    {
        $tokensUsed = [];

        $this->rateLimiter->checkLimit('openai');
        $seoAnalysis = $this->openAIService->analyzeSEO($params);
        $tokensUsed['stage_1'] = $seoAnalysis['tokens_used'];
        $this->rateLimiter->recordRequest('openai');

        $this->rateLimiter->checkLimit('openai');
        $promptResult = $this->openAIService->generateArticlePrompt($seoAnalysis, $params);
        $tokensUsed['stage_2'] = $promptResult['tokens_used'];
        $this->rateLimiter->recordRequest('openai');

        $this->rateLimiter->checkLimit('openai');
        $article = $this->openAIService->generateArticle($promptResult['prompt'], $params);
        $tokensUsed['stage_3'] = $article['tokens_used'];
        $this->rateLimiter->recordRequest('openai');

        $article['tokens_used'] = array_merge($tokensUsed, [
            'total' => array_sum($tokensUsed)
        ]);

        return $article;
    }

    private function generateThreeTierGemini(array $params): array
    {
        $tokensUsed = [];

        $this->rateLimiter->checkLimit('google');
        $seoAnalysis = $this->googleGeminiService->analyzeSEO($params);
        $tokensUsed['stage_1'] = $seoAnalysis['tokens_used'];
        $this->rateLimiter->recordRequest('google');

        $this->rateLimiter->checkLimit('google');
        $promptResult = $this->googleGeminiService->generateArticlePrompt($seoAnalysis, $params);
        $tokensUsed['stage_2'] = $promptResult['tokens_used'];
        $this->rateLimiter->recordRequest('google');

        $this->rateLimiter->checkLimit('google');
        $article = $this->googleGeminiService->generateArticle($promptResult['prompt'], $params);
        $tokensUsed['stage_3'] = $article['tokens_used'];
        $this->rateLimiter->recordRequest('google');

        $article['tokens_used'] = array_merge($tokensUsed, [
            'total' => array_sum($tokensUsed)
        ]);

        return $article;
    }

    private function generateSimpleGPT(array $params): array
    {
        $this->rateLimiter->checkLimit('openai');
        $article = $this->openAIService->generateSimpleArticle($params);
        $this->rateLimiter->recordRequest('openai');

        $article['tokens_used'] = [
            'stage_1' => $article['tokens_used'],
            'total' => $article['tokens_used']
        ];

        return $article;
    }

    private function generateSimpleGemini(array $params): array
    {
        $this->rateLimiter->checkLimit('google');
        $article = $this->googleGeminiService->generateSimpleArticle($params);
        $this->rateLimiter->recordRequest('google');

        $article['tokens_used'] = [
            'stage_1' => $article['tokens_used'],
            'total' => $article['tokens_used']
        ];

        return $article;
    }

    public function testConnections(): array
    {
        return [
            'openai' => $this->openAIService->testConnection(),
            'google' => $this->googleGeminiService->testConnection()
        ];
    }
}
