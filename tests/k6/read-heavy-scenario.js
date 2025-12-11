import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';

// Custom metrics
const errorRate = new Rate('errors');

// Test configuration
export const options = {
  stages: [
    { duration: '1m', target: 100 },   // Ramp up to 100 users over 1 minute
    { duration: '3m', target: 100 },   // Stay at 100 users for 3 minutes
    { duration: '1m', target: 250 },   // Ramp up to 250 users
    { duration: '3m', target: 250 },   // Stay at 250 users for 3 minutes
    { duration: '1m', target: 500 },   // Ramp up to 500 users
    { duration: '3m', target: 500 },   // Stay at 500 users for 3 minutes
    { duration: '2m', target: 0 },     // Ramp down to 0 users
  ],
  thresholds: {
    http_req_duration: ['p(95)<500'], // 95% of requests should be below 500ms
    errors: ['rate<0.1'],              // Error rate should be less than 10%
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost';

// Helper function to get random ID within range
function randomInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

// Helper function to get random user ID (1-5000)
function getRandomUserId() {
  return randomInt(1, 5000);
}

// Helper function to get random course ID (1-50)
function getRandomCourseId() {
  return randomInt(1, 50);
}

// Helper function to get random quiz ID (1-250)
function getRandomQuizId() {
  return randomInt(1, 250);
}

// Helper function to get random material ID (1-500)
function getRandomMaterialId() {
  return randomInt(1, 500);
}

// Helper function to get random assignment ID (1-250)
function getRandomAssignmentId() {
  return randomInt(1, 250);
}

export default function () {
  const requests = [];

  // Simulate Read-Heavy workload (80% Read, 20% Write)
  const action = Math.random();

  if (action < 0.40) {
    // 40% - Get quiz with questions (heavy read operation)
    const quizId = getRandomQuizId();
    const res = http.get(`${BASE_URL}/api/quizzes/${quizId}`);

    check(res, {
      'quiz detail status is 200': (r) => r.status === 200,
      'quiz detail has data': (r) => {
        const body = JSON.parse(r.body);
        return body.success === true && body.data !== null;
      },
    }) || errorRate.add(1);

  } else if (action < 0.65) {
    // 25% - Get materials for a course
    const courseId = getRandomCourseId();
    const res = http.get(`${BASE_URL}/api/courses/${courseId}/materials`);

    check(res, {
      'materials status is 200': (r) => r.status === 200,
      'materials has data': (r) => {
        const body = JSON.parse(r.body);
        return body.success === true;
      },
    }) || errorRate.add(1);

  } else if (action < 0.80) {
    // 15% - Get course gradebook (aggregated data)
    const courseId = getRandomCourseId();
    const res = http.get(`${BASE_URL}/api/courses/${courseId}/gradebook`);

    check(res, {
      'gradebook status is 200': (r) => r.status === 200,
      'gradebook has data': (r) => {
        const body = JSON.parse(r.body);
        return body.success === true;
      },
    }) || errorRate.add(1);

  } else if (action < 0.90) {
    // 10% - Start quiz attempt (write operation)
    const quizId = getRandomQuizId();
    const userId = getRandomUserId();

    const payload = JSON.stringify({
      user_id: userId,
    });

    const params = {
      headers: {
        'Content-Type': 'application/json',
      },
    };

    const res = http.post(`${BASE_URL}/api/quizzes/${quizId}/attempts`, payload, params);

    check(res, {
      'start attempt status is 201': (r) => r.status === 201,
      'start attempt success': (r) => {
        const body = JSON.parse(r.body);
        return body.success === true;
      },
    }) || errorRate.add(1);

  } else {
    // 10% - Submit assignment (write operation)
    const assignmentId = getRandomAssignmentId();
    const userId = getRandomUserId();

    const payload = JSON.stringify({
      user_id: userId,
      file_path: `submissions/test-${Date.now()}.pdf`,
    });

    const params = {
      headers: {
        'Content-Type': 'application/json',
      },
    };

    const res = http.post(`${BASE_URL}/api/assignments/${assignmentId}/submissions`, payload, params);

    check(res, {
      'submit assignment status is 201': (r) => r.status === 201,
      'submit assignment success': (r) => {
        const body = JSON.parse(r.body);
        return body.success === true;
      },
    }) || errorRate.add(1);
  }

  // Think time between requests (0.5-2 seconds)
  sleep(Math.random() * 1.5 + 0.5);
}
