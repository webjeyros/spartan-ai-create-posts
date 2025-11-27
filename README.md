# 🚀 Spartan AI Create Posts

Laravel API для автоматической генерации SEO-оптимизированных статей с использованием ChatGPT и Google Gemini.

## ✨ Ключевые возможности

- ✅ **4 сценария генерации** статей (трехуровневая и простая)
- ✅ **Интеграция с ChatGPT и Google Gemini**
- ✅ **Трехуровневая генерация**: SEO-анализ → Промпт → Статья
- ✅ **Асинхронная обработка** через Redis Queue
- ✅ **Кэширование SEO-анализов** для оптимизации
- ✅ **Rate limiting** для соблюдения лимитов API
- ✅ **Генерация метатегов** (5 вариантов title и description)
- ✅ **Множественная генерация** - для каждого ключевого слова отдельная статья

## 🎯 4 Сценария генерации

### Сценарий 1: `three_tier_both` (Google Gemini + ChatGPT)
Этап 1: Google Gemini → SEO-анализ запроса  
Этап 2: ChatGPT → Генерация промпта  
Этап 3: ChatGPT → Написание статьи

### Сценарий 2: `three_tier_gpt` (только ChatGPT)
Все 3 этапа выполняются ChatGPT

### Сценарий 3: `three_tier_gemini` (только Google Gemini)
Все 3 этапа выполняются Google Gemini

### Сценарий 4: `simple_gpt` или `simple_gemini` (простой)
Один запрос к AI → готовая статья

## 🛠️ Требования

- PHP 8.2+
- Laravel 11.x
- MySQL 8.0+ / MariaDB 10.5+
- Redis 6.0+
- Composer 2.x
- OpenAI API Key
- Google Gemini API Key

## 📦 Быстрая установка

```bash
# 1. Клонировать репозиторий
git clone https://github.com/webjeyros/spartan-ai-create-posts.git
cd spartan-ai-create-posts

# 2. Установить зависимости
composer install

# 3. Настроить окружение
cp .env.example .env
php artisan key:generate

# 4. Настроить .env (добавить API ключи)
# OPENAI_API_KEY=sk-...
# GOOGLE_GEMINI_API_KEY=AIza...
# API_KEYS=your-api-key-1,your-api-key-2

# 5. Создать базу данных и выполнить миграции
mysql -u root -p -e "CREATE DATABASE spartan_ai_posts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate

# 6. Запустить queue worker
php artisan queue:work redis --queue=article-generation &

# 7. Запустить сервер
php artisan serve
```

## 🚀 Использование

### Базовый запрос (генерация 3 статей)

```bash
curl -X POST http://localhost:8000/api/articles/generate \\
  -H "Authorization: Bearer your-api-key" \\
  -H "Content-Type: application/json" \\
  -d '{
    "scenario": "three_tier_both",
    "keywords": ["tax deductions canada", "canadian tax credits", "CRA tax tips"],
    "required_keywords": ["CRA", "income tax"],
    "page_type": "blog article",
    "language": "English",
    "country": "Canada",
    "word_count": 3000
  }'
```

**Важно:** Для каждого ключевого слова в массиве `keywords` создается **отдельная статья**!

### Ответ

```json
{
  "success": true,
  "message": "3 article generation jobs queued",
  "data": {
    "jobs": [
      {
        "keyword": "tax deductions canada",
        "job_id": "01JDQW...",
        "status": "queued"
      },
      ...
    ],
    "total_jobs": 3
  }
}
```

### Проверка статуса

```bash
curl -X GET http://localhost:8000/api/articles/status/01JDQW... \\
  -H "Authorization: Bearer your-api-key"
```

## 📚 API Endpoints

| Метод | Endpoint | Описание |
|-------|----------|----------|
| POST | `/api/articles/generate` | Генерация статей |
| GET | `/api/articles/status/{jobId}` | Статус генерации |
| GET | `/api/articles/history` | История |
| POST | `/api/articles/test` | Тест подключений |
| GET | `/api/stats` | Статистика |

## 📖 Документация

Полная документация доступна в директории `docs/`:
- [INSTALLATION.md](docs/INSTALLATION.md) - Детальная инструкция по установке
- [API.md](docs/API.md) - Описание API endpoints
- [ARCHITECTURE.md](docs/ARCHITECTURE.md) - Архитектура проекта

## 🎨 Параметры запроса

### Обязательные

| Параметр | Тип | Описание |
|----------|-----|----------|
| `scenario` | string | `three_tier_both`, `three_tier_gpt`, `three_tier_gemini`, `simple_gpt`, `simple_gemini` |
| `keywords` | array | Массив ключевых слов (для каждого генерируется отдельная статья) |
| `page_type` | string | Назначение статьи ("blog article", "product page", etc.) |
| `language` | string | Язык статьи |
| `country` | string | Целевая страна для SEO |
| `word_count` | integer | Количество слов (500-10000) |

### Опциональные

| Параметр | Тип | По умолчанию | Описание |
|----------|-----|--------------|----------|
| `required_keywords` | array | `[]` | Обязательные ключевые слова в статье |
| `async` | boolean | `true` | Асинхронная обработка |
| `openai_api_key` | string | из .env | Кастомный OpenAI ключ |
| `google_api_key` | string | из .env | Кастомный Google ключ |

## 🔐 Безопасность

### API Key Authentication

Настройте API ключи в `.env`:
```env
API_KEYS=key1-secret,key2-secret,key3-secret
```

Каждый запрос должен содержать:
```
Authorization: Bearer your-api-key
```

### Rate Limiting

- 60 запросов в минуту на IP
- Автоматическое соблюдение лимитов OpenAI и Google
- Retry с exponential backoff

## 📊 Мониторинг

```bash
# Просмотр логов
tail -f storage/logs/laravel.log | grep "ArticleGeneration"

# Статус очереди
php artisan queue:failed

# Laravel Horizon (опционально)
composer require laravel/horizon
php artisan horizon
# Dashboard: http://localhost:8000/horizon
```

## 🧪 Тестирование

```bash
# Все тесты
php artisan test

# Конкретный тест
php artisan test --filter ArticleGenerationTest

# С покрытием
php artisan test --coverage
```

## 📈 Производительность

### Оптимизации

1. **Кэширование SEO-анализов** (1 час)
2. **Параллельные queue workers**
3. **Connection pooling**
4. **Redis для очередей и кэша**

### Запуск нескольких workers

```bash
# 3 параллельных worker'а для ускорения обработки
php artisan queue:work redis --queue=article-generation --tries=3 &
php artisan queue:work redis --queue=article-generation --tries=3 &
php artisan queue:work redis --queue=article-generation --tries=3 &
```

## 🐛 Решение проблем

### Генерация зависает
```bash
php artisan queue:restart
php artisan queue:work redis --queue=article-generation
```

### "API key not set"
```bash
php artisan config:clear
php artisan config:cache
```

### Превышен rate limit
- Используйте кэширование (включено по умолчанию)
- Увеличьте задержки в конфигурации
- Распределите нагрузку на несколько API ключей

## 🌐 Production Deployment

### Nginx конфигурация

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;
    root /var/www/spartan-ai-create-posts/public;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Оптимизация для production

```bash
composer install --optimize-autoloader --no-dev
php artisan config:cache
php artisan route:cache
php artisan view:cache
chmod -R 775 storage bootstrap/cache
```

### Supervisor для queue workers

```ini
[program:spartan-ai-worker]
command=php /path/to/artisan queue:work redis --queue=article-generation --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=3
```

## 🤝 Вклад в проект

1. Fork репозитория
2. Создайте feature branch: `git checkout -b feature/amazing-feature`
3. Commit изменений: `git commit -m 'Add amazing feature'`
4. Push в branch: `git push origin feature/amazing-feature`
5. Откройте Pull Request

## 📧 Поддержка

Если возникли вопросы или проблемы:

1. Проверьте [Issues](https://github.com/webjeyros/spartan-ai-create-posts/issues)
2. Создайте новый Issue с подробным описанием
3. Укажите версию PHP, Laravel и текст ошибки

## 📝 Лицензия

MIT License

## 🙏 Благодарности

- OpenAI за ChatGPT API
- Google за Gemini API
- Laravel Community

## 👨‍💻 Автор

**Spartan AI Team**

- GitHub: [@webjeyros](https://github.com/webjeyros)

---

Made with ❤️ by Spartan AI Team