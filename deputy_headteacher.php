<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
if (($_SESSION['user']['role'] ?? '') !== 'Deputy Headteacher') {
    http_response_code(403);
    exit('Deputy Headteacher access is required.');
}

$connection = db();
ensure_exam_tables();
ensure_discipline_tables();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function deputy_post_value(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(403);
        exit('Your session token expired. Refresh the page and try again.');
    }

    try {
        $action = deputy_post_value('action');
        if ($action === 'record_incident') {
            $studentId = filter_var($_POST['student_id'] ?? '', FILTER_VALIDATE_INT);
            $incidentDate = deputy_post_value('incident_date');
            $incidentType = deputy_post_value('incident_type');
            $description = deputy_post_value('description');
            $actionTaken = deputy_post_value('action_taken');
            $parsedDate = DateTime::createFromFormat('!Y-m-d', $incidentDate);

            if (!$studentId || $studentId < 1
                || !$parsedDate || $parsedDate->format('Y-m-d') !== $incidentDate
                || $incidentType === '' || strlen($incidentType) > 80
                || $description === '' || strlen($description) > 10000
                || strlen($actionTaken) > 10000) {
                throw new InvalidArgumentException('Enter a student, valid incident date and type, and a description.');
            }
            $studentCheck = $connection->prepare('SELECT id FROM students WHERE id = ?');
            $studentCheck->execute([$studentId]);
            if (!$studentCheck->fetchColumn()) {
                throw new InvalidArgumentException('The selected student could not be found.');
            }

            $insert = $connection->prepare(
                'INSERT INTO discipline_records
                    (student_id, incident_date, incident_type, description, action_taken, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $studentId,
                $incidentDate,
                $incidentType,
                $description,
                $actionTaken !== '' ? $actionTaken : null,
                (int) $_SESSION['user']['id'],
            ]);
            $message = 'Discipline incident recorded.';
        } elseif ($action === 'resolve_incident') {
            $incidentId = filter_var($_POST['incident_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$incidentId || $incidentId < 1) {
                throw new InvalidArgumentException('Select a valid discipline record.');
            }
            $resolve = $connection->prepare(
                "UPDATE discipline_records SET status = 'Resolved' WHERE id = ? AND status = 'Open'"
            );
            $resolve->execute([$incidentId]);
            if ($resolve->rowCount() !== 1) {
                throw new InvalidArgumentException('That discipline record is no longer open.');
            }
            $message = 'Discipline record marked as resolved.';
        } else {
            throw new InvalidArgumentException('Choose a supported discipline action.');
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }
}

$schoolCounts = $connection->query(
    "SELECT
        (SELECT COUNT(*) FROM students) AS students,
        (SELECT COUNT(DISTINCT class_name) FROM students WHERE class_name IS NOT NULL AND class_name <> '') AS classes,
        (SELECT COUNT(*) FROM attendance
          WHERE attendance_date = CURRENT_DATE AND attendance_status = 'Absent') AS absent_today,
        (SELECT COUNT(*) FROM discipline_records WHERE status = 'Open') AS open_incidents,
        (SELECT COUNT(*) FROM exams) AS exams"
)->fetch();

$students = $connection->query(
    'SELECT id, admission_number, first_name, last_name, class_name
     FROM students ORDER BY class_name, last_name, first_name LIMIT 100'
)->fetchAll();

$studentOptions = $connection->query(
    'SELECT id, admission_number, first_name, last_name, class_name
     FROM students ORDER BY class_name, last_name, first_name'
)->fetchAll();

$attendanceRows = $connection->query(
    "SELECT students.first_name, students.last_name, students.admission_number,
            students.class_name, attendance.attendance_date, attendance.attendance_status
     FROM attendance
     JOIN students ON students.id = attendance.student_id
     WHERE attendance.attendance_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)
     ORDER BY attendance.attendance_date DESC, students.class_name, students.last_name
     LIMIT 50"
)->fetchAll();

$academicRows = $connection->query(
    "SELECT exams.id, exams.title, exams.class_name, exams.term, exams.academic_year,
            exams.exam_date, exams.status,
            COUNT(CASE WHEN student_marks.status = 'Validated' THEN student_marks.id END) AS validated_marks,
            ROUND(AVG(CASE WHEN student_marks.status = 'Validated'
                THEN student_marks.marks_obtained / exam_subjects.max_marks * 100 END), 2) AS average_percentage
     FROM exams
     LEFT JOIN exam_subjects ON exam_subjects.exam_id = exams.id
     LEFT JOIN student_marks
       ON student_marks.exam_id = exams.id
      AND student_marks.subject_id = exam_subjects.subject_id
     GROUP BY exams.id, exams.title, exams.class_name, exams.term,
              exams.academic_year, exams.exam_date, exams.status
     ORDER BY exams.exam_date DESC, exams.id DESC
     LIMIT 20"
)->fetchAll();

$disciplineRows = $connection->query(
    'SELECT discipline_records.id, discipline_records.incident_date,
            discipline_records.incident_type, discipline_records.description,
            discipline_records.action_taken, discipline_records.status,
            students.first_name, students.last_name, students.admission_number,
            students.class_name, users.full_name AS recorded_by_name
     FROM discipline_records
     JOIN students ON students.id = discipline_records.student_id
     LEFT JOIN users ON users.id = discipline_records.recorded_by
     ORDER BY discipline_records.incident_date DESC, discipline_records.id DESC
     LIMIT 50'
)->fetchAll();

$schoolName = system_setting('school_name', 'Mandela International French School');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Deputy Headteacher | <?= e($schoolName) ?> IMS</title>
    <style>
        :root{--ink:#172b4d;--muted:#718096;--line:#e5edf5;--navy:#0b2d55;--blue:#1763a6}
        *{box-sizing:border-box}
        body{margin:0;color:var(--ink);background:#f2f6fb;font:14px Arial,sans-serif}
        a{color:inherit;text-decoration:none}
        header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:17px max(5%,calc((100% - 1280px)/2));color:#fff;background:linear-gradient(110deg,#092b4c,#15558a 70%,#218c89)}
        header nav{display:flex;gap:8px;flex-wrap:wrap}
        header a{padding:9px 12px;border:1px solid rgba(255,255,255,.22);border-radius:7px;color:#fff}
        header a:hover{background:rgba(255,255,255,.13)}
        main{max-width:1280px;margin:auto;padding:28px 26px 50px}
        .hero{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:25px 30px;border-radius:14px;color:#fff;background:linear-gradient(115deg,#124b75,#218c89)}
        .eyebrow{margin:0 0 8px;color:#c9f1e9;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase}
        h1{margin:0;font-size:29px}.hero p:last-child{margin:10px 0 0;color:#e0f1f2;line-height:1.5}
        .date{padding:10px 14px;border:1px solid rgba(255,255,255,.3);border-radius:7px;white-space:nowrap}
        .stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin:20px 0}
        .stat,.card{padding:20px;border:1px solid var(--line);border-radius:11px;background:#fff}
        .stat small{display:block;color:var(--muted)}.stat strong{display:block;margin-top:10px;font-size:27px}
        .card{margin-top:18px}.card h2{margin:0 0 8px;font-size:18px}
        .subtext{margin:0 0 16px;color:var(--muted);font-size:12px;line-height:1.5}
        .grid{display:grid;grid-template-columns:minmax(280px,.8fr) minmax(0,1.6fr);gap:18px;align-items:start}
        label{display:block;margin:14px 0 6px;font-size:12px;font-weight:700}
        input,select,textarea{width:100%;padding:10px 11px;border:1px solid #d5dfeb;border-radius:6px;color:var(--ink);font:inherit}
        textarea{min-height:85px;resize:vertical}
        button{padding:9px 12px;border:0;border-radius:6px;color:#fff;background:var(--blue);cursor:pointer;font:inherit;font-weight:700}
        button:hover{background:#104f87}.resolve{padding:6px 9px;background:#23805c;font-size:11px}
        .message,.error{margin:16px 0;padding:11px 13px;border-radius:6px}
        .message{color:#17623e;background:#e8f7f0}.error{color:#9d3636;background:#fff1f1}
        .table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:650px}
        th{padding:9px 8px;color:#8998aa;font-size:10px;text-align:left;text-transform:uppercase;letter-spacing:.5px}
        td{padding:11px 8px;border-top:1px solid #edf1f5;color:#42526e;font-size:12px;vertical-align:top}
        td strong{display:block;color:var(--ink);font-size:13px}.muted{display:block;margin-top:4px;color:var(--muted);font-size:11px}
        .badge{display:inline-block;padding:5px 8px;border-radius:14px;color:#1763a6;background:#eaf3fb;font-size:11px;font-weight:700;white-space:nowrap}
        .present,.resolved{color:#267153;background:#e8f7f0}.absent,.open{color:#a33a3a;background:#fff0f0}.late{color:#94621f;background:#fff4df}
        .empty{padding:20px;color:var(--muted);text-align:center}
        @media(max-width:900px){.stats{grid-template-columns:repeat(3,minmax(0,1fr))}.grid{grid-template-columns:1fr}}
        @media(max-width:600px){header,.hero{align-items:flex-start;flex-direction:column}main{padding:20px 14px 35px}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.stat,.card{padding:15px}.hero{padding:22px}.date{white-space:normal}}
    </style>
</head>
<body>
<header>
    <strong><?= e($schoolName) ?> &middot; Deputy Headteacher</strong>
    <nav><a href="#students">Students</a><a href="#discipline">Discipline</a><a href="#attendance">Attendance</a><a href="#academics">Academics</a><a href="exams.php">Academic reports</a><a href="logout.php">Sign out</a></nav>
</header>
<main>
    <section class="hero">
        <div><p class="eyebrow">School leadership</p><h1>Deputy Headteacher Dashboard</h1><p>Monitor students, manage discipline, review attendance, and follow academic activities.</p></div>
        <div class="date"><?= e(date('l, F j, Y')) ?></div>
    </section>

    <?php if ($message !== ''): ?><p class="message" role="status"><?= e($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <section class="stats" aria-label="School overview">
        <div class="stat"><small>Students</small><strong><?= e((string) $schoolCounts['students']) ?></strong></div>
        <div class="stat"><small>Classes</small><strong><?= e((string) $schoolCounts['classes']) ?></strong></div>
        <div class="stat"><small>Absent today</small><strong><?= e((string) $schoolCounts['absent_today']) ?></strong></div>
        <div class="stat"><small>Open discipline records</small><strong><?= e((string) $schoolCounts['open_incidents']) ?></strong></div>
        <div class="stat"><small>Exam activities</small><strong><?= e((string) $schoolCounts['exams']) ?></strong></div>
    </section>

    <section class="card" id="students">
        <h2>Monitor students</h2>
        <p class="subtext">Student directory grouped by class. Showing up to 100 students.</p>
        <div class="table-wrap"><table><thead><tr><th>Student</th><th>Admission number</th><th>Class</th></tr></thead><tbody>
        <?php foreach ($studentOptions as $student): ?>
            <tr><td><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong></td><td><?= e($student['admission_number']) ?></td><td><?= e($student['class_name'] ?: 'Unassigned') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$students): ?><tr><td colspan="3" class="empty">No student records are available.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="card" id="discipline">
        <h2>Manage discipline</h2>
        <p class="subtext">Record student incidents and track follow-up. Records are visible to school staff with access to this workspace.</p>
        <div class="grid">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="record_incident">
                <label for="discipline-student-search">Student</label>
                <input id="discipline-student-search" type="text"
                       data-student-search="discipline-student-id"
                       placeholder="Enter student full name" autocomplete="off" required>
                <select id="discipline-student-id" name="student_id" hidden>
                    <option value="">Select a student</option>
                    <?php foreach ($studentOptions as $student): ?>
                    <option value="<?= e((string) $student['id']) ?>"
                            data-student-name="<?= e($student['first_name'] . ' ' . $student['last_name']) ?>"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="incident_date">Incident date</label>
                <input id="incident_date" type="date" name="incident_date" value="<?= e(date('Y-m-d')) ?>" required>
                <label for="incident_type">Incident type</label>
                <input id="incident_type" name="incident_type" maxlength="80" required placeholder="e.g. Classroom disruption">
                <label for="description">Incident details</label>
                <textarea id="description" name="description" maxlength="10000" required></textarea>
                <label for="action_taken">Action taken / follow-up</label>
                <textarea id="action_taken" name="action_taken" maxlength="10000"></textarea>
                <button type="submit">Record incident</button>
            </form>
            <div class="table-wrap"><table><thead><tr><th>Date / student</th><th>Incident / follow-up</th><th>Status</th><th>Manage</th></tr></thead><tbody>
            <?php foreach ($disciplineRows as $record): ?>
                <tr>
                    <td><?= e($record['incident_date']) ?><strong><?= e($record['first_name'] . ' ' . $record['last_name']) ?></strong><span class="muted"><?= e($record['class_name'] ?: 'Unassigned') ?> &middot; <?= e($record['admission_number']) ?></span></td>
                    <td><strong><?= e($record['incident_type']) ?></strong><?= e($record['description']) ?><span class="muted">Action: <?= e($record['action_taken'] ?: 'Not recorded') ?> &middot; By <?= e($record['recorded_by_name'] ?: 'Former account') ?></span></td>
                    <td><span class="badge <?= strtolower($record['status']) ?>"><?= e($record['status']) ?></span></td>
                    <td><?php if ($record['status'] === 'Open'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="resolve_incident"><input type="hidden" name="incident_id" value="<?= e((string) $record['id']) ?>"><button class="resolve" type="submit">Mark resolved</button></form><?php else: ?><span class="muted">Complete</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$disciplineRows): ?><tr><td colspan="4" class="empty">No discipline records have been entered.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
    </section>

    <section class="card" id="attendance">
        <h2>View attendance</h2>
        <p class="subtext">Student attendance recorded over the last seven days. Showing up to 50 records.</p>
        <div class="table-wrap"><table><thead><tr><th>Date</th><th>Student</th><th>Class</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($attendanceRows as $record): ?>
            <tr><td><?= e($record['attendance_date']) ?></td><td><strong><?= e($record['first_name'] . ' ' . $record['last_name']) ?></strong><span class="muted"><?= e($record['admission_number']) ?></span></td><td><?= e($record['class_name'] ?: 'Unassigned') ?></td><td><span class="badge <?= strtolower($record['attendance_status']) ?>"><?= e($record['attendance_status']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$attendanceRows): ?><tr><td colspan="4" class="empty">No attendance records have been entered for the last seven days.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="card" id="academics">
        <h2>Monitor academic activities</h2>
        <p class="subtext">Recent exams, result publication status, validated marks, and average performance.</p>
        <div class="table-wrap"><table><thead><tr><th>Activity</th><th>Class</th><th>Term / year</th><th>Date</th><th>Validated marks</th><th>Average</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($academicRows as $activity): ?>
            <tr><td><strong><?= e($activity['title']) ?></strong></td><td><?= e($activity['class_name']) ?></td><td><?= e($activity['term'] . ' ' . $activity['academic_year']) ?></td><td><?= e($activity['exam_date']) ?></td><td><?= e((string) $activity['validated_marks']) ?></td><td><?= $activity['average_percentage'] !== null ? e(number_format((float) $activity['average_percentage'], 2) . '%') : '-' ?></td><td><span class="badge <?= $activity['status'] === 'Published' ? 'present' : 'open' ?>"><?= e($activity['status']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$academicRows): ?><tr><td colspan="7" class="empty">Academic activities will appear here when exams are created.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</main>
<script src="assets/student-search.js" defer></script>
</body>
</html>
