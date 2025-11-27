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

        $prompts = $this->getPrompts($params['language']);
        $systemPrompt = $this->buildSEOAnalysisPrompt($params, $prompts);
        $model = $params['model'] ?? config('article-generator.google.default_model');
        
        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $systemPrompt],
                        ['text' => $prompts['seo_user_prefix'] . ": {$params['query']}"]
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
        $prompts = $this->getPrompts($params['language']);
        
        $systemPrompt = str_replace(
            ['{language}', '{word_count}'],
            [$params['language'], $params['word_count']],
            $prompts['prompt_generation_system']
        );

        $userContent = $prompts['seo_analysis_prefix'] . ":\n" . $seoAnalysis['analysis'] . 
            "\n\n" . $prompts['prompt_generation_command'];

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
        $prompts = $this->getPrompts($params['language']);
        $model = $params['model'] ?? config('article-generator.google.default_model');
        
        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $articlePrompt],
                        ['text' => $prompts['article_write_command']]
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
        $prompts = $this->getPrompts($params['language']);
        $keywords = $params['keyword'];
        $requiredKeywords = !empty($params['required_keywords']) 
            ? $prompts['required_keywords_prefix'] . ': ' . implode(', ', $params['required_keywords']) 
            : '';

        $systemPrompt = str_replace(
            ['{language}', '{country}', '{word_count}', '{page_type}', '{required_keywords}'],
            [$params['language'], $params['country'], $params['word_count'], $params['page_type'], $requiredKeywords],
            $prompts['simple_article_system']
        );

        $model = $params['model'] ?? config('article-generator.google.default_model');
        
        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $systemPrompt],
                        ['text' => $prompts['simple_article_command'] . ": {$keywords}"]
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

    private function buildSEOAnalysisPrompt(array $params, array $prompts): string
    {
        return str_replace(
            ['{query}', '{country}', '{language}', '{page_type}', '{required_keywords}'],
            [
                $params['query'],
                $params['country'],
                $params['language'],
                $params['page_type'],
                !empty($params['required_keywords']) ? implode(', ', $params['required_keywords']) : $prompts['not_specified']
            ],
            $prompts['seo_analysis_system']
        );
    }

    private function parseArticleResponse(string $content, int $tokensUsed): array
    {
        $metaTitles = [];
        $metaDescriptions = [];

        if (preg_match('/<json>(.*?)<\/json>/s', $content, $jsonMatch)) {
            $json = json_decode($jsonMatch[1], true);
            if ($json) {
                $metaTitles = $json['meta_titles'] ?? [];
                $metaDescriptions = $json['meta_descriptions'] ?? [];
            }
            $content = str_replace($jsonMatch[0], '', $content);
        }

        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $content, $h1Match)) {
            $title = strip_tags($h1Match[1]);
        } else {
            preg_match('/#\s+(.+)$/m', $content, $titleMatches);
            $title = $titleMatches[1] ?? 'Untitled Article';
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
            'content' => trim($content),
            'meta_titles' => array_slice($metaTitles, 0, 5),
            'meta_descriptions' => array_slice($metaDescriptions, 0, 5),
            'tokens_used' => $tokensUsed,
            'word_count' => str_word_count(strip_tags($content))
        ];
    }

    private function getPrompts(string $language): array
    {
        $isRussian = (stripos($language, 'ru') !== false || stripos($language, 'рус') !== false);

        if ($isRussian) {
            return [
                'seo_analysis_system' => "Ты ведущий SEO-стратег с глубоким пониманием семантики веб-поиска.\n\nПараметры:\n- Запрос: {query}\n- Страна: {country}\n- Язык: {language}\n- Тип: {page_type}\n- Обязательные слова: {required_keywords}\n\nВыполни SEO-анализ: интент, SERP, структура, ключевые слова.",
                'seo_user_prefix' => 'Проанализируй запрос',
                'seo_analysis_prefix' => 'SEO Анализ',
                'prompt_generation_system' => 'Ты эксперт по созданию промптов для SEO-статей на {language}. Объем: {word_count} слов. Создай детальный промпт с ролью, структурой H1-H3, тоном, ключевыми словами.',
                'prompt_generation_command' => 'Создай детальный промпт для написания статьи',
                'article_write_command' => 'Напиши статью согласно всем требованиям в промпте',
                'simple_article_system' => 'Ты профессиональный SEO-копирайтер.\n\nТребования:\n- Язык: {language}\n- Страна: {country}\n- Объем: {word_count} слов\n- Тип: {page_type}\n{required_keywords}\n\nФормат: HTML (h1-h3, p, ul, li). В конце добавь блок <json>{"meta_titles": ["...", "...", "...", "...", "..."], "meta_descriptions": ["...", "...", "...", "...", "..."]}</json> с 5 вариантами.',
                'simple_article_command' => 'Напиши экспертную статью на тему',
                'required_keywords_prefix' => 'Обязательные ключевые слова',
                'not_specified' => 'не указаны'
            ];
        }

        return [
            'seo_analysis_system' => "You are a leading SEO strategist with deep understanding of web search semantics.\n\nParameters:\n- Query: {query}\n- Country: {country}\n- Language: {language}\n- Page Type: {page_type}\n- Required Keywords: {required_keywords}\n\nPerform comprehensive SEO analysis: intent, SERP, structure, keywords.",
            'seo_user_prefix' => 'Analyze the query',
            'seo_analysis_prefix' => 'SEO Analysis',
            'prompt_generation_system' => 'You are an expert in creating prompts for SEO articles in {language}. Target: {word_count} words. Create detailed prompt with role, H1-H3 structure, tone, keywords.',
            'prompt_generation_command' => 'Create a detailed prompt for writing the article',
            'article_write_command' => 'Write the article according to all requirements in the prompt. Output valid HTML. Add JSON metadata at the end.',
            'simple_article_system' => 'You are a professional SEO copywriter.\n\nRequirements:\n- Language: {language}\n- Country: {country}\n- Word Count: {word_count}\n- Type: {page_type}\n{required_keywords}\n\nFormat: HTML (h1-h3, p, ul, li). At the end add <json>{"meta_titles": ["...", "...", "...", "...", "..."], "meta_descriptions": ["...", "...", "...", "...", "..."]}</json> block with 5 variants.',
            'simple_article_command' => 'Write an expert article about',
            'required_keywords_prefix' => 'Required keywords',
            'not_specified' => 'not specified'
        ];
    }
}
