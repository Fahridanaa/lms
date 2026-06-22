<?php

$diagram = static function (string $title, array $steps): string {
    $escape = static fn (string $value): string => str_replace('"', '\\"', $value);
    $lines = [
        'flowchart TD',
        '    T["'.$escape($title).'"]',
        '    T:::title',
    ];

    $previous = 'T';

    foreach ($steps as $index => $step) {
        $node = 'S'.($index + 1);
        $lines[] = '    '.$node.'["'.$escape($step).'"]';
        $lines[] = '    '.$previous.' --> '.$node;
        $previous = $node;
    }

    $lines[] = '    classDef title fill:#0b1b43,color:#fff,stroke:#0b1b43';
    $lines[] = '    classDef default fill:#fffaf0,stroke:#0b1b43,color:#0b1b43';

    return implode("\n", $lines);
};

$flow = static function (string $scenario, string $weight, string $endpoint, array $moodleSteps, array $lmsSteps) use ($diagram): array {
    return [
        'scenario' => $scenario,
        'weight' => $weight,
        'endpoint' => $endpoint,
        'moodle_diagram' => $diagram('Moodle: '.$endpoint, $moodleSteps),
        'lms_diagram' => $diagram('LMS Purwarupa: '.$endpoint, $lmsSteps),
    ];
};

return [
    $flow('Beban baca', '25%', 'GET /api/courses/{courseId}/structure', [
        'Request masuk ke course/view.php atau core_course_external::get_course_contents',
        'require_login menentukan user, course, context, dan enrolment',
        'get_fast_modinfo memuat course_sections dan course_modules',
        'availability_info mengevaluasi hidden, tanggal, grup, dan restriction',
        'completion_info menambahkan status completion user',
        'callback plugin module menambahkan ringkasan resource, quiz, dan assign',
        'Lapisan theme/block memformat halaman course atau response external',
    ], [
        'CourseStructureController menerima course id dan actor header',
        'CourseAccessService memeriksa role, enrolment, dan status suspended',
        'CourseStructureService memeriksa cache key struktur course',
        'CourseCacheLoader eager load section dan learning module',
        'Batch load availability, completion, grade, group, dan override',
        'Lampirkan hanya ringkasan material, quiz, dan assignment',
        'Kembalikan JSON struktur ringkas yang dipakai benchmark',
    ]),
    $flow('Beban baca', '10%', 'GET /api/materials/{id}', [
        'view resource module atau external fetch menerima cm id',
        'require_login memuat context course module',
        'Capability check memverifikasi mod/resource:view',
        'availability_info memblokir akses hidden/date/group restricted',
        'files API mengambil intro resource dan metadata file',
        'completion_info bisa menandai viewed sesuai konfigurasi',
        'Renderer mengembalikan halaman resource atau payload link file',
    ], [
        'MaterialController menentukan material id dan actor',
        'MaterialService memeriksa cache store material',
        'Muat material bersama course dan learning module',
        'CourseAccessService memvalidasi akses baca course',
        'ModuleAvailabilityService memvalidasi aturan hidden/date/group',
        'Kembalikan metadata material dan field konten',
    ]),
    $flow('Beban baca', '5%', 'GET /api/quizzes/{id}', [
        'quiz view memuat instance quiz dan course module',
        'require_login menentukan context dan enrolment',
        'quiz access manager memeriksa open time, close time, dan attempts',
        'question engine memuat slot, referensi question, dan layout',
        'availability dan capability check menentukan aksi yang terlihat',
        'Renderer mengembalikan ringkasan quiz, tombol attempt, dan ringkasan nilai',
    ], [
        'QuizController menentukan quiz id dan actor',
        'QuizService memeriksa cache detail quiz',
        'QuizRepository memuat quiz, course, module, dan question slot',
        'CourseAccessService memvalidasi enrolment dan role',
        'ModuleAvailabilityService memeriksa availability quiz dan override',
        'Kembalikan pengaturan quiz dan ringkasan question untuk benchmark',
    ]),
    $flow('Beban baca', '8%', 'GET /api/assignments/{id}', [
        'assign view memuat instance assign dan course module',
        'require_login menentukan context dan enrolment',
        'class assign memuat submission, grade, dan user flags',
        'availability dan mod/assign:view check dijalankan',
        'status plugin submission dan feedback disiapkan',
        'Renderer mengembalikan intro assignment, due date, dan tabel status',
    ], [
        'AssignmentController menentukan assignment id dan actor',
        'AssignmentService memeriksa cache assignment',
        'AssignmentRepository memuat course, module, dan submission jika dibutuhkan',
        'CourseAccessService memvalidasi akses actor',
        'ModuleAvailabilityService menerapkan aturan hidden/date/group',
        'Kembalikan detail assignment dan ringkasan status submission',
    ]),
    $flow('Beban baca', '12%', 'GET /api/courses/{id}/gradebook', [
        'Entry grade report menentukan context course',
        'Capability check memeriksa grade:viewall atau permission report',
        'grade_tree memuat grade_categories dan grade_items',
        'grade_grades dimuat untuk user yang enrolled',
        'Status hidden, locked, dan overridden diterapkan',
        'Plugin report merender tabel grader/user gradebook',
    ], [
        'GradebookController menentukan course id dan actor',
        'GradebookService canReadGradebook memeriksa instructor/capability',
        'Tagged cache course:{id}:gradebook:instructor diperiksa',
        'GradeItem, Grade, GradeCategory, dan enrolled student di-query',
        'Weighted average dan category tree dibangun',
        'Kembalikan JSON gradebook course yang ringkas',
    ]),
    $flow('Beban baca', '5%', 'GET /api/users/{id}/grades', [
        'User grade report menentukan target user dan viewer',
        'Capability check memeriksa nilai sendiri atau permission grader',
        'grade_items dan grade_grades dimuat lintas course',
        'Visibility nilai hidden dan locked diterapkan',
        'Plugin report memformat baris nilai per course',
    ], [
        'GradebookController menentukan user id dan actor',
        'GradebookService canReadUserGrades memeriksa owner atau instructor',
        'Cache student atau cache berscope instructor diperiksa',
        'GradeRepository memuat grade user dan grade item',
        'Grade item hidden difilter untuk self-view student',
        'Kembalikan daftar grade dan average',
    ]),
    $flow('Beban baca', '3%', 'GET /api/courses/{id}/materials', [
        'Halaman course memuat fast modinfo untuk semua module',
        'Daftar module difilter ke instance resource',
        'Availability dan capability check berjalan per resource',
        'Metadata file diambil untuk resource yang terlihat',
        'Renderer atau external API mengembalikan daftar material',
    ], [
        'MaterialController atau CourseStructureService menentukan material course',
        'CourseAccessService memeriksa akses baca course',
        'Query cache/list material hanya memuat module material',
        'ModuleAvailabilityService memfilter material yang unavailable',
        'Kembalikan ringkasan material yang terlihat',
    ]),
    $flow('Beban baca', '2%', 'GET /api/courses/{id}/completion', [
        'completion_info memuat pengaturan course completion',
        'Record criteria dan status module completion dibaca',
        'Enrolment user dan status role divalidasi',
        'Aggregation memeriksa criteria activity, grade, dan date',
        'Status course completion dikembalikan atau dihitung ulang oleh cron',
    ], [
        'CourseCompletionController menentukan course dan actor',
        'CourseCompletionService memeriksa cache course completion',
        'Muat criteria, criterion completion, dan module completion',
        'CourseAccessService memvalidasi visibility course',
        'Kembalikan persentase completion dan status criterion',
    ]),
    $flow('Beban baca', '7%', 'Kegagalan terkontrol: restricted/hidden/unavailable', [
        'Request masuk ke target module atau halaman course',
        'require_login bisa lolos untuk user enrolled',
        'availability_info mengevaluasi restriction tree',
        'Capability dan group membership check dijalankan',
        'Moodle melempar exception unavailable atau permission',
        'Error renderer/external API mengembalikan 403/404 terkontrol',
    ], [
        'Controller menentukan actor dan target resource',
        'CourseAccessService memeriksa suspended/enrolment/role',
        'ModuleAvailabilityService memeriksa hidden/date/group/prerequisite/min grade',
        'BusinessException dilempar untuk akses ditolak',
        'API mengembalikan JSON failure terkontrol',
    ]),
    $flow('Beban baca', '3%', 'GET /api/quizzes/{id}/attempts/{id}/result', [
        'Halaman review quiz menentukan attempt dan context quiz',
        'Ownership atau capability review diperiksa',
        'Question engine memuat ulang attempt step dan response',
        'Opsi review quiz menentukan mark/answer yang terlihat',
        'Grade dan feedback dihitung atau dimuat',
        'Renderer mengembalikan review attempt',
    ], [
        'QuizController attemptResult menentukan quiz, attempt, dan actor',
        'QuizService memvalidasi ownership atau akses instructor',
        'QuizAttemptRepository memuat attempt question, step, dan grade',
        'QuizScoringService mengembalikan score dan hasil per question',
        'Kembalikan JSON hasil yang ringkas',
    ]),
    $flow('Beban baca', '10%', 'POST /api/quizzes/{id}/attempts', [
        'external start_attempt memvalidasi quiz dan user',
        'require_login dan quiz access manager check dijalankan',
        'Attempt limit, open time, password, dan override diperiksa',
        'Question engine membuat usage dan slot',
        'quiz_attempts dan question_attempts di-insert',
        'Event dan gradebook hook disiapkan',
    ], [
        'QuizController startAttempt memvalidasi request',
        'QuizService memeriksa akses actor dan availability quiz',
        'QuizOverride dan rule attempt limit diterapkan',
        'QuizAttemptRepository membuat row attempt dan question',
        'Cache tag untuk quiz/user attempt diinvalidasi',
        'Kembalikan attempt id dan payload question',
    ]),
    $flow('Beban baca', '10%', 'POST /api/assignments/{id}/submissions', [
        'assign external save_submission menentukan assignment',
        'require_login dan mod/assign:submit check dijalankan',
        'Due date, cutoff, dan rule group/team diperiksa',
        'Plugin submission menyimpan teks/file',
        'Row assign_submission dan tabel plugin di-update',
        'Event gradebook dan completion bisa terpicu',
    ], [
        'AssignmentController submit memvalidasi request',
        'AssignmentService memeriksa availability course dan assignment',
        'SubmissionRepository membuat atau mengupdate submission',
        'ModuleCompletionService menandai progress assignment jika perlu',
        'Cache tag assignment dan gradebook diinvalidasi',
        'Kembalikan JSON submission',
    ]),
    $flow('Beban tulis', '10%', 'GET /api/courses/{courseId}/structure', [
        'Pembacaan struktur course Moodle setelah write terbaru',
        'get_fast_modinfo bisa memakai ulang cache sampai invalidated',
        'Status availability dan completion bersifat user-specific',
        'Callback module menambahkan status activity terbaru',
        'Response mencerminkan rebuild cache Moodle jika write membuatnya dirty',
    ], [
        'CourseStructureController membaca setelah workload write',
        'CourseStructureService memeriksa cache struktur',
        'Course tag yang invalidated memaksa reload jika perlu',
        'Batch loader membangun ulang module, completion, grade, dan availability',
        'Kembalikan JSON struktur setelah write',
    ]),
    $flow('Beban tulis', '10%', 'GET /api/courses/{courseId}/gradebook', [
        'Grade report memuat grade tree setelah submission/attempt',
        'Recalculation gradebook bisa mengupdate grade item stale',
        'grade_grades dan total category dibaca',
        'Rule hidden/locked diterapkan',
        'Plugin report merender gradebook terbaru',
    ], [
        'GradebookController membaca setelah write cascade',
        'GradebookService melihat stale marker atau cache miss',
        'GradebookRecalculationService menghitung ulang jika perlu',
        'Reload grade, grade item, category, dan student',
        'Kembalikan JSON gradebook terbaru',
    ]),
    $flow('Beban tulis', '5%', 'GET /api/assignments/{id} atau material/quiz', [
        'Moodle memuat activity module yang dipilih',
        'require_login, context, capability, dan availability berjalan',
        'Plugin activity-specific memuat status terbaru',
        'Status completion atau submission dilampirkan',
        'Renderer mengembalikan view activity terbaru',
    ], [
        'Controller terpilih menentukan activity dan actor',
        'Service memeriksa cache activity-specific',
        'CourseAccessService dan ModuleAvailabilityService memvalidasi akses',
        'Repository memuat status submission/attempt/material terbaru',
        'Kembalikan JSON activity',
    ]),
    $flow('Beban tulis', '5%', 'GET /api/users/{id}/grades atau performance', [
        'User report menentukan viewer dan target user',
        'Gradebook API memuat grade setelah write',
        'Rule visibility dan locked diterapkan',
        'Data ringkasan report/performance dihitung',
        'Response mengembalikan status grade terbaru',
    ], [
        'GradebookController menentukan endpoint grade atau performance',
        'GradebookService memeriksa cache user grade yang actor-aware',
        'User grade tag yang invalidated memaksa reload',
        'Weighted average dan count dihitung ulang',
        'Kembalikan JSON grade atau ringkasan performance',
    ]),
    $flow('Beban tulis', '5%', 'GET course structure lagi setelah write cascade', [
        'Halaman course reload fast modinfo dan status activity',
        'Cache completion bisa dibangun ulang untuk user',
        'Availability check bisa berubah setelah write grade/completion',
        'Callback module menampilkan status terbaru',
        'Response menunjukkan efek cascade dari write sebelumnya',
    ], [
        'CourseStructureController mengulang pembacaan struktur',
        'CourseStructureService mengalami cache miss setelah invalidation',
        'Batch loader reload completion, grade, dan availability',
        'Status activity yang berubah dilampirkan ke module',
        'Kembalikan JSON struktur yang dibangun ulang',
    ]),
    $flow('Beban tulis', '5%', 'Kegagalan terkontrol: restricted/suspended/dll', [
        'Request Moodle masuk ke entry course atau module',
        'require_login memeriksa enrolment dan status suspended',
        'Context capability dan availability check dijalankan',
        'Restriction failure menghasilkan exception terkontrol',
        'External API memetakan exception ke failure response',
    ], [
        'Controller menentukan actor dan target route',
        'CourseAccessService menolak suspended atau enrolment yang hilang',
        'ModuleAvailabilityService menolak module hidden/restricted',
        'BusinessException dipetakan ke JSON 403 atau 404',
        'Benchmark mencatat controlled failure yang diharapkan',
    ]),
    $flow('Beban tulis', '20%', 'POST /api/assignments/{id}/submissions', [
        'assign save_submission memvalidasi user dan assignment',
        'Submission plugin manager memvalidasi payload',
        'Data submission file/text disimpan',
        'Status dan timestamp assign_submission di-update',
        'Event completion dan gradebook dipancarkan',
        'Cache diinvalidasi melalui event Moodle',
    ], [
        'AssignmentController submit memvalidasi request',
        'AssignmentService memeriksa permission submit dan due rule',
        'SubmissionRepository meng-upsert row submission',
        'ModuleCompletionService mengupdate status completion',
        'Marker recalculation gradebook diset jika perlu',
        'Cache tag assignment, user grade, dan course di-flush',
    ]),
    $flow('Beban tulis', '15%', 'POST quiz attempt -> PUT submit answers', [
        'start_attempt membuat quiz usage dan question attempt',
        'Question engine menyajikan slot ke user',
        'process_attempt memvalidasi jawaban yang dikirim',
        'Question engine menilai step dan status attempt',
        'quiz_attempts, question_attempt_steps, dan gradebook di-update',
        'Review/result tersedia sesuai pengaturan quiz',
    ], [
        'QuizController startAttempt membuat row attempt',
        'QuizService mengembalikan question yang dipilih',
        'QuizController submitAttempt memvalidasi payload jawaban',
        'QuizScoringService menilai jawaban dan menyimpan step',
        'Row QuizGrade dan Grade di-update',
        'Cache quiz, attempt, gradebook, dan user grade diinvalidasi',
    ]),
    $flow('Beban tulis', '5%', 'GET /api/materials/{id}/download (completion)', [
        'pluginfile/file.php menentukan file dan context',
        'require_login dan mod/resource:view check dijalankan',
        'File storage menentukan contenthash dan path',
        'Completion API menandai resource viewed jika dikonfigurasi',
        'File response melakukan stream konten',
    ], [
        'MaterialController download menentukan material dan actor',
        'MaterialService memvalidasi akses dan availability',
        'Metadata material atau path file dikembalikan',
        'ModuleCompletionService menandai material viewed',
        'Cache tag completion dan course structure diinvalidasi',
    ]),
    $flow('Beban tulis', '5%', 'PUT /api/submissions/{id}/grade', [
        'Tabel grading assign membuka submission',
        'Capability mod/assign:grade diperiksa',
        'Form grade memvalidasi grade dan feedback',
        'Row assign_grade ditulis',
        'grade_update mengirim nilai ke gradebook',
        'Event memicu completion dan cache invalidation',
    ], [
        'AssignmentController gradeSubmission memvalidasi request',
        'AssignmentService memeriksa permission grader',
        'SubmissionRepository menulis field grade',
        'GradebookService membuat atau mengupdate row Grade',
        'Marker dan tag recalculation gradebook diinvalidasi',
        'Kembalikan JSON submission yang sudah dinilai',
    ]),
    $flow('Beban tulis', '5%', 'PUT /api/submissions/{id}/marker-grade', [
        'Workflow marker assign menentukan allocated marker',
        'Capability marker dan status workflow diperiksa',
        'Grade marker atau draft feedback disimpan',
        'Workflow bisa memindahkan marking state',
        'Update gradebook final menunggu release policy',
    ], [
        'AssignmentController markerGrade memvalidasi request',
        'AssignmentService memeriksa permission allocated marker',
        'Row AssignmentMark dibuat atau di-update',
        'Status submission mencatat marker grade',
        'Cache assignment dan tag marker/user diinvalidasi',
        'Kembalikan JSON marker grade',
    ]),
    $flow('Beban tulis', '10%', 'PUT /api/grades/{id}', [
        'Form edit gradebook menentukan grade item dan grade grade',
        'Capability grade:edit diperiksa',
        'Nilai grade, feedback, dan flag overridden divalidasi',
        'grade_grade di-update dan history ditulis',
        'Aggregation menghitung ulang total category/course',
        'Event menginvalidasi report dan cache',
    ], [
        'GradebookController update memvalidasi request',
        'GradebookService memeriksa actor bisa update grade',
        'GradeRepository mengupdate Grade dan GradeHistory',
        'GradebookRecalculationService menandai course stale',
        'Cache tag course gradebook dan user grade di-flush',
        'Kembalikan JSON grade terbaru',
    ]),
];
