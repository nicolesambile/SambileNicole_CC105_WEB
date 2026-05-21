<?php
// --- student_dashboard.php ---
// Displays the student dashboard and loads attendance and requirement details.
session_start();
date_default_timezone_set('Asia/Manila');
require('./connection.php');

// 1. SESSION PROTECTION
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['user', 'student'], true)) {
    header("Location: login.php");
    exit();
}

// 2. LOAD DATA FROM DATABASE
$attendance_result = mysqli_query($connection, "SELECT * FROM attendance ORDER BY date DESC");
$attendance = mysqli_fetch_all($attendance_result, MYSQLI_ASSOC);

$students_result = mysqli_query($connection, "SELECT s.* FROM students s");

if (!$students_result) {
    die('Database query failed: ' . mysqli_error($connection));
}

$students = [];
while ($row = mysqli_fetch_assoc($students_result)) {
    $reqs = json_decode($row['requirements'], true) ?: [];
    // Convert old format to new if necessary
    foreach ($reqs as $key => $val) {
        if (is_string($val)) {
            $reqs[$key] = ['status' => $val];
        }
    }
    $row['requirements'] = $reqs;
    $row['uploaded_files'] = json_decode($row['uploaded_files'], true) ?: [];
    $students[] = $row;
}

$reports_result = mysqli_query($connection, "SELECT * FROM narrative_reports ORDER BY date DESC");
$reports = mysqli_fetch_all($reports_result, MYSQLI_ASSOC);

$announcements_result = mysqli_query($connection, "SELECT * FROM announcements ORDER BY date DESC");
$announcements = mysqli_fetch_all($announcements_result, MYSQLI_ASSOC);

// FIND CURRENT STUDENT INFO
$current_student = null;
foreach ($students as $s) {
    if (isset($_SESSION['user_id']) && $s['user_id'] == $_SESSION['user_id']) {
        $current_student = $s;
        break;
    }
}

if ($current_student && isset($_SESSION['user_id'])) {
    // Always refresh contact_number directly from students table to ensure latest data
    $student_id = $current_student['id'];
    $refresh_query = mysqli_query($connection, "SELECT id, user_id, contact_number FROM students WHERE id='$student_id' LIMIT 1");
    
    if ($refresh_query) {
        if (mysqli_num_rows($refresh_query) > 0) {
            $refresh_row = mysqli_fetch_assoc($refresh_query);
            if (isset($refresh_row['contact_number'])) {
                $current_student['contact_number'] = trim($refresh_row['contact_number'] ?? '');
            }
        }
    } else {
        error_log('Contact refresh query failed: ' . mysqli_error($connection));
    }
}

// Check if student record exists
if (!$current_student) {
    // Student record not found - redirect to login or show error
    header("Location: login.php?error=student_not_found");
    exit();
}

// HELPER: Get student record ID from user ID
function getStudentId($connection, $user_id) {
    $query = mysqli_query($connection, "SELECT id FROM students WHERE user_id='" . mysqli_real_escape_string($connection, $user_id) . "' LIMIT 1");
    if (!$query || mysqli_num_rows($query) === 0) {
        return null;
    }
    $row = mysqli_fetch_assoc($query);
    return $row['id'];
}

// 5. LOGIC ACTIONS
if (isset($_GET['delete'])) {
    $report_id = (int)$_GET['delete'];
    $student_id = getStudentId($connection, $_SESSION['user_id']);
    if ($student_id) {
        $query = "DELETE FROM narrative_reports WHERE id=$report_id AND student_id='" . mysqli_real_escape_string($connection, $student_id) . "'";
        mysqli_query($connection, $query);
    }
    header("Location: student_dashboard.php?tab=reports");
    exit();
}

$edit_report = null;
if (isset($_GET['edit'])) {
    $report_id = (int)$_GET['edit'];
    $student_id = getStudentId($connection, $_SESSION['user_id']);
    if ($student_id) {
        $query = "SELECT * FROM narrative_reports WHERE id=$report_id AND student_id='" . mysqli_real_escape_string($connection, $student_id) . "'";
        $result = mysqli_query($connection, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $edit_report = mysqli_fetch_assoc($result);
        }
    }
}

if (isset($_GET['delete_file'])) {
    $file_index = (int)$_GET['delete_file'];
    $student_id = getStudentId($connection, $_SESSION['user_id']);
    if ($student_id) {
        $result = mysqli_query($connection, "SELECT uploaded_files FROM students WHERE id='" . mysqli_real_escape_string($connection, $student_id) . "'");
        if ($result) {
            $row = mysqli_fetch_assoc($result);
            $files = json_decode($row['uploaded_files'], true) ?: [];
            if (isset($files[$file_index])) {
                $file_path = $files[$file_index]['path'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                unset($files[$file_index]);
                $files = array_values($files); // reindex
                $files_json = mysqli_real_escape_string($connection, json_encode($files));
                mysqli_query($connection, "UPDATE students SET uploaded_files='$files_json' WHERE id='" . mysqli_real_escape_string($connection, $student_id) . "'");
            }
        }
    }
    header("Location: student_dashboard.php?tab=requirements");
    exit();
}

if (isset($_POST['log_type'])) {
    $type = $_POST['log_type'] === 'out' ? 'out' : 'in';

    // Get the actual student record ID from students table
    $student_query = mysqli_query($connection, "SELECT id FROM students WHERE user_id='" . mysqli_real_escape_string($connection, $_SESSION['user_id']) . "' LIMIT 1");
    if (!$student_query || mysqli_num_rows($student_query) === 0) {
        echo '<script>alert("Student record not found!"); window.location = "student_dashboard.php";</script>';
        exit();
    }
    $student_row = mysqli_fetch_assoc($student_query);
    $student_id = mysqli_real_escape_string($connection, $student_row['id']);
    $name = mysqli_real_escape_string($connection, $_SESSION['fullname'] ?? 'Student');
    $date = date('Y-m-d');
    $time = date('H:i:s');

    $open_session = mysqli_query($connection, "SELECT id FROM attendance WHERE student_id='$student_id' AND date='$date' AND (time_out='--:--' OR time_out='' OR time_out IS NULL) ORDER BY id DESC LIMIT 1");
    $has_open_session = $open_session && mysqli_num_rows($open_session) > 0;

    $completed_session = mysqli_query($connection, "SELECT id FROM attendance WHERE student_id='$student_id' AND date='$date' AND time_out NOT IN ('--:--', '') AND time_out IS NOT NULL ORDER BY id DESC LIMIT 1");
    $has_completed_session = $completed_session && mysqli_num_rows($completed_session) > 0;

    if ($type === 'in') {
        if ($has_open_session) {
            header("Location: student_dashboard.php?tab=attendance&error=already_clocked_in");
            exit();
        }

        if ($has_completed_session) {
            header("Location: student_dashboard.php?tab=attendance&error=already_completed_today");
            exit();
        }

        $query = "INSERT INTO attendance (student_id, name, date, time_in, time_out, status) VALUES ('$student_id', '$name', '$date', '$time', NULL, 'Pending')";
        mysqli_query($connection, $query);
        header("Location: student_dashboard.php?tab=attendance&success=clock_in");
        exit();
    }

    if ($has_open_session) {
        $row = mysqli_fetch_assoc($open_session);
        mysqli_query($connection, "UPDATE attendance SET time_out='$time' WHERE id='" . mysqli_real_escape_string($connection, $row['id']) . "'");
        header("Location: student_dashboard.php?tab=attendance&success=clock_out");
        exit();
    }

    if ($has_completed_session) {
        header("Location: student_dashboard.php?tab=attendance&error=already_clocked_out");
        exit();
    }

    header("Location: student_dashboard.php?tab=attendance&error=no_clock_in");
    exit();
}

if (isset($_POST['submit_report'])) {
    $student_id = getStudentId($connection, $_SESSION['user_id']);
    if (!$student_id) {
        echo '<script>alert("Student record not found!"); window.location = "student_dashboard.php";</script>';
        exit();
    }
    $student_id = mysqli_real_escape_string($connection, $student_id);
    $title = mysqli_real_escape_string($connection, $_POST['report_title']);
    $content = mysqli_real_escape_string($connection, $_POST['narrative']);
    $hours = (int)$_POST['hours'];
    $edit_id = (int)$_POST['edit_id'];
    $report_date = date('Y-m-d');
    $display_date = date('M d, Y');

    if ($edit_id > 0) {
        $query = "UPDATE narrative_reports SET title='$title', content='$content', hours=$hours WHERE id=$edit_id AND student_id='$student_id'";
    } else {
        $query = "INSERT INTO narrative_reports (student_id, title, content, hours, date) VALUES ('$student_id', '$title', '$content', $hours, '$report_date')";
    }
    mysqli_query($connection, $query);
    header("Location: student_dashboard.php?tab=reports&success=1&title=" . urlencode($title) . "&date=" . urlencode($display_date) . "&hours=" . $hours);
    exit();
}

if (isset($_POST['upload_req'])) {
    // Ensure uploads directory exists
    if (!is_dir('uploads')) {
        mkdir('uploads', 0755, true);
    }

    $student_id = getStudentId($connection, $_SESSION['user_id']);
    if (!$student_id) {
        echo '<script>alert("Student record not found!"); window.location = "student_dashboard.php";</script>';
        exit();
    }
    $student_id = mysqli_real_escape_string($connection, $student_id);
    $req_name = mysqli_real_escape_string($connection, $_POST['req_name']);
    $safe_req_name = str_replace('/', '_', $req_name); // Replace slashes with underscores for filename

    if (isset($_FILES['req_file']) && $_FILES['req_file']['error'] == 0) {
        $file_name = $_FILES['req_file']['name'];
        $file_tmp = $_FILES['req_file']['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_exts = ['pdf', 'doc', 'docx', 'jpg', 'png', 'txt'];
        if (in_array($file_ext, $allowed_exts)) {
            $new_file_name = $student_id . '_' . $safe_req_name . '_' . time() . '.' . $file_ext;
            $file_path = 'uploads/' . $new_file_name;
            if (move_uploaded_file($file_tmp, $file_path)) {
                // Get current requirements
                $result = mysqli_query($connection, "SELECT requirements FROM students WHERE id='$student_id'");
                $row = mysqli_fetch_assoc($result);
                $reqs = json_decode($row['requirements'], true) ?: [];
                // Delete old file if exists
                if (isset($reqs[$req_name]['file']) && file_exists($reqs[$req_name]['file'])) {
                    unlink($reqs[$req_name]['file']);
                }
                $reqs[$req_name] = ['status' => 'Submitted', 'file' => $file_path, 'filename' => $file_name];
                $reqs_json = mysqli_real_escape_string($connection, json_encode($reqs));

                $query = "UPDATE students SET requirements='$reqs_json' WHERE id='$student_id'";
                mysqli_query($connection, $query);
                header("Location: student_dashboard.php?tab=requirements&success=1");
                exit();
            } else {
                $error = "Failed to upload file.";
            }
        } else {
            $error = "Invalid file type. Allowed: " . implode(', ', $allowed_exts);
        }
    } else {
        $error = "No file selected or upload error.";
    }
}

if (isset($_POST['upload_file'])) {
    $student_id = getStudentId($connection, $_SESSION['user_id']);
    if (!$student_id) {
        echo '<script>alert("Student record not found!"); window.location = "student_dashboard.php";</script>';
        exit();
    }
    $student_id = mysqli_real_escape_string($connection, $student_id);
    if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
        $file_name = $_FILES['file']['name'];
        $file_tmp = $_FILES['file']['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_exts = ['pdf', 'doc', 'docx', 'jpg', 'png', 'txt'];
        if (in_array($file_ext, $allowed_exts)) {
            $new_file_name = $student_id . '_' . time() . '.' . $file_ext;
            $file_path = 'uploads/' . $new_file_name;
            if (move_uploaded_file($file_tmp, $file_path)) {
                // Get current uploaded_files
                $result = mysqli_query($connection, "SELECT uploaded_files FROM students WHERE id='$student_id'");
                $row = mysqli_fetch_assoc($result);
                $files = json_decode($row['uploaded_files'], true) ?: [];
                $files[] = ['name' => $file_name, 'path' => $file_path];
                $files_json = mysqli_real_escape_string($connection, json_encode($files));
                $query = "UPDATE students SET uploaded_files='$files_json' WHERE id='$student_id'";
                mysqli_query($connection, $query);
                header("Location: student_dashboard.php?tab=requirements&success=1");
                exit();
            } else {
                $error = "Failed to upload file.";
            }
        } else {
            $error = "Invalid file type. Allowed: " . implode(', ', $allowed_exts);
        }
    } else {
        $error = "No file selected or upload error.";
    }
}

if (isset($_POST['update_profile'])) {
    $student_id = getStudentId($connection, $_SESSION['user_id']);
    if (!$student_id) {
        echo '<script>alert("Student record not found!"); window.location = "student_dashboard.php";</script>';
        exit();
    }
    $student_id = mysqli_real_escape_string($connection, $student_id);
    $strand = mysqli_real_escape_string($connection, $_POST['strand']);
    $grade_level = mysqli_real_escape_string($connection, $_POST['grade_level']);
    $contact_number = mysqli_real_escape_string($connection, trim($_POST['contact_number'] ?? ''));

    $query = "UPDATE students SET strand='$strand', grade_level='$grade_level', contact_number='$contact_number' WHERE id='$student_id'";
    $update_result = mysqli_query($connection, $query);

    if ($update_result) {
        $user_id = mysqli_real_escape_string($connection, $_SESSION['user_id']);
        mysqli_query($connection, "UPDATE isfinals SET contact_number='$contact_number' WHERE id='$user_id'");
    } else {
        error_log('Student profile update failed: ' . mysqli_error($connection));
    }
    
    header("Location: student_dashboard.php?tab=profile&success=1");
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
            header("Location: student_dashboard.php?tab=profile&success=password_changed");
            exit();
        } else {
            $error = "New passwords do not match.";
        }
    } else {
        $error = "Current password is incorrect.";
    }
}

// Handle Concern Submission
$response_column = 'teacher_response';
$response_column_check = mysqli_query($connection, "SHOW COLUMNS FROM student_concerns LIKE 'teacher_response'");
if (!$response_column_check || mysqli_num_rows($response_column_check) === 0) {
    $response_column = 'supervisor_response';
}
$concerns_result = mysqli_query($connection, "SELECT *, $response_column AS teacher_response FROM student_concerns ORDER BY date_submitted DESC");
$concerns = ($concerns_result) ? mysqli_fetch_all($concerns_result, MYSQLI_ASSOC) : [];

if (isset($_POST['submit_concern'])) {
    $student_id = getStudentId($connection, $_SESSION['user_id']);
    if (!$student_id) {
        echo '<script>alert("Student record not found!"); window.location = "student_dashboard.php";</script>';
        exit();
    }
    $student_id = mysqli_real_escape_string($connection, $student_id);
    $concern_type = mysqli_real_escape_string($connection, $_POST['concern_type']);
    $concern_message = mysqli_real_escape_string($connection, $_POST['concern_message']);
    $date_submitted = date('Y-m-d H:i:s');

    $query = "INSERT INTO student_concerns (student_id, concern_type, message, date_submitted, status) VALUES ('$student_id', '$concern_type', '$concern_message', '$date_submitted', 'Pending')";
    if (mysqli_query($connection, $query)) {
        header("Location: student_dashboard.php?tab=concerns&success=1");
        exit();
    }
}

if (isset($_POST['edit_concern'])) {
    $student_id = getStudentId($connection, $_SESSION['user_id']);
    $concern_id = mysqli_real_escape_string($connection, $_POST['concern_id']);
    $concern_type = mysqli_real_escape_string($connection, $_POST['concern_type']);
    $concern_message = mysqli_real_escape_string($connection, trim($_POST['concern_message'] ?? ''));

    if ($student_id && $concern_id && $concern_message) {
        $student_id_safe = mysqli_real_escape_string($connection, $student_id);
        mysqli_query($connection, "UPDATE student_concerns SET concern_type='$concern_type', message='$concern_message', status='Pending' WHERE id='$concern_id' AND student_id='$student_id_safe'");
        header("Location: student_dashboard.php?tab=concerns&success=1");
        exit();
    }
}

$active_tab = $_GET['tab'] ?? 'home';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Dashboard | Work Immersion Monitoring System</title>
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
            color: rgba(255,255,255,0.95);
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

        main {
            padding: 2rem 2.5rem;
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

        .modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: var(--shadow-xl);
            backdrop-filter: blur(20px);
        }

        .modal-header {
            border-bottom: 1px solid var(--border-light);
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(248,250,249,0.9) 100%);
            border-radius: 20px 20px 0 0;
            padding: 1.75rem 2rem;
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
            transition: all 0.4s ease;
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
            transform: translateY(-8px) scale(1.03);
            box-shadow: var(--shadow-xl);
        }

        .stat-card:hover::before {
            opacity: 1;
            animation: shimmer 1.5s ease-in-out;
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

        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .current-time {
            text-align: center;
            padding: 1rem;
            background: rgba(15, 58, 40, 0.05);
            border-radius: 16px;
            border: 1px solid rgba(15, 58, 40, 0.1);
        }

        .time-display {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
            font-family: 'Courier New', monospace;
        }

        .clock-interface {
            background: rgba(255, 255, 255, 0.8);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(15, 58, 40, 0.1);
        }

        .clock-card {
            border: 2px solid rgba(15, 58, 40, 0.1);
            border-radius: 20px;
            transition: all 0.3s ease;
            background: white;
            height: 100%;
        }

        .clock-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(15, 58, 40, 0.15);
            border-color: var(--primary);
        }

        .clock-in-card:hover {
            border-color: var(--accent);
            box-shadow: 0 15px 35px rgba(31, 116, 79, 0.2);
        }

        .clock-out-card:hover {
            border-color: #dc3545;
            box-shadow: 0 15px 35px rgba(220, 53, 69, 0.2);
        }

        .clock-icon {
            opacity: 0.8;
        }

        .work-stats {
            padding: 1rem 0;
        }

        .stat-item {
            padding: 1rem 0;
            border-bottom: 1px solid rgba(15, 58, 40, 0.06);
        }

        .stat-item:last-child {
            border-bottom: none;
        }

        .legend-item {
            display: flex;
            align-items: center;
            margin-left: 1rem;
        }

        .legend-item span.badge {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            padding: 0;
        }

        .empty-state {
            padding: 3rem 0;
        }

        .empty-state i {
            opacity: 0.5;
        }

        .concerns-list {
            max-height: 600px;
            overflow-y: auto;
            padding-right: 0.5rem;
        }

        .concerns-list::-webkit-scrollbar {
            width: 6px;
        }

        .concerns-list::-webkit-scrollbar-track {
            background: rgba(15, 58, 40, 0.05);
            border-radius: 3px;
        }

        .concerns-list::-webkit-scrollbar-thumb {
            background: rgba(15, 58, 40, 0.2);
            border-radius: 3px;
        }

        .concerns-list::-webkit-scrollbar-thumb:hover {
            background: rgba(15, 58, 40, 0.3);
        }

        .concern-item {
            background: rgba(255, 255, 255, 0.5);
            padding: 1rem;
            border-radius: 12px;
            border: 1px solid rgba(15, 58, 40, 0.06);
            transition: all 0.25s ease;
        }

        .concern-item:hover {
            background: rgba(15, 58, 40, 0.03);
            border-color: rgba(15, 58, 40, 0.1);
        }

        .concern-item h6 {
            color: var(--primary);
            margin: 0;
        }

        .concern-item.pb-3 {
            padding-bottom: 1rem !important;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 150px;
        }

        .mobile-menu-toggle {
            display: none;
            background: var(--gradient-primary);
            border: none;
            color: white;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.25rem;
            z-index: 1000;
        }

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-responsive::-webkit-scrollbar {
            height: 6px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: rgba(15, 58, 40, 0.05);
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: rgba(15, 58, 40, 0.2);
            border-radius: 3px;
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
                gap: 1rem;
                padding: 1rem;
                margin-bottom: 1.5rem;
                border-radius: 12px;
            }

            .topbar .user-chip {
                gap: 8px;
                font-size: 0.85rem;
            }

            .topbar .avatar {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .clock-interface {
                padding: 1rem;
            }

            .clock-card .card-body {
                padding: 1.5rem 1rem;
            }

            .current-time {
                padding: 0.5rem;
            }

            .time-display {
                font-size: 1rem;
            }

            .btn-lg {
                padding: 0.6rem 1.2rem;
                font-size: 0.9rem;
            }

            .legend-item {
                margin-left: 0.5rem;
                font-size: 0.85rem;
            }

            .card {
                border-radius: 16px;
                padding: 1rem !important;
            }

            .card-header {
                padding: 1rem 1rem;
            }

            .card-body {
                padding: 1rem;
            }

            .table {
                font-size: 0.85rem;
            }

            .table thead th,
            .table tbody td {
                padding: 0.75rem 0.5rem;
            }

            .btn {
                padding: 0.5rem 1rem;
                font-size: 0.85rem;
            }

            .btn-primary, .btn-outline-primary {
                width: 100%;
                margin-bottom: 0.5rem;
            }

            .form-control, .form-select {
                padding: 0.75rem;
                font-size: 1rem;
            }

            h2 {
                font-size: 1.5rem !important;
            }

            h5 {
                font-size: 1rem !important;
            }

            .modal-body {
                padding: 1rem;
            }

            textarea.form-control {
                min-height: 120px;
            }

            .row {
                --bs-gutter-x: 0.75rem;
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

            /* Improve card spacing on mobile */
            .card {
                margin-bottom: 1rem;
            }

            /* Better modal experience on mobile */
            .modal-dialog {
                margin: 0.5rem;
            }
        }

        @media (max-width: 576px) {
            main {
                padding: 0.75rem;
            }

            .card {
                padding: 0.75rem !important;
            }

            .topbar {
                padding: 0.75rem;
                margin-bottom: 1rem;
            }

            h2 {
                font-size: 1.25rem !important;
            }

            h5 {
                font-size: 0.9rem !important;
            }

            .table thead th {
                font-size: 0.7rem;
            }
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
                <h5 class="fw-bold mb-0" style="font-size: 0.95rem; letter-spacing: 0.05em;">STUDENT PANEL</h5>
                <small style="font-size: 0.8rem; opacity: 0.85;">Work Immersion</small>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item"><a href="?tab=home" class="nav-link <?= $active_tab == 'home' ? 'active' : '' ?>"><i class="bi bi-house-door me-2"></i> Home</a></li>
                <li class="nav-item"><a href="?tab=profile" class="nav-link <?= $active_tab == 'profile' ? 'active' : '' ?>"><i class="bi bi-person me-2"></i> Profile</a></li>
                <li class="nav-item"><a href="?tab=attendance" class="nav-link <?= $active_tab == 'attendance' ? 'active' : '' ?>"><i class="bi bi-calendar-check me-2"></i> Attendance</a></li>
                <li class="nav-item"><a href="?tab=requirements" class="nav-link <?= $active_tab == 'requirements' ? 'active' : '' ?>"><i class="bi bi-file-earmark-arrow-up me-2"></i> Requirements</a></li>
                <li class="nav-item"><a href="?tab=reports" class="nav-link <?= $active_tab == 'reports' ? 'active' : '' ?>"><i class="bi bi-journal-text me-2"></i> Reports</a></li>
                <li class="nav-item"><a href="?tab=concerns" class="nav-link <?= $active_tab == 'concerns' ? 'active' : '' ?>"><i class="bi bi-chat-left-text me-2"></i> Concerns & Feedback</a></li>
                <li class="nav-item"><a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#changePasswordModal"><i class="bi bi-key me-2"></i> Change Password</a></li>
                <li class="nav-item mt-5"><a href="check_login.php?logout=1" class="nav-link text-danger border border-danger"><i class="bi bi-power me-2"></i> Logout</a></li>
            </ul>
        </nav>

        <main class="col-md-10 px-md-4 py-4">
            <div class="topbar">
                <div>
                    <div class="fw-bold"><?= htmlspecialchars($_SESSION['fullname'] ?? 'Student') ?></div>
                    <div class="small text-muted"><?= date('M d, Y H:i') ?></div>
                </div>
                <div class="user-chip">
                    <span class="avatar"><?= substr(htmlspecialchars($_SESSION['user_email'] ?? 'S'), 0, 1) ?></span>
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($_SESSION['user_email'] ?? 'Student') ?></div>
                        <div class="small text-muted">Student</div>
                    </div>
                </div>
            </div>

            <?php if ($active_tab == 'home'): ?>
            <div class="d-flex justify-content-between align-items-center mb-4 dashboard-header">
                <div>
                    <h2 class="mb-2 fw-bold" style="font-size: 2.25rem; background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">Welcome Back</h2>
                    <p class="text-muted small mb-0">Track your work immersion progress and announcements.</p>
                </div>
                <div class="small text-muted">
                    <i class="bi bi-calendar3 me-1"></i><?= date('l, F j, Y') ?>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-md-8">
                    <div class="card p-4">
                        <h5 class="fw-bold mb-4"><i class="bi bi-megaphone me-2 text-warning"></i>Announcements</h5>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead class="table-light">
                                    <tr>
                                <th>Date</th>
                                <th>Topic</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($announcements)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">No announcements posted.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_reverse($announcements) as $a): ?>
                                    <tr>
                                        <td class="small text-muted"><?= htmlspecialchars($a['date'] ?? 'N/A') ?></td>
                                        <td><strong><?= htmlspecialchars($a['title'] ?? 'No Title') ?></strong></td>
                                        <td><?= nl2br(htmlspecialchars($a['content'] ?? 'No content available.')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-4 mb-3 text-center">
                <h6 class="text-muted small fw-bold">CURRENT STATUS</h6>
                <h3 class="fw-bold text-primary"><?= $current_student['status'] ?? 'Active' ?></h3>
            </div>
            <div class="card p-4 text-center">
                <h6 class="text-muted small fw-bold">FINAL GRADE</h6>
                <h2 class="fw-bold text-success"><?= $current_student['grade'] ?? '--' ?></h2>
            </div>
        </div>
    </div>

    <?php elseif ($active_tab == 'profile'): ?>
    <div class="row g-4">
        <div class="col-md-8">
            <div class="card p-4">
                <h5 class="fw-bold mb-4"><i class="bi bi-person-circle me-2 text-primary"></i>Student Profile</h5>
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success">
                        <?php if($_GET['success'] == 'password_changed'): ?>
                            Password changed successfully!
                        <?php else: ?>
                            Profile updated successfully!
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Full Name</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($current_student['fullname'] ?? '') ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Email</label>
                            <input type="email" class="form-control" value="<?= htmlspecialchars($current_student['email'] ?? '') ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Contact Number</label>
                            <input id="contactNumberInput" type="tel" name="contact_number" class="form-control" value="<?= htmlspecialchars(trim($current_student['contact_number'] ?? '')) ?>" placeholder="09XXXXXXXXX" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Strand</label>
                            <select name="strand" class="form-select" required>
                                <option value="">Select Strand</option>
                                <option value="ICT" <?= ($current_student['strand'] ?? '') == 'ICT' ? 'selected' : '' ?>>ICT (Information and Communications Technology)</option>
                                <option value="STEM" <?= ($current_student['strand'] ?? '') == 'STEM' ? 'selected' : '' ?>>STEM (Science, Technology, Engineering, Mathematics)</option>
                                <option value="ABM" <?= ($current_student['strand'] ?? '') == 'ABM' ? 'selected' : '' ?>>ABM (Accountancy, Business and Management)</option>
                                <option value="HUMSS" <?= ($current_student['strand'] ?? '') == 'HUMSS' ? 'selected' : '' ?>>HUMSS (Humanities and Social Sciences)</option>
                                <option value="GAS" <?= ($current_student['strand'] ?? '') == 'GAS' ? 'selected' : '' ?>>GAS (General Academic Strand)</option>
                                <option value="TVL" <?= ($current_student['strand'] ?? '') == 'TVL' ? 'selected' : '' ?>>TVL (Technical-Vocational-Livelihood)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Grade Level</label>
                            <select name="grade_level" class="form-select" required>
                                <option value="">Select Grade Level</option>
                                <option value="Grade 11" <?= ($current_student['grade_level'] ?? '') == 'Grade 11' ? 'selected' : '' ?>>Grade 11</option>
                                <option value="Grade 12" <?= ($current_student['grade_level'] ?? '') == 'Grade 12' ? 'selected' : '' ?>>Grade 12</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" name="update_profile" class="btn btn-primary px-4">
                            <i class="bi bi-check-circle me-2"></i>Update Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-4 mb-3 text-center">
                <h6 class="text-muted small fw-bold">STUDENT ID</h6>
                <h3 class="fw-bold text-primary">#<?= htmlspecialchars($current_student['id'] ?? 'N/A') ?></h3>
            </div>
            <div class="card p-4 mb-3 text-center">
                <h6 class="text-muted small fw-bold">COMPANY ASSIGNED</h6>
                <h4 class="fw-bold text-primary"><?= htmlspecialchars($current_student['company_assigned'] ?? 'N/A') ?></h4>
            </div>
            <div class="card p-4 text-center">
                <h6 class="text-muted small fw-bold">FINAL GRADE</h6>
                <h2 class="fw-bold text-success"><?= $current_student['grade'] ?? '--' ?></h2>
            </div>
        </div>
    </div>

    <?php elseif ($active_tab == 'attendance'): ?>
        <!-- Work Hours Overview -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h5 class="fw-bold mb-1"><i class="bi bi-clock-history me-2 text-primary"></i>Work Hours Tracker</h5>
                            <p class="text-muted small mb-0">Track your daily attendance and work progress</p>
                        </div>
                        <div class="current-time">
                            <div class="time-display fw-bold text-primary" id="currentTime">--:--:--</div>
                            <small class="text-muted"><?php echo date('l, F j, Y'); ?></small>
                        </div>
                    </div>

                    <!-- Time In/Time Out Interface -->
                    <div class="clock-interface mb-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="clock-card clock-in-card" id="clockInCard">
                                    <div class="card-body text-center p-4">
                                        <div class="clock-icon mb-3">
                                            <i class="bi bi-play-circle-fill text-success" style="font-size: 3rem;"></i>
                                        </div>
                                        <h6 class="fw-bold text-success mb-2">Time In</h6>
                                        <p class="text-muted small mb-3">Start your work session</p>
                                        <form method="POST" class="d-inline">
                                            <button type="submit" name="log_type" value="in" class="btn btn-success btn-lg px-4">
                                                <i class="bi bi-play-fill me-2"></i>Time In
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="clock-card clock-out-card" id="clockOutCard">
                                    <div class="card-body text-center p-4">
                                        <div class="clock-icon mb-3">
                                            <i class="bi bi-stop-circle-fill text-danger" style="font-size: 3rem;"></i>
                                        </div>
                                        <h6 class="fw-bold text-danger mb-2">Time Out</h6>
                                        <p class="text-muted small mb-3">End your work session</p>
                                        <form method="POST" class="d-inline">
                                            <button type="submit" name="log_type" value="out" class="btn btn-danger btn-lg px-4">
                                                <i class="bi bi-stop-fill me-2"></i>Time Out
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Status Messages -->
                    <?php if (isset($_GET['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?php if ($_GET['success'] === 'clock_in'): ?>
                                <strong>Time In Successful!</strong> Your work session has started.
                            <?php elseif ($_GET['success'] === 'clock_out'): ?>
                                <strong>Time Out Successful!</strong> Your work session has ended.
                            <?php endif; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_GET['error'])): ?>
                        <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php if ($_GET['error'] === 'already_clocked_in'): ?>
                                <strong>Already Clocked In</strong> You already have an open session today.
                            <?php elseif ($_GET['error'] === 'already_completed_today'): ?>
                                <strong>Session Completed</strong> You have already clocked in and out today.
                            <?php elseif ($_GET['error'] === 'already_clocked_out'): ?>
                                <strong>Already Time Out</strong> Your work session for today is already closed.
                            <?php elseif ($_GET['error'] === 'no_clock_in'): ?>
                                <strong>No Active Session</strong> Please clock in before you can clock out.
                            <?php endif; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Work Hours Summary -->
            <div class="col-lg-4">
                <div class="card p-4 h-100">
                    <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart me-2 text-primary"></i>Work Summary</h6>
                    <?php
                    // Calculate work hours statistics
                    $total_hours = 0;
                    $total_days = 0;
                    $approved_sessions = 0;
                    $pending_sessions = 0;

                    foreach ($attendance as $row) {
                        if ($row['student_id'] == $current_student['id']) {
                            $total_days++;
                            if ($row['status'] == 'Approved') {
                                $approved_sessions++;
                            } elseif ($row['status'] == 'Pending') {
                                $pending_sessions++;
                            }

                            // Calculate hours from time in/out
                            if (!empty($row['time_in']) && !empty($row['time_out']) &&
                                $row['time_in'] !== '--:--' && $row['time_out'] !== '--:--') {
                                $time_in = strtotime($row['time_in']);
                                $time_out = strtotime($row['time_out']);
                                if ($time_out > $time_in) {
                                    $hours_worked = ($time_out - $time_in) / 3600;
                                    $total_hours += $hours_worked;
                                }
                            }
                        }
                    }

                    $required_hours = $current_student['required_hours'] ?? 0;
                    $progress_percentage = $required_hours > 0 ? min(100, ($total_hours / $required_hours) * 100) : 0;
                    ?>
                    <div class="work-stats">
                        <div class="stat-item mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Total Hours</span>
                                <span class="fw-bold text-primary"><?php echo number_format($total_hours, 1); ?> hrs</span>
                            </div>
                            <div class="progress mt-2" style="height: 6px;">
                                <div class="progress-bar bg-primary" role="progressbar"
                                     style="width: <?php echo $progress_percentage; ?>%"
                                     aria-valuenow="<?php echo $progress_percentage; ?>"
                                     aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <small class="text-muted">Required: <?php echo $required_hours; ?> hrs (<?php echo round($progress_percentage); ?>% complete)</small>
                        </div>

                        <div class="stat-item mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Work Days</span>
                                <span class="fw-bold"><?php echo $total_days; ?> days</span>
                            </div>
                        </div>

                        <div class="stat-item mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Approved Sessions</span>
                                <span class="fw-bold text-success"><?php echo $approved_sessions; ?></span>
                            </div>
                        </div>

                        <div class="stat-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Pending Approval</span>
                                <span class="fw-bold text-warning"><?php echo $pending_sessions; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Attendance History -->
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h5 class="fw-bold mb-1"><i class="bi bi-calendar-event me-2 text-primary"></i>Attendance History</h5>
                    <p class="text-muted small mb-0">Your complete work attendance record</p>
                </div>
                <div class="d-flex gap-2">
                    <div class="legend-item">
                        <span class="badge bg-success me-1"></span>
                        <small class="text-muted">Approved</small>
                    </div>
                    <div class="legend-item">
                        <span class="badge bg-warning text-dark me-1"></span>
                        <small class="text-muted">Pending</small>
                    </div>
                    <div class="legend-item">
                        <span class="badge bg-danger me-1"></span>
                        <small class="text-muted">Rejected</small>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4"><i class="bi bi-calendar-date me-1"></i>Date</th>
                            <th><i class="bi bi-clock me-1 text-success"></i>Time In</th>
                            <th><i class="bi bi-clock-fill me-1 text-danger"></i>Time Out</th>
                            <th><i class="bi bi-hourglass me-1"></i>Duration</th>
                            <th><i class="bi bi-check-circle me-1"></i>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $hasAttendance = false; ?>
                        <?php foreach ($attendance as $row): if($row['student_id']==$current_student['id']): $hasAttendance = true; ?>
                        <?php
                        // Calculate duration
                        $duration = '--';
                        $duration_hours = 0;
                        if (!empty($row['time_in']) && !empty($row['time_out']) &&
                            $row['time_in'] !== '--:--' && $row['time_out'] !== '--:--') {
                            $time_in = strtotime($row['time_in']);
                            $time_out = strtotime($row['time_out']);
                            if ($time_out > $time_in) {
                                $duration_seconds = $time_out - $time_in;
                                $hours = floor($duration_seconds / 3600);
                                $minutes = floor(($duration_seconds % 3600) / 60);
                                $duration = sprintf('%dh %dm', $hours, $minutes);
                                $duration_hours = $hours + ($minutes / 60);
                            }
                        }

                        // Status styling
                        $status_class = 'bg-warning text-dark';
                        $status_icon = 'bi-clock';
                        if ($row['status'] == 'Approved') {
                            $status_class = 'bg-success';
                            $status_icon = 'bi-check-circle-fill';
                        } elseif ($row['status'] == 'Rejected') {
                            $status_class = 'bg-danger';
                            $status_icon = 'bi-x-circle-fill';
                        }
                        ?>
                        <tr>
                            <td class="ps-4 fw-semibold"><?php echo date('M d, Y', strtotime($row['date'] ?? 'today')); ?></td>
                            <td>
                                <span class="text-success fw-bold"><?php echo htmlspecialchars($row['time_in'] && $row['time_in'] !== '--:--' ? date('h:i A', strtotime($row['time_in'])) : '--:--'); ?></span>
                            </td>
                            <td>
                                <span class="text-danger fw-bold"><?php echo htmlspecialchars($row['time_out'] && $row['time_out'] !== '--:--' ? date('h:i A', strtotime($row['time_out'])) : '--:--'); ?></span>
                            </td>
                            <td>
                                <?php if ($duration !== '--'): ?>
                                    <span class="badge bg-info text-dark"><?php echo $duration; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">--</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $status_class; ?>">
                                    <i class="bi <?php echo $status_icon; ?> me-1"></i>
                                    <?php echo htmlspecialchars($row['status'] ?? 'Pending'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endif; endforeach; ?>
                        <?php if (!$hasAttendance): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <div class="empty-state">
                                    <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                                    <h6 class="text-muted mt-3">No Attendance Records</h6>
                                    <p class="text-muted small">Start by clocking in to begin tracking your work hours.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    
    <?php elseif ($active_tab == 'reports'): ?>
    <div class="row g-4">
        <div class="col-md-5">
            <div class="card p-4">
                <h5 class="fw-bold mb-3">Daily Log</h5>
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <strong>Report submitted successfully!</strong>
                        <?php if (isset($_GET['title']) && isset($_GET['date'])): ?>
                            <div class="mt-2 pt-2 border-top">
                                <small>
                                    <strong><?= htmlspecialchars($_GET['title']) ?></strong> 
                                    <br><small class="text-muted"><?= htmlspecialchars($_GET['date']) ?> • <?= htmlspecialchars($_GET['hours'] ?? '0') ?> hours</small>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="edit_id" value="<?= $edit_report ? $edit_report['id'] : '' ?>">
                    <input name="report_title" class="form-control mb-3" placeholder="Title" value="<?= htmlspecialchars($edit_report['title'] ?? '') ?>" required>
                    <input name="hours" type="number" class="form-control mb-3" placeholder="Hours Rendered" value="<?= htmlspecialchars($edit_report['hours'] ?? '') ?>" required>
                    <textarea name="narrative" class="form-control mb-3" rows="4" placeholder="What did you do today?" required><?= htmlspecialchars($edit_report['content'] ?? '') ?></textarea>
                    <button name="submit_report" class="btn btn-primary w-100"><?= $edit_report ? 'Update Report' : 'Submit Report' ?></button>
                </form>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card p-4">
                <h5 class="fw-bold mb-3">Past Submissions</h5>
                <?php foreach ($reports as $r): if($r['student_id']==$current_student['id']): ?>
                    <div class="border-bottom mb-3 pb-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <strong><?= $r['title'] ?></strong> <small class="text-muted">(<?= $r['hours'] ?> hrs)</small>
                                <small class="text-muted d-block mb-1"><?= $r['date'] ?? 'N/A' ?></small>
                                <p class="small text-muted mb-0"><?= $r['content'] ?></p>
                            </div>
                            <div class="ms-2">
                                <a href="?tab=reports&edit=<?= $r['id'] ?>" class="btn btn-sm btn-outline-warning me-1">Edit</a>
                                <a href="?tab=reports&delete=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this report?')">Delete</a>
                            </div>
                        </div>
                    </div>
                <?php endif; endforeach; ?>
            </div>
        </div>
    </div>

    <?php elseif ($active_tab == 'concerns'):
    ?>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card p-4">
                <div class="d-flex align-items-center mb-4">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-chat-left-text me-2 text-primary"></i>Submit Your Concern</h5>
                        <p class="text-muted small mb-0">Share your feedback, concerns, or suggestions about your work immersion experience</p>
                    </div>
                </div>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <strong>Thank you!</strong> Your concern has been submitted successfully. The supervisor will review it soon.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="bi bi-tag me-2"></i>Concern Type</label>
                        <select name="concern_type" class="form-select" required>
                            <option value="" disabled selected>-- Select concern type --</option>
                            <option value="Supervision">Supervision Issue</option>
                            <option value="Workload">Workload/Scheduling</option>
                            <option value="Safety">Safety Concern</option>
                            <option value="Harassment">Harassment/Discrimination</option>
                            <option value="Learning">Learning Opportunity</option>
                            <option value="Facilities">Facilities/Resources</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="bi bi-chat-dots me-2"></i>Your Concern</label>
                        <textarea name="concern_message" class="form-control" rows="6" placeholder="Please describe your concern in detail. Be specific about what happened, when it happened, and how it affected you..." required></textarea>
                        <small class="text-muted mt-2 d-block">Your concern will be kept confidential and reviewed by the supervisor.</small>
                    </div>
                    <button type="submit" name="submit_concern" class="btn btn-primary w-100 py-2">
                        <i class="bi bi-send me-2"></i>Submit Concern
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card p-4">
                <h5 class="fw-bold mb-4"><i class="bi bi-list-check me-2 text-primary"></i>Your Concerns History</h5>

                <?php if (empty($concerns) || !$current_student || !array_filter($concerns, function($c) use ($current_student) { return $c['student_id'] == $current_student['id']; })): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                        <h6 class="text-muted mt-3">No Concerns Submitted Yet</h6>
                        <p class="text-muted small">Your concerns and feedback will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="concerns-list">
                        <?php if ($current_student): ?>
                        <?php foreach ($concerns as $c):
                            if ($c['student_id'] == $current_student['id']):
                                $status_class = 'bg-warning text-dark';
                                $status_icon = 'bi-clock';
                                if ($c['status'] == 'Reviewed') {
                                    $status_class = 'bg-info';
                                    $status_icon = 'bi-eye';
                                } elseif ($c['status'] == 'Resolved') {
                                    $status_class = 'bg-success';
                                    $status_icon = 'bi-check-circle';
                                }
                        ?>
                        <div class="concern-item mb-3 pb-3 border-bottom">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($c['concern_type']); ?></h6>
                                    <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($c['date_submitted'])); ?></small>
                                </div>
                                <span class="badge <?php echo $status_class; ?>">
                                    <i class="bi <?php echo $status_icon; ?> me-1"></i>
                                    <?php echo htmlspecialchars($c['status']); ?>
                                </span>
                            </div>
                            <p class="small text-muted mb-2"><?php echo htmlspecialchars(substr($c['message'], 0, 150)); ?><?php echo strlen($c['message']) > 150 ? '...' : ''; ?></p>
                            <?php if (!empty($c['teacher_response'])): ?>
                            <div class="alert alert-info py-2 px-3 mb-0" style="font-size: 0.875rem;">
                                <strong>Teacher/Admin Response:</strong><br>
                                <?php echo nl2br(htmlspecialchars($c['teacher_response'])); ?>
                                <?php if (!empty($c['response_date'])): ?>
                                <br><small class="text-muted">Responded: <?php echo date('M d, Y H:i', strtotime($c['response_date'])); ?></small>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-end mt-3">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editConcernModal<?= htmlspecialchars($c['id']) ?>">Edit</button>
                            </div>
                        </div>

                        <div class="modal fade" id="editConcernModal<?= htmlspecialchars($c['id']) ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bold">Edit Concern</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="concern_id" value="<?= htmlspecialchars($c['id']) ?>">
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Concern Type</label>
                                                <select name="concern_type" class="form-select" required>
                                                    <option value="Supervision" <?= $c['concern_type'] == 'Supervision' ? 'selected' : '' ?>>Supervision Issue</option>
                                                    <option value="Workload" <?= $c['concern_type'] == 'Workload' ? 'selected' : '' ?>>Workload/Scheduling</option>
                                                    <option value="Safety" <?= $c['concern_type'] == 'Safety' ? 'selected' : '' ?>>Safety Concern</option>
                                                    <option value="Harassment" <?= $c['concern_type'] == 'Harassment' ? 'selected' : '' ?>>Harassment/Discrimination</option>
                                                    <option value="Learning" <?= $c['concern_type'] == 'Learning' ? 'selected' : '' ?>>Learning Opportunity</option>
                                                    <option value="Facilities" <?= $c['concern_type'] == 'Facilities' ? 'selected' : '' ?>>Facilities/Resources</option>
                                                    <option value="Other" <?= $c['concern_type'] == 'Other' ? 'selected' : '' ?>>Other</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Concern Message</label>
                                                <textarea name="concern_message" class="form-control" rows="5" required><?= htmlspecialchars($c['message']) ?></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="edit_concern" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php elseif ($active_tab == 'requirements'): ?>
    <div class="card p-4">
        <h5 class="fw-bold mb-4">Submission Checklist</h5>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">Requirement uploaded/replaced successfully!</div>
        <?php endif; ?>
        <div class="row g-3">
            <?php foreach (['Parent ID','Resume/Application letter','Endorsement Letter','Waiver'] as $req): ?>
            <div class="col-md-3 text-center">
                <div class="border rounded p-3 bg-light">
                    <h6><?= $req ?></h6>
                    <span class="badge mb-3 <?= isset($current_student['requirements'][$req]['status']) ? 'bg-success' : 'bg-danger' ?>">
                        <?= $current_student['requirements'][$req]['status'] ?? 'Missing' ?>
                    </span>
                    <?php if (isset($current_student['requirements'][$req]['file'])): ?>
                        <?php $file_path = $current_student['requirements'][$req]['file']; ?>
                        <?php $file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION)); ?>
                        <?php if (in_array($file_ext, ['jpg', 'jpeg', 'png'])): ?>
                            <a href="#" class="btn btn-sm btn-outline-info mb-2" data-bs-toggle="modal" data-bs-target="#fileModal" onclick="loadFile('<?= htmlspecialchars($file_path) ?>', '<?= htmlspecialchars($req) ?>')">View</a><br>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($file_path) ?>" target="_blank" class="btn btn-sm btn-outline-info mb-2">View</a><br>
                        <?php endif; ?>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="req_name" value="<?= $req ?>">
                        <input type="file" name="req_file" class="form-control form-control-sm mb-2" required>
                        <button name="upload_req" class="btn btn-sm btn-outline-primary"><?= isset($current_student['requirements'][$req]['file']) ? 'Replace' : 'Upload' ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- File Modal -->
<div class="modal fade" id="fileModal" tabindex="-1" aria-labelledby="fileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="fileModalLabel">View File</h5>
                <button id="fileModalCloseButton" type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div id="fileContent"></div>
            </div>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold">Change Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if(isset($error)): ?>
                        <div class="alert alert-danger small"><?php echo $error; ?></div>
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

    </main>
        </div>
    </div>
</div>

<script>
function loadFile(filePath, fileName) {
    document.getElementById('fileModalLabel').textContent = 'View: ' + fileName;
    const fileExt = filePath.split('.').pop().toLowerCase();
    const contentDiv = document.getElementById('fileContent');
    const closeButton = document.getElementById('fileModalCloseButton');

    if (['jpg', 'jpeg', 'png'].includes(fileExt)) {
        contentDiv.innerHTML = '<img src="' + filePath + '" alt="' + fileName + '" class="img-fluid">';
        if (closeButton) closeButton.style.display = 'inline-block';
    } else {
        contentDiv.innerHTML = '<iframe src="' + filePath + '" width="100%" height="500px" frameborder="0"></iframe>';
        if (closeButton) closeButton.style.display = 'none';
    }
}
</script>

<script>
// Live clock update
function updateClock() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', {
        hour12: true,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    const timeElement = document.getElementById('currentTime');
    if (timeElement) {
        timeElement.textContent = timeString;
    }
}

// Update clock immediately and then every second
updateClock();
setInterval(updateClock, 1000);

// Mobile menu toggle
const mobileMenuToggle = document.getElementById('mobileMenuToggle');
const sidebar = document.getElementById('sidebar');

if (mobileMenuToggle && sidebar) {
    mobileMenuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('active');
    });

    // Close sidebar when a nav link is clicked
    const navLinks = sidebar.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        link.addEventListener('click', function() {
            sidebar.classList.remove('active');
        });
    });

    // Close sidebar when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.sidebar') && !e.target.closest('.mobile-menu-toggle')) {
            sidebar.classList.remove('active');
        }
    });
}

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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