<?php
// --- login.php ---
// Login page for students and owner/supervisors. Displays errors and success messages.
$reset_success = isset($_GET['reset']) && $_GET['reset'] === 'success';
$login_error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - WIMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
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
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
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
            background: rgba(15, 58, 40, 0.38);
            pointer-events: none;
            z-index: 0;
        }
        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 430px;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid var(--border);
            border-radius: 32px;
            box-shadow: 0 30px 80px rgba(15, 58, 40, 0.22);
            overflow: hidden;
            backdrop-filter: blur(12px);
        }
        .login-card .card-body {
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
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #111827;
        }
        .form-control,
        .form-select {
            width: 100%;
            border: 1px solid rgba(15, 58, 40, 0.12);
            border-radius: 16px;
            padding: 0.95rem 1rem 0.95rem 3rem;
            font-size: 0.95rem;
            transition: border-color 0.25s ease, box-shadow 0.25s ease;
            background: #ffffff;
        }
        .form-control:focus,
        .form-select:focus {
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
        .btn-login {
            width: 100%;
            padding: 0.95rem 1rem;
            border: none;
            border-radius: 16px;
            font-size: 0.98rem;
            font-weight: 700;
            color: #ffffff;
            background: linear-gradient(135deg, #1f744f 0%, var(--primary) 100%);
            box-shadow: 0 16px 30px rgba(15, 58, 40, 0.24);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 36px rgba(15, 58, 40, 0.28);
        }
        .options-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 1rem 0 1.25rem;
        }
        .options-row label {
            font-size: 0.92rem;
            color: var(--muted);
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-weight: 500;
        }
        .options-row a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 700;
        }
        .options-row a:hover {
            text-decoration: underline;
        }
        .secure-note {
            margin-top: 1rem;
            text-align: center;
            font-size: 0.86rem;
            color: var(--muted);
        }
        .alert {
            border-radius: 14px;
            border: none;
        }
        @media (max-width: 576px) {
            .login-card .card-body {
                padding: 1.75rem 1.5rem;
            }
            .login-title {
                font-size: 1.6rem;
            }
            .options-row {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            .options-row a {
                margin-top: 0.5rem;
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
<div class="login-container">
    <div class="card login-card">
        <div class="card-body">
            <?php if (!empty($reset_success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <strong>Success!</strong> Password reset successful. Please log in with your new password.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (!empty($login_error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?= $login_error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="brand-icon">
                <img src="psnhs.jpeg" alt="School Logo">
            </div>
            <h1 class="login-title">Pulung Santol National High School</h1>
            <p class="login-subtitle">Work Immersion Monitoring System</p>

            <form action="check_login.php" method="post">
                <div class="mb-3 input-icon">
                    <label class="form-label" for="loginEmail">Email or Username</label>
                    <i class="bi bi-envelope"></i>
                    <input id="loginEmail" type="text" name="EMAIL" class="form-control" placeholder="Example@gmail.com" required />
                </div>

                <div class="mb-3 input-icon">
                    <label class="form-label" for="loginPassword">Password</label>
                    <i class="bi bi-lock"></i>
                    <input id="loginPassword" type="password" name="PASSWORD" class="form-control" placeholder="Enter your password" required />
                </div>

                <div class="mb-3 input-icon">
                    <label class="form-label" for="loginRole">Role</label>
                    <i class="bi bi-person-badge-fill"></i>
                    <select id="loginRole" name="ROLE" class="form-select" required>
                        <option value="">-- Select Your Role --</option>
                        <option value="student">Student</option>
                        <option value="owner_supervisor">Owner/Supervisor</option>
                    </select>
                </div>

                <div class="options-row">
                    <label><input type="checkbox" name="remember" /> Remember me</label>
                    <a href="forgot_password.php">Forgot password?</a>
                </div>

                <button type="submit" name="login" class="btn btn-login">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                </button>
            </form>

            <div class="options-row">
                <span>Don't have an account?</span>
                <a href="index.php">Create an account</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>