# 🏗️ Архитектура проекта Spartan AI Create Posts

## Общий обзор

Проект построен на основе **Laravel 11.x** с использованием **Service Layer** паттерна и **Queue-based** асинхронной обработки.

```
┌────────────────────────────────────────────────────────┐
│                    HTTP Request                            │
│            (Authorization: Bearer API_KEY)                  │
└──────────────────────────┬─────────────────────────────┘
                             │
                             │
                    ┌────────┴─────────┐
                    │   Middleware   │
                    │ ApiKeyMiddleware
                    └────────┬─────────┘
                             │
                             │
                    ┌────────┴─────────────────────┐
                    │    ArticleController      │
                    │  (Validation & Dispatch) │
                    └────────┬─────────────────────┘
                             │
              ┌──────────────┼───────────────┐
              │              │                │
              │         (For each keyword)    │
              │              │                │
      ┌───────┴─────┐    ┌────┴────┐    ┌────┴────┐
      │   Redis      │    │  Model  │    │  Model  │
      │   Queue      │    │  Save   │    │  Save   │
      └───────┬─────┘    └─────────┘    └─────────┘
              │
              │
      ┌───────┴────────────────────────┐
      │   GenerateArticleJob (Worker)     │
      └───────┬────────────────────────┘
              │
              │
      ┌───────┴───────────────────────────────┐
      │   ArticleGenerationService          │
      │   (Scenario Orchestrator)            │
      └───────┬───────────────────────────────┘
              │
      ┌───────┼───────────────┐
      │       │                  │
┌─────┴───┐ ┌───┴────┐ ┌─────┴─────┐
│ OpenAI  │ │  Gemini │ │ RateLimit│
│ Service │ │ Service │ │  Service  │
└─────┬───┘ └───┬────┘ └──────────┘
      │         │
      │         │
┌─────┴─────────┴────┐
│  External AI APIs  │
│  (OpenAI/Gemini)  │
└───────────────────┘
```

## Компоненты системы

### 1. API Layer (HTTP)

**ArticleController**
- Принимает HTTP запросы
- Валидирует входные данные
- Создает **отдельный Job для каждого ключевого слова**
- Отправляет в очередь Redis

**ApiKeyMiddleware**
- Проверяет аутентичность API ключа
- Поддерживает 3 формата: Bearer token, X-API-Key header, query parameter

### 2. Queue Layer

**GenerateArticleJob**
- Асинхронно обрабатывает генерацию
- 3 попытки (tries) при ошибке
- Timeout: 15 минут
- Обновляет статус в базе данных

### 3. Service Layer

**ArticleGenerationService** (Оркестратор)
- Выбирает сценарий генерации
- Координирует OpenAI и Gemini сервисы
- Управляет трехуровневым процессом

**OpenAIService**
- SEO-анализ
- Генерация промпта
- Генерация статьи
- Простая генерация

**GoogleGeminiService**
- Аналогичные методы для Gemini API

**RateLimiterService**
- Отслеживает количество запросов в минуту
- Использует Redis для хранения счетчиков
- Предотвращает превышение лимитов API

### 4. Data Layer

**ArticleGeneration Model**
- Eloquent ORM
- Scopes: pending(), completed(), failed()
- Casts: JSON для keywords, result, tokens_used
- Индексы для быстрого поиска

## 4 Сценария генерации

### Scenario 1: three_tier_both
```
Stage 1: Google Gemini API
         ↓
         SEO Analysis (кэшируется)
         ↓
Stage 2: OpenAI API  
         ↓
         Article Prompt
         ↓
Stage 3: OpenAI API
         ↓
         Final Article + Meta Tags
```

### Scenario 2: three_tier_gpt
```
All 3 Stages: OpenAI API
```

### Scenario 3: three_tier_gemini
```
All 3 Stages: Google Gemini API
```

### Scenario 4: simple_gpt / simple_gemini
```
Single Request → Complete Article
```

## Оптимизации

### 1. Кэширование
- SEO-анализы кэшируются на 1 час
- Ключ: md5(query + country + language)
- Redis backend

### 2. Rate Limiting
- Отслеживание по RPM (requests per minute)
- Отдельные лимиты для OpenAI и Gemini
- Автоматическое ожидание при превышении

### 3. Retry Logic
- 3 попытки для каждого API запроса
- Exponential backoff (5 секунд)
- Job retry при фейле

### 4. Параллелизация
- Множественные queue workers
- Независимая обработка каждого ключа

## База данных

### article_generations
```sql
CREATE TABLE article_generations (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    job_id VARCHAR(255) UNIQUE,
    scenario VARCHAR(255),
    query VARCHAR(255),          -- Текущее ключевое слово
    keywords JSON,               -- Все ключевые слова
    required_keywords JSON,
    language VARCHAR(255),
    country VARCHAR(255),
    word_count INT,
    page_type TEXT,
    status VARCHAR(255),         -- queued, processing, completed, failed
    result LONGTEXT,             -- JSON: title, content, meta_titles, meta_descriptions
    tokens_used JSON,            -- stage_1, stage_2, stage_3, total
    error_message TEXT,
    completed_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_job_id (job_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    INDEX idx_status_created (status, created_at)
);
```

## Поток данных

### Генерация статьи (three_tier_both)

1. **HTTP Request** → ArticleController
2. **Validation** → Проверка входных данных
3. **Loop по keywords** → Для каждого ключевого слова:
   - Создать ArticleGeneration record
   - Dispatch GenerateArticleJob
4. **HTTP Response** → Вернуть job_id для каждого ключа
5. **Queue Worker** picks up job
6. **GenerateArticleJob** → ArticleGenerationService
7. **Stage 1** → GoogleGeminiService.analyzeSEO()
   - Проверка cache
   - API запрос к Gemini
   - Сохранение в cache
8. **Stage 2** → OpenAIService.generateArticlePrompt()
   - Использует результат Stage 1
   - API запрос к OpenAI
9. **Stage 3** → OpenAIService.generateArticle()
   - Использует промпт из Stage 2
   - Генерирует финальную статью
10. **Parse Response** → Извлечение title, content, meta_tags
11. **Update DB** → Сохранение результата, status=completed

## Масштабирование

### Horizontal Scaling
- Добавление больше queue workers
- Использование Redis Cluster
- Database replication (read replicas)

### Load Balancing
- Nginx перед несколькими Laravel инстансами
- Redis Sentinel для high availability

### Monitoring
- Laravel Horizon для queue monitoring
- Application logs в storage/logs/
- Database slow query log

---

Эта архитектура обеспечивает надежность, масштабируемость и производительность системы генерации статей.