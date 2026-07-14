# Analisis CPU Bottleneck pada LMS Application

## Ringkasan Benchmark

**CPU 100% di semua skenario, bahkan di 100 VU.** Data dari `resources-summary.csv`:

| VU | cpu_avg_pct | cpu_max_pct | mem_avg_pct | Disk R (MB/s) | Disk W (MB/s) | Throughput (req/s) |
|----|------------|------------|------------|--------------|--------------|------------------|
| 100 | 86-91% | 100% | 16-26% | 0.1-0.14 | 1.6-16 | 57-67 |
| 250 | 93-95% | 100% | 17-30% | 0.1-0.2 | 1.7-13 | 58-69 |
| 500 | 95-96% | 100% | 18-30% | 0.1-0.28 | 1.7-15 | 59-72 |
| 750 | 96-97% | 100% | 18-31% | 0.1-0.2 | 1.8-14 | 58-68 |
| 1000 | 97-98% | 100% | 19-31% | 0.1-0.46 | 1.6-14 | 60-72 |
| 1500 | 97-98% | 100% | 19-31% | 0.1-0.14 | 1.7-14 | 59-69 |
| 2000 | 98% | 100% | 19-31% | 0.1-0.2 | 1.6-14 | 61-71 |

**Throughput stuck at ~60-72 req/s regardless of VU count.** Adding more users only increases latency (300ms → 26s), never throughput.

---

## Root Cause #1: Gradebook Endpoint — Heavy Aggregation per Request

**Endpoint:** `GET /api/courses/{courseId}/gradebook`  
**Avg latency at 100 VU:** 1.5-4s (no-cache), 2015ms (cache-aside)  
**Avg latency at 1500 VU:** ~24s  

Di `GradebookService::getCourseGradebook()` (line 112-225), satu request melakukan:

1. **Query 1:** `activeStudentIds()` — SELECT semua enrollment untuk course
2. **Query 2:** `GradeItem::where('course_id')` — load semua grade items
3. **Query 3:** Aggregasi JOIN `grades` × `grade_items` dengan `SUM/COALESCE` — rata-rata semua student
4. **Query 4:** `User::whereIn(ids)` — load info student
5. **Query 5:** Load grades per student dengan `with('gradeItem')` — **ini load SEMUA grade rows**
6. **Query 6:** `GradeCategory::with('gradeItems')` — load kategori

**Masalah: Query #5 memuat semua grade untuk semua student dalam satu course.** Untuk 500 student × 20 grade item = 10,000 row. Data ini lalu di-group-by di PHP.

**Masalah: `buildCategoryTree()` (line 568-594)** — recursive closure yang traverse parent-child tree di PHP untuk setiap request.

**Masalah: Cache miss = semua query di atas jalan.** Cache-aside hanya membantu setelah miss pertama, tapi miss pertama tetap lambat dan CPU-heavy. Di no-cache strategy, setiap request mengeksekusi semua ini.

---

## Root Cause #2: Course Structure — Banyak Query per Request

**Endpoint:** `GET /api/courses/{courseId}/structure`  
**Avg latency at 100 VU:** 186-409ms  
**Avg latency at 1500 VU:** ~19.5s  

Di `CourseStructureService::buildStructure()` (line 54-275), satu request melakukan:

1. **Query 1:** `course->sections()->with('learningModules.availabilityRules')`
2. **Query 2:** `Material::whereIn(ids)` — load materials
3. **Query 3:** `Quiz::whereIn(ids)` — load quizzes
4. **Query 4:** `Assignment::whereIn(ids)` — load assignments
5. **Query 5:** `QuizAttempt::whereIn(quizIds)` — count attempts (untuk student)
6. **Query 6:** `Submission::whereIn(assignmentIds)` — latest submissions
7. **Query 7:** `ModuleCompletion::whereIn(moduleIds)` — completion states
8. **Query 8:** `Grade::whereIn(gradeItemIds)` — grades for availability rules
9. **Query 9:** `CourseGroupMember::whereIn(groupIds)` — group membership
10. **Query 10-11:** `CourseGrouping` + `CourseGroupingGroup` — grouping data
11. **Authorization overhead** via `CourseAccessService::readableModulesFor()` — batch-load context, roles, capabilities, role assignments

**Semua query jalan di setiap request**, bahkan dengan cache-aside (cache key per-actor, jadi setiap student punya cache terpisah).

---

## Root Cause #3: Repository Layer — Zero Caching

**Semua repository method melakukan fresh SQL query.** Tidak ada `Cache::remember()` atau `Cache::tags()` di:

- `GradeRepository::getCourseStatistics()` — expensive JOIN + aggregation
- `GradeRepository::getTopPerformers()` — weighted average dengan JOIN
- `QuizAttemptRepository::getAverageScore()` — recalculated on every read
- `SubmissionRepository::getStatistics()` — CASE statements + aggregation
- `BaseRepository::all()` — dumps entire table

**Dampak:** Endpoint yang menampilkan statistics atau top-performers selalu memicu full-scan/aggregation query di MySQL, meskipun data tidak berubah.

---

## Root Cause #4: Authorisasi Heavy per Request

**Class:** `CourseAccessService` (948 lines)

`readableModulesFor()` (line 445-643) melakukan batch-load:

1. **Context::query()** untuk batch module contexts
2. **Context::query()** ancestor paths
3. **Role::query()** — role lookups
4. **Capability::query()** — capability lookups
5. **RoleCapability::query()** — role-capability mappings
6. **RoleAssignment::query()** — semua role assignments di contexts terkait
7. **CourseGroupMember::query()** — group memberships

Ini semua untuk menentukan apakah user bisa melihat module. Setiap authorization check (canReadCourse, canReadGradebook, isInstructorForCourse) menjalankan subquery sendiri.

Setiap `canReadCourse()` memanggil `AuthorizationService::userHasCapabilityAt()` atau `userHasRoleAt()` yang melakukan context path walking — string manipulation di PHP.

---

## Root Cause #5: Course Completion Cascade — Write Amplification

Ketika grade di-update (`GradebookService::updateGrade()`):

1. Grade history record (INSERT)
2. Grade update (UPDATE)
3. **Mark course stale** (UPDATE)
4. **Cache flush** tags (Redis ops)
5. **CourseCompletionService::onGradeUpdate()** — load criteria, evaluasi semua criteria satu per satu, check complete all
6. **Cache invalidation** completion cache

`onGradeUpdate()` (line 197-224) di `CourseCompletionService`:

- Load criteria untuk grade_item yang diupdate
- Evaluate grade criterion (query grade)
- **EvaluateAll()** — load semua criteria untuk course, batch load completions, batch load grades, iterate criteria

Ini terjadi SETIAP KALI grade berubah. Pada write-heavy workload (60% write), cascade ini berulang terus.

---

## Root Cause #6: Cache Strategy Overhead

**Cache-aside strategy (`CacheAsideStrategy::get()`, line 157-194):**

```php
// Always does this even on cache HIT:
try {
    if (!empty($this->cacheTags)) {
        $value = Cache::tags($this->cacheTags)->get($prefixedKey);
    } else {
        $value = Cache::get($prefixedKey);
    }
    // ...
    $this->put($key, $value); // On cache miss: serialize + store to Redis
} finally {
    $this->cacheTags = []; // Reset tags — O(n) array assignment
}
```

**Masalah: Tags-based Redis operations add overhead.** Setiap `Cache::tags()` melibatkan multi-key Redis operations (SADD untuk tag set, kemudian SET untuk cache key).

**Masalah: Serialization/Deserialization.** Data gradebook yang besar (array dengan ribuan item) di `serialize()` PHP → Redis setiap cache miss.

**No-cache strategy (`NoCacheStrategy::get()`, line 223-243):**
```php
public function get(string $key, ?callable $callback = null): mixed
{
    // Skip cache entirely
    if (!empty($this->cacheTags)) {
        $this->cacheTags = [];
    }
    
    if ($callback === null) {
        throw new \RuntimeException("...");
    }
    
    return $callback(); // Always hits DB
}
```

**Cache hit ratio dari benchmark:**
- cache-aside: 42-46% (read-heavy), 27-29% (write-heavy)
- read-through: 45-47% (read-heavy), 28-29% (write-heavy)
- write-through: 42-46% (read-heavy), 28-29% (write-heavy)

Cache hit ratio rendah karena **cache key per-actor** — setiap student punya cache sendiri untuk course structure. Jika ada 500 student, 500 cache keys terpisah. Ditambah write-heavy workload sering invalidate cache.

---

## Root Cause #7: ORM Overhead

**Eloquent N+1 patterns:**

- `GradeRepository::getUserCourseGrades()` (line 31-39) — eager load `gradeable` tapi TIDAK `course`. Jika caller akses `$grade->course`, itu N+1.
- `AssignmentRepository::getUpcomingByCourse()` (line 41-48) — **zero eager loading**. Akses `$assignment->course` atau `->learningModule` = N+1.
- `MaterialRepository::getByTypeAndCourse()` — no `with()`, potensi N+1.

**Polymorphic relations:** Grade memiliki `gradeable` polymorphic (quiz_attempt atau submission). Setiap akses `$grade->gradeable` memicu query tambahan jika tidak eager-loaded.

---

## Kesimpulan: Mengapa CPU 100%?

**Flow satu request typical (gradebook read):**

```
PHP menerima request
  ↓
resolveActor() → load user dari DB (query)
  ↓
canReadGradebook() → 
  ├─ contextService->find() (query)
  └─ authorizationService->userHasCapabilityAt() (subquery)
  ↓
getCourseGradebook() → cache-aside miss →
  ├─ activeStudentIds() (query)
  ├─ GradeItem::where('course_id') (query + PHP hydration)
  ├─ Grade::from('grades','g') JOIN grade_items (aggregation query + PHP hydration)
  ├─ User::whereIn(ids) (query)
  ├─ Grade::where('course_id') (query + 5000-10000 rows hydrated)
  ├─ GradeCategory::with('gradeItems') (query)
  ├─ buildCategoryTree() → recursive PHP loop
  ├─ map() → filter() → values() → all() → PHP loops
  ├─ serialize array → JSON response
  └─ $this->put(key, value) → serialize + Redis SET + SADD tags
  ↓
Response JSON
```

**Setiap langkah memakan CPU:**
1. **MySQL queries** — complex JOINs + aggregations (CPU di DB)
2. **PHP hydration** — Eloquent hydrates setiap row jadi object (CPU di PHP)
3. **Collection operations** — `filter()`, `map()`, `groupBy()`, `keyBy()` iterate arrays (CPU di PHP)
4. **Authorization checks** — context path walking, string manipulation (CPU di PHP)
5. **Cache serialization** — `serialize()`/`unserialize()` data besar (CPU di PHP)
6. **JSON encoding** — `json_encode()` array besar (CPU di PHP)

**Kenapa throughput stuck di ~65 req/s?** Karena setiap request memonopoli CPU untuk waktu yang lama. Pada 100 VU, CPU sudah 86-91%, sehingga request harus antri. Menambah VU tidak menambah throughput — hanya membuat antrian lebih panjang (latensi naik).

---

## Rekomendasi Perbaikan

1. **Gradebook query optimization:** Pindahkan aggregation ke SQL sepenuhnya (`GROUP BY user_id` dengan window functions), jangan load semua grade ke PHP untuk di-loop.
2. **Materialized view / cache warm:** Untuk gradebook yang tidak berubah cepat, pre-compute dan cache JSON response. Hindari recompute tiap request.
3. **Reduce authorization overhead:** Cache context+role lookups per request (request-scoped cache sudah ada di beberapa method tapi tidak konsisten).
4. **Batch grade operations:** Jangan flush cache per-student, gunakan tag-based invalidation yang lebih granular.
5. **Course structure pagination:** Untuk course dengan 50+ module, jangan kirim semuanya dalam satu response.
6. **Eloquent → Query Builder untuk hot path:** Untuk aggregation queries, gunakan `DB::raw()` langsung daripada Eloquent Collection methods yang heavy.
7. **Warm cache proactively:** Jangan tunggu cache miss — pre-compute gradebook dan course structure setelah write operation.
8. **Reduce tag granularity:** Gunakan prefix-based invalidation daripada tag-based untuk hot path read-heavy.
9. **Deduplicate context queries:** Implement request-scoped cache di ContextService untuk menghindari 4× query yang sama.
10. **Eliminate DB write on read path:** Hapus `markRecalculated()` dari gradebook cache callback.
11. **Consolidate grade_items loading:** Load grade_items sekali per request, reuse.

---

## Additional Findings from Agent Analysis

### Gradebook Service (GradebookService agent)
- **DB write on read path (CRITICAL):** `markRecalculated()` executes UPDATE inside cache callback, adding 3-10ms write latency to every cache miss.
- **Grade items loaded 3×:** line 127, 168, 196 — three separate SELECT * FROM grade_items in one request.
- **PHP-side enrollment filtering:** `activeStudentIds()` SELECT * then PHP-filter with isActive() including 2× now() per enrollment.
- **Recursive category tree:** `buildCategoryTree()` uses recursive closure for every cache miss.

### Course Structure (CourseStructure agent)
- **4× duplicate ContextService::find():** canReadCourse, isInstructorForCourse, isActiveEnrollee, readableModulesFor each independently query the same context row.
- **Duplicate role assignment work:** isInstructorForCourse() queries roles, then readableModulesFor() re-fetches all roles again.
- **hasActiveEnrolmentMethod() is a redundant EXISTS:** runs on every isActiveEnrollee() despite enrollment already verified.
- **User-completions tag causes cascading invalidation:** completing any module invalidates ALL course structures for that user.

### Cache Layer (CacheLayer agent)
- **Zero throughput improvement from caching:** no-cache, cache-aside, read-through, write-through all converge on ~57-72 req/s cap.
- **Cache tag overhead dominates:** every tagged operation involves multiple Redis SADD/SREM/FLUSH commands.
- **Aggressive flushTags() after every write:** flushing 'course:{id}' invalidates structure + materials + assignments + gradebook for that course.
- **Redis memory higher with caching:** cache-aside ~2GB vs no-cache ~1.5GB due to tag indices.

### Quiz Service (QuizAssignment agent)
- **Double scoring iteration:** calculate() iterates all questions, then main loop calls scoreQuestion() per question — 2N iterations.
- **Quiz questions collection iterated 3×:** once in calculate(), once in main loop, once for sum('points').
- **Step-data 4× creation per question:** each with is_array check + json_encode/cast — significant allocation.
- **CourseCompletion cascade on every quiz submit:** triggers evaluateAll() loading ALL criteria + completions + grades.

### Repositories (Repositories agent)
- **Zero caching at repository layer:** every aggregate method (getCourseStatistics, getTopPerformers, getAverageScore) hits DB fresh each time.
- **Inconsistent eager loading:** getByCourse loads relations, getUpcomingByCourse loads nothing — causing unpredictable N+1.
- **PHP-level filtering after SQL:** getAllWithCourse() loads rows only to discard them via PHP filter().
- **BaseRepository::all() is a table dump:** no limit, pagination, or caching on full-table reads.
