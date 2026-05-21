<?php
// --- send.php ---
// Sends composed email messages using the email utility functions.
require('./email_utils.php');

$success = false;
$error = '';

if (isset($_POST['send'])) {
    $to      = filter_var($_POST['to_email'], FILTER_VALIDATE_EMAIL);
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);

    if (!$to || empty($subject) || empty($message)) {
        $error = 'Invalid input. Please fill in all fields.';
    } elseif (send_email($to, '', $subject, nl2br(htmlspecialchars($message)))) {
        $success = true;
    } else {
        $error = 'Failed to send message. Please try again later or contact support.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Sent | Work Immersion Monitoring System</title>
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
        .response-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 500px;
        }
        .response-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 32px;
            box-shadow: 0 30px 80px rgba(15, 58, 40, 0.22);
            overflow: hidden;
            backdrop-filter: blur(12px);
        }
        .response-card .card-body {
            padding: 2.25rem 2rem;
            text-align: center;
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
        .response-icon {
            font-size: 3.5rem;
            margin-bottom: 1rem;
        }
        .response-icon.success {
            color: #198754;
        }
        .response-icon.error {
            color: #dc3545;
        }
        .response-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .response-text {
            color: var(--muted);
            margin-bottom: 1.5rem;
            font-size: 1rem;
        }
        .btn-group-custom {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .btn-custom {
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
        .btn-custom:hover {
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
        @media (min-width: 576px) {
            .btn-group-custom {
                flex-direction: row;
                justify-content: center;
            }
            .btn-custom, .btn-outline-custom {
                min-width: 150px;
            }
        }
        @media (max-width: 576px) {
            .response-card .card-body {
                padding: 1.75rem 1.5rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="response-container">
        <div class="card response-card">
            <div class="card-body">
                <div class="brand-icon">
                    <img src="psnhs.jpeg" alt="School Logo">
                </div>
                <h1 class="login-title">Pulung Santol National High School</h1>
                <p class="login-subtitle">Email Status • Work Immersion Monitoring System</p>
                <?php if ($success): ?>
                    <div class="response-icon success">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <h1 class="response-title text-success">Message Sent!</h1>
                    <p class="response-text">Your email has been sent successfully. The recipient will receive it shortly.</p>
                    <div class="btn-group-custom">
                        <a href="email.php" class="btn btn-custom">Send Another</a>
                        <a href="admin.php?tab=announcements" class="btn btn-outline-custom">Back to Announcements</a>
                    </div>
                <?php else: ?>
                    <div class="response-icon error">
                        <i class="bi bi-x-circle-fill"></i>
                    </div>
                    <h1 class="response-title text-danger">Failed to Send</h1>
                    <p class="response-text"><?= htmlspecialchars($error) ?></p>
                    <div class="btn-group-custom">
                        <a href="email.php" class="btn btn-custom">Try Again</a>
                        <a href="admin.php?tab=announcements" class="btn btn-outline-custom">Back to Announcements</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

//Nicole Sambile
//John Paul Santos
//Jessica Salalila