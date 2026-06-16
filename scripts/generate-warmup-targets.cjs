#!/usr/bin/env node
/**
 * Generate warm-up target pools from k6 fixtures.
 *
 * Reads k6 fixture module, extracts the const/SharedArray values
 * (already JSON-parseable), and writes a warmup-targets.json file.
 *
 * Usage: node scripts/generate-warmup-targets.js [output]
 *   K6_FIXTURE_FILE=path  Override fixture source (default: auto-detect)
 * Output: scripts/lib/warmup-targets.json (or first arg)
 */

const fs = require('fs');
const path = require('path');

const scriptDir = path.dirname(process.argv[1]);
const projectDir = path.resolve(scriptDir, '..');

// Determine fixture source: env var > sampled.js > fixtures.js
const k6Dir = path.join(projectDir, 'tests/Benchmark/k6');
const fixtureFile = process.env.K6_FIXTURE_FILE
  ? path.resolve(projectDir, process.env.K6_FIXTURE_FILE)
  : (fs.existsSync(path.join(k6Dir, 'fixtures.sampled.js'))
      ? path.join(k6Dir, 'fixtures.sampled.js')
      : path.join(k6Dir, 'fixtures.js'));

const outputFile = process.argv[2] || path.join(scriptDir, 'lib/warmup-targets.json');

if (!fs.existsSync(fixtureFile)) {
  console.error(`Error: fixture file not found at ${fixtureFile}`);
  console.error('Run the database seeder + k6 fixture generator first.');
  process.exit(1);
}

console.error(`Warm-up source: ${fixtureFile}`);

const content = fs.readFileSync(fixtureFile, 'utf-8');

// Extract const declarations with JSON-parseable values.
// Pattern: const NAME = [JSON array/value];
// Handles multi-line arrays.
const targetNames = [
  'ENROLLED_PAIRS',
  'INSTRUCTOR_COURSE_PAIRS',
  'READABLE_MATERIAL_TARGETS',
  'READABLE_QUIZ_TARGETS',
  'READABLE_ASSIGNMENT_TARGETS',
  'COURSE_COMPLETION_CHECK_TARGETS',
  'WRITABLE_MATERIAL_DOWNLOAD_TARGETS',
  'WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS',
  'WRITABLE_QUIZ_ATTEMPT_TARGETS',
  'GRADING_TARGETS',
  'GRADE_UPDATE_TARGETS',
  'INSTRUCTOR_IDS',
  'STUDENT_IDS',
  'COURSE_IDS',
  'ACTIVE_COURSE_IDS',
];

const result = {};

for (const name of targetNames) {
  // Build regex: find `const NAME =` and capture until `;` that ends the declaration
  // Handles both plain `const NAME = [...]` and `const NAME = new SharedArray('NAME', () => [...])
  const regex = new RegExp(`const\\s+${name}\\s*=\\s*([\\s\\S]*?);\\s*$`, 'm');
  const match = content.match(regex);

  if (!match) {
    console.warn(`Warning: ${name} not found in ${fixtureFile}`);
    result[name] = [];
    continue;
  }

  let valueStr = match[1].trim();

  // Strip SharedArray wrapper: `new SharedArray('NAME', () => [...]` → `[...]`
  // Must handle trailing `)` from the constructor call
  valueStr = valueStr.replace(/^new\s+SharedArray\(\s*'[^']+'\s*,\s*\(\)\s*=>\s*/, '');
  // Remove SharedArray closing paren if present (trailing ) after array)
  valueStr = valueStr.replace(/\)\s*$/, '');

  // Remove trailing semicolons and whitespace
  valueStr = valueStr.replace(/;+\s*$/, '').trim();

  try {
    result[name] = JSON.parse(valueStr);
  } catch (err) {
    // If direct parse fails, try to clean up (remove trailing commas, etc.)
    try {
      // Remove trailing commas before closing brackets
      const cleaned = valueStr
        .replace(/,\s*\]/g, ']')
        .replace(/,\s*\}/g, '}')
        .replace(/\/\/.*$/gm, ''); // remove inline comments
      result[name] = JSON.parse(cleaned);
      console.warn(`Warning: ${name} needed trailing-comma cleanup`);
    } catch (err2) {
      console.error(`Error: Failed to parse ${name}: ${err2.message}`);
      console.error(`First 200 chars: ${valueStr.substring(0, 200)}`);
      result[name] = [];
    }
  }
}

// Add metadata
result._generatedAt = new Date().toISOString();
result._sourceFile = fixtureFile;
result._targetCounts = {};
for (const name of targetNames) {
  result._targetCounts[name] = result[name] ? result[name].length : 0;
}

// Validate required non-empty arrays
const requiredNonEmpty = [
  'ENROLLED_PAIRS',
  'INSTRUCTOR_COURSE_PAIRS',
  'READABLE_MATERIAL_TARGETS',
  'READABLE_QUIZ_TARGETS',
  'READABLE_ASSIGNMENT_TARGETS',
  'COURSE_COMPLETION_CHECK_TARGETS',
];
let hasError = false;
for (const name of requiredNonEmpty) {
  if (!result[name] || result[name].length === 0) {
    console.error(`Error: ${name} is empty — warm-up targets would be incomplete.`);
    hasError = true;
  }
}
if (hasError) {
  process.exit(1);
}

// Ensure output directory exists
const outputDir = path.dirname(outputFile);
if (!fs.existsSync(outputDir)) {
  fs.mkdirSync(outputDir, { recursive: true });
}

// Write through temp file to avoid partial output
const tmpFile = outputFile + '.tmp';
try {
  fs.writeFileSync(tmpFile, JSON.stringify(result, null, 2), 'utf-8');
  fs.renameSync(tmpFile, outputFile);
} catch (err) {
  console.error(`Error writing target file: ${err.message}`);
  try { fs.unlinkSync(tmpFile); } catch (_) {}
  process.exit(1);
}

console.error(`Warm-up targets written to ${outputFile}`);
console.error(`  _generatedAt: ${result._generatedAt}`);
for (const name of requiredNonEmpty) {
  console.error(`  ${name}: ${result._targetCounts[name]}`);
}
