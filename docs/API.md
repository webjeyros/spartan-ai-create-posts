# 📚 API Documentation

Полное описание REST API эндпоинтов.

## Базовый URL

```
http://localhost:8000/api
```

## Аутентификация

Все запросы должны включать API ключ:

```bash
# Bearer Token (recommended)
curl -H "Authorization: Bearer your-api-key"

# X-API-Key Header
curl -H "X-API-Key: your-api-key"

# Query Parameter
curl "http://localhost:8000/api/articles/generate?api_key=your-api-key"
```

---

## Endpoints

### 1. Generate Articles

Создает **отдельную статью для каждого ключевого слова**.

**Endpoint:** `POST /articles/generate`

**Request Body:**
```json
{
  "scenario": "three_tier_both",
  "keywords": ["keyword1", "keyword2", "keyword3"],
  "required_keywords": ["must-have-1", "must-have-2"],
  "page_type": "blog article",
  "language": "English",
  "country": "Canada",
  "word_count": 3000,
  "async": true,
  "openai_api_key": "sk-custom-key",
  "google_api_key": "AIza-custom-key"
}
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `scenario` | string | Yes | `three_tier_both`, `three_tier_gpt`, `three_tier_gemini`, `simple_gpt`, `simple_gemini` |
| `keywords` | array | Yes | Массив ключевых слов (по одной статье на каждое) |
| `required_keywords` | array | No | Обязательные ключевые слова в статье |
| `page_type` | string | Yes | Назначение статьи |
| `language` | string | Yes | Язык статьи |
| `country` | string | Yes | Целевая страна |
| `word_count` | integer | Yes | Количество слов (500-10000) |
| `async` | boolean | No | Асинхронная обработка (default: true) |
| `openai_api_key` | string | No | Кастомный OpenAI ключ |
| `google_api_key` | string | No | Кастомный Google ключ |

**Success Response (201):**
```json
{
  "success": true,
  "message": "3 article generation jobs queued",
  "data": {
    "jobs": [
      {
        "keyword": "keyword1",
        "job_id": "01JDQW...",
        "status": "queued"
      },
      {
        "keyword": "keyword2",
        "job_id": "01JDQX...",
        "status": "queued"
      },
      {
        "keyword": "keyword3",
        "job_id": "01JDQY...",
        "status": "queued"
      }
    ],
    "total_jobs": 3
  }
}
```

**Error Response (400):**
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "scenario": ["The scenario field is required."],
    "keywords": ["The keywords must be an array."]
  }
}
```

---

### 2. Check Generation Status

Проверка статуса генерации по job_id.

**Endpoint:** `GET /articles/status/{jobId}`

**Success Response (200) - In Progress:**
```json
{
  "success": true,
  "data": {
    "job_id": "01JDQW...",
    "keyword": "keyword1",
    "scenario": "three_tier_both",
    "status": "processing",
    "created_at": "2024-01-15T10:30:00Z"
  }
}
```

**Success Response (200) - Completed:**
```json
{
  "success": true,
  "data": {
    "job_id": "01JDQW...",
    "keyword": "keyword1",
    "scenario": "three_tier_both",
    "status": "completed",
    "result": {
      "title": "Complete Article Title",
      "content": "<h1>Title</h1><p>Content...</p>",
      "meta_titles": [
        "Meta Title Variant 1",
        "Meta Title Variant 2",
        "..."
      ],
      "meta_descriptions": [
        "Meta Description Variant 1",
        "Meta Description Variant 2",
        "..."
      ],
      "word_count": 3042,
      "tokens_used": {
        "stage_1": 1250,
        "stage_2": 850,
        "stage_3": 2800,
        "total": 4900
      }
    },
    "created_at": "2024-01-15T10:30:00Z",
    "completed_at": "2024-01-15T10:32:30Z",
    "generation_time": 150
  }
}
```

**Success Response (200) - Failed:**
```json
{
  "success": true,
  "data": {
    "job_id": "01JDQW...",
    "keyword": "keyword1",
    "status": "failed",
    "error": "OpenAI API error (429): Rate limit exceeded",
    "created_at": "2024-01-15T10:30:00Z"
  }
}
```

**Error Response (404):**
```json
{
  "success": false,
  "message": "Job not found"
}
```

---

### 3. Generation History

Получение истории всех генераций с пагинацией.

**Endpoint:** `GET /articles/history`

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `per_page` | integer | Количество на страницу (default: 20) |
| `status` | string | Фильтр по статусу: queued, processing, completed, failed |

**Example:**
```bash
curl "http://localhost:8000/api/articles/history?status=completed&per_page=10"
```

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "job_id": "01JDQW...",
        "scenario": "three_tier_both",
        "query": "keyword1",
        "status": "completed",
        "word_count": 3042,
        "created_at": "2024-01-15T10:30:00Z"
      }
    ],
    "per_page": 10,
    "total": 45,
    "last_page": 5
  }
}
```

---

### 4. Test API Connections

Проверка подключения к OpenAI и Google Gemini API.

**Endpoint:** `POST /articles/test`

**Success Response (200):**
```json
{
  "success": true,
  "message": "API test completed",
  "data": {
    "openai": {
      "success": true,
      "service": "OpenAI",
      "message": "Connection successful"
    },
    "google": {
      "success": true,
      "service": "Google Gemini",
      "message": "Connection successful"
    }
  }
}
```

---

### 5. Statistics

Общая статистика генераций.

**Endpoint:** `GET /stats`

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "total_generations": 150,
    "completed": 142,
    "failed": 5,
    "in_progress": 3,
    "total_words_generated": 426000,
    "total_tokens_used": 685000
  }
}
```

---

## Error Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 201 | Resource created |
| 400 | Bad request (validation error) |
| 401 | Unauthorized (invalid API key) |
| 404 | Resource not found |
| 429 | Too many requests (rate limit) |
| 500 | Internal server error |

## Rate Limiting

- **60 requests per minute** per IP address
- OpenAI API: 500 RPM (configurable)
- Google API: 1000 RPM (configurable)

**Rate Limit Headers:**
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1610000000
```

---

## Examples

### Full Generation Flow

```bash
# 1. Generate articles
RESPONSE=$(curl -X POST http://localhost:8000/api/articles/generate \
  -H "Authorization: Bearer your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "scenario": "three_tier_both",
    "keywords": ["keyword1"],
    "page_type": "blog article",
    "language": "English",
    "country": "USA",
    "word_count": 2000
  }')

# Extract job_id
JOB_ID=$(echo $RESPONSE | jq -r '.data.jobs[0].job_id')

# 2. Check status (wait 2 minutes)
sleep 120
curl -X GET "http://localhost:8000/api/articles/status/$JOB_ID" \
  -H "Authorization: Bearer your-api-key"

# 3. View history
curl -X GET "http://localhost:8000/api/articles/history" \
  -H "Authorization: Bearer your-api-key"
```

---

## Webhooks (Будущая функциональность)

В будущем будет добавлена поддержка webhook'ов для уведомления о завершении генерации.