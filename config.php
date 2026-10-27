<?php
declare(strict_types=1);

function env_value(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return $value;
}

function request_is_secure(): bool
{
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($forwardedProto) && trim($forwardedProto) !== '') {
        return strtolower(trim(explode(',', $forwardedProto)[0])) === 'https';
    }

    $forwardedSsl = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';
    if (is_string($forwardedSsl) && strtolower(trim($forwardedSsl)) === 'on') {
        return true;
    }

    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

if (!defined('APP_ENV')) {
    define('APP_ENV', (string) env_value('APP_ENV', env_value('MFIS_APP_ENV', 'production')));
}
if (APP_ENV === 'development' || APP_ENV === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

if (!defined('APP_URL')) {
    define('APP_URL', rtrim((string) env_value('APP_URL', env_value('MFIS_PUBLIC_BASE_URL', 'http://localhost/mfis_ims')), '/'));
}
if (!defined('DB_HOST')) {
    define('DB_HOST', (string) env_value('DB_HOST', env_value('MFIS_DB_HOST', 'localhost')));
}
if (!defined('DB_PORT')) {
    define('DB_PORT', (string) env_value('DB_PORT', env_value('MFIS_DB_PORT', '3306')));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', (string) env_value('DB_NAME', env_value('MFIS_DB_NAME', 'mfis_ims')));
}
if (!defined('DB_USER')) {
    define('DB_USER', (string) env_value('DB_USER', env_value('MFIS_DB_USER', 'root')));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', (string) env_value('DB_PASS', env_value('MFIS_DB_PASS', '')));
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', (string) env_value('DB_CHARSET', 'utf8mb4'));
}
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', (string) env_value('APP_TIMEZONE', 'Africa/Kigali'));
}
if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', (string) env_value('APP_BASE_PATH', ''));
}

date_default_timezone_set(APP_TIMEZONE);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => request_is_secure(),
    'domain' => '',
]);
session_name('mfis_ims_session');
session_start();

$languagePreference = $_SESSION['language'] ?? $_COOKIE['mfis_language'] ?? 'en';
$_SESSION['language'] = in_array($languagePreference, ['en', 'fr'], true) ? $languagePreference : 'en';

function app_language(): string {
    return $_SESSION['language'] ?? 'en';
}

function set_app_language(string $language): void {
    if (!in_array($language, ['en', 'fr'], true)) {
        throw new InvalidArgumentException('Unsupported language preference.');
    }

    $_SESSION['language'] = $language;
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $cookiePath = rtrim(dirname($scriptName), '/\\') . '/';
    if ($cookiePath === '//') {
        $cookiePath = '/';
    }
    setcookie('mfis_language', $language, [
        'expires' => time() + 31536000,
        'path' => $cookiePath,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function app_prepare_html_document(string $output): string {
    if (stripos($output, '</body>') === false) {
        return $output;
    }

    $language = app_language();
    $output = preg_replace_callback(
        '/<html\b([^>]*)>/i',
        static function (array $matches) use ($language): string {
            $attributes = preg_replace('/\s+lang\s*=\s*(["\']).*?\1/i', '', $matches[1]);
            return '<html lang="' . $language . '"' . $attributes . '>';
        },
        $output,
        1
    );

    return preg_replace(
        '/<\/body>/i',
        '<script src="language.js?v=3" defer></script></body>',
        $output,
        1
    );
}

ob_start('app_prepare_html_document');

function db(): PDO {
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . DB_CHARSET,
        ]
    );

    return $pdo;
}

function ensure_exam_tables(): void {
    $connection = db();

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS subjects (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            subject_name VARCHAR(100) NOT NULL,
            subject_code VARCHAR(30) NOT NULL,
            class_name VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_subject_code (subject_code),
            UNIQUE KEY uq_subject_name_class (subject_name, class_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS exams (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(120) NOT NULL,
            exam_type VARCHAR(30) NOT NULL DEFAULT 'Mid-Term',
            class_name VARCHAR(50) NOT NULL,
            term VARCHAR(20) NOT NULL,
            academic_year VARCHAR(9) NOT NULL,
            exam_date DATE NOT NULL,
            status ENUM('Draft','Open','Closed','Published') NOT NULL DEFAULT 'Draft',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $examTypeColumn = $connection->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exams' AND COLUMN_NAME = 'exam_type'"
    )->fetchColumn();
    if ((int) $examTypeColumn === 0) {
        $connection->exec(
            "ALTER TABLE exams ADD COLUMN exam_type VARCHAR(30) NOT NULL DEFAULT 'Mid-Term' AFTER title"
        );
    }

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS exam_subjects (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            exam_id INT UNSIGNED NOT NULL,
            subject_id INT UNSIGNED NOT NULL,
            max_marks DECIMAL(5,2) NOT NULL DEFAULT 100.00,
            pass_mark DECIMAL(5,2) NOT NULL DEFAULT 40.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_exam_subject (exam_id, subject_id),
            KEY idx_exam_subject (exam_id),
            KEY idx_subject (subject_id),
            CONSTRAINT fk_exam_subjects_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
            CONSTRAINT fk_exam_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS student_marks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            exam_id INT UNSIGNED NOT NULL,
            student_id INT NOT NULL,
            subject_id INT UNSIGNED NOT NULL,
            marks_obtained DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            grade VARCHAR(2) DEFAULT NULL,
            remark VARCHAR(120) DEFAULT NULL,
            status ENUM('Pending','Validated') NOT NULL DEFAULT 'Pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_student_exam_subject (exam_id, student_id, subject_id),
            KEY idx_exam_student (exam_id, student_id),
            KEY idx_exam_subject (exam_id, subject_id),
            CONSTRAINT fk_student_marks_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
            CONSTRAINT fk_student_marks_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            CONSTRAINT fk_student_marks_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $examStatus = $connection->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exams' AND COLUMN_NAME = 'status'"
    )->fetchColumn();
    if (is_string($examStatus) && strpos($examStatus, "'Published'") === false) {
        $connection->exec(
            "ALTER TABLE exams MODIFY status ENUM('Draft','Open','Closed','Published') NOT NULL DEFAULT 'Draft'"
        );
    }
}

function ensure_timetable_tables(): void {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS timetable_entries (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_name VARCHAR(50) NOT NULL,
            subject_name VARCHAR(100) NOT NULL,
            teacher_id INT DEFAULT NULL,
            day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            room VARCHAR(40) DEFAULT NULL,
            term VARCHAR(20) NOT NULL,
            academic_year VARCHAR(9) NOT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_timetable_period (academic_year, term, day_of_week, start_time),
            KEY idx_timetable_teacher (teacher_id),
            CONSTRAINT fk_timetable_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_timetable_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_librarian_role(): void {
    $connection = db();
    $roleId = $connection->prepare('SELECT id FROM roles WHERE role_name = ? LIMIT 1');
    $roleId->execute(['Librarian']);
    if (!$roleId->fetchColumn()) {
        $statement = $connection->prepare(
            'INSERT INTO roles (role_name, responsibilities) VALUES (?, ?)'
        );
        $statement->execute([
            'Librarian',
            'Manages the school library, book catalogue, lending and returns.'
        ]);
    }
}

function ensure_head_teacher_role(): void {
    $connection = db();
    $roleId = $connection->prepare('SELECT id FROM roles WHERE role_name = ? LIMIT 1');
    $roleId->execute(['Head Teacher/Principal']);
    if (!$roleId->fetchColumn()) {
        $statement = $connection->prepare(
            'INSERT INTO roles (role_name, responsibilities) VALUES (?, ?)'
        );
        $statement->execute([
            'Head Teacher/Principal',
            'Monitors school statistics, academic performance, attendance, staff and students, and approves completed academic reports.'
        ]);
    }
}

function ensure_deputy_headteacher_role(): void {
    $connection = db();
    $roleId = $connection->prepare('SELECT id FROM roles WHERE role_name = ? LIMIT 1');
    $roleId->execute(['Deputy Headteacher']);
    if (!$roleId->fetchColumn()) {
        $statement = $connection->prepare(
            'INSERT INTO roles (role_name, responsibilities) VALUES (?, ?)'
        );
        $statement->execute([
            'Deputy Headteacher',
            'Monitors students, manages discipline records, views attendance, and monitors academic activities.'
        ]);
    }
}

function ensure_teacher_role(): void {
    $connection = db();
    $roleId = $connection->prepare('SELECT id FROM roles WHERE role_name = ? LIMIT 1');
    $roleId->execute(['Teacher']);
    if (!$roleId->fetchColumn()) {
        $statement = $connection->prepare(
            'INSERT INTO roles (role_name, responsibilities) VALUES (?, ?)'
        );
        $statement->execute([
            'Teacher',
            'Teaches assigned classes and subjects, records attendance, and submits student marks.'
        ]);
    }
}

function ensure_teacher_profile_table(): void {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS teacher_profiles (
            user_id INT NOT NULL,
            phone VARCHAR(40) DEFAULT NULL,
            qualification VARCHAR(160) DEFAULT NULL,
            hired_on DATE DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_teacher_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_teacher_subject_assignment_table(): void {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS teacher_subject_assignments (
            teacher_id INT NOT NULL,
            subject_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (teacher_id, subject_id),
            KEY idx_teacher_subject_subject (subject_id),
            CONSTRAINT fk_teacher_subject_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_teacher_subject_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_discipline_tables(): void {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS discipline_records (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id INT NOT NULL,
            incident_date DATE NOT NULL,
            incident_type VARCHAR(80) NOT NULL,
            description TEXT NOT NULL,
            action_taken TEXT DEFAULT NULL,
            status ENUM('Open','Resolved') NOT NULL DEFAULT 'Open',
            recorded_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_discipline_date_status (incident_date, status),
            KEY idx_discipline_student (student_id),
            CONSTRAINT fk_discipline_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            CONSTRAINT fk_discipline_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_admin_control_tables(): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $connection = db();
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(80) NOT NULL,
            setting_value TEXT NOT NULL,
            updated_by INT DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key),
            CONSTRAINT fk_system_setting_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS system_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT DEFAULT NULL,
            role_name VARCHAR(80) DEFAULT NULL,
            event_type VARCHAR(80) NOT NULL,
            description VARCHAR(500) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_system_log_created (created_at),
            KEY idx_system_log_user (user_id),
            CONSTRAINT fk_system_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT NOT NULL,
            permission_key VARCHAR(80) NOT NULL,
            is_allowed TINYINT(1) NOT NULL DEFAULT 0,
            updated_by INT DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (role_id, permission_key),
            CONSTRAINT fk_role_permission_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT fk_role_permission_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $defaults = [
        'students.manage' => ['Registrar'],
        'registrar.access' => ['Registrar'],
        'teacher.access' => ['Teacher'],
        'finance.access' => ['Accountant'],
        'inventory.access' => ['Accountant'],
        'library.access' => ['Librarian'],
        'exams.manage' => ['Registrar'],
        'reports.view' => ['Registrar', 'Head Teacher/Principal', 'Deputy Headteacher'],
        'reports.approve' => ['Head Teacher/Principal'],
    ];
    $roles = $connection->query('SELECT id, role_name FROM roles')->fetchAll();
    $insert = $connection->prepare(
        'INSERT IGNORE INTO role_permissions (role_id, permission_key, is_allowed) VALUES (?, ?, ?)'
    );
    foreach ($roles as $role) {
        foreach ($defaults as $permission => $allowedRoles) {
            $insert->execute([
                (int) $role['id'],
                $permission,
                in_array($role['role_name'], $allowedRoles, true) ? 1 : 0,
            ]);
        }
    }
    $ready = true;
}

function has_system_permission(string $permission): bool {
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)) {
        return false;
    }
    if (($user['role'] ?? '') === 'Administrator') {
        return true;
    }

    ensure_admin_control_tables();
    $statement = db()->prepare(
        'SELECT role_permissions.is_allowed
         FROM role_permissions
         JOIN users ON users.role_id = role_permissions.role_id
         WHERE users.id = ? AND role_permissions.permission_key = ?
         LIMIT 1'
    );
    $statement->execute([(int) ($user['id'] ?? 0), $permission]);
    return (bool) $statement->fetchColumn();
}

function log_system_event(string $eventType, string $description): void {
    ensure_admin_control_tables();
    $user = $_SESSION['user'] ?? [];
    $statement = db()->prepare(
        'INSERT INTO system_logs (user_id, role_name, event_type, description, ip_address)
         VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([
        isset($user['id']) ? (int) $user['id'] : null,
        isset($user['role']) ? (string) $user['role'] : null,
        substr($eventType, 0, 80),
        substr($description, 0, 500),
        isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
    ]);
}

function system_setting(string $key, string $default = ''): string {
    ensure_admin_control_tables();
    $statement = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function ensure_library_tables(): void {
    $connection = db();
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS library_books (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(180) NOT NULL,
            author VARCHAR(120) NOT NULL,
            category VARCHAR(80) NOT NULL,
            isbn VARCHAR(30) DEFAULT NULL,
            quantity INT UNSIGNED NOT NULL,
            available_copies INT UNSIGNED NOT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_library_book_isbn (isbn),
            KEY idx_library_book_title (title),
            KEY idx_library_book_category (category),
            CONSTRAINT fk_library_book_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS library_loans (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            book_id INT UNSIGNED NOT NULL,
            student_id INT NOT NULL,
            issued_by INT DEFAULT NULL,
            issue_date DATE NOT NULL,
            due_date DATE NOT NULL,
            closed_at DATETIME DEFAULT NULL,
            status ENUM('Issued','Returned','Lost') NOT NULL DEFAULT 'Issued',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_library_loan_status_due (status, due_date),
            KEY idx_library_loan_student (student_id, status),
            KEY idx_library_loan_book (book_id, status),
            CONSTRAINT fk_library_loan_book FOREIGN KEY (book_id) REFERENCES library_books(id) ON DELETE RESTRICT,
            CONSTRAINT fk_library_loan_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
            CONSTRAINT fk_library_loan_issuer FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_staff_attendance_tables(): void {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS staff_attendance (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            staff_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            attendance_status ENUM('Present','Late','Absent','Leave') NOT NULL,
            check_in TIME DEFAULT NULL,
            check_out TIME DEFAULT NULL,
            source ENUM('Manual','Machine') NOT NULL DEFAULT 'Manual',
            notes VARCHAR(255) DEFAULT NULL,
            recorded_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_staff_attendance_day (staff_id, attendance_date),
            KEY idx_staff_attendance_date_status (attendance_date, attendance_status),
            CONSTRAINT fk_staff_attendance_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_staff_attendance_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Adds a payment_method ENUM column to fee_payments if it does not already exist.
 * Safe to call on every request.
 */
function ensure_payment_method_column(): void {
    $connection = db();
    $exists = $connection->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME  = 'fee_payments'
           AND COLUMN_NAME = 'payment_method'"
    )->fetchColumn();

    if (!$exists) {
        $connection->exec(
            "ALTER TABLE fee_payments
             ADD COLUMN payment_method ENUM('Cash','Mobile Money','Bank','Pay Code') NOT NULL DEFAULT 'Cash'"
        );
    }
}

function ensure_fee_assessment_table(): void {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS student_fee_assessments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id INT NOT NULL,
            term VARCHAR(20) NOT NULL,
            academic_year VARCHAR(9) NOT NULL,
            amount_due DECIMAL(12,2) NOT NULL,
            assessed_by INT DEFAULT NULL,
            assessed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_student_fee_term (student_id, term, academic_year),
            KEY idx_fee_assessment_period (academic_year, term),
            CONSTRAINT fk_fee_assessment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            CONSTRAINT fk_fee_assessment_user FOREIGN KEY (assessed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Adds a pay_code column to the students table if it does not already exist.
 * Safe to call on every request — it checks information_schema first.
 */
function ensure_pay_code_column(): void {
    $connection = db();
    $col = $connection->query(
        "SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME  = 'students'
           AND COLUMN_NAME = 'pay_code'"
    )->fetch();

    if (!$col) {
        // Column does not exist yet — add it.
        // Disable FK checks so MariaDB allows the ALTER on a referenced parent table.
        $connection->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $connection->exec(
                "ALTER TABLE students
                 ADD COLUMN pay_code VARCHAR(20) DEFAULT NULL,
                 ADD UNIQUE KEY uq_pay_code (pay_code)"
            );
        } finally {
            $connection->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }
    // Column already exists (any size) — nothing to do.
    // We intentionally do NOT resize an existing column: shrinking VARCHAR with
    // existing data causes duplicate-key collisions on the unique index.
}

/**
 * Creates the inventory module tables if they do not already exist.
 * Safe to call on every request — it uses CREATE TABLE IF NOT EXISTS.
 */
function ensure_inventory_tables(): void {
    $connection = db();

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS inventory_categories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_name VARCHAR(80) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inventory_category (category_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS inventory_suppliers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_name VARCHAR(120) NOT NULL,
            contact_person VARCHAR(120) DEFAULT NULL,
            phone VARCHAR(40) DEFAULT NULL,
            email VARCHAR(120) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inventory_supplier (supplier_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS inventory_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_code VARCHAR(40) NOT NULL,
            item_name VARCHAR(120) NOT NULL,
            category_id INT UNSIGNED DEFAULT NULL,
            supplier_id INT UNSIGNED DEFAULT NULL,
            unit VARCHAR(20) NOT NULL DEFAULT 'Unit',
            quantity_on_hand INT NOT NULL DEFAULT 0,
            reorder_level INT NOT NULL DEFAULT 0,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            location VARCHAR(80) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inventory_item_code (item_code),
            KEY idx_inventory_item_category (category_id),
            KEY idx_inventory_item_supplier (supplier_id),
            KEY idx_inventory_item_stock (quantity_on_hand, reorder_level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS inventory_movements (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id INT UNSIGNED NOT NULL,
            movement_type ENUM('Opening','Receipt','Issue','Return','Adjustment','Loss') NOT NULL DEFAULT 'Receipt',
            quantity INT NOT NULL DEFAULT 0,
            balance_after INT NOT NULL DEFAULT 0,
            unit_cost DECIMAL(12,2) DEFAULT NULL,
            reference VARCHAR(80) DEFAULT NULL,
            supplier_id INT UNSIGNED DEFAULT NULL,
            movement_date DATE NOT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            recorded_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_movement_item (item_id),
            KEY idx_movement_date (movement_date),
            KEY idx_movement_type (movement_type),
            CONSTRAINT fk_movement_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Seed the standard school store categories on a fresh install.
    $connection->exec(
        "INSERT IGNORE INTO inventory_categories (category_name, description) VALUES
            ('Stationery', 'Pens, pencils, exercise books, files and writing materials.'),
            ('Cleaning', 'Soap, sanitiser, brooms, mops and waste bins.'),
            ('Furniture', 'Desks, chairs, shelves and storage cabinets.'),
            ('Sports', 'Footballs, cones, nets and sportswear.'),
            ('Uniforms', 'School uniform pieces, ties, socks and shoes.'),
            ('ICT', 'Cables, projectors, printers, batteries and accessories.'),
            ('First Aid', 'Bandages, antiseptics, gloves and medical supplies.')"
    );
}

/**
 * Generates a unique all-numeric pay code of the form YYYYNNNNNN
 * (e.g. 2026000001, 2026000002, …).
 * The year prefix makes codes easy to trace back to their admission year.
 * The 6-digit sequential suffix supports up to 999,999 students per year.
 * Retries up to 20 times to handle race-condition collisions.
 */
function generate_pay_code(): string {
    $connection = db();
    $year = (int) date('Y');
    $prefix = (string) $year;          // e.g. "2026"

    for ($attempt = 0; $attempt < 20; $attempt++) {
        // Find the highest sequence already used for this year
        $max = $connection->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(pay_code, 5) AS UNSIGNED)), 0)
             FROM students
             WHERE pay_code LIKE ?"
        );
        $max->execute([$prefix . '%']);
        $next = (int) $max->fetchColumn() + 1;

        $candidate = sprintf('%d%06d', $year, $next);  // e.g. 2026000001

        // Belt-and-suspenders uniqueness check before returning
        $check = $connection->prepare('SELECT COUNT(*) FROM students WHERE pay_code = ?');
        $check->execute([$candidate]);
        if ((int) $check->fetchColumn() === 0) {
            return $candidate;
        }
    }

    // Fallback: append a random 6-digit number to guarantee uniqueness
    return $prefix . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function e(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function app_absolute_url(string $relativePath): string {
    $publicBaseUrl = getenv('MFIS_PUBLIC_BASE_URL');
    if (is_string($publicBaseUrl) && trim($publicBaseUrl) !== '') {
        $publicBaseUrl = rtrim(trim($publicBaseUrl), '/');
        if (!preg_match('~\Ahttps?://[A-Za-z0-9.\-\[\]:]+(?:/[A-Za-z0-9._\~!$&\'()*+,;=:@%-]*)*\z~i', $publicBaseUrl)) {
            throw new RuntimeException('MFIS_PUBLIC_BASE_URL must be an absolute HTTP or HTTPS application URL.');
        }
        return $publicBaseUrl . '/' . ltrim($relativePath, '/');
    }

    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim(APP_URL, '/') . '/' . ltrim($relativePath, '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!is_string($host) || !preg_match('/\A(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(?::([0-9]{1,5}))?\z/', $host, $matches)) {
        throw new RuntimeException('Unable to create a student card URL from the current host.');
    }
    if (isset($matches[1]) && ((int) $matches[1] < 1 || (int) $matches[1] > 65535)) {
        throw new RuntimeException('Unable to create a student card URL from the current host.');
    }

    $hostName = strtolower(preg_replace('/:\d+$/', '', $host));
    if (in_array($hostName, ['localhost', '127.0.0.1', '[::1]'], true)) {
        $serverAddress = $_SERVER['SERVER_ADDR'] ?? '';
        $networkAddresses = is_string($serverAddress) ? [$serverAddress] : [];
        $machineName = gethostname();
        if (is_string($machineName) && $machineName !== '') {
            $resolvedAddresses = gethostbynamel($machineName);
            if (is_array($resolvedAddresses)) {
                $networkAddresses = array_merge($networkAddresses, $resolvedAddresses);
            }
        }

        $networkAddresses = array_values(array_unique($networkAddresses));
        usort($networkAddresses, static function (string $left, string $right): int {
            $privateNetworkRank = static function (string $address): int {
                if (preg_match('/\A(?:10\.|192\.168\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/', $address)) {
                    return 2;
                }
                return 1;
            };
            return $privateNetworkRank($right) <=> $privateNetworkRank($left);
        });

        $reachableAddress = null;
        foreach ($networkAddresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if (strpos($address, '127.') === 0 || strpos($address, '169.254.') === 0 || $address === '0.0.0.0') {
                    continue;
                }
            } elseif ($address === '::1' || preg_match('/\Afe[89ab][0-9a-f]:/i', $address)) {
                continue;
            }
            $reachableAddress = $address;
            break;
        }

        if ($reachableAddress === null) {
            throw new RuntimeException(
                'A network address for this computer could not be detected. Set MFIS_PUBLIC_BASE_URL to the school server address so phones can open meal-card QR codes.'
            );
        }
        $host = strpos($reachableAddress, ':') !== false ? '[' . $reachableAddress . ']' : $reachableAddress;
        if (isset($matches[1])) {
            $host .= ':' . $matches[1];
        }
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = dirname($scriptName);
    if ($basePath === '.' || $basePath === '/') {
        $basePath = '';
    }
    $path = '/' . trim($basePath . '/' . ltrim($relativePath, '/'), '/');
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';

    return $scheme . '://' . $host . $path;
}

function exam_grade_for_percentage(float $score): string {
    if ($score >= 80) {
        return 'A';
    }
    if ($score >= 70) {
        return 'B';
    }
    if ($score >= 60) {
        return 'C';
    }
    if ($score >= 50) {
        return 'D';
    }
    if ($score >= 40) {
        return 'E';
    }
    return 'F';
}

function role_destination(string $role): string {
    return [
        'Administrator' => 'admin.php',
        'Registrar' => 'registrar.php',
        'Teacher' => 'teacher.php',
        'Accountant' => 'finance.php',
        'Librarian' => 'librarian.php',
        'Head Teacher/Principal' => 'head_teacher.php',
        'Deputy Headteacher' => 'deputy_headteacher.php',
        'Parent' => 'student.php',
        'Student' => 'student_portal.php'
    ][$role] ?? 'dashboard.php';
}