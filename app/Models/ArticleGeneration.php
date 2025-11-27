<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleGeneration extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'scenario',
        'query',
        'keywords',
        'required_keywords',
        'language',
        'country',
        'word_count',
        'page_type',
        'status',
        'result',
        'tokens_used',
        'error_message',
        'completed_at'
    ];

    protected $casts = [
        'keywords' => 'array',
        'required_keywords' => 'array',
        'result' => 'array',
        'tokens_used' => 'array',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->whereIn('status', ['queued', 'processing']);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            'queued' => 'В очереди',
            'processing' => 'Обрабатывается',
            'completed' => 'Завершено',
            'failed' => 'Ошибка',
            default => 'Неизвестно'
        };
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, ['queued', 'processing']);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }
}
