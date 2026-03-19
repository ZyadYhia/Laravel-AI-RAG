# AI RAG

A Laravel 12 RAG (Retrieval-Augmented Generation) application built with Inertia.js, React, and the Laravel AI SDK. Uses PostgreSQL with pgvector for vector storage and Redis for caching/queues.

## Tech Stack

- **Backend:** Laravel 12, PHP 8.4
- **Frontend:** React 19, Inertia.js v2, Tailwind CSS v4
- **Database:** PostgreSQL with pgvector extension
- **Cache/Queue:** Redis 7
- **AI:** Laravel AI SDK (Ollama, OpenAI, Gemini, Cohere)
- **Real-time:** Laravel Reverb
- **Local Dev:** Laravel Herd, Docker

## Prerequisites

- [Laravel Herd](https://herd.laravel.com/) (provides PHP 8.4, Composer, and Node.js)
- [Docker Desktop](https://www.docker.com/products/docker-desktop/)

## Installation

### 1. Clone the repository

```bash
git clone <repository-url>
cd Ai-RAG
```

### 2. Start Docker services

Start PostgreSQL (with pgvector) and Redis containers:

```bash
cd ai-rag-stack
docker compose up -d
cd ..
```

This starts:

- **PostgreSQL** on port `5432` (user: `postgres`, password: `secret`, database: `rag_db`)
- **Redis** on port `6379`

### 3. Enable pgvector extension

Connect to the PostgreSQL container and create the vector extension:

```bash
docker exec -it rag_postgres psql -U postgres -d rag_db
```

Then run:

```sql
CREATE EXTENSION vector;
```

Verify the extension is installed:

```sql
SELECT * FROM pg_extension;
```

Type `\q` to exit psql.

### 4. Install dependencies

```bash
composer install
npm install
```

### 5. Environment setup

```bash
cp .env.example .env
php artisan key:generate
```

Update your `.env` file with the database and Redis configuration:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rag_db
DB_USERNAME=postgres
DB_PASSWORD=secret

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

CACHE_STORE=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb
```

Add your AI provider API keys as needed:

```dotenv
OPENAI_API_KEY=your-openai-key
GEMINI_API_KEY=your-gemini-key
COHERE_API_KEY=your-cohere-key
```

### 6. Run migrations and seed the database

```bash
php artisan migrate
php artisan db:seed
```

This creates a default test user:

| Field    | Value              |
|----------|--------------------|
| Email    | `test@test.com`    |
| Password | `123123123`        |

### 7. Build frontend assets

```bash
npm run build
```

### 8. Link Herd site (optional)

If using Herd's site linking:

```bash
cd /path/to/Ai-RAG
herd link ai-rag
```

The app will then be available at `http://ai-rag.test`.

## Running the Application

With **Laravel Herd**, the app is already served — you only need the supporting services.

Start the Vite dev server for frontend hot-reloading:

```bash
npm run dev
```

In a separate terminal, start the queue worker and Reverb WebSocket server:

```bash
composer run dev:broadcasting
```

> **Without Herd?** Use `composer run dev` instead — it starts the PHP dev server, queue, logs, Vite, and Reverb all at once.

## Testing

```bash
php artisan test
```

## Useful Commands

| Command                                                   | Description                |
| --------------------------------------------------------- | -------------------------- |
| `composer run dev`                                        | Start all dev services (without Herd) |
| `composer run dev:broadcasting`                           | Start queue worker & Reverb |
| `npm run dev`                                             | Vite dev server (hot-reload) |
| `php artisan test --compact`                              | Run tests (compact output) |
| `vendor/bin/pint`                                         | Fix code style             |
| `npm run lint`                                            | Lint frontend code         |
| `npm run format`                                          | Format frontend code       |
| `docker compose -f ai-rag-stack/docker-compose.yml up -d` | Start Docker services      |
| `docker compose -f ai-rag-stack/docker-compose.yml down`  | Stop Docker services       |
