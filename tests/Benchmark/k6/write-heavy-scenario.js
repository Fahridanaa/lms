import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import {
  headersFor,
  randomEnrolledPair,
  randomInstructorCoursePair,
  READABLE_MATERIAL_TARGETS,
  READABLE_QUIZ_TARGETS,
  READABLE_ASSIGNMENT_TARGETS,
  WRITABLE_MATERIAL_DOWNLOAD_TARGETS,
  WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS,
  WRITABLE_QUIZ_ATTEMPT_TARGETS,
  GRADING_TARGETS,
  GRADE_UPDATE_TARGETS,
  MARKER_GRADE_TARGETS,
  UNAUTHORIZED_GRADE_UPDATE_TARGETS,
  GROUP_RESTRICTED_MODULE_TARGETS,
  PREREQUISITE_LOCKED_TARGETS,
  PREREQUISITE_UNLOCK_TARGETS,
  HIDDEN_MODULE_TARGETS,
  LOCKED_GRADE_TARGETS,
  SUSPENDED_ACCESS_TARGETS,
  NON_ENROLLED_ACCESS_TARGETS,
  pick,
  activityPath,
  scoreWithinMax,
} from './fixtures.js';

// ============================================================
// WRITE-HEAVY SCENARIO (40% Read, 60% Write)
//
// Distribusi Operasi:
//  10% - GET /api/courses/{courseId}/structure          (read)
//  10% - GET /api/courses/{courseId}/gradebook          (read)
//   5% - GET /api/assignments/{id} or material/quiz     (read)
//   5% - GET /api/users/{id}/grades or performance      (read)
//   5% - GET course structure again after write cascade  (read, cascade)
//   5% - Controlled failures (restricted/suspended/etc)  (read, controlled)
//  20% - POST /api/assignments/{id}/submissions         (write)
//  15% - POST quiz attempt → PUT submit answers         (write)
//   5% - GET /api/materials/{id}/download (completion)   (write)
//   5% - PUT /api/submissions/{id}/grade                (write)
//   5% - PUT /api/submissions/{id}/marker-grade         (write)
//  10% - PUT /api/grades/{id}                           (write)
// -------------------------------------------------------
// Total: 40% read, 60% write
// ============================================================

const errorRate = new Rate('errors');
const courseStructureDuration    = new Trend('course_structure_duration', true);
const gradebookDuration          = new Trend('gradebook_duration', true);
const readActivityDuration       = new Trend('read_activity_duration', true);
const userGradesDuration         = new Trend('user_grades_duration', true);
const cascadeReadDuration        = new Trend('cascade_read_duration', true);
const submitAssignmentDuration   = new Trend('submit_assignment_duration', true);
const submitQuizDuration         = new Trend('submit_quiz_duration', true);
const materialDownloadDuration   = new Trend('material_download_duration', true);
const gradeSubmissionDuration    = new Trend('grade_submission_duration', true);
const markerGradeDuration        = new Trend('marker_grade_duration', true);
const gradeUpdateDuration        = new Trend('grade_update_duration', true);

const CONCURRENT_USERS = parseInt(__ENV.CONCURRENT_USERS || '100');

export const options = {
  stages: [
    { duration: '1m',  target: CONCURRENT_USERS },
    { duration: '5m',  target: CONCURRENT_USERS },
    { duration: '30s', target: 0 },
  ],
  thresholds: {
    // http_req_failed{ef:1} tracks CONTROLLED failure requests (expect 403/404).
    // These always fail by design, so allow 100% failure rate.
    'http_req_failed{ef:1}': ['rate<1.01'],
    // http_req_failed excludes controlled failures (tagged expected_response: false).
    // Only unexpected failures count here — write collisions, 5xx, timeouts.
    http_req_failed:          ['rate<0.15'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
  tags: {
    scenario:          'write-heavy',
    concurrent_users:  `${CONCURRENT_USERS}`,
    cache_strategy:    __ENV.CACHE_STRATEGY || 'unknown',
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost';

export default function () {
  const action = Math.random();

  // ── 10% — Course Structure (READ) ──────────────────────
  if (action < 0.10) {
    const pair = randomEnrolledPair();
    const h = headersFor(pair.studentId);
    const res = http.get(`${BASE_URL}/api/courses/${pair.courseId}/structure`, h);
    courseStructureDuration.add(res.timings.duration);
    check(res, {
      '[wh-structure] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 10% — Gradebook (READ, instructor) ─────────────────
  } else if (action < 0.20) {
    const pair = randomInstructorCoursePair();
    const h = headersFor(pair.instructorId);
    const res = http.get(`${BASE_URL}/api/courses/${pair.courseId}/gradebook`, h);
    gradebookDuration.add(res.timings.duration);
    check(res, {
      '[wh-gradebook] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 5% — Activity Detail (READ, actor-aware) ────────────
  } else if (action < 0.25) {
    const activityType = Math.random();
    let target;
    let url;
    if (activityType < 0.33) {
      target = pick(READABLE_ASSIGNMENT_TARGETS);
      url = `${BASE_URL}/api/assignments/${target.activityId}`;
    } else if (activityType < 0.66) {
      target = pick(READABLE_QUIZ_TARGETS);
      url = `${BASE_URL}/api/quizzes/${target.activityId}`;
    } else {
      target = pick(READABLE_MATERIAL_TARGETS);
      url = `${BASE_URL}/api/materials/${target.activityId}`;
    }
    const h = headersFor(target.studentId);
    const res = http.get(url, h);
    readActivityDuration.add(res.timings.duration);
    // Actor-aware: fully available activity for the paired student → 200
    check(res, {
      '[wh-activity] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 5% — User Grades/Performance (READ, own) ──────────
  } else if (action < 0.30) {
    const pair = randomEnrolledPair();
    const h = headersFor(pair.studentId);
    const isGrades = Math.random() < 0.5;
    const endpoint = isGrades
      ? `/api/users/${pair.studentId}/grades`
      : `/api/users/${pair.studentId}/performance`;
    const res = http.get(`${BASE_URL}${endpoint}`, h);
    userGradesDuration.add(res.timings.duration);
    check(res, {
      '[wh-user-grades] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 5% — Completion Cascade: read → write → read (CASCADE) ──
  } else if (action < 0.35) {
    const target = pick(WRITABLE_MATERIAL_DOWNLOAD_TARGETS);
    const h = headersFor(target.studentId);

    // Step 1: Read course structure before write
    const preRes = http.get(`${BASE_URL}/api/courses/${target.courseId}/structure`, h);
    const preOk = check(preRes, {
      '[wh-cascade-pre] status 200': (r) => r.status === 200,
    });
    if (!preOk) { errorRate.add(1); return; }

    // Step 2: Perform write action — material download triggers completion
    const writeRes = http.get(`${BASE_URL}/api/materials/${target.activityId}/download`, h);
    const writeOk = check(writeRes, {
      '[wh-cascade-write] status 200': (r) => r.status === target.expectedStatus,
    });
    if (!writeOk) { errorRate.add(1); return; }

    // Step 3: Read course structure again — cache should be invalidated
    const postRes = http.get(`${BASE_URL}/api/courses/${target.courseId}/structure`, h);
    cascadeReadDuration.add(postRes.timings.duration);
    const postOk = check(postRes, {
      '[wh-cascade-post] status 200': (r) => r.status === 200,
    });
    if (!postOk) errorRate.add(1);

  // ── 5% — Controlled Failures (READ, expected 403/404) ─
  } else if (action < 0.40) {
    const failureType = Math.random();

    // All controlled-failure requests are tagged ef=1 so http_req_failed threshold can exclude them

    if (failureType < 0.20 && GROUP_RESTRICTED_MODULE_TARGETS.length > 0) {
      const target = pick(GROUP_RESTRICTED_MODULE_TARGETS);
      const h = { ...headersFor(target.userId), tags: { ef: '1', expected_response: false } };
      const res = http.get(`${BASE_URL}${activityPath(target)}`, h);
      const ok = check(res, {
        '[wh-cf-group] status 404': (r) => r.status === target.expectedStatus,
      });
      if (!ok) errorRate.add(1);

    } else if (failureType < 0.50 && SUSPENDED_ACCESS_TARGETS.length > 0) {
      const target = pick(SUSPENDED_ACCESS_TARGETS);
      const h = { ...headersFor(target.userId), tags: { ef: '1', expected_response: false } };
      const res = http.get(`${BASE_URL}/api/courses/${target.courseId}/structure`, h);
      const ok = check(res, {
        '[wh-cf-suspended] status 403': (r) => r.status === target.expectedStatus,
      });
      if (!ok) errorRate.add(1);

    } else if (failureType < 0.75 && NON_ENROLLED_ACCESS_TARGETS.length > 0) {
      const target = pick(NON_ENROLLED_ACCESS_TARGETS);
      const h = { ...headersFor(target.userId), tags: { ef: '1', expected_response: false } };
      const res = http.get(`${BASE_URL}/api/courses/${target.courseId}/structure`, h);
      const ok = check(res, {
        '[wh-cf-non-enrolled] status 403': (r) => r.status === target.expectedStatus,
      });
      if (!ok) errorRate.add(1);

    } else if (LOCKED_GRADE_TARGETS.length > 0) {
      // Attempt grade update on a locked grade item (expect 403)
      const target = pick(LOCKED_GRADE_TARGETS);
      const h = { ...headersFor(target.instructorId), tags: { ef: '1', expected_response: false } };
      const res = http.put(
        `${BASE_URL}/api/grades/${target.gradeId}`,
        JSON.stringify({ score: 85 }),
        h
      );
      gradeUpdateDuration.add(res.timings.duration);
      const ok = check(res, {
        '[wh-cf-locked-grade] status 403': (r) => r.status === target.expectedStatus,
      });
      if (!ok) errorRate.add(1);
    }

  // ── 20% — Assignment Submission (WRITE, actor-valid) ────
  } else if (action < 0.60) {
    const target = pick(WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS);
    const h = headersFor(target.studentId);
    const res = http.post(
      `${BASE_URL}/api/assignments/${target.activityId}/submissions`,
      JSON.stringify({
        file_path: `benchmark/wh-submit-${__VU}-${Date.now()}.pdf`,
      }),
      h
    );
    submitAssignmentDuration.add(res.timings.duration);
    const ok = check(res, {
      '[wh-submit] status 201': (r) => r.status === target.expectedStatus,
    });
    if (!ok) errorRate.add(1);

  // ── 15% — Quiz Submit Chain: start → submit (WRITE, actor-valid) ──
  } else if (action < 0.75) {
    const target = pick(WRITABLE_QUIZ_ATTEMPT_TARGETS);
    const h = headersFor(target.studentId);

    // Step 1: Start attempt
    const startRes = http.post(
      `${BASE_URL}/api/quizzes/${target.activityId}/attempts`,
      JSON.stringify({}),
      h
    );
    const startOk = check(startRes, {
      '[wh-quiz] start 201': (r) => r.status === target.expectedStatus,
    });
    if (!startOk) {
      errorRate.add(1);
    } else {
      let data = null;
      try {
        data = JSON.parse(startRes.body).data;
      } catch (_) {
        errorRate.add(1);
        return;
      }
      if (data && data.id) {
        if (!target.answers || Object.keys(target.answers).length === 0) {
          errorRate.add(1);
          return;
        }
        const submitRes = http.put(
          `${BASE_URL}/api/quizzes/${target.activityId}/attempts/${data.id}`,
          JSON.stringify({ answers: target.answers }),
          h
        );
        submitQuizDuration.add(submitRes.timings.duration);
        const submitOk = check(submitRes, {
          '[wh-quiz] submit 200': (r) => r.status === 200,
        });
        if (!submitOk) errorRate.add(1);
      }
    }

  // ── 5% — Material Download (WRITE, actor-valid) ────────
  } else if (action < 0.80) {
    const target = pick(WRITABLE_MATERIAL_DOWNLOAD_TARGETS);
    const h = headersFor(target.studentId);
    const res = http.get(`${BASE_URL}/api/materials/${target.activityId}/download`, h);
    materialDownloadDuration.add(res.timings.duration);
    const ok = check(res, {
      '[wh-material-dl] status 200': (r) => r.status === target.expectedStatus,
    });
    if (!ok) errorRate.add(1);

  // ── 5% — Marker Grade (WRITE, marker-valid) ───────────
  } else if (action < 0.85) {
    if (MARKER_GRADE_TARGETS.length > 0) {
      const target = pick(MARKER_GRADE_TARGETS);
      const h = headersFor(target.markerId);
      const res = http.put(
        `${BASE_URL}/api/submissions/${target.submissionId}/marker-grade`,
        JSON.stringify({
          score: Math.floor(Math.random() * 41) + 60,
          feedback: 'Benchmark marker grading.',
        }),
        h
      );
      markerGradeDuration.add(res.timings.duration);
      check(res, {
        '[wh-marker-grade] status 200': (r) => r.status === 200,
      }) || errorRate.add(1);
    }

  // ── 5% — Grade Submission (WRITE, instructor, valid target) ──
  } else if (action < 0.90) {
    const target = pick(GRADING_TARGETS);
    const h = headersFor(target.instructorId);
    const res = http.put(
      `${BASE_URL}/api/submissions/${target.submissionId}/grade`,
      JSON.stringify({
        score: Math.floor(Math.random() * 41) + 60,
        feedback: 'Benchmark grading - automated.',
      }),
      h
    );
    gradeSubmissionDuration.add(res.timings.duration);
    check(res, {
      '[wh-grade] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 5% — Grade Update via PUT /api/grades/{id} (WRITE, valid) ──
  } else if (action < 0.95) {
    const target = pick(GRADE_UPDATE_TARGETS);
    const h = headersFor(target.instructorId);
    const res = http.put(
      `${BASE_URL}/api/grades/${target.gradeId}`,
      JSON.stringify({
        score: scoreWithinMax(target),
      }),
      h
    );
    gradeUpdateDuration.add(res.timings.duration);
    check(res, {
      '[wh-grade-update] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 5% — Controlled Failure: unauthorized grade update (expect 403) ──
  } else {
    const target = pick(UNAUTHORIZED_GRADE_UPDATE_TARGETS);
    const h = { ...headersFor(target.instructorId), tags: { ef: '1', expected_response: false } };
    const res = http.put(
      `${BASE_URL}/api/grades/${target.gradeId}`,
      JSON.stringify({
        score: scoreWithinMax(target),
      }),
      h
    );
    gradeUpdateDuration.add(res.timings.duration);
    const ok = check(res, {
      '[wh-grade-unauth] status 403': (r) => r.status === target.expectedStatus,
    });
    if (!ok) errorRate.add(1);
  }

  sleep(Math.random() * 1.2 + 0.3);
}

export function handleSummary(data) {
  return {
    [__ENV.SUMMARY_EXPORT || '/tmp/k6-summary.json']: JSON.stringify(data),
  };
}
