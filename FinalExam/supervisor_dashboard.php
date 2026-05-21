<?php
// --- supervisor_dashboard.php ---
// Displays the supervisor dashboard and supervisor-only data views.
session_start();
date_default_timezone_set('Asia/Manila');
require('./connection.php');

// Ensure supervisor contact number column exists
mysqli_query($connection, "ALTER TABLE isfinals ADD COLUMN IF NOT EXISTS contact_number VARCHAR(20) DEFAULT NULL");
// Ensure students table has supervisor assignment column
mysqli_query($connection, "ALTER TABLE students ADD COLUMN IF NOT EXISTS supervisor_id INT DEFAULT NULL");
// Ensure separate supervisor profile table exists
mysqli_query($connection, "CREATE TABLE IF NOT EXISTS supervisors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    fullname VARCHAR(255) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    company VARCHAR(255) DEFAULT NULL,
    contact_number VARCHAR(20) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES isfinals(id) ON DELETE CASCADE
)");
mysqli_query($connection, "ALTER TABLE supervisors ADD COLUMN IF NOT EXISTS fullname VARCHAR(255) DEFAULT NULL");
mysqli_query($connection, "ALTER TABLE supervisors ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL");
mysqli_query($connection, "ALTER TABLE supervisors ADD COLUMN IF NOT EXISTS company VARCHAR(255) DEFAULT NULL");

// 1. Access Control
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'owner_supervisor') {
    header("Location: login.php");
    exit();
}

$supervisor_company = null;
$current_supervisor_id = null;
if (isset($_SESSION['user_id'])) {
    $user_id = mysqli_real_escape_string($connection, $_SESSION['user_id']);
    $supervisor_profile_result = mysqli_query($connection, "SELECT * FROM supervisors WHERE user_id='" . $user_id . "' LIMIT 1");
    if ($supervisor_profile_result && mysqli_num_rows($supervisor_profile_result) > 0) {
        $supervisor_profile = mysqli_fetch_assoc($supervisor_profile_result);
        $current_supervisor_id = $supervisor_profile['id'];
        $supervisor_company = trim($supervisor_profile['company'] ?? '');
    } else {
        mysqli_query($connection, "INSERT INTO supervisors (user_id, fullname, email, company, contact_number)
            SELECT id, fullname, email, NULL, contact_number FROM isfinals WHERE id='" . $user_id . "' LIMIT 1 ON DUPLICATE KEY UPDATE user_id=user_id");
        $supervisor_profile_result = mysqli_query($connection, "SELECT * FROM supervisors WHERE user_id='" . $user_id . "' LIMIT 1");
        if ($supervisor_profile_result && mysqli_num_rows($supervisor_profile_result) > 0) {
            $supervisor_profile = mysqli_fetch_assoc($supervisor_profile_result);
            $current_supervisor_id = $supervisor_profile['id'];
            $supervisor_company = trim($supervisor_profile['company'] ?? '');
        }
    }
}

if ($current_supervisor_id && empty($supervisor_company)) {
    $company_rows = mysqli_query($connection, "SELECT DISTINCT company_assigned FROM students WHERE supervisor_id='" . mysqli_real_escape_string($connection, $current_supervisor_id) . "' AND company_assigned IS NOT NULL AND company_assigned <> ''");
    $companies = [];
    if ($company_rows) {
        while ($company_row = mysqli_fetch_assoc($company_rows)) {
            if (!empty(trim($company_row['company_assigned'] ?? ''))) {
                $companies[] = trim($company_row['company_assigned']);
            }
        }
    }
    if (!empty($companies)) {
        $supervisor_company = implode(', ', array_unique($companies));
    }
}

$all_attendance = [];
$all_students = [];
if ($current_supervisor_id) {
    $all_attendance_result = mysqli_query($connection, "SELECT a.* FROM attendance a JOIN students s ON a.student_id = s.id WHERE s.supervisor_id='" . mysqli_real_escape_string($connection, $current_supervisor_id) . "' ORDER BY a.date DESC, a.time_in DESC, a.time_out DESC, a.id DESC");
    $all_attendance = ($all_attendance_result) ? mysqli_fetch_all($all_attendance_result, MYSQLI_ASSOC) : [];
    $all_students_result = mysqli_query($connection, "SELECT s.* FROM students s WHERE s.supervisor_id='" . mysqli_real_escape_string($connection, $current_supervisor_id) . "' ORDER BY s.fullname ASC");
    $all_students = ($all_students_result) ? mysqli_fetch_all($all_students_result, MYSQLI_ASSOC) : [];
}

// Load current supervisor profile
$current_user = null;
if (isset($_SESSION['user_id'])) {
    $user_result = mysqli_query($connection, "SELECT * FROM isfinals WHERE id='" . mysqli_real_escape_string($connection, $_SESSION['user_id']) . "' LIMIT 1");
    if ($user_result && mysqli_num_rows($user_result) > 0) {
        $current_user = mysqli_fetch_assoc($user_result);
    }
    $supervisor_profile = null;
    $supervisor_result = mysqli_query($connection, "SELECT * FROM supervisors WHERE user_id='" . mysqli_real_escape_string($connection, $_SESSION['user_id']) . "' LIMIT 1");
    if ($supervisor_result && mysqli_num_rows($supervisor_result) > 0) {
        $supervisor_profile = mysqli_fetch_assoc($supervisor_result);
    }
    if ($current_user && $supervisor_profile) {
        $current_user['contact_number'] = $supervisor_profile['contact_number'];
    }
}

$active_tab = $_GET['tab'] ?? 'dashboard';

if (isset($_POST['update_profile'])) {
    $fullname = mysqli_real_escape_string($connection, trim($_POST['fullname']));
    $email = mysqli_real_escape_string($connection, trim($_POST['email']));
    $contact_number = mysqli_real_escape_string($connection, trim($_POST['contact_number'] ?? ''));
    $company = mysqli_real_escape_string($connection, trim($_POST['company'] ?? ''));
    $profile_error = null;

    if ($fullname === '' || $email === '' || $contact_number === '') {
        $profile_error = "Full name, email, and contact number are required.";
    } else {
        $email_check = mysqli_query($connection, "SELECT id FROM isfinals WHERE email='$email' AND id!='" . mysqli_real_escape_string($connection, $_SESSION['user_id']) . "' LIMIT 1");
        if ($email_check && mysqli_num_rows($email_check) > 0) {
            $profile_error = "Email is already in use by another account.";
        } else {
            // Update supervisor profile in supervisors table
            $supervisor_check = mysqli_query($connection, "SELECT id FROM supervisors WHERE user_id='" . mysqli_real_escape_string($connection, $_SESSION['user_id']) . "' LIMIT 1");
            if ($supervisor_check && mysqli_num_rows($supervisor_check) > 0) {
                // Update existing supervisor profile
                $update_result = mysqli_query($connection, "UPDATE supervisors SET fullname='$fullname', email='$email', company='$company', contact_number='$contact_number' WHERE user_id='" . mysqli_real_escape_string($connection, $_SESSION['user_id']) . "'");
            } else {
                // Insert new supervisor profile
                $update_result = mysqli_query($connection, "INSERT INTO supervisors (user_id, fullname, email, company, contact_number) VALUES ('" . mysqli_real_escape_string($connection, $_SESSION['user_id']) . "', '$fullname', '$email', '$company', '$contact_number')");
            }
            
            if (!$update_result) {
                $profile_error = "Failed to save profile: " . mysqli_error($connection);
            } else {
                // Also update isfinals table for backward compatibility
                mysqli_query($connection, "UPDATE isfinals SET fullname='$fullname', email='$email', contact_number='$contact_number' WHERE id='" . mysqli_real_escape_string($connection, $_SESSION['user_id']) . "'");
                $_SESSION['fullname'] = $fullname;
                $_SESSION['user_email'] = $email;
                // Refresh current_user to show updated info from supervisors table
                $current_user = mysqli_fetch_assoc(mysqli_query($connection, "SELECT * FROM isfinals WHERE id='" . mysqli_real_escape_string($connection, $_SESSION['user_id']) . "' LIMIT 1"));
                $supervisor_profile = mysqli_fetch_assoc(mysqli_query($connection, "SELECT * FROM supervisors WHERE user_id='" . mysqli_real_escape_string($connection, $_SESSION['user_id']) . "' LIMIT 1"));
                if ($supervisor_profile) {
                    $current_user['contact_number'] = $supervisor_profile['contact_number'];
                }
                header("Location: supervisor_dashboard.php?tab=profile&success=profile_updated");
                exit();
            }
        }
    }
}

// Ensure current_user is always fresh
if (isset($_SESSION['user_id'])) {
    $refresh_user = mysqli_query($connection, "SELECT * FROM isfinals WHERE id='" . mysqli_real_escape_string($connection, $_SESSION['user_id']) . "' LIMIT 1");
    if ($refresh_user && mysqli_num_rows($refresh_user) > 0) {
        $current_user = mysqli_fetch_assoc($refresh_user);
    }
}

// 3. Logic Actions

// Validate Attendance (Approve/Reject)
if (isset($_GET['action'], $_GET['att_id'])) {
    if ($_SESSION['role'] !== 'owner_supervisor') {
        header("Location: login.php");
        exit();
    }

    $action = $_GET['action'] === 'approve' ? 'approve' : ($_GET['action'] === 'reject' ? 'reject' : '');
    if ($action) {
        $att_id = mysqli_real_escape_string($connection, $_GET['att_id']);
        $status = $action === 'approve' ? 'Approved' : 'Rejected';
        mysqli_query($connection, "UPDATE attendance SET status='$status' WHERE id='$att_id' AND status='Pending'");
    }

    header("Location: supervisor_dashboard.php");
    exit();
}

// Give Ratings/Evaluation for Students
if (isset($_POST['submit_rating'])) {
    $student_id = mysqli_real_escape_string($connection, $_POST['student_id']);
    $rating = mysqli_real_escape_string($connection, $_POST['rating']);
    $remarks = mysqli_real_escape_string($connection, $_POST['remarks']);

    $query = "UPDATE students SET performance_rating='$rating', behavior_remarks='$remarks' WHERE id='$student_id'";
    mysqli_query($connection, $query);
    header("Location: supervisor_dashboard.php?success=rated");
    exit();
}

// Change Password
if (isset($_POST['change_password'])) {
    $current_password = mysqli_real_escape_string($connection, $_POST['current_password']);
    $new_password = mysqli_real_escape_string($connection, $_POST['new_password']);
    $confirm_password = mysqli_real_escape_string($connection, $_POST['confirm_password']);

    // Get current user
    $user_id = $_SESSION['user_id'];
    $user_result = mysqli_query($connection, "SELECT password FROM isfinals WHERE id='$user_id'");
    $user = mysqli_fetch_assoc($user_result);

    if (password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            mysqli_query($connection, "UPDATE isfinals SET password='$hashed_password' WHERE id='$user_id'");
            header("Location: supervisor_dashboard.php?success=password_changed");
            exit();
        } else {
            $error = "New passwords do not match.";
        }
    } else {
        $error = "Current password is incorrect.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supervisor Dashboard | Work Immersion Monitoring System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #0f3a28;
            --primary-light: #1a5c3a;
            --primary-dark: #0a2a1c;
            --surface: rgba(255, 255, 255, 0.98);
            --surface-secondary: rgba(248, 250, 249, 0.95);
            --border: rgba(15, 58, 40, 0.12);
            --border-light: rgba(15, 58, 40, 0.08);
            --text: #111827;
            --text-secondary: #374151;
            --muted: #6b7280;
            --accent: #1f744f;
            --accent-light: #22c55e;
            --success: #16a34a;
            --warning: #f59e0b;
            --danger: #dc2626;
            --info: #3b82f6;
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            --gradient-secondary: linear-gradient(135deg, #f8faf9 0%, #e8f5e8 100%);
            --shadow-sm: 0 4px 12px rgba(15, 58, 40, 0.08);
            --shadow-md: 0 8px 25px rgba(15, 58, 40, 0.12);
            --shadow-lg: 0 20px 60px rgba(15, 58, 40, 0.15);
            --shadow-xl: 0 25px 75px rgba(15, 58, 40, 0.18);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 0;
            color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--gradient-secondary);
            background-attachment: fixed;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 20% 80%, rgba(31, 116, 79, 0.03) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(15, 58, 40, 0.03) 0%, transparent 50%);
            pointer-events: none;
            z-index: -1;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .sidebar {
            background: var(--gradient-primary);
            min-height: 100vh;
            color: #ecfdf5;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border-right: 1px solid rgba(255,255,255,0.1);
            backdrop-filter: blur(20px);
            position: relative;
            overflow: hidden;
        }

        .sidebar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(255,255,255,0.05) 0%, transparent 100%);
            pointer-events: none;
        }

        .sidebar .nav-link {
            color: rgba(236, 253, 245, 0.9);
            border-radius: 16px;
            margin-bottom: 8px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 14px 20px;
            display: flex;
            align-items: center;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .sidebar .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.5s;
        }

        .sidebar .nav-link:hover::before {
            left: 100%;
        }

        .sidebar .nav-link.active,
        .sidebar .nav-link:hover {
            color: #ffffff;
            background: rgba(255,255,255,0.15);
            box-shadow: var(--shadow-lg);
            transform: translateX(4px);
            border-left: 4px solid var(--accent-light);
        }

        .card {
            border: 1px solid var(--border-light);
            border-radius: 20px;
            background: var(--surface);
            box-shadow: var(--shadow-md);
            backdrop-filter: blur(20px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .card:hover {
            box-shadow: var(--shadow-xl);
            transform: translateY(-4px);
            border-color: var(--border);
        }

        .card:hover::before {
            opacity: 1;
        }

        .card-header {
            background: linear-gradient(135deg, rgba(255,255,255,0.8) 0%, rgba(248,250,249,0.8) 100%);
            border-bottom: 1px solid var(--border-light);
            padding: 1.5rem 2rem;
            position: relative;
        }

        .card-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--accent-light), transparent);
            opacity: 0.3;
        }

        .topbar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px 32px;
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            backdrop-filter: blur(20px);
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .topbar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(31, 116, 79, 0.02) 0%, rgba(15, 58, 40, 0.02) 100%);
            pointer-events: none;
        }

        .topbar .user-chip {
            display: inline-flex;
            align-items: center;
            gap: 16px;
            white-space: nowrap;
            position: relative;
            z-index: 1;
        }

        .topbar .user-chip .avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: var(--gradient-primary);
            color: #ffffff;
            font-weight: 700;
            font-size: 1.2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-md);
            border: 3px solid rgba(255,255,255,0.8);
            transition: all 0.3s ease;
        }

        .topbar .user-chip .avatar:hover {
            transform: scale(1.1);
            box-shadow: var(--shadow-lg);
        }

        .table {
            background: transparent;
            border-radius: 12px;
            overflow: hidden;
        }

        .table thead {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.1em;
            color: rgba(255,255,255,0.9);
            font-weight: 700;
            border: none;
        }

        .table thead th {
            padding: 1.25rem 1rem;
            border: none;
            position: relative;
        }

        .table tbody tr {
            border-bottom: 1px solid var(--border-light);
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: linear-gradient(135deg, rgba(31, 116, 79, 0.02) 0%, rgba(15, 58, 40, 0.02) 100%);
            transform: scale(1.01);
            box-shadow: 0 4px 12px rgba(15, 58, 40, 0.06);
        }

        .table tbody td {
            padding: 1.25rem 1rem;
            vertical-align: middle;
            border: none;
        }

        .btn-primary {
            background: var(--gradient-primary);
            border: none;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            padding: 0.75rem 1.5rem;
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--accent) 0%, var(--primary-dark) 100%);
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .btn-outline-primary {
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 12px;
            background: transparent;
            font-weight: 600;
            transition: all 0.3s ease;
            padding: 0.75rem 1.5rem;
        }

        .btn-outline-primary:hover {
            background: var(--gradient-primary);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-success, .btn-info, .btn-warning, .btn-danger, .btn-outline-danger {
            border-radius: 16px;
            font-weight: 600;
            border: 1.5px solid;
        }

        .form-control,
        .form-select,
        textarea {
            background: var(--surface);
            border: 2px solid var(--border);
            color: var(--text);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 8px rgba(15, 58, 40, 0.04);
        }

        .form-control:focus,
        .form-select:focus,
        textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 0.2rem rgba(31, 116, 79, 0.15);
            background: var(--surface);
            color: var(--text);
            transform: translateY(-1px);
        }

        .form-label {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
        }

        .text-primary {
            color: var(--primary) !important;
        }

        .text-success {
            color: #16a34a !important;
        }

        .text-muted {
            color: var(--muted) !important;
        }

        .bg-light {
            background: rgba(255, 255, 255, 0.92) !important;
        }

        .badge-pending { background: #fef9c3; color: #854d0e; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }

        main {
            padding: 2.5rem 3rem;
            position: relative;
        }

        main::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 30% 20%, rgba(31, 116, 79, 0.02) 0%, transparent 40%),
                radial-gradient(circle at 70% 80%, rgba(15, 58, 40, 0.02) 0%, transparent 40%);
            pointer-events: none;
            z-index: -1;
        }

        @media (min-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 260px;
                height: 100vh;
                overflow-y: auto;
            }
            main {
                margin-left: 260px;
            }
        }

        @media (max-width: 767.98px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -260px;
                width: 260px;
                height: 100vh;
                z-index: 1050;
                transition: left 0.3s ease;
            }
            .sidebar.active {
                left: 0;
            }
            main {
                margin-left: 0;
            }
            .topbar {
                padding: 15px 20px;
            }
            .topbar .user-chip {
                display: none;
            }
        }

        .modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: var(--shadow-xl);
            backdrop-filter: blur(20px);
            border: none;
        }

        .modal-header {
            border-bottom: 1px solid var(--border-light);
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(248,250,249,0.9) 100%);
            border-radius: 20px 20px 0 0;
            padding: 2rem;
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            border-top: 1px solid var(--border-light);
            background: rgba(248,250,249,0.5);
            border-radius: 0 0 20px 20px;
            padding: 1.5rem 2rem;
        }

        .stat-card {
            background: var(--gradient-primary);
            color: white;
            padding: 1.5rem 2rem;
            border-radius: 20px;
            text-align: center;
            min-width: 140px;
            box-shadow: var(--shadow-lg);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            transition: all 0.6s ease;
            opacity: 0;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.05);
            box-shadow: var(--shadow-xl);
        }

        .stat-card:hover::before {
            opacity: 1;
            animation: shimmer 1.5s ease-in-out;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
            .table-responsive {
                font-size: 0.85rem;
            }
            .table th, .table td {
                padding: 0.5rem;
            }
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
            .stat-card {
                min-width: 100px;
                padding: 0.75rem 1rem;
            }
            .stat-number {
                font-size: 1.5rem;
            }
            .stat-label {
                font-size: 0.75rem;
            }
            font-weight: 500;
        }
        .avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(15, 58, 40, 0.25);
        }

        .avatar-circle {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(15, 58, 40, 0.25);
        }

        .profile-info p {
            margin-bottom: 0.75rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(15, 58, 40, 0.06);
        }

        .profile-info p:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .evaluation-stats {
            background: rgba(15, 58, 40, 0.06);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
        }

        .student-avatar {
            flex-shrink: 0;
        }

        .avatar-circle-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(15, 58, 40, 0.15);
        }

        .attendance-info div {
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }

        .rating-display .stars {
            font-size: 1.1rem;
        }

        .remarks-preview {
            max-width: 200px;
        }

        .progress {
            background: rgba(15, 58, 40, 0.1);
            border-radius: 8px;
            height: 10px;
            overflow: hidden;
            position: relative;
        }

        .progress-bar {
            background: var(--gradient-primary);
            border-radius: 8px;
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: progress-shine 2s infinite;
        }

        @keyframes progress-shine {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .student-overview {
            border-left: 4px solid var(--primary);
        }

        .rating-section {
            border: 1px solid rgba(15, 58, 40, 0.1);
            border-radius: 12px;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.5);
        }

        .rating-options {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .rating-option {
            position: relative;
        }

        .rating-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            margin: 0;
        }

        .rating-label {
            display: block;
            padding: 1rem;
            border: 2px solid rgba(15, 58, 40, 0.1);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.25s ease;
            background: white;
        }

        .rating-label:hover {
            border-color: var(--primary);
            background: rgba(15, 58, 40, 0.02);
        }

        .rating-option input[type="radio"]:checked + .rating-label {
            border-color: var(--primary);
            background: rgba(15, 58, 40, 0.05);
            box-shadow: 0 0 0 0.2rem rgba(15, 58, 40, 0.1);
        }

        .rating-stars {
            font-size: 1.2rem;
        }

        .remarks-section {
            border: 1px solid rgba(15, 58, 40, 0.1);
            border-radius: 12px;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.5);
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--primary);
            font-size: 1.5rem;
            padding: 0.5rem;
            border-radius: 8px;
            transition: background 0.25s ease;
        }

        .menu-toggle:hover {
            background: rgba(15, 58, 40, 0.08);
        }

        @media (max-width: 767.98px) {
            .menu-toggle {
                display: block;
            }
            .table-responsive {
                font-size: 0.85rem;
            }
            .table th, .table td {
                padding: 0.5rem;
            }
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
            .stat-card {
                min-width: 100px;
                padding: 0.75rem 1rem;
            }
            .stat-number {
                font-size: 1.5rem;
            }
            .stat-label {
                font-size: 0.75rem;
            }
            .evaluation-stats {
                font-size: 0.7rem;
                padding: 0.2rem 0.5rem;
            }
            .student-overview .row > div {
                margin-bottom: 0.5rem;
            }
            .rating-label {
                padding: 0.75rem;
            }
            .rating-stars {
                font-size: 1rem;
            }
            .rating-content small {
                font-size: 0.75rem;
            }

            /* Better touch targets for mobile */
            .nav-link {
                padding: 1rem 1.25rem !important;
                min-height: 48px;
                display: flex;
                align-items: center;
            }

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

            /* Improve card spacing on mobile */
            .card {
                margin-bottom: 1rem;
            }
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--surface-secondary);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--gradient-primary);
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }

        /* Loading animation for cards */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card {
            animation: fadeInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .dashboard-header {
            position: relative;
        }

        .dashboard-header::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 60px;
            height: 4px;
            background: var(--gradient-primary);
            border-radius: 2px;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="bi bi-list"></i></button>
    <div class="row">
        <nav class="col-md-2 d-none d-md-block sidebar p-3 shadow" id="sidebar">
            <div style="text-align: center; margin-bottom: 1.5rem; padding-top: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.12);">
                <div style="width: 70px; height: 70px; margin: 0 auto 1rem; border-radius: 18px; overflow: hidden; background: rgba(255,255,255,0.95); display: grid; place-items: center;">
                    <img src="psnhs.jpeg" alt="School Logo" style="width: 60px; height: 60px; object-fit: contain;">
                </div>
                <h5 class="fw-bold mb-0" style="font-size: 0.95rem; letter-spacing: 0.05em;">SUPERVISOR PANEL</h5>
                <small style="font-size: 0.8rem; opacity: 0.85;">Work Immersion</small>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item"><a href="?tab=dashboard" class="nav-link <?= $active_tab == 'dashboard' ? 'active' : '' ?>"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a></li>
                <li class="nav-item"><a href="?tab=profile" class="nav-link <?= $active_tab == 'profile' ? 'active' : '' ?>"><i class="bi bi-person me-2"></i> Profile</a></li>
                <li class="nav-item"><a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#changePasswordModal"><i class="bi bi-key me-2"></i> Change Password</a></li>
                <li class="nav-item mt-5"><a href="check_login.php?logout=1" class="nav-link text-danger border border-danger"><i class="bi bi-power me-2"></i> Logout</a></li>
            </ul>
        </nav>

        <main class="col-md-10 px-md-4 py-4">
            <div class="topbar">
                <div class="d-flex align-items-center gap-3">
                    <button class="menu-toggle d-md-none" id="menuToggle">
                        <i class="bi bi-list"></i>
                    </button>
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($_SESSION['fullname'] ?? 'Supervisor') ?></div>
                        <div class="small text-muted"><?= date('M d, Y H:i') ?></div>
                    </div>
                </div>
                <div class="user-chip">
                    <span class="avatar"><?= substr(htmlspecialchars($_SESSION['user_email'] ?? 'S'), 0, 1) ?></span>
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($_SESSION['user_email'] ?? 'Supervisor') ?></div>
                        <div class="small text-muted">Supervisor</div>
                    </div>
                </div>
            </div>
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php if($_GET['success'] == 'password_changed'): ?>
                    Password changed successfully.
                <?php elseif($_GET['success'] == 'profile_updated'): ?>
                    Profile updated successfully.
                <?php else: ?>
                    Action processed successfully.
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($active_tab == 'dashboard'): ?>
            <div class="dashboard-header mb-5">
                <h2 class="mb-2 fw-bold" style="font-size: 2.5rem; background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">Supervisor Control Panel</h2>
                <p class="text-muted mb-4 fs-6">Review pending attendance and student evaluations with comprehensive oversight.</p>
                <div class="text-center">
                    <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 fs-7 fw-semibold">
                        <i class="bi bi-calendar-check me-1"></i><?php echo date('l, F j, Y'); ?>
                    </span>
                </div>
            </div>

            <!-- Statistics Overview -->
            <div class="row g-3 mb-5">
                <div class="col-md-3">
                    <div class="stat-card text-center">
                        <div class="stat-number"><?php echo count(array_filter($all_attendance, function($att) { return ($att['status'] ?? 'Pending') == 'Pending'; })); ?></div>
                        <div class="stat-label">Pending Reviews</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card text-center">
                        <div class="stat-number"><?php echo count(array_filter($all_attendance, function($att) { return ($att['status'] ?? 'Pending') == 'Approved'; })); ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card text-center">
                        <div class="stat-number"><?php echo count(array_filter($all_attendance, function($att) { return ($att['status'] ?? 'Pending') == 'Rejected'; })); ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card text-center">
                        <div class="stat-number"><?php echo count($all_students); ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="row g-4 mb-5">
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header py-3 border-0">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-bar-chart me-2 text-primary"></i>Attendance Overview</h5>
                        </div>
                        <div class="card-body d-flex align-items-center">
                            <canvas id="attendanceChart" style="max-width: 100%; height: auto;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header py-3 border-0">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-graph-up me-2 text-success"></i>Student Progress Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row text-center g-3">
                                <div class="col-6">
                                    <div class="p-3 border-end">
                                        <div class="display-4 text-primary fw-bold"><?php echo count(array_filter($all_students, function($s) { return isset($s['performance_rating']); })); ?></div>
                                        <small class="text-muted">Evaluated Students</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="p-3">
                                        <div class="display-4 text-warning fw-bold"><?php echo count(array_filter($all_students, function($s) { return !isset($s['performance_rating']); })); ?></div>
                                        <small class="text-muted">Pending Evaluation</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-5">
                <div class="card-header py-3 border-0">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-calendar-check me-2 text-primary"></i>Pending Attendance Logs</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">Student</th>
                                <th>Date</th>
                                <th>Time In / Out</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_attendance as $att): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= $att['name'] ?? 'Unknown User' ?></div>
                                    <small class="text-muted">ID: #<?= $att['student_id'] ?? '---' ?></small>
                                </td>
                                <td><?= $att['date'] ?? 'N/A' ?></td>
                                <td>
                                    <?php
                                        $time_in = trim($att['time_in'] ?? '');
                                        $time_out = trim($att['time_out'] ?? '');
                                        $time_in_label = '---';
                                        if ($time_in !== '' && $time_in !== '--:--') {
                                            $parsed_time_in = strtotime($time_in);
                                            $time_in_label = $parsed_time_in ? date('h:i A', $parsed_time_in) : $time_in;
                                        }
                                        $time_out_label = '---';
                                        if ($time_out !== '' && $time_out !== '--:--') {
                                            $parsed_time_out = strtotime($time_out);
                                            $time_out_label = $parsed_time_out ? date('h:i A', $parsed_time_out) : $time_out;
                                        }
                                    ?>
                                    <div class="d-flex flex-column gap-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-success text-white"><i class="bi bi-arrow-up-right me-1"></i>In</span>
                                            <span class="text-muted small"><?= htmlspecialchars($time_in_label) ?></span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-danger text-white"><i class="bi bi-arrow-down-left me-1"></i>Out</span>
                                            <span class="text-muted small"><?= htmlspecialchars($time_out_label) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($att['type'] ?? 'Log') ?></td>
                                <td>
                                    <span class="badge badge-<?= strtolower($att['status'] ?? 'pending') ?>">
                                        <?= htmlspecialchars($att['status'] ?? 'Pending') ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if (($att['status'] ?? 'Pending') == 'Pending' && isset($att['id'])): ?>
                                        <?php $att_id = htmlspecialchars($att['id']); ?>
                                        <a href="?action=approve&att_id=<?php echo $att_id ?>" class="btn btn-sm btn-success px-3">Approve</a>
                                        <a href="?action=reject&att_id=<?php echo $att_id ?>" class="btn btn-sm btn-outline-danger px-3">Reject</a>
                                    <?php else: ?>
                                        <span class="text-muted small">Processed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($all_attendance)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No attendance logs found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-star me-2 text-warning"></i>Student Evaluations</h5>
                    <div class="d-flex gap-2">
                        <div class="evaluation-stats">
                            <small class="text-muted">
                                <i class="bi bi-check-circle-fill text-success me-1"></i>
                                <?php echo count(array_filter($all_students, function($s) { return isset($s['performance_rating']); })); ?> Evaluated
                            </small>
                        </div>
                        <div class="evaluation-stats">
                            <small class="text-muted">
                                <i class="bi bi-clock text-warning me-1"></i>
                                <?php echo count(array_filter($all_students, function($s) { return !isset($s['performance_rating']); })); ?> Pending
                            </small>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">Student Profile</th>
                                <th>Company</th>
                                <th>Hours Progress</th>
                                <th>Attendance</th>
                                <th>Performance</th>
                                <th>Remarks</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_students as $s):
                                $required_hours = $s['required_hours'] ?? 0;
                                $rendered_hours = $s['total_hours_rendered'] ?? 0;
                                $progress_percentage = $required_hours > 0 ? min(100, ($rendered_hours / $required_hours) * 100) : 0;
                                $has_rating = isset($s['performance_rating']);
                            ?>
                            <tr class="<?= $has_rating ? 'table-light' : '' ?>">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div class="student-avatar me-3">
                                            <span class="avatar-circle-small">
                                                <?= substr(htmlspecialchars($s['fullname'] ?? 'S'), 0, 1) ?>
                                            </span>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($s['fullname'] ?? 'New Student'); ?></div>
                                            <small class="text-muted">ID: #<?php echo htmlspecialchars($s['id'] ?? '---'); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($s['company_assigned'] ?? 'Not Assigned'); ?></div>
                                </td>
                                <td>
                                    <div class="progress mb-1" style="height: 8px;">
                                        <div class="progress-bar bg-<?= $progress_percentage >= 100 ? 'success' : ($progress_percentage >= 75 ? 'info' : 'warning') ?>"
                                             role="progressbar"
                                             style="width: <?php echo $progress_percentage; ?>%"
                                             aria-valuenow="<?php echo $progress_percentage; ?>"
                                             aria-valuemin="0"
                                             aria-valuemax="100"></div>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo $rendered_hours; ?>/<?php echo $required_hours; ?> hrs
                                        <span class="text-<?= $progress_percentage >= 100 ? 'success' : 'muted' ?>">
                                            (<?php echo round($progress_percentage); ?>%)
                                        </span>
                                    </small>
                                </td>
                                <td>
                                    <div class="attendance-info">
                                        <div class="text-danger"><i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($s['late_count'] ?? 0); ?> Late</div>
                                        <div class="text-warning"><i class="bi bi-x-circle"></i> <?php echo htmlspecialchars($s['absent_count'] ?? 0); ?> Absent</div>
                                    </div>
                                </td>
                                <td>
                                    <?php if($has_rating): ?>
                                        <div class="rating-display">
                                            <div class="stars mb-1">
                                                <?php for($i=1; $i<=5; $i++): ?>
                                                    <i class="bi bi-star<?= $i <= $s['performance_rating'] ? '-fill text-warning' : ' text-muted' ?>"></i>
                                                <?php endfor; ?>
                                            </div>
                                            <small class="text-muted fw-semibold">
                                                <?php
                                                $rating_labels = ['Poor', 'Fair', 'Satisfactory', 'Very Good', 'Excellent'];
                                                echo $rating_labels[$s['performance_rating'] - 1] ?? 'Rated';
                                                ?>
                                            </small>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="bi bi-clock me-1"></i>Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="remarks-preview">
                                        <?php if(!empty($s['behavior_remarks'])): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars(substr($s['behavior_remarks'], 0, 50)); ?><?php echo strlen($s['behavior_remarks']) > 50 ? '...' : ''; ?></small>
                                        <?php else: ?>
                                            <small class="text-muted fst-italic">No remarks</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm <?= $has_rating ? 'btn-outline-primary' : 'btn-primary' ?> px-3"
                                            data-bs-toggle="modal"
                                            data-bs-target="#rateModal<?php echo $s['id']; ?>">
                                        <i class="bi bi-pencil-square me-1"></i>
                                        <?php echo $has_rating ? 'Update' : 'Evaluate'; ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

                <?php foreach ($all_students as $s): ?>
                <div class="modal fade" id="rateModal<?php echo $s['id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content border-0 shadow">
                            <form method="POST">
                                <div class="modal-header border-0 pb-0">
                                    <div class="d-flex align-items-center w-100">
                                        <div class="avatar-circle-small me-3">
                                            <?= substr(htmlspecialchars($s['fullname'] ?? 'S'), 0, 1) ?>
                                        </div>
                                        <div>
                                            <h5 class="fw-bold mb-0">Evaluate Student</h5>
                                            <small class="text-muted"><?php echo htmlspecialchars($s['fullname'] ?? 'Student'); ?> • ID: #<?php echo htmlspecialchars($s['id'] ?? '---'); ?></small>
                                        </div>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="student_id" value="<?php echo $s['id']; ?>">

                                    <!-- Student Overview -->
                                    <div class="student-overview mb-4 p-3 bg-light rounded">
                                        <h6 class="fw-bold text-primary mb-2"><i class="bi bi-person-circle me-2"></i>Student Overview</h6>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <strong><?php echo htmlspecialchars($s['company_assigned'] ?? 'Not Assigned'); ?></strong>
                                            </div>
                                            <div class="col-md-4">
                                                <small class="text-muted d-block">Hours Progress</small>
                                                <strong><?php echo htmlspecialchars($s['total_hours_rendered'] ?? 0); ?>/<?php echo htmlspecialchars($s['required_hours'] ?? 0); ?> hrs</strong>
                                            </div>
                                            <div class="col-md-4">
                                                <small class="text-muted d-block">Attendance</small>
                                                <strong class="text-danger"><?php echo htmlspecialchars($s['late_count'] ?? 0); ?> Late</strong> •
                                                <strong class="text-warning"><?php echo htmlspecialchars($s['absent_count'] ?? 0); ?> Absent</strong>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Performance Rating -->
                                    <div class="rating-section mb-4">
                                        <label class="form-label fw-bold mb-3">
                                            <i class="bi bi-star-fill text-warning me-2"></i>Performance Rating
                                        </label>
                                        <div class="rating-options">
                                            <?php
                                            $ratings = [
                                                5 => ['label' => 'Excellent', 'desc' => 'Outstanding performance, exceeds expectations', 'color' => 'success'],
                                                4 => ['label' => 'Very Good', 'desc' => 'Good performance, meets expectations', 'color' => 'info'],
                                                3 => ['label' => 'Satisfactory', 'desc' => 'Acceptable performance, needs improvement', 'color' => 'warning'],
                                                2 => ['label' => 'Fair', 'desc' => 'Below average, significant improvement needed', 'color' => 'orange'],
                                                1 => ['label' => 'Poor', 'desc' => 'Unsatisfactory performance', 'color' => 'danger']
                                            ];
                                            ?>
                                            <?php foreach($ratings as $value => $rating): ?>
                                            <div class="rating-option mb-2">
                                                <input type="radio" name="rating" value="<?php echo $value; ?>"
                                                       id="rating<?php echo $s['id']; ?>_<?php echo $value; ?>"
                                                       <?php echo ($s['performance_rating'] ?? '') == $value ? 'checked' : ''; ?> required>
                                                <label for="rating<?php echo $s['id']; ?>_<?php echo $value; ?>" class="rating-label">
                                                    <div class="d-flex align-items-center">
                                                        <div class="rating-stars me-3">
                                                            <?php for($i=1; $i<=5; $i++): ?>
                                                                <i class="bi bi-star<?= $i <= $value ? '-fill' : '' ?> text-warning"></i>
                                                            <?php endfor; ?>
                                                        </div>
                                                        <div class="rating-content">
                                                            <div class="fw-bold text-<?php echo $rating['color']; ?>"><?php echo $rating['label']; ?> (<?php echo $value; ?>/5)</div>
                                                            <small class="text-muted"><?php echo $rating['desc']; ?></small>
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- Behavioral Remarks -->
                                    <div class="remarks-section">
                                        <label class="form-label fw-bold">
                                            <i class="bi bi-chat-quote text-primary me-2"></i>Behavioral Remarks & Observations
                                        </label>
                                        <textarea name="remarks" class="form-control" rows="4"
                                                  placeholder="Provide detailed observations about the student's behavior, work ethic, professionalism, and any specific incidents or achievements..."><?php echo htmlspecialchars($s['behavior_remarks'] ?? ''); ?></textarea>
                                        <small class="text-muted mt-1 d-block">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Detailed remarks help track student development and provide valuable feedback.
                                        </small>
                                    </div>
                                </div>
                                <div class="modal-footer border-0">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                        <i class="bi bi-x-circle me-2"></i>Cancel
                                    </button>
                                    <button type="submit" name="submit_rating" class="btn btn-primary px-4">
                                        <i class="bi bi-check-circle me-2"></i>Save Evaluation
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($active_tab == 'profile'): ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1" style="font-size: 2.25rem; font-weight: 800;">Profile Settings</h2>
                    <p class="text-muted small mb-0">Update your account details and manage your profile information.</p>
                </div>
                <div class="avatar-large">
                    <span class="avatar-circle"><?= substr(htmlspecialchars($current_user['fullname'] ?? $_SESSION['fullname'] ?? 'S'), 0, 1) ?></span>
                </div>
            </div>

            <div class="row gy-4">
                <div class="col-lg-8">
                    <div class="card p-4 mb-4">
                        <h5 class="fw-bold mb-4"><i class="bi bi-person-circle me-2 text-primary"></i> Supervisor Profile</h5>
                        <?php if (!empty($profile_error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <?= htmlspecialchars($profile_error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Full Name</label>
                                    <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($current_user['fullname'] ?? $_SESSION['fullname'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Email Address</label>
                                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($current_user['email'] ?? $_SESSION['user_email'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Contact Number</label>
                                    <input type="tel" name="contact_number" class="form-control" value="<?= htmlspecialchars(trim($current_user['contact_number'] ?? '')) ?>" placeholder="09XXXXXXXXX" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Company Owned</label>
                                    <input type="text" name="company" class="form-control" value="<?= htmlspecialchars($supervisor_profile['company'] ?? $supervisor_company ?? '') ?>" placeholder="Company name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Account Role</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars(str_replace('_', '/', $current_user['role'] ?? 'owner_supervisor')) ?>" readonly>
                                </div>
                            </div>
                            <div class="mt-4">
                                <button type="submit" name="update_profile" class="btn btn-primary px-4">
                                    <i class="bi bi-check-circle me-2"></i>Save Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Change Password Modal -->
        <div class="modal fade" id="changePasswordModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST">
                        <div class="modal-header border-0 pb-0">
                            <h5 class="fw-bold"><i class="bi bi-key me-2 text-primary"></i>Change Password</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <?php if(isset($error)): ?>
                                <div class="alert alert-danger alert-dismissible fade show">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    <small><?php echo htmlspecialchars($error); ?></small>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Current Password</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">New Password</label>
                                <input type="password" name="new_password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control" required>
                            </div>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-circle me-2"></i>Cancel
                            </button>
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="bi bi-check-circle me-2"></i>Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        </div>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile sidebar toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        if (mobileMenuToggle && sidebar) {
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
            });

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth < 768 && !sidebar.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            });
        }

        // Attendance Chart
        <?php
        $pending_count = count(array_filter($all_attendance, function($att) { return ($att['status'] ?? 'Pending') == 'Pending'; }));
        $approved_count = count(array_filter($all_attendance, function($att) { return ($att['status'] ?? 'Pending') == 'Approved'; }));
        $rejected_count = count(array_filter($all_attendance, function($att) { return ($att['status'] ?? 'Pending') == 'Rejected'; }));
        ?>
        const ctx = document.getElementById('attendanceChart').getContext('2d');
        const attendanceChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Pending', 'Approved', 'Rejected'],
                datasets: [{
                    label: 'Attendance Status',
                    data: [<?php echo $pending_count; ?>, <?php echo $approved_count; ?>, <?php echo $rejected_count; ?>],
                    backgroundColor: [
                        'rgba(255, 193, 7, 0.8)',  // Yellow for pending
                        'rgba(25, 135, 84, 0.8)',  // Green for approved
                        'rgba(220, 53, 69, 0.8)'   // Red for rejected
                    ],
                    borderColor: [
                        'rgba(255, 193, 7, 1)',
                        'rgba(25, 135, 84, 1)',
                        'rgba(220, 53, 69, 1)'
                    ],
                    borderWidth: 2,
                    borderRadius: 4,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed.y;
                                return label + ': ' + value;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
    <?php if (isset($error)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modalEl = document.getElementById('changePasswordModal');
            if (modalEl) {
                var changePasswordModal = new bootstrap.Modal(modalEl);
                changePasswordModal.show();
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>

//Nicole Sambile
//John Paul Santos
//Jessica Salalila