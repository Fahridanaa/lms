#!/usr/bin/env node
/**
 * Quick JSON field extractor — replaces jq for warm-up target reads.
 *
 * Usage:
 *   node scripts/lib/json-get.js <file> <path>
 *
 * Path syntax (simple dot/bracket access + .length):
 *   ENROLLED_PAIRS                       → full array
 *   ENROLLED_PAIRS.length                → array length
 *   ENROLLED_PAIRS[0]                    → first element
 *   ENROLLED_PAIRS[0].studentId          → field of element
 *   ENROLLED_PAIRS | length              → array length (jq compat)
 *
 * Filter queries unsupported — use: node -e "..." for complex cases.
 */

const fs = require('fs');
const path = require('path');

const file = process.argv[2];
const expr = process.argv[3];

if (!file || !expr) {
  console.error('Usage: node json-get.js <file> <path>');
  process.exit(1);
}

let data;
try {
  const raw = fs.readFileSync(file, 'utf-8');
  data = JSON.parse(raw);
} catch (err) {
  console.error('Error reading JSON:', err.message);
  process.exit(1);
}

// Resolve dot/bracket path like "ENROLLED_PAIRS[0].studentId"
function resolve(obj, expression) {
  // Remove jq-style piping: "ENROLLED_PAIRS | length" → "ENROLLED_PAIRS.length"
  expression = expression.replace(/\s*\|\s*/g, '.');

  // Handle array bracket notation
  expression = expression.replace(/\[(\d+)\]/g, '.[$1]');

  const parts = expression.split('.');
  let current = obj;

  for (const part of parts) {
    if (part === '') continue;
    if (current === null || current === undefined) {
      return undefined;
    }
    if (part.startsWith('[') && part.endsWith(']')) {
      const idx = parseInt(part.slice(1, -1), 10);
      current = current[idx];
    } else {
      current = current[part];
    }
  }

  return current;
}

try {
  const value = resolve(data, expr);
  if (value === undefined) {
    process.exit(0);
  }
  if (typeof value === 'string') {
    console.log(value);
  } else if (typeof value === 'number' || typeof value === 'boolean') {
    console.log(String(value));
  } else if (Array.isArray(value) || typeof value === 'object') {
    console.log(JSON.stringify(value));
  } else {
    console.log(String(value));
  }
} catch (err) {
  console.error('Error resolving path:', err.message);
  process.exit(1);
}
