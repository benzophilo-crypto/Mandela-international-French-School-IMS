<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$role = $_SESSION['user']['role'] ?? '';
ensure_exam_tables();
$canManageExams = has_system_permission('exams.manage');
$canApproveReports = has_system_permission('reports.approve');
$canViewSchoolReports = has_system_permission('reports.view');
if (!$canManageExams && !$canApproveReports && !$canViewSchoolReports) {
    http_response_code(403);
    exit('You do not have permission to access examination reports.');
}

$connection = db();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

function format_decimal(float $value): string {
    return number_format($value, 2, '.', ',');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Your session token expired. Refresh the page and try again.');
        }

        $action = $_POST['action'] ?? '';
        if ($action === 'publish_results' && !$canApproveReports) {
            throw new RuntimeException('Head Teachers/Principals may approve completed reports only.');
        }
        if ($action !== 'publish_results' && !$canManageExams) {
            throw new RuntimeException('You do not have permission to manage exams or validate marks.');
        }

        if ($action === 'save_exam') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $examType = trim((string) ($_POST['exam_type'] ?? ''));
            $className = trim((string) ($_POST['class_name'] ?? ''));
            $term = trim((string) ($_POST['term'] ?? ''));
            $academicYear = trim((string) ($_POST['academic_year'] ?? ''));
            $examDate = trim((string) ($_POST['exam_date'] ?? ''));
            $parsedExamDate = DateTime::createFromFormat('!Y-m-d', $examDate);
            $allowedExamTypes = ['Mid-Term', 'End-Term'];

            if ($title === '' || !in_array($examType, $allowedExamTypes, true) || $className === '' || $term === '' || $academicYear === '' || !$parsedExamDate || $parsedExamDate->format('Y-m-d') !== $examDate) {
                throw new InvalidArgumentException('Exam title, type, class, term, academic year, and exam date are required.');
            }

            $statement = $connection->prepare(
                'INSERT INTO exams (title, exam_type, class_name, term, academic_year, exam_date, status) VALUES (?, ?, ?, ?, ?, ?, "Draft")'
            );
            $statement->execute([$title, $examType, $className, $term, $academicYear, $examDate]);
            $message = 'Examination created successfully.';
        } elseif ($action === 'save_subject') {
            $subjectName = trim((string) ($_POST['subject_name'] ?? ''));
            $subjectCode = strtoupper(trim((string) ($_POST['subject_code'] ?? '')));
            $className = trim((string) ($_POST['class_name'] ?? ''));

            if ($subjectName === '' || $subjectCode === '' || $className === '') {
                throw new InvalidArgumentException('Subject name, code, and class are required.');
            }

            $statement = $connection->prepare(
                'INSERT INTO subjects (subject_name, subject_code, class_name) VALUES (?, ?, ?)'
            );
            $statement->execute([$subjectName, $subjectCode, $className]);
            $message = 'Subject added successfully.';
        } elseif ($action === 'assign_exam_subjects') {
            $examId = (int) ($_POST['exam_id'] ?? 0);
            $subjectIds = $_POST['subject_ids'] ?? [];

            if ($examId < 1 || !is_array($subjectIds)) {
                throw new InvalidArgumentException('Select a valid exam before assigning subjects.');
            }
            $examForAssignment = $connection->prepare('SELECT class_name, status FROM exams WHERE id = ?');
            $examForAssignment->execute([$examId]);
            $examForAssignment = $examForAssignment->fetch();
            if (!$examForAssignment || $examForAssignment['status'] === 'Published') {
                throw new InvalidArgumentException('Subjects cannot be assigned to a missing or published exam.');
            }
            $existingMarks = $connection->prepare('SELECT COUNT(*) FROM student_marks WHERE exam_id = ?');
            $existingMarks->execute([$examId]);
            if ((int) $existingMarks->fetchColumn() > 0) {
                throw new InvalidArgumentException('Subjects cannot be changed after marks have been entered.');
            }

            $connection->beginTransaction();
            $connection->prepare('DELETE FROM exam_subjects WHERE exam_id = ?')->execute([$examId]);

            foreach ($subjectIds as $subjectId) {
                $subjectId = (int) $subjectId;
                if ($subjectId > 0) {
                    $maxValue = $_POST['max_marks_' . $subjectId] ?? '100';
                    $passValue = $_POST['pass_mark_' . $subjectId] ?? '40';
                    if (!is_numeric($maxValue) || !is_numeric($passValue)) {
                        throw new InvalidArgumentException('Enter numeric maximum and pass marks for every selected subject.');
                    }
                    $maxMarks = (float) $maxValue;
                    $passMark = (float) $passValue;
                    $subjectClass = $connection->prepare('SELECT class_name FROM subjects WHERE id = ?');
                    $subjectClass->execute([$subjectId]);
                    if ($subjectClass->fetchColumn() !== $examForAssignment['class_name']) {
                        throw new InvalidArgumentException('Only subjects for the exam class can be assigned.');
                    }
                    if ($maxMarks <= 0 || $maxMarks > 999.99 || $passMark < 0 || $passMark > $maxMarks) {
                        throw new InvalidArgumentException('Each pass mark must be between zero and its positive maximum mark.');
                    }
                    $statement = $connection->prepare(
                        'INSERT INTO exam_subjects (exam_id, subject_id, max_marks, pass_mark) VALUES (?, ?, ?, ?)'
                    );
                    $statement->execute([$examId, $subjectId, $maxMarks, $passMark]);
                }
            }

            $connection->commit();
            $message = 'Exam subjects updated successfully.';
        } elseif ($action === 'validate_marks') {
            $examId = (int) ($_POST['exam_id'] ?? 0);
            $markIds = $_POST['mark_id'] ?? [];
            if ($examId < 1 || !is_array($markIds) || empty($markIds)) {
                throw new InvalidArgumentException('Select at least one mark to validate.');
            }
            $examStatus = $connection->prepare('SELECT status FROM exams WHERE id = ?');
            $examStatus->execute([$examId]);
            if ($examStatus->fetchColumn() === 'Published') {
                throw new InvalidArgumentException('Marks cannot be changed after results are published.');
            }

            $getMark = $connection->prepare(
                'SELECT student_marks.id, student_marks.marks_obtained, exam_subjects.max_marks
                 FROM student_marks
                 JOIN exam_subjects
                   ON exam_subjects.exam_id = student_marks.exam_id
                  AND exam_subjects.subject_id = student_marks.subject_id
                 WHERE student_marks.exam_id = ? AND student_marks.id = ? AND student_marks.status = "Pending"'
            );
            $validateMark = $connection->prepare(
                'UPDATE student_marks SET grade = ?, status = "Validated"
                 WHERE exam_id = ? AND id = ? AND status = "Pending"'
            );
            $validatedCount = 0;
            foreach ($markIds as $markId) {
                $markId = (int) $markId;
                if ($markId < 1) {
                    throw new InvalidArgumentException('Select valid pending marks to validate.');
                }
                $getMark->execute([$examId, $markId]);
                $mark = $getMark->fetch();
                if (!$mark) {
                    throw new InvalidArgumentException('One or more selected marks are not pending for this exam.');
                }
                $grade = exam_grade_for_percentage(
                    (float) $mark['marks_obtained'] / (float) $mark['max_marks'] * 100
                );
                $validateMark->execute([$grade, $examId, $markId]);
                $validatedCount += $validateMark->rowCount();
            }
            if ($validatedCount === 0) {
                throw new InvalidArgumentException('Select at least one pending mark to validate.');
            }
            $message = 'Selected marks were validated.';
        } elseif ($action === 'publish_results') {
            $examId = (int) ($_POST['exam_id'] ?? 0);
            $examForPublish = $connection->prepare('SELECT class_name, status FROM exams WHERE id = ?');
            $examForPublish->execute([$examId]);
            $examForPublish = $examForPublish->fetch();
            if (!$examForPublish || $examForPublish['status'] === 'Published') {
                throw new InvalidArgumentException('Select an unpublished exam to release results.');
            }

            $studentCount = $connection->prepare('SELECT COUNT(*) FROM students WHERE class_name = ?');
            $studentCount->execute([$examForPublish['class_name']]);
            $studentCount = (int) $studentCount->fetchColumn();
            $subjectCount = $connection->prepare('SELECT COUNT(*) FROM exam_subjects WHERE exam_id = ?');
            $subjectCount->execute([$examId]);
            $subjectCount = (int) $subjectCount->fetchColumn();
            if ($studentCount === 0 || $subjectCount === 0) {
                throw new InvalidArgumentException('Add students and assign subjects before publishing results.');
            }
            $expectedMarks = $studentCount * $subjectCount;
            $markCounts = $connection->prepare(
                'SELECT COUNT(*) AS total_marks,
                        SUM(CASE WHEN sm.status = "Validated" THEN 1 ELSE 0 END) AS validated_marks
                 FROM student_marks sm
                 JOIN students s ON s.id = sm.student_id
                 JOIN exam_subjects es ON es.exam_id = sm.exam_id AND es.subject_id = sm.subject_id
                 WHERE sm.exam_id = ? AND s.class_name = ?'
            );
            $markCounts->execute([$examId, $examForPublish['class_name']]);
            $markCounts = $markCounts->fetch();
            if ((int) $markCounts['total_marks'] !== $expectedMarks || (int) $markCounts['validated_marks'] !== $expectedMarks) {
                throw new InvalidArgumentException('Every student must have marks entered and validated for every assigned subject before results can be published.');
            }
            $connection->prepare('UPDATE exams SET status = "Published" WHERE id = ?')->execute([$examId]);
            $message = 'Results published successfully. Students and parents can now view them.';
        } else {
            throw new InvalidArgumentException('Choose a valid examination management action.');
        }
    }
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    $error = $exception->getMessage();
}

$subjects = $connection->query(
    'SELECT id, subject_name, subject_code, class_name FROM subjects ORDER BY class_name, subject_name'
)->fetchAll();
$classOptions = $connection->query(
    'SELECT DISTINCT class_name FROM students WHERE class_name IS NOT NULL AND class_name <> "" ORDER BY class_name'
)->fetchAll(PDO::FETCH_COLUMN);

$exams = $connection->query(
    'SELECT id, title, exam_type, class_name, term, academic_year, exam_date, status FROM exams ORDER BY exam_date DESC, id DESC'
)->fetchAll();

$examList = $exams;
$selectedExamId = (int) ($_GET['exam_id'] ?? ($_POST['exam_id'] ?? ($exams[0]['id'] ?? 0)));
$selectedExam = null;
foreach ($exams as $exam) {
    if ((int) $exam['id'] === $selectedExamId) {
        $selectedExam = $exam;
        break;
    }
}
if ($selectedExam === null && !empty($exams)) {
    $selectedExam = $exams[0];
    $selectedExamId = (int) $exams[0]['id'];
}

$assignedSubjects = [];
$assignedSubjectMap = [];
if ($selectedExamId > 0) {
    $assignedStatement = $connection->prepare(
        'SELECT es.id, es.exam_id, es.subject_id, s.subject_name, s.subject_code, s.class_name, es.max_marks, es.pass_mark
         FROM exam_subjects es
         JOIN subjects s ON s.id = es.subject_id
         WHERE es.exam_id = ?
         ORDER BY s.subject_name'
    );
    $assignedStatement->execute([$selectedExamId]);
    $assignedSubjects = $assignedStatement->fetchAll();
    foreach ($assignedSubjects as $assigned) {
        $assignedSubjectMap[(int) $assigned['subject_id']] = $assigned;
    }
}

$allSubjectsForSelectedClass = [];
if ($selectedExam) {
    $classStatement = $connection->prepare(
        'SELECT id, subject_name, subject_code, class_name FROM subjects WHERE class_name = ? ORDER BY subject_name'
    );
    $classStatement->execute([$selectedExam['class_name']]);
    $allSubjectsForSelectedClass = $classStatement->fetchAll();
}

$studentRows = [];
if ($selectedExam) {
    $studentStatement = $connection->prepare(
        'SELECT id, admission_number, first_name, last_name FROM students WHERE class_name = ? ORDER BY last_name, first_name'
    );
    $studentStatement->execute([$selectedExam['class_name']]);
    $studentRows = $studentStatement->fetchAll();
}

$markRows = [];
if ($selectedExamId > 0) {
    $marksSql = $connection->prepare(
        'SELECT sm.id, sm.student_id, sm.subject_id, sm.marks_obtained, sm.grade, sm.status,
                s.first_name, s.last_name, sub.subject_name, sub.subject_code
         FROM student_marks sm
         JOIN students s ON s.id = sm.student_id
         JOIN subjects sub ON sub.id = sm.subject_id
         WHERE sm.exam_id = ?
         ORDER BY s.last_name, s.first_name, sub.subject_name'
    );
    $marksSql->execute([$selectedExamId]);
    $markRows = $marksSql->fetchAll();
}

$reportRows = [];
if ($selectedExamId > 0 && !empty($studentRows) && !empty($assignedSubjects)) {
    $marksByStudent = [];
    foreach ($markRows as $row) {
        if ($row['status'] === 'Validated') {
            $marksByStudent[(int) $row['student_id']][(int) $row['subject_id']] = (float) $row['marks_obtained'];
        }
    }

    foreach ($studentRows as $student) {
        $studentId = (int) $student['id'];
        $totalMarks = 0.0;
        $completedSubjects = 0;
        foreach ($assignedSubjects as $subject) {
            if (isset($marksByStudent[$studentId][(int) $subject['subject_id']])) {
                $totalMarks += $marksByStudent[$studentId][(int) $subject['subject_id']];
                $completedSubjects++;
            }
        }
        if ($completedSubjects !== count($assignedSubjects)) {
            continue;
        }
        $maximumMarks = array_sum(array_map(static fn ($subject) => (float) $subject['max_marks'], $assignedSubjects));
        $average = $maximumMarks > 0 ? ($totalMarks / $maximumMarks) * 100 : 0.0;
        $reportRows[] = [
            'student_id' => $studentId,
            'student_name' => $student['first_name'] . ' ' . $student['last_name'],
            'admission_number' => $student['admission_number'],
            'total' => $totalMarks,
            'average' => $average,
            'grade' => exam_grade_for_percentage($average),
        ];
    }

    usort($reportRows, static fn ($left, $right) => ($right['average'] <=> $left['average']) ?: strcasecmp($left['student_name'], $right['student_name']));
    $rank = 0;
    $lastAverage = null;
    foreach ($reportRows as $index => &$row) {
        if ($lastAverage === null || abs($row['average'] - $lastAverage) > 0.00001) {
            $rank = $index + 1;
        }
        $row['position'] = $rank;
        $lastAverage = $row['average'];
    }
    unset($row);
}

$subjectPerformance = [];
if ($selectedExamId > 0 && !empty($assignedSubjects)) {
    foreach ($assignedSubjects as $subject) {
        $scores = [];
        foreach ($markRows as $row) {
            if ((int) $row['subject_id'] === (int) $subject['subject_id'] && $row['status'] === 'Validated') {
                $scores[] = ((float) $row['marks_obtained'] / (float) $subject['max_marks']) * 100;
            }
        }
        $avg = !empty($scores) ? array_sum($scores) / count($scores) : 0.0;
        $subjectPerformance[] = [
            'subject_name' => $subject['subject_name'],
            'average' => $avg,
            'grade' => exam_grade_for_percentage($avg),
            'students' => count($scores),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examination &amp; Results | MIFS IMS</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f7fb; color: #172b4d; font: 14px Arial, sans-serif; }
        header { display: flex; justify-content: space-between; padding: 20px 5%; color: #fff; background: #0b2d55; }
        header a { color: #dce8f7; text-decoration: none; }
        main { max-width: 1400px; margin: auto; padding: 30px 5%; }
        h1 { margin: 0 0 8px; }
        h2 { margin-top: 0; }
        .intro { color: #718096; margin-bottom: 16px; }
        .notice { padding: 12px 15px; border-radius: 7px; margin-bottom: 22px; background: #e8f7f0; color: #267153; }
        .notice.error { background: #fff0f0; color: #a33a3a; }
        .summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card, .metric { border: 1px solid #e7edf4; border-radius: 10px; background: #fff; }
        .metric { padding: 20px; }
        .metric small { display: block; color: #8290a3; font-size: 11px; }
        .metric strong { display: block; margin-top: 8px; font-size: 28px; }
        .card { padding: 20px; margin-bottom: 22px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 18px; }
        .full { grid-column: 1 / -1; }
        label { display: block; margin: 12px 0 5px; color: #52637a; font-size: 12px; font-weight: 700; }
        input, select { width: 100%; padding: 10px; border: 1px solid #d8e0ea; border-radius: 6px; font: inherit; }
        input[type="checkbox"] { width: auto; }
        .checkbox-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        button { padding: 9px 12px; border: 0; border-radius: 6px; color: #fff; background: #1763a6; cursor: pointer; }
        button.secondary { background: #5a6b85; }
        button.warning { background: #c79118; }
        table { width: 100%; border-collapse: collapse; } th { color: #8290a3; font-size: 11px; text-align: left; text-transform: uppercase; }
        th, td { padding: 12px 8px; border-bottom: 1px solid #edf1f5; }
        .chip { display: inline-block; padding: 4px 8px; border-radius: 999px; background: #edf4ff; color: #214a7d; font-size: 11px; }
        .pill { display: inline-block; padding: 4px 8px; border-radius: 999px; background: #e8f7f0; color: #267153; font-size: 11px; }

        .scroll { overflow-x: auto; }
        .report-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 0 16px; }
        .chart-row { display: grid; grid-template-columns: minmax(120px, 1fr) 3fr 55px; align-items: center; gap: 12px; margin: 14px 0; }
        .chart-track { height: 14px; overflow: hidden; border-radius: 9px; background: #edf1f5; }
        .chart-bar { height: 100%; border-radius: inherit; background: #4181c4; }
        .chart-value { color: #52637a; font-weight: 700; text-align: right; }
        @media (max-width: 980px) {
            .summary, .grid, .form-grid { grid-template-columns: 1fr; }

        }
        @media (max-width: 600px) { .report-actions { align-items: flex-start; flex-direction: column; } .chart-row { grid-template-columns: 1fr 2fr 48px; gap: 7px; } }
    </style>
</head>
<body>
<header>
    <strong>MIFS IMS &middot; Examination &amp; Results</strong>
    <nav><a href="<?= $role === 'Head Teacher/Principal' ? 'head_teacher.php' : 'dashboard.php' ?>">Back to dashboard</a></nav>
</header>
<main>
    <h1><?= $canManageExams ? 'Examination management' : 'Academic report approval' ?></h1>
    <p class="intro"><?= $canManageExams ? 'Create exams and assign subjects. Teachers enter marks for their assigned subjects; review and validate submitted marks here before generating grade summaries and rankings.' : 'Review completed academic reports and approve publication after all marks have been validated.' ?></p>

    <?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

    <section class="summary">
        <div class="metric">
            <small>Exams</small>
            <strong><?= count($exams) ?></strong>
        </div>
        <div class="metric">
            <small>Subjects</small>
            <strong><?= count($subjects) ?></strong>
        </div>
        <div class="metric">
            <small>Pending marks</small>
            <strong><?= count(array_filter($markRows, static fn ($row) => (string) $row['status'] === 'Pending')) ?></strong>
        </div>
        <div class="metric">
            <small>Validated</small>
            <strong><?= count(array_filter($markRows, static fn ($row) => (string) $row['status'] === 'Validated')) ?></strong>
        </div>
    </section>

    <?php if ($canManageExams): ?>
    <div class="grid">
        <section class="card">
            <h2>Create exam</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save_exam">
                <label>Exam title</label>
                <input type="text" name="title" placeholder="Mid-Term Exam" required>
                <label>Exam type</label>
                <select name="exam_type" required>
                    <option value="">Select exam type</option>
                    <option value="Mid-Term">Mid-Term</option>
                    <option value="End-Term">End-Term</option>
                </select>
                <label>Class</label>
                <select name="class_name" required>
                    <option value="">Select class</option>
                    <?php foreach ($classOptions as $className): ?>
                        <option value="<?= e($className) ?>"><?= e($className) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Term</label>
                <input type="text" name="term" placeholder="Term 1" required>
                <label>Academic year</label>
                <input type="text" name="academic_year" placeholder="2026/2027" required>
                <label>Exam date</label>
                <input type="date" name="exam_date" required>
                <div style="margin-top:18px;"><button type="submit">Create exam</button></div>
            </form>
        </section>

        <section class="card">
            <h2>Add subject</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save_subject">
                <div class="form-grid">
                    <div>
                        <label>Subject name</label>
                        <input type="text" name="subject_name" required>
                    </div>
                    <div>
                        <label>Subject code</label>
                        <input type="text" name="subject_code" placeholder="ENG" required>
                    </div>
                    <div class="full">
                        <label>Class</label>
                        <select name="class_name" required>
                            <option value="">Select class</option>
                            <?php foreach ($classOptions as $className): ?>
                                <option value="<?= e($className) ?>"><?= e($className) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top:18px;"><button type="submit">Save subject</button></div>
            </form>
        </section>
    </div>
    <?php endif; ?>

    <?php if ($canManageExams): ?>
    <section class="card">
        <h2>Assign subjects to selected exam</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="assign_exam_subjects">
            <div class="form-grid">
                <div>
                    <label>Exam</label>
                    <select name="exam_id" required onchange="window.location='exams.php?exam_id=' + this.value">
                        <option value="">Select exam</option>
                        <?php foreach ($exams as $exam): ?>
                            <option value="<?= e((string) $exam['id']) ?>" <?= ((int) $exam['id'] === $selectedExamId) ? 'selected' : '' ?>><?= e($exam['title'] . ' - ' . $exam['exam_type'] . ' - ' . $exam['class_name'] . ' (' . $exam['term'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if ($selectedExam): ?>
                <div class="scroll" style="margin-top:18px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Select</th>
                                <th>Subject</th>
                                <th>Class</th>
                                <th>Max marks</th>
                                <th>Pass mark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allSubjectsForSelectedClass as $subject): ?>
                                <?php $checked = false; foreach ($assignedSubjects as $assigned) { if ((int)$assigned['subject_id'] === (int)$subject['id']) { $checked = true; break; } } ?>
                                <tr>
                                    <td><input type="checkbox" name="subject_ids[]" value="<?= e((string) $subject['id']) ?>" <?= $checked ? 'checked' : '' ?>></td>
                                    <td><?= e($subject['subject_name']) ?> (<?= e($subject['subject_code']) ?>)</td>
                                    <td><?= e($subject['class_name']) ?></td>
                                    <?php $assignment = $assignedSubjectMap[(int) $subject['id']] ?? ['max_marks' => 100.0, 'pass_mark' => 40.0]; ?>
                                    <td><input type="number" min="0" step="0.01" name="max_marks_<?= e((string) $subject['id']) ?>" value="<?= e((string) ((float) $assignment['max_marks'])) ?>" style="max-width:120px;"></td>
                                    <td><input type="number" min="0" step="0.01" name="pass_mark_<?= e((string) $subject['id']) ?>" value="<?= e((string) ((float) $assignment['pass_mark'])) ?>" style="max-width:120px;"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:18px;"><button type="submit">Assign subjects</button></div>
            <?php endif; ?>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($selectedExam): ?>
    <?php if ($canManageExams): ?>
        <section class="card">
            <h2>Validate marks</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="validate_marks">
                <input type="hidden" name="exam_id" value="<?= e((string) $selectedExamId) ?>">
                <?php if ($markRows): ?>
                    <div class="scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Select</th>
                                    <th>Student</th>
                                    <th>Subject</th>
                                    <th>Mark</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($markRows as $mark): ?>
                                    <tr>
                                        <td><input type="checkbox" name="mark_id[]" value="<?= e((string) $mark['id']) ?>" <?= (string) $mark['status'] === 'Validated' ? 'disabled' : '' ?>></td>
                                        <td><?= e($mark['first_name'] . ' ' . $mark['last_name']) ?></td>
                                        <td><?= e($mark['subject_name']) ?></td>
                                        <td><?= e(format_decimal((float) $mark['marks_obtained'])) ?></td>
                                        <td><span class="<?= (string) $mark['status'] === 'Validated' ? 'pill' : 'chip' ?>"><?= e($mark['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top:18px;"><button class="warning" type="submit">Validate selected marks</button></div>
                <?php else: ?>
                    <p class="intro">No marks are available for this exam yet.</p>
                <?php endif; ?>
            </form>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Grade and performance report</h2>
        <?php if ($selectedExam): ?>
            <div class="report-actions">
                <span>Exam status: <span class="<?= $selectedExam['status'] === 'Published' ? 'pill' : 'chip' ?>"><?= e($selectedExam['status']) ?></span></span>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="chip" href="exam_report.php?exam_id=<?= e((string) $selectedExamId) ?>">Download PDF report</a>
                    <?php if ($selectedExam['status'] !== 'Published' && ($canApproveReports || $canManageExams)): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="publish_results">
                            <input type="hidden" name="exam_id" value="<?= e((string) $selectedExamId) ?>">
                            <button class="warning" type="submit"><?= $role === 'Head Teacher/Principal' ? 'Approve and publish report' : 'Publish results' ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($reportRows): ?>
            <div class="scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Position</th>
                            <th>Student</th>
                            <th>Admission</th>
                            <th>Total</th>
                            <th>Average</th>
                            <th>Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <td><?= e((string) $row['position']) ?></td>
                                <td><?= e($row['student_name']) ?></td>
                                <td><?= e($row['admission_number']) ?></td>
                                <td><?= e(format_decimal((float) $row['total'])) ?></td>
                                <td><?= e(format_decimal((float) $row['average'])) ?></td>
                                <td><span class="pill"><?= e($row['grade']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="intro">No results are available for this exam.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Class subject performance</h2>
        <?php if ($subjectPerformance): ?>
            <p class="intro">Average validated mark as a percentage of each subject maximum.</p>
            <?php foreach ($subjectPerformance as $performance): ?>
                <?php $barWidth = max(0, min(100, (float) $performance['average'])); ?>
                <div class="chart-row">
                    <strong><?= e($performance['subject_name']) ?></strong>
                    <div class="chart-track" role="img" aria-label="<?= e($performance['subject_name'] . ': ' . format_decimal((float) $performance['average']) . ' percent') ?>">
                        <div class="chart-bar" style="width:<?= e(format_decimal($barWidth)) ?>%"></div>
                    </div>
                    <span class="chart-value"><?= e(format_decimal((float) $performance['average'])) ?>%</span>
                </div>
                <small><?= e($performance['grade']) ?> average &middot; <?= e((string) $performance['students']) ?> marks entered</small>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="intro">No subject performance data is available yet.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
