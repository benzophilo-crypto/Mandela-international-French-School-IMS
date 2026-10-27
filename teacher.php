<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
if (!has_system_permission('teacher.access')) {
    http_response_code(403);
    exit('Teacher workspace permission is required.');
}

$connection = db();
ensure_exam_tables();
ensure_timetable_tables();
ensure_teacher_subject_assignment_table();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$teacherId = (int) $_SESSION['user']['id'];
$role = $_SESSION['user']['role'] ?? '';
$message = '';
$error = '';

function teacher_exam_subjects(PDO $connection, int $teacherId, int $examId): array {
    $statement = $connection->prepare(
        "SELECT DISTINCT subjects.id, subjects.subject_name, exam_subjects.max_marks
         FROM exams
         JOIN subjects
           ON subjects.class_name = exams.class_name
         JOIN exam_subjects
           ON exam_subjects.exam_id = exams.id
          AND exam_subjects.subject_id = subjects.id
         WHERE exams.id = ?
           AND (
               EXISTS (
                   SELECT 1 FROM timetable_entries
                   WHERE timetable_entries.class_name = exams.class_name
                     AND timetable_entries.term = exams.term
                     AND timetable_entries.academic_year = exams.academic_year
                     AND timetable_entries.subject_name = subjects.subject_name
                     AND timetable_entries.teacher_id = ?
               )
               OR EXISTS (
                   SELECT 1 FROM teacher_subject_assignments
                   WHERE teacher_subject_assignments.subject_id = subjects.id
                     AND teacher_subject_assignments.teacher_id = ?
               )
           )
         ORDER BY subjects.subject_name"
    );
    $statement->execute([$examId, $teacherId, $teacherId]);
    return $statement->fetchAll();
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Your session token expired. Refresh the page and try again.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'save_teacher_marks') {
            if ($role !== 'Teacher') {
                throw new RuntimeException('Only teachers can submit student marks.');
            }
            $examId = (int) ($_POST['exam_id'] ?? 0);
            $examStatement = $connection->prepare(
                'SELECT id, class_name, term, academic_year, status FROM exams WHERE id = ?'
            );
            $examStatement->execute([$examId]);
            $exam = $examStatement->fetch();
            if (!$exam || $exam['status'] === 'Published') {
                throw new InvalidArgumentException('Select an available, unpublished exam.');
            }

            $subjectRows = teacher_exam_subjects($connection, $teacherId, $examId);
            if (!$subjectRows) {
                throw new InvalidArgumentException('You are not assigned to any subjects for this exam.');
            }

            $studentStatement = $connection->prepare(
                'SELECT id FROM students WHERE class_name = ? ORDER BY last_name, first_name'
            );
            $studentStatement->execute([$exam['class_name']]);
            $studentIds = $studentStatement->fetchAll(PDO::FETCH_COLUMN);
            if (!$studentIds) {
                throw new InvalidArgumentException('There are no students enrolled in this class.');
            }

            $connection->beginTransaction();
            $saveMark = $connection->prepare(
                'INSERT INTO student_marks (exam_id, student_id, subject_id, marks_obtained, grade, status)
                 VALUES (?, ?, ?, ?, NULL, "Pending")
                 ON DUPLICATE KEY UPDATE marks_obtained = VALUES(marks_obtained), grade = NULL, status = "Pending"'
            );
            $existingMark = $connection->prepare(
                'SELECT status FROM student_marks WHERE exam_id = ? AND student_id = ? AND subject_id = ?'
            );
            $savedCount = 0;

            foreach ($studentIds as $studentId) {
                foreach ($subjectRows as $subject) {
                    $field = 'mark_' . (int) $studentId . '_' . (int) $subject['id'];
                    $value = trim((string) ($_POST[$field] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    if (!is_numeric($value)) {
                        throw new InvalidArgumentException('Marks must be numeric.');
                    }
                    $mark = round((float) $value, 2);
                    if ($mark < 0 || $mark > (float) $subject['max_marks']) {
                        throw new InvalidArgumentException(
                            'Each mark must be between zero and the subject maximum of ' . $subject['max_marks'] . '.'
                        );
                    }

                    $existingMark->execute([$examId, (int) $studentId, (int) $subject['id']]);
                    if ($existingMark->fetchColumn() === 'Validated') {
                        throw new InvalidArgumentException('A validated mark cannot be changed by a teacher.');
                    }
                    $saveMark->execute([$examId, (int) $studentId, (int) $subject['id'], $mark]);
                    $savedCount++;
                }
            }

            if ($savedCount === 0) {
                throw new InvalidArgumentException('Enter at least one mark before saving.');
            }
            $connection->commit();
            $message = 'Marks saved as pending. The registrar can review and validate them.';
        } elseif ($action === 'record_attendance') {
            $date = $_POST['attendance_date'] ?? '';
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $status = $_POST['attendance_status'] ?? '';
            if ($studentId < 1 || !DateTime::createFromFormat('Y-m-d', $date) || !in_array($status, ['Present', 'Absent', 'Late'], true)) {
                throw new InvalidArgumentException('Select a student, date, and valid attendance status.');
            }
            $assignedStudent = $connection->prepare(
                'SELECT COUNT(*) FROM students
                 WHERE students.id = ?
                   AND (
                       EXISTS (
                           SELECT 1 FROM timetable_entries
                           WHERE timetable_entries.class_name = students.class_name
                             AND timetable_entries.teacher_id = ?
                       )
                       OR EXISTS (
                           SELECT 1 FROM teacher_subject_assignments
                           JOIN subjects ON subjects.id = teacher_subject_assignments.subject_id
                           WHERE subjects.class_name = students.class_name
                             AND teacher_subject_assignments.teacher_id = ?
                       )
                   )'
            );
            $assignedStudent->execute([$studentId, $teacherId, $teacherId]);
            if ((int) $assignedStudent->fetchColumn() === 0) {
                throw new InvalidArgumentException('You can only record attendance for students in your assigned classes.');
            }
            $existing = $connection->prepare('SELECT id FROM attendance WHERE student_id = ? AND attendance_date = ? LIMIT 1');
            $existing->execute([$studentId, $date]);
            $existingId = $existing->fetchColumn();
            if ($existingId) {
                $statement = $connection->prepare('UPDATE attendance SET attendance_status = ?, recorded_by = ? WHERE id = ?');
                $statement->execute([$status, $teacherId, $existingId]);
                $message = 'Attendance updated successfully.';
            } else {
                $statement = $connection->prepare('INSERT INTO attendance (student_id, attendance_date, attendance_status, recorded_by) VALUES (?, ?, ?, ?)');
                $statement->execute([$studentId, $date, $status, $teacherId]);
                $message = 'Attendance recorded successfully.';
            }
        } else {
            throw new InvalidArgumentException('Choose a valid teacher workspace action.');
        }
    }
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    $error = $exception->getMessage();
}

$assignedClassesStatement = $connection->prepare(
    'SELECT class_name FROM (
         SELECT class_name FROM timetable_entries WHERE teacher_id = ?
         UNION
         SELECT subjects.class_name
         FROM teacher_subject_assignments
         JOIN subjects ON subjects.id = teacher_subject_assignments.subject_id
         WHERE teacher_subject_assignments.teacher_id = ?
     ) AS teacher_classes
     WHERE class_name IS NOT NULL
     ORDER BY class_name'
);
$assignedClassesStatement->execute([$teacherId, $teacherId]);
$assignedClasses = $assignedClassesStatement->fetchAll(PDO::FETCH_COLUMN);
$students = [];
if ($assignedClasses) {
    $studentPlaceholders = implode(',', array_fill(0, count($assignedClasses), '?'));
    $studentStatement = $connection->prepare(
        'SELECT id, admission_number, first_name, last_name, class_name
         FROM students WHERE class_name IN (' . $studentPlaceholders . ')
         ORDER BY class_name, last_name, first_name'
    );
    $studentStatement->execute($assignedClasses);
    $students = $studentStatement->fetchAll();
}
$todayAttendanceStatement = $connection->prepare(
    'SELECT students.first_name, students.last_name, students.class_name, attendance.attendance_status
     FROM attendance JOIN students ON students.id = attendance.student_id
     WHERE attendance.attendance_date = CURRENT_DATE
       AND EXISTS (
           SELECT 1 FROM timetable_entries
           WHERE timetable_entries.class_name = students.class_name
             AND timetable_entries.teacher_id = ?
           UNION
           SELECT 1 FROM teacher_subject_assignments
           JOIN subjects ON subjects.id = teacher_subject_assignments.subject_id
           WHERE subjects.class_name = students.class_name
             AND teacher_subject_assignments.teacher_id = ?
       )
     ORDER BY students.class_name, students.last_name'
);
$todayAttendanceStatement->execute([$teacherId, $teacherId]);
$todayAttendance = $todayAttendanceStatement->fetchAll();

$availableExamsStatement = $connection->prepare(
    "SELECT DISTINCT exams.id, exams.title, exams.exam_type, exams.class_name, exams.term, exams.academic_year, exams.exam_date
     FROM exams
    JOIN subjects ON subjects.class_name = exams.class_name
    JOIN exam_subjects
      ON exam_subjects.exam_id = exams.id
     AND exam_subjects.subject_id = subjects.id
    WHERE exams.status <> 'Published'
      AND (
          EXISTS (
              SELECT 1 FROM timetable_entries
              WHERE timetable_entries.class_name = exams.class_name
                AND timetable_entries.term = exams.term
                AND timetable_entries.academic_year = exams.academic_year
                AND timetable_entries.subject_name = subjects.subject_name
                AND timetable_entries.teacher_id = ?
          )
          OR EXISTS (
              SELECT 1 FROM teacher_subject_assignments
              WHERE teacher_subject_assignments.subject_id = subjects.id
                AND teacher_subject_assignments.teacher_id = ?
          )
      )
    ORDER BY exams.exam_date DESC, exams.id DESC"
);
$availableExamsStatement->execute([$teacherId, $teacherId]);
$availableExams = $availableExamsStatement->fetchAll();
$selectedExamId = (int) ($_POST['exam_id'] ?? $_GET['exam_id'] ?? 0);
$selectedExam = null;
foreach ($availableExams as $availableExam) {
    if ((int) $availableExam['id'] === $selectedExamId) {
        $selectedExam = $availableExam;
        break;
    }
}
$markSubjects = $selectedExam ? teacher_exam_subjects($connection, $teacherId, $selectedExamId) : [];
$classStudents = [];
$existingMarks = [];
if ($selectedExam) {
    $classStudentStatement = $connection->prepare(
        'SELECT id, admission_number, first_name, last_name
         FROM students WHERE class_name = ? ORDER BY last_name, first_name'
    );
    $classStudentStatement->execute([$selectedExam['class_name']]);
    $classStudents = $classStudentStatement->fetchAll();

    $marksStatement = $connection->prepare(
        'SELECT student_id, subject_id, marks_obtained, status
         FROM student_marks WHERE exam_id = ?'
    );
    $marksStatement->execute([$selectedExamId]);
    foreach ($marksStatement->fetchAll() as $markRow) {
        $existingMarks[(int) $markRow['student_id']][(int) $markRow['subject_id']] = $markRow;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teacher Workspace | MFIS IMS</title>
<style>
*{box-sizing:border-box}
body{margin:0;color:#172b4d;background:#f5f7fb;font:14px Arial,sans-serif}
header{display:flex;justify-content:space-between;padding:20px 5%;color:#fff;background:#0b2d55}
header a{color:#dce8f7;text-decoration:none}
main{max-width:1300px;margin:auto;padding:30px 5%}
h1{margin:0 0 7px}h2{margin-top:0}
.intro{color:#718096;line-height:1.5}
.card{padding:20px;margin:20px 0;border:1px solid #e7edf4;border-radius:10px;background:#fff}
label{display:block;margin:12px 0 5px;color:#52637a;font-size:12px;font-weight:700}
input,select{width:100%;padding:10px;border:1px solid #d8e0ea;border-radius:6px;font:inherit}
button{padding:10px 14px;border:0;border-radius:6px;color:#fff;background:#1763a6;cursor:pointer;font:inherit}
button.btn-green{background:#15803d}
button.btn-secondary{color:#38516e;background:#edf2f7}
.notice{padding:12px 15px;margin-bottom:18px;border-radius:7px;color:#267153;background:#e8f7f0}
.error{color:#a33a3a;background:#fff0f0}
.muted{color:#718096;font-size:12px}
.status{font-size:11px;font-weight:700}
.Pending{color:#94621f}.Validated{color:#267153}
.Present{color:#267153}.Absent{color:#a33a3a}.Late{color:#94621f}
.layout{display:grid;grid-template-columns:350px minmax(0,1fr);gap:20px}
.actions{margin-top:16px;display:flex;gap:10px;flex-wrap:wrap}

/* ── Spreadsheet marks table ── */
.sheet-wrap{overflow-x:auto;border:1px solid #d8e0ea;border-radius:8px;margin-top:16px}
.sheet-table{width:100%;border-collapse:collapse;font-size:13px}
.sheet-table thead tr{position:sticky;top:0;z-index:2}
.sheet-table thead th{
    padding:10px 8px;background:#0b2d55;color:#fff;
    font-size:11px;text-align:left;text-transform:uppercase;
    letter-spacing:.4px;white-space:nowrap;border-right:1px solid #1d4c82;
}
.sheet-table thead th:last-child{border-right:0}
.sheet-table tbody tr:nth-child(even){background:#f8fafc}
.sheet-table tbody tr:hover{background:#edf4ff}
.sheet-table td{
    padding:6px 8px;border-bottom:1px solid #e7edf4;
    border-right:1px solid #e7edf4;vertical-align:middle;
}
.sheet-table td:last-child{border-right:0}
.row-num{width:36px;text-align:center;color:#8290a3;font-size:12px;font-weight:700}
.student-select{min-width:200px}
.mark-cell input[type="number"]{
    width:90px;padding:6px 8px;border:1px solid #d8e0ea;border-radius:4px;
    font:13px Arial,sans-serif;text-align:right;
    background:#fff;transition:border-color .15s,box-shadow .15s;
}
.mark-cell input[type="number"]:focus{
    outline:none;border-color:#1763a6;box-shadow:0 0 0 3px rgba(23,99,166,.12);
}
.mark-cell input.validated{background:#f0fdf4;color:#15803d;border-color:#86efac;cursor:not-allowed}
.mark-cell .val-badge{display:inline-block;padding:2px 7px;border-radius:10px;background:#dcfce7;color:#15803d;font-size:11px;font-weight:700}
.del-btn{width:30px;height:30px;padding:0;border:0;border-radius:4px;
    color:#b94747;background:#fff0f0;cursor:pointer;font-size:16px;line-height:1}
.del-btn:hover{background:#fee2e2}
.add-row-bar{padding:10px 12px;border-top:2px dashed #d8e0ea;background:#f8fafc}
.add-row-bar button{padding:7px 14px;font-size:13px}
.sheet-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px;flex-wrap:wrap}
.sheet-footer .hint{color:#718096;font-size:12px}

@media(max-width:800px){.layout{grid-template-columns:1fr}}
</style>
</head>
<body>
<header><strong>MIFS IMS &middot; Teacher Workspace</strong><nav><a href="logout.php">Sign out</a></nav></header>
<main>
<h1>Teacher workspace</h1>
<p class="intro">Enter marks for students in your assigned class and subject. Submitted marks remain pending until reviewed by the registrar.</p>
<?php if($message):?><div class="notice"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>

<!-- ══════════════════════════════════════════════════════════
     MARKS ENTRY — spreadsheet style
     ══════════════════════════════════════════════════════════ -->
<section class="card">
    <h2>Enter student marks</h2>

    <?php if ($availableExams): ?>
        <!-- Exam selector -->
        <form method="get" id="exam-select-form">
            <label for="exam_id">Select exam, class and term</label>
            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                <div style="flex:1;min-width:240px">
                    <select id="exam_id" name="exam_id" required>
                        <option value="">— choose an exam —</option>
                        <?php foreach ($availableExams as $examOption): ?>
                            <option value="<?=e((string)$examOption['id'])?>"
                                <?=((int)$examOption['id']===$selectedExamId?'selected':'')?>>
                                <?=e($examOption['title'].' · '.$examOption['exam_type'].' · '.$examOption['class_name'].' · '.$examOption['term'].' '.$examOption['academic_year'])?>
                            </option>
                        <?php endforeach;?>
                    </select>
                </div>
                <button type="submit">Load class</button>
            </div>
        </form>
    <?php else: ?>
        <p class="intro">No available exams match your subject assignments. Ask the registrar to create an exam and assign subjects for your class.</p>
    <?php endif; ?>

    <?php if ($selectedExam && $classStudents && $markSubjects): ?>

        <?php
        // Build JS-safe student list for the "add row" dropdown
        $jsStudents = [];
        foreach ($classStudents as $st) {
            $jsStudents[] = [
                'id'    => (int)$st['id'],
                'name'  => $st['first_name'].' '.$st['last_name'],
                'adm'   => $st['admission_number'],
            ];
        }
        // Build JS-safe subject list
        $jsSubjects = [];
        foreach ($markSubjects as $sub) {
            $jsSubjects[] = [
                'id'  => (int)$sub['id'],
                'name'=> $sub['subject_name'],
                'max' => (float)$sub['max_marks'],
            ];
        }
        ?>

        <p style="margin:14px 0 0">
            <strong><?=e($selectedExam['class_name'])?></strong> &nbsp;&middot;&nbsp;
            <?=e($selectedExam['term'].' '.$selectedExam['academic_year'])?>
        </p>

        <form method="post" id="marks-form">
            <input type="hidden" name="csrf_token" value="<?=e($_SESSION['csrf_token'])?>">
            <input type="hidden" name="action" value="save_teacher_marks">
            <input type="hidden" name="exam_id" value="<?=e((string)$selectedExamId)?>">

            <!-- Spreadsheet table -->
            <div class="sheet-wrap">
                <table class="sheet-table" id="marks-sheet">
                    <thead>
                        <tr>
                            <th class="row-num">#</th>
                            <th class="student-select">Student</th>
                            <th style="width:120px">Admission no.</th>
                            <?php foreach($markSubjects as $sub):?>
                                <th><?=e($sub['subject_name'])?><br>
                                    <span style="font-weight:400;opacity:.75">Max <?=e((string)$sub['max_marks'])?></span>
                                </th>
                            <?php endforeach;?>
                            <th style="width:44px"></th><!-- delete col -->
                        </tr>
                    </thead>
                    <tbody id="marks-tbody">
                    <?php foreach($classStudents as $rowIdx => $student):
                        $sid = (int)$student['id'];
                    ?>
                        <tr data-row="<?=$rowIdx+1?>">
                            <td class="row-num"><?=$rowIdx+1?></td>
                            <!-- Student name (read-only for existing rows) -->
                            <td>
                                <input type="hidden" name="row_student_id[]" value="<?=e((string)$sid)?>">
                                <strong><?=e($student['first_name'].' '.$student['last_name'])?></strong>
                            </td>
                            <td style="color:#718096;font-size:12px"><?=e($student['admission_number'])?></td>
                            <!-- One mark cell per subject -->
                            <?php foreach($markSubjects as $sub):
                                $existing = $existingMarks[$sid][(int)$sub['id']] ?? null;
                                $validated = $existing && $existing['status'] === 'Validated';
                                $fieldName = 'mark_'.$sid.'_'.(int)$sub['id'];
                            ?>
                            <td class="mark-cell">
                                <?php if($validated):?>
                                    <input type="number" class="validated" value="<?=e((string)$existing['marks_obtained'])?>"
                                           readonly tabindex="-1"
                                           title="Validated — cannot be changed">
                                    <br><span class="val-badge">&#10003; Validated</span>
                                <?php else:?>
                                    <input type="number"
                                           name="<?=e($fieldName)?>"
                                           min="0" max="<?=e((string)$sub['max_marks'])?>"
                                           step="0.01"
                                           value="<?=e($existing ? (string)$existing['marks_obtained'] : '')?>"
                                           placeholder="0"
                                           aria-label="<?=e($student['first_name'].' '.$student['last_name'].' — '.$sub['subject_name'])?>">
                                    <?php if($existing):?>
                                        <br><span class="status Pending">Pending</span>
                                    <?php endif;?>
                                <?php endif;?>
                            </td>
                            <?php endforeach;?>
                            <!-- Delete row — only for dynamically added rows; hide for DB rows -->
                            <td><button type="button" class="del-btn" style="visibility:hidden" onclick="removeRow(this)" title="Remove row">&#215;</button></td>
                        </tr>
                    <?php endforeach;?>
                    </tbody>

                    <!-- Add-row bar inside table as a tfoot -->
                    <tfoot>
                        <tr>
                            <td colspan="<?=3+count($markSubjects)+1?>" class="add-row-bar">
                                <button type="button" class="btn-secondary" id="add-row-btn" onclick="addRow()">
                                    &#43; Add row
                                </button>
                                <span class="muted" style="margin-left:10px">Add an extra student row if needed</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="sheet-footer">
                <span class="hint">&#9432; Leave a mark blank to skip it. Validated marks cannot be changed.</span>
                <div style="display:flex;gap:10px">
                    <button type="submit" class="btn-green">&#10003; Save all marks as pending</button>
                </div>
            </div>
        </form>

        <!-- JS data for dynamic rows -->
        <script>
        var STUDENTS = <?=json_encode($jsStudents, JSON_HEX_TAG)?>;
        var SUBJECTS = <?=json_encode($jsSubjects, JSON_HEX_TAG)?>;
        var dynamicRowCount = 0;

        function addRow() {
            var tbody  = document.getElementById('marks-tbody');
            var rows   = tbody.querySelectorAll('tr');
            var rowNum = rows.length + 1;
            dynamicRowCount++;

            // Build student options
            var opts = '<option value="">— select student —</option>';
            STUDENTS.forEach(function(s) {
                opts += '<option value="' + s.id + '" data-adm="' + escHtml(s.adm) + '">' + escHtml(s.name) + '</option>';
            });

            // Build mark cells
            var cells = '';
            SUBJECTS.forEach(function(sub) {
                cells += '<td class="mark-cell" data-subid="' + sub.id + '">'
                       + '<input type="number" min="0" max="' + sub.max + '" step="0.01" placeholder="0"'
                       + ' aria-label="' + escHtml(sub.name) + '">'
                       + '</td>';
            });

            var tr = document.createElement('tr');
            tr.setAttribute('data-dynamic', '1');
            tr.innerHTML =
                '<td class="row-num">' + rowNum + '</td>'
                + '<td>'
                +   '<input type="hidden" name="row_student_id[]" value="" class="dyn-student-id">'
                +   '<select class="dyn-student-select" onchange="onStudentChange(this)">'
                +       opts
                +   '</select>'
                + '</td>'
                + '<td class="dyn-adm" style="color:#718096;font-size:12px">—</td>'
                + cells
                + '<td><button type="button" class="del-btn" onclick="removeRow(this)" title="Remove row">&#215;</button></td>';

            tbody.appendChild(tr);
            renumberRows();
        }

        function onStudentChange(sel) {
            var tr   = sel.closest('tr');
            var sid  = sel.value;
            var opt  = sel.options[sel.selectedIndex];
            var adm  = opt.dataset.adm || '—';

            // Update hidden student id
            tr.querySelector('.dyn-student-id').value = sid;
            // Update admission number cell
            tr.querySelector('.dyn-adm').textContent = adm;

            // Wire up name attributes for mark inputs
            SUBJECTS.forEach(function(sub, idx) {
                var cell = tr.querySelectorAll('.mark-cell')[idx];
                if (!cell) return;
                var inp = cell.querySelector('input[type="number"]');
                if (inp) {
                    inp.name = sid ? ('mark_' + sid + '_' + sub.id) : '';
                }
            });
        }

        function removeRow(btn) {
            var tr = btn.closest('tr');
            tr.parentNode.removeChild(tr);
            renumberRows();
        }

        function renumberRows() {
            var rows = document.querySelectorAll('#marks-tbody tr');
            rows.forEach(function(tr, i) {
                var cell = tr.querySelector('.row-num');
                if (cell) cell.textContent = i + 1;
            });
        }

        function escHtml(str) {
            return String(str)
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;');
        }
        </script>

    <?php elseif ($selectedExam && !$classStudents): ?>
        <p class="intro">No students are currently enrolled in this class.</p>
    <?php elseif ($selectedExam && !$markSubjects): ?>
        <p class="intro">No subjects are assigned to you for this exam. Contact the registrar or administrator.</p>
    <?php endif;?>
</section>

<div class="layout">
    <section class="card"><h2>Record attendance</h2><form method="post"><input type="hidden" name="csrf_token" value="<?=e($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="record_attendance"><label for="attendance-student-search">Student</label><input id="attendance-student-search" type="text" data-student-search="attendance-student-id" placeholder="Enter student full name" autocomplete="off" required><select id="attendance-student-id" name="student_id" hidden><option value="">Select student</option><?php foreach($students as $student):?><option value="<?=e((string)$student['id'])?>" data-student-name="<?=e($student['first_name'].' '.$student['last_name'])?>"><?=e($student['first_name'].' '.$student['last_name'])?></option><?php endforeach;?></select><label for="attendance_date">Date</label><input id="attendance_date" type="date" name="attendance_date" value="<?=e(date('Y-m-d'))?>" required><label for="attendance_status">Status</label><select id="attendance_status" name="attendance_status" required><option value="Present">Present</option><option value="Absent">Absent</option><option value="Late">Late</option></select><br><br><button type="submit">Save attendance</button></form></section>
    <section class="card"><h2>Today's attendance</h2><?php if($todayAttendance):?><div class="table-wrap"><table><thead><tr><th>Student</th><th>Class</th><th>Status</th></tr></thead><tbody><?php foreach($todayAttendance as $row):?><tr><td><?=e($row['first_name'].' '.$row['last_name'])?></td><td><?=e($row['class_name'])?></td><td class="<?=e($row['attendance_status'])?>"><?=e($row['attendance_status'])?></td></tr><?php endforeach;?></tbody></table></div><?php else:?><p class="intro">No attendance has been recorded today.</p><?php endif;?></section>
</div>
</main>
<script src="assets/student-search.js" defer></script>
</body></html>
