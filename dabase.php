<?php
require_once __DIR__ . '/config.php';

$db = db();
$db->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS student_id INT NULL UNIQUE');
$db->exec("UPDATE roles SET role_name = 'Parent' WHERE role_name = 'Parent/Student'");
$db->exec("CREATE TABLE IF NOT EXISTS enrollments (id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, term VARCHAR(20) NOT NULL, academic_year VARCHAR(9) NOT NULL, report_date DATE NOT NULL, registration_status ENUM('Submitted', 'Approved', 'Rejected') NOT NULL DEFAULT 'Submitted', notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_student_term (student_id, term, academic_year), FOREIGN KEY (student_id) REFERENCES students(id))");
ensure_discipline_tables();

$db->exec('SET FOREIGN_KEY_CHECKS=0');
$db->exec('DELETE FROM fee_payments');
$db->exec('DELETE FROM attendance');
$db->exec('DELETE FROM discipline_records');
$db->exec('DELETE FROM students');
$db->exec('DELETE FROM users');
$db->exec('DELETE FROM roles');
$db->exec('SET FOREIGN_KEY_CHECKS=1');

$roleRows = [
    ['Administrator', 'Creates user accounts, assigns roles, manages system settings and school records.'],
    ['Registrar', 'Registers students, manages admissions, enrolment and student information.'],
    ['Teacher', 'Records attendance, enters grades, manages assigned classes and student progress.'],
    ['Accountant', 'Manages school fees, payments, invoices, receipts and financial reports.'],
    ['Librarian', 'Manages the school library, book catalogue, lending and returns.'],
    ['Head Teacher/Principal', 'Monitors school statistics, academic performance, attendance, staff and students, and approves completed academic reports.'],
    ['Deputy Headteacher', 'Monitors students, manages discipline records, views attendance, and monitors academic activities.'],
    ['Parent', 'Views linked child information, attendance, results and fee status.'],
    ['Student', 'Submits term registration, views attendance, payments and school information.']
];

$stmt = $db->prepare('INSERT INTO roles (role_name, responsibilities) VALUES (?, ?)');
foreach ($roleRows as [$name, $desc]) {
    $stmt->execute([$name, $desc]);
}

$rolesMap = [];
$roleRes = $db->query("SELECT id, role_name FROM roles");
while ($row = $roleRes->fetch()) {
    $rolesMap[$row['role_name']] = (int) $row['id'];
}

$users = [
    ['System Administrator', 'admin@mandelafrenchschool.edu', 'Admin12345!', $rolesMap['Administrator']],
    ['Amina Ndayisenga', 'registrar@mandelafrenchschool.edu', 'Registrar123!', $rolesMap['Registrar']],
    ['Daniel Mugisha', 'teacher@mandelafrenchschool.edu', 'Teacher123!', $rolesMap['Teacher']],
    ['Grace Byiringiro', 'accountant@mandelafrenchschool.edu', 'Account123!', $rolesMap['Accountant']],
    ['Deputy Headteacher', 'deputy@mandelafrenchschool.edu', 'Deputy123!', $rolesMap['Deputy Headteacher']]
];

$insertUser = $db->prepare('INSERT INTO users (full_name, email, password_hash, role_id) VALUES (?, ?, ?, ?)');
foreach ($users as [$name, $email, $pass, $roleId]) {
    $insertUser->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $roleId]);
}

$students = [
    ['MF-2024-001', 'Alicia', 'Mugisha', 'Grade 7', 'Joseph Mugisha', '+250788112233'],
    ['MF-2024-002', 'Brian', 'Uwimana', 'Grade 8', 'Rose Uwimana', '+250788445566'],
    ['MF-2024-003', 'Clara', 'Niyonsaba', 'Grade 9', 'Emmanuel Niyonsaba', '+250788778899'],
    ['MF-2024-004', 'Ethan', 'Kamanzi', 'Grade 10', 'Patrice Kamanzi', '+250788990011']
];

$insertStudent = $db->prepare('INSERT INTO students (admission_number, first_name, last_name, class_name, guardian_name, guardian_phone) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($students as $student) {
    $insertStudent->execute($student);
}

$parentRoleId = $rolesMap['Parent'];
$parentStudentId = $db->query("SELECT id FROM students WHERE admission_number = 'MF-2024-001' LIMIT 1")->fetchColumn();
$insertUser = $db->prepare('INSERT INTO users (full_name, email, password_hash, role_id, student_id) VALUES (?, ?, ?, ?, ?)');
$insertUser->execute(['Student Parent', 'parent@mandelafrenchschool.edu', password_hash('Parent123!', PASSWORD_DEFAULT), $parentRoleId, $parentStudentId]);
$studentRoleId = $rolesMap['Student'];
$studentId = $db->query("SELECT id FROM students WHERE admission_number = 'MF-2024-002' LIMIT 1")->fetchColumn();
$insertUser->execute(['Brian Uwimana', 'student@mandelafrenchschool.edu', password_hash('Student123!', PASSWORD_DEFAULT), $studentRoleId, $studentId]);

$teacherId = $db->query("SELECT id FROM users WHERE email = 'teacher@mandelafrenchschool.edu' LIMIT 1")->fetchColumn();
$studentIds = $db->query('SELECT id FROM students ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);

$attendanceInsert = $db->prepare('INSERT INTO attendance (student_id, attendance_date, attendance_status, recorded_by) VALUES (?, ?, ?, ?)');
$paymentInsert = $db->prepare('INSERT INTO fee_payments (student_id, amount, payment_date, receipt_number, recorded_by) VALUES (?, ?, ?, ?, ?)');

$statuses = ['Present', 'Absent', 'Late'];
foreach ($studentIds as $index => $studentId) {
    $date = '2026-09-20';
    $status = $statuses[$index % count($statuses)];
    $attendanceInsert->execute([$studentId, $date, $status, $teacherId]);

    $amount = 150000 + ($index * 25000);
    $receipt = 'RCPT-' . sprintf('%03d', $studentId);
    $paymentInsert->execute([$studentId, $amount, $date, $receipt, $teacherId]);
}

echo "Database seeded successfully.\n";
