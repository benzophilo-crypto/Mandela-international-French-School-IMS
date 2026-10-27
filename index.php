<?php
require_once __DIR__ . '/config.php';
ensure_librarian_role();
ensure_head_teacher_role();
ensure_deputy_headteacher_role();
ensure_admin_control_tables();

$loginError = '';
$selectedRole = '';

if (isset($_SESSION['user'])) {
    header('Location: ' . role_destination((string) ($_SESSION['user']['role'] ?? '')));
    exit;
}

$roles = db()->query('SELECT role_name FROM roles ORDER BY role_name')->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedLanguage = $_POST['language'] ?? app_language();
    if (is_string($selectedLanguage) && in_array($selectedLanguage, ['en', 'fr'], true)) {
        set_app_language($selectedLanguage);
    }

    $roleValue = $_POST['role'] ?? '';
    $selectedRole = is_string($roleValue) ? trim($roleValue) : '';
    $role = $selectedRole;
    $passwordValue = $_POST['password'] ?? '';
    $password = is_string($passwordValue) ? $passwordValue : '';

    if ($role === '' || $password === '') {
        $loginError = 'Select your role and enter your password.';
    } elseif (!in_array($role, $roles, true)) {
        $loginError = 'Select a valid role.';
    } else {
        $statement = db()->prepare(
            "SELECT users.*, roles.role_name, roles.responsibilities
             FROM users
             JOIN roles ON users.role_id = roles.id
             WHERE roles.role_name = ?
             ORDER BY users.id"
        );
        $statement->execute([$role]);
        $user = null;
        while ($candidate = $statement->fetch()) {
            if (password_verify($password, (string) $candidate['password_hash'])) {
                $user = $candidate;
                break;
            }
        }

        if ($user === null) {
            $loginError = 'Invalid role or password.';
            log_system_event('login_failed', 'Failed sign-in attempt for role ' . substr($role, 0, 60) . '.');
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int) $user['id'],
                'full_name' => (string) $user['full_name'],
                'email' => (string) $user['email'],
                'role' => (string) $user['role_name'],
                'student_id' => $user['student_id'] !== null ? (int) $user['student_id'] : null,
                'responsibilities' => (string) $user['responsibilities'],
            ];

            log_system_event('login_success', 'User signed in successfully.');
            header('Location: ' . role_destination((string) $user['role_name']));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Secure sign in to the Mandela International French School information management system">
    <title>Sign in | Mandela International French School</title>
    <style>
        :root {
            --navy: #092d55;
            --gold: #f1be45;
            --ink: #18324f;
            --muted: #74859a;
            --shadow: 0 18px 48px rgba(9, 45, 85, 0.12);
            --line: #dfe7f0;
            --error: #b42318;
            --error-bg: #fff1f1;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            min-height: 100vh;
            color: var(--ink);
            background: linear-gradient(180deg, #edf3fa 0%, #f6f8fb 100%);
            font-family: Arial, Helvetica, sans-serif;
        }

        .page {
            display: grid;
            min-height: 100vh;
            grid-template-columns: minmax(340px, 1fr) minmax(420px, 500px);
        }

        .hero {
            position: relative;
            display: flex;
            align-items: center;
            padding: 8%;
            overflow: hidden;
            color: white;
            background: linear-gradient(140deg, #061e3c 0%, var(--navy) 48%, #126195 100%);
        }

        .hero::before,
        .hero::after {
            position: absolute;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 50%;
            content: '';
        }

        .hero::before {
            width: 520px;
            height: 520px;
            right: -220px;
            top: -160px;
        }

        .hero::after {
            width: 350px;
            height: 350px;
            left: -190px;
            bottom: -160px;
        }

        .hero-content {
            position: relative;
            z-index: 1;
            max-width: 560px;
        }

        .crest {
            display: grid;
            width: 180px;
            height: 180px;
            margin-bottom: 28px;
            place-items: center;
            border: 4px solid var(--gold);
            border-radius: 50%;
            color: var(--navy);
            background: var(--gold);
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -1px;
            overflow: hidden;
            box-shadow: 0 12px 28px rgba(0,0,0,0.12);
        }

        .crest img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .eyebrow {
            margin: 0 0 14px;
            color: #f9d77d;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        h1 {
            max-width: 520px;
            margin: 0;
            font-size: clamp(34px, 4vw, 58px);
            line-height: 1.08;
        }

        .hero p:not(.eyebrow) {
            max-width: 460px;
            margin: 22px 0 0;
            color: #d7e9f7;
            font-size: 16px;
            line-height: 1.65;
        }

        .hero-line {
            width: 75px;
            height: 4px;
            margin-top: 28px;
            border-radius: 5px;
            background: var(--gold);
        }

        .login-side {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            padding: 40px;
        }

        .language-picker {
            position: absolute;
            top: 24px;
            right: 30px;
            margin: 0;
            padding: 0;
            border: 0;
            z-index: 3;
        }

        .language-picker legend {
            margin-bottom: 8px;
            color: #73859a;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            text-align: right;
        }

        .language-options {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px;
            border: 1px solid #dfe7f0;
            border-radius: 999px;
            background: #f3f7fb;
            box-shadow: 0 8px 18px rgba(17, 40, 66, 0.06);
        }

        .language-option {
            position: relative;
            margin: 0;
            cursor: pointer;
        }

        .language-option input {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .language-option span {
            display: grid;
            place-items: center;
            min-width: 90px;
            min-height: 34px;
            padding: 7px 14px;
            border: 1px solid transparent;
            border-radius: 999px;
            color: #52677e;
            background: transparent;
            font-size: 12px;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .language-option input:checked + span {
            color: var(--navy);
            background: linear-gradient(135deg, #f7d77a, #f1be45);
            border-color: rgba(9, 45, 85, 0.18);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.35);
        }

        .language-option input:focus-visible + span {
            box-shadow: 0 0 0 4px rgba(59, 130, 184, 0.12);
            outline: none;
        }

        .language-option:hover span {
            border-color: rgba(59, 130, 184, 0.2);
            background: rgba(59, 130, 184, 0.04);
        }

        .box {
            width: 100%;
            max-width: 380px;
        }

        .brand-small {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
            color: var(--navy);
            font-size: 13px;
            font-weight: 700;
        }

        .brand-small span {
            display: grid;
            width: 58px;
            height: 58px;
            place-items: center;
            border-radius: 50%;
            color: var(--navy);
            background: #fff1c9;
            font-size: 11px;
            overflow: hidden;
            flex: 0 0 auto;
        }

        .brand-small img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        h2 {
            margin: 0;
            color: var(--ink);
            font-size: 30px;
            line-height: 1.2;
        }

        .subtitle {
            margin: 9px 0 24px;
            color: var(--muted);
            line-height: 1.5;
        }

        label {
            display: block;
            margin: 17px 0 7px;
            color: #405775;
            font-size: 12px;
            font-weight: 700;
        }

        input, select {
            width: 100%;
            padding: 14px 15px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--ink);
            background: #fbfcfe;
            font: inherit;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        input:focus, select:focus {
            border-color: #3b82b8;
            box-shadow: 0 0 0 4px rgba(59, 130, 184, 0.12);
            outline: none;
            background: #fff;
        }

        button {
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            border: 0;
            border-radius: 8px;
            color: #142f4e;
            background: linear-gradient(135deg, #f4c557, #e8aa2d);
            box-shadow: 0 8px 16px rgba(206, 149, 26, 0.2);
            cursor: pointer;
            font-weight: 700;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 11px 20px rgba(206, 149, 26, 0.3);
        }

        .alert {
            display: block;
            margin-top: 16px;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #f3c9c9;
            color: var(--error);
            background: var(--error-bg);
            font-size: 13px;
            line-height: 1.5;
        }

        .footer-note {
            margin-top: 28px;
            color: #96a3b2;
            font-size: 11px;
            text-align: center;
        }

        @media (max-width: 820px) {
            .page { display: block; }
            .hero { min-height: 340px; padding: 42px 8%; }
            .crest { width: 140px; height: 140px; }
            .hero h1 { font-size: 38px; }
            .login-side { padding: 45px 8%; }
            .language-picker {
                position: static;
                margin-bottom: 18px;
                display: flex;
                justify-content: flex-end;
            }
            .language-picker legend {
                color: #5f7589;
            }
            .language-options {
                background: #f4f8fb;
                border-color: #dbe6ef;
            }
        }

        @media (max-width: 460px) {
            .hero { min-height: 300px; }
            .crest { width: 112px; height: 112px; }
            .hero p:not(.eyebrow) { font-size: 14px; }
            .login-side { padding: 35px 7%; }
            .language-option span { min-width: 74px; }
        }
    </style>
</head>
<body>
    <main class="page">
        <section class="hero" aria-label="School information">
            <div class="hero-content">
                <div class="crest">
                    <img src="assets/logo.png" alt="Mandela International French School logo">
                </div>
                <p class="eyebrow">Our central mission</p>
                <h1>Promoting academic excellence and diversity through education.</h1>
                <p>Raising the voices of tomorrow through quality intercultural education, where every student is encouraged to become a global citizen.</p>
                <div class="hero-line" aria-hidden="true"></div>
            </div>

        </section>
        <section class="login-side" aria-label="Sign in">
            <fieldset class="language-picker" id="login-language">
                <legend>Language / Langue</legend>
                <div class="language-options">
                    <label class="language-option">
                        <input type="radio" name="language" value="en" form="login-form" <?= app_language() === 'en' ? 'checked' : '' ?>>
                        <span>English</span>
                    </label>
                    <label class="language-option">
                        <input type="radio" name="language" value="fr" form="login-form" <?= app_language() === 'fr' ? 'checked' : '' ?>>
                        <span>Français</span>
                    </label>
                </div>
            </fieldset>

            <div class="box">
                <div class="brand-small">
                    <span><img src="assets/logo.png" alt=""></span>
                    Mandela International French School
                </div>

                <h2>Sign in</h2>
                <p class="subtitle">Choose your role to continue to your secure workspace.</p>

                <?php if ($loginError !== ''): ?>
                    <div class="alert" role="alert"><?= e($loginError) ?></div>
                <?php endif; ?>

                <form id="login-form" method="post" action="<?= e((string) ($_SERVER['PHP_SELF'] ?? '')) ?>" autocomplete="off">
                    <label for="role">Your role</label>
                    <select id="role" name="role" required>
                        <option value="">Choose role</option>
                        <?php foreach ($roles as $roleName): ?>
                            <option value="<?= e((string) $roleName) ?>" <?= $selectedRole === $roleName ? 'selected' : '' ?>>
                                <?= e((string) $roleName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" placeholder="Enter your assigned password" autocomplete="current-password" required>

                    <button type="submit">Continue to workspace</button>
                </form>

                <div class="footer-note">Your access is limited to the responsibilities assigned to your role.</div>
            </div>
        </section>
    </main>
</body>
</html>