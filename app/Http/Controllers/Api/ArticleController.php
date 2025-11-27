<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateArticleJob;
use App\Models\ArticleGeneration;
use App\Services\ArticleGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function __construct(
        private ArticleGenerationService $articleService
    ) {}

    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'scenario' => 'required|in:three_tier_both,three_tier_gpt,three_tier_gemini,simple_gpt,simple_gemini',
            'keywords' => 'required|array|min:1',
            'keywords.*' => 'required|string|max:255',
            'required_keywords' => 'nullable|array',
            'required_keywords.*' => 'string|max:255',
            'page_type' => 'required|string|max:255',
            'language' => 'required|string|max:50',
            'country' => 'required|string|max:50',
            'word_count' => 'required|integer|min:500|max:10000',
            'async' => 'nullable|boolean',
            'openai_api_key' => 'nullable|string',
            'google_api_key' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        $data = $validator->validated();
        $async = $data['async'] ?? true;
        $jobs = [];

        try {
            foreach ($data['keywords'] as $keyword) {
                $jobId = (string) Str::ulid();
                
                $generation = ArticleGeneration::create([
                    'job_id' => $jobId,
                    'scenario' => $data['scenario'],
                    'query' => $keyword,
                    'keywords' => $data['keywords'],
                    'required_keywords' => $data['required_keywords'] ?? [],
                    'language' => $data['language'],
                    'country' => $data['country'],
                    'word_count' => $data['word_count'],
                    'page_type' => $data['page_type'],
                    'status' => $async ? 'queued' : 'processing'
                ]);

                $params = [
                    'keyword' => $keyword,
                    'query' => $keyword,
                    'required_keywords' => $data['required_keywords'] ?? [],
                    'language' => $data['language'],
                    'country' => $data['country'],
                    'word_count' => $data['word_count'],
                    'page_type' => $data['page_type'],
                    'openai_api_key' => $data['openai_api_key'] ?? null,
                    'google_api_key' => $data['google_api_key'] ?? null,
                ];

                if ($async) {
                    GenerateArticleJob::dispatch(
                        $generation->id,
                        $data['scenario'],
                        $params
                    );

                    $jobs[] = [
                        'keyword' => $keyword,
                        'job_id' => $jobId,
                        'status' => 'queued'
                    ];
                } else {
                    try {
                        $result = $this->articleService->generateArticle($params, $data['scenario']);
                        
                        $generation->update([
                            'status' => 'completed',
                            'result' => $result,
                            'tokens_used' => $result['tokens_used'],
                            'completed_at' => now()
                        ]);

                        $jobs[] = [
                            'keyword' => $keyword,
                            'job_id' => $jobId,
                            'status' => 'completed',
                            'result' => $result
                        ];
                    } catch (\Exception $e) {
                        $generation->update([
                            'status' => 'failed',
                            'error_message' => $e->getMessage()
                        ]);

                        $jobs[] = [
                            'keyword' => $keyword,
                            'job_id' => $jobId,
                            'status' => 'failed',
                            'error' => $e->getMessage()
                        ];
                    }
                }
            }

            Log::info('Article generation request processed', [
                'total_keywords' => count($data['keywords']),
                'scenario' => $data['scenario'],
                'async' => $async
            ]);

            return response()->json([
                'success' => true,
                'message' => count($jobs) . ' article generation jobs ' . ($async ? 'queued' : 'processed'),
                'data' => [
                    'jobs' => $jobs,
                    'total_jobs' => count($jobs)
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Article generation request failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Generation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function status(string $jobId)
    {
        $generation = ArticleGeneration::where('job_id', $jobId)->first();

        if (!$generation) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found'
            ], 404);
        }

        $response = [
            'success' => true,
            'data' => [
                'job_id' => $generation->job_id,
                'keyword' => $generation->query,
                'scenario' => $generation->scenario,
                'status' => $generation->status,
                'created_at' => $generation->created_at->toISOString(),
            ]
        ];

        if ($generation->status === 'completed') {
            $response['data']['result'] = $generation->result;
            $response['data']['completed_at'] = $generation->completed_at?->toISOString();
            $response['data']['generation_time'] = $generation->result['generation_time'] ?? null;
        } elseif ($generation->status === 'failed') {
            $response['data']['error'] = $generation->error_message;
        }

        return response()->json($response);
    }

    public function history(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $status = $request->input('status');

        $query = ArticleGeneration::orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        $generations = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $generations
        ]);
    }

    public function test()
    {
        try {
            $results = $this->articleService->testConnections();

            return response()->json([
                'success' => true,
                'message' => 'API test completed',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'API test failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
