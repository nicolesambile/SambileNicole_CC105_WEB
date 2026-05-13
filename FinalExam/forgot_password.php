<?php
// --- forgot_password.php ---
// Handles password reset requests by generating reset codes and emailing users.
session_start();
require('./connection.php');
require('./email_utils.php');

function normalize_role($role) {
    $role = strtolower(trim($role ?? ''));
    if ($role === 'supervisor' || $role === 'owner/supervisor') {
        return 'owner_supervisor';
    }
    if ($role === 'user') {
        return 'student';
    }
    return $role;
}

function ensure_reset_columns($connection) {
    mysqli_query($connection, "ALTER TABLE isfinals ADD COLUMN IF NOT EXISTS reset_code VARCHAR(255) DEFAULT NULL");
    mysqli_query($connection, "ALTER TABLE isfinals ADD COLUMN IF NOT EXISTS reset_expiry DATETIME DEFAULT NULL");
}

ensure_reset_columns($connection);

$error = '';
$message = '';

if (isset($_POST['request_reset'])) {
    $identifier = trim($_POST['identifier'] ?? '');
    $selected_role = normalize_role($_POST['role'] ?? '');

    if ($identifier === '' || $selected_role === '') {
        $error = 'Please enter your email or username and select your role.';
    } else {
        $identifier_safe = mysqli_real_escape_string($connection, $identifier);
        $query = "SELECT * FROM isfinals WHERE email='$identifier_safe' OR username='$identifier_safe' LIMIT 1";
        $result = mysqli_query($connection, $query);

        if ($result && mysqli_num_rows($result) > 0) {
            $user = mysqli_fetch_assoc($result);
            $db_role = normalize_role($user['role'] ?? '');
            if ($db_role !== $selected_role) {
                $error = 'Role mismatch. Please select the correct role for your account.';
            } else {
                $code = random_int(100000, 999999);
                $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $code_hash = password_hash((string) $code, PASSWORD_DEFAULT);
                $update = "UPDATE isfinals SET reset_code='" . mysqli_real_escape_string($connection, $code_hash) . "', reset_expiry='$expiry' WHERE id='" . mysqli_real_escape_string($connection, $user['id']) . "'";
                mysqli_query($connection, $update);

                // Send email with reset code
                if (send_reset_email($user['email'], $user['fullname'], $code)) {
                    $message = 'A reset code has been sent to your email address. Please check your inbox and use the code within 1 hour to reset your password.';
                } else {
                    $error = 'Failed to send reset email. Please try again later or contact support.';
                }
            }
        } else {
            $error = 'No account was found with that email or username.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Work Immersion Monitoring System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --page-bg: linear-gradient(180deg, rgba(15, 58, 40, 0.72), rgba(15, 58, 40, 0.18)), url('psback.jpg');
            --primary: #0f3a28;
            --surface: rgba(255, 255, 255, 0.96);
            --border: rgba(15, 58, 40, 0.12);
            --muted: #4b5563;
        }
        * {
            box-sizing: border-box;
        }
        body {
            min-height: 100vh;
            margin: 0;
            padding: 1.5rem;
            color: #111827;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--page-bg);
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: grid;
            place-items: center;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(15, 58, 40, 0.32);
            pointer-events: none;
            z-index: 0;
        }
        .forgot-container {
            position: relative;
            z-index: 1;
            padding: 20px 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .forgot-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 32px;
            box-shadow: 0 30px 80px rgba(15, 58, 40, 0.22);
            overflow: hidden;
            backdrop-filter: blur(12px);
        }
        .forgot-card .card-body {
            padding: 2.25rem 2rem;
        }
        .brand-icon {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            margin: 0 auto 1rem;
            border: 1px solid rgba(15, 58, 40, 0.12);
            background: white;
            display: grid;
            place-items: center;
            overflow: hidden;
        }
        .brand-icon img {
            width: 72px;
            height: 72px;
            object-fit: contain;
        }
        .login-title {
            font-size: 1.95rem;
            font-weight: 800;
            text-align: center;
            margin-bottom: 0.25rem;
        }
        .login-subtitle {
            text-align: center;
            color: var(--muted);
            font-size: 0.96rem;
            margin-bottom: 1.75rem;
        }
        .card-header-custom {
            background: transparent;
            padding: 0;
            color: inherit;
            text-align: center;
        }
        .card-header-custom h2 {
            font-weight: 700;
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }
        .card-header-custom p {
            font-size: 0.95rem;
            opacity: 0.95;
            margin: 0;
        }
        .form-label {
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.45rem;
            font-size: 0.95rem;
        }
        .form-control, .form-select {
            width: 100%;
            border: 1px solid rgba(15, 58, 40, 0.12);
            border-radius: 16px;
            padding: 0.95rem 1rem 0.95rem 3rem;
            font-size: 0.95rem;
            transition: border-color 0.25s ease, box-shadow 0.25s ease;
            background: #ffffff;
        }
        .form-control:focus, .form-select:focus {
            border-color: rgba(15, 58, 40, 0.28);
            box-shadow: 0 0 0 0.15rem rgba(15, 58, 40, 0.12);
            outline: none;
        }
        .input-icon {
            position: relative;
        }
        .input-icon > i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 1rem;
            line-height: 1;
        }
        .input-icon > i + input,
        .input-icon > i + select,
        .input-icon > i + textarea {
            padding-left: 2.8rem;
        }
        .input-icon label i {
            position: static;
            margin-right: 0.45rem;
            vertical-align: middle;
            color: var(--muted);
            font-size: 1rem;
            line-height: 1;
        }
        .btn-submit {
            width: 100%;
            padding: 0.95rem 1.25rem;
            font-weight: 700;
            font-size: 1rem;
            color: #fff;
            background: linear-gradient(135deg, #1f744f 0%, var(--primary) 100%);
            border: none;
            border-radius: 16px;
            letter-spacing: 0.02em;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(15, 58, 40, 0.22);
        }
        .alert {
            border: none;
            border-radius: 8px;
            border-left: 4px solid;
        }
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left-color: #22c55e;
        }
        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left-color: #ef4444;
        }
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        .back-link a {
            color: #6366f1;
            text-decoration: none;
            font-weight: 600;
        }
        .back-link a:hover {
            text-decoration: underline;
        }
        @media (max-width: 576px) {
            .card-body {
                padding: 1.5rem;
            }
            .card-header-custom {
                padding: 1.5rem 1rem;
            }
            .card-header-custom h2 {
                font-size: 1.5rem;
            }

            /* Better touch targets for mobile */
            .btn {
                min-height: 44px;
                padding: 0.75rem 1.5rem;
            }

            .form-control, .form-select {
                min-height: 44px;
            }

            /* Better modal experience on mobile */
            .modal-dialog {
                margin: 0.5rem;
            }
        }
    </style>
</head>
<body>
<div class="container forgot-container">
    <div class="row justify-content-center w-100">
        <div class="col-lg-5 col-md-7 col-sm-9">
            <div class="card forgot-card">
                <div class="card-body">
                    <div class="brand-icon">
                        <img src="psnhs.jpeg" alt="School Logo">
                    </div>
                    <h1 class="login-title">Pulung Santol National High School</h1>
                    <p class="login-subtitle">Forgot Password • Work Immersion Monitoring System</p>
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-circle-fill me-2"></i>
                            <?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?= $message ?>
                            <div class="mt-3">
                                <a href="reset_password.php" class="btn btn-sm btn-submit"><i class="bi bi-arrow-right me-1"></i>Go to Reset Password</a>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mb-4">Enter your email or username and select your role to receive a password reset code.</p>

                    <form method="post">
                        <div class="mb-3 input-icon">
                            <label class="form-label" for="identifier">Email or Username</label>
                            <i class="bi bi-envelope"></i>
                            <input id="identifier" type="text" name="identifier" class="form-control" placeholder="Enter your email or username" value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" required>
                        </div>
                        <div class="mb-4 input-icon">
                            <label class="form-label" for="role">Select Your Role</label>
                            <i class="bi bi-briefcase"></i>
                            <select id="role" name="role" class="form-select" required>
                                <option value="">-- Choose your role --</option>
                                <option value="student" <?= (isset($_POST['role']) && $_POST['role'] === 'student') ? 'selected' : '' ?>>🎓 Student</option>
                                <option value="owner_supervisor" <?= (isset($_POST['role']) && $_POST['role'] === 'owner_supervisor') ? 'selected' : '' ?>>👔 Owner/Supervisor</option>
                            </select>
                        </div>
                        <div class="d-grid mb-3">
                            <button type="submit" name="request_reset" class="btn btn-submit btn-lg"><i class="bi bi-send me-2"></i>GENERATE RESET CODE</button>
                        </div>
                    </form>

                    <div class="back-link">
                        <a href="login.php"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
