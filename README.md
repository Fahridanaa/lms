# aku dan laravel

## Project Overview

**Nama Project:** LMS Mini - Cache Strategy Benchmarking

**Tujuan:** Menganalisis dan membandingkan strategi caching (Cache-Aside, Read-Through, Write-Through) pada Laravel Cache Abstraction Layer untuk optimasi performa data layer sistem pembelajaran.

---

## Tech Stack

| Layer         | Technology            | Version |
| ------------- | --------------------- | ------- |
| Framework     | Laravel               | 12.x    |
| PHP           | PHP                   | 8.2+    |
| Database      | MySQL                 | 8.0+    |
| Cache Backend | Redis                 | 7.x     |
| Development   | Laravel Sail (Docker) | Latest  |
| Load Testing  | Grafana K6            | Latest  |
| API Format    | REST JSON             | -       |

---

## Architecture Overview

### Caching Strategy Pattern

```
┌─────────────────────────────────────────────────────────────┐
│                      API Request                            │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                    Controller                               │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                 Cache Service Layer                         │
│  ┌─────────────────────────────────────────────────────┐    │
│  │           CacheStrategyInterface                    │    │
│  │  - get(key): mixed                                  │    │
│  │  - put(key, value, ttl): bool                       │    │
│  │  - forget(key): bool                                │    │
│  │  - remember(key, ttl, callback): mixed              │    │
│  └─────────────────────────────────────────────────────┘    │
│           ▲              ▲             ▲                    │
│           │              │             │                    │
│  ┌────────┴───┐  ┌───────┴────┐  ┌─────┴───────┐            │
│  │Cache-Aside │  │Read-Through│  │Write-Through│            │
│  │  Strategy  │  │  Strategy  │  │  Strategy   │            │
│  └────────────┘  └────────────┘  └─────────────┘            │
└─────────────────────────┬───────────────────────────────────┘
                          │
          ┌───────────────┼──────────────┐
          ▼               ▼              ▼
    ┌──────────┐   ┌──────────┐   ┌──────────┐
    │  Redis   │   │  MySQL   │   │  Redis   │
    │  Cache   │   │ Database │   │  Cache   │
    └──────────┘   └──────────┘   └──────────┘
```

### Strategy Switching (Config-Based)

```env
# .env
CACHE_STRATEGY=cache-aside  # Options: cache-aside, read-through, write-through
CACHE_DRIVER=redis
CACHE_TTL=3600
```

---

## Project Structure

```
lms/
├── app/
│   ├── Contracts/
│   │   └── CacheStrategyInterface.php
│   ├── Services/
│   │   ├── Cache/
│   │   │   ├── CacheAsideStrategy.php
│   │   │   ├── ReadThroughStrategy.php
│   │   │   ├── WriteThroughStrategy.php
│   │   │   └── CacheStrategyFactory.php
│   │   ├── QuizService.php
│   │   ├── MaterialService.php
│   │   ├── AssignmentService.php
│   │   └── GradebookService.php
│   ├── Http/
│   │   └── Controllers/
│   │       └── Api/
│   │           ├── QuizController.php
│   │           ├── MaterialController.php
│   │           ├── AssignmentController.php
│   │           └── GradebookController.php
│   ├── Models/
│   │   ├── User.php
│   │   ├── Course.php
│   │   ├── Quiz.php
│   │   ├── Question.php
│   │   ├── QuizAttempt.php
│   │   ├── Material.php
│   │   ├── Assignment.php
│   │   ├── Submission.php
│   │   └── Grade.php
│   └── Providers/
│       └── CacheStrategyServiceProvider.php
├── config/
│   └── caching-strategy.php
├── database/
│   ├── migrations/
│   ├── seeders/
│   │   ├── DatabaseSeeder.php
│   │   ├── UserSeeder.php
│   │   ├── CourseSeeder.php
│   │   ├── QuizSeeder.php
│   │   ├── MaterialSeeder.php
│   │   └── AssignmentSeeder.php
│   └── factories/
├── routes/
│   └── api.php
├── tests/
│   ├── Feature/
│   │   └── Api/
│   └── Benchmark/
│       └── k6/
├── compose.yml
└── .env.example
```

---

## Database Schema

### ERD Overview

> Belom ada, soon!

### Data Seeding Specification

| Entity        | Count  | Notes                             |
| ------------- | ------ | --------------------------------- |
| Users         | 5,000  | 4,900 students, 100 instructors   |
| Courses       | 50     | ~100 students per course          |
| Enrollments   | ~5,000 | Random distribution               |
| Quizzes       | 250    | 5 per course                      |
| Questions     | 5,000  | 20 per quiz                       |
| Quiz Attempts | 25,000 | ~5 attempts per quiz              |
| Materials     | 500    | 10 per course                     |
| Assignments   | 250    | 5 per course                      |
| Submissions   | 12,500 | ~50 per assignment                |
| Grades        | 37,500 | Combined quiz + assignment grades |

---

## Caching Strategy Implementation

### 1. Cache-Aside (Lazy Loading)

```php
// Application manages cache explicitly
// READ: Check cache → if miss, query DB → store in cache
// WRITE: Update DB → invalidate cache

public function get(string $key, callable $callback): mixed
{
    if ($cached = Cache::get($key)) {
        return $cached;
    }

    $value = $callback();
    Cache::put($key, $value, $this->ttl);

    return $value;
}

public function put(string $key, mixed $value): bool
{
    // Write to database first (handled by caller)
    // Then invalidate cache
    return Cache::forget($key);
}
```

### 2. Read-Through

```php
// Cache layer handles DB reads transparently
// READ: Cache intercepts → if miss, cache fetches from DB
// WRITE: Update DB → invalidate cache

public function get(string $key, callable $dataSource): mixed
{
    return Cache::remember($key, $this->ttl, $dataSource);
}
```

### 3. Write-Through

```php
// Synchronous write to both cache and DB
// READ: Same as Read-Through
// WRITE: Write to cache AND DB simultaneously

public function put(string $key, mixed $value, callable $persist): bool
{
    // Write to database
    $persist($value);

    // Write to cache
    Cache::put($key, $value, $this->ttl);

    return true;
}
```

---

## API Endpoints

### Quiz Module (Read-Heavy)

| Method | Endpoint                                        | Description                    | Cache Behavior     |
| ------ | ----------------------------------------------- | ------------------------------ | ------------------ |
| GET    | `/api/quizzes`                                  | List all quizzes               | Cached             |
| GET    | `/api/quizzes/{id}`                             | Get quiz detail with questions | Cached             |
| GET    | `/api/quizzes/{id}/questions`                   | Get questions for quiz         | Cached             |
| POST   | `/api/quizzes/{id}/attempts`                    | Start quiz attempt             | Write              |
| PUT    | `/api/quizzes/{id}/attempts/{attemptId}`        | Submit quiz answers            | Write + Invalidate |
| GET    | `/api/quizzes/{id}/attempts/{attemptId}/result` | Get attempt result             | Cached             |

### Material Module (Read-Heavy)

| Method | Endpoint                       | Description            | Cache Behavior     |
| ------ | ------------------------------ | ---------------------- | ------------------ |
| GET    | `/api/courses/{id}/materials`  | List course materials  | Cached             |
| GET    | `/api/materials/{id}`          | Get material detail    | Cached             |
| GET    | `/api/materials/{id}/download` | Download material file | Cached metadata    |
| POST   | `/api/materials`               | Upload new material    | Write + Invalidate |

### Assignment Module (Write-Heavy)

| Method | Endpoint                            | Description                   | Cache Behavior     |
| ------ | ----------------------------------- | ----------------------------- | ------------------ |
| GET    | `/api/courses/{id}/assignments`     | List assignments              | Cached             |
| GET    | `/api/assignments/{id}`             | Get assignment detail         | Cached             |
| POST   | `/api/assignments/{id}/submissions` | Submit assignment             | Write              |
| GET    | `/api/assignments/{id}/submissions` | List submissions (instructor) | Cached             |
| PUT    | `/api/submissions/{id}/grade`       | Grade submission              | Write + Invalidate |

### Gradebook Module (Mixed)

| Method | Endpoint                                  | Description              | Cache Behavior      |
| ------ | ----------------------------------------- | ------------------------ | ------------------- |
| GET    | `/api/courses/{id}/gradebook`             | Get course gradebook     | Cached (aggregated) |
| GET    | `/api/users/{id}/grades`                  | Get user's all grades    | Cached              |
| GET    | `/api/courses/{id}/users/{userId}/grades` | Get user grade in course | Cached              |
| PUT    | `/api/grades/{id}`                        | Update grade             | Write + Invalidate  |

---

## Benchmark Scenarios

### Environment Specification

| Component | Specification    |
| --------- | ---------------- |
| VPS       | 2 vCPU, 2GB RAM  |
| OS        | Ubuntu 22.04 LTS |
| Docker    | Latest           |
| PHP       | 8.2 (FPM)        |
| MySQL     | 8.0              |
| Redis     | 7.x              |

### Workload Scenarios

#### Scenario 1: Read-Heavy (80% Read, 20% Write)

Simulates normal LMS usage - students accessing quizzes, downloading materials.

```
Concurrent Users: 100, 250, 500, 750, 1000
Duration: 5 minutes per test
Ramp-up: 60 seconds

Operations:
- 40% GET /api/quizzes/{id}
- 25% GET /api/materials/{id}
- 15% GET /api/courses/{id}/gradebook
- 10% POST /api/quizzes/{id}/attempts
- 10% POST /api/assignments/{id}/submissions
```

#### Scenario 2: Write-Heavy (40% Read, 60% Write)

Simulates deadline period - heavy submissions and grading.

```
Concurrent Users: 100, 250, 500, 750, 1000
Duration: 5 minutes per test
Ramp-up: 60 seconds

Operations:
- 20% GET /api/assignments/{id}
- 20% GET /api/courses/{id}/gradebook
- 35% POST /api/assignments/{id}/submissions
- 15% PUT /api/submissions/{id}/grade
- 10% POST /api/quizzes/{id}/attempts
```

### Metrics to Collect

| Category        | Metric                       | Tool      | Command/Method     |
| --------------- | ---------------------------- | --------- | ------------------ |
| Response Time   | Avg, Min, Max, P90, P95, P99 | K6        | Aggregate Report   |
| Throughput      | Requests/second              | K6        | Aggregate Report   |
| Error Rate      | % Failed requests            | K6        | Aggregate Report   |
| CPU Usage       | % Utilization                | htop      | Manual observation |
| Memory Usage    | Used/Available               | htop      | Manual observation |
| Disk I/O        | Read/Write IOPS, Latency     | iostat    | `iostat -dx 1`     |
| Cache Hit Ratio | Hits/Misses                  | redis-cli | `INFO stats`       |
| Query Time      | Avg query duration           | Telescope | Dashboard          |

---

## Development Commands

### Initial Setup

```bash
# Clone repository
git clone https://github.com/Fahridanaa/lms.git
cd lms

# Copy environment file
cp .env.example .env

# Start Docker containers
./vendor/bin/sail up -d

# Install dependencies
./vendor/bin/sail composer install

# Generate app key
./vendor/bin/sail artisan key:generate

# Run migrations
./vendor/bin/sail artisan migrate

# Seed database
./vendor/bin/sail artisan db:seed

# Clear and warm up cache
./vendor/bin/sail artisan cache:clear
./vendor/bin/sail artisan config:cache
./vendor/bin/sail artisan route:cache
```

### Switching Cache Strategy

```bash
# Edit .env file
CACHE_STRATEGY=cache-aside  # or read-through, write-through

# Clear cache and restart
./vendor/bin/sail artisan cache:clear
./vendor/bin/sail artisan config:clear
./vendor/bin/sail down && ./vendor/bin/sail up -d
```

### Telescope Access

```
URL: http://localhost/telescope
```

---

## Coding Standards

### General Rules

1. **PSR-12** coding standard
2. **Type hints** untuk semua parameter dan return types
3. **DocBlocks** untuk semua public methods
4. **Interface-based** design untuk strategy pattern
5. **Repository pattern** untuk data access

### Naming Conventions

```php
// Interfaces
interface CacheStrategyInterface {}

// Strategy Classes
class CacheAsideStrategy implements CacheStrategyInterface {}
class ReadThroughStrategy implements CacheStrategyInterface {}
class WriteThroughStrategy implements CacheStrategyInterface {}

// Service Classes
class QuizService {}
class MaterialService {}

// Cache Keys (konsisten dan descriptive)
"quiz:{id}"
"quiz:{id}:questions"
"course:{id}:materials"
"course:{id}:gradebook"
"user:{id}:grades"
```

### Cache Key Patterns

```php
// Format: {entity}:{id}:{relation?}
// Examples:
"quiz:123"                    // Single quiz
"quiz:123:questions"          // Quiz with questions
"course:45:materials"         // Course materials list
"course:45:gradebook"         // Course gradebook (aggregated)
"user:789:grades"             // User's all grades
"assignment:56:submissions"   // Assignment submissions
```

---
