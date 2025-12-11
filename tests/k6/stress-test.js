import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';

// Custom metrics
const errorRate = new Rate('errors');

// Stress test configuration - Push system to its limits
export const options = {
  stages: [
    { duration: '2m', target: 100 },   // Warm up
    { duration: '2m', target: 500 },   // Ramp up to moderate load
    { duration: '2m', target: 1000 },  // Approach expected limit
    { duration: '3m', target: 1000 },  // Stay at high load
    { duration: '2m', target: 1500 },  // Push beyond limit
    { duration: '3m', target: 1500 },  // Stay at stress level
    { duration: '2m', target: 2000 },  // Maximum stress
    { duration: '2m', target: 2000 },  // Hold maximum
    { duration: '3m', target: 0 },     // Recovery
  ],
  thresholds: {
    http_req_duration: ['p(95)<2000'], // Allow higher latency under stress
    errors: ['rate<0.2'],               // Allow higher error rate under stress
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost';

function randomInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function getRandomUserId() {
  return randomInt(1, 5000);
}

function getRandomCourseId() {
  return randomInt(1, 50);
}

function getRandomQuizId() {
  return randomInt(1, 250);
}

function getRandomMaterialId() {
  return randomInt(1, 500);
}

function getRandomAssignmentId() {
  return randomInt(1, 250);
}

export default function () {
  // Mixed workload for stress testing (50% read, 50% write)
  const action = Math.random();

  const params = {
    headers: {
      'Content-Type': 'application/json',
    },
  };

  if (action < 0.25) {
    // 25% - Get quiz with questions
    const quizId = getRandomQuizId();
    const res = http.get(`${BASE_URL}/api/quizzes/${quizId}`);

    check(res, {
      'quiz status ok': (r) => r.status === 200,
    }) || errorRate.add(1);

  } else if (action < 0.40) {
    // 15% - Get course materials
    const courseId = getRandomCourseId();
    const res = http.get(`${BASE_URL}/api/courses/${courseId}/materials`);

    check(res, {
      'materials status ok': (r) => r.status === 200,
    }) || errorRate.add(1);

  } else if (action < 0.50) {
    // 10% - Get user grades
    const userId = getRandomUserId();
    const res = http.get(`${BASE_URL}/api/users/${userId}/grades`);

    check(res, {
      'grades status ok': (r) => r.status === 200,
    }) || errorRate.add(1);

  } else if (action < 0.75) {
    // 25% - Submit assignment
    const assignmentId = getRandomAssignmentId();
    const userId = getRandomUserId();

    const payload = JSON.stringify({
      user_id: userId,
      file_path: `stress-test-${Date.now()}.pdf`,
    });

    const res = http.post(`${BASE_URL}/api/assignments/${assignmentId}/submissions`, payload, params);

    check(res, {
      'submission status ok': (r) => r.status === 201,
    }) || errorRate.add(1);

  } else if (action < 0.90) {
    // 15% - Start quiz attempt
    const quizId = getRandomQuizId();
    const userId = getRandomUserId();

    const payload = JSON.stringify({
      user_id: userId,
    });

    const res = http.post(`${BASE_URL}/api/quizzes/${quizId}/attempts`, payload, params);

    check(res, {
      'attempt status ok': (r) => r.status === 201,
    }) || errorRate.add(1);

  } else {
    // 10% - Get course statistics (expensive operation)
    const courseId = getRandomCourseId();
    const res = http.get(`${BASE_URL}/api/courses/${courseId}/statistics`);

    check(res, {
      'statistics status ok': (r) => r.status === 200,
    }) || errorRate.add(1);
  }

  // Shorter think time for stress test (0.1-0.5 seconds)
  sleep(Math.random() * 0.4 + 0.1);
}
