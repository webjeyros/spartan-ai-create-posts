<?php

namespace Tests\Feature;

use App\Models\ArticleGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleGenerationTest extends TestCase
{
    use RefreshDatabase;

    private string $validApiKey = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['article-generator.api_keys' => $this->validApiKey]);
    }

    public function test_can_generate_articles_for_multiple_keywords()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validApiKey,
        ])->postJson('/api/articles/generate', [
            'scenario' => 'simple_gpt',
            'keywords' => ['keyword1', 'keyword2', 'keyword3'],
            'page_type' => 'blog article',
            'language' => 'English',
            'country' => 'USA',
            'word_count' => 1000,
            'async' => true
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'success',
                     'message',
                     'data' => [
                         'jobs' => [
                             '*' => ['keyword', 'job_id', 'status']
                         ],
                         'total_jobs'
                     ]
                 ]);

        $this->assertEquals(3, ArticleGeneration::count());
        
        $responseData = $response->json('data');
        $this->assertEquals(3, $responseData['total_jobs']);
        $this->assertCount(3, $responseData['jobs']);
    }

    public function test_validation_fails_with_invalid_data()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validApiKey,
        ])->postJson('/api/articles/generate', [
            'scenario' => 'invalid_scenario',
            'keywords' => [],
            'word_count' => 50
        ]);

        $response->assertStatus(400)
                 ->assertJsonStructure([
                     'success',
                     'message',
                     'errors'
                 ]);
    }

    public function test_api_key_authentication_required()
    {
        $response = $this->postJson('/api/articles/generate', [
            'scenario' => 'simple_gpt',
            'keywords' => ['test'],
            'page_type' => 'blog',
            'language' => 'English',
            'country' => 'USA',
            'word_count' => 1000
        ]);

        $response->assertStatus(401)
                 ->assertJson([
                     'success' => false,
                     'message' => 'API key is required'
                 ]);
    }

    public function test_can_check_generation_status()
    {
        $generation = ArticleGeneration::create([
            'job_id' => 'test-job-123',
            'scenario' => 'simple_gpt',
            'query' => 'test keyword',
            'keywords' => ['test keyword'],
            'required_keywords' => [],
            'language' => 'English',
            'country' => 'USA',
            'word_count' => 1000,
            'page_type' => 'blog article',
            'status' => 'queued'
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validApiKey,
        ])->getJson('/api/articles/status/' . $generation->job_id);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         'job_id' => 'test-job-123',
                         'keyword' => 'test keyword',
                         'status' => 'queued'
                     ]
                 ]);
    }

    public function test_can_get_generation_history()
    {
        ArticleGeneration::factory()->count(5)->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validApiKey,
        ])->getJson('/api/articles/history');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'data' => [
                         'data',
                         'current_page',
                         'per_page',
                         'total'
                     ]
                 ]);
    }

    public function test_can_test_api_connections()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validApiKey,
        ])->postJson('/api/articles/test');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'message',
                     'data'
                 ]);
    }
}
