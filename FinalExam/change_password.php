<?php
session_start();
$error = '';
if (isset($_SESSION['change_password_error'])) {
    $error = $_SESSION['change_password_error'];
    unset($_SESSION['change_password_error']);
}
?>
<!DOCTYPE html>
<!-- change_password.php - Displays the change password form for logged-in users. -->
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | Work Immersion Monitoring System</title>
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
        .change-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 520px;
        }
        .change-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 32px;
            box-shadow: 0 30px 80px rgba(15, 58, 40, 0.22);
            overflow: hidden;
            backdrop-filter: blur(12px);
        }
        .change-card .card-body {
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
        .btn-change {
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
        .btn-change:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(15, 58, 40, 0.22);
        }
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(15, 58, 40, 0.08);
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
            .change-card .card-body {
                padding: 1.75rem 1.5rem 1.5rem;
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
    <div class="change-container">
        <div class="change-card">
            <div class="card-body">
                <div class="brand-icon">
                    <img src="psnhs.jpeg" alt="School Logo">
                </div>
                <h1 class="login-title">Pulung Santol National High School</h1>
                <p class="login-subtitle">Change Password • Work Immersion Monitoring System</p>
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <form action="update_password.php" method="post">
                    <div class="mb-3 input-icon">
                        <label class="form-label" for="old_password">Old Password</label>
                        <i class="bi bi-lock"></i>
                        <input id="old_password" type="password" name="old_password" placeholder="Enter your old password" class="form-control" required />
                    </div>
                    <div class="mb-3 input-icon">
                        <label class="form-label" for="new_password">New Password</label>
                        <i class="bi bi-lock-fill"></i>
                        <input id="new_password" type="password" name="new_password" placeholder="Enter a new password" class="form-control" required />
                    </div>
                    <div class="mb-4 input-icon">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <i class="bi bi-lock-fill"></i>
                        <input id="confirm_password" type="password" name="confirm_password" placeholder="Confirm your new password" class="form-control" required />
                    </div>
                    <div class="d-grid mb-3">
                        <button type="submit" name="change" class="btn btn-change btn-lg"><i class="bi bi-check-circle me-2"></i>CHANGE PASSWORD</button>
                    </div>
                </form>
                <div class="back-link">
                    <a href="dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

//Nicole Sambile
//John Paul Santos
//Jessica Salalila