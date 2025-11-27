<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GoogleGeminiService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;

    public function __construct()
    {
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
        $this->timeout = config('article-generator.google.timeout', 900);
        $this->maxRetries = config('article-generator.google.max_retries', 3);
        $this->apiKey = config('article-generator.google.api_key');
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function analyzeSEO(array $params): array
    {
        $cacheKey = 'seo_analysis_gemini_' . md5(json_encode([
            'query' => $params['query'],
            'country' => $params['country'],
            'language' => $params['language']
        ]));

        if (config('article-generator.cache.enabled')) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                Log::info('SEO analysis loaded from cache (Gemini)', ['query' => $params['query']]);
                return $cached;
            }
        }

        $systemPrompt = $this->buildSEOAnalysisPrompt($params);
        $model = $params['model'] ?? config('article-generator.google.default_model');
        
        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $systemPrompt],
                        ['text' => "Проанализируй запрос: {$params['query']}"]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 4000
            ]
        ];

        Log::info('Google Gemini SEO Analysis started', ['query' => $params['query']]);
        
        $response = $this->makeRequest($model, 'generateContent', $requestData);
        $data = $response->json();

        $result = [
            'analysis' => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
            'tokens_used' => $data['usageMetadata']['totalTokenCount'] ?? 0,
            'model' => $model
        ];

        if (config('article-generator.cache.enabled')) {
            Cache::put($cacheKey, $result, config('article-generator.cache.ttl'));
        }

        Log::info('Google Gemini SEO Analysis completed', [
            'query' => $params['query'],
            'tokens' => $result['tokens_used']
        ]);

        return $result;
    }

    public function generateArticlePrompt(array $seoAnalysis, array $params): array
    {
        $systemPrompt = "Ты эксперт по созданию промптов для написания SEO-статей. " .
            "На основе детального SEO-анализа создай исчерпывающий промпт на {$params['language']} " .
            "для генерации статьи объемом {$params['word_count']} слов.";

        $userContent = "SEO Анализ:\n" . $seoAnalysis['analysis'] . 
            "\n\nСоздай детальный промпт для написания статьи.";

        $model = $params['model'] ?? config('article-generator.google.default_model');
        
        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $systemPrompt],
                        ['text' => $userContent]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.8,
                'maxOutputTokens' => 3000
            ]
        ];

        Log::info('Google Gemini Prompt Generation started');
        
        $response = $this->makeRequest($model, 'generateContent', $requestData);
        $data = $response->json();

        $result = [
            'prompt' => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
            'tokens_used' => $data['usageMetadata']['totalTokenCount'] ?? 0
        ];

        Log::info('Google Gemini Prompt Generation completed', ['tokens' => $result['tokens_used']]);

        return $result;
    }

    public function generateArticle(string $articlePrompt, array $params): array
    {
        $model = $params['model'] ?? config('article-generator.google.default_model');
        
        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $articlePrompt],
                        ['text' => 'Напиши статью согласно всем требованиям в промпте.']
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.9,
                'maxOutputTokens' => 8000
            ]
        ];

        Log::info('Google Gemini Article Generation started');
        
        $response = $this->makeRequest($model, 'generateContent', $requestData);
        $data = $response->json();

        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        $result = $this->parseArticleResponse($content, $data['usageMetadata']['totalTokenCount'] ?? 0);

        Log::info('Google Gemini Article Generation completed', [
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
            "Статья должна быть экспертной, хорошо структурированной с заголовками H1-H3.";

        $model = $params['model'] ?? config('article-generator.google.default_model');
        
        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $systemPrompt],
                        ['text' => 'Напиши статью']
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.85,
                'maxOutputTokens' => 8000
            ]
        ];

        Log::info('Google Gemini Simple Article Generation started', ['keyword' => $keywords]);
        
        $response = $this->makeRequest($model, 'generateContent', $requestData);
        $data = $response->json();

        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        $result = $this->parseArticleResponse($content, $data['usageMetadata']['totalTokenCount'] ?? 0);

        Log::info('Google Gemini Simple Article completed', [
            'keyword' => $keywords,
            'word_count' => $result['word_count']
        ]);

        return $result;
    }

    public function testConnection(): array
    {
        try {
            $model = config('article-generator.google.default_model');
            $requestData = [
                'contents' => [
                    ['parts' => [['text' => 'Test connection']]]
                ]
            ];
            
            $this->makeRequest($model, 'generateContent', $requestData);
            
            return [
                'success' => true,
                'service' => 'Google Gemini',
                'message' => 'Connection successful'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'service' => 'Google Gemini',
                'message' => $e->getMessage()
            ];
        }
    }

    private function makeRequest(string $model, string $method, array $data)
    {
        if (!$this->apiKey) {
            throw new Exception('Google Gemini API key not set');
        }

        $url = "{$this->baseUrl}/models/{$model}:{$method}?key={$this->apiKey}";

        $response = Http::timeout($this->timeout)
            ->retry($this->maxRetries, 5000, function ($exception, $request) {
                Log::warning('Google Gemini API retry', ['error' => $exception->getMessage()]);
                return true;
            })
            ->post($url, $data);

        if (!$response->successful()) {
            $error = $response->json()['error']['message'] ?? 'Unknown error';
            Log::error('Google Gemini API error', [
                'status' => $response->status(),
                'error' => $error
            ]);
            throw new Exception("Google Gemini API error ({$response->status()}): {$error}");
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
Ты ведущий SEO-стратег с глубоким пониманием семантики веб-поиска и современных алгоритмов поисковых систем.

Параметры анализа:
- Целевой запрос: {$query}
- Страна: {$country}
- Язык: {$language}
- Тип страницы: {$pageType}
- Обязательные ключевые слова: {$requiredKeywords}

Выполни комплексный SEO-анализ с рекомендациями по структуре, ключевым словам и метаданным.
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
