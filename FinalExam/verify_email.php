<?php
// --- verify_email.php ---
// Verifies user email addresses with a submitted verification code.
session_start();
require('./connection.php');

$error = '';
$message = '';

if (isset($_POST['verify_email'])) {
    $email = trim($_POST['email'] ?? '');
    $verification_code = trim($_POST['verification_code'] ?? '');

    if (empty($email) || empty($verification_code)) {
        $error = 'Please enter both email and verification code.';
    } else {
        $email_safe = mysqli_real_escape_string($connection, $email);
        $query = "SELECT * FROM isfinals WHERE email='$email_safe' AND email_verified=0 LIMIT 1";
        $result = mysqli_query($connection, $query);

        if ($result && mysqli_num_rows($result) > 0) {
            $user = mysqli_fetch_assoc($result);

            if (empty($user['verification_code']) || empty($user['verification_expiry'])) {
                $error = 'No verification code found for this account. Please register again.';
            } elseif (strtotime($user['verification_expiry']) < time()) {
                $error = 'The verification code has expired. Please register again.';
            } elseif (!password_verify($verification_code, $user['verification_code'])) {
                $error = 'Invalid verification code. Please check the code and try again.';
            } else {
                // Mark email as verified
                $update = "UPDATE isfinals SET email_verified=1, verification_code=NULL, verification_expiry=NULL WHERE id='" . mysqli_real_escape_string($connection, $user['id']) . "'";
                mysqli_query($connection, $update);

                $message = 'Email verified successfully! You can now log in to your account.';
            }
        } else {
            $error = 'No unverified account found with this email address.';
        }
    }
}

if (isset($_POST['resend_code'])) {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        $email_safe = mysqli_real_escape_string($connection, $email);
        $query = "SELECT * FROM isfinals WHERE email='$email_safe' AND email_verified=0 LIMIT 1";
        $result = mysqli_query($connection, $query);

        if ($result && mysqli_num_rows($result) > 0) {
            $user = mysqli_fetch_assoc($result);

            // Generate new verification code
            $verification_code = random_int(100000, 999999);
            $verification_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $code_hash = password_hash((string) $verification_code, PASSWORD_DEFAULT);

            $update = "UPDATE isfinals SET verification_code='$code_hash', verification_expiry='$verification_expiry' WHERE id='" . mysqli_real_escape_string($connection, $user['id']) . "'";
            mysqli_query($connection, $update);

            // Send verification email
            require('./email_utils.php');
            if (send_verification_email($user['email'], $user['fullname'], $verification_code)) {
                $message = 'A new verification code has been sent to your email address.';
            } else {
                $error = 'Failed to send verification email. Please try again later.';
            }
        } else {
            $error = 'No unverified account found with this email address.';
        }
    }
}

// Get email from URL parameter
$email_from_url = isset($_GET['email']) ? trim($_GET['email']) : '';
$registered = isset($_GET['registered']) && $_GET['registered'] == '1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification | Work Immersion Monitoring System</title>
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
        .verification-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 520px;
        }
        .verification-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 32px;
            box-shadow: 0 30px 80px rgba(15, 58, 40, 0.22);
            overflow: hidden;
            backdrop-filter: blur(12px);
        }
        .verification-card .card-body {
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
        .form-label {
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.45rem;
            font-size: 0.95rem;
        }
        .form-control {
            width: 100%;
            border: 1px solid rgba(15, 58, 40, 0.12);
            border-radius: 16px;
            padding: 0.95rem 1rem 0.95rem 3rem;
            font-size: 0.95rem;
            transition: border-color 0.25s ease, box-shadow 0.25s ease;
            background: #ffffff;
        }
        .form-control:focus {
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
        .verification-code-input {
            font-size: 1.5rem;
            letter-spacing: 0.5rem;
            text-align: center;
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }
        .btn-verify {
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
        .btn-verify:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(15, 58, 40, 0.22);
        }
        .btn-outline-custom {
            width: 100%;
            padding: 0.95rem 1.25rem;
            font-weight: 700;
            font-size: 1rem;
            color: var(--primary);
            background: transparent;
            border: 1px solid var(--primary);
            border-radius: 16px;
            letter-spacing: 0.02em;
            transition: all 0.25s ease;
        }
        .btn-outline-custom:hover {
            background: var(--primary);
            color: #fff;
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
        .help-text {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(15, 58, 40, 0.08);
        }
        .help-text a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
        }
        .help-text a:hover {
            text-decoration: underline;
        }
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        .back-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
        }
        .back-link a:hover {
            text-decoration: underline;
        }
        @media (max-width: 576px) {
            .verification-card .card-body {
                padding: 1.75rem 1.5rem 1.5rem;
            }
            .verification-code-input {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <div class="card verification-card">
            <div class="card-body">
                <div class="brand-icon">
                    <img src="psnhs.jpeg" alt="School Logo">
                </div>
                <h1 class="login-title">Pulung Santol National High School</h1>
                <p class="login-subtitle">Email Verification • Work Immersion Monitoring System</p>
                <?php if ($registered): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <strong>Registration successful!</strong> Please check your email for the verification code.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

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
                        <?= htmlspecialchars($message) ?>
                        <?php if (strpos($message, 'verified successfully') !== false): ?>
                            <div class="mt-3">
                                <a href="login.php" class="btn btn-sm btn-verify"><i class="bi bi-box-arrow-in-right me-1"></i>Go to Login</a>
                            </div>
                        <?php endif; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email_from_url) ?>">

                    <div class="mb-3 input-icon">
                        <label class="form-label" for="email_display">Email Address</label>
                        <i class="bi bi-envelope"></i>
                        <input id="email_display" type="email" name="email_display" class="form-control" value="<?= htmlspecialchars($email_from_url) ?>" readonly>
                    </div>

                    <div class="mb-4 input-icon">
                        <label class="form-label" for="verification_code">Verification Code</label>
                        <i class="bi bi-shield-check"></i>
                        <input id="verification_code" type="text" name="verification_code" class="form-control verification-code-input" placeholder="000000" required maxlength="6" inputmode="numeric">
                        <small class="text-muted d-block mt-2">Enter the 6-digit code sent to your email</small>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" name="verify_email" class="btn btn-verify btn-lg"><i class="bi bi-check-circle me-2"></i>VERIFY EMAIL</button>
                    </div>
                </form>

                <form method="post">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email_from_url) ?>">
                    <div class="help-text">
                        <small class="text-muted">Didn't receive the code?</small><br>
                        <button type="submit" name="resend_code" class="btn btn-link btn-sm p-0"><i class="bi bi-arrow-repeat me-1"></i>Resend Verification Code</button>
                    </div>
                </form>

                <div class="back-link">
                    <a href="login.php"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>