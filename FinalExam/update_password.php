<?php
// --- update_password.php ---
// Updates the current user's password on form submission.
session_start();
require('./connection.php');

if (isset($_POST['change'])) {

$email = $_SESSION['email'];

$old = $_POST['old_password'];
$new = $_POST['new_password'];
$confirm = $_POST['confirm_password'];

$result = mysqli_query($connection, "SELECT * FROM istbl WHERE Email='$email'");
$row = mysqli_fetch_assoc($result);

if ($row['Password'] != $old) {
    $_SESSION['change_password_error'] = 'Current password is incorrect.';
    header('Location: change_password.php');
    exit();
}

if ($new != $confirm) {
    $_SESSION['change_password_error'] = 'New password does not match.';
    header('Location: change_password.php');
    exit();
}

mysqli_query($connection, "UPDATE istbl SET Password='$new' WHERE Email='$email'");
echo "<script>alert('Password change successfully'); window.location='dashboard.php';</script>";
}
?>

//Nicole Sambile
//John Paul Santos
//Jessica Salalila