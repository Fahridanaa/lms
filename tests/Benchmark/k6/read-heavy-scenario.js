import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// ============================================================
// READ-HEAVY SCENARIO (80% Read, 20% Write)
// Sesuai Tabel 3.2 Proposal Skripsi
//
// Distribusi Operasi:
//   25% - GET /api/quizzes/{id}                      (read)
//   20% - GET /api/courses/{courseId}/materials       (read)
//   15% - GET /api/materials/{id}                     (read)
//   20% - GET /api/courses/{courseId}/gradebook       (read)
//   10% - POST /api/quizzes/{id}/attempts             (write)
//   10% - POST /api/assignments/{id}/submissions      (write)
//
// Penggunaan:
//   k6 run --env BASE_URL=http://your-vps-ip \
//           --env CONCURRENT_USERS=100 \
//           --env CACHE_STRATEGY=cache-aside \
//           read-heavy-scenario.js
// ============================================================

// Custom metrics per endpoint
const errorRate = new Rate('errors');
const quizDetailDuration    = new Trend('quiz_detail_duration', true);
const materialsListDuration = new Trend('materials_list_duration', true);
const materialDetailDuration = new Trend('material_detail_duration', true);
const gradebookDuration     = new Trend('gradebook_duration', true);
const startAttemptDuration  = new Trend('start_attempt_duration', true);
const submitAssignmentDuration = new Trend('submit_assignment_duration', true);

// Ambil concurrent users dari env (default: 100)
const CONCURRENT_USERS = parseInt(__ENV.CONCURRENT_USERS || '100');

export const options = {
  // 1 menit ramp-up + 5 menit steady state + 30 detik ramp-down
  // Sesuai proposal: "Duration: 5 menit per test, Ramp-up: 60 detik"
  stages: [
    { duration: '1m',  target: CONCURRENT_USERS },  // ramp-up
    { duration: '5m',  target: CONCURRENT_USERS },  // steady state
    { duration: '30s', target: 0 },                 // ramp-down
  ],
  thresholds: {
    http_req_duration: ['p(95)<2000'],  // 95% request < 2 detik
    http_req_failed:   ['rate<0.10'],   // error rate < 10%
    errors:            ['rate<0.10'],
  },
  // Pastikan p(99) ikut tersimpan di summary export
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
  tags: {
    scenario:          'read-heavy',
    concurrent_users:  `${CONCURRENT_USERS}`,
    cache_strategy:    __ENV.CACHE_STRATEGY || 'unknown',
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost';

const headers = { headers: { 'Content-Type': 'application/json' }, timeout: '30s' };

// ─────────────────────────────────────────────
// Helper: random integer [min, max]
// ─────────────────────────────────────────────
function randomInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

// Sesuai data seeder:
//   - User 1–100      : instructors
//   - User 101–5000   : students
//   - Course 1–50
//   - Quiz 1–250      (5 per course)
//   - Material 1–500  (10 per course)
//   - Assignment 1–250 (5 per course)

function randomStudentId()    { return randomInt(101, 5000); }
function randomCourseId()     { return randomInt(1, 50);     }
function randomQuizId()       { return randomInt(1, 250);    }
function randomMaterialId()   { return randomInt(1, 500);    }
function randomAssignmentId() { return randomInt(1, 250);    }

// ─────────────────────────────────────────────
// Main VU function
// ─────────────────────────────────────────────
export default function () {
  const action = Math.random();

  if (action < 0.25) {
    // ── 25% ─ GET /api/quizzes/{id} ──────────────────────
    const res = http.get(`${BASE_URL}/api/quizzes/${randomQuizId()}`, headers);
    quizDetailDuration.add(res.timings.duration);
    check(res, {
      '[quiz-detail] status 200': (r) => r.status === 200,
      '[quiz-detail] success':    (r) => {
        try { return JSON.parse(r.body).success === true; } catch(_) { return false; }
      },
    }) || errorRate.add(1);

  } else if (action < 0.45) {
    // ── 20% ─ GET /api/courses/{id}/materials ────────────
    const res = http.get(`${BASE_URL}/api/courses/${randomCourseId()}/materials`, headers);
    materialsListDuration.add(res.timings.duration);
    check(res, {
      '[materials-list] status 200': (r) => r.status === 200,
      '[materials-list] success':    (r) => {
        try { return JSON.parse(r.body).success === true; } catch(_) { return false; }
      },
    }) || errorRate.add(1);

  } else if (action < 0.60) {
    // ── 15% ─ GET /api/materials/{id} ────────────────────
    const res = http.get(`${BASE_URL}/api/materials/${randomMaterialId()}`, headers);
    materialDetailDuration.add(res.timings.duration);
    check(res, {
      '[material-detail] status 200': (r) => r.status === 200,
      '[material-detail] success':    (r) => {
        try { return JSON.parse(r.body).success === true; } catch(_) { return false; }
      },
    }) || errorRate.add(1);

  } else if (action < 0.80) {
    // ── 20% ─ GET /api/courses/{id}/gradebook ────────────
    // Query berat: agregasi nilai semua mahasiswa per course
    const res = http.get(`${BASE_URL}/api/courses/${randomCourseId()}/gradebook`, headers);
    gradebookDuration.add(res.timings.duration);
    check(res, {
      '[gradebook] status 200': (r) => r.status === 200,
      '[gradebook] success':    (r) => {
        try { return JSON.parse(r.body).success === true; } catch(_) { return false; }
      },
    }) || errorRate.add(1);

  } else if (action < 0.90) {
    // ── 10% ─ POST /api/quizzes/{id}/attempts ────────────
    const res = http.post(
      `${BASE_URL}/api/quizzes/${randomQuizId()}/attempts`,
      JSON.stringify({ user_id: randomStudentId() }),
      headers
    );
    startAttemptDuration.add(res.timings.duration);
    check(res, {
      '[start-attempt] status 201': (r) => r.status === 201,
      '[start-attempt] success':    (r) => {
        try { return JSON.parse(r.body).success === true; } catch(_) { return false; }
      },
    }) || errorRate.add(1);

  } else {
    // ── 10% ─ POST /api/assignments/{id}/submissions ──────
    const res = http.post(
      `${BASE_URL}/api/assignments/${randomAssignmentId()}/submissions`,
      JSON.stringify({
        user_id:   randomStudentId(),
        file_path: `submissions/rh-${Date.now()}-${randomInt(1000, 9999)}.pdf`,
      }),
      headers
    );
    submitAssignmentDuration.add(res.timings.duration);
    check(res, {
      '[submit-assignment] status 201': (r) => r.status === 201,
      '[submit-assignment] success':    (r) => {
        try { return JSON.parse(r.body).success === true; } catch(_) { return false; }
      },
    }) || errorRate.add(1);
  }

  // Think time: 0.5–2 detik (simulasi user nyata)
  sleep(Math.random() * 1.5 + 0.5);
}
