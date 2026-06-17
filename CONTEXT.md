# LMS Benchmark Context

This context defines the domain language for the LMS benchmark application and keeps the research claim precise enough to compare against Moodle without implying Moodle equivalence.

## Language

**Moodle-Inspired Controlled LMS Benchmark**:
A controlled benchmark workload that models selected Moodle-inspired academic LMS flows such as course content reads, quiz attempts, assignment submissions, completion checks, authorization checks, and gradebook aggregation. It is not a full Moodle-equivalent platform; it includes only LMS complexity that affects cache behavior, read/write paths, invalidation, or response shape.
_Avoid_: Full Moodle clone, real Moodle replacement

**Benchmark-Relevant Moodle-Like Complexity**:
Moodle-inspired behavior is benchmark-relevant when it changes database reads or writes, authorization checks, cache keys, cache invalidation, or response shape. Features that are mostly UI, administration, marketplace/plugin management, payment, messaging, themes, backup/restore, analytics, or external integrations are outside the benchmark scope unless they directly affect the measured workload.
_Avoid_: All Moodle features, full plugin architecture

**Measured LMS Flow**:
An existing benchmarked flow in the application: course content reads, material reads/downloads, quiz attempts, assignment submissions/grading, enrolment checks, gradebook aggregation, and related cache invalidation. Complexity should deepen these flows rather than adding many unmeasured Moodle activity types.
_Avoid_: Feature count, unused module breadth

**Relationship-Valid Benchmark Traffic**:
Benchmark requests should normally follow valid LMS relationships: enrolled students access their course activities, instructors access their own course gradebooks, and activity actions respect visibility and availability. Random or invalid identifiers should only be used when the scenario intentionally measures unauthorized, missing, or invalid traffic.
_Avoid_: Mostly random ID traffic

**Course Structure**:
A course is organized into ordered sections, and each section contains ordered learning modules that wrap benchmarked activities such as materials, quizzes, and assignments. The benchmark should read course content as a user-visible content tree rather than only as separate flat activity lists.
_Avoid_: Flat course activity list

**Conditional Availability**:
Rules that decide whether a learning module is visible or actionable for a specific user. The benchmark scope includes a small fixed rule set such as date windows, required prior module completion, minimum grade, and group membership; it does not include Moodle's full availability engine.
_Avoid_: Full availability JSON engine

**Module Completion**:
User-specific progress state for a learning module. Completion may be driven by viewing a material, submitting an assignment, finishing a quiz attempt, or reaching a passing grade, and it can affect course structure reads and conditional availability for later modules.

The current implementation also supports course-level completion criteria, where module completion, grade thresholds, or date criteria can mark a user's course completion state. It remains simpler than Moodle's full completion aggregation model and does not reproduce every completion default, aggregation method, or reaggregation workflow.
_Avoid_: Global module completion

**Course Group**:
A subset of enrolled users within a course used for benchmark-relevant access restrictions, assignment or quiz overrides, and instructor grading views. Course groupings are supported as containers that collect multiple groups. The benchmark scope does not include Moodle's full group administration feature set.
_Avoid_: Full Moodle group management

**Assignment Workflow**:
The benchmark-relevant lifecycle of an assignment submission, including draft, submitted, returned, reopened, latest attempt, late status, extension overrides, and grader assignment. Full rubric or advanced grading systems are optional and should only be included when they affect measured grade write complexity.
_Avoid_: Full advanced grading UI

**Quiz Attempt Policy**:
The benchmark-relevant rules controlling quiz access, attempt lifecycle, and review visibility. This includes user or group overrides, overdue handling, grace periods, attempt state transitions, review visibility rules, and delay between attempts, but excludes Moodle's full question engine.
_Avoid_: Full Moodle question engine

**Grade Item**:
A gradebook column representing something gradeable, such as a quiz, assignment, or manual grade. User grade records belong to grade items; each grade item can define max score, pass score, weight, hidden or locked state, and source without requiring Moodle's full formula and history system.
_Avoid_: Direct-only grade rows

**File Context Metadata**:
Benchmark-relevant file information that identifies ownership and cache behavior for materials and submissions, such as owning context, component or activity type, MIME type, file size, checksum or content hash, revision, visibility, and uploader. The current implementation uses owner-based lookups (owner_type/owner_id) rather than Moodle's content-addressed storage keyed by contenthash/pathnamehash/contextid/component/filearea. This is a known simplification documented under Known Simplifications.
_Avoid_: Full Moodle file API

**Simple Enrolment Model**:
The benchmark uses a single `course_enrollments` table where each record stores user_id, course_id, role, status, starts_at, and ends_at directly. This contrasts with Moodle's separation of enrolment method instances (the `enrol` table tracks plugins like manual, self, cohort) and user enrolment records (`user_enrolments`). The single-table model is a simplification that does not reproduce the multi-method enrolment read complexity or the multiple-active-enrolment-per-user scenario.
_Avoid_: Multi-method enrolment system

**Context-Based Role Model**:
The benchmark resolves student and instructor access through Moodle-inspired contexts, roles, and role assignments, including ancestor walking for inherited roles. It remains simpler than Moodle because it does not implement the full configurable capability matrix, role override rules, role switching rules, or all context-level administration behavior.
_Avoid_: Full Moodle capability system

**Simplified Completion Aggregation**:
The benchmark tracks module completion and course completion criteria, but it does not reproduce Moodle's full completion aggregation methods, default completion settings, cron-driven reaggregation, or every administrative override. This keeps completion benchmark-relevant while avoiding a full completion subsystem clone.
_Avoid_: Full Moodle completion administration

**Varied Benchmark Dataset**:
A reproducible seed dataset with intentionally uneven course shapes, section counts, module counts, availability rules, group restrictions, completion rules, assignment policies, quiz policies, grade item weights, and user activity. The dataset should avoid uniform per-course counts when variation affects benchmark realism.
_Avoid_: Uniform fixture dataset

**Exercised Complexity**:
Benchmark-relevant complexity must be touched by benchmark traffic through reads, writes, filtering, authorization, cache keys, or cache invalidation. Schema-only features should not be used to justify realism unless at least one benchmark scenario exercises them.
_Avoid_: Cosmetic schema complexity

**Benchmark Persona**:
A role-specific traffic actor in the benchmark workload. Student personas read course structure, view or download materials, start and submit quizzes, submit assignments, and trigger completion; instructor personas read gradebooks, inspect submissions, grade or return submissions, and update grade records.
_Avoid_: Role-agnostic traffic

**Two-Workload Benchmark Shape**:
The benchmark has exactly two workload families: read-heavy and write-heavy. The read-heavy workload is 80% reads and 20% writes; the write-heavy workload is 60% writes and 40% reads. Valid, invalid, and edge-case behavior must be represented inside those two workloads when needed, not split into a separate third workload.
_Avoid_: Separate edge-case workload

**Controlled Expected Failure**:
An expected error response included deliberately inside the read-heavy or write-heavy workload, such as hidden modules, unavailable activities, exceeded attempts, late submissions, or unauthorized instructor actions. Expected failures should be a small controlled portion of traffic; the main benchmark path should remain relationship-valid and mostly successful.
_Avoid_: Random failure traffic

**Course Structure Read**:
The primary learner-facing read path that returns course sections, visible learning modules, availability state, completion state, and activity summaries for the current user. Separate activity detail reads may exist, but the course structure read is central to Moodle-inspired benchmark realism.
_Avoid_: Materials-only course read

**Cascading Invalidation Write**:
A write action that changes downstream user, course, activity, gradebook, progress, or availability state and therefore invalidates multiple cached read models. Examples include assignment submission, assignment grading or return, quiz submission, module completion update, grade item or user grade update, and activity setting override.
_Avoid_: Isolated insert-only write

**Caching Strategy Comparison**:
The research comparison target remains caching strategies. Moodle-inspired LMS complexity exists to make cache keys, hit and miss behavior, invalidation, read latency, write latency, and consistency effects more realistic; it does not change the thesis into a general LMS performance study.
_Avoid_: General LMS performance study

**Out-of-Scope Moodle Surface**:
Moodle features that should not be implemented for this benchmark unless the research question changes: full plugin architecture, additional unmeasured activity modules, SCORM, LTI, H5P, forums, chat, messaging, payment or commerce enrolment, backup and restore, analytics or AI, theme systems, full file repository API, full question engine, full rubric UI, and admin configuration screens.
_Avoid_: Moodle clone scope

**Benchmark-Relevant Authorization**:
Access control that makes benchmark traffic behave like real LMS users. Students, instructors, and administrators may have different permissions, and benchmarked reads or writes should check course enrolment, course role, group membership, module visibility, module availability, grade visibility, and ownership; the benchmark does not include Moodle's full role and capability administration.

The current implementation uses a context-based role model with inherited role checks, but intentionally stops short of Moodle's full configurable capability and role override system. This is a known simplification documented under Known Simplifications.
_Avoid_: Full Moodle permissions system

## Known Simplifications

The following design choices are documented simplifications where the benchmark differs from Moodle's architecture. Each directly affects cache behavior, read patterns, or write invalidation, and is therefore relevant to the research claim.

| Simplification | Moodle Equivalent | Cache Impact | Priority for Closing |
|---|---|---|---|
| **Partial Capability Model** — context roles and inherited checks exist, but not Moodle's full capability matrix or role override rules | Full role/capability system (`context` → `role_assignments` + `role_capabilities` + overrides) | Authorization is now on the hot path, but cache behavior is still simpler than configurable capability resolution | 🟡 Medium — important, but full capabilities may exceed benchmark scope |
| **Simplified Completion Aggregation** — module completion and course completion criteria exist, but not full completion defaults, aggregation methods, or cron-style reaggregation | Full course and activity completion subsystem | Captures completion-driven invalidation, but not every reaggregation or administrative cache path | 🟢 Low — enough unless completion becomes central to the thesis |
| **Simple Enrolment Model** — single `course_enrollments` table | Method instances (`enrol`) + user records (`user_enrolments`) with timings | Fewer enrolment reads per user; no multi-method-overlap caching | 🟢 Low — single active enrolment per course is sufficient for benchmark traffic |
| **Non-Content-Addressed File Storage** — owner-based lookup (`owner_type`/`owner_id`) | Content-addressed `files` table with `contenthash`, `pathnamehash`, `contextid`, `component`, `filearea` | Different cache key structure for file reads/deduplication; no hash-lookup caching | 🟢 Low — file download caching strategy is independent of storage addressing |

These simplifications should be evaluated against Exercised Complexity: a simplification only matters if benchmark traffic would hit the missing feature. The Partial Capability Model is the remaining simplification that touches the widest request surface.

## Navigation

- **PRD:** Not yet created — this is a benchmark workload, not a product
- **Roadmap:** Not yet created
- **ADRs:** [`docs/adr/`](./docs/adr/) — architecture decision records
- **Plans:** [`plan/`](./plan/) — detailed execution plans per slice

## Example Dialogue

Dev: Are we trying to rebuild Moodle before running cache benchmarks?

Domain expert: No. We need a controlled LMS-like workload inspired by Moodle's academic flows, with enough benchmark-relevant complexity to make the caching results defensible.

Dev: Which Moodle features count?

Domain expert: Features that affect reads, writes, authorization, cache keys, invalidation, or response shape. Course modules, availability, completion, groups, grade items, assignment workflow, quiz overrides, and file metadata are candidates. Themes, payments, backup/restore, and full plugin infrastructure are not part of this benchmark.

Dev: Should we add forums, wikis, chat, SCORM, and many other Moodle modules to make the LMS look more complex?

Domain expert: No. We should deepen the measured LMS flows instead. Extra modules only help if they are part of the benchmark workload and affect the measured caching behavior.

Dev: Can the load test keep choosing random user, course, quiz, assignment, and submission IDs?

Domain expert: Not for the main benchmark. The common path should use relationship-valid traffic, such as an enrolled student opening course content or an instructor grading a submission in their course. Invalid traffic belongs in a separate scenario if it is being measured on purpose.

Dev: Is a course just a container with separate material, quiz, and assignment lists?

Domain expert: No. A course should have ordered sections, and sections should contain ordered learning modules. The learner-facing content read should resolve that structure with visibility and availability rules.

Dev: Is module availability only a visible flag and date range?

Domain expert: No. The benchmark should include a small set of user-specific availability rules, such as prior completion, minimum grade, and group membership, because those change cache behavior. It should not attempt to reproduce Moodle's full availability system.

Dev: Does completion belong in the benchmark, or is it just a UI progress feature?

Domain expert: Completion belongs in the benchmark because it is user-specific LMS state. Completing one module can change which later modules are available and therefore changes reads, writes, cache keys, and invalidation.

Dev: Do groups matter for this benchmark?

Domain expert: Yes, but only when they affect measured behavior. Groups can restrict module access, change assignment or quiz settings, and narrow grading views. Full group administration is outside scope.

Dev: Should assignment complexity come from rubrics and advanced grading first?

Domain expert: No. The first priority is submission workflow because it directly changes write behavior and cache invalidation. Rubrics can stay simple unless they are needed to make grade writes meaningfully more realistic.

Dev: Should quiz complexity come from supporting every Moodle question type?

Domain expert: No. The benchmark should focus on attempt and access behavior first. Full question engine behavior is outside scope unless a specific rule changes benchmark reads, writes, or invalidation.

Dev: Is the gradebook just an average over stored grade rows?

Domain expert: No. The benchmark should separate grade items from user grade records. That makes gradebook reads and grade write invalidation closer to a real LMS while avoiding Moodle's full grade formula and history system.

Dev: Should the benchmark reproduce Moodle's full file storage API?

Domain expert: No. It should model metadata and ownership context that affect materials, submissions, downloads, cache keys, and invalidation. A full content-addressed file pool and repository plugins are outside scope.

Dev: Is a fixed number of quizzes, materials, and assignments per course realistic enough?

Domain expert: No. The benchmark dataset should be deterministic but varied so different courses have different shapes, rules, and activity levels.

Dev: Can we add tables and models for realism even if k6 never touches them?

Domain expert: Not as evidence for benchmark realism. Any complexity used to defend the benchmark must be exercised by at least one benchmark scenario.

Dev: Should all benchmark requests behave like the same generic user?

Domain expert: No. The benchmark should use student and instructor personas because role-specific behavior changes authorization, cache keys, response shape, and invalidation.

Dev: Should invalid or edge-case traffic become its own benchmark scenario?

Domain expert: No. The benchmark has only read-heavy and write-heavy workloads. Edge behavior can be included inside those workloads when it supports the benchmark design, but it should not become a separate workload family.

Dev: Should the two workloads mostly be valid journeys?

Domain expert: Yes. Both workloads should be mostly relationship-valid and successful, with only a small controlled amount of expected failures when those failures represent realistic LMS behavior.

Dev: What should the main learner read path be?

Domain expert: A course structure read that resolves sections, modules, visibility, availability, completion, and activity summaries for the current user. Flat material or quiz reads can remain secondary.

Dev: What kinds of writes matter most for the cache benchmark?

Domain expert: Writes that cascade into multiple read models matter most. The write-heavy workload should include actions that invalidate course structure, gradebook, user progress, and activity caches, not only simple row inserts.

Dev: Are we now benchmarking LMS performance generally?

Domain expert: No. The comparison target is still caching strategies. LMS complexity is included only when it makes cache behavior and benchmark realism stronger.

Dev: What Moodle areas should we explicitly avoid?

Domain expert: Avoid the full plugin ecosystem and unmeasured product surfaces such as SCORM, LTI, forums, messaging, payment, backup, analytics, themes, full file repositories, full question engine, full rubric UI, and admin screens.

Dev: Does authentication and user access matter for the benchmark?

Domain expert: Yes. The benchmark should enforce realistic student and instructor access rules because authorization changes cache keys, response shape, and expected failures. It should not reproduce Moodle's full configurable role and capability system.
