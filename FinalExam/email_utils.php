<?php
// --- email_utils.php ---
// Reusable email sending utility functions powered by PHPMailer.
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

/**
 * Send an email using PHPMailer
 *
 * @param string $to_email Recipient email address
 * @param string $to_name Recipient name
 * @param string $subject Email subject
 * @param string $body Email body (HTML)
 * @param string $from_email Sender email (optional)
 * @param string $from_name Sender name (optional)
 * @return bool True on success, false on failure
 */
function send_email($to_email, $to_name, $subject, $body, $from_email = 'nicolesambile0@gmail.com', $from_name = 'WIMS System') {
    $mail = new PHPMailer(true);

    try {
        // SMTP Config
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'nicolesambile0@gmail.com';      // CHANGE THIS
        $mail->Password   = 'zizg hpnr ijzf ejhi';    // CHANGE THIS
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Sender & Receiver
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to_email, $to_name);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send email verification code
 *
 * @param string $to_email Recipient email address
 * @param string $user_name Recipient name
 * @param string $verification_code The verification code
 * @return bool True on success, false on failure
 */
function send_verification_email($to_email, $user_name, $verification_code) {
    $subject = 'Email Verification - WIMS';
    $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;'>
            <h2 style='color: #333; text-align: center;'>Welcome to WIMS!</h2>
            <p>Hello <strong>{$user_name}</strong>,</p>
            <p>Thank you for registering with the Work Immersion Monitoring System (WIMS).</p>
            <p>Please verify your email address by entering the verification code below:</p>
            <div style='background: #f8f9fa; padding: 15px; border-radius: 5px; text-align: center; margin: 20px 0;'>
                <h3 style='color: #007bff; margin: 0; font-size: 24px;'>{$verification_code}</h3>
                <p style='margin: 5px 0 0 0; color: #666;'>Your verification code</p>
            </div>
            <p><strong>Important:</strong> This code will expire in 24 hours. If you did not create this account, please ignore this email.</p>
            <p>After verification, you can log in to your account and start using WIMS.</p>
            <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
            <p style='color: #666; font-size: 12px; text-align: center;'>This is an automated message from WIMS. Please do not reply to this email.</p>
        </div>
    ";

    return send_email($to_email, $user_name, $subject, $body);
}

/**
 * Send a password reset code email
 *
 * @param string $to_email Recipient email address
 * @param string $user_name Recipient name
 * @param string $reset_code Reset code to include in the email
 * @return bool True on success, false on failure
 */
function send_reset_email($to_email, $user_name, $reset_code) {
    $subject = 'Password Reset Code - WIMS';
    $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;'>
            <h2 style='color: #333; text-align: center;'>Password Reset Request</h2>
            <p>Hello <strong>{$user_name}</strong>,</p>
            <p>We received a request to reset your password for your WIMS account.</p>
            <p>Use the code below to reset your password. This code will expire in 1 hour.</p>
            <div style='background: #f8f9fa; padding: 15px; border-radius: 5px; text-align: center; margin: 20px 0;'>
                <h3 style='color: #007bff; margin: 0; font-size: 28px;'>{$reset_code}</h3>
                <p style='margin: 5px 0 0 0; color: #666;'>Your password reset code</p>
            </div>
            <p>If you did not request a password reset, please ignore this email or contact support.</p>
            <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
            <p style='color: #666; font-size: 12px; text-align: center;'>This is an automated message from WIMS. Please do not reply to this email.</p>
        </div>
    ";

    return send_email($to_email, $user_name, $subject, $body);
}
?>