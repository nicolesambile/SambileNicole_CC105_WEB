<!DOCTYPE html>
<!-- index.php - Registration page for new users to sign up for the Work Immersion Monitoring System -->
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration | Work Immersion Monitoring System</title>
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
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--page-bg);
            background-size: cover;
            background-position: center;
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
        .registration-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 520px;
        }
        .registration-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 32px;
            box-shadow: 0 30px 80px rgba(15, 58, 40, 0.22);
            overflow: hidden;
            backdrop-filter: blur(12px);
        }
        .card-header-custom {
            background: #ffffff;
            border-bottom: 1px solid rgba(15, 58, 40, 0.08);
            padding: 2rem 1.75rem;
            text-align: center;
        }
        .card-header-custom h2 {
            font-weight: 800;
            font-size: 1.85rem;
            line-height: 1.1;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }
        .card-header-custom p {
            font-size: 0.98rem;
            color: var(--muted);
            margin: 0;
        }
        .card-body {
            padding: 2.25rem 2rem 2rem;
        }
        .form-label {
            display: block;
            margin-bottom: 0.45rem;
            font-weight: 600;
            color: #111827;
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
        .btn-register {
            width: 100%;
            background: linear-gradient(135deg, #1f744f 0%, var(--primary) 100%);
            border: none;
            padding: 0.95rem 1.25rem;
            font-weight: 700;
            font-size: 1rem;
            color: #fff;
            border-radius: 16px;
            letter-spacing: 0.02em;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(15, 58, 40, 0.22);
        }
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(15, 58, 40, 0.08);
        }
        .login-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
            transition: color 0.25s ease;
        }
        .login-link a:hover {
            color: #1f744f;
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
        @media (max-width: 576px) {
            .card-body {
                padding: 1.75rem 1.5rem 1.5rem;
            }
            .card-header-custom {
                padding: 1.75rem 1.5rem;
            }
            .card-header-custom h2 {
                font-size: 1.6rem;
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
    <div class="registration-container">
        <div class="registration-card">
            <div class="card-body">
                <div class="brand-icon">
                    <img src="psnhs.jpeg" alt="School Logo">
                </div>
                <div class="text-center mb-4">
                    <h2 class="login-title">Pulung Santol National High School</h2>
                    <p class="login-subtitle">Work Immersion Monitoring System</p>
                </div>
                <form action="save.php" method="post">
                    <div class="mb-3 input-icon">
                        <label class="form-label" for="fullName">Full Name</label>
                        <i class="bi bi-person"></i>
                        <input id="fullName" type="text" name="FN" placeholder="Juan dela Cruz" class="form-control" required />
                    </div>
                    <div class="mb-3 input-icon">
                        <label class="form-label" for="emailAddress">Email Address</label>
                        <i class="bi bi-envelope"></i>
                        <input id="emailAddress" type="email" name="EMAIL" placeholder="Example@gmail.com" class="form-control" required />
                    </div>
                    <div class="mb-3 input-icon">
                        <label class="form-label" for="username">Username</label>
                        <i class="bi bi-at"></i>
                        <input id="username" type="text" name="UN" placeholder="Choose a username" class="form-control" required />
                    </div>
                    <div class="mb-3 input-icon">
                        <label class="form-label" for="password">Password</label>
                        <i class="bi bi-lock"></i>
                        <input id="password" type="password" name="PASSWORD" placeholder="Enter a strong password" class="form-control" required />
                    </div>
                    <div class="mb-3 input-icon">
                        <label class="form-label" for="confirmPassword">Confirm Password</label>
                        <i class="bi bi-lock-fill"></i>
                        <input id="confirmPassword" type="password" name="CONFIRM_PASSWORD" placeholder="Confirm your password" class="form-control" required />
                    </div>
                    <div class="mb-3 input-icon">
                        <label class="form-label" for="contactNumber">Contact Number</label>
                        <i class="bi bi-telephone"></i>
                        <input id="contactNumber" type="tel" name="CONTACT_NUMBER" placeholder="09XXXXXXXXX" class="form-control" required />
                    </div>
                    <div class="mb-4 input-icon">
                        <label class="form-label" for="roleSelect">Select Your Role</label>
                        <i class="bi bi-briefcase"></i>
                        <select id="roleSelect" name="ROLE" class="form-select" required>
                            <option value="">-- Choose your role --</option>
                            <option value="student">👨‍🎓Student</option>
                            <option value="owner_supervisor">👔Owner/Supervisor</option>
                        </select>
                    </div>
                    <div class="d-grid mb-3">
                        <button type="submit" name="save" class="btn btn-register btn-lg"><i class="bi bi-check-circle me-2"></i>CREATE ACCOUNT</button>
                    </div>
                </form>
                <div class="login-link">
                    <small class="text-muted">Already have an account?</small><br>
                    <a href="login.php"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in here</a>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>