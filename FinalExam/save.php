<?php
// --- save.php ---
// Handles new user registration, validates input, and saves accounts to the database.
require('./connection.php');
require('./email_utils.php');

if (isset($_POST['save'])) {

    $fullname = mysqli_real_escape_string($connection, $_POST['FN']);
    $email = mysqli_real_escape_string($connection, $_POST['EMAIL']);
    $username = mysqli_real_escape_string($connection, $_POST['UN']);
    $password = mysqli_real_escape_string($connection, $_POST['PASSWORD']);
    $confirm_password = mysqli_real_escape_string($connection, $_POST['CONFIRM_PASSWORD']);
    $contact_number = mysqli_real_escape_string($connection, $_POST['CONTACT_NUMBER']);
    $role = strtolower(trim($_POST['ROLE'] ?? ''));
    $role = mysqli_real_escape_string($connection, $role);

    // Debug log
    error_log("Registration attempt: Role value = '" . $role . "'");

    if ($password !== $confirm_password) {
        echo '<script>alert("Passwords do not match!"); window.location = "index.php";</script>';
        exit;
    }

    if (strlen($password) < 8
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password)
        || !preg_match('/[!@#$%^&*()_+\[\]{}|;:\\",.<>\/`~\-]/', $password)
    ) {
        echo '<script>alert("Password must be at least 8 characters and include uppercase, lowercase, number, and special character."); window.location = "index.php";</script>';
        exit;
    }

    if (empty($role)) {
        echo '<script>alert("Please select a role!"); window.location = "index.php";</script>';
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Check if email already exists
    $queryCheckEmail = "SELECT * FROM isfinals WHERE email = '$email'";
    $resultEmail = mysqli_query($connection, $queryCheckEmail);

    // Check if username already exists
    $queryCheckUsername = "SELECT * FROM isfinals WHERE username = '$username'";
    $resultUsername = mysqli_query($connection, $queryCheckUsername);

    if (mysqli_num_rows($resultEmail) > 0) {
        echo '<script>alert("Email already exists!"); window.location = "index.php";</script>';
    } elseif (mysqli_num_rows($resultUsername) > 0) {
        echo '<script>alert("Username already exists!"); window.location = "index.php";</script>';
    } else {
        // Generate verification code
        $verification_code = random_int(100000, 999999);
        $verification_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $code_hash = password_hash((string) $verification_code, PASSWORD_DEFAULT);

        $queryCreate = "
            INSERT INTO isfinals
            (fullname, email, username, password, role, contact_number, email_verified, verification_code, verification_expiry, approval_status)
            VALUES
            ('$fullname', '$email', '$username', '$hashed_password', '$role', '$contact_number', 0, '$code_hash', '$verification_expiry', 'Pending')
        ";
        $sqlCreate = mysqli_query($connection, $queryCreate);

        if ($sqlCreate) {
            $user_id = mysqli_insert_id($connection);

            // If role is student/user, insert into students table
            if (in_array(strtolower($role), ['user', 'student'])) {
                $queryStudent = "INSERT INTO students (user_id, fullname, email, username, contact_number, requirements, uploaded_files) VALUES ('$user_id', '$fullname', '$email', '$username', '$contact_number', '[]', '[]')";
                mysqli_query($connection, $queryStudent);
            }

            // If role is owner_supervisor, insert into supervisors table
            if (strtolower($role) === 'owner_supervisor') {
                mysqli_query($connection, "INSERT INTO supervisors (user_id, contact_number) VALUES ('$user_id', '$contact_number') ON DUPLICATE KEY UPDATE contact_number='$contact_number'");
            }

            // Send verification email
            if (send_verification_email($email, $fullname, $verification_code)) {
                // Redirect to verification page with success message
                header("Location: verify_email.php?email=" . urlencode($email) . "&registered=1");
                exit();
            } else {
                // Email failed to send, but account and profile row were created
                echo '<script>alert("Account created successfully! However, we could not send the verification email. Please contact support to verify your account."); window.location = "login.php";</script>';
                exit();
            }
        } else {
            $error = mysqli_error($connection);
            echo '<script>alert("Error: ' . addslashes($error) . '\\nQuery: ' . addslashes($queryCreate) . '"); window.location = "index.php";</script>';
        }
    }
}
?>

//Nicole Sambile
//John Paul Santos
//Jessica Salalila