<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_generations', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->unique();
            $table->string('scenario');
            $table->string('query');
            $table->json('keywords');
            $table->json('required_keywords')->nullable();
            $table->string('language');
            $table->string('country');
            $table->integer('word_count');
            $table->text('page_type');
            $table->string('status');
            $table->longText('result')->nullable();
            $table->json('tokens_used')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('job_id');
            $table->index('status');
            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_generations');
    }
};
