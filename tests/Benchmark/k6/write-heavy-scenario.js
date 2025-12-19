import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';

// Custom metrics
const errorRate = new Rate('errors');

// Test configuration for Write-Heavy scenario
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
    http_req_duration: ['p(95)<1000'], // 95% of requests should be below 1000ms (higher for writes)
    errors: ['rate<0.1'],               // Error rate should be less than 10%
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

// Helper function to get random assignment ID (1-250)
function getRandomAssignmentId() {
  return randomInt(1, 250);
}

// Helper function to get random submission ID (1-12500)
function getRandomSubmissionId() {
  return randomInt(1, 12500);
}

// Helper function to generate random answers
function generateRandomAnswers() {
  const answers = {};
  // Generate 10-20 random question answers
  const numQuestions = randomInt(10, 20);
  for (let i = 1; i <= numQuestions; i++) {
    const questionId = randomInt(1, 5000);
    answers[questionId] = ['A', 'B', 'C', 'D'][randomInt(0, 3)];
  }
  return answers;
}

export default function () {
  // Simulate Write-Heavy workload (40% Read, 60% Write)
  const action = Math.random();

  const params = {
    headers: {
      'Content-Type': 'application/json',
    },
  };

  if (action < 0.20) {
    // 20% - Get assignments for a course (read)
    const courseId = getRandomCourseId();
    const res = http.get(`${BASE_URL}/api/courses/${courseId}/assignments`);

    check(res, {
      'assignments status is 200': (r) => r.status === 200,
      'assignments has data': (r) => {
        const body = JSON.parse(r.body);
        return body.success === true;
      },
    }) || errorRate.add(1);

  } else if (action < 0.40) {
    // 20% - Get course gradebook (read)
    const courseId = getRandomCourseId();
    const res = http.get(`${BASE_URL}/api/courses/${courseId}/gradebook`);

    check(res, {
      'gradebook status is 200': (r) => r.status === 200,
      'gradebook has data': (r) => {
        const body = JSON.parse(r.body);
        return body.success === true;
      },
    }) || errorRate.add(1);

  } else if (action < 0.75) {
    // 35% - Submit assignment (write)
    const assignmentId = getRandomAssignmentId();
    const userId = getRandomUserId();

    const payload = JSON.stringify({
      user_id: userId,
      file_path: `submissions/load-test-${Date.now()}-${randomInt(1000, 9999)}.pdf`,
    });

    const res = http.post(`${BASE_URL}/api/assignments/${assignmentId}/submissions`, payload, params);

    check(res, {
      'submit assignment status is 201': (r) => r.status === 201,
      'submit assignment success': (r) => {
        const body = JSON.parse(r.body);
        return body.success === true;
      },
    }) || errorRate.add(1);

  } else if (action < 0.90) {
    // 15% - Grade a submission (write)
    const submissionId = getRandomSubmissionId();

    const payload = JSON.stringify({
      score: randomInt(50, 100),
      feedback: 'Good work! Load test feedback.',
    });

    const res = http.put(`${BASE_URL}/api/submissions/${submissionId}/grade`, payload, params);

    check(res, {
      'grade submission status is 200': (r) => r.status === 200,
      'grade submission success': (r) => {
        const body = JSON.parse(r.body);
        return body.success === true;
      },
    }) || errorRate.add(1);

  } else {
    // 10% - Start and submit quiz attempt (write)
    const quizId = getRandomQuizId();
    const userId = getRandomUserId();

    // Start attempt
    const startPayload = JSON.stringify({
      user_id: userId,
    });

    const startRes = http.post(`${BASE_URL}/api/quizzes/${quizId}/attempts`, startPayload, params);

    if (check(startRes, {
      'start attempt status is 201': (r) => r.status === 201,
    })) {
      const startBody = JSON.parse(startRes.body);
      const attemptId = startBody.data.id;

      // Submit attempt
      const submitPayload = JSON.stringify({
        answers: generateRandomAnswers(),
      });

      const submitRes = http.put(`${BASE_URL}/api/quizzes/${quizId}/attempts/${attemptId}`, submitPayload, params);

      check(submitRes, {
        'submit attempt status is 200': (r) => r.status === 200,
        'submit attempt success': (r) => {
          const body = JSON.parse(r.body);
          return body.success === true;
        },
      }) || errorRate.add(1);
    } else {
      errorRate.add(1);
    }
  }

  // Think time between requests (0.3-1.5 seconds)
  sleep(Math.random() * 1.2 + 0.3);
}
