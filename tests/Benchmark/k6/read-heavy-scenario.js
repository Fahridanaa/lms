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
  WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS,
  WRITABLE_QUIZ_ATTEMPT_TARGETS,
  QUIZ_DETAIL_ATTEMPT_TARGETS,
  COURSE_COMPLETION_CHECK_TARGETS,
  GROUP_RESTRICTED_MODULE_TARGETS,
  GROUPING_RESTRICTED_MODULE_TARGETS,
  PREREQUISITE_LOCKED_TARGETS,
  HIDDEN_MODULE_TARGETS,
  MIN_GRADE_LOCKED_TARGETS,
  SUSPENDED_ACCESS_TARGETS,
  NON_ENROLLED_ACCESS_TARGETS,
  pick,
  activityPath,
} from './fixtures.js';

// ============================================================
// READ-HEAVY SCENARIO (80% Read, 20% Write)
//
// Distribusi Operasi:
//  25% - GET /api/courses/{courseId}/structure          (read)
//  10% - GET /api/materials/{id}                          (read)
//   5% - GET /api/quizzes/{id}                          (read)
//   8% - GET /api/assignments/{id}                      (read)
//  12% - GET /api/courses/{id}/gradebook                (read)
//   5% - GET /api/users/{id}/grades                     (read)
//   3% - GET /api/courses/{id}/materials                (read)
//   2% - GET /api/courses/{id}/completion               (read, course completion)
//   7% - Expected failure: restricted/hidden/unavailable (read, controlled)
//   3% - GET /api/quizzes/{id}/attempts/{id}/result     (read, detail)
//  10% - POST /api/quizzes/{id}/attempts                (write)
//  10% - POST /api/assignments/{id}/submissions         (write)
// -------------------------------------------------------
// Total: 80% read (incl. 7% controlled failures), 20% write
// ============================================================

const errorRate = new Rate('errors');
const courseStructureDuration  = new Trend('course_structure_duration', true);
const materialDetailDuration   = new Trend('material_detail_duration', true);
const quizDetailDuration       = new Trend('quiz_detail_duration', true);
const assignmentDetailDuration = new Trend('assignment_detail_duration', true);
const gradebookDuration        = new Trend('gradebook_duration', true);
const userGradesDuration       = new Trend('user_grades_duration', true);
const materialListDuration         = new Trend('material_list_duration', true);
const courseCompletionDuration      = new Trend('course_completion_duration', true);
const quizAttemptResultDuration     = new Trend('quiz_attempt_result_duration', true);
const startAttemptDuration          = new Trend('start_attempt_duration', true);
const submitAssignmentDuration = new Trend('submit_assignment_duration', true);

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
    // http_req_failed includes both controlled failures (~7% ef:1) and
    // unexpected failures. After php-fpm tuning, unexpected failures should
    // be near 0, so total rate must be < 5%.
    http_req_failed:          ['rate<0.15'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
  tags: {
    scenario:          'read-heavy',
    concurrent_users:  `${CONCURRENT_USERS}`,
    cache_strategy:    __ENV.CACHE_STRATEGY || 'unknown',
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost';

export default function () {
  const action = Math.random();

  // ── 25% — Course Structure (READ) ──────────────────────
  if (action < 0.25) {
    const pair = randomEnrolledPair();
    const h = headersFor(pair.studentId);
    const res = http.get(`${BASE_URL}/api/courses/${pair.courseId}/structure`, h);
    courseStructureDuration.add(res.timings.duration);
    check(res, {
      '[course-structure] status 200': (r) => r.status === 200,
      '[course-structure] has sections': (r) => {
        try {
          const d = JSON.parse(r.body).data;
          return d && d.sections && d.sections.length > 0;
        } catch(_) { return false; }
      },
    }) || errorRate.add(1);

  // ── 10% — Material Detail (READ, actor-aware) ──────────
  } else if (action < 0.35) {
    const target = pick(READABLE_MATERIAL_TARGETS);
    const h = headersFor(target.studentId);
    const res = http.get(`${BASE_URL}/api/materials/${target.activityId}`, h);
    materialDetailDuration.add(res.timings.duration);
    // Enrolled student + fully available material → 200
    check(res, {
      '[material] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 5% — Quiz Detail (READ, actor-aware) ──────────────
  } else if (action < 0.40) {
    const target = pick(READABLE_QUIZ_TARGETS);
    const h = headersFor(target.studentId);
    const res = http.get(`${BASE_URL}/api/quizzes/${target.activityId}`, h);
    quizDetailDuration.add(res.timings.duration);
    // Enrolled student + fully available quiz → 200
    check(res, {
      '[quiz-detail] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 8% — Assignment Detail (READ, actor-aware) ─────────
  } else if (action < 0.48) {
    const target = pick(READABLE_ASSIGNMENT_TARGETS);
    const h = headersFor(target.studentId);
    const res = http.get(`${BASE_URL}/api/assignments/${target.activityId}`, h);
    assignmentDetailDuration.add(res.timings.duration);
    // Enrolled student + fully available assignment → 200
    check(res, {
      '[assignment-detail] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 12% — Gradebook (READ, instructor only) ────────────
  } else if (action < 0.60) {
    const pair = randomInstructorCoursePair();
    const h = headersFor(pair.instructorId);
    const res = http.get(`${BASE_URL}/api/courses/${pair.courseId}/gradebook`, h);
    gradebookDuration.add(res.timings.duration);
    check(res, {
      '[gradebook] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 5% — User Grades (READ, student reads own) ────────
  } else if (action < 0.65) {
    const pair = randomEnrolledPair();
    const h = headersFor(pair.studentId);
    const res = http.get(`${BASE_URL}/api/users/${pair.studentId}/grades`, h);
    userGradesDuration.add(res.timings.duration);
    check(res, {
      '[user-grades] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 3% — Course Materials List (READ) ─────────────────
  } else if (action < 0.68) {
    const pair = randomEnrolledPair();
    const h = headersFor(pair.studentId);
    const res = http.get(`${BASE_URL}/api/courses/${pair.courseId}/materials`, h);
    materialListDuration.add(res.timings.duration);
    check(res, {
      '[material-list] status 200': (r) => r.status === 200,
    }) || errorRate.add(1);

  // ── 2% — Course Completion State (READ) ────────────────
  } else if (action < 0.70) {
    if (COURSE_COMPLETION_CHECK_TARGETS.length > 0) {
      const target = pick(COURSE_COMPLETION_CHECK_TARGETS);
      const h = headersFor(target.studentId);
      const res = http.get(
        `${BASE_URL}/api/courses/${target.courseId}/completion`,
        h
      );
      courseCompletionDuration.add(res.timings.duration);
      check(res, {
        '[course-completion] status 200': (r) => r.status === 200,
        '[course-completion] has progress': (r) => {
          try {
            const body = JSON.parse(r.body);
            return body && body.progress && typeof body.progress.criteria_total === 'number';
          } catch(_) { return false; }
        },
      }) || errorRate.add(1);
    }

  // ── 7% — Controlled Failures (READ, expected 403/404) ─
  } else if (action < 0.77) {
    const failureType = Math.random();

    // All controlled-failure requests are tagged ef=1 so http_req_failed threshold can exclude them

    if (failureType < 0.20 && GROUP_RESTRICTED_MODULE_TARGETS.length > 0) {
      // Group-restricted module access (expect 404)
      const target = pick(GROUP_RESTRICTED_MODULE_TARGETS);
      const h = { ...headersFor(target.userId), tags: { ef: '1' } };
      const res = http.get(`${BASE_URL}${activityPath(target)}`, h);
      const ok = check(res, {
        '[cf-group-restricted] status 404': (r) => r.status === target.expectedStatus,
      });
      if (!ok) errorRate.add(1);

    } else if (failureType < 0.40 && PREREQUISITE_LOCKED_TARGETS.length > 0) {
      // Prerequisite-locked module (expect 404)
      const target = pick(PREREQUISITE_LOCKED_TARGETS);
      const h = { ...headersFor(target.userId), tags: { ef: '1' } };
      const res = http.get(`${BASE_URL}${activityPath(target)}`, h);
      const ok = check(res, {
        '[cf-prereq-locked] status 404': (r) => r.status === target.expectedStatus,
      });
      if (!ok) errorRate.add(1);

    } else if (failureType < 0.60 && MIN_GRADE_LOCKED_TARGETS.length > 0) {
      // Min-grade locked module (expect 404)
      const target = pick(MIN_GRADE_LOCKED_TARGETS);
      const h = { ...headersFor(target.userId), tags: { ef: '1' } };
      const res = http.get(`${BASE_URL}${activityPath(target)}`, h);
      const ok = check(res, {
        '[cf-min-grade-locked] status 404': (r) => r.status === target.expectedStatus,
      });
      if (!ok) errorRate.add(1);

    } else if (failureType < 0.80 && GROUPING_RESTRICTED_MODULE_TARGETS.length > 0) {
      // Grouping-restricted module access (expect 404)
      const target = pick(GROUPING_RESTRICTED_MODULE_TARGETS);
      const h = { ...headersFor(target.userId), tags: { ef: '1' } };
      const res = http.get(`${BASE_URL}${activityPath(target)}`, h);
      const ok = check(res, {
        '[cf-grouping-restricted] status 404': (r) => r.status === (target.expectedStatus || 404),
      });
      if (!ok) errorRate.add(1);

    } else if (SUSPENDED_ACCESS_TARGETS.length > 0) {
      // Suspended student tries to read (expect 403)
      const target = pick(SUSPENDED_ACCESS_TARGETS);
      const h = { ...headersFor(target.userId), tags: { ef: '1' } };
      const res = http.get(`${BASE_URL}/api/courses/${target.courseId}/structure`, h);
      const ok = check(res, {
        '[cf-suspended] status 403': (r) => r.status === target.expectedStatus,
      });
      if (!ok) errorRate.add(1);

    } else if (NON_ENROLLED_ACCESS_TARGETS.length > 0) {
      // Non-enrolled student tries to read (expect 403)
      const target = pick(NON_ENROLLED_ACCESS_TARGETS);
      const h = { ...headersFor(target.userId), tags: { ef: '1' } };
      const res = http.get(`${BASE_URL}/api/courses/${target.courseId}/structure`, h);
      const ok = check(res, {
        '[cf-non-enrolled] status 403': (r) => r.status === target.expectedStatus,
      });
      if (!ok) errorRate.add(1);
    }

  // ── 3% — Quiz Attempt Result (READ, normalized detail) ─
  } else if (action < 0.80) {
    if (QUIZ_DETAIL_ATTEMPT_TARGETS.length > 0) {
      const target = pick(QUIZ_DETAIL_ATTEMPT_TARGETS);
      const h = headersFor(target.userId);
      const res = http.get(
        `${BASE_URL}/api/quizzes/${target.quizId}/attempts/${target.attemptId}/result`,
        h
      );
      quizAttemptResultDuration.add(res.timings.duration);
      check(res, {
        '[quiz-attempt-result] status 200': (r) => r.status === 200,
      }) || errorRate.add(1);
    }

  // ── 10% — Start Quiz Attempt (WRITE, actor-valid) ─────
  } else if (action < 0.90) {
    const target = pick(WRITABLE_QUIZ_ATTEMPT_TARGETS);
    const h = headersFor(target.studentId);
    const res = http.post(
      `${BASE_URL}/api/quizzes/${target.activityId}/attempts`,
      JSON.stringify({}),
      h
    );
    startAttemptDuration.add(res.timings.duration);
    const ok = check(res, {
      '[start-attempt] status 201': (r) => r.status === target.expectedStatus,
    });
    if (!ok) errorRate.add(1);

  // ── 10% — Submit Assignment (WRITE, actor-valid) ───────
  } else {
    const target = pick(WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS);
    const h = headersFor(target.studentId);
    const res = http.post(
      `${BASE_URL}/api/assignments/${target.activityId}/submissions`,
      JSON.stringify({
        file_path: `benchmark/rh-${__VU}-${Date.now()}.pdf`,
      }),
      h
    );
    submitAssignmentDuration.add(res.timings.duration);
    const ok = check(res, {
      '[submit-assignment] status 201': (r) => r.status === target.expectedStatus,
    });
    if (!ok) errorRate.add(1);
  }

  sleep(Math.random() * 1.5 + 0.5);
}

export function handleSummary(data) {
  return {
    [__ENV.SUMMARY_EXPORT || '/tmp/k6-summary.json']: JSON.stringify(data),
  };
}
