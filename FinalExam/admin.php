<?php
// --- 1. SESSION CONFIGURATION ---
// Configure secure session and cookie settings before output.
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
date_default_timezone_set('Asia/Manila');

// --- 2. DATABASE CONNECTION ---
// Load the shared database connection settings.
require('./connection.php');

// --- 3. AUTHENTICATION & REDIRECTION ---

// --- 2. AUTHENTICATION & REDIRECTION ---
$admin_creds = ['user' => 'admin', 'pass' => 'password123'];

if (isset($_POST['login'])) {
    if ($_POST['user'] === $admin_creds['user'] && $_POST['pass'] === $admin_creds['pass']) {
        session_regenerate_id(true);
        $_SESSION['admin_auth'] = true;
        header("Location: admin.php?tab=dashboard");
        exit();
    } else {
        $error = "Invalid Credentials!";
    }
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header("Location: admin.php");
    exit();
}

// --- 3. LOAD DATA FROM DATABASE ---
$students_db = [];
$users = [];
$attendance_db = [];
$announcements_db = [];
$reports_db = [];
$admin_error = '';
$admin_message = '';
$concerns_db = [];
$supervisors = [];

// --- 4. HELPER FUNCTIONS ---
// Reusable functions for UI badges and file size formatting.
function getConcernBadgeClass($status) {
    $status = strtolower($status ?? '');
    if ($status === 'pending') {
        return 'bg-warning';
    }
    if ($status === 'reviewed') {
        return 'bg-info';
    }
    if ($status === 'resolved') {
        return 'bg-success';
    }
    return 'bg-secondary';
}

function formatFileSize($bytes) {
    if ($bytes == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($bytes, 1024));
    return (string)round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// --- 5. DATA SUMMARY ARRAYS ---
// Initialized storage for attendance summary calculations.
$pending_attendance_by_student = [];
$latest_attendance_by_student = [];

// --- 6. LOAD DATA FROM DATABASE ---
// Load students, users, attendance, announcements, reports, and concerns.
if ($connection) {
    // Ensure owner_supervisor users exist in the supervisors table so they can be assigned.
    mysqli_query($connection, "INSERT INTO supervisors (user_id, fullname, email, contact_number)
        SELECT f.id, f.fullname, f.email, f.contact_number
        FROM isfinals f
        LEFT JOIN supervisors s ON f.id = s.user_id
        WHERE f.role = 'owner_supervisor' AND s.user_id IS NULL");

    // Load student records plus linked supervisor name and contact details.
    $students_result = mysqli_query($connection, "SELECT s.*, f.contact_number AS isfinals_contact, sup.fullname AS supervisor_name FROM students s LEFT JOIN isfinals f ON s.user_id = f.id LEFT JOIN supervisors sup ON s.supervisor_id = sup.id");
    if ($students_result) {
        while ($row = mysqli_fetch_assoc($students_result)) {
            $row['requirements'] = json_decode($row['requirements'], true) ?: [];
            $row['uploaded_files'] = json_decode($row['uploaded_files'], true) ?: [];
            if (empty(trim($row['contact_number'] ?? '')) && !empty(trim($row['isfinals_contact'] ?? ''))) {
                $row['contact_number'] = trim($row['isfinals_contact']);
            }
            $students_db[] = $row;
        }
    }

    // Load all registered users and related supervisor contact information.
    $users_result = mysqli_query($connection, "SELECT f.*, COALESCE(s.fullname, f.fullname) AS supervisor_fullname, COALESCE(s.email, f.email) AS supervisor_email, s.contact_number AS supervisor_contact, s.id AS supervisor_record_id FROM isfinals f LEFT JOIN supervisors s ON f.id = s.user_id");
    if ($users_result) {
        $users = mysqli_fetch_all($users_result, MYSQLI_ASSOC);
    }

    // Load attendance history for display and summary metrics.
    $attendance_result = mysqli_query($connection, "SELECT * FROM attendance ORDER BY date DESC, time_in DESC, time_out DESC, id DESC");
    if ($attendance_result) {
        $attendance_db = mysqli_fetch_all($attendance_result, MYSQLI_ASSOC);
    }

    // Load announcements to show the latest publication list.
    $announcements_db = [];
    $announcements_result = mysqli_query($connection, "SELECT * FROM announcements ORDER BY date DESC");
    if ($announcements_result) {
        $announcements_db = mysqli_fetch_all($announcements_result, MYSQLI_ASSOC);
    }

    // Load narrative reports linked to students.
    $reports_db = [];
    $reports_result = mysqli_query($connection, "SELECT nr.*, s.fullname as student_name FROM narrative_reports nr JOIN students s ON nr.student_id = s.id ORDER BY nr.date DESC");
    if ($reports_result) {
        $reports_db = mysqli_fetch_all($reports_result, MYSQLI_ASSOC);
    }

    // Determine which teacher response column exists in the student concerns table.
    $concern_response_column = 'teacher_response';
    $response_column_check = mysqli_query($connection, "SHOW COLUMNS FROM student_concerns LIKE 'teacher_response'");
    if (!$response_column_check || mysqli_num_rows($response_column_check) === 0) {
        $concern_response_column = 'supervisor_response';
    }

    $concerns_db = [];
    $concerns_result = mysqli_query($connection, "SELECT sc.*, s.fullname as student_name, sc.$concern_response_column AS teacher_response FROM student_concerns sc JOIN students s ON sc.student_id = s.id ORDER BY sc.date_submitted DESC");
    if ($concerns_result) {
        $concerns_db = mysqli_fetch_all($concerns_result, MYSQLI_ASSOC);
    }

    // Create a lookup map for student records by user_id.
    $student_map = [];
    foreach ($students_db as $student) {
        if (!empty($student['user_id'])) {
            $student_map[$student['user_id']] = $student;
        }
    }

    // Summarize the latest and pending attendance records, plus total approved hours.
    $pending_attendance_by_student = [];
    $latest_attendance_by_student = [];
    $student_total_hours = [];
    // Derive attendance state per student: latest entry, pending approvals, and total approved hours.
    foreach ($attendance_db as $record) {
        $student_id = $record['student_id'];
        if (!isset($latest_attendance_by_student[$student_id])) {
            $latest_attendance_by_student[$student_id] = $record;
            if (strtolower($record['status'] ?? '') === 'pending') {
                $pending_attendance_by_student[$student_id] = $record;
            }
        }

        if (strtolower($record['status'] ?? '') === 'approved' && !empty($record['time_in']) && !empty($record['time_out']) && $record['time_in'] !== '--:--' && $record['time_out'] !== '--:--') {
            $time_in = strtotime($record['time_in']);
            $time_out = strtotime($record['time_out']);
            if ($time_out > $time_in) {
                $hours = ($time_out - $time_in) / 3600;
                $student_total_hours[$student_id] = ($student_total_hours[$student_id] ?? 0) + $hours;
            }
        }
    }
}

// --- 7. ACTION HANDLERS ---
// Process admin-side operations only after successful authentication.
if (isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true) {
    
    // DELETE STUDENT
    if (isset($_GET['delete_student'])) {
        $id = mysqli_real_escape_string($connection, $_GET['delete_student']);
        mysqli_query($connection, "DELETE FROM students WHERE id='$id'");
        header("Location: admin.php?tab=users"); exit();
    }

    // DELETE USER
    if (isset($_GET['delete_user'])) {
        $id = mysqli_real_escape_string($connection, $_GET['delete_user']);
        mysqli_query($connection, "DELETE FROM students WHERE user_id='$id'");
        mysqli_query($connection, "DELETE FROM isfinals WHERE id='$id'");
        header("Location: admin.php?tab=users"); exit();
    }

    // DELETE ANNOUNCEMENT
    if (isset($_GET['delete_ann'])) {
        $id = mysqli_real_escape_string($connection, $_GET['delete_ann']);
        mysqli_query($connection, "DELETE FROM announcements WHERE id='$id'");
        header("Location: admin.php?tab=announcements"); exit();
    }

    // DELETE ATTENDANCE LOG
    if (isset($_GET['delete_attendance'])) {
        $id = mysqli_real_escape_string($connection, $_GET['delete_attendance']);
        mysqli_query($connection, "DELETE FROM attendance WHERE id='$id'");
        header("Location: admin.php?tab=attendance"); exit();
    }

    // ADD STUDENT OR OWNER/SUPERVISOR
    if (isset($_POST['add_student'])) {
        $name = mysqli_real_escape_string($connection, trim($_POST['name']));
        $email = mysqli_real_escape_string($connection, trim($_POST['email']));
        $username = mysqli_real_escape_string($connection, trim($_POST['username']));
        $password = mysqli_real_escape_string($connection, $_POST['password']);
        $contact_number = mysqli_real_escape_string($connection, trim($_POST['contact_number'] ?? ''));
        $company_assigned = mysqli_real_escape_string($connection, trim($_POST['company_assigned'] ?? ''));
        $supervisor_id = !empty($_POST['supervisor_id']) ? mysqli_real_escape_string($connection, $_POST['supervisor_id']) : NULL;
        $required_hours = max(0, (int)($_POST['required_hours'] ?? 80));
        $total_hours_rendered = max(0, (int)($_POST['total_hours_rendered'] ?? 0));
        $late_count = max(0, (int)($_POST['late_count'] ?? 0));
        $absent_count = max(0, (int)($_POST['absent_count'] ?? 0));
        $role = mysqli_real_escape_string($connection, trim($_POST['role'] ?? 'student'));
        if ($role !== 'owner_supervisor') {
            $role = 'student';
        }

        if (empty($name) || empty($email) || empty($username) || empty($password) || empty($contact_number)) {
            $admin_error = 'Please complete all required fields.';
        } elseif ($role === 'student' && empty($company_assigned)) {
            $admin_error = 'Please provide the company assigned for the student.';
        } else {
            $resultEmail = mysqli_query($connection, "SELECT id FROM isfinals WHERE email='$email'");
            $resultUsername = mysqli_query($connection, "SELECT id FROM isfinals WHERE username='$username'");
            if (mysqli_num_rows($resultEmail) > 0) {
                $admin_error = 'Email already exists.';
            } elseif (mysqli_num_rows($resultUsername) > 0) {
                $admin_error = 'Username already exists.';
            } else {
                mysqli_query($connection, "INSERT INTO isfinals (fullname, email, username, password, role, contact_number, approval_status) VALUES ('$name', '$email', '$username', '$password', '$role', '$contact_number', 'Approved')");
                $new_user_id = mysqli_insert_id($connection);
                if ($role === 'student') {
                    mysqli_query($connection, "INSERT INTO students (user_id, fullname, email, username, contact_number, company_assigned, supervisor_id, required_hours, total_hours_rendered, late_count, absent_count, requirements, uploaded_files) VALUES ('$new_user_id', '$name', '$email', '$username', '$contact_number', '$company_assigned', ".($supervisor_id ? "'$supervisor_id'" : "NULL").", $required_hours, $total_hours_rendered, $late_count, $absent_count, '[]', '[]')");
                } elseif ($role === 'owner_supervisor') {
                    mysqli_query($connection, "INSERT INTO supervisors (user_id, fullname, email, contact_number) VALUES ('$new_user_id', '$name', '$email', '$contact_number')");
                }
                header("Location: admin.php?tab=users"); exit();
            }
        }
    }

    // UPDATE REQUIREMENT STATUS
    if (isset($_POST['update_action'])) {
        $student_id = mysqli_real_escape_string($connection, $_POST['student_id'] ?? '');
        $req_type = mysqli_real_escape_string($connection, $_POST['req_type'] ?? '');
        $req_val = mysqli_real_escape_string($connection, $_POST['req_val'] ?? 'Pending');

        if ($student_id && $req_type) {
            $reqResult = mysqli_query($connection, "SELECT requirements FROM students WHERE id='$student_id'");
            if ($reqResult && $row = mysqli_fetch_assoc($reqResult)) {
                $reqs = json_decode($row['requirements'], true) ?: [];
                $reqs[$req_type] = $req_val;
                $reqs_json = mysqli_real_escape_string($connection, json_encode($reqs));
                mysqli_query($connection, "UPDATE students SET requirements='$reqs_json' WHERE id='$student_id'");
            }
        }
        header("Location: admin.php?tab=users"); exit();
    }

    // UPDATE REQUIRED HOURS
    if (isset($_POST['update_required_hours'])) {
        $student_id = mysqli_real_escape_string($connection, $_POST['student_id'] ?? '');
        $required_hours = max(0, (int)($_POST['required_hours'] ?? 0));

        if ($student_id) {
            mysqli_query($connection, "UPDATE students SET required_hours='$required_hours' WHERE id='$student_id'");
        }
        header("Location: admin.php?tab=users&success=hours_updated"); exit();
    }

    // UPDATE COMPANY ASSIGNED
    if (isset($_POST['update_company_assigned'])) {
        $student_id = mysqli_real_escape_string($connection, $_POST['student_id'] ?? '');
        $company_assigned = mysqli_real_escape_string($connection, $_POST['company_assigned'] ?? '');

        if ($student_id) {
            mysqli_query($connection, "UPDATE students SET company_assigned='$company_assigned' WHERE id='$student_id'");
        }
        header("Location: admin.php?tab=users&success=company_updated"); exit();
    }

    // UPDATE SUPERVISOR ASSIGNED
    if (isset($_POST['update_supervisor_assigned'])) {
        $student_id = mysqli_real_escape_string($connection, $_POST['student_id'] ?? '');
        $supervisor_id = !empty($_POST['supervisor_id']) ? mysqli_real_escape_string($connection, $_POST['supervisor_id']) : NULL;

        if ($student_id) {
            mysqli_query($connection, "UPDATE students SET supervisor_id=".($supervisor_id ? "'$supervisor_id'" : "NULL")." WHERE id='$student_id'");
        }
        header("Location: admin.php?tab=users&success=supervisor_updated"); exit();
    }

    // SIMULATE ATTENDANCE
    if (isset($_POST['log_type'])) {
        $student_id = mysqli_real_escape_string($connection, $_POST['student_id'] ?? '');
        $type = $_POST['log_type'] === 'out' ? 'out' : 'in';
        $date = date('Y-m-d');
        $time = date('H:i:s');

        if ($student_id) {
            $studentResult = mysqli_query($connection, "SELECT fullname FROM students WHERE id='$student_id'");
            $studentName = 'Unknown';
            if ($studentResult && $studentRow = mysqli_fetch_assoc($studentResult)) {
                $studentName = mysqli_real_escape_string($connection, $studentRow['fullname']);
            }

            if ($type === 'out') {
                $pending = mysqli_query($connection, "SELECT id FROM attendance WHERE student_id='$student_id' AND date='$date' AND (time_out='--:--' OR time_out='' OR time_out IS NULL) ORDER BY id DESC LIMIT 1");
                if ($pending && mysqli_num_rows($pending) > 0) {
                    $row = mysqli_fetch_assoc($pending);
                    mysqli_query($connection, "UPDATE attendance SET time_out='$time' WHERE id='" . mysqli_real_escape_string($connection, $row['id']) . "'");
                    header("Location: admin.php?tab=attendance&success=clock_out");
                    exit();
                }
                header("Location: admin.php?tab=attendance&error=no_clock_in");
                exit();
            } else {
                $existing = mysqli_query($connection, "SELECT id FROM attendance WHERE student_id='$student_id' AND date='$date' AND time_in != '--:--'");
                if (!$existing || mysqli_num_rows($existing) === 0) {
                    mysqli_query($connection, "INSERT INTO attendance (student_id, name, date, time_in, time_out, status) VALUES ('$student_id', '$studentName', '$date', '$time', NULL, 'Pending')");
                    header("Location: admin.php?tab=attendance&success=clock_in");
                    exit();
                }
                header("Location: admin.php?tab=attendance&error=already_clocked_in");
                exit();
            }
        }
        header("Location: admin.php?tab=attendance&error=invalid_student");
        exit();
    }

    // POST ANNOUNCEMENT
    if (isset($_POST['add_announcement'])) {
        $title = mysqli_real_escape_string($connection, $_POST['title']);
        $content = mysqli_real_escape_string($connection, $_POST['content']);
        $date = date('Y-m-d');
        mysqli_query($connection, "INSERT INTO announcements (title, content, date) VALUES ('$title', '$content', '$date')");
        header("Location: admin.php?tab=announcements"); exit();
    }

    // ATTENDANCE APPROVAL/REJECTION IS HANDLED ONLY BY owner/supervisor users, not from admin panel.
    if (isset($_GET['approve_att']) || isset($_GET['reject_att'])) {
        $return_tab = isset($_GET['tab']) ? mysqli_real_escape_string($connection, $_GET['tab']) : 'attendance';
        header("Location: admin.php?tab=" . urlencode($return_tab));
        exit();
    }

    // APPROVE USER ACCOUNT
    if (isset($_GET['approve_user'])) {
        $id = mysqli_real_escape_string($connection, $_GET['approve_user']);
        mysqli_query($connection, "UPDATE isfinals SET approval_status='Approved' WHERE id='$id'");
        header("Location: admin.php?tab=users"); exit();
    }

    // REJECT USER ACCOUNT
    if (isset($_GET['reject_user'])) {
        $id = mysqli_real_escape_string($connection, $_GET['reject_user']);
        mysqli_query($connection, "UPDATE isfinals SET approval_status='Rejected' WHERE id='$id'");
        header("Location: admin.php?tab=users"); exit();
    }

    // SUBMIT FINAL GRADE
    if (isset($_POST['submit_grade'])) {
        $student_id = mysqli_real_escape_string($connection, $_POST['student_id']);
        $grade = mysqli_real_escape_string($connection, $_POST['grade']);

        if ($student_id && $grade) {
            mysqli_query($connection, "UPDATE students SET grade='$grade' WHERE id='$student_id'");
            header("Location: admin.php?tab=grades&success=1"); exit();
        }
    }

    // UPDATE CONCERN STATUS
    if (isset($_POST['update_concern_status'])) {
        $concern_id = mysqli_real_escape_string($connection, $_POST['concern_id']);
        $new_status = mysqli_real_escape_string($connection, $_POST['new_status']);
        $teacher_response = mysqli_real_escape_string($connection, $_POST['teacher_response']);

        if ($concern_id && $new_status) {
            $update_fields = "status='$new_status'";
            $db_response_column = $concern_response_column ?? 'teacher_response';
            if (!empty($teacher_response)) {
                $update_fields .= ", $db_response_column='$teacher_response', response_date=NOW()";
            } elseif (empty($teacher_response) && !empty($_POST['teacher_response'])) {
                // If response is empty but was provided, clear it
                $update_fields .= ", $db_response_column=NULL, response_date=NULL";
            }
            mysqli_query($connection, "UPDATE student_concerns SET $update_fields WHERE id='$concern_id'");
            header("Location: admin.php?tab=concerns&success=1"); exit();
        }
    }

    // EDIT CONCERN MESSAGE / FEEDBACK
    if (isset($_POST['edit_concern'])) {
        $concern_id = mysqli_real_escape_string($connection, $_POST['concern_id']);
        $message = mysqli_real_escape_string($connection, trim($_POST['message'] ?? ''));
        $teacher_response = mysqli_real_escape_string($connection, trim($_POST['teacher_response'] ?? ''));
        $db_response_column = $concern_response_column ?? 'teacher_response';

        if ($concern_id) {
            $update_fields = "message='$message'";
            if (!empty($teacher_response)) {
                $update_fields .= ", $db_response_column='$teacher_response', response_date=NOW()";
            } else {
                $update_fields .= ", $db_response_column=NULL, response_date=NULL";
            }
            mysqli_query($connection, "UPDATE student_concerns SET $update_fields WHERE id='$concern_id'");
            header("Location: admin.php?tab=concerns&success=1"); exit();
        }
    }
}

$active_tab = $_GET['tab'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Work Immersion Monitoring System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
            --muted: #6b7280;
            --accent: #1f744f;
            --accent-light: #22c55e;
            --shadow-sm: 0 4px 12px rgba(15, 58, 40, 0.08);
            --shadow-md: 0 8px 25px rgba(15, 58, 40, 0.12);
            --shadow-lg: 0 20px 60px rgba(15, 58, 40, 0.15);
            --shadow-xl: 0 25px 75px rgba(15, 58, 40, 0.18);
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            --gradient-secondary: linear-gradient(135deg, #f8faf9 0%, #e8f5e8 100%);
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
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(circle at 20% 80%, rgba(31, 116, 79, 0.03) 0%, transparent 40%),
                radial-gradient(circle at 80% 20%, rgba(15, 58, 40, 0.03) 0%, transparent 40%);
            pointer-events: none;
            z-index: -1;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .container-fluid {
            position: relative;
            z-index: 1;
        }

        .sidebar {
            background: var(--gradient-primary);
            min-height: 100vh;
            color: #ecfdf5;
            transition: all 0.4s ease;
            border-right: 1px solid rgba(255,255,255,0.1);
            backdrop-filter: blur(20px);
            position: relative;
            overflow: hidden;
        }

        .sidebar::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(45deg, rgba(255,255,255,0.08) 0%, transparent 100%);
            pointer-events: none;
        }

        .sidebar .nav-link {
            color: rgba(236, 253, 245, 0.95);
            border-radius: 16px;
            margin-bottom: 10px;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 14px 20px;
            display: flex;
            align-items: center;
            font-weight: 500;
            position: relative;
        }

        .sidebar .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transition: left 0.5s;
        }

        .sidebar .nav-link:hover::before {
            left: 100%;
        }

        .sidebar .nav-link.active,
        .sidebar .nav-link:hover {
            color: #ffffff;
            background: rgba(255,255,255,0.16);
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
            transition: all 0.4s ease;
            position: relative;
            overflow: visible;
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
        }

        .card:hover::before {
            opacity: 1;
        }

        .card-header {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(248,250,249,0.9) 100%);
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
            inset: 0;
            background: linear-gradient(135deg, rgba(31, 116, 79, 0.02) 0%, rgba(15, 58, 40, 0.02) 100%);
            pointer-events: none;
        }

        .topbar .search-input {
            max-width: 420px;
            flex: 1;
            margin-right: 2rem;
            position: relative;
            z-index: 1;
        }

        .topbar .search-input .form-control {
            border-radius: 14px;
            border: 1px solid rgba(15, 58, 40, 0.15);
            background: #ffffff;
            box-shadow: none;
            transition: all 0.25s ease;
        }

        .topbar .search-input .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.15rem rgba(15, 58, 40, 0.12);
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
        }

        .status-badge {
            font-size: 0.7rem;
            padding: 8px 14px;
            border-radius: 20px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: rgba(15, 58, 40, 0.12);
            color: var(--primary);
            display: inline-block;
        }

        .table {
            background: transparent;
        }

        .table thead {
            background: rgba(15, 58, 40, 0.06);
            text-transform: uppercase;
            font-size: 0.76rem;
            letter-spacing: 0.1em;
            color: var(--muted);
            font-weight: 700;
        }

        .table tbody tr {
            border-bottom: 1px solid var(--border-light);
        }

        .table tbody tr:hover {
            background: linear-gradient(135deg, rgba(31, 116, 79, 0.02) 0%, rgba(15, 58, 40, 0.02) 100%);
            transform: scale(1.01);
            box-shadow: 0 4px 12px rgba(15, 58, 40, 0.06);
        }

        .btn-primary {
            background: var(--gradient-primary);
            border: none;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            font-weight: 600;
            transition: all 0.3s ease;
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
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline-secondary {
            color: var(--text);
            border: 2px solid rgba(15, 23, 42, 0.12);
            background: #ffffff;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            padding: 0.75rem 1.5rem;
        }

        .btn-outline-secondary:hover {
            background: rgba(31, 138, 85, 0.08);
            border-color: var(--accent);
        }

        .btn-outline-danger {
            color: #b91c1c;
            border: 2px solid rgba(185, 28, 28, 0.18);
            border-radius: 12px;
            font-weight: 600;
        }

        .btn-outline-danger:hover {
            background: rgba(185, 28, 28, 0.08);
        }

        .btn-success, .btn-info, .btn-warning {
            border-radius: 12px;
            font-weight: 600;
            border: none;
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
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(15, 58, 40, 0.04);
        }

        .admin-login-input {
            position: relative;
        }

        .admin-login-input i {
            position: absolute;
            top: 50%;
            left: 16px;
            transform: translateY(-50%);
            color: rgba(15, 58, 40, 0.55);
            font-size: 1.05rem;
            display: grid;
            place-items: center;
            width: 1.75rem;
            height: 1.75rem;
        }

        .admin-login-input input {
            padding-left: 3rem;
        }


        .form-control:focus,
        .form-select:focus,
        textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(15, 58, 40, 0.12);
            background: #ffffff;
            color: var(--text);
        }

        .form-label {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 0.65rem;
        }

        .bg-light {
            background: rgba(255, 255, 255, 0.92) !important;
        }

        .text-muted {
            color: var(--muted) !important;
        }

        .admin-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.25s ease;
        }

        .admin-link:hover {
            color: var(--accent);
            text-decoration: underline;
        }

        main {
            padding: 2rem 2.5rem;
        }

        .mobile-menu-toggle {
            display: none;
            background: var(--primary);
            border: none;
            color: white;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.25rem;
            z-index: 1000;
            margin-bottom: 1rem;
        }

        .table-responsive {
            overflow-x: visible !important;
            overflow-y: visible !important;
            -webkit-overflow-scrolling: touch;
        }

        .table-responsive .dropdown-menu {
            position: absolute !important;
            z-index: 10550 !important;
            white-space: nowrap;
        }

        .card {
            overflow: visible !important;
        }

        /* Action buttons styling */
        .table .btn-group .btn {
            border-radius: 6px !important;
            margin: 0 1px;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            line-height: 1;
            min-width: 32px;
        }

        .table .btn-group .btn i {
            font-size: 0.8rem;
        }

        /* Responsive action buttons */
        @media (max-width: 768px) {
            .table .d-flex.flex-wrap {
                flex-direction: column !important;
                align-items: flex-end !important;
                gap: 0.25rem !important;
            }

            .table .btn-group {
                margin: 0 !important;
            }

            .table .btn-group .btn {
                padding: 0.375rem 0.5rem;
                font-size: 0.8rem;
                min-width: 36px;
            }
        }

        @media (max-width: 576px) {
            .table .d-flex.flex-wrap {
                align-items: center !important;
                justify-content: center !important;
            }

            .table .btn-group .btn {
                padding: 0.4rem 0.6rem;
                font-size: 0.85rem;
                min-width: 40px;
            }

            .table .btn-group .btn i {
                font-size: 0.9rem;
            }
        }

        @media (max-width: 767.98px) {
            .mobile-menu-toggle {
                display: inline-block;
            }

            .sidebar {
                position: fixed;
                top: 0;
                left: -260px;
                width: 260px;
                height: 100vh;
                overflow-y: auto;
                z-index: 999;
                transition: left 0.3s ease;
            }

            .sidebar.active {
                left: 0;
            }

            main {
                padding: 1rem;
                margin-left: 0;
            }

            .topbar {
                flex-wrap: wrap;
                gap: 0.75rem;
                padding: 1rem;
                margin-bottom: 1.5rem;
            }

            .search-input {
                order: 3;
                flex-basis: 100%;
            }

            .user-chip {
                font-size: 0.85rem;
                gap: 8px;
            }

            .user-chip .avatar {
                width: 40px;
                height: 40px;
                font-size: 0.9rem;
            }

            .card {
                border-radius: 12px;
                margin-bottom: 1rem;
            }

            .table {
                font-size: 0.8rem;
            }

            .table thead th {
                padding: 0.75rem 0.5rem;
                font-size: 0.7rem;
            }

            .table tbody td {
                padding: 0.75rem 0.5rem;
            }

            .btn {
                padding: 0.5rem 0.75rem;
                font-size: 0.8rem;
            }

            h2 {
                font-size: 1.5rem !important;
            }

            .row {
                --bs-gutter-x: 0.75rem;
            }
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

        @media (max-width: 576px) {
            main {
                padding: 0.75rem;
            }

            h2 {
                font-size: 1.25rem !important;
            }

            .card {
                padding: 0.75rem;
            }

            .modal-body {
                padding: 1rem;
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

            /* Improve table readability on mobile */
            .table-responsive {
                font-size: 0.85rem;
            }

            .table th, .table td {
                padding: 0.5rem;
            }
        }

        /* Glass effect for modals */
        .modal-content {
            background: rgba(255, 255, 255, 0.98);
            border: 1px solid rgba(15, 58, 40, 0.12);
            border-radius: 24px;
            box-shadow: 0 30px 80px rgba(15, 58, 40, 0.22);
            backdrop-filter: blur(12px);
        }

        .modal-header {
            border-bottom: 1px solid rgba(15, 58, 40, 0.06);
            background: transparent;
        }

        .btn-close {
            filter: invert(0);
        }

        /* Dashboard specific */
        .display-4, .display-6 {
            color: var(--primary);
        }

        .border-start {
            border-left: 4px solid !important;
        }

        .border-primary {
            border-color: var(--accent) !important;
        }

        .text-primary {
            color: var(--primary) !important;
        }

        .text-success {
            color: #16a34a !important;
        }

        .text-warning {
            color: #d97706 !important;
        }

        .text-info {
            color: #0891b2 !important;
        }

        /* Modal gradient backgrounds */
        .bg-gradient-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%) !important;
        }

        .bg-gradient-success {
            background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%) !important;
        }

        /* Avatar circles for modals */
        .avatar-circle-lg {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--gradient-primary);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 3px solid rgba(255,255,255,0.8);
        }

        /* Enhanced modal styling */
        .modal-lg {
            max-width: 800px;
        }

        .modal-xl {
            max-width: 1200px;
        }

        .modal-header.bg-gradient-primary,
        .modal-header.bg-gradient-success {
            color: white;
            border-bottom: none;
        }

        .modal-header.bg-gradient-primary .modal-title,
        .modal-header.bg-gradient-success .modal-title {
            color: white;
        }

        .modal-footer.bg-light {
            background: rgba(248, 250, 249, 0.95) !important;
            border-top: 1px solid rgba(15, 58, 40, 0.06);
        }

        /* Card enhancements for modals */
        .card.border-start {
            border-left-width: 4px !important;
        }

        .card.border-primary {
            border-color: var(--accent) !important;
        }

        .card.border-success {
            border-color: #16a34a !important;
        }

        /* Form control enhancements */
        .form-select-lg {
            font-size: 1rem;
            padding: 0.75rem 1rem;
        }

        /* Alert enhancements */
        .alert {
            border-radius: 12px;
            border: none;
        }

        .alert-info {
            background: rgba(8, 145, 178, 0.1);
            color: #0891b2;
        }

        .alert-warning {
            background: rgba(217, 119, 6, 0.1);
            color: #d97706;
        }

        /* File Upload UI Enhancements */
        .bg-gradient-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%) !important;
            color: white !important;
        }

        .file-item {
            transition: all 0.3s ease;
            border: 1px solid var(--border-light) !important;
        }

        .file-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--accent-light) !important;
        }

        .file-icon {
            min-width: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .file-card {
            transition: all 0.3s ease;
        }

        .file-card:hover {
            transform: translateY(-4px);
        }

        /* Search and Filter Bar */
        .input-group-text {
            background: var(--surface);
            border-color: var(--border);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 0.2rem rgba(31, 116, 79, 0.15);
        }

        /* Dashboard Enhancements */
        .metric-card {
            transition: all 0.3s ease;
            border-radius: 16px !important;
        }

        .metric-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg) !important;
        }

        .metric-icon {
            transition: all 0.3s ease;
        }

        .metric-card:hover .metric-icon {
            transform: scale(1.1);
        }

        .metric-value {
            font-size: 2rem;
            line-height: 1;
        }

        .metric-label {
            font-size: 0.875rem;
        }

        .metric-trend {
            font-size: 0.75rem;
        }

        /* Quick Actions */
        .action-icon {
            transition: all 0.3s ease;
        }

        .list-group-item:hover .action-icon {
            transform: scale(1.1);
        }

        .list-group-item {
            transition: all 0.3s ease;
            border-radius: 0 !important;
        }

        .list-group-item:hover {
            background-color: rgba(var(--primary), 0.05) !important;
            transform: translateX(4px);
        }

        /* Activity Timeline */
        .activity-timeline {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            transition: all 0.3s ease;
        }

        .activity-item:hover {
            background-color: rgba(var(--primary), 0.02);
        }

        .activity-icon {
            transition: all 0.3s ease;
        }

        .activity-item:hover .activity-icon {
            transform: scale(1.1);
        }

        /* System Status */
        .system-status-item {
            transition: all 0.3s ease;
        }

        .system-status-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .status-icon {
            transition: all 0.3s ease;
        }

        .system-status-item:hover .status-icon {
            transform: scale(1.1);
        }

        /* Summary Stats */
        .summary-stats .border-bottom {
            border-color: rgba(var(--border)) !important;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .metric-value {
                font-size: 1.5rem;
            }

            .display-5 {
                font-size: 2rem;
            }

            .metric-card {
                margin-bottom: 1rem;
            }
        }

        @media (max-width: 576px) {
            .metric-value {
                font-size: 1.25rem;
            }

            .metric-card .card-body {
                padding: 1rem !important;
            }
        }

        /* Responsive adjustments for file cards */
        @media (max-width: 768px) {
            .file-item .d-flex {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 0.5rem;
            }

            .file-item .d-flex > div:last-child {
                align-self: flex-end;
            }

            .file-icon {
                align-self: flex-start;
            }
        }

        /* Stats cards in header */
        .text-center .fw-bold {
            font-size: 1.5rem;
            line-height: 1;
        }

        .text-center small {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<!-- Admin login page -->
<?php if (!isset($_SESSION['admin_auth'])): ?>
    <div class="container-fluid d-flex justify-content-center align-items-center" style="min-height: 100vh; padding: 1.5rem; background: linear-gradient(180deg, rgba(15, 58, 40, 0.72), rgba(15, 58, 40, 0.18)), url('psback.jpg'); background-size: cover; background-position: center; background-repeat: no-repeat; position: relative;">
        <div style="content: ''; position: absolute; inset: 0; background: rgba(15, 58, 40, 0.38); pointer-events: none; z-index: 0;"></div>
        <div class="card p-5 shadow-lg text-center" style="width: 100%; max-width: 480px; backdrop-filter: blur(12px); position: relative; z-index: 1;">
            <div style="width: 100px; height: 100px; border-radius: 50%; margin: 0 auto 1.5rem; border: 1px solid rgba(15, 58, 40, 0.12); background: white; display: grid; place-items: center; overflow: hidden;">
                <img src="psnhs.jpeg" alt="School Logo" style="width: 85px; height: 85px; object-fit: contain;">
            </div>
            <h2 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.5rem;">Admin Portal</h2>
            <p class="text-muted mb-4" style="font-size: 1rem;">Work Immersion Monitoring System</p>
            <?php if(isset($error)) echo "<div class='alert alert-danger py-2 small' style='border-radius: 12px;'>$error</div>"; ?>
            <form method="POST">
                <div class="mb-3 text-start">
                    <label class="form-label">Username</label>
                    <div class="admin-login-input">
                        <i class="bi bi-person-fill"></i>
                        <input type="text" name="user" class="form-control" placeholder="Enter your username" required>
                    </div>
                </div>
                <div class="mb-4 text-start">
                    <label class="form-label">Password</label>
                    <div class="admin-login-input">
                        <i class="bi bi-lock-fill"></i>
                        <input type="password" name="pass" class="form-control" placeholder="Enter your password" required>
                    </div>
                </div>
                <button type="submit" name="login" class="btn btn-primary w-100 py-2 mb-3" style="font-size: 1rem; border-radius: 16px;">Sign In</button>
                <a href="index.php" class="admin-link">Back to Main Login</a>
            </form>
        </div>
    </div>
    <!-- Admin dashboard page -->
    <?php else: ?>
    <div class="container-fluid">
        <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="bi bi-list"></i></button>
        <div class="row">
            <!-- Sidebar navigation menu -->
            <nav class="col-md-2 d-none d-md-block sidebar p-3 shadow" id="sidebar">
                <div style="text-align: center; margin-bottom: 1.5rem; padding-top: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.12);">
                    <img src="psnhs.jpeg" alt="School Logo" style="width: 60px; height: 60px; object-fit: contain; border-radius: 8px; margin-bottom: 1rem;">
                    <h5 class="fw-bold mb-0" style="font-size: 0.95rem; letter-spacing: 0.05em;">WORK IMMERSION</h5>
                    <small style="font-size: 0.8rem; opacity: 0.85;">Monitoring System</small>
                </div>
                <ul class="nav flex-column">
                    <li class="nav-item"><a href="?tab=dashboard" class="nav-link <?= $active_tab == 'dashboard' ? 'active' : '' ?>"><i class="bi bi-house-door me-2"></i> Dashboard</a></li>
                    <li class="nav-item"><a href="?tab=users" class="nav-link <?= $active_tab == 'users' ? 'active' : '' ?>"><i class="bi bi-people me-2"></i> Users Management</a></li>
                    <li class="nav-item"><a href="?tab=files" class="nav-link <?= $active_tab == 'files' ? 'active' : '' ?>"><i class="bi bi-file-earmark me-2"></i> Uploaded Files</a></li>
                    <li class="nav-item"><a href="?tab=grades" class="nav-link <?= $active_tab == 'grades' ? 'active' : '' ?>"><i class="bi bi-award me-2"></i> Final Grades</a></li>
                    <li class="nav-item"><a href="?tab=database" class="nav-link <?= $active_tab == 'database' ? 'active' : '' ?>"><i class="bi bi-database me-2"></i> Database Status</a></li>
                    <li class="nav-item"><a href="?tab=attendance" class="nav-link <?= $active_tab == 'attendance' ? 'active' : '' ?>"><i class="bi bi-clock-history me-2"></i> Attendance Log</a></li>
                    <li class="nav-item"><a href="?tab=announcements" class="nav-link <?= $active_tab == 'announcements' ? 'active' : '' ?>"><i class="bi bi-megaphone me-2"></i> Announcements</a></li>
                    <li class="nav-item"><a href="?tab=reports" class="nav-link <?= $active_tab == 'reports' ? 'active' : '' ?>"><i class="bi bi-journal-text me-2"></i> Student Reports</a></li>
                    <li class="nav-item"><a href="?tab=concerns" class="nav-link <?= $active_tab == 'concerns' ? 'active' : '' ?>"><i class="bi bi-exclamation-triangle me-2"></i> Student Concerns</a></li>
                    <li class="nav-item mt-5"><a href="?logout=1" class="nav-link text-danger border border-danger"><i class="bi bi-power me-2"></i> Logout</a></li>
                </ul>
            </nav>

            <!-- Main dashboard content area -->
            <main class="col-md-10 px-md-4 py-4">
                <!-- Dashboard topbar and action area -->
                <div class="topbar">
                    <div class="search-input w-100 me-3">
                        <input type="text" class="form-control" placeholder="Search students, classes...">
                    </div>
                    <div class="user-chip">
                        <span class="avatar">SA</span>
                        <div>
                            <div class="fw-bold">System Administrator</div>
                            <div class="small text-muted">Admin</div>
                        </div>
                    </div>
                </div>
                <!-- Dashboard overview tab -->
                <?php if ($active_tab == 'dashboard'): ?>
                    <?php
                        // Calculate comprehensive dashboard statistics
                        $total_students = count(array_filter($students_db, function($s) { return isset($s['id']); }));
                        $total_users = count($users);
                        $pending_approvals = 0;
                        $active_students = 0;
                        $total_attendance = count($attendance_db);
                        $pending_attendance = count($pending_attendance_by_student);
                        $total_announcements = count($announcements_db);
                        $total_concerns = count($concerns_db);

                        // Calculate file statistics
                        $total_requirements = 0;
                        $total_uploaded_files = 0;
                        foreach ($students_db as $s) {
                            $uploaded_files = is_array($s['uploaded_files']) ? $s['uploaded_files'] : (json_decode($s['uploaded_files'], true) ?: []);
                            $total_uploaded_files += count($uploaded_files);
                            foreach ($s['requirements'] as $data) {
                                if (is_array($data) && isset($data['file'])) $total_requirements++;
                            }
                        }

                        foreach ($users as $u) {
                            if (isset($u['approval_status']) && $u['approval_status'] === 'Pending') {
                                $pending_approvals++;
                            }
                            if (!isset($u['approval_status']) || $u['approval_status'] === 'Approved') {
                                $active_students++;
                            }
                        }

                        // Recent activity data
                        $recent_attendance = array_slice(array_reverse($attendance_db), 0, 5);
                        $recent_announcements = array_slice(array_reverse($announcements_db), 0, 3);
                        $recent_concerns = array_slice(array_reverse($concerns_db), 0, 3);
                    ?>

                    <!-- Dashboard Header -->
                    <div class="dashboard-header mb-4">
                        <div class="d-flex justify-content-between align-items-start flex-column flex-lg-row gap-3">
                            <div class="flex-grow-1">
                                <h1 class="display-5 fw-bold mb-2" style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                                    <i class="bi bi-house-door-fill me-3"></i>Dashboard Overview
                                </h1>
                                <p class="text-muted lead mb-0">Welcome back! Here's what's happening in your system today.</p>
                            </div>
                            <div class="text-end">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="text-muted small">
                                        <i class="bi bi-clock-history me-1"></i>
                                        Last updated: <strong><?= date('M d, Y H:i') ?></strong>
                                    </div>
                                    <button class="btn btn-outline-primary btn-sm" onclick="location.reload()">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Key Metrics Row -->
                    <div class="row g-4 mb-4">
                        <!-- Students Overview -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card h-100 border-0 shadow-sm metric-card">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <div class="metric-icon bg-primary bg-opacity-10 rounded-3 p-3">
                                            <i class="bi bi-people-fill text-primary fs-2"></i>
                                        </div>
                                        <div class="metric-trend text-success small fw-bold">
                                            <i class="bi bi-graph-up-arrow me-1"></i>+12%
                                        </div>
                                    </div>
                                    <h2 class="metric-value mb-1 fw-bold text-primary"><?= $total_students ?></h2>
                                    <p class="metric-label text-muted mb-2">Total Students</p>
                                    <div class="progress" style="height: 4px;">
                                        <div class="progress-bar bg-primary" style="width: 85%"></div>
                                    </div>
                                    <small class="text-muted mt-2 d-block">Active: <?= $active_students ?> | Pending: <?= $pending_approvals ?></small>
                                </div>
                            </div>
                        </div>

                        <!-- Attendance Overview -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card h-100 border-0 shadow-sm metric-card">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <div class="metric-icon bg-success bg-opacity-10 rounded-3 p-3">
                                            <i class="bi bi-check-circle-fill text-success fs-2"></i>
                                        </div>
                                        <div class="metric-trend text-success small fw-bold">
                                            <i class="bi bi-graph-up-arrow me-1"></i>+8%
                                        </div>
                                    </div>
                                    <h2 class="metric-value mb-1 fw-bold text-success"><?= $total_attendance ?></h2>
                                    <p class="metric-label text-muted mb-2">Total Attendance</p>
                                    <div class="progress" style="height: 4px;">
                                        <div class="progress-bar bg-success" style="width: 92%"></div>
                                    </div>
                                    <small class="text-muted mt-2 d-block">Pending: <?= $pending_attendance ?> records</small>
                                </div>
                            </div>
                        </div>

                        <!-- Files Overview -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card h-100 border-0 shadow-sm metric-card">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <div class="metric-icon bg-info bg-opacity-10 rounded-3 p-3">
                                            <i class="bi bi-file-earmark-text-fill text-info fs-2"></i>
                                        </div>
                                        <div class="metric-trend text-info small fw-bold">
                                            <i class="bi bi-graph-up-arrow me-1"></i>+15%
                                        </div>
                                    </div>
                                    <h2 class="metric-value mb-1 fw-bold text-info"><?= $total_requirements + $total_uploaded_files ?></h2>
                                    <p class="metric-label text-muted mb-2">Total Files</p>
                                    <div class="progress" style="height: 4px;">
                                        <div class="progress-bar bg-info" style="width: 78%"></div>
                                    </div>
                                    <small class="text-muted mt-2 d-block">Req: <?= $total_requirements ?> | Add: <?= $total_uploaded_files ?></small>
                                </div>
                            </div>
                        </div>

                        <!-- System Health -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card h-100 border-0 shadow-sm metric-card">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <div class="metric-icon bg-warning bg-opacity-10 rounded-3 p-3">
                                            <i class="bi bi-activity text-warning fs-2"></i>
                                        </div>
                                        <div class="metric-trend text-success small fw-bold">
                                            <i class="bi bi-check-circle me-1"></i>Healthy
                                        </div>
                                    </div>
                                    <h2 class="metric-value mb-1 fw-bold text-warning">100%</h2>
                                    <p class="metric-label text-muted mb-2">System Health</p>
                                    <div class="progress" style="height: 4px;">
                                        <div class="progress-bar bg-warning" style="width: 100%"></div>
                                    </div>
                                    <small class="text-muted mt-2 d-block">All systems operational</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Content Row -->
                    <div class="row g-4 mb-4">
                        <!-- Quick Actions Panel -->
                        <div class="col-xl-4 col-lg-5">
                            <div class="card h-100 border-0 shadow-sm">
                                <div class="card-header bg-gradient-primary text-white border-0">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-lightning-charge-fill me-2 fs-5"></i>
                                        <h5 class="mb-0 fw-bold">Quick Actions</h5>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <div class="list-group list-group-flush">
                                        <a href="?tab=users" class="list-group-item list-group-item-action d-flex align-items-center py-3 px-4 border-0">
                                            <div class="action-icon bg-primary bg-opacity-10 rounded-3 p-2 me-3">
                                                <i class="bi bi-person-plus-fill text-primary"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold text-primary">Manage Users</div>
                                                <small class="text-muted">Add, edit, or remove accounts</small>
                                            </div>
                                            <i class="bi bi-chevron-right text-muted"></i>
                                        </a>
                                        <a href="?tab=attendance" class="list-group-item list-group-item-action d-flex align-items-center py-3 px-4 border-0">
                                            <div class="action-icon bg-success bg-opacity-10 rounded-3 p-2 me-3">
                                                <i class="bi bi-clock-fill text-success"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold text-success">Attendance Logs</div>
                                                <small class="text-muted">Review and approve records</small>
                                            </div>
                                            <i class="bi bi-chevron-right text-muted"></i>
                                        </a>
                                        <a href="?tab=files" class="list-group-item list-group-item-action d-flex align-items-center py-3 px-4 border-0">
                                            <div class="action-icon bg-info bg-opacity-10 rounded-3 p-2 me-3">
                                                <i class="bi bi-folder-fill text-info"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold text-info">File Management</div>
                                                <small class="text-muted">Review student uploads</small>
                                            </div>
                                            <i class="bi bi-chevron-right text-muted"></i>
                                        </a>
                                        <a href="?tab=announcements" class="list-group-item list-group-item-action d-flex align-items-center py-3 px-4 border-0">
                                            <div class="action-icon bg-warning bg-opacity-10 rounded-3 p-2 me-3">
                                                <i class="bi bi-megaphone-fill text-warning"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold text-warning">Announcements</div>
                                                <small class="text-muted">Create system messages</small>
                                            </div>
                                            <i class="bi bi-chevron-right text-muted"></i>
                                        </a>
                                        <a href="?tab=grades" class="list-group-item list-group-item-action d-flex align-items-center py-3 px-4 border-0">
                                            <div class="action-icon bg-danger bg-opacity-10 rounded-3 p-2 me-3">
                                                <i class="bi bi-award-fill text-danger"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold text-danger">Grade Management</div>
                                                <small class="text-muted">Assign final grades</small>
                                            </div>
                                            <i class="bi bi-chevron-right text-muted"></i>
                                        </a>
                                        <a href="?tab=reports" class="list-group-item list-group-item-action d-flex align-items-center py-3 px-4 border-0 rounded-bottom">
                                            <div class="action-icon bg-secondary bg-opacity-10 rounded-3 p-2 me-3">
                                                <i class="bi bi-bar-chart-fill text-secondary"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold text-secondary">Reports</div>
                                                <small class="text-muted">View system reports</small>
                                            </div>
                                            <i class="bi bi-chevron-right text-muted"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Activity & System Status -->
                        <div class="col-xl-8 col-lg-7">
                            <div class="row g-4">
                                <!-- Recent Activity -->
                                <div class="col-12">
                                    <div class="card h-100 border-0 shadow-sm">
                                        <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-activity text-primary me-2 fs-5"></i>
                                                <h5 class="mb-0 fw-bold">Recent Activity</h5>
                                            </div>
                                            <span class="badge bg-primary">Live</span>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="activity-timeline">
                                                <?php if (!empty($recent_attendance)): ?>
                                                    <?php foreach ($recent_attendance as $att): ?>
                                                        <div class="activity-item d-flex align-items-start p-3 border-bottom">
                                                            <div class="activity-icon bg-success bg-opacity-10 rounded-circle p-2 me-3">
                                                                <i class="bi bi-clock-fill text-success"></i>
                                                            </div>
                                                            <div class="flex-grow-1">
                                                                <div class="d-flex justify-content-between align-items-start">
                                                                    <div>
                                                                        <div class="fw-semibold text-success small mb-1">Attendance Recorded</div>
                                                                        <div class="text-muted small">
                                                                            <?= htmlspecialchars($att['name']) ?> logged attendance
                                                                        </div>
                                                                    </div>
                                                                    <small class="text-muted">
                                                                        <?= htmlspecialchars($att['date']) ?> <?= htmlspecialchars($att['time_in']) ?>
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>

                                                <?php if (!empty($recent_announcements)): ?>
                                                    <?php foreach ($recent_announcements as $ann): ?>
                                                        <div class="activity-item d-flex align-items-start p-3 border-bottom">
                                                            <div class="activity-icon bg-info bg-opacity-10 rounded-circle p-2 me-3">
                                                                <i class="bi bi-megaphone-fill text-info"></i>
                                                            </div>
                                                            <div class="flex-grow-1">
                                                                <div class="d-flex justify-content-between align-items-start">
                                                                    <div>
                                                                        <div class="fw-semibold text-info small mb-1">Announcement Posted</div>
                                                                        <div class="text-muted small">
                                                                            "<?= htmlspecialchars(substr($ann['title'], 0, 40)) ?>..."
                                                                        </div>
                                                                    </div>
                                                                    <small class="text-muted">
                                                                        <?= htmlspecialchars($ann['date']) ?>
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>

                                                <?php if (!empty($recent_concerns)): ?>
                                                    <?php foreach ($recent_concerns as $concern): ?>
                                                        <div class="activity-item d-flex align-items-start p-3 border-bottom">
                                                            <div class="activity-icon bg-warning bg-opacity-10 rounded-circle p-2 me-3">
                                                                <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                                                            </div>
                                                            <div class="flex-grow-1">
                                                                <div class="d-flex justify-content-between align-items-start">
                                                                    <div>
                                                                        <div class="fw-semibold text-warning small mb-1">Concern Submitted</div>
                                                                        <div class="text-muted small">
                                                                            New concern from <?= htmlspecialchars($concern['student_name'] ?? 'student') ?>
                                                                        </div>
                                                                    </div>
                                                                    <small class="text-muted">
                                                                        <?= htmlspecialchars($concern['date'] ?? date('M d')) ?>
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>

                                                <?php if (empty($recent_attendance) && empty($recent_announcements) && empty($recent_concerns)): ?>
                                                    <div class="text-center py-5">
                                                        <i class="bi bi-graph-up text-muted mb-3" style="font-size: 3rem;"></i>
                                                        <h6 class="text-muted mb-2">No Recent Activity</h6>
                                                        <p class="text-muted small mb-0">Activity will appear here as users interact with the system</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- System Status & Additional Metrics -->
                    <div class="row g-4">
                        <!-- System Status Cards -->
                        <div class="col-xl-8">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-light border-0">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-shield-check text-success me-2 fs-5"></i>
                                        <h5 class="mb-0 fw-bold">System Status</h5>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row g-4">
                                        <div class="col-md-3 col-sm-6">
                                            <div class="system-status-item text-center p-3 rounded-3 bg-success bg-opacity-10 border border-success border-opacity-25">
                                                <div class="status-icon mb-2">
                                                    <i class="bi bi-database-check text-success fs-2"></i>
                                                </div>
                                                <h6 class="fw-bold text-success mb-1">Database</h6>
                                                <small class="text-muted">Connected</small>
                                                <div class="mt-2">
                                                    <i class="bi bi-check-circle-fill text-success"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-sm-6">
                                            <div class="system-status-item text-center p-3 rounded-3 bg-primary bg-opacity-10 border border-primary border-opacity-25">
                                                <div class="status-icon mb-2">
                                                    <i class="bi bi-file-earmark-check text-primary fs-2"></i>
                                                </div>
                                                <h6 class="fw-bold text-primary mb-1">File System</h6>
                                                <small class="text-muted">Active</small>
                                                <div class="mt-2">
                                                    <i class="bi bi-check-circle-fill text-primary"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-sm-6">
                                            <div class="system-status-item text-center p-3 rounded-3 bg-info bg-opacity-10 border border-info border-opacity-25">
                                                <div class="status-icon mb-2">
                                                    <i class="bi bi-envelope-check text-info fs-2"></i>
                                                </div>
                                                <h6 class="fw-bold text-info mb-1">Email System</h6>
                                                <small class="text-muted">Ready</small>
                                                <div class="mt-2">
                                                    <i class="bi bi-check-circle-fill text-info"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-sm-6">
                                            <div class="system-status-item text-center p-3 rounded-3 bg-warning bg-opacity-10 border border-warning border-opacity-25">
                                                <div class="status-icon mb-2">
                                                    <i class="bi bi-shield-lock text-warning fs-2"></i>
                                                </div>
                                                <h6 class="fw-bold text-warning mb-1">Security</h6>
                                                <small class="text-muted">Active</small>
                                                <div class="mt-2">
                                                    <i class="bi bi-check-circle-fill text-warning"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Stats Summary -->
                        <div class="col-xl-4">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-gradient-primary text-white border-0">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-bar-chart-fill me-2 fs-5"></i>
                                        <h5 class="mb-0 fw-bold">Today's Summary</h5>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="summary-stats">
                                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                            <span class="text-muted small">New Registrations</span>
                                            <span class="fw-bold text-primary">
                                                <?php
                                                $today_registrations = 0;
                                                foreach ($users as $u) {
                                                    if (isset($u['created_date']) && date('Y-m-d', strtotime($u['created_date'])) === date('Y-m-d')) {
                                                        $today_registrations++;
                                                    }
                                                }
                                                echo $today_registrations;
                                                ?>
                                            </span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                            <span class="text-muted small">Attendance Today</span>
                                            <span class="fw-bold text-success">
                                                <?php
                                                $today_attendance = 0;
                                                foreach ($attendance_db as $att) {
                                                    if ($att['date'] === date('Y-m-d')) {
                                                        $today_attendance++;
                                                    }
                                                }
                                                echo $today_attendance;
                                                ?>
                                            </span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                            <span class="text-muted small">Files Uploaded</span>
                                            <span class="fw-bold text-info">
                                                <?php
                                                $today_files = 0;
                                                // This would need file upload timestamps to be accurate
                                                echo $total_uploaded_files; // Placeholder
                                                ?>
                                            </span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center py-2">
                                            <span class="text-muted small">Active Sessions</span>
                                            <span class="fw-bold text-warning">1</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($active_tab == 'users'): ?>
                    <?php
                        $student_users = [];
                        $supervisors = [];
                        foreach ($users as $u) {
                            if ($u['role'] === 'owner_supervisor') {
                                $supervisors[] = $u;
                            } else {
                                $student_users[] = $u;
                            }
                        }
                    ?>
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-start flex-column flex-md-row gap-3">
                            <div>
                                <h2 class="mb-1" style="font-size: 2.25rem; font-weight: 800;">Users Management</h2>
                                <p class="text-muted small mb-0">Manage student and supervisor accounts in one place.</p>
                            </div>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal"><i class="bi bi-plus-lg me-1"></i> Add New User</button>
                        </div>

                        <!-- Statistics Cards -->
                        <div class="row g-3 mt-3">
                            <div class="col-sm-6 col-lg-3">
                                <div class="card border-primary shadow-sm h-100">
                                    <div class="card-body py-3 px-3">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <h6 class="text-uppercase text-muted mb-1">Student Accounts</h6>
                                                <h3 class="mb-0 fw-bold text-primary"><?= count($student_users) ?></h3>
                                            </div>
                                            <i class="bi bi-people-fill fs-3 text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="card border-success shadow-sm h-100">
                                    <div class="card-body py-3 px-3">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <h6 class="text-uppercase text-muted mb-1">Supervisor Accounts</h6>
                                                <h3 class="mb-0 fw-bold text-success"><?= count($supervisors) ?></h3>
                                            </div>
                                            <i class="bi bi-person-badge-fill fs-3 text-success"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="card border-warning shadow-sm h-100">
                                    <div class="card-body py-3 px-3">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <h6 class="text-uppercase text-muted mb-1">Pending Approvals</h6>
                                                <h3 class="mb-0 fw-bold text-warning">
                                                    <?php
                                                    $pending_count = 0;
                                                    foreach ($users as $u) {
                                                        if (isset($u['approval_status']) && $u['approval_status'] === 'Pending') {
                                                            $pending_count++;
                                                        }
                                                    }
                                                    echo $pending_count;
                                                    ?>
                                                </h3>
                                            </div>
                                            <i class="bi bi-clock-fill fs-3 text-warning"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="card border-info shadow-sm h-100">
                                    <div class="card-body py-3 px-3">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <h6 class="text-uppercase text-muted mb-1">Total Users</h6>
                                                <h3 class="mb-0 fw-bold text-info"><?= count($users) ?></h3>
                                            </div>
                                            <i class="bi bi-person-lines-fill fs-3 text-info"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Search Bar -->
                        <div class="mt-4">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" id="userSearch" placeholder="Search users by name, email, or company...">
                                <button class="btn btn-outline-secondary" type="button" onclick="clearSearch()">Clear</button>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($admin_error)): ?><div class="alert alert-danger"><?= htmlspecialchars($admin_error) ?></div><?php endif; ?>
                    <?php if (!empty($admin_message)): ?><div class="alert alert-success"><?= htmlspecialchars($admin_message) ?></div><?php endif; ?>
                    <div class="row g-4">
                        <div class="col-12">
                            <div class="card p-0">
                                <div class="card-header bg-light px-4 py-3">
                                    <h5 class="mb-0">Student Users</h5>
                                </div>
                                <div class="table-responsive">
                                    <table id="studentUsersTable" class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <th>Contact</th>
                                                <th class="d-none d-lg-table-cell">Company</th>
                                                <th class="d-none d-lg-table-cell">Supervisor</th>
                                                <th class="d-none d-lg-table-cell">Required Hrs</th>
                                                <th class="d-none d-lg-table-cell">Total Hrs</th>
                                                <th class="d-none d-lg-table-cell">Rendered Hrs</th>
                                                <th class="d-none d-lg-table-cell">Late</th>
                                                <th class="d-none d-lg-table-cell">Absent</th>
                                                <th>Status</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($student_users)): ?>
                                                <tr><td colspan="12" class="text-center text-muted py-4">No student users found.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($student_users as $u): ?>
                                                    <?php $student_record = $student_map[$u['id']] ?? null; ?>
                                                    <tr>
                                                        <td class="fw-bold"><?= htmlspecialchars($u['fullname']) ?></td>
                                                        <td><?= htmlspecialchars($u['email']) ?></td>
                                                        <td>
                                                            <?php
                                                            $student_contact = '';
                                                            if (!empty(trim($student_record['contact_number'] ?? ''))) {
                                                                $student_contact = trim($student_record['contact_number']);
                                                            } elseif (!empty(trim($student_record['isfinals_contact'] ?? ''))) {
                                                                $student_contact = trim($student_record['isfinals_contact']);
                                                            } else {
                                                                $student_contact = trim($u['contact_number'] ?? '');
                                                            }
                                                            if (!empty($student_contact)) {
                                                                echo '<span class="badge bg-light text-dark">' . htmlspecialchars($student_contact) . '</span>';
                                                            } else {
                                                                echo '<span class="text-muted small">N/A</span>';
                                                            }
                                                            ?>
                                                        </td>
                                                        <td class="d-none d-lg-table-cell"><?= htmlspecialchars($student_record['company_assigned'] ?? 'N/A') ?></td>
                                                        <td class="d-none d-lg-table-cell"><?= htmlspecialchars($student_record['supervisor_name'] ?? 'N/A') ?></td>
                                                        <td class="d-none d-lg-table-cell">
                                                            <span class="fw-semibold text-primary"><?= htmlspecialchars($student_record['required_hours'] ?? 0) ?></span>
                                                        </td>
                                                        <td class="d-none d-lg-table-cell">
                                                            <?php $total_hours = $student_total_hours[$u['id']] ?? 0; ?>
                                                            <span class="fw-semibold text-secondary"><?= number_format($total_hours, 1) ?> hrs</span>
                                                        </td>
                                                        <td class="d-none d-lg-table-cell">
                                                            <?php
                                                            $rendered = $student_record['total_hours_rendered'] ?? 0;
                                                            $required = $student_record['required_hours'] ?? 0;
                                                            $progress = $required > 0 ? min(100, ($rendered / $required) * 100) : 0;
                                                            ?>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <span class="fw-semibold"><?= $rendered ?></span>
                                                                <div class="progress flex-grow-1" style="height: 6px; width: 80px;">
                                                                    <div class="progress-bar bg-<?= $progress >= 100 ? 'success' : ($progress >= 75 ? 'info' : 'warning') ?>" role="progressbar"
                                                                         style="width: <?= $progress ?>%"
                                                                         aria-valuenow="<?= $progress ?>"
                                                                         aria-valuemin="0"
                                                                         aria-valuemax="100"></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="d-none d-lg-table-cell">
                                                            <?php $late_count = $student_record['late_count'] ?? 0; ?>
                                                            <span class="badge bg-<?= $late_count > 0 ? 'danger' : 'success' ?>">
                                                                <?= $late_count ?>
                                                            </span>
                                                        </td>
                                                        <td class="d-none d-lg-table-cell">
                                                            <?php $absent_count = $student_record['absent_count'] ?? 0; ?>
                                                            <span class="badge bg-<?= $absent_count > 0 ? 'warning' : 'success' ?> text-<?= $absent_count > 0 ? 'dark' : 'white' ?>">
                                                                <?= $absent_count ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if (isset($u['approval_status']) && $u['approval_status'] === 'Pending'): ?>
                                                                <span class="status-badge bg-warning text-dark">Pending Approval</span>
                                                            <?php elseif (isset($u['approval_status']) && $u['approval_status'] === 'Rejected'): ?>
                                                                <span class="status-badge bg-danger text-white">Rejected</span>
                                                            <?php else: ?>
                                                                <span class="status-badge bg-success">Active</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <?php if ($student_record): ?>
                                                                <div class="d-flex flex-wrap justify-content-end gap-1">
                                                                    <!-- Primary Actions -->
                                                                    <div class="btn-group" role="group">
                                                                        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#reqModal<?= htmlspecialchars($student_record['id']) ?>" title="View Files">
                                                                            <i class="bi bi-folder"></i>
                                                                        </button>
                                                                        <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#editCompanyModal<?= htmlspecialchars($student_record['id']) ?>" title="Edit Company">
                                                                            <i class="bi bi-building"></i>
                                                                        </button>
                                                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#editSupervisorModal<?= htmlspecialchars($student_record['id']) ?>" title="Edit Supervisor">
                                                                            <i class="bi bi-person"></i>
                                                                        </button>
                                                                    </div>

                                                                    <!-- Approval Actions (if pending) -->
                                                                    <?php if (isset($u['approval_status']) && $u['approval_status'] === 'Pending'): ?>
                                                                        <div class="btn-group ms-1" role="group">
                                                                            <a href="admin.php?approve_user=<?= htmlspecialchars($u['id']) ?>&tab=users" class="btn btn-success btn-sm" title="Approve Account" onclick="return confirm('Approve this user account?')">
                                                                                <i class="bi bi-check-circle"></i>
                                                                            </a>
                                                                            <a href="admin.php?reject_user=<?= htmlspecialchars($u['id']) ?>&tab=users" class="btn btn-warning btn-sm" title="Reject Account" onclick="return confirm('Reject this user account?')">
                                                                                <i class="bi bi-x-circle"></i>
                                                                            </a>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <!-- Danger Actions -->
                                                                    <div class="btn-group ms-1" role="group">
                                                                        <a href="?delete_user=<?= htmlspecialchars($u['id']) ?>" class="btn btn-outline-danger btn-sm" title="Delete User" onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                                                            <i class="bi bi-trash"></i>
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                                <?php if (!empty($pending_id)): ?>
                                                                    <div class="mt-1">
                                                                        <span class="badge bg-warning text-dark">Attendance pending</span>
                                                                    </div>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary text-white">No Record</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="card p-0">
                                <div class="card-header bg-light px-4 py-3">
                                    <h5 class="mb-0">Owner / Supervisor Users</h5>
                                </div>
                                <div class="table-responsive">
                                    <table id="supervisorUsersTable" class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr><th>Name</th><th>Email</th><th class="d-none d-md-table-cell">Contact</th><th class="text-end">Actions</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($supervisors)): ?>
                                                <tr><td colspan="5" class="text-center text-muted py-4">No owner or supervisor users found.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($supervisors as $u): ?>
                                                    <tr>
                                                        <td class="fw-bold"><?= htmlspecialchars($u['fullname']) ?></td>
                                                        <td><?= htmlspecialchars($u['email']) ?></td>
                                                        <td class="d-none d-md-table-cell">
                                                            <?php
                                                            $supervisor_contact = trim($u['supervisor_contact'] ?? $u['contact_number'] ?? '');
                                                            if (!empty($supervisor_contact)): ?>
                                                                <span class="badge bg-light text-dark"><?= htmlspecialchars($supervisor_contact) ?></span>
                                                            <?php else: ?>
                                                                <span class="text-muted small">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <span class="badge bg-info text-white">Owner / Supervisor</span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($active_tab == 'files'): ?>
                    <?php
                        $file_students = $students_db;
                        $total_files = 0;
                        $total_requirements = 0;
                        foreach ($file_students as $s) {
                            $uploaded_files = is_array($s['uploaded_files']) ? $s['uploaded_files'] : (json_decode($s['uploaded_files'], true) ?: []);
                            $total_files += count($uploaded_files);
                            foreach ($s['requirements'] as $data) {
                                if (is_array($data) && isset($data['file'])) $total_requirements++;
                            }
                        }
                    ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 style="font-size: 2.25rem; font-weight: 800; margin-bottom: 0;">Student File Uploads</h2>
                            <p class="text-muted mb-0">Manage and review student-submitted files</p>
                        </div>
                        <div class="text-end">
                            <div class="d-flex gap-3">
                                <div class="text-center">
                                    <div class="fw-bold text-primary fs-4"><?= $total_requirements ?></div>
                                    <small class="text-muted">Requirements</small>
                                </div>
                                <div class="text-center">
                                    <div class="fw-bold text-info fs-4"><?= $total_files ?></div>
                                    <small class="text-muted">Files</small>
                                </div>
                                <div class="text-center">
                                    <div class="fw-bold text-secondary fs-4"><?= count($file_students) ?></div>
                                    <small class="text-muted">Students</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Search and Filter Bar -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="text" id="fileSearch" class="form-control" placeholder="Search students or files...">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <select id="fileTypeFilter" class="form-select">
                                        <option value="">All File Types</option>
                                        <option value="requirements">Requirements Only</option>
                                        <option value="additional">Additional Files Only</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select id="statusFilter" class="form-select">
                                        <option value="">All Students</option>
                                        <option value="with-files">With Files</option>
                                        <option value="no-files">No Files</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (empty($file_students)): ?>
                        <div class="card p-5 text-center">
                            <i class="bi bi-file-earmark-x display-4 text-muted mb-3"></i>
                            <h5 class="text-muted">No Students Found</h5>
                            <p class="text-muted">No student records available to display uploaded files.</p>
                        </div>
                    <?php else: ?>
                        <div class="row" id="filesContainer">
                            <?php foreach ($file_students as $s):
                                $uploaded_files = is_array($s['uploaded_files']) ? $s['uploaded_files'] : (json_decode($s['uploaded_files'], true) ?: []);
                                $req_count = 0;
                                foreach ($s['requirements'] as $data) {
                                    if (is_array($data) && isset($data['file'])) $req_count++;
                                }
                                $has_files = !empty($uploaded_files) || $req_count > 0;
                            ?>
                                <div class="col-lg-6 mb-4 file-card" data-student-name="<?= htmlspecialchars(strtolower($s['fullname'])) ?>" data-has-files="<?= $has_files ? 'true' : 'false' ?>">
                                    <div class="card h-100 shadow-sm border-0 bg-white">
                                        <!-- Student Header -->
                                        <div class="card-header bg-gradient-primary text-white border-0">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1 fw-bold">
                                                        <i class="bi bi-person-circle me-2"></i>
                                                        <?= htmlspecialchars($s['fullname']) ?>
                                                    </h6>
                                                    <p class="mb-1 small opacity-75">
                                                        <i class="bi bi-envelope me-1"></i>
                                                        <?= htmlspecialchars($s['email'] ?? 'No email') ?>
                                                    </p>
                                                    <p class="mb-0 small opacity-75">
                                                        <i class="bi bi-hash me-1"></i>
                                                        Student ID: <?= htmlspecialchars($s['id']) ?>
                                                    </p>
                                                </div>
                                                <div class="text-end">
                                                    <?php if (!empty($s['email'])): ?>
                                                        <a href="email.php?to_email=<?= urlencode($s['email']) ?>&subject=Student%20Upload%20Review" class="btn btn-light btn-sm" title="Send Message">
                                                            <i class="bi bi-envelope"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="card-body">
                                            <!-- Requirements Section -->
                                            <div class="mb-4">
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <h6 class="text-primary mb-0 fw-bold">
                                                        <i class="bi bi-file-earmark-check me-2"></i>
                                                        Requirement Submissions
                                                    </h6>
                                                    <span class="badge bg-primary"><?= $req_count ?> files</span>
                                                </div>

                                                <?php if ($req_count > 0): ?>
                                                    <div class="row g-2">
                                                        <?php foreach ($s['requirements'] as $req => $data):
                                                            if (is_array($data) && isset($data['file'])):
                                                                $req_ext = strtolower(pathinfo($data['file'], PATHINFO_EXTENSION));
                                                                $req_icon = in_array($req_ext, ['jpg','jpeg','png','gif','webp']) ? 'bi-file-earmark-image' :
                                                                          (in_array($req_ext, ['pdf']) ? 'bi-file-earmark-pdf' :
                                                                          (in_array($req_ext, ['doc','docx']) ? 'bi-file-earmark-word' :
                                                                          (in_array($req_ext, ['xls','xlsx']) ? 'bi-file-earmark-excel' : 'bi-file-earmark-text')));
                                                                $file_size = isset($data['size']) ? formatFileSize($data['size']) : 'Unknown';
                                                        ?>
                                                            <div class="col-12">
                                                                <div class="file-item border rounded p-3 bg-light">
                                                                    <div class="d-flex align-items-center justify-content-between">
                                                                        <div class="d-flex align-items-center flex-grow-1">
                                                                            <div class="file-icon me-3">
                                                                                <i class="bi <?= $req_icon ?> fs-4 text-primary"></i>
                                                                            </div>
                                                                            <div class="flex-grow-1">
                                                                                <div class="fw-semibold text-truncate" title="<?= htmlspecialchars($req) ?>">
                                                                                    <?= htmlspecialchars($req) ?>
                                                                                </div>
                                                                                <div class="text-muted small">
                                                                                    <?= htmlspecialchars($data['filename'] ?? basename($data['file'])) ?>
                                                                                    <?php if (isset($data['size'])): ?>
                                                                                        <span class="ms-2">• <?= $file_size ?></span>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                        <div class="d-flex gap-1">
                                                                            <a href="<?= htmlspecialchars($data['file']) ?>" target="_blank" class="btn btn-outline-primary btn-sm" title="View File">
                                                                                <i class="bi bi-eye"></i>
                                                                            </a>
                                                                            <a href="<?= htmlspecialchars($data['file']) ?>" download class="btn btn-outline-success btn-sm" title="Download">
                                                                                <i class="bi bi-download"></i>
                                                                            </a>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endif; endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-center py-4 bg-light rounded">
                                                        <i class="bi bi-file-earmark-x text-muted mb-2" style="font-size: 2rem;"></i>
                                                        <p class="text-muted small mb-0">No requirement files submitted</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Additional Files Section -->
                                            <div>
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <h6 class="text-info mb-0 fw-bold">
                                                        <i class="bi bi-folder me-2"></i>
                                                        Additional Files
                                                    </h6>
                                                    <span class="badge bg-info"><?= count($uploaded_files) ?> files</span>
                                                </div>

                                                <?php if (!empty($uploaded_files)): ?>
                                                    <div class="row g-2">
                                                        <?php foreach ($uploaded_files as $index => $file):
                                                            $file_path = $file['path'] ?? '';
                                                            $file_name = $file['name'] ?? basename($file_path);
                                                            $file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                                                            $file_icon = in_array($file_ext, ['jpg','jpeg','png','gif','webp']) ? 'bi-file-earmark-image' :
                                                                       (in_array($file_ext, ['pdf']) ? 'bi-file-earmark-pdf' :
                                                                       (in_array($file_ext, ['doc','docx']) ? 'bi-file-earmark-word' :
                                                                       (in_array($file_ext, ['xls','xlsx']) ? 'bi-file-earmark-excel' : 'bi-file-earmark-text')));
                                                            $file_size = isset($file['size']) ? formatFileSize($file['size']) : 'Unknown';
                                                        ?>
                                                            <div class="col-12">
                                                                <div class="file-item border rounded p-3 bg-light">
                                                                    <div class="d-flex align-items-center justify-content-between">
                                                                        <div class="d-flex align-items-center flex-grow-1">
                                                                            <div class="file-icon me-3">
                                                                                <i class="bi <?= $file_icon ?> fs-4 text-info"></i>
                                                                            </div>
                                                                            <div class="flex-grow-1">
                                                                                <div class="fw-semibold text-truncate" title="<?= htmlspecialchars($file_name) ?>">
                                                                                    <?= htmlspecialchars($file_name) ?>
                                                                                </div>
                                                                                <div class="text-muted small">
                                                                                    File <?= $index + 1 ?>
                                                                                    <?php if (isset($file['size'])): ?>
                                                                                        <span class="ms-2">• <?= $file_size ?></span>
                                                                                    <?php endif; ?>
                                                                                    <?php if (isset($file['date'])): ?>
                                                                                        <span class="ms-2">• <?= htmlspecialchars($file['date']) ?></span>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                        <div class="d-flex gap-1">
                                                                            <a href="<?= htmlspecialchars($file_path) ?>" target="_blank" class="btn btn-outline-info btn-sm" title="View File">
                                                                                <i class="bi bi-eye"></i>
                                                                            </a>
                                                                            <a href="<?= htmlspecialchars($file_path) ?>" download class="btn btn-outline-success btn-sm" title="Download">
                                                                                <i class="bi bi-download"></i>
                                                                            </a>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-center py-4 bg-light rounded">
                                                        <i class="bi bi-folder-x text-muted mb-2" style="font-size: 2rem;"></i>
                                                        <p class="text-muted small mb-0">No additional files uploaded</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Card Footer with Stats -->
                                        <div class="card-footer bg-transparent border-0">
                                            <div class="row text-center">
                                                <div class="col-6">
                                                    <div class="border-end">
                                                        <small class="text-muted d-block">Requirements</small>
                                                        <div class="fw-bold text-primary fs-5"><?= $req_count ?></div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Additional</small>
                                                    <div class="fw-bold text-info fs-5"><?= count($uploaded_files) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <script>
                    // File search and filter functionality
                    document.addEventListener('DOMContentLoaded', function() {
                        const searchInput = document.getElementById('fileSearch');
                        const typeFilter = document.getElementById('fileTypeFilter');
                        const statusFilter = document.getElementById('statusFilter');
                        const fileCards = document.querySelectorAll('.file-card');

                        function filterFiles() {
                            const searchTerm = searchInput.value.toLowerCase();
                            const typeValue = typeFilter.value;
                            const statusValue = statusFilter.value;

                            fileCards.forEach(card => {
                                const studentName = card.dataset.studentName;
                                const hasFiles = card.dataset.hasFiles;
                                let showCard = true;

                                // Search filter
                                if (searchTerm && !studentName.includes(searchTerm)) {
                                    showCard = false;
                                }

                                // Status filter
                                if (statusValue === 'with-files' && hasFiles !== 'true') {
                                    showCard = false;
                                } else if (statusValue === 'no-files' && hasFiles !== 'false') {
                                    showCard = false;
                                }

                                // Type filter (would need more complex logic for file types)
                                // For now, just show/hide based on other filters

                                card.style.display = showCard ? '' : 'none';
                            });
                        }

                        searchInput.addEventListener('input', filterFiles);
                        typeFilter.addEventListener('change', filterFiles);
                        statusFilter.addEventListener('change', filterFiles);
                    });
                    </script>

                <?php elseif ($active_tab == 'grades'): ?>
                    <?php
                        $grade_students = array_filter($students_db, function($s) {
                            return isset($s['id']);
                        });
                    ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2 style="font-size: 2.25rem; font-weight: 800; margin-bottom: 0;">Final Grades Management</h2>
                        <div class="text-muted small">Assign final grades to students</div>
                    </div>
                    <?php if (isset($_GET['success'])): ?><div class="alert alert-success">Grade submitted successfully!</div><?php endif; ?>
                    <div class="card p-0 overflow-hidden">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr><th>Student</th><th>Strand</th><th>Grade Level</th><th>Current Grade</th><th>Performance</th><th class="text-end">Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($grade_students)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No students found.</td></tr>
                                <?php else: ?>
                                <?php foreach ($grade_students as $s): ?>
                                <tr>
                                    <td class="fw-bold">
                                        <?= htmlspecialchars($s['fullname']) ?>
                                        <br><small class="text-muted">ID: <?= htmlspecialchars($s['id']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?= htmlspecialchars($s['strand'] ?? 'Not Set') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?= htmlspecialchars($s['grade_level'] ?? 'Not Set') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['grade'])): ?>
                                            <span class="badge bg-success fs-6 px-3 py-2">
                                                <?= htmlspecialchars($s['grade']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['performance_rating'])): ?>
                                            <div class="text-warning">
                                                <?php for($i=0; $i<$s['performance_rating']; $i++) echo '<i class="bi bi-star-fill"></i>'; ?>
                                                <small class="text-dark ms-1">(<?= $s['performance_rating'] ?>/5)</small>
                                            </div>
                                            <?php if (!empty($s['behavior_remarks'])): ?>
                                                <small class="text-muted d-block mt-1"><strong>Remarks:</strong> <?= htmlspecialchars($s['behavior_remarks']) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">No rating</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#gradeModal<?= htmlspecialchars($s['id']) ?>">
                                            <i class="bi bi-pencil-square me-1"></i>Assign Grade
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($active_tab == 'database'): ?>
                    <div class="d-flex justify-content-between align-items-start mb-4">
                        <div>
                            <h2 style="font-size: 2.25rem; font-weight: 800; margin-bottom: 0.25rem;">Database Status</h2>
                            <p class="text-muted small mb-0">Monitor the database connection, table counts, and recent system activity.</p>
                        </div>
                    </div>

                    <div class="row g-4 mb-4">
                        <div class="col-lg-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Connection Status</h5>
                                    <p class="mb-2">Database connection is currently:</p>
                                    <span class="badge <?= $connection ? 'bg-success' : 'bg-danger' ?> py-2 px-3">
                                        <?= $connection ? 'Connected' : 'Disconnected' ?>
                                    </span>
                                    <?php if (!$connection): ?>
                                        <div class="alert alert-danger mt-4 mb-0" role="alert">
                                            <strong>Error:</strong> <?= htmlspecialchars(mysqli_connect_error()) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($connection): ?>
                        <div class="col-lg-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Recent Activity</h5>
                                    <p class="mb-3"><strong>Last user registered:</strong>
                                        <?php
                                        $result = mysqli_query($connection, "SELECT fullname, email FROM isfinals ORDER BY id DESC LIMIT 1");
                                        if ($result && $row = mysqli_fetch_assoc($result)) {
                                            echo htmlspecialchars($row['fullname']) . ' (' . htmlspecialchars($row['email']) . ')';
                                        } else {
                                            echo 'None or Error';
                                        }
                                        ?>
                                    </p>
                                    <p class="mb-0"><strong>Last attendance log:</strong>
                                        <?php
                                        $result = mysqli_query($connection, "SELECT name, date FROM attendance ORDER BY id DESC LIMIT 1");
                                        if ($result && $row = mysqli_fetch_assoc($result)) {
                                            echo htmlspecialchars($row['name']) . ' on ' . htmlspecialchars($row['date']);
                                        } else {
                                            echo 'None or Error';
                                        }
                                        ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">Table Counts</h5>
                                    <div class="row g-3">
                                        <div class="col-sm-6">
                                            <div class="p-3 rounded-4 border bg-white">
                                                <div class="text-muted small">Users (isfinals)</div>
                                                <div class="fs-4 fw-bold">
                                                    <?php
                                                    $result = mysqli_query($connection, "SELECT COUNT(*) as count FROM isfinals");
                                                    echo $result ? (int) mysqli_fetch_assoc($result)['count'] : 'Error';
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="p-3 rounded-4 border bg-white">
                                                <div class="text-muted small">Students</div>
                                                <div class="fs-4 fw-bold">
                                                    <?php
                                                    $result = mysqli_query($connection, "SELECT COUNT(*) as count FROM students");
                                                    echo $result ? (int) mysqli_fetch_assoc($result)['count'] : 'Error';
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="p-3 rounded-4 border bg-white">
                                                <div class="text-muted small">Attendance</div>
                                                <div class="fs-4 fw-bold">
                                                    <?php
                                                    $result = mysqli_query($connection, "SELECT COUNT(*) as count FROM attendance");
                                                    echo $result ? (int) mysqli_fetch_assoc($result)['count'] : 'Error';
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="p-3 rounded-4 border bg-white">
                                                <div class="text-muted small">Narrative Reports</div>
                                                <div class="fs-4 fw-bold">
                                                    <?php
                                                    $result = mysqli_query($connection, "SELECT COUNT(*) as count FROM narrative_reports");
                                                    echo $result ? (int) mysqli_fetch_assoc($result)['count'] : 'Error';
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="p-3 rounded-4 border bg-white">
                                                <div class="text-muted small">Announcements</div>
                                                <div class="fs-4 fw-bold">
                                                    <?php
                                                    $result = mysqli_query($connection, "SELECT COUNT(*) as count FROM announcements");
                                                    echo $result ? (int) mysqli_fetch_assoc($result)['count'] : 'Error';
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($active_tab == 'attendance'): ?>
                    <h2 style="font-size: 2.25rem; font-weight: 800; margin-bottom: 1.5rem;">Attendance Management</h2>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header bg-white fw-bold">Attendance Logs</div>
                                <?php if (isset($_GET['success'])): ?>
                                    <?php if ($_GET['success'] === 'clock_in'): ?>
                                        <div class="alert alert-success alert-dismissible fade show mx-3 mt-3" role="alert">
                                            <i class="bi bi-check-circle-fill me-2"></i>Time In recorded successfully.
                                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                        </div>
                                    <?php elseif ($_GET['success'] === 'clock_out'): ?>
                                        <div class="alert alert-success alert-dismissible fade show mx-3 mt-3" role="alert">
                                            <i class="bi bi-check-circle-fill me-2"></i>Time Out recorded successfully.
                                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (isset($_GET['error'])): ?>
                                    <?php if ($_GET['error'] === 'already_clocked_in'): ?>
                                        <div class="alert alert-warning alert-dismissible fade show mx-3 mt-3" role="alert">
                                            <i class="bi bi-exclamation-triangle-fill me-2"></i>Student has already clocked in for today.
                                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                        </div>
                                    <?php elseif ($_GET['error'] === 'no_clock_in'): ?>
                                        <div class="alert alert-warning alert-dismissible fade show mx-3 mt-3" role="alert">
                                            <i class="bi bi-exclamation-triangle-fill me-2"></i>Please clock in before you can clock out.
                                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                        </div>
                                    <?php elseif ($_GET['error'] === 'invalid_student'): ?>
                                        <div class="alert alert-danger alert-dismissible fade show mx-3 mt-3" role="alert">
                                            <i class="bi bi-exclamation-triangle-fill me-2"></i>Invalid student selection.
                                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead><tr><th>Date</th><th>Student</th><th>Time In</th><th>Time Out</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                                        <tbody>
                                            <?php $hasAttendance = false; ?>
                                            <?php foreach (array_reverse($attendance_db) as $entry): $hasAttendance = true; ?>
                                            <tr>
                                                <td><?= htmlspecialchars($entry['date'] ?? 'N/A') ?></td>
                                                <td class="fw-bold"><?= htmlspecialchars($entry['name'] ?? 'Unknown') ?></td>
                                                <td class="text-success fw-bold"><?= htmlspecialchars($entry['time_in'] && $entry['time_in'] !== '--:--' ? $entry['time_in'] : '--') ?></td>
                                                <td class="text-danger fw-bold"><?= htmlspecialchars($entry['time_out'] && $entry['time_out'] !== '--:--' ? $entry['time_out'] : '--') ?></td>
                                                <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($entry['status'] ?? 'Pending') ?></span></td>
                                                <td class="text-end">
                                                    <?php $attendance_id = htmlspecialchars($entry['id']); ?>
                                                    <a href="admin.php?delete_attendance=<?php echo $attendance_id ?>&tab=attendance" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (!$hasAttendance): ?>
                                                <tr><td colspan="6" class="text-center py-4 text-muted">No attendance logs found.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card p-3 border-primary border-top border-4">
                                <h5 class="fw-bold">Simulate Attendance</h5>
                                <form method="POST">
                                    <div class="mb-3">
                                        <label class="small fw-bold">Student</label>
                                        <select name="student_id" class="form-select" required>
                                            <option value="">Select Student</option>
                                            <?php foreach ($students_db as $s) echo "<option value='" . htmlspecialchars($s['id']) . "'>" . htmlspecialchars($s['fullname']) . "</option>"; ?>
                                        </select>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" name="log_type" value="in" class="btn btn-success flex-fill">Time In</button>
                                        <button type="submit" name="log_type" value="out" class="btn btn-danger flex-fill">Time Out</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php elseif ($active_tab == 'announcements'): ?>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card p-3 mb-4 shadow-sm">
                                <h5 class="fw-bold">Create Post</h5>
                                <form method="POST">
                                    <div class="mb-3"><label class="small">Title</label><input type="text" name="title" class="form-control" required></div>
                                    <div class="mb-3"><label class="small">Message</label><textarea name="content" class="form-control" rows="4" required></textarea></div>
                                    <button type="submit" name="add_announcement" class="btn btn-primary w-100">Post Now</button>
                                </form>
                                <a href="email.php" class="btn btn-outline-secondary w-100 mt-2">Direct Message</a>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <?php foreach (array_reverse($announcements_db) as $a): ?>
                            <div class="card mb-3 p-3 border-start border-primary border-4 shadow-sm">
                                <h5 class="text-primary fw-bold mb-1"><?= htmlspecialchars($a['title']) ?></h5>
                                <p class="mb-2"><?= nl2br(htmlspecialchars($a['content'])) ?></p>
                                <div class="d-flex justify-content-between text-muted small border-top pt-2">
                                    <span><i class="bi bi-calendar3 me-1"></i> <?= htmlspecialchars($a['date']) ?></span>
                                    <?php $announcement_id = htmlspecialchars($a['id']); ?>
                                    <div>
                                        <a href="?delete_ann=<?php echo $announcement_id ?>" class="text-danger fw-bold" onclick="return confirm('Delete announcement?')">Delete</a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($active_tab == 'reports'): ?>
                    <div class="card">
                        <div class="card-header bg-white py-3 border-0">
                            <h5 class="mb-0 fw-bold text-dark">Student Narrative Reports</h5>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Student Name</th>
                                        <th>Report Title</th>
                                        <th>Hours</th>
                                        <th>Date</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($reports_db)): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">No reports found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($reports_db as $report): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold"><?= htmlspecialchars($report['student_name'] ?? 'Unknown') ?></td>
                                            <td><?= htmlspecialchars($report['title'] ?? 'No Title') ?></td>
                                            <td><span class="badge bg-info"><?= htmlspecialchars($report['hours'] ?? 0) ?> hrs</span></td>
                                            <td><?= htmlspecialchars($report['date'] ?? 'N/A') ?></td>
                                            <td class="text-end pe-4">
                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewReportModal<?= htmlspecialchars($report['id']) ?>">View</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php elseif ($active_tab == 'concerns'): ?>
                    <?php if (isset($_GET['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            Concern status updated successfully!
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="mb-2 fw-bold" style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">Student Concerns & Feedback</h2>
                            <p class="text-muted small mb-0">Manage and respond to student concerns and feedback</p>
                        </div>
                        <div class="d-flex gap-2">
                            <div class="badge bg-primary px-3 py-2">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                <?= count($concerns_db) ?> Total Concerns
                            </div>
                        </div>
                    </div>

                    <!-- Status Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="card h-100 border-start border-warning border-4">
                                <div class="card-body text-center">
                                    <div class="display-6 text-warning mb-2">
                                        <i class="bi bi-clock-history"></i>
                                    </div>
                                    <h4 class="fw-bold text-warning">
                                        <?php
                                        $pending_count = count(array_filter($concerns_db, function($c) { return strtolower($c['status'] ?? '') === 'pending'; }));
                                        echo $pending_count;
                                        ?>
                                    </h4>
                                    <p class="text-muted small mb-0">Pending</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card h-100 border-start border-info border-4">
                                <div class="card-body text-center">
                                    <div class="display-6 text-info mb-2">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <h4 class="fw-bold text-info">
                                        <?php
                                        $reviewed_count = count(array_filter($concerns_db, function($c) { return strtolower($c['status'] ?? '') === 'reviewed'; }));
                                        echo $reviewed_count;
                                        ?>
                                    </h4>
                                    <p class="text-muted small mb-0">Reviewed</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card h-100 border-start border-success border-4">
                                <div class="card-body text-center">
                                    <div class="display-6 text-success mb-2">
                                        <i class="bi bi-check-circle"></i>
                                    </div>
                                    <h4 class="fw-bold text-success">
                                        <?php
                                        $resolved_count = count(array_filter($concerns_db, function($c) { return strtolower($c['status'] ?? '') === 'resolved'; }));
                                        echo $resolved_count;
                                        ?>
                                    </h4>
                                    <p class="text-muted small mb-0">Resolved</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card h-100 border-start border-primary border-4">
                                <div class="card-body text-center">
                                    <div class="display-6 text-primary mb-2">
                                        <i class="bi bi-chat-dots"></i>
                                    </div>
                                    <h4 class="fw-bold text-primary">
                                        <?php
                                        $responded_count = count(array_filter($concerns_db, function($c) { return !empty($c['teacher_response']); }));
                                        echo $responded_count;
                                        ?>
                                    </h4>
                                    <p class="text-muted small mb-0">Responded</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Concerns Table -->
                    <div class="card shadow-sm">
                        <div class="card-header bg-white py-3 border-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fw-bold text-dark">
                                    <i class="bi bi-list-ul me-2 text-primary"></i>All Concerns
                                </h5>
                                <div class="d-flex gap-2">
                                    <select class="form-select form-select-sm" id="statusFilter" style="width: auto;">
                                        <option value="">All Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="reviewed">Reviewed</option>
                                        <option value="resolved">Resolved</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="concernsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4 border-0">
                                            <i class="bi bi-person me-1 text-muted"></i>Student
                                        </th>
                                        <th class="border-0">
                                            <i class="bi bi-tag me-1 text-muted"></i>Type
                                        </th>
                                        <th class="border-0">
                                            <i class="bi bi-chat-text me-1 text-muted"></i>Message
                                        </th>
                                        <th class="border-0">
                                            <i class="bi bi-flag me-1 text-muted"></i>Status
                                        </th>
                                        <th class="border-0">
                                            <i class="bi bi-reply me-1 text-muted"></i>Response
                                        </th>
                                        <th class="border-0">
                                            <i class="bi bi-calendar me-1 text-muted"></i>Date
                                        </th>
                                        <th class="text-end pe-4 border-0">
                                            <i class="bi bi-gear me-1 text-muted"></i>Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($concerns_db)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5">
                                                <div class="empty-state">
                                                    <i class="bi bi-inbox display-4 text-muted mb-3"></i>
                                                    <h6 class="text-muted">No concerns found</h6>
                                                    <p class="text-muted small mb-0">Student concerns and feedback will appear here.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($concerns_db as $concern): ?>
                                        <tr class="concern-row" data-status="<?= strtolower($concern['status'] ?? 'pending') ?>">
                                            <td class="ps-4">
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-circle me-3">
                                                        <span class="fw-bold text-primary">
                                                            <?= substr(htmlspecialchars($concern['student_name'] ?? 'Unknown'), 0, 1) ?>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold text-dark mb-0">
                                                            <?= htmlspecialchars($concern['student_name'] ?? 'Unknown') ?>
                                                        </div>
                                                        <small class="text-muted">Student</small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    <i class="bi bi-tag-fill me-1"></i>
                                                    <?= htmlspecialchars($concern['concern_type'] ?? 'General') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="message-preview">
                                                    <span class="text-truncate d-inline-block" style="max-width: 250px;" title="<?= htmlspecialchars($concern['message']) ?>">
                                                        <?= htmlspecialchars(substr($concern['message'] ?? '', 0, 50)) ?>
                                                        <?= strlen($concern['message'] ?? '') > 50 ? '...' : '' ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $status = $concern['status'] ?? 'Pending';
                                                $badge_class = getConcernBadgeClass($status);
                                                $status_icon = match(strtolower($status)) {
                                                    'pending' => 'bi-clock',
                                                    'reviewed' => 'bi-eye',
                                                    'resolved' => 'bi-check-circle',
                                                    default => 'bi-question-circle'
                                                };
                                                ?>
                                                <span class="badge <?= $badge_class ?> px-3 py-2">
                                                    <i class="bi <?= $status_icon ?> me-1"></i>
                                                    <?= htmlspecialchars($status) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($concern['teacher_response'])): ?>
                                                    <div class="text-center">
                                                        <i class="bi bi-check-circle-fill text-success fs-5" title="Response provided"></i>
                                                        <small class="d-block text-muted">Responded</small>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-center">
                                                        <i class="bi bi-dash-circle text-muted fs-5" title="No response yet"></i>
                                                        <small class="d-block text-muted">Pending</small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="text-muted small">
                                                    <i class="bi bi-calendar-event me-1"></i>
                                                    <?= htmlspecialchars(date('M d, Y', strtotime($concern['date_submitted'] ?? 'now'))) ?>
                                                </div>
                                                <div class="text-muted small">
                                                    <i class="bi bi-clock me-1"></i>
                                                    <?= htmlspecialchars(date('H:i', strtotime($concern['date_submitted'] ?? 'now'))) ?>
                                                </div>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div class="btn-group" role="group">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewConcernModal<?= htmlspecialchars($concern['id']) ?>" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editConcernModal<?= htmlspecialchars($concern['id']) ?>" title="Edit Concern">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#updateConcernModal<?= htmlspecialchars($concern['id']) ?>" title="Update Status">
                                                        <i class="bi bi-check-circle"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <div class="modal fade" id="addStudentModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content"><form method="POST">
            <div class="modal-header fw-bold">Add User</div>
            <div class="modal-body">
                <div class="mb-3"><label>Role</label><select id="userRoleSelect" name="role" class="form-select">
                    <option value="student" selected>Student</option>
                    <option value="owner_supervisor">Owner / Supervisor</option>
                </select></div>
                <div class="mb-3"><label>Full Name</label><input type="text" name="name" class="form-control" required></div>
                <div class="mb-3"><label>Email</label><input type="email" name="email" class="form-control" required></div>
                <div class="mb-3"><label>Username</label><input type="text" name="username" class="form-control" required></div>
                <div class="mb-3"><label>Password</label><input type="password" name="password" class="form-control" required></div>
                <div class="mb-3"><label>Contact Number</label><input type="tel" name="contact_number" placeholder="09XXXXXXXXX" class="form-control" required></div>
                <div class="mb-3 student-only"><label>Company Assigned</label><input type="text" name="company_assigned" class="form-control" placeholder="Company Assigned"></div>
                <div class="mb-3 student-only"><label>Assigned Supervisor</label><select name="supervisor_id" class="form-select">
                    <option value="">None</option>
                    <?php foreach ($supervisors as $sup): ?>
                        <option value="<?= htmlspecialchars($sup['supervisor_record_id'] ?? $sup['id']) ?>"><?= htmlspecialchars($sup['supervisor_fullname'] ?? $sup['fullname']) ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div class="mb-3 student-only"><label>Required Hours</label><input type="number" name="required_hours" class="form-control" value="80" min="0"></div>
                <div class="mb-3 student-only"><label>Total Hours Rendered</label><input type="number" name="total_hours_rendered" class="form-control" value="0" min="0"></div>
                <div class="mb-3 student-only"><label>Late Count</label><input type="number" name="late_count" class="form-control" value="0" min="0"></div>
                <div class="mb-3 student-only"><label>Absents Count</label><input type="number" name="absent_count" class="form-control" value="0" min="0"></div>
                <div class="mb-3 student-only"><label>Strand</label><select name="strand" class="form-select"><option>ICT</option><option>STEM</option><option>ABM</option><option>HUMSS</option></select></div>
            </div>
            <div class="modal-footer"><button type="submit" name="add_student" class="btn btn-primary">Save</button></div>
        </form></div></div>
    </div>

    <?php foreach ($students_db as $s): ?>
    <div class="modal fade" id="reqModal<?= htmlspecialchars($s['id']) ?>" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
            <div class="modal-header fw-bold">Requirements: <?= htmlspecialchars($s['fullname']) ?></div>
            <div class="modal-body">
                <?php foreach ((array)$s['requirements'] as $type => $val): ?>
                    <form method="POST" class="mb-2">
                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($s['id']) ?>"><input type="hidden" name="req_type" value="<?= htmlspecialchars($type) ?>">
                        <label class="small fw-bold"><?= htmlspecialchars($type) ?></label>
                        <select name="req_val" onchange="this.form.submit()" class="form-select form-select-sm">
                            <option value="Pending" <?= $val=='Pending'?'selected':'' ?>>Pending</option>
                            <option value="Complete" <?= $val=='Complete'?'selected':'' ?>>Complete</option>
                        </select>
                        <input type="hidden" name="update_action" value="1">
                    </form>
                <?php endforeach; ?>
                <hr class="my-3">
                <h6 class="fw-bold">Uploaded Files</h6>
                <?php $student_files = is_array($s['uploaded_files']) ? $s['uploaded_files'] : (json_decode($s['uploaded_files'], true) ?: []); ?>
                <?php if (!empty($student_files)): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($student_files as $file): ?>
                            <li class="list-group-item px-0 py-2"><a href="<?= htmlspecialchars($file['path'] ?? '') ?>" target="_blank"><?= htmlspecialchars($file['name'] ?? 'View File') ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="small text-muted mb-0">No additional files uploaded by this student.</p>
                <?php endif; ?>
            </div>
        </div></div>
    </div>

    <div class="modal fade" id="editHoursModal<?= htmlspecialchars($s['id']) ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Edit Required Hours</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($s['id']) ?>">
                        <div class="mb-3">
                            <label class="form-label">Student</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($s['fullname']) ?>" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Required Hours</label>
                            <input type="number" name="required_hours" class="form-control" value="<?= htmlspecialchars($s['required_hours'] ?? 0) ?>" min="0" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_required_hours" class="btn btn-primary">Save Hours</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editCompanyModal<?= htmlspecialchars($s['id']) ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Edit Company Assigned</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($s['id']) ?>">
                        <div class="mb-3">
                            <label class="form-label">Student</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($s['fullname']) ?>" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Company Assigned</label>
                            <input type="text" name="company_assigned" class="form-control" value="<?= htmlspecialchars($s['company_assigned'] ?? '') ?>" placeholder="Enter company name">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_company_assigned" class="btn btn-primary">Save Company</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editSupervisorModal<?= htmlspecialchars($s['id']) ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Edit Assigned Supervisor</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($s['id']) ?>">
                        <div class="mb-3">
                            <label class="form-label">Student</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($s['fullname']) ?>" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assigned Supervisor</label>
                            <select name="supervisor_id" class="form-select">
                                <option value="">None</option>
                                <?php foreach ($supervisors as $sup): ?>
                                    <option value="<?= htmlspecialchars($sup['supervisor_record_id'] ?? $sup['id']) ?>" <?= ($s['supervisor_id'] == ($sup['supervisor_record_id'] ?? $sup['id'])) ? 'selected' : '' ?>><?= htmlspecialchars($sup['supervisor_fullname'] ?? $sup['fullname']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_supervisor_assigned" class="btn btn-primary">Save Supervisor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php endforeach; ?>

    <?php foreach ($students_db as $s): ?>
    <div class="modal fade" id="gradeModal<?= htmlspecialchars($s['id']) ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Assign Final Grade</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($s['id']) ?>">
                        <div class="text-center mb-4">
                            <h6 class="fw-bold text-primary"><?= htmlspecialchars($s['fullname']) ?></h6>
                            <small class="text-muted">ID: <?= htmlspecialchars($s['id']) ?> | Strand: <?= htmlspecialchars($s['strand'] ?? 'Not Set') ?> | Grade Level: <?= htmlspecialchars($s['grade_level'] ?? 'Not Set') ?></small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Final Grade</label>
                            <select name="grade" class="form-select form-select-lg" required>
                                <option value="">Select Final Grade</option>
                                <option value="98-100" <?= ($s['grade'] ?? '') == '98-100' ? 'selected' : '' ?>>98-100 - Excellent</option>
                                <option value="95-97" <?= ($s['grade'] ?? '') == '95-97' ? 'selected' : '' ?>>95-97 - Very Good</option>
                                <option value="90-94" <?= ($s['grade'] ?? '') == '90-94' ? 'selected' : '' ?>>90-94 - Good</option>
                                <option value="87-89" <?= ($s['grade'] ?? '') == '87-89' ? 'selected' : '' ?>>87-89 - Satisfactory</option>
                                <option value="83-86" <?= ($s['grade'] ?? '') == '83-86' ? 'selected' : '' ?>>83-86 - Fair</option>
                                <option value="79-82" <?= ($s['grade'] ?? '') == '79-82' ? 'selected' : '' ?>>79-82 - Passed</option>
                                <option value="76-78" <?= ($s['grade'] ?? '') == '76-78' ? 'selected' : '' ?>>76-78 - Conditional</option>
                                <option value="75" <?= ($s['grade'] ?? '') == '75' ? 'selected' : '' ?>>75 - Needs Improvement</option>
                                <option value="INC" <?= ($s['grade'] ?? '') == 'INC' ? 'selected' : '' ?>>INC - Incomplete</option>
                                <option value="Failed" <?= ($s['grade'] ?? '') == 'Failed' ? 'selected' : '' ?>>Failed</option>
                            </select>
                        </div>
                        <?php if (!empty($s['performance_rating'])): ?>
                        <div class="alert alert-info">
                            <small><strong>Supervisor Rating:</strong>
                            <?php for($i=0; $i<$s['performance_rating']; $i++) echo '<i class="bi bi-star-fill text-warning"></i>'; ?>
                            (<?= $s['performance_rating'] ?>/5)</small>
                            <?php if (!empty($s['behavior_remarks'])): ?>
                                <br><small><strong>Behavior Remarks:</strong> <?= htmlspecialchars($s['behavior_remarks']) ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_grade" class="btn btn-success">
                            <i class="bi bi-check-circle me-1"></i>Submit Grade
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php foreach ($reports_db as $report): ?>
    <div class="modal fade" id="viewReportModal<?= htmlspecialchars($report['id']) ?>" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><?= htmlspecialchars($report['title'] ?? 'Report') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="small text-muted fw-bold">Student Name</label>
                        <p class="mb-0"><?= htmlspecialchars($report['student_name'] ?? 'Unknown') ?></p>
                    </div>
                    <div class="mb-3">
                        <label class="small text-muted fw-bold">Date</label>
                        <p class="mb-0"><?= htmlspecialchars($report['date'] ?? 'N/A') ?></p>
                    </div>
                    <div class="mb-3">
                        <label class="small text-muted fw-bold">Hours</label>
                        <p class="mb-0"><span class="badge bg-info"><?= htmlspecialchars($report['hours'] ?? 0) ?> hours</span></p>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="small text-muted fw-bold">Report Content</label>
                        <p><?= nl2br(htmlspecialchars($report['content'] ?? 'No content available')) ?></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php foreach ($concerns_db ?? [] as $concern): ?>
    <div class="modal fade" id="viewConcernModal<?= htmlspecialchars($concern['id']) ?>" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-gradient-primary text-white border-0">
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-eye me-2"></i>Concern Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Student Info Card -->
                    <div class="card border-0 bg-light mb-4">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center mb-2">
                                <div class="avatar-circle-lg me-3">
                                    <span class="fw-bold text-primary fs-4">
                                        <?= substr(htmlspecialchars($concern['student_name'] ?? 'Unknown'), 0, 1) ?>
                                    </span>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark">
                                        <?= htmlspecialchars($concern['student_name'] ?? 'Unknown') ?>
                                    </h6>
                                    <small class="text-muted">Student</small>
                                </div>
                            </div>
                            <div class="row g-3 mt-2">
                                <div class="col-md-6">
                                    <small class="text-muted d-block">Concern Type</small>
                                    <span class="badge bg-primary px-3 py-2">
                                        <i class="bi bi-tag-fill me-1"></i>
                                        <?= htmlspecialchars($concern['concern_type'] ?? 'General') ?>
                                    </span>
                                </div>
                                <div class="col-md-6">
                                    <small class="text-muted d-block">Status</small>
                                    <?php
                                    $status = $concern['status'] ?? 'Pending';
                                    $badge_class = getConcernBadgeClass($status);
                                    $status_icon = match(strtolower($status)) {
                                        'pending' => 'bi-clock',
                                        'reviewed' => 'bi-eye',
                                        'resolved' => 'bi-check-circle',
                                        default => 'bi-question-circle'
                                    };
                                    ?>
                                    <span class="badge <?= $badge_class ?> px-3 py-2">
                                        <i class="bi <?= $status_icon ?> me-1"></i>
                                        <?= htmlspecialchars($status) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Concern Message -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-dark mb-3">
                            <i class="bi bi-chat-quote text-primary me-2"></i>Student's Concern
                        </h6>
                        <div class="card border-start border-primary border-4 bg-light">
                            <div class="card-body p-3">
                                <p class="mb-0 text-dark lh-base">
                                    <?= nl2br(htmlspecialchars($concern['message'] ?? 'No message available')) ?>
                                </p>
                            </div>
                        </div>
                        <small class="text-muted mt-2 d-block">
                            <i class="bi bi-calendar-event me-1"></i>
                            Submitted on <?= htmlspecialchars(date('F d, Y \a\t H:i', strtotime($concern['date_submitted'] ?? 'now'))) ?>
                        </small>
                    </div>

                    <!-- Teacher/Admin Response -->
                    <?php if (!empty($concern['teacher_response'])): ?>
                    <div class="mb-0">
                        <h6 class="fw-bold text-dark mb-3">
                            <i class="bi bi-reply text-success me-2"></i>Teacher/Admin Response
                        </h6>
                        <div class="card border-start border-success border-4 bg-success bg-opacity-10">
                            <div class="card-body p-3">
                                <p class="mb-0 text-dark lh-base">
                                    <?= nl2br(htmlspecialchars($concern['teacher_response'])) ?>
                                </p>
                            </div>
                        </div>
                        <?php if (!empty($concern['response_date'])): ?>
                        <small class="text-muted mt-2 d-block">
                            <i class="bi bi-calendar-check me-1"></i>
                            Responded on <?= htmlspecialchars(date('F d, Y \a\t H:i', strtotime($concern['response_date']))) ?>
                        </small>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning border-0 bg-warning bg-opacity-10">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>No response yet.</strong> This concern is waiting for teacher/admin feedback.
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Close
                    </button>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateConcernModal<?= htmlspecialchars($concern['id']) ?>" data-bs-dismiss="modal">
                        <i class="bi bi-pencil-square me-1"></i>Update Status
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="updateConcernModal<?= htmlspecialchars($concern['id']) ?>" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-gradient-success text-white border-0">
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-check-circle me-2"></i>Update Concern Status
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body p-4">
                        <input type="hidden" name="concern_id" value="<?= htmlspecialchars($concern['id']) ?>">

                        <!-- Concern Summary -->
                        <div class="card border-0 bg-light mb-4">
                            <div class="card-body p-3">
                                <h6 class="fw-bold text-dark mb-3">
                                    <i class="bi bi-info-circle text-primary me-2"></i>Concern Summary
                                </h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Student</small>
                                        <span class="fw-bold text-dark">
                                            <?= htmlspecialchars($concern['student_name'] ?? 'Unknown') ?>
                                        </span>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Type</small>
                                        <span class="badge bg-secondary">
                                            <?= htmlspecialchars($concern['concern_type'] ?? 'General') ?>
                                        </span>
                                    </div>
                                    <div class="col-12">
                                        <small class="text-muted d-block">Current Status</small>
                                        <?php
                                        $status = $concern['status'] ?? 'Pending';
                                        $badge_class = getConcernBadgeClass($status);
                                        $status_icon = match(strtolower($status)) {
                                            'pending' => 'bi-clock',
                                            'reviewed' => 'bi-eye',
                                            'resolved' => 'bi-check-circle',
                                            default => 'bi-question-circle'
                                        };
                                        ?>
                                        <span class="badge <?= $badge_class ?> px-3 py-2">
                                            <i class="bi <?= $status_icon ?> me-1"></i>
                                            <?= htmlspecialchars($status) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Status Update -->
                        <div class="mb-4">
                            <h6 class="fw-bold text-dark mb-3">
                                <i class="bi bi-flag text-warning me-2"></i>Update Status
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">New Status</label>
                                    <select name="new_status" class="form-select form-select-lg" required>
                                        <option value="Pending" <?= strtolower($status) == 'pending' ? 'selected' : '' ?>>
                                            <i class="bi bi-clock me-1"></i>Pending
                                        </option>
                                        <option value="Reviewed" <?= strtolower($status) == 'reviewed' ? 'selected' : '' ?>>
                                            <i class="bi bi-eye me-1"></i>Reviewed
                                        </option>
                                        <option value="Resolved" <?= strtolower($status) == 'resolved' ? 'selected' : '' ?>>
                                            <i class="bi bi-check-circle me-1"></i>Resolved
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Priority Level</label>
                                    <select name="priority" class="form-select form-select-lg">
                                        <option value="normal" selected>Normal</option>
                                        <option value="high">High Priority</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Response Section -->
                        <div class="mb-0">
                            <h6 class="fw-bold text-dark mb-3">
                                <i class="bi bi-reply text-success me-2"></i>Teacher/Admin Response
                            </h6>
                            <div class="card border-start border-success border-4">
                                <div class="card-body p-0">
                                    <textarea name="teacher_response" class="form-control border-0 bg-transparent" rows="5" placeholder="Provide your response to the student's concern. Be helpful, supportive, and clear in your communication..."><?= htmlspecialchars($concern['teacher_response'] ?? '') ?></textarea>
                                </div>
                            </div>
                            <small class="text-muted mt-2 d-block">
                                <i class="bi bi-info-circle me-1"></i>
                                Your response will be visible to the student. Take time to address their concern thoughtfully.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </button>
                        <button type="submit" name="update_concern_status" class="btn btn-success px-4">
                            <i class="bi bi-check-circle-fill me-1"></i>Update Status & Save Response
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="editConcernModal<?= htmlspecialchars($concern['id']) ?>" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-gradient-primary text-white border-0">
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-pencil-square me-2"></i>Edit Concern & Response
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body p-4">
                        <input type="hidden" name="concern_id" value="<?= htmlspecialchars($concern['id']) ?>">

                        <!-- Student Info -->
                        <div class="card border-0 bg-light mb-4">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle-lg me-3">
                                        <span class="fw-bold text-primary fs-4">
                                            <?= substr(htmlspecialchars($concern['student_name'] ?? 'Unknown'), 0, 1) ?>
                                        </span>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1 fw-bold text-dark">
                                            <?= htmlspecialchars($concern['student_name'] ?? 'Unknown') ?>
                                        </h6>
                                        <div class="d-flex gap-3">
                                            <small class="text-muted">
                                                <i class="bi bi-tag me-1"></i>
                                                <?= htmlspecialchars($concern['concern_type'] ?? 'General') ?>
                                            </small>
                                            <small class="text-muted">
                                                <i class="bi bi-calendar me-1"></i>
                                                <?= htmlspecialchars(date('M d, Y H:i', strtotime($concern['date_submitted'] ?? 'now'))) ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Edit Sections -->
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <h6 class="fw-bold text-dark mb-3">
                                    <i class="bi bi-chat-quote text-primary me-2"></i>Student's Concern
                                </h6>
                                <div class="card border-start border-primary border-4">
                                    <div class="card-body p-3">
                                        <textarea name="message" class="form-control border-0 bg-transparent" rows="6" required placeholder="Edit the student's concern message..."><?= htmlspecialchars($concern['message'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <h6 class="fw-bold text-dark mb-3">
                                    <i class="bi bi-reply text-success me-2"></i>Teacher/Admin Response
                                </h6>
                                <div class="card border-start border-success border-4">
                                    <div class="card-body p-3">
                                        <textarea name="teacher_response" class="form-control border-0 bg-transparent" rows="6" placeholder="Edit or add your response to the student's concern..."><?= htmlspecialchars($concern['teacher_response'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <small class="text-muted mt-2 d-block">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Leave response empty if you haven't responded yet.
                                </small>
                            </div>
                        </div>

                        <!-- Edit Notes -->
                        <div class="alert alert-info border-0 bg-info bg-opacity-10 mt-4">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Editing Guidelines:</strong> You can modify both the student's concern message and your response. Changes will be saved and visible to the student.
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </button>
                        <button type="submit" name="edit_concern" class="btn btn-primary px-4">
                            <i class="bi bi-check-circle-fill me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var roleSelect = document.getElementById('userRoleSelect');
    var studentFields = document.querySelectorAll('.student-only');
    if (!roleSelect) return;
    function updateRoleFields() {
        var isStudent = roleSelect.value === 'student';
        studentFields.forEach(function(field) {
            field.style.display = isStudent ? '' : 'none';
            field.querySelectorAll('input, select').forEach(function(control) {
                control.disabled = !isStudent;
            });
        });
    }
    roleSelect.addEventListener('change', updateRoleFields);
    updateRoleFields();

    // User search functionality
    const searchInput = document.getElementById('userSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase();
            const studentTable = document.querySelector('#studentUsersTable tbody');
            const supervisorTable = document.querySelector('#supervisorUsersTable tbody');

            if (studentTable) {
                filterTable(studentTable, query);
            }
            if (supervisorTable) {
                filterTable(supervisorTable, query);
            }
        });
    }

    function filterTable(tbody, query) {
        const rows = tbody.querySelectorAll('tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    }

    window.clearSearch = function() {
        document.getElementById('userSearch').value = '';
        document.getElementById('userSearch').dispatchEvent(new Event('input'));
    };

    function hideDropdown(dropdown) {
        if (!dropdown) return;
        const toggle = dropdown.querySelector('[data-bs-toggle="dropdown"]');
        const menu = dropdown.querySelector('.dropdown-menu');
        if (toggle) {
            toggle.classList.remove('show');
            toggle.setAttribute('aria-expanded', 'false');
        }
        if (menu) {
            menu.classList.remove('show');
        }
        dropdown.classList.remove('show');
        if (toggle && typeof bootstrap !== 'undefined') {
            const instance = bootstrap.Dropdown.getOrCreateInstance(toggle);
            if (instance) {
                instance.hide();
            }
        }
    }

    function hideAllDropdowns(exceptToggle) {
        document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
            const dropdown = menu.closest('.dropdown');
            if (!dropdown) return;
            if (exceptToggle && dropdown.contains(exceptToggle)) return;
            hideDropdown(dropdown);
        });
    }

    // Close any previously open dropdown before opening a new one
    document.querySelectorAll('.dropdown-toggle').forEach(function(toggle) {
        toggle.addEventListener('click', function(event) {
            hideAllDropdowns(toggle);
        });
    });

    // Close dropdown menus when any action item is clicked
    document.querySelectorAll('.dropdown-menu .dropdown-item').forEach(function(item) {
        item.addEventListener('click', function(event) {
            event.stopPropagation();
            const dropdown = this.closest('.dropdown');
            if (!dropdown) return;
            hideDropdown(dropdown);
        });
    });

    // Mobile menu toggle
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
});
</script>
</body>
</html>

//Nicole Sambile
//John Paul Santos
//Jessica Salalila