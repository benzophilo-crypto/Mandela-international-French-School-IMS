<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['user']['role'] ?? '') !== 'Administrator') {
    http_response_code(403);
    exit('Administrator access is required.');
}

$connection = db();
ensure_teacher_role();
ensure_librarian_role();
ensure_head_teacher_role();
ensure_deputy_headteacher_role();
ensure_admin_control_tables();
ensure_teacher_profile_table();
ensure_exam_tables();
ensure_timetable_tables();
ensure_teacher_subject_assignment_table();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$editStudent = null;
$editUser = null;
$action = '';
$announcementMessage = '';
$permissionCatalog = [
    'students.manage' => 'Manage student records',
    'registrar.access' => 'Access Registrar workspace',
    'teacher.access' => 'Access Teacher workspace',
    'finance.access' => 'Access Finance dashboard',
    'inventory.access' => 'Access Inventory workspace',
    'library.access' => 'Access Library workspace',
    'exams.manage' => 'Manage exams and validate marks',
    'reports.view' => 'View academic reports',
    'reports.approve' => 'Approve and publish reports',
];
$settingDefinitions = [
    'school_name' => ['School name', 'Mandela International French School'],
    'academic_year' => ['Current academic year', ''],
    'current_term' => ['Current term', ''],
    'contact_email' => ['School contact email', ''],
    'contact_phone' => ['School contact phone', ''],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download_backup') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Your session token expired. Refresh the page and try again.');
    }
    log_system_event('database_backup', 'Administrator downloaded a full SQL database backup.');
    $connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection->beginTransaction();
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="mfis-ims-backup-' . date('Y-m-d-His') . '.sql"');
    echo "-- MIFS IMS database backup generated " . date('Y-m-d H:i:s') . "\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n";

    $tables = $connection->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $quotedTable = '`' . str_replace('`', '``', (string) $table) . '`';
        $create = $connection->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
        if (!$create || !isset($create[1])) {
            throw new RuntimeException('Unable to read table definition for database backup.');
        }
        echo "\nDROP TABLE IF EXISTS " . $quotedTable . ";\n" . $create[1] . ";\n";
        $rows = $connection->query('SELECT * FROM ' . $quotedTable);
        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            $values = array_map(static function ($value) use ($connection): string {
                if ($value === null) {
                    return 'NULL';
                }
                $quoted = $connection->quote((string) $value);
                if ($quoted === false) {
                    throw new RuntimeException('Unable to encode a database value for the backup.');
                }
                return $quoted;
            }, $row);
            echo 'INSERT INTO ' . $quotedTable . ' VALUES (' . implode(', ', $values) . ");\n";
        }
    }
    echo "\nSET FOREIGN_KEY_CHECKS=1;\n";
    $connection->commit();
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Your session token expired. Refresh the page and try again.');
        }

        $action = $_POST['action'] ?? '';
        if ($action === 'save_student') {
            $id = (int) ($_POST['id'] ?? 0);
            $values = [
                trim($_POST['admission_number'] ?? ''),
                trim($_POST['first_name'] ?? ''),
                trim($_POST['last_name'] ?? ''),
                trim($_POST['class_name'] ?? ''),
                trim($_POST['guardian_name'] ?? ''),
                trim($_POST['guardian_phone'] ?? '')
            ];
            if ($values[0] === '' || $values[1] === '' || $values[2] === '') {
                throw new InvalidArgumentException('Admission number, first name, and last name are required.');
            }
            if ($id > 0) {
                $values[] = $id;
                $statement = $connection->prepare(
                    'UPDATE students SET admission_number = ?, first_name = ?, last_name = ?, class_name = ?, guardian_name = ?, guardian_phone = ? WHERE id = ?'
                );
                $statement->execute($values);
                $message = 'Student record updated successfully.';
            } else {
                $statement = $connection->prepare(
                    'INSERT INTO students (admission_number, first_name, last_name, class_name, guardian_name, guardian_phone) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $statement->execute($values);
                $message = 'Student added successfully.';
            }
        } elseif ($action === 'delete_student') {
            $id = (int) ($_POST['id'] ?? 0);
            $connection->beginTransaction();
            $connection->prepare('DELETE FROM attendance WHERE student_id = ?')->execute([$id]);
            $connection->prepare('DELETE FROM fee_payments WHERE student_id = ?')->execute([$id]);
            $connection->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);
            $connection->commit();
            $message = 'Student and related attendance/payment records deleted.';
        } elseif ($action === 'save_user') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['full_name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $roleId = (int) ($_POST['role_id'] ?? 0);
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $roleId < 1) {
                throw new InvalidArgumentException('Enter a valid name, email, and role.');
            }
            if ($id > 0) {
                $statement = $connection->prepare('UPDATE users SET full_name = ?, email = ?, role_id = ? WHERE id = ?');
                $statement->execute([$name, $email, $roleId, $id]);
                $message = 'User account updated successfully.';
            } else {
                $password = $_POST['password'] ?? '';
                if (strlen($password) < 8) {
                    throw new InvalidArgumentException('New user passwords must be at least 8 characters.');
                }
                $statement = $connection->prepare('INSERT INTO users (full_name, email, password_hash, role_id) VALUES (?, ?, ?, ?)');
                $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
                $message = 'User account added successfully.';
            }
        } elseif ($action === 'add_teacher') {
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $qualification = trim((string) ($_POST['qualification'] ?? ''));
            $hiredOn = trim((string) ($_POST['hired_on'] ?? ''));
            $submittedAssignments = $_POST['assignments'] ?? [];
            $parsedHireDate = $hiredOn === '' ? null : DateTime::createFromFormat('!Y-m-d', $hiredOn);

            if ($name === '' || strlen($name) > 120
                || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120
                || strlen($password) < 8) {
                throw new InvalidArgumentException('Enter the teacher name, a valid email, and a password of at least 8 characters.');
            }
            if (strlen($phone) > 40 || strlen($qualification) > 160) {
                throw new InvalidArgumentException('One or more teacher details exceed the allowed length.');
            }
            if ($hiredOn !== '' && (!$parsedHireDate || $parsedHireDate->format('Y-m-d') !== $hiredOn)) {
                throw new InvalidArgumentException('Enter a valid employment start date.');
            }
            if (!is_array($submittedAssignments) || count($submittedAssignments) > 20) {
                throw new InvalidArgumentException('Submit between one and twenty subject assignments.');
            }
            $assignments = [];
            $uniqueAssignments = [];
            foreach ($submittedAssignments as $assignment) {
                if (!is_array($assignment)) {
                    throw new InvalidArgumentException('Submit valid class and subject assignments.');
                }
                $classInput = $assignment['class_name'] ?? '';
                $subjectInput = $assignment['subject_name'] ?? '';
                $codeInput = $assignment['subject_code'] ?? '';
                if (!is_string($classInput) || !is_string($subjectInput) || !is_string($codeInput)) {
                    throw new InvalidArgumentException('Submit valid class and subject assignments.');
                }
                $className = trim($classInput);
                $subjectName = trim($subjectInput);
                $subjectCode = strtoupper(trim($codeInput));
                if ($className === '' && $subjectName === '' && $subjectCode === '') {
                    continue;
                }
                if ($className === '' || $subjectName === '' || $subjectCode === ''
                    || strlen($className) > 50 || strlen($subjectName) > 100 || strlen($subjectCode) > 30) {
                    throw new InvalidArgumentException('Complete the class, subject name, and subject code for every assignment.');
                }
                $assignmentKey = strtolower($className . "\0" . $subjectName);
                if (isset($uniqueAssignments[$assignmentKey])) {
                    throw new InvalidArgumentException('A class and subject can only be assigned once to this teacher.');
                }
                $uniqueAssignments[$assignmentKey] = true;
                $assignments[] = [
                    'class_name' => $className,
                    'subject_name' => $subjectName,
                    'subject_code' => $subjectCode,
                ];
            }
            if (!$assignments) {
                throw new InvalidArgumentException('Add at least one class and subject assignment for this teacher.');
            }

            $teacherRole = $connection->prepare('SELECT id FROM roles WHERE role_name = ? LIMIT 1');
            $teacherRole->execute(['Teacher']);
            $roleId = (int) $teacherRole->fetchColumn();
            if ($roleId < 1) {
                throw new RuntimeException('The Teacher role is not configured.');
            }

            $connection->beginTransaction();
            $statement = $connection->prepare(
                'INSERT INTO users (full_name, email, password_hash, role_id) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
            $teacherId = (int) $connection->lastInsertId();

            $statement = $connection->prepare(
                'INSERT INTO teacher_profiles (user_id, phone, qualification, hired_on) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([
                $teacherId,
                $phone !== '' ? $phone : null,
                $qualification !== '' ? $qualification : null,
                $hiredOn !== '' ? $hiredOn : null,
            ]);

            $findSubject = $connection->prepare(
                'SELECT id, subject_code FROM subjects WHERE subject_name = ? AND class_name = ? LIMIT 1'
            );
            $findSubjectCode = $connection->prepare(
                'SELECT subject_name, class_name FROM subjects WHERE subject_code = ? LIMIT 1'
            );
            $createSubject = $connection->prepare(
                'INSERT INTO subjects (subject_name, subject_code, class_name) VALUES (?, ?, ?)'
            );
            $assignSubject = $connection->prepare(
                'INSERT INTO teacher_subject_assignments (teacher_id, subject_id) VALUES (?, ?)'
            );
            foreach ($assignments as $assignment) {
                $findSubject->execute([$assignment['subject_name'], $assignment['class_name']]);
                $subject = $findSubject->fetch();
                if ($subject && strtoupper((string) $subject['subject_code']) !== $assignment['subject_code']) {
                    throw new InvalidArgumentException('That subject already exists for this class with a different code.');
                }
                if (!$subject) {
                    $findSubjectCode->execute([$assignment['subject_code']]);
                    $existingCode = $findSubjectCode->fetch();
                    if ($existingCode) {
                        throw new InvalidArgumentException('That subject code is already used by another subject.');
                    }
                    $createSubject->execute([
                        $assignment['subject_name'],
                        $assignment['subject_code'],
                        $assignment['class_name'],
                    ]);
                    $subjectId = (int) $connection->lastInsertId();
                } else {
                    $subjectId = (int) $subject['id'];
                }
                $assignSubject->execute([$teacherId, $subjectId]);
            }

            $connection->commit();
            $message = 'Teacher account, profile, and subject assignments created successfully.';
        } elseif ($action === 'delete_user') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $_SESSION['user']['id']) {
                throw new InvalidArgumentException('You cannot delete the account currently signed in.');
            }
            $connection->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $message = 'User account deleted successfully.';
        } elseif ($action === 'save_announcement') {
            $title = trim($_POST['title'] ?? '');
            $announcement = trim($_POST['message'] ?? '');
            $audience = $_POST['audience'] ?? 'All';
            if ($title === '' || $announcement === '' || !in_array($audience, ['All', 'Parents', 'Students'], true)) {
                throw new InvalidArgumentException('Enter a title, message, and valid audience.');
            }
            $statement = $connection->prepare(
                'INSERT INTO announcements (title, message, audience, published_at) VALUES (?, ?, ?, NOW())'
            );
            $statement->execute([$title, $announcement, $audience]);
            $message = 'School communication published successfully.';
        } elseif ($action === 'save_settings') {
            $submittedSettings = $_POST['settings'] ?? [];
            if (!is_array($submittedSettings)) {
                throw new InvalidArgumentException('Submit valid school settings.');
            }
            $connection->beginTransaction();
            $saveSetting = $connection->prepare(
                'INSERT INTO system_settings (setting_key, setting_value, updated_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP'
            );
            foreach ($settingDefinitions as $key => [$label]) {
                $submittedValue = $submittedSettings[$key] ?? '';
                if (!is_string($submittedValue)) {
                    throw new InvalidArgumentException('Enter a valid value for ' . $label . '.');
                }
                $value = trim($submittedValue);
                if (strlen($value) > 255) {
                    throw new InvalidArgumentException($label . ' must be 255 characters or fewer.');
                }
                if ($key === 'contact_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Enter a valid school contact email address.');
                }
                if ($key === 'academic_year' && $value !== '' && !preg_match('/^\d{4}\/\d{4}$/', $value)) {
                    throw new InvalidArgumentException('Academic year must use the YYYY/YYYY format.');
                }
                $saveSetting->execute([$key, $value, (int) $_SESSION['user']['id']]);
            }
            $connection->commit();
            $message = 'System settings updated successfully.';
        } elseif ($action === 'save_permissions') {
            $submittedPermissions = $_POST['permissions'] ?? [];
            if (!is_array($submittedPermissions)) {
                throw new InvalidArgumentException('Submit a valid role-permissions matrix.');
            }
            $connection->beginTransaction();
            $savePermission = $connection->prepare(
                'INSERT INTO role_permissions (role_id, permission_key, is_allowed, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP'
            );
            $permissionRoles = $connection->query("SELECT id, role_name FROM roles WHERE role_name <> 'Administrator'")->fetchAll();
            foreach ($permissionRoles as $roleRow) {
                foreach ($permissionCatalog as $permission => $label) {
                    $rolePermissions = $submittedPermissions[(string) $roleRow['id']] ?? [];
                    if (!is_array($rolePermissions)) {
                        throw new InvalidArgumentException('Submit a valid role-permissions matrix.');
                    }
                    $allowed = isset($rolePermissions[$permission]) ? 1 : 0;
                    $savePermission->execute([
                        (int) $roleRow['id'],
                        $permission,
                        $allowed,
                        (int) $_SESSION['user']['id'],
                    ]);
                }
            }
            $connection->commit();
            $message = 'Role permissions updated successfully.';
        }

        if ($action !== '') {
            $actionDescriptions = [
                'save_student' => 'Created or updated a student record.',
                'delete_student' => 'Deleted a student record and related records.',
                'save_user' => 'Created or updated a user account and role assignment.',
                'add_teacher' => 'Created a teacher account, profile, and subject assignments.',
                'delete_user' => 'Deleted a user account.',
                'save_announcement' => 'Published a school communication.',
                'save_settings' => 'Updated school system settings.',
                'save_permissions' => 'Updated permissions assigned to roles.',
            ];
            log_system_event($action, $actionDescriptions[$action] ?? 'Completed administrator action.');
        }
    }

    if (isset($_GET['edit_student'])) {
        $statement = $connection->prepare('SELECT * FROM students WHERE id = ?');
        $statement->execute([(int) $_GET['edit_student']]);
        $editStudent = $statement->fetch();  
    }
    if (isset($_GET['edit_user'])) {
        $statement = $connection->prepare('SELECT id, full_name, email, role_id FROM users WHERE id = ?');
        $statement->execute([(int) $_GET['edit_user']]);
        $editUser = $statement->fetch();
    }
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    $error = $exception instanceof PDOException && $exception->getCode() === '23000' && $action === 'delete_user'
        ? 'This user cannot be deleted because attendance or payment records reference the account.'
        : ($exception instanceof PDOException && $exception->getCode() === '23000'
            ? (($action ?? '') === 'add_teacher'
            ? 'The teacher email address or a subject code is already in use.'
                : 'That email or admission number is already in use.')
            : $exception->getMessage());
}

$students = $connection->query('SELECT * FROM students ORDER BY last_name, first_name')->fetchAll();
$users = $connection->query(
    'SELECT users.id, users.full_name, users.email, users.created_at, roles.role_name, roles.id AS role_id
     FROM users JOIN roles ON roles.id = users.role_id ORDER BY users.full_name'
)->fetchAll();
$roles = $connection->query('SELECT id, role_name FROM roles ORDER BY role_name')->fetchAll();
$teacherRows = $connection->query(
    "SELECT users.id, users.full_name, users.email, teacher_profiles.phone,
            teacher_profiles.qualification, teacher_profiles.hired_on,
            GROUP_CONCAT(DISTINCT CONCAT(teacher_assignments.subject_name, ' - ', teacher_assignments.class_name)
                         ORDER BY teacher_assignments.class_name, teacher_assignments.subject_name SEPARATOR ', ') AS assignments
     FROM users
     JOIN roles ON roles.id = users.role_id
     LEFT JOIN teacher_profiles ON teacher_profiles.user_id = users.id
     LEFT JOIN (
         SELECT teacher_id, subject_name, class_name FROM timetable_entries WHERE teacher_id IS NOT NULL
         UNION
         SELECT teacher_subject_assignments.teacher_id, subjects.subject_name, subjects.class_name
         FROM teacher_subject_assignments
         JOIN subjects ON subjects.id = teacher_subject_assignments.subject_id
     ) AS teacher_assignments ON teacher_assignments.teacher_id = users.id
     WHERE roles.role_name = 'Teacher'
     GROUP BY users.id, users.full_name, users.email, teacher_profiles.phone,
              teacher_profiles.qualification, teacher_profiles.hired_on
     ORDER BY users.full_name"
)->fetchAll();
$permissionRows = $connection->query(
    'SELECT role_permissions.role_id, role_permissions.permission_key, role_permissions.is_allowed
     FROM role_permissions'
)->fetchAll();
$permissionMatrix = [];
foreach ($permissionRows as $permissionRow) {
    $permissionMatrix[(int) $permissionRow['role_id']][$permissionRow['permission_key']] = (bool) $permissionRow['is_allowed'];
}
$systemSettings = $connection->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
$settingValues = [];
foreach ($systemSettings as $setting) {
    $settingValues[$setting['setting_key']] = $setting['setting_value'];
}
foreach ($settingDefinitions as $key => [$label, $default]) {
    if (!isset($settingValues[$key])) {
        $settingValues[$key] = $default;
    }
}
$systemLogs = $connection->query(
    'SELECT system_logs.created_at, system_logs.event_type, system_logs.description,
            system_logs.ip_address, COALESCE(users.full_name, "Deleted account") AS user_name,
            COALESCE(system_logs.role_name, "Guest") AS role_name
     FROM system_logs
     LEFT JOIN users ON users.id = system_logs.user_id
     ORDER BY system_logs.id DESC
     LIMIT 100'
)->fetchAll();
$announcements = $connection->query(
    'SELECT id, title, audience, published_at FROM announcements ORDER BY published_at DESC LIMIT 10'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ICT Administration | MIFS IMS</title>
    <style>
        * { box-sizing: border-box; } body { margin: 0; background: #f5f7fb; color: #172b4d; font: 14px Arial, sans-serif; }
        header { display: flex; align-items: center; justify-content: space-between; padding: 20px 5%; color: #fff; background: #0b2d55; }
        header a { color: #dce8f7; text-decoration: none; } main { max-width: 1400px; margin: auto; padding: 30px 5%; }
        h1, h2 { margin-top: 0; } h1 { margin-bottom: 7px; } h2 { font-size: 18px; } .intro { color: #718096; margin-bottom: 24px; }
        .notice { padding: 12px 15px; border-radius: 7px; margin-bottom: 18px; background: #e8f7f0; color: #267153; }
        .notice.error { background: #fff0f0; color: #a33a3a; } .grid { display: grid; grid-template-columns: 360px 1fr; gap: 20px; margin-bottom: 25px; }
        .card { padding: 20px; border: 1px solid #e7edf4; border-radius: 10px; background: #fff; } label { display: block; margin: 12px 0 5px; color: #52637a; font-size: 12px; font-weight: 700; }
        input, select { width: 100%; padding: 10px; border: 1px solid #d8e0ea; border-radius: 6px; font: inherit; } button, .button { display: inline-block; padding: 10px 14px; border: 0; border-radius: 6px; color: #fff; background: #1763a6; cursor: pointer; font: inherit; text-decoration: none; }
        button.danger { background: #b94747; } button.secondary, .button.secondary { color: #38516e; background: #edf2f7; } .actions { display: flex; gap: 7px; align-items: center; } form.inline { display: inline; }
        table { width: 100%; border-collapse: collapse; } th { color: #8290a3; font-size: 11px; text-align: left; text-transform: uppercase; } th, td { padding: 12px 8px; border-bottom: 1px solid #edf1f5; } td { color: #52637a; } td strong { display: block; color: #172b4d; }
        .table-wrap { overflow-x: auto; } .section { margin-bottom: 25px; } .muted { color: #8290a3; font-size: 12px; }
        .admin-hero{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:22px 25px;margin-bottom:20px;border-radius:12px;color:#fff;background:linear-gradient(110deg,#092b4c,#15558a 70%,#218c89)}
        .admin-hero p{margin:8px 0 0;color:#d8edf0}.admin-nav{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:22px}
        .admin-nav a{padding:9px 12px;border:1px solid #dce5ef;border-radius:7px;color:#1763a6;background:#fff;text-decoration:none;font-weight:700}
        .admin-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:22px}
        .admin-stat{padding:15px;border:1px solid #e7edf4;border-top:4px solid #4389c4;border-radius:9px;background:#fff}.admin-stat:nth-child(2){border-top-color:#7866c1}.admin-stat:nth-child(3){border-top-color:#42a17e}.admin-stat:nth-child(4){border-top-color:#e3a232}
        .admin-stat small{display:block;color:#718096;font-size:11px;font-weight:700}.admin-stat strong{display:block;margin-top:7px;font-size:24px}
        .permission-table input{width:auto}.help{color:#718096;font-size:12px;line-height:1.5}
        .teacher-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0 16px}.teacher-form-grid .full{grid-column:1/-1}
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; }.admin-stats{grid-template-columns:repeat(2,minmax(0,1fr));}.admin-hero{align-items:flex-start;flex-direction:column} }
        @media (max-width: 650px) { .teacher-form-grid{grid-template-columns:1fr}.teacher-form-grid .full{grid-column:auto} }
    </style>
</head>
<body>
<header><strong>MIFS IMS &middot; Administrator</strong><nav><a href="dashboard.php">Dashboard</a> &nbsp; | &nbsp; <a href="logout.php">Sign out</a></nav></header>
<main>
    <section class="admin-hero"><div><h1>Administrator dashboard</h1><p>Manage accounts, access, school settings, audit activity, and database backups.</p></div><strong><?= e($_SESSION['user']['full_name']) ?></strong></section>
    <nav class="admin-nav" aria-label="Administrator dashboard sections">
        <a href="#accounts">Users &amp; roles</a><a href="#teachers">Teachers &amp; subjects</a><a href="#settings">System settings</a><a href="#permissions">Permissions</a><a href="#logs">System logs</a><a href="#backup">Database backup</a>
    </nav>
    <section class="admin-stats">
        <div class="admin-stat"><small>Students</small><strong><?= count($students) ?></strong></div>
        <div class="admin-stat"><small>User accounts</small><strong><?= count($users) ?></strong></div>
        <div class="admin-stat"><small>Defined roles</small><strong><?= count($roles) ?></strong></div>
        <div class="admin-stat"><small>Recent audit events</small><strong><?= count($systemLogs) ?></strong></div>
    </section>
    <p class="intro">Manage school users, role assignments, student records, settings, permissions, audit logs, and database backups. Changes are saved directly to the main database.</p>
    <?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

    <section class="grid" id="students">
        <div class="card">
            <h2><?= $editStudent ? 'Update student' : 'Add student' ?></h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save_student">
                <input type="hidden" name="id" value="<?= e((string) ($editStudent['id'] ?? 0)) ?>">
                <label>Admission number</label><input name="admission_number" required value="<?= e($editStudent['admission_number'] ?? '') ?>">
                <label>First name</label><input name="first_name" required value="<?= e($editStudent['first_name'] ?? '') ?>">
                <label>Last name</label><input name="last_name" required value="<?= e($editStudent['last_name'] ?? '') ?>">
                <label>Class</label><input name="class_name" value="<?= e($editStudent['class_name'] ?? '') ?>">
                <label>Guardian name</label><input name="guardian_name" value="<?= e($editStudent['guardian_name'] ?? '') ?>">
                <label>Guardian phone</label><input name="guardian_phone" value="<?= e($editStudent['guardian_phone'] ?? '') ?>">
                <br><br><button type="submit"><?= $editStudent ? 'Save changes' : 'Add student' ?></button>
                <?php if ($editStudent): ?> <a class="button secondary" href="admin.php">Cancel</a><?php endif; ?>
            </form>
        </div>
        <div class="card">
            <h2>Student records <span class="muted">(<?= count($students) ?>)</span></h2>
            <div class="table-wrap"><table><thead><tr><th>Student</th><th>Admission</th><th>Class</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($students as $student): ?><tr><td><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong><?= e($student['guardian_name'] ?: 'No guardian listed') ?></td><td><?= e($student['admission_number']) ?></td><td><?= e($student['class_name'] ?: 'Unassigned') ?></td><td><div class="actions"><a class="button secondary" href="?edit_student=<?= e((string) $student['id']) ?>">Edit</a><form class="inline" method="post" onsubmit="return confirm('Delete this student and related records?');"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete_student"><input type="hidden" name="id" value="<?= e((string) $student['id']) ?>"><button class="danger" type="submit">Delete</button></form></div></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
    </section>

    <section class="section card" id="accounts">
        <h2><?= $editUser ? 'Update staff user' : 'Add staff user' ?></h2>
        <form method="post" class="grid" style="grid-template-columns: repeat(4, 1fr); margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="save_user"><input type="hidden" name="id" value="<?= e((string) ($editUser['id'] ?? 0)) ?>">
            <div><label>Full name</label><input name="full_name" required value="<?= e($editUser['full_name'] ?? '') ?>"></div><div><label>Email</label><input type="email" name="email" required value="<?= e($editUser['email'] ?? '') ?>"></div>
            <?php if (!$editUser): ?><div><label>Temporary password</label><input type="password" name="password" minlength="8" required></div><?php endif; ?><div><label>Role</label><select name="role_id" required><?php foreach ($roles as $role): ?><option value="<?= e((string) $role['id']) ?>" <?= (int) ($editUser['role_id'] ?? 0) === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['role_name']) ?></option><?php endforeach; ?></select></div>
            <div><button type="submit"><?= $editUser ? 'Save user changes' : 'Add user' ?></button><?php if ($editUser): ?> <a class="button secondary" href="admin.php">Cancel</a><?php endif; ?></div>
        </form>
    </section>

    <section class="section card" id="teachers">
        <h2>Add a teacher</h2>
        <p class="muted">Create the teacher's login and staff profile, then assign one or more classes and subjects. Timetable times and rooms can be added separately.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add_teacher">
            <div class="teacher-form-grid">
                <div><label>Teacher full name</label><input name="full_name" required maxlength="120"></div>
                <div><label>Teacher email</label><input type="email" name="email" required maxlength="120"></div>
                <div><label>Temporary password</label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
                <div><label>Phone</label><input type="tel" name="phone" maxlength="40"></div>
                <div><label>Qualification</label><input name="qualification" maxlength="160" placeholder="e.g. B.Ed. Mathematics"></div>
                <div><label>Employment start date</label><input type="date" name="hired_on"></div>
                <div class="full">
                    <h3>Class and subject assignments</h3>
                    <div id="teacher-assignment-rows">
                        <div class="teacher-form-grid teacher-assignment-row">
                            <div><label>Class</label><input name="assignments[0][class_name]" required maxlength="50" placeholder="e.g. Grade 5"></div>
                            <div><label>Subject name</label><input name="assignments[0][subject_name]" required maxlength="100"></div>
                            <div><label>Subject code</label><input name="assignments[0][subject_code]" required maxlength="30" placeholder="e.g. MAT"></div>
                            <div class="full"><button type="button" class="secondary remove-assignment">Remove subject</button></div>
                        </div>
                    </div>
                    <button type="button" class="secondary" id="add-teacher-assignment">Add another subject</button>
                </div>
                <div class="full"><button type="submit">Create teacher and assignments</button></div>
            </div>
        </form>
        <template id="teacher-assignment-template">
            <div class="teacher-form-grid teacher-assignment-row">
                <div><label>Class</label><input name="assignments[__INDEX__][class_name]" required maxlength="50" placeholder="e.g. Grade 5"></div>
                <div><label>Subject name</label><input name="assignments[__INDEX__][subject_name]" required maxlength="100"></div>
                <div><label>Subject code</label><input name="assignments[__INDEX__][subject_code]" required maxlength="30" placeholder="e.g. MAT"></div>
                <div class="full"><button type="button" class="secondary remove-assignment">Remove subject</button></div>
            </div>
        </template>
    </section>

    <section class="section card">
        <h2>Teacher directory <span class="muted">(<?= count($teacherRows) ?>)</span></h2>
        <div class="table-wrap"><table><thead><tr><th>Teacher</th><th>Contact</th><th>Qualification</th><th>Class and subject</th><th>Start date</th></tr></thead><tbody>
        <?php foreach ($teacherRows as $teacher): ?>
            <tr>
                <td><strong><?= e($teacher['full_name']) ?></strong><?= e($teacher['email']) ?></td>
                <td><?= e($teacher['phone'] ?: 'Not provided') ?></td>
                <td><?= e($teacher['qualification'] ?: 'Not provided') ?></td>
                <td><?= e($teacher['assignments'] ?: 'No subject assigned') ?></td>
                <td><?= e($teacher['hired_on'] ?: 'Not recorded') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$teacherRows): ?><tr><td colspan="5" class="muted">No teacher accounts have been created yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="section card" id="settings">
        <h2>System settings</h2>
        <p class="muted">Maintain the school's displayed identity and current academic contact details.</p>
        <form method="post" class="grid" style="grid-template-columns: repeat(2, minmax(0, 1fr)); margin:0;">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_settings">
            <?php foreach ($settingDefinitions as $key => [$label]): ?>
            <div><label><?= e($label) ?></label><input name="settings[<?= e($key) ?>]" value="<?= e($settingValues[$key]) ?>" maxlength="255" <?= $key === 'contact_email' ? 'type="email"' : '' ?> <?= $key === 'academic_year' ? 'pattern="\d{4}/\d{4}" placeholder="2026/2027"' : '' ?>></div>
            <?php endforeach; ?>
            <div style="align-self:end;"><button type="submit">Save system settings</button></div>
        </form>
    </section>

    <section class="section card" id="permissions">
        <h2>Role permissions</h2>
        <p class="help">Grant or remove access to workspaces and administrative capabilities by role. Administrator access is a protected system-owner permission and cannot be removed here. Updates are enforced by the application and recorded in system logs.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_permissions">
            <div class="table-wrap"><table class="permission-table">
                <thead><tr><th>Role</th><?php foreach ($permissionCatalog as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($roles as $role): ?>
                    <?php if ($role['role_name'] === 'Administrator') continue; ?>
                    <tr>
                        <td><strong><?= e($role['role_name']) ?></strong></td>
                        <?php foreach ($permissionCatalog as $permission => $label): ?>
                        <td><input type="checkbox" aria-label="<?= e($role['role_name'] . ': ' . $label) ?>" name="permissions[<?= e((string) $role['id']) ?>][<?= e($permission) ?>]" value="1" <?= !empty($permissionMatrix[(int) $role['id']][$permission]) ? 'checked' : '' ?>></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <br><button type="submit">Save role permissions</button>
        </form>
    </section>

    <section class="section card" id="logs">
        <h2>System logs</h2>
        <p class="muted">Recent administrative changes and database backup activity. Audit history is retained in the main database.</p>
        <div class="table-wrap"><table><thead><tr><th>Date / time</th><th>User</th><th>Role</th><th>Event</th><th>Description</th><th>IP address</th></tr></thead><tbody>
        <?php foreach ($systemLogs as $log): ?>
        <tr><td><?= e($log['created_at']) ?></td><td><strong><?= e($log['user_name']) ?></strong></td><td><?= e($log['role_name']) ?></td><td><?= e($log['event_type']) ?></td><td><?= e($log['description']) ?></td><td><?= e($log['ip_address'] ?: '-') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$systemLogs): ?><tr><td colspan="6" class="muted">No system events have been recorded yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="section card" id="backup">
        <h2>Database backup</h2>
        <p class="help">Download a full SQL backup of all database tables and records. The file contains sensitive personal information and password hashes; store it securely and do not place it in a public web directory.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="download_backup">
            <button type="submit">Download full database backup (.sql)</button>
        </form>
    </section>

    <section class="section card">
        <h2>School communications</h2>
        <p class="muted">Publish important notices that appear in the Parent and Student portals.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_announcement">
            <label>Notice title</label><input name="title" required>
            <label>Message</label><textarea name="message" required style="width:100%;min-height:90px;padding:10px;border:1px solid #d8e0ea;border-radius:6px;font:inherit;"></textarea>
            <label>Audience</label><select name="audience" required><option value="All">Everyone</option><option value="Parents">Parents</option><option value="Students">Students</option></select>
            <br><br><button type="submit">Publish communication</button>
        </form>
        <?php if ($announcements): ?><div class="table-wrap" style="margin-top:20px;"><table><thead><tr><th>Notice</th><th>Audience</th><th>Published</th></tr></thead><tbody><?php foreach ($announcements as $announcement): ?><tr><td><strong><?= e($announcement['title']) ?></strong></td><td><?= e($announcement['audience']) ?></td><td><?= e($announcement['published_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>

    <section class="card">
        <h2>Staff accounts <span class="muted">(<?= count($users) ?>)</span></h2>
        <div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($users as $account): ?><tr><td><strong><?= e($account['full_name']) ?></strong></td><td><?= e($account['email']) ?></td><td><?= e($account['role_name']) ?></td><td><?= e($account['created_at']) ?></td><td><div class="actions"><a class="button secondary" href="?edit_user=<?= e((string) $account['id']) ?>">Edit</a><?php if ((int) $account['id'] !== (int) $_SESSION['user']['id']): ?><form class="inline" method="post" onsubmit="return confirm('Delete this user account?');"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= e((string) $account['id']) ?>"><button class="danger" type="submit">Delete</button></form><?php else: ?><span class="muted">Current account</span><?php endif; ?></div></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</main>
<script>
(() => {
    const rows = document.getElementById('teacher-assignment-rows');
    const template = document.getElementById('teacher-assignment-template');
    const addButton = document.getElementById('add-teacher-assignment');
    let nextIndex = 1;

    const updateAddButton = () => {
        addButton.disabled = rows.children.length >= 20;
        addButton.textContent = addButton.disabled ? 'Maximum of 20 subjects reached' : 'Add another subject';
    };

    addButton.addEventListener('click', () => {
        if (rows.children.length < 20) {
            rows.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(nextIndex++)));
            updateAddButton();
        }
    });

    rows.addEventListener('click', (event) => {
        if (event.target instanceof HTMLElement && event.target.classList.contains('remove-assignment')) {
            if (rows.children.length > 1) {
                event.target.closest('.teacher-assignment-row').remove();
                updateAddButton();
            }
        }
    });
    updateAddButton();
})();
</script>
</body>
</html>
