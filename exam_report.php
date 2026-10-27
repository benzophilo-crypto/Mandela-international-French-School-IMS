<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['user'];
$role = $user['role'] ?? '';
$canManageExams = has_system_permission('exams.manage');
$canViewSchoolReports = has_system_permission('reports.view')
    || has_system_permission('reports.approve')
    || $canManageExams;
$canViewPersonalResults = in_array($role, ['Student', 'Parent'], true);
if (!$canViewSchoolReports && !$canViewPersonalResults) {
    http_response_code(403);
    exit('You do not have access to examination reports.');
}

ensure_exam_tables();
$connection = db();
$examId = (int) ($_GET['exam_id'] ?? 0);
$studentId = (int) ($_GET['student_id'] ?? 0);
if ($examId < 1) {
    http_response_code(400);
    exit('A valid exam is required.');
}

$examQuery = $connection->prepare('SELECT * FROM exams WHERE id = ? LIMIT 1');
$examQuery->execute([$examId]);
$exam = $examQuery->fetch();
if (!$exam) {
    http_response_code(404);
    exit('The requested exam was not found.');
}
if ($canViewPersonalResults) {
    $account = $connection->prepare('SELECT student_id FROM users WHERE id = ? LIMIT 1');
    $account->execute([(int) $user['id']]);
    $linkedStudentId = (int) $account->fetchColumn();
    if ($linkedStudentId < 1 || $linkedStudentId !== $studentId) {
        http_response_code(403);
        exit('You may only download results for your linked student record.');
    }
    if ($exam['status'] !== 'Published') {
        http_response_code(404);
        exit('This examination report has not been published.');
    }
} elseif ($studentId < 1) {
    $studentId = 0;
}

$studentSql = 'SELECT id, admission_number, first_name, last_name FROM students WHERE class_name = ?';
$studentParams = [$exam['class_name']];
$studentSql .= ' ORDER BY last_name, first_name';
$studentsQuery = $connection->prepare($studentSql);
$studentsQuery->execute($studentParams);
$students = $studentsQuery->fetchAll();
if (!$students) {
    http_response_code(404);
    exit('No student records were found for this report.');
}

$subjectsQuery = $connection->prepare(
    'SELECT s.id, s.subject_name, s.subject_code, es.max_marks, es.pass_mark
     FROM exam_subjects es JOIN subjects s ON s.id = es.subject_id
     WHERE es.exam_id = ? ORDER BY s.subject_name'
);
$subjectsQuery->execute([$examId]);
$subjects = $subjectsQuery->fetchAll();
if (!$subjects) {
    http_response_code(404);
    exit('No subjects have been assigned to this exam.');
}

$marksQuery = $connection->prepare(
    'SELECT student_id, subject_id, marks_obtained, grade, status
     FROM student_marks WHERE exam_id = ? AND status = "Validated"'
);
$marksQuery->execute([$examId]);
$markMap = [];
foreach ($marksQuery->fetchAll() as $mark) {
    $markMap[(int) $mark['student_id']][(int) $mark['subject_id']] = $mark;
}

$reportRows = [];
$maxTotal = 0.0;
foreach ($subjects as $subject) {
    $maxTotal += (float) $subject['max_marks'];
}
foreach ($students as $student) {
    $total = 0.0;
    $studentSubjects = [];
    foreach ($subjects as $subject) {
        $mark = $markMap[(int) $student['id']][(int) $subject['id']] ?? null;
        if ($mark === null) {
            continue;
        }
        $mark['subject_name'] = $subject['subject_name'];
        $mark['max_marks'] = (float) $subject['max_marks'];
        $mark['pass_mark'] = (float) $subject['pass_mark'];
        $studentSubjects[] = $mark;
        $total += (float) $mark['marks_obtained'];
    }
    if (count($studentSubjects) !== count($subjects)) {
        continue;
    }
    $average = $maxTotal > 0 ? ($total / $maxTotal) * 100 : 0.0;
    $reportRows[] = [
        'student' => $student,
        'subjects' => $studentSubjects,
        'total' => $total,
        'maximum' => $maxTotal,
        'average' => $average,
        'grade' => exam_grade_for_percentage($average),
    ];
}
usort($reportRows, static function (array $left, array $right): int {
    return ($right['average'] <=> $left['average']) ?: strcasecmp(
        $left['student']['last_name'] . $left['student']['first_name'],
        $right['student']['last_name'] . $right['student']['first_name']
    );
});
if ($studentId > 0 && !$reportRows) {
    http_response_code(404);
    exit('Validated results for this student are not available.');
}
$rank = 0;
$lastAverage = null;
$positionByStudent = [];
foreach ($reportRows as $index => $row) {
    if ($lastAverage === null || abs($row['average'] - $lastAverage) > 0.00001) {
        $rank = $index + 1;
    }
    $positionByStudent[(int) $row['student']['id']] = $rank;
    $lastAverage = $row['average'];
}
if ($studentId > 0) {
    $reportRows = array_values(array_filter(
        $reportRows,
        static fn (array $row): bool => (int) $row['student']['id'] === $studentId
    ));
}

$lines = [
    'MANDELA INTERNATIONAL FRENCH SCHOOL',
    'EXAMINATION RESULT REPORT',
    '',
    'Exam: ' . $exam['title'] . ' | Class: ' . $exam['class_name'],
    'Term: ' . $exam['term'] . ' | Academic year: ' . $exam['academic_year'] . ' | Date: ' . $exam['exam_date'],
    '',
];
foreach ($reportRows as $row) {
    $name = $row['student']['first_name'] . ' ' . $row['student']['last_name'];
    $lines[] = $name . ' (' . $row['student']['admission_number'] . ')';
    $lines[] = 'Position: ' . $positionByStudent[(int) $row['student']['id']]
        . ' | Total: ' . number_format($row['total'], 2) . '/' . number_format($row['maximum'], 2)
        . ' | Average: ' . number_format($row['average'], 2) . '% | Grade: ' . $row['grade'];
    foreach ($row['subjects'] as $subject) {
        $lines[] = '  ' . $subject['subject_name'] . ': '
            . number_format((float) $subject['marks_obtained'], 2) . '/'
            . number_format((float) $subject['max_marks'], 2)
            . ' | Grade: ' . $subject['grade']
            . ' | ' . ((float) $subject['marks_obtained'] >= (float) $subject['pass_mark'] ? 'Pass' : 'Needs improvement');
    }
    $lines[] = '';
}
if (!$reportRows) {
    $lines[] = 'No complete, validated student results are available yet.';
}

$wrappedLines = [];
foreach ($lines as $line) {
    $asciiLine = preg_replace('/[^\x20-\x7E]/', '?', $line);
    foreach (explode("\n", wordwrap((string) $asciiLine, 105, "\n", true)) as $wrappedLine) {
        $wrappedLines[] = $wrappedLine;
    }
}
$pages = array_chunk($wrappedLines, 42);
if (!$pages) {
    $pages = [[]];
}

$objects = [];
$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(
    static fn (int $index): string => (string) (4 + $index * 2) . ' 0 R',
    array_keys($pages)
)) . '] /Count ' . count($pages) . ' >>';
$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
foreach ($pages as $index => $pageLines) {
    $pageObject = 4 + $index * 2;
    $contentObject = $pageObject + 1;
    $stream = "BT\n/F1 9 Tf\n40 550 Td\n";
    foreach ($pageLines as $lineIndex => $line) {
        if ($lineIndex > 0) {
            $stream .= "0 -12 Td\n";
        }
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $stream .= '(' . $escaped . ") Tj\n";
    }
    $stream .= "ET\n";
    $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents '
        . $contentObject . ' 0 R >>';
    $objects[$contentObject] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
}

$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach ($objects as $objectId => $object) {
    $offsets[$objectId] = strlen($pdf);
    $pdf .= $objectId . " 0 obj\n" . $object . "\nendobj\n";
}
$xrefOffset = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
for ($objectId = 1; $objectId <= count($objects); $objectId++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$objectId]);
}
$pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

$filename = 'exam-results-' . $examId . ($studentId > 0 ? '-student-' . $studentId : '') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
