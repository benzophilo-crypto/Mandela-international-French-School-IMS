<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['user'];
$role = $user['role'] ?? '';
if (!in_array($role, ['Student', 'Parent'], true)) {
    http_response_code(403);
    exit('Student or parent access is required to view published results.');
}

ensure_exam_tables();
$connection = db();
$account = $connection->prepare(
    'SELECT users.student_id, students.first_name, students.last_name, students.class_name, students.admission_number
     FROM users
     JOIN students ON students.id = users.student_id
     WHERE users.id = ? LIMIT 1'
);
$account->execute([(int) $user['id']]);
$student = $account->fetch();

if (!$student) {
    http_response_code(409);
    exit('Your account is not linked to a student record. Contact the Registrar.');
}

$publishedExams = $connection->prepare(
    "SELECT id, title, class_name, term, academic_year, exam_date
     FROM exams
     WHERE status = 'Published' AND class_name = ?
     ORDER BY exam_date DESC, id DESC"
);
$publishedExams->execute([$student['class_name']]);
$exams = $publishedExams->fetchAll();

$reports = [];
foreach ($exams as $exam) {
    $subjectQuery = $connection->prepare(
        'SELECT s.id, s.subject_name, s.subject_code, es.max_marks, es.pass_mark,
                sm.marks_obtained, sm.grade
         FROM exam_subjects es
         JOIN subjects s ON s.id = es.subject_id
         JOIN student_marks sm ON sm.subject_id = s.id AND sm.exam_id = es.exam_id
         WHERE es.exam_id = ? AND sm.student_id = ? AND sm.status = "Validated"
         ORDER BY s.subject_name'
    );
    $subjectQuery->execute([(int) $exam['id'], (int) $student['student_id']]);
    $subjectRows = $subjectQuery->fetchAll();

    $total = 0.0;
    $maximum = 0.0;
    foreach ($subjectRows as $subject) {
        $total += (float) $subject['marks_obtained'];
        $maximum += (float) $subject['max_marks'];
    }
    $average = $maximum > 0 ? ($total / $maximum) * 100 : 0.0;

    $rankQuery = $connection->prepare(
        'SELECT s.id, SUM(sm.marks_obtained) AS total_marks,
                SUM(es.max_marks) AS maximum_marks
         FROM students s
         JOIN exam_subjects es ON es.exam_id = ?
         JOIN student_marks sm ON sm.exam_id = es.exam_id AND sm.subject_id = es.subject_id
                              AND sm.student_id = s.id AND sm.status = "Validated"
         WHERE s.class_name = ?
         GROUP BY s.id
         HAVING COUNT(DISTINCT sm.subject_id) = (
             SELECT COUNT(*) FROM exam_subjects WHERE exam_id = ?
         )
         ORDER BY (SUM(sm.marks_obtained) / SUM(es.max_marks)) DESC, s.id ASC'
    );
    $rankQuery->execute([(int) $exam['id'], $exam['class_name'], (int) $exam['id']]);
    $rankings = $rankQuery->fetchAll();
    $position = null;
    $currentRank = 0;
    $previousAverage = null;
    foreach ($rankings as $index => $ranking) {
        $rankingAverage = (float) $ranking['total_marks'] / (float) $ranking['maximum_marks'] * 100;
        if ($previousAverage === null || abs($rankingAverage - $previousAverage) > 0.00001) {
            $currentRank = $index + 1;
        }
        if ((int) $ranking['id'] === (int) $student['student_id']) {
            $position = $currentRank;
        }
        $previousAverage = $rankingAverage;
    }

    $reports[] = [
        'exam' => $exam,
        'subjects' => $subjectRows,
        'total' => $total,
        'maximum' => $maximum,
        'average' => $average,
        'grade' => exam_grade_for_percentage($average),
        'position' => $position,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Published Results | MIFS IMS</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #172b4d; background: #f5f7fb; font: 14px Arial, sans-serif; }
        header { display: flex; justify-content: space-between; padding: 20px 5%; color: #fff; background: #0b2d55; }
        header a { color: #dce8f7; text-decoration: none; }
        main { max-width: 1100px; margin: auto; padding: 30px 5%; }
        .intro { color: #718096; }
        .card { margin: 18px 0; padding: 20px; border: 1px solid #e7edf4; border-radius: 10px; background: #fff; }
        .summary { display: flex; flex-wrap: wrap; gap: 18px; color: #52637a; }
        table { width: 100%; margin-top: 16px; border-collapse: collapse; }
        th { color: #8290a3; font-size: 11px; text-align: left; text-transform: uppercase; }
        th, td { padding: 12px 8px; border-bottom: 1px solid #edf1f5; }
        .empty { padding: 24px; color: #718096; text-align: center; }
        .grade { padding: 4px 9px; border-radius: 14px; color: #267153; background: #e8f7f0; }
        .download { display: inline-block; margin-top: 14px; padding: 10px 13px; border-radius: 6px; color: #fff; background: #1763a6; text-decoration: none; }
        @media (max-width: 650px) { .table-wrap { overflow-x: auto; } table { min-width: 550px; } }
    </style>
</head>
<body>
<header><strong>MIFS IMS &middot; Published Results</strong><nav><a href="dashboard.php">Dashboard</a> &nbsp; | &nbsp; <a href="logout.php">Sign out</a></nav></header>
<main>
    <h1>Academic results</h1>
    <p class="intro">Showing results released by the Registrar for <?= e($student['first_name'] . ' ' . $student['last_name']) ?> (<?= e($student['admission_number']) ?>).</p>
    <?php if ($reports): ?>
        <?php foreach ($reports as $report): ?>
            <section class="card">
                <h2><?= e($report['exam']['title']) ?> &middot; <?= e($report['exam']['term']) ?> <?= e($report['exam']['academic_year']) ?></h2>
                <p class="intro"><?= e($report['exam']['class_name']) ?> &middot; <?= e($report['exam']['exam_date']) ?></p>
                <div class="summary">
                    <span>Total: <strong><?= e(number_format($report['total'], 2)) ?> / <?= e(number_format($report['maximum'], 2)) ?></strong></span>
                    <span>Average: <strong><?= e(number_format($report['average'], 2)) ?>%</strong></span>
                    <span>Grade: <strong class="grade"><?= e($report['grade']) ?></strong></span>
                    <?php if ($report['position'] !== null): ?><span>Position: <strong><?= e((string) $report['position']) ?></strong></span><?php endif; ?>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Subject</th><th>Mark</th><th>Maximum</th><th>Grade</th><th>Result</th></tr></thead>
                        <tbody>
                        <?php foreach ($report['subjects'] as $subject): ?>
                            <tr>
                                <td><?= e($subject['subject_name']) ?></td>
                                <td><?= e(number_format((float) $subject['marks_obtained'], 2)) ?></td>
                                <td><?= e(number_format((float) $subject['max_marks'], 2)) ?></td>
                                <td><?= e($subject['grade']) ?></td>
                                <td><?= (float) $subject['marks_obtained'] >= (float) $subject['pass_mark'] ? 'Pass' : 'Needs improvement' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <a class="download" href="exam_report.php?exam_id=<?= e((string) $report['exam']['id']) ?>&amp;student_id=<?= e((string) $student['student_id']) ?>">Download PDF report</a>
            </section>
        <?php endforeach; ?>
    <?php else: ?>
        <section class="card empty">There are no published examination results for your class yet.</section>
    <?php endif; ?>
</main>
</body>
</html>
