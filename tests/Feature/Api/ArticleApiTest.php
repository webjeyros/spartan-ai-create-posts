<?php

namespace Tests\Feature\Api;

use App\Models\ArticleGeneration;
use App\Jobs\GenerateArticleJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $apiKey = 'test-secret-api-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['article-generator.api_keys' => $this->apiKey]);
    }

    public function test_requires_authentication()
    {
        $response = $this->postJson('/api/articles/generate', []);
        $response->assertStatus(401);
    }

    public function test_rejects_invalid_api_key()
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer invalid-key'])
            ->postJson('/api/articles/generate', []);
        
        $response->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'Invalid API key']);
    }

    public function test_validates_required_fields()
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->postJson('/api/articles/generate', []);

        $response->assertStatus(400)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    public function test_creates_jobs_for_multiple_keywords()
    {
        Queue::fake();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->postJson('/api/articles/generate', [
                'scenario' => 'simple_gpt',
                'keywords' => ['keyword1', 'keyword2', 'keyword3'],
                'page_type' => 'blog article',
                'language' => 'English',
                'country' => 'USA',
                'word_count' => 1500,
                'async' => true
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'jobs' => ['*' => ['keyword', 'job_id', 'status']],
                    'total_jobs'
                ]
            ]);

        $this->assertEquals(3, $response->json('data.total_jobs'));
        $this->assertDatabaseCount('article_generations', 3);
        
        Queue::assertPushed(GenerateArticleJob::class, 3);
    }

    public function test_validates_scenario_values()
    {
        $validScenarios = ['three_tier_both', 'three_tier_gpt', 'three_tier_gemini', 'simple_gpt', 'simple_gemini'];

        foreach ($validScenarios as $scenario) {
            $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
                ->postJson('/api/articles/generate', [
                    'scenario' => $scenario,
                    'keywords' => ['test'],
                    'page_type' => 'blog',
                    'language' => 'English',
                    'country' => 'USA',
                    'word_count' => 1000
                ]);

            $response->assertStatus(201);
        }

        $this->assertDatabaseCount('article_generations', 5);
    }

    public function test_returns_job_status()
    {
        $generation = ArticleGeneration::factory()->create([
            'status' => 'queued'
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->getJson('/api/articles/status/' . $generation->job_id);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'job_id' => $generation->job_id,
                    'status' => 'queued'
                ]
            ]);
    }

    public function test_returns_404_for_nonexistent_job()
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->getJson('/api/articles/status/nonexistent-id');

        $response->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Job not found']);
    }

    public function test_returns_generation_history()
    {
        ArticleGeneration::factory()->count(15)->create();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->getJson('/api/articles/history');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['data', 'current_page', 'per_page', 'total']]);
    }

    public function test_filters_history_by_status()
    {
        ArticleGeneration::factory()->count(5)->create(['status' => 'completed']);
        ArticleGeneration::factory()->count(3)->create(['status' => 'failed']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->getJson('/api/articles/history?status=completed');

        $response->assertStatus(200);
        $this->assertEquals(5, $response->json('data.total'));
    }

    public function test_returns_statistics()
    {
        ArticleGeneration::factory()->count(10)->completed()->create();
        ArticleGeneration::factory()->count(3)->failed()->create();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->getJson('/api/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_generations',
                    'completed',
                    'failed',
                    'in_progress'
                ]
            ]);

        $data = $response->json('data');
        $this->assertEquals(13, $data['total_generations']);
        $this->assertEquals(10, $data['completed']);
        $this->assertEquals(3, $data['failed']);
    }

    public function test_accepts_custom_api_keys()
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->postJson('/api/articles/generate', [
                'scenario' => 'simple_gpt',
                'keywords' => ['test'],
                'page_type' => 'blog',
                'language' => 'English',
                'country' => 'USA',
                'word_count' => 1000,
                'openai_api_key' => 'custom-key-123',
                'google_api_key' => 'custom-google-key'
            ]);

        $response->assertStatus(201);
    }
}
