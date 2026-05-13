<?php
// --- setup.php ---
// Database setup script to create required tables and schema for the application.
require('./connection.php');

echo "<h3>Setting up database...</h3>";

function runQuery($connection, $sql) {
    if (!mysqli_query($connection, $sql)) {
        echo "<p style='color:red;'>Error: " . mysqli_error($connection) . "</p>";
    }
}

/* =========================
   1. USERS TABLE
========================= */
$sql = "CREATE TABLE IF NOT EXISTS isfinals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    username VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','user','student','owner_supervisor') DEFAULT 'student'
)";
runQuery($connection, $sql);
runQuery($connection, "ALTER TABLE isfinals MODIFY COLUMN role ENUM('admin','user','student','owner_supervisor') DEFAULT 'student'");
runQuery($connection, "UPDATE isfinals SET role='owner_supervisor' WHERE role='supervisor' OR role='owner/supervisor'");
runQuery($connection, "ALTER TABLE isfinals ADD COLUMN IF NOT EXISTS reset_code VARCHAR(255) DEFAULT NULL");
runQuery($connection, "ALTER TABLE isfinals ADD COLUMN IF NOT EXISTS reset_expiry DATETIME DEFAULT NULL");
runQuery($connection, "ALTER TABLE isfinals ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) DEFAULT 0");
runQuery($connection, "ALTER TABLE isfinals ADD COLUMN IF NOT EXISTS verification_code VARCHAR(255) DEFAULT NULL");
runQuery($connection, "ALTER TABLE isfinals ADD COLUMN IF NOT EXISTS verification_expiry DATETIME DEFAULT NULL");
runQuery($connection, "ALTER TABLE isfinals ADD COLUMN IF NOT EXISTS contact_number VARCHAR(20) DEFAULT NULL");
runQuery($connection, "ALTER TABLE isfinals ADD COLUMN IF NOT EXISTS approval_status ENUM('Pending','Approved','Rejected') DEFAULT 'Approved'");
runQuery($connection, "ALTER TABLE isfinals ADD COLUMN IF NOT EXISTS weak_password_warning TINYINT(1) DEFAULT 0");

/* =========================
   2. STUDENTS TABLE
========================= */
$sql = "CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fullname VARCHAR(255),
    email VARCHAR(255),
    username VARCHAR(255),
    contact_number VARCHAR(20),

    strand VARCHAR(50),
    grade_level VARCHAR(20),
    grade VARCHAR(10),
    company_assigned VARCHAR(255) DEFAULT NULL,
    required_hours INT DEFAULT 80,
    total_hours_rendered INT DEFAULT 0,
    late_count INT DEFAULT 0,
    absent_count INT DEFAULT 0,

    requirements TEXT,
    uploaded_files TEXT,

    performance_rating INT DEFAULT NULL,
    behavior_remarks TEXT,

    FOREIGN KEY (user_id) REFERENCES isfinals(id)
        ON DELETE CASCADE
)";
runQuery($connection, $sql);
runQuery($connection, "ALTER TABLE students ADD COLUMN IF NOT EXISTS contact_number VARCHAR(20) DEFAULT NULL");
runQuery($connection, "ALTER TABLE students ADD COLUMN IF NOT EXISTS company_assigned VARCHAR(255) DEFAULT NULL");
runQuery($connection, "ALTER TABLE students ADD COLUMN IF NOT EXISTS required_hours INT DEFAULT 80");
runQuery($connection, "ALTER TABLE students ADD COLUMN IF NOT EXISTS total_hours_rendered INT DEFAULT 0");
runQuery($connection, "ALTER TABLE students ADD COLUMN IF NOT EXISTS late_count INT DEFAULT 0");
runQuery($connection, "ALTER TABLE students ADD COLUMN IF NOT EXISTS absent_count INT DEFAULT 0");

/* =========================
   3. SUPERVISORS TABLE
========================= */
$sql = "CREATE TABLE IF NOT EXISTS supervisors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    fullname VARCHAR(255) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    company VARCHAR(255) DEFAULT NULL,
    contact_number VARCHAR(20) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES isfinals(id)
        ON DELETE CASCADE
)";
runQuery($connection, $sql);
// Add missing columns to supervisors table if they don't exist
runQuery($connection, "ALTER TABLE supervisors ADD COLUMN IF NOT EXISTS fullname VARCHAR(255) DEFAULT NULL");
runQuery($connection, "ALTER TABLE supervisors ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL");
runQuery($connection, "ALTER TABLE supervisors ADD COLUMN IF NOT EXISTS company VARCHAR(255) DEFAULT NULL");
runQuery($connection, "ALTER TABLE supervisors MODIFY COLUMN contact_number VARCHAR(20) DEFAULT NULL");

/* =========================
   ADD SUPERVISOR ASSIGNMENT
========================= */
runQuery($connection, "ALTER TABLE students ADD COLUMN IF NOT EXISTS supervisor_id INT DEFAULT NULL");
runQuery($connection, "ALTER TABLE students ADD CONSTRAINT fk_supervisor_id FOREIGN KEY (supervisor_id) REFERENCES supervisors(id) ON DELETE SET NULL");

/* =========================
   4. ATTENDANCE TABLE
========================= */
$sql = "CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    name VARCHAR(255),
    date DATE,
    time_in TIME,
    time_out TIME,
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',

    FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE
)";
runQuery($connection, $sql);

/* =========================
   4. NARRATIVE REPORTS
========================= */
$sql = "CREATE TABLE IF NOT EXISTS narrative_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(255),
    content TEXT,
    hours INT DEFAULT 0,
    date DATE,

    FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE
)";
runQuery($connection, $sql);

/* =========================
   5. ANNOUNCEMENTS
========================= */
$sql = "CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    content TEXT,
    date DATE
)";
runQuery($connection, $sql);

/* =========================
   6. STUDENT CONCERNS TABLE
========================= */
$sql = "CREATE TABLE IF NOT EXISTS student_concerns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    concern_type VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    date_submitted DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Pending', 'Reviewed', 'Resolved') DEFAULT 'Pending',
    teacher_response TEXT,
    response_date DATETIME,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
)";
runQuery($connection, $sql);

/* =========================
   7. INSERT USERS (SECURE)
========================= */
$supervisor_pass = password_hash("123", PASSWORD_DEFAULT);
$student_pass = password_hash("123", PASSWORD_DEFAULT);

/* =========================
   INSERT USERS
========================= */
runQuery($connection, "
INSERT INTO isfinals (fullname, email, username, password, role)
VALUES ('Cole Supervisor', 'cole@gmail.com', 'cole', '$supervisor_pass', 'owner_supervisor')
ON DUPLICATE KEY UPDATE email=email
");
$supervisor_result = mysqli_query($connection, "SELECT id FROM isfinals WHERE email='cole@gmail.com' LIMIT 1");
if ($supervisor_result && mysqli_num_rows($supervisor_result) > 0) {
    $supervisor_row = mysqli_fetch_assoc($supervisor_result);
    $supervisor_user_id = $supervisor_row['id'];
    runQuery($connection, "INSERT INTO supervisors (user_id, contact_number) VALUES ('$supervisor_user_id', NULL) ON DUPLICATE KEY UPDATE contact_number=contact_number");
}
runQuery($connection, "
INSERT INTO isfinals (fullname, email, username, password, role)
VALUES ('Sample Student', 'student@example.com', 'student', '$student_pass', 'student')
ON DUPLICATE KEY UPDATE email=email
");

/* =========================
   GET STUDENT USER ID
========================= */
$result = mysqli_query(
    $connection,
    "SELECT id FROM isfinals WHERE email='student@example.com' LIMIT 1"
);

$row = mysqli_fetch_assoc($result);
$student_user_id = $row['id'] ?? null;

/* =========================
   INSERT STUDENT RECORD
========================= */
if ($student_user_id) {

    runQuery($connection, "
    INSERT INTO students 
    (user_id, fullname, email, username, requirements, uploaded_files)
    VALUES (
        '$student_user_id',
        'Sample Student',
        'student@example.com',
        'student',
        '[]',
        '[]'
    )
    ON DUPLICATE KEY UPDATE user_id=user_id
    ");
}
echo "<h3>Database setup complete!</h3>";
?>