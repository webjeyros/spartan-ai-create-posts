<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class OpenAIService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;

    public function __construct()
    {
        $this->baseUrl = config('article-generator.openai.api_base', 'https://api.openai.com/v1');
        $this->timeout = config('article-generator.openai.timeout', 900);
        $this->maxRetries = config('article-generator.openai.max_retries', 3);
        $this->apiKey = config('article-generator.openai.api_key');
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function analyzeSEO(array $params): array
    {
        $cacheKey = 'seo_analysis_' . md5(json_encode([
            'query' => $params['query'],
            'country' => $params['country'],
            'language' => $params['language']
        ]));

        if (config('article-generator.cache.enabled')) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                Log::info('SEO analysis loaded from cache', ['query' => $params['query']]);
                return $cached;
            }
        }

        $systemPrompt = $this->buildSEOAnalysisPrompt($params);
        
        $requestData = [
            'model' => $params['model'] ?? config('article-generator.openai.default_model'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Проанализируй запрос: {$params['query']}"]
            ],
            'temperature' => 0.7,
            'max_tokens' => 4000
        ];

        Log::info('OpenAI SEO Analysis started', ['query' => $params['query']]);
        
        $response = $this->makeRequest('chat/completions', $requestData);
        $data = $response->json();

        $result = [
            'analysis' => $data['choices'][0]['message']['content'] ?? '',
            'tokens_used' => $data['usage']['total_tokens'] ?? 0,
            'model' => $requestData['model']
        ];

        if (config('article-generator.cache.enabled')) {
            Cache::put($cacheKey, $result, config('article-generator.cache.ttl'));
        }

        Log::info('OpenAI SEO Analysis completed', [
            'query' => $params['query'],
            'tokens' => $result['tokens_used']
        ]);

        return $result;
    }

    public function generateArticlePrompt(array $seoAnalysis, array $params): array
    {
        $systemPrompt = "Ты эксперт по созданию промптов для написания SEO-статей. " .
            "На основе детального SEO-анализа создай исчерпывающий промпт на {$params['language']} " .
            "для генерации статьи объемом {$params['word_count']} слов.\n\n" .
            "Промпт должен включать:\n" .
            "- Роль для копирайтера\n" .
            "- Цель статьи\n" .
            "- Целевую аудиторию\n" .
            "- Тон и стиль\n" .
            "- Детальную структуру (H1-H3)\n" .
            "- Ключевые слова для использования\n" .
            "- Особые инструкции";

        $userContent = "SEO Анализ:\n" . $seoAnalysis['analysis'] . 
            "\n\nСоздай детальный промпт для написания статьи.";

        $requestData = [
            'model' => $params['model'] ?? config('article-generator.openai.default_model'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent]
            ],
            'temperature' => 0.8,
            'max_tokens' => 3000
        ];

        Log::info('OpenAI Prompt Generation started');
        
        $response = $this->makeRequest('chat/completions', $requestData);
        $data = $response->json();

        $result = [
            'prompt' => $data['choices'][0]['message']['content'] ?? '',
            'tokens_used' => $data['usage']['total_tokens'] ?? 0
        ];

        Log::info('OpenAI Prompt Generation completed', ['tokens' => $result['tokens_used']]);

        return $result;
    }

    public function generateArticle(string $articlePrompt, array $params): array
    {
        $requestData = [
            'model' => $params['model'] ?? config('article-generator.openai.default_model'),
            'messages' => [
                ['role' => 'system', 'content' => $articlePrompt],
                ['role' => 'user', 'content' => 'Напиши статью согласно всем требованиям в промпте.']
            ],
            'temperature' => 0.9,
            'max_tokens' => 8000
        ];

        Log::info('OpenAI Article Generation started');
        
        $response = $this->makeRequest('chat/completions', $requestData);
        $data = $response->json();

        $content = $data['choices'][0]['message']['content'] ?? '';
        
        $result = $this->parseArticleResponse($content, $data['usage']['total_tokens'] ?? 0);

        Log::info('OpenAI Article Generation completed', [
            'word_count' => $result['word_count'],
            'tokens' => $result['tokens_used']
        ]);

        return $result;
    }

    public function generateSimpleArticle(array $params): array
    {
        $keywords = $params['keyword'];
        $requiredKeywords = !empty($params['required_keywords']) 
            ? 'Обязательные ключевые слова: ' . implode(', ', $params['required_keywords']) 
            : '';

        $systemPrompt = "Ты профессиональный копирайтер и SEO-специалист. " .
            "Напиши статью на тему: {$keywords}.\n\n" .
            "Требования:\n" .
            "- Язык: {$params['language']}\n" .
            "- Страна: {$params['country']}\n" .
            "- Объем: {$params['word_count']} слов\n" .
            "- Назначение: {$params['page_type']}\n" .
            "{$requiredKeywords}\n\n" .
            "Статья должна быть экспертной, хорошо структурированной с заголовками H1-H3, " .
            "содержать практическую ценность. В конце добавь 5 вариантов мета-тайтлов " .
            "и 5 вариантов мета-дескрипшенов.";

        $requestData = [
            'model' => $params['model'] ?? config('article-generator.openai.default_model'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => 'Напиши статью']
            ],
            'temperature' => 0.85,
            'max_tokens' => 8000
        ];

        Log::info('OpenAI Simple Article Generation started', ['keyword' => $keywords]);
        
        $response = $this->makeRequest('chat/completions', $requestData);
        $data = $response->json();

        $content = $data['choices'][0]['message']['content'] ?? '';
        
        $result = $this->parseArticleResponse($content, $data['usage']['total_tokens'] ?? 0);

        Log::info('OpenAI Simple Article completed', [
            'keyword' => $keywords,
            'word_count' => $result['word_count']
        ]);

        return $result;
    }

    public function testConnection(): array
    {
        try {
            $response = $this->makeRequest('models', [], 'GET');
            return [
                'success' => true,
                'service' => 'OpenAI',
                'message' => 'Connection successful'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'service' => 'OpenAI',
                'message' => $e->getMessage()
            ];
        }
    }

    private function makeRequest(string $endpoint, array $data = [], string $method = 'POST')
    {
        if (!$this->apiKey) {
            throw new Exception('OpenAI API key not set');
        }

        $url = $this->baseUrl . '/' . $endpoint;

        $http = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])
        ->timeout($this->timeout)
        ->retry($this->maxRetries, 5000, function ($exception, $request) {
            Log::warning('OpenAI API retry', ['error' => $exception->getMessage()]);
            return true;
        });

        $response = match (strtoupper($method)) {
            'GET' => $http->get($url, $data),
            'POST' => $http->post($url, $data),
            default => throw new Exception('Unsupported HTTP method: ' . $method),
        };

        if (!$response->successful()) {
            $error = $response->json()['error']['message'] ?? 'Unknown error';
            Log::error('OpenAI API error', [
                'status' => $response->status(),
                'error' => $error,
                'endpoint' => $endpoint
            ]);
            throw new Exception("OpenAI API error ({$response->status()}): {$error}");
        }

        return $response;
    }

    private function buildSEOAnalysisPrompt(array $params): string
    {
        $query = $params['query'];
        $country = $params['country'];
        $language = $params['language'];
        $pageType = $params['page_type'];
        $requiredKeywords = !empty($params['required_keywords']) 
            ? implode(', ', $params['required_keywords']) 
            : 'не указаны';

        return <<<PROMPT
Ты ведущий SEO-стратег и исследователь поисковых систем с глубоким пониманием семантики веб-поиска, 
современных архитектур поискового ИИ (включая Трансформеры, BERT, MUM, RankBrain), принципов работы 
с Сущностями (Entities), Онтологиями, Таксономиями, графами знаний.

Параметры анализа:
- Целевой запрос: {$query}
- Страна: {$country}
- Язык: {$language}
- Тип страницы: {$pageType}
- Обязательные ключевые слова: {$requiredKeywords}

Выполни комплексный SEO-анализ:

1. АНАЛИЗ SERP (Google для {$country}):
   - Типы контента в топ-10
   - Вопросы из "People Also Ask"
   - Анализ AI Overviews
   - SERP features

2. МОДЕЛИРОВАНИЕ ИНТЕРПРЕТАЦИИ ЗАПРОСА:
   - Распознавание сущностей (NER)
   - Таксономическая ветка
   - Определение интента
   - Семантические ожидания от контента

3. РЕКОМЕНДАЦИИ ПО ОПТИМИЗАЦИИ:
   - Структура статьи (детальная иерархия H1-H3)
   - Ключевые слова и LSI
   - Семантическое ядро
   - Обязательные разделы для раскрытия

4. МЕТАДАННЫЕ:
   - 5 вариантов мета-тайтлов (50-58 символов)
   - 5 вариантов мета-дескрипшенов (140-155 символов)

PROMPT;
    }

    private function parseArticleResponse(string $content, int $tokensUsed): array
    {
        preg_match('/#\s+(.+)$/m', $content, $titleMatches);
        $title = $titleMatches[1] ?? 'Untitled Article';

        $metaTitles = [];
        if (preg_match_all('/Мета-тайтл.*?:\s*(.+)$/m', $content, $matches)) {
            $metaTitles = array_slice($matches[1], 0, 5);
        }

        $metaDescriptions = [];
        if (preg_match_all('/Мета-дескрипшен.*?:\s*(.+)$/m', $content, $matches)) {
            $metaDescriptions = array_slice($matches[1], 0, 5);
        }

        if (empty($metaTitles)) {
            $metaTitles = [$title];
        }
        if (empty($metaDescriptions)) {
            $preview = substr(strip_tags($content), 0, 150);
            $metaDescriptions = [$preview];
        }

        return [
            'title' => $title,
            'content' => $content,
            'meta_titles' => $metaTitles,
            'meta_descriptions' => $metaDescriptions,
            'tokens_used' => $tokensUsed,
            'word_count' => str_word_count(strip_tags($content))
        ];
    }
}
