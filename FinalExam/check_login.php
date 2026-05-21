<?php
// --- check_login.php ---
// Authenticates submitted login credentials and redirects users by role.
session_start();
require('./connection.php');

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}

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

if (isset($_POST['login'])) {

    $email = mysqli_real_escape_string($connection, $_POST['EMAIL']);
    $password = mysqli_real_escape_string($connection, $_POST['PASSWORD']);
    $selected_role = normalize_role($_POST['ROLE'] ?? '');
    $selected_role = mysqli_real_escape_string($connection, $selected_role);
    
    // Validate that a role was selected
    if (empty($selected_role)) {
        header('Location: login.php?error=' . urlencode('Please select a role to login!'));
        exit();
    }

    if (!in_array($selected_role, ['student', 'owner_supervisor'], true)) {
        header('Location: login.php?error=' . urlencode('Invalid role selection. Please choose Student or Owner/Supervisor.'));
        exit();
    }

    $query = "SELECT * FROM isfinals WHERE email='$email' OR username='$email' LIMIT 1";
    $result = mysqli_query($connection, $query);

    if (mysqli_num_rows($result) > 0) {

        $row = mysqli_fetch_assoc($result);
        $stored_password = $row['password'];
        $password_ok = false;

        if (password_verify($password, $stored_password)) {
            $password_ok = true;
            if (password_needs_rehash($stored_password, PASSWORD_DEFAULT)) {
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                mysqli_query($connection, "UPDATE isfinals SET password='" . mysqli_real_escape_string($connection, $new_hash) . "' WHERE id='" . mysqli_real_escape_string($connection, $row['id']) . "'");
            }
        } elseif ($stored_password === $password) {
            // Legacy plain-text password fallback: migrate to hashed storage
            $password_ok = true;
            $new_hash = password_hash($password, PASSWORD_DEFAULT);
            mysqli_query($connection, "UPDATE isfinals SET password='" . mysqli_real_escape_string($connection, $new_hash) . "' WHERE id='" . mysqli_real_escape_string($connection, $row['id']) . "'");
        }

        if (!$password_ok) {
            header('Location: login.php?error=' . urlencode('Invalid credentials or role!'));
            exit();
        }

        // Check account approval status
        $approval_status = $row['approval_status'] ?? 'Approved';
        
        if ($approval_status === 'Pending') {
            header('Location: login.php?error=' . urlencode('Your account is pending admin approval. Please wait.'));
            exit();
        }
        
        if ($approval_status === 'Rejected') {
            // Delete rejected account
            $user_id = mysqli_real_escape_string($connection, $row['id']);
            mysqli_query($connection, "DELETE FROM students WHERE user_id='$user_id'");
            mysqli_query($connection, "DELETE FROM supervisors WHERE user_id='$user_id'");
            mysqli_query($connection, "DELETE FROM isfinals WHERE id='$user_id'");
            
            header('Location: login.php?error=' . urlencode('Your account has been rejected and deleted. Please contact support.'));
            exit();
        }

        // Check if email is verified
        if ($row['email_verified'] != 1) {
            header('Location: verify_email.php?email=' . urlencode($row['email']));
            exit();
        }

        // Normalize role from database
        $db_role = normalize_role($row['role'] ?? '');

        // If user role is not one of the expected roles, block login
        if (!in_array($db_role, ['student', 'owner_supervisor'], true)) {
            header('Location: login.php?error=' . urlencode('Your account role is not valid for this login page.'));
            exit();
        }

        // Validate the selected role against stored role
        if ($selected_role !== $db_role) {
            $message = 'Role mismatch! Your account role is ' . ucfirst(str_replace('_', ' ', $db_role)) . ', but you selected ' . ucfirst(str_replace('_', ' ', $selected_role)) . '.';
            header('Location: login.php?error=' . urlencode($message));
            exit();
        }

        // SAVE USER DATA
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['user_email'] = $row['email'];
        $_SESSION['fullname'] = $row['fullname'];
        $_SESSION['role'] = $db_role;

        // Debug: log the session values
        error_log("Login successful: Email=" . $_SESSION['user_email'] . ", Role=" . $_SESSION['role']);

        if ($_SESSION['role'] === 'student') {
            header("Location: student_dashboard.php");
        } elseif ($_SESSION['role'] === 'owner_supervisor') {
            header("Location: supervisor_dashboard.php");
        } else {
            header("Location: login.php");
        }
        exit();

    } else {
        header('Location: login.php?error=' . urlencode('Invalid credentials or role!'));
    }
}
?>

//Nicole Sambile
//John Paul Santos
//Jessica Salalila