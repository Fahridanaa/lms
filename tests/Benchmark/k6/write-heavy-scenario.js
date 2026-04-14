import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// ============================================================
// WRITE-HEAVY SCENARIO (40% Read, 60% Write)
// Sesuai Tabel 3.3 Proposal Skripsi
//
// Distribusi Operasi:
//   20% - GET  /api/assignments/{id}                          (read)
//   20% - GET  /api/courses/{courseId}/gradebook              (read)
//   30% - POST /api/assignments/{id}/submissions              (write)
//   20% - PUT  /api/quizzes/{quizId}/attempts/{attemptId}     (write) *
//   10% - PUT  /api/submissions/{id}/grade                    (write)
//
//   * Karena PUT submit kuis memerlukan attempt ID yang aktif,
//     operasi ini dieksekusi sebagai chain: start → submit.
//
// Penggunaan:
//   k6 run --env BASE_URL=http://your-vps-ip \
//           --env CONCURRENT_USERS=100 \
//           --env CACHE_STRATEGY=cache-aside \
//           write-heavy-scenario.js
// ============================================================

// Custom metrics per endpoint
const errorRate = new Rate('errors');
const assignmentDetailDuration  = new Trend('assignment_detail_duration', true);
const gradebookDuration         = new Trend('gradebook_duration', true);
const submitAssignmentDuration  = new Trend('submit_assignment_duration', true);
const submitQuizDuration        = new Trend('submit_quiz_duration', true);
const gradeSubmissionDuration   = new Trend('grade_submission_duration', true);

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
    http_req_duration: ['p(95)<3000'],  // write ops lebih lambat, toleransi lebih tinggi
    http_req_failed:   ['rate<0.10'],
    errors:            ['rate<0.10'],
  },
  tags: {
    scenario:         'write-heavy',
    concurrent_users: `${CONCURRENT_USERS}`,
    cache_strategy:   __ENV.CACHE_STRATEGY || 'unknown',
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
//   - Assignment 1–250 (5 per course)
//   - Submission 1–12500 (seeded)

function randomStudentId()     { return randomInt(101, 5000);   }
function randomInstructorId()  { return randomInt(1, 100);      }
function randomCourseId()      { return randomInt(1, 50);       }
function randomQuizId()        { return randomInt(1, 250);      }
function randomAssignmentId()  { return randomInt(1, 250);      }
function randomSubmissionId()  { return randomInt(1, 12500);    }

// Generate random jawaban kuis (10–20 soal)
function generateRandomAnswers() {
  const answers = {};
  const numQuestions = randomInt(10, 20);
  for (let i = 0; i < numQuestions; i++) {
    const questionId = randomInt(1, 5000);
    answers[questionId] = ['A', 'B', 'C', 'D'][randomInt(0, 3)];
  }
  return answers;
}

// ─────────────────────────────────────────────
// Main VU function
// ─────────────────────────────────────────────
export default function () {
  const action = Math.random();

  if (action < 0.20) {
    // ── 20% ─ GET /api/assignments/{id} ──────────────────
    const res = http.get(`${BASE_URL}/api/assignments/${randomAssignmentId()}`, headers);
    assignmentDetailDuration.add(res.timings.duration);
    check(res, {
      '[assignment-detail] status 200': (r) => r.status === 200,
      '[assignment-detail] success':    (r) => {
        try { return JSON.parse(r.body).success === true; } catch(_) { return false; }
      },
    }) || errorRate.add(1);

  } else if (action < 0.40) {
    // ── 20% ─ GET /api/courses/{id}/gradebook ────────────
    const res = http.get(`${BASE_URL}/api/courses/${randomCourseId()}/gradebook`, headers);
    gradebookDuration.add(res.timings.duration);
    check(res, {
      '[gradebook] status 200': (r) => r.status === 200,
      '[gradebook] success':    (r) => {
        try { return JSON.parse(r.body).success === true; } catch(_) { return false; }
      },
    }) || errorRate.add(1);

  } else if (action < 0.70) {
    // ── 30% ─ POST /api/assignments/{id}/submissions ──────
    // Simulasi peak load menjelang deadline pengumpulan tugas
    const res = http.post(
      `${BASE_URL}/api/assignments/${randomAssignmentId()}/submissions`,
      JSON.stringify({
        user_id:   randomStudentId(),
        file_path: `submissions/wh-${Date.now()}-${randomInt(1000, 9999)}.pdf`,
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

  } else if (action < 0.90) {
    // ── 20% ─ PUT /api/quizzes/{quizId}/attempts/{attemptId}
    // Simulasi submit jawaban kuis saat ujian berlangsung.
    // Dieksekusi sebagai chain: start attempt → submit answers.
    const quizId = randomQuizId();
    const userId  = randomStudentId();

    // Step 1: Mulai attempt baru
    const startRes = http.post(
      `${BASE_URL}/api/quizzes/${quizId}/attempts`,
      JSON.stringify({ user_id: userId }),
      headers
    );

    const startOk = check(startRes, {
      '[quiz-attempt] start 201': (r) => r.status === 201,
    });

    if (startOk) {
      try {
        const attemptId = JSON.parse(startRes.body).data.id;

        // Step 2: Submit jawaban
        const submitRes = http.put(
          `${BASE_URL}/api/quizzes/${quizId}/attempts/${attemptId}`,
          JSON.stringify({ answers: generateRandomAnswers() }),
          headers
        );
        submitQuizDuration.add(submitRes.timings.duration);
        check(submitRes, {
          '[quiz-attempt] submit 200': (r) => r.status === 200,
          '[quiz-attempt] success':    (r) => {
            try { return JSON.parse(r.body).success === true; } catch(_) { return false; }
          },
        }) || errorRate.add(1);

      } catch (_) {
        errorRate.add(1);
      }
    } else {
      // Jika start gagal (misal: sudah ada attempt aktif), tidak hitung sebagai error fatal
      // karena ini bisa terjadi secara wajar saat load tinggi
    }

  } else {
    // ── 10% ─ PUT /api/submissions/{id}/grade ────────────
    // Simulasi dosen menilai tugas (cascading cache invalidation)
    const res = http.put(
      `${BASE_URL}/api/submissions/${randomSubmissionId()}/grade`,
      JSON.stringify({
        score:    randomInt(50, 100),
        feedback: 'Load test - penilaian otomatis.',
      }),
      headers
    );
    gradeSubmissionDuration.add(res.timings.duration);
    check(res, {
      '[grade-submission] status 200': (r) => r.status === 200,
      '[grade-submission] success':    (r) => {
        try { return JSON.parse(r.body).success === true; } catch(_) { return false; }
      },
    }) || errorRate.add(1);
  }

  // Think time: 0.3–1.5 detik (write scenario lebih agresif)
  sleep(Math.random() * 1.2 + 0.3);
}
