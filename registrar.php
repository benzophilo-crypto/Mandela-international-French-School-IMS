<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
if (!has_system_permission('registrar.access')) {
    http_response_code(403);
    exit('Registrar workspace permission is required.');
}

$connection = db();
ensure_pay_code_column();
ensure_timetable_tables();
ensure_staff_attendance_tables();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$createdCredentials = null;
$printTimetable = ($_GET['print_timetable'] ?? '') === '1';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Your session token expired. Refresh and try again.');
        }

        $action = $_POST['action'] ?? '';
        if ($action === 'add_student' && !has_system_permission('students.manage')) {
            throw new RuntimeException('You do not have permission to manage student records.');
        }
        if ($action === 'review_enrollment') {
            $status = $_POST['registration_status'] ?? '';
            if (!in_array($status, ['Approved', 'Rejected'], true)) {
                throw new InvalidArgumentException('Invalid registration status.');
            }
            $statement = $connection->prepare('UPDATE enrollments SET registration_status = ? WHERE id = ?');
            $statement->execute([$status, (int) ($_POST['id'] ?? 0)]);
            $message = 'Registration status updated.';
        } elseif ($action === 'add_student') {
            $admission = trim($_POST['admission_number'] ?? '');
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $className = trim($_POST['class_name'] ?? '');
            $guardianName = trim($_POST['guardian_name'] ?? '');
            $guardianPhone = trim($_POST['guardian_phone'] ?? '');
            $createAccount = isset($_POST['create_account']);
            $studentEmail = strtolower(trim($_POST['student_email'] ?? ''));
            $studentPassword = $_POST['student_password'] ?? '';

            if ($admission === '' || $firstName === '' || $lastName === '' || $className === '') {
                throw new InvalidArgumentException('Admission number, name, and class are required.');
            }
            if ($createAccount && (!filter_var($studentEmail, FILTER_VALIDATE_EMAIL) || strlen($studentPassword) < 8)) {
                throw new InvalidArgumentException('A valid student email and password of at least 8 characters are required.');
            }

            $connection->beginTransaction();
            $statement = $connection->prepare(
                'INSERT INTO students (admission_number, first_name, last_name, class_name, guardian_name, guardian_phone)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$admission, $firstName, $lastName, $className, $guardianName, $guardianPhone]);
            $studentId = (int) $connection->lastInsertId();

            // Generate and persist the pay code immediately after admission
            $payCode = generate_pay_code();
            $connection->prepare('UPDATE students SET pay_code = ? WHERE id = ?')
                       ->execute([$payCode, $studentId]);

            if ($createAccount) {
                $roleId = $connection->query("SELECT id FROM roles WHERE role_name = 'Student' LIMIT 1")->fetchColumn();
                if (!$roleId) {
                    throw new RuntimeException('The Student role is not configured. Ask ICT to create it first.');
                }
                $statement = $connection->prepare(
                    'INSERT INTO users (full_name, email, password_hash, role_id, student_id)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $statement->execute([
                    $firstName . ' ' . $lastName,
                    $studentEmail,
                    password_hash($studentPassword, PASSWORD_DEFAULT),
                    $roleId,
                    $studentId
                ]);
                $createdCredentials = ['email' => $studentEmail, 'password' => $studentPassword];
            }
            $connection->commit();
            $message = 'Student added successfully.' . ($createAccount ? ' Student portal account created.' : '');
        } elseif ($action === 'record_staff_attendance') {
            $staffId = (int) ($_POST['staff_id'] ?? 0);
            $attendanceDate = trim((string) ($_POST['attendance_date'] ?? ''));
            $attendanceStatus = (string) ($_POST['attendance_status'] ?? '');
            $checkIn = trim((string) ($_POST['check_in'] ?? ''));
            $checkOut = trim((string) ($_POST['check_out'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $parsedDate = DateTime::createFromFormat('!Y-m-d', $attendanceDate);
            if ($staffId < 1 || !$parsedDate || $parsedDate->format('Y-m-d') !== $attendanceDate || $attendanceDate > date('Y-m-d')) {
                throw new InvalidArgumentException('Select a teacher and a valid attendance date that is not in the future.');
            }
            if (!in_array($attendanceStatus, ['Present', 'Late', 'Absent', 'Leave'], true)) {
                throw new InvalidArgumentException('Select a valid staff attendance status.');
            }
            $teacherCheck = $connection->prepare(
                "SELECT COUNT(*) FROM users
                 JOIN roles ON roles.id = users.role_id
                 WHERE users.id = ? AND roles.role_name = 'Teacher'"
            );
            $teacherCheck->execute([$staffId]);
            if ((int) $teacherCheck->fetchColumn() !== 1) {
                throw new InvalidArgumentException('Manual staff attendance can only be entered for a teacher account.');
            }

            $parseTime = static function (string $time): ?string {
                if ($time === '') {
                    return null;
                }
                $parsed = DateTime::createFromFormat('!H:i', $time);
                return $parsed && $parsed->format('H:i') === $time ? $time . ':00' : null;
            };
            $checkInValue = $parseTime($checkIn);
            $checkOutValue = $parseTime($checkOut);
            if (($checkIn !== '' && $checkInValue === null) || ($checkOut !== '' && $checkOutValue === null)) {
                throw new InvalidArgumentException('Enter valid check-in and check-out times.');
            }
            if ($checkInValue !== null && $checkOutValue !== null && $checkOutValue <= $checkInValue) {
                throw new InvalidArgumentException('Check-out time must be later than check-in time.');
            }

            $statement = $connection->prepare(
                "INSERT INTO staff_attendance
                    (staff_id, attendance_date, attendance_status, check_in, check_out, source, notes, recorded_by)
                 VALUES (?, ?, ?, ?, ?, 'Manual', ?, ?)"
            );
            $statement->execute([
                $staffId,
                $attendanceDate,
                $attendanceStatus,
                $checkInValue,
                $checkOutValue,
                $notes !== '' ? $notes : null,
                (int) $_SESSION['user']['id']
            ]);
            $message = 'Teacher attendance recorded.';
        } elseif ($action === 'add_timetable') {
            // Add a simple timetable entry
            $className = trim((string) ($_POST['class_name'] ?? ''));
            $subjectName = trim((string) ($_POST['subject_name'] ?? ''));
            $teacherId = isset($_POST['teacher_id']) && $_POST['teacher_id'] !== '' ? (int) $_POST['teacher_id'] : null;
            $dayOfWeek = trim((string) ($_POST['day_of_week'] ?? ''));
            $startTime = trim((string) ($_POST['start_time'] ?? ''));
            $endTime = trim((string) ($_POST['end_time'] ?? ''));
            $term = trim((string) ($_POST['term'] ?? ''));
            $academicYear = trim((string) ($_POST['academic_year'] ?? ''));

            if ($className === '' || $subjectName === '' || $dayOfWeek === '' || $startTime === '' || $endTime === '' || $term === '' || $academicYear === '') {
                throw new InvalidArgumentException('Class, subject, day, start/end time, term and academic year are required.');
            }

            $s = DateTime::createFromFormat('H:i', $startTime);
            $e = DateTime::createFromFormat('H:i', $endTime);
            if (!$s || !$e || $s >= $e) {
                throw new InvalidArgumentException('Enter valid start and end times (HH:MM) with start before end.');
            }

            $stmt = $connection->prepare(
                'INSERT INTO timetable_entries (class_name, subject_name, teacher_id, day_of_week, start_time, end_time, term, academic_year, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $className,
                $subjectName,
                $teacherId,
                $dayOfWeek,
                $startTime,
                $endTime,
                $term,
                $academicYear,
                $_SESSION['user']['id'] ?? null
            ]);

            $message = 'Timetable entry added.';
        }
    }
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    $createdCredentials = null;
    $error = $exception instanceof PDOException && $exception->getCode() === '23000'
        ? ((($action ?? '') === 'record_staff_attendance')
            ? 'Attendance has already been recorded for this teacher on that date.'
            : 'That admission number or student email is already in use.')
        : $exception->getMessage();
}

$students = $connection->query(
    'SELECT admission_number, first_name, last_name, class_name, guardian_name
     FROM students ORDER BY class_name, last_name, first_name'
)->fetchAll();
$enrollments = $connection->query(
    'SELECT enrollments.id, enrollments.term, enrollments.academic_year, enrollments.report_date,
            enrollments.registration_status, students.first_name, students.last_name, students.admission_number
     FROM enrollments JOIN students ON students.id = enrollments.student_id
     ORDER BY enrollments.created_at DESC'
)->fetchAll();
$registrationCounts = [
    'registered_students' => (int) $connection->query('SELECT COUNT(*) FROM students')->fetchColumn(),
    'enrollment_records' => (int) $connection->query('SELECT COUNT(*) FROM enrollments')->fetchColumn(),
    'submitted' => (int) $connection->query("SELECT COUNT(*) FROM enrollments WHERE registration_status = 'Submitted'")->fetchColumn(),
    'approved' => (int) $connection->query("SELECT COUNT(*) FROM enrollments WHERE registration_status = 'Approved'")->fetchColumn(),
    'rejected' => (int) $connection->query("SELECT COUNT(*) FROM enrollments WHERE registration_status = 'Rejected'")->fetchColumn(),
    'timetable_entries' => (int) $connection->query('SELECT COUNT(*) FROM timetable_entries')->fetchColumn()
];

// Load teachers for the timetable form
$teachers = $connection->query(
    "SELECT users.id, users.full_name FROM users
     JOIN roles ON roles.id = users.role_id
     WHERE roles.role_name = 'Teacher' ORDER BY users.full_name"
)->fetchAll();

$staffAttendanceDate = trim((string) ($_GET['staff_attendance_date'] ?? date('Y-m-d')));
$parsedStaffAttendanceDate = DateTime::createFromFormat('!Y-m-d', $staffAttendanceDate);
if (!$parsedStaffAttendanceDate || $parsedStaffAttendanceDate->format('Y-m-d') !== $staffAttendanceDate) {
    $staffAttendanceDate = date('Y-m-d');
}
$staffAttendanceSummaryStatement = $connection->prepare(
    "SELECT
        COUNT(*) AS recorded_count,
        SUM(CASE WHEN attendance_status = 'Present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN attendance_status = 'Late' THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN attendance_status = 'Absent' THEN 1 ELSE 0 END) AS absent_count,
        SUM(CASE WHEN attendance_status = 'Leave' THEN 1 ELSE 0 END) AS leave_count
     FROM staff_attendance WHERE attendance_date = ?"
);
$staffAttendanceSummaryStatement->execute([$staffAttendanceDate]);
$staffAttendanceSummary = $staffAttendanceSummaryStatement->fetch();
$staffAttendanceStatement = $connection->prepare(
    "SELECT staff_attendance.id, staff_attendance.attendance_date, staff_attendance.attendance_status,
            staff_attendance.check_in, staff_attendance.check_out, staff_attendance.source, staff_attendance.notes,
            staff.full_name, staff.email, recorder.full_name AS recorded_by_name
     FROM staff_attendance
     JOIN users staff ON staff.id = staff_attendance.staff_id
     LEFT JOIN users recorder ON recorder.id = staff_attendance.recorded_by
     WHERE staff_attendance.attendance_date = ?
     ORDER BY FIELD(staff_attendance.attendance_status, 'Absent','Late','Leave','Present'), staff.full_name"
);
$staffAttendanceStatement->execute([$staffAttendanceDate]);
$staffAttendanceRows = $staffAttendanceStatement->fetchAll();

// Load existing timetable entries for display
$timetableEntries = $connection->query(
    "SELECT te.*, u.full_name AS teacher_name
     FROM timetable_entries te
     LEFT JOIN users u ON u.id = te.teacher_id
     ORDER BY te.academic_year DESC, te.term DESC,
       FIELD(te.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),
       te.start_time"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registrar Workspace | MIFS IMS</title>
    <style>
        :root { --navy: #103b67; --navy-dark: #092b4c; --ink: #172b4d; --muted: #718096; --line: #e5edf5; --surface: #fff; }
        * { box-sizing: border-box; }
        body { margin: 0; color: var(--ink); background: #f2f6fb; font: 14px Arial, sans-serif; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 17px max(5%, calc((100% - 1240px) / 2)); color: #fff; background: linear-gradient(110deg, var(--navy-dark), #15558a 72%, #2182a1); box-shadow: 0 4px 16px rgba(9, 43, 76, .18); }
        header strong { letter-spacing: .2px; }
        header nav { display: flex; gap: 8px; }
        header a { padding: 9px 12px; border: 1px solid rgba(255,255,255,.2); border-radius: 7px; color: #f2f8ff; text-decoration: none; transition: background .2s, border-color .2s; }
        header a:hover { border-color: rgba(255,255,255,.5); background: rgba(255,255,255,.13); }
        main { max-width: 1240px; margin: auto; padding: 34px 28px 50px; }
        .hero { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 24px; padding: 29px 32px; overflow: hidden; border-radius: 15px; color: #fff; background: linear-gradient(115deg, #114477, #197da0 70%, #3b9f99); box-shadow: 0 12px 28px rgba(17, 68, 119, .16); }
        .hero::after { position: absolute; top: -74px; right: 17%; width: 220px; height: 220px; border: 1px solid rgba(255,255,255,.16); border-radius: 50%; box-shadow: 0 0 0 28px rgba(255,255,255,.04), 0 0 0 58px rgba(255,255,255,.035); content: ''; }
        .hero-copy, .hero-date { position: relative; z-index: 1; }
        .eyebrow { margin: 0 0 8px; color: #ffe29b; font-size: 11px; font-weight: 700; letter-spacing: 1.7px; text-transform: uppercase; }
        h1 { margin: 0 0 7px; font-size: clamp(26px, 3vw, 34px); letter-spacing: -.5px; }
        .hero .intro { max-width: 650px; margin: 0; color: #d7e8f7; line-height: 1.55; }
        .hero-date { padding: 12px 16px; border: 1px solid rgba(255,255,255,.25); border-radius: 9px; color: #fff; background: rgba(255,255,255,.11); font-size: 12px; white-space: nowrap; }
        .summary { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 15px; margin: 20px 0 26px; }
        .metric { position: relative; min-height: 116px; padding: 18px; overflow: hidden; border: 1px solid var(--line); border-radius: 11px; background: var(--surface); box-shadow: 0 5px 15px rgba(27, 55, 90, .045); }
        .metric::before { position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: var(--metric-color, #4389c4); content: ''; }
        .metric:nth-child(2) { --metric-color: #7866c1; }
        .metric:nth-child(3) { --metric-color: #e3a232; }
        .metric:nth-child(4) { --metric-color: #42a17e; }
        .metric:nth-child(5) { --metric-color: #dd6c72; }
        .metric-label { display: flex; align-items: center; justify-content: space-between; gap: 8px; color: var(--muted); font-size: 11px; font-weight: 700; }
        .metric-icon { display: grid; width: 31px; height: 31px; place-items: center; border-radius: 9px; color: var(--metric-color, #4389c4); background: #eaf3fb; font-size: 10px; font-weight: 800; }
        .metric:nth-child(2) .metric-icon { background: #f0edfa; }
        .metric:nth-child(3) .metric-icon { background: #fff4df; }
        .metric:nth-child(4) .metric-icon { background: #e8f7f0; }
        .metric:nth-child(5) .metric-icon { background: #fff0f0; }
        .metric strong { display: block; margin-top: 8px; color: var(--ink); font-size: 27px; }
        .card { margin-bottom: 20px; padding: 22px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); box-shadow: 0 5px 18px rgba(27, 55, 90, .045); }
        .card h2 { display: flex; align-items: center; gap: 10px; margin: 0 0 7px; font-size: 17px; }
        .card h2::before { width: 4px; height: 19px; border-radius: 4px; background: #e7b63f; content: ''; }
        .intro { color: var(--muted); line-height: 1.5; }
        label { display: block; margin: 12px 0 5px; color: #52637a; font-size: 12px; font-weight: 700; }
        input, select { width: 100%; padding: 10px 11px; border: 1px solid #d8e2ed; border-radius: 7px; color: var(--ink); background: #fbfdff; font: inherit; transition: border-color .15s, box-shadow .15s; }
        input:focus, select:focus { border-color: #4b8dc4; outline: none; box-shadow: 0 0 0 3px rgba(75,141,196,.13); }
        input[type="checkbox"] { width: auto; accent-color: #1763a6; }
        button { padding: 9px 13px; border: 0; border-radius: 7px; color: #fff; background: linear-gradient(120deg, #1763a6, #2382a5); box-shadow: 0 4px 9px rgba(23,99,166,.14); cursor: pointer; font: inherit; font-weight: 700; transition: transform .15s, box-shadow .15s; }
        button:hover { box-shadow: 0 6px 13px rgba(23,99,166,.22); transform: translateY(-1px); }
        button.reject { background: linear-gradient(120deg, #b94747, #d25e60); }
        .notice { padding: 13px 16px; margin: 16px 0; border: 1px solid #c8e9d9; border-radius: 8px; color: #267153; background: #eaf8f1; }
        .error { border-color: #f1d1d1; color: #a33a3a; background: #fff1f1; }
        .status-present { color: #267153; background: #e8f7f0; }
        .status-late { color: #94621f; background: #fff4df; }
        .status-absent { color: #a33a3a; background: #fff0f0; }
        .status-leave { color: #5d55a0; background: #f0edfa; }
        .source-badge { display: inline-block; padding: 4px 8px; border-radius: 12px; color: #1763a6; background: #eaf3fb; font-size: 11px; font-weight: 700; }
        .attendance-toolbar { display: flex; align-items: end; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .attendance-toolbar form { display: flex; align-items: end; gap: 10px; }
        .attendance-toolbar label { margin-top: 0; }
        .attendance-toolbar input { width: auto; }
        .credentials { border-left: 4px solid #d99b2b; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 11px 10px; color: #76879c; background: #f6f9fc; font-size: 10px; letter-spacing: .6px; text-align: left; text-transform: uppercase; }
        th:first-child { border-radius: 7px 0 0 7px; } th:last-child { border-radius: 0 7px 7px 0; }
        td { padding: 12px 10px; border-bottom: 1px solid #edf1f5; color: #40536d; }
        tr:hover td { background: #fbfdff; }
        td strong { display: block; color: var(--ink); }
        .badge { display: inline-block; padding: 5px 9px; border-radius: 12px; color: #1763a6; background: #eaf3fb; font-size: 11px; font-weight: 700; }
        .table-wrap { overflow-x: auto; margin-top: 14px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 18px; }
        .full { grid-column: 1 / -1; }
        .timetable-print-action a { display: inline-block; padding: 9px 13px; border-radius: 7px; color: #fff; background: linear-gradient(120deg, #1763a6, #2382a5); font-weight: 700; text-decoration: none; }
        .print-heading { display: none; }
        @media print {
            @page { size: landscape; margin: 12mm; }
            body { color: #000; background: #fff; }
            body * { visibility: hidden !important; }
            #timetable-section, #timetable-section * { visibility: visible !important; }
            #timetable-section { position: absolute; inset: 0; width: 100%; margin: 0; padding: 0; border: 0; box-shadow: none; }
            #timetable-section .timetable-entry-form, #timetable-section .timetable-print-action { display: none !important; }
            #timetable-section .print-heading { display: block; margin-bottom: 18px; text-align: center; }
            #timetable-section .print-heading h1 { margin: 0 0 6px; font-size: 22px; }
            #timetable-section .print-heading p { margin: 0; color: #333; }
            #timetable-section h2, #timetable-section > .intro { display: none; }
            #timetable-section .table-wrap { overflow: visible; margin: 0; }
            #timetable-section table { font-size: 11px; }
            #timetable-section th, #timetable-section td { padding: 7px; border: 1px solid #777; color: #000; background: #fff; }
            #timetable-section th { font-weight: 700; }
            #timetable-section tr { break-inside: avoid; }
        }
        <?php if ($printTimetable): ?>
        @media screen {
            body.print-timetable > header, body.print-timetable > main > :not(#timetable-section) { display: none; }
            body.print-timetable > main { padding: 0; }
            body.print-timetable #timetable-section { max-width: 1240px; margin: 30px auto; }
        }
        <?php endif; ?>
        @media (max-width: 900px) { .summary { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 650px) { header { align-items: flex-start; flex-direction: column; padding: 16px 5%; } header nav { flex-wrap: wrap; } main { padding: 22px 5% 36px; } .hero { align-items: flex-start; flex-direction: column; padding: 23px; } .hero-date { white-space: normal; } .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } .metric { padding: 14px; } .card { padding: 17px; } .form-grid { grid-template-columns: 1fr; } .full { grid-column: auto; } }
        @media (max-width: 390px) { .summary { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="<?= $printTimetable ? 'print-timetable' : '' ?>">
<header><strong>MIFS IMS &middot; Registrar Workspace</strong><nav><a href="dashboard.php">Dashboard</a> &nbsp; | &nbsp; <a href="exams.php">Exams &amp; Results</a> &nbsp; | &nbsp; <a href="logout.php">Sign out</a></nav></header>
<main>
    <section class="hero">
        <div class="hero-copy">
            <p class="eyebrow">Registrar workspace</p>
            <h1>Student registry</h1>
            <p class="intro">Manage admissions, enrollment reviews, class assignments, and the school timetable from one place.</p>
        </div>
        <div class="hero-date"><?= e(date('l, F j, Y')) ?></div>
    </section>
    <?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($createdCredentials): ?><div class="notice credentials"><strong>Student portal credentials</strong><br>Email: <?= e($createdCredentials['email']) ?><br>Password: <?= e($createdCredentials['password']) ?></div><?php endif; ?>

    <section class="summary">
        <div class="metric"><div class="metric-label">Registered students <span class="metric-icon">ST</span></div><strong><?= e((string) $registrationCounts['registered_students']) ?></strong></div>
        <div class="metric"><div class="metric-label">Enrollment records <span class="metric-icon">EN</span></div><strong><?= e((string) $registrationCounts['enrollment_records']) ?></strong></div>
        <div class="metric"><div class="metric-label">Awaiting review <span class="metric-icon">RV</span></div><strong><?= e((string) $registrationCounts['submitted']) ?></strong></div>
        <div class="metric"><div class="metric-label">Approved <span class="metric-icon">OK</span></div><strong><?= e((string) $registrationCounts['approved']) ?></strong></div>
        <div class="metric"><div class="metric-label">Rejected <span class="metric-icon">RJ</span></div><strong><?= e((string) $registrationCounts['rejected']) ?></strong></div>
    </section>

    <section class="card" id="staff-attendance">
        <div class="attendance-toolbar">
            <div>
                <h2>Staff attendance</h2>
                <p class="intro">Review teacher attendance captured from the attendance machine and enter records manually when needed.</p>
            </div>
            <form method="get">
                <div><label for="staff_attendance_date">Attendance date</label><input id="staff_attendance_date" type="date" name="staff_attendance_date" value="<?= e($staffAttendanceDate) ?>"></div>
                <button type="submit">View date</button>
            </form>
        </div>
        <div class="summary" style="grid-template-columns:repeat(5,minmax(0,1fr));margin:14px 0;">
            <div class="metric"><div class="metric-label">Records <span class="metric-icon">ALL</span></div><strong><?= e((string)($staffAttendanceSummary['recorded_count'] ?? 0)) ?></strong></div>
            <div class="metric"><div class="metric-label">Present <span class="metric-icon">P</span></div><strong><?= e((string)($staffAttendanceSummary['present_count'] ?? 0)) ?></strong></div>
            <div class="metric"><div class="metric-label">Late <span class="metric-icon">L</span></div><strong><?= e((string)($staffAttendanceSummary['late_count'] ?? 0)) ?></strong></div>
            <div class="metric"><div class="metric-label">Absent <span class="metric-icon">A</span></div><strong><?= e((string)($staffAttendanceSummary['absent_count'] ?? 0)) ?></strong></div>
            <div class="metric"><div class="metric-label">On leave <span class="metric-icon">LV</span></div><strong><?= e((string)($staffAttendanceSummary['leave_count'] ?? 0)) ?></strong></div>
        </div>
        <div class="table-wrap">
            <table><thead><tr><th>Teacher</th><th>Status</th><th>Check in</th><th>Check out</th><th>Source</th><th>Recorded by</th><th>Notes</th></tr></thead><tbody>
            <?php foreach ($staffAttendanceRows as $attendance): ?>
                <?php $statusClass = 'status-' . strtolower($attendance['attendance_status']); ?>
                <tr>
                    <td><strong><?= e($attendance['full_name']) ?></strong><?= e($attendance['email']) ?></td>
                    <td><span class="source-badge <?= e($statusClass) ?>"><?= e($attendance['attendance_status']) ?></span></td>
                    <td><?= e($attendance['check_in'] ? substr($attendance['check_in'], 0, 5) : '-') ?></td>
                    <td><?= e($attendance['check_out'] ? substr($attendance['check_out'], 0, 5) : '-') ?></td>
                    <td><span class="source-badge"><?= e($attendance['source']) ?></span></td>
                    <td><?= e($attendance['recorded_by_name'] ?: ($attendance['source'] === 'Machine' ? 'Attendance machine' : 'Not available')) ?></td>
                    <td><?= e($attendance['notes'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$staffAttendanceRows): ?><tr><td colspan="7" class="intro">No staff attendance records for this date.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </section>

    <section class="card">
        <h2>Manually record teacher attendance</h2>
        <p class="intro">Use this when a teacher’s attendance was not captured by the machine. Duplicate teacher/date records are rejected to protect captured attendance.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="record_staff_attendance">
            <div><label for="attendance_staff_id">Teacher</label><select id="attendance_staff_id" name="staff_id" required><option value="">Select teacher</option><?php foreach ($teachers as $teacher): ?><option value="<?= e((string)$teacher['id']) ?>"><?= e($teacher['full_name']) ?></option><?php endforeach; ?></select></div>
            <div><label for="staff_attendance_entry_date">Attendance date</label><input id="staff_attendance_entry_date" type="date" name="attendance_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e($staffAttendanceDate) ?>" required></div>
            <div><label for="attendance_status">Status</label><select id="attendance_status" name="attendance_status" required><option value="Present">Present</option><option value="Late">Late</option><option value="Absent">Absent</option><option value="Leave">Leave</option></select></div>
            <div><label for="check_in">Check-in time (optional)</label><input id="check_in" type="time" name="check_in"></div>
            <div><label for="check_out">Check-out time (optional)</label><input id="check_out" type="time" name="check_out"></div>
            <div><label for="staff_attendance_notes">Notes (optional)</label><input id="staff_attendance_notes" name="notes" maxlength="255"></div>
            <div class="full"><button type="submit">Record teacher attendance</button></div>
        </form>
    </section>

    <section class="card">
        <h2>Manually add a student</h2>
        <p class="intro">Use this for students who have not registered through the Student Portal.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add_student">
            <div><label>Admission number</label><input name="admission_number" required></div>
            <div><label>Class</label><input name="class_name" required></div>
            <div><label>First name</label><input name="first_name" required></div>
            <div><label>Last name</label><input name="last_name" required></div>
            <div><label>Guardian name</label><input name="guardian_name"></div>
            <div><label>Guardian phone</label><input name="guardian_phone"></div>
            <div class="full"><label><input type="checkbox" name="create_account" value="1"> Create Student Portal account</label></div>
            <div><label>Student email</label><input type="email" name="student_email"></div>
            <div><label>Password (minimum 8 characters)</label><input type="password" name="student_password" minlength="8"></div>
            <div class="full"><button type="submit">Add student</button></div>
        </form>
    </section>

    <section class="card">
        <h2>Term enrollment submissions (<?= e((string) count($enrollments)) ?>)</h2>
        <div class="table-wrap"><table><thead><tr><th>Student</th><th>Term</th><th>Report date</th><th>Status</th><th>Review</th></tr></thead><tbody>
        <?php foreach ($enrollments as $enrollment): ?><tr><td><strong><?= e($enrollment['first_name'] . ' ' . $enrollment['last_name']) ?></strong><?= e($enrollment['admission_number']) ?></td><td><?= e($enrollment['term'] . ' ' . $enrollment['academic_year']) ?></td><td><?= e($enrollment['report_date']) ?></td><td><?= e($enrollment['registration_status']) ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="review_enrollment"><input type="hidden" name="id" value="<?= e((string) $enrollment['id']) ?>"><button name="registration_status" value="Approved" type="submit">Approve</button> <button class="reject" name="registration_status" value="Rejected" type="submit">Reject</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>

    <section class="card">
        <h2>Enrolled students (<?= e((string) count($students)) ?>)</h2>
        <div class="table-wrap"><table><thead><tr><th>Student</th><th>Admission</th><th>Class</th><th>Guardian</th></tr></thead><tbody>
        <?php foreach ($students as $student): ?><tr><td><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong></td><td><span class="badge"><?= e($student['admission_number']) ?></span></td><td><?= e($student['class_name'] ?: 'Not assigned') ?></td><td><?= e($student['guardian_name'] ?: 'Not provided') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>

    <section class="card" id="timetable-section">
        <h2>Timetable entries (<?= e((string) count($timetableEntries)) ?>)</h2>
        <p class="intro">Create and review timetable records used by teachers and the registrar.</p>
        <div class="print-heading">
            <h1>Mandela International French School</h1>
            <p>Class timetable &middot; Printed <?= e(date('F j, Y')) ?></p>
        </div>
        <p class="timetable-print-action"><a class="button" href="?print_timetable=1#timetable-section" target="_blank" rel="noopener">Print / Save timetable as PDF</a> <span class="intro">Choose “Save as PDF” in the print dialog to keep a soft copy.</span></p>
        <form method="post" class="form-grid timetable-entry-form">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add_timetable">
            <div><label>Class name</label><input name="class_name" required></div>
            <div><label>Subject</label><input name="subject_name" required></div>
            <div><label>Teacher</label>
                <select name="teacher_id"><option value="">Unassigned</option><?php foreach ($teachers as $t): ?><option value="<?= e((string) $t['id']) ?>"><?= e($t['full_name']) ?></option><?php endforeach; ?></select>
            </div>
            <div><label>Day of week</label>
                <select name="day_of_week" required>
                    <option>Monday</option>
                    <option>Tuesday</option>
                    <option>Wednesday</option>
                    <option>Thursday</option>
                    <option>Friday</option>
                    <option>Saturday</option>
                </select>
            </div>
            <div><label>Start time</label><input type="time" name="start_time" required></div>
            <div><label>End time</label><input type="time" name="end_time" required></div>
            <div><label>Term</label><input name="term" required></div>
            <div><label>Academic year</label><input name="academic_year" required></div>
            <div class="full"><button type="submit">Add timetable entry</button></div>
        </form>

        <div class="table-wrap" style="margin-top:16px;"><table><thead><tr><th>Class</th><th>Subject</th><th>Teacher</th><th>Day</th><th>Time</th><th>Term</th><th>Year</th></tr></thead><tbody>
        <?php foreach ($timetableEntries as $te): ?><tr>
            <td><?= e($te['class_name']) ?></td>
            <td><?= e($te['subject_name']) ?></td>
            <td><?= e($te['teacher_name'] ?? 'Unassigned') ?></td>
            <td><?= e($te['day_of_week']) ?></td>
            <td><?= e(substr($te['start_time'],0,5) . ' - ' . substr($te['end_time'],0,5)) ?></td>
            <td><?= e($te['term']) ?></td>
            <td><?= e($te['academic_year']) ?></td>
        </tr><?php endforeach; ?>
        <?php if (!$timetableEntries): ?><tr><td colspan="7">No timetable entries have been created yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</main>
<?php if ($printTimetable): ?><script>window.addEventListener('load', function () { window.print(); });</script><?php endif; ?>
</body>
</html>
