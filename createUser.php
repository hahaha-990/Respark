<?php
require_once 'config.php';
// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = strtoupper(trim($_POST['studentId'] ?? ''));
    $fullname = trim($_POST['fullName'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $langPref = in_array($_POST['lang_pref'] ?? 'ms', ['ms','en']) ? $_POST['lang_pref'] : 'ms';
    $notifyPush = isset($_POST['notify_push']) ? 1 : 0;
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Validation
    if (empty($studentId) || empty($fullname) || empty($email) || empty($password) || empty($confirm_password) || empty($phone)) {
        $error = 'All fields are required.';
    } elseif (!preg_match('/^[A-Z0-9\-]{3,20}$/', $studentId)) {
        $error = 'Student ID must be 3-20 characters (uppercase alphanumeric and hyphens only).';
    } elseif (!preg_match('/^[a-zA-Z\s\']{2,100}$/', $fullname)) {
        $error = 'Full name must contain only letters and spaces.';
    } elseif (strlen($phone) < 7 || strlen($phone) > 20 || !preg_match('/^[0-9\+\-\s]+$/', $phone)) {
        $error = 'Phone number must be 7-20 digits and may include +, -, or spaces.';
    } elseif (strlen($email) > 254) {
        $error = 'Email is too long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{6,}$/', $password)) {
        $error = 'Password must contain at least one uppercase letter, one lowercase letter, one digit, and one special character.';
    }

    // Insert into database if no errors
    if (empty($error)) {
        try {
            $pdo = db();
            
            // Check if student ID or email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ? OR email = ?");
            $checkStmt->execute([$studentId, $email]);
            $checkResult = $checkStmt->fetchAll();

            if (count($checkResult) > 0) {
                $error = 'Student ID or email already exists.';
            } else {
                // Hash password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // Insert new user
                $insertStmt = $pdo->prepare("INSERT INTO users (user_id, full_name, email, password_hash, role, phone, lang_pref, notify_push) VALUES (?, ?, ?, ?, 'student', ?, ?, ?)");
                $insertResult = $insertStmt->execute([$studentId, $fullname, $email, $hashedPassword, $phone, $langPref, $notifyPush]);

                if ($insertResult) {
                    $success = 'Account created successfully! Redirecting to login...';
                    header("refresh:2;url=index.php");
                } else {
                    $error = 'Error creating account. Please try again.';
                }
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        button { width: 100%; padding: 10px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background-color: #0056b3; }
        .error { color: red; margin-bottom: 15px; }
        .success { color: green; margin-bottom: 15px; }
    </style>
</head>
<body>
    <h2>Create Account</h2>
    
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label for="studentId">Student ID:</label>
            <input type="text" id="studentId" name="studentId" placeholder="e.g., A22EC0001" value="<?php echo htmlspecialchars($_POST['studentId'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label for="fullName">Full Name:</label>
            <input type="text" id="fullName" name="fullName" placeholder="e.g., Ahmad bin Hassan" value="<?php echo htmlspecialchars($_POST['fullName'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label for="email">Email:</label>
            <input type="email" id="email" name="email" placeholder="e.g., ahmad@gmail.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label for="phone">Phone Number:</label>
            <input type="text" id="phone" name="phone" placeholder="e.g., 0123456789" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label for="lang_pref">Preferred Language:</label>
            <select id="lang_pref" name="lang_pref" required>
                <option value="ms" <?php echo (($_POST['lang_pref'] ?? 'ms') === 'ms') ? 'selected' : ''; ?>>Bahasa Malaysia</option>
                <option value="en" <?php echo (($_POST['lang_pref'] ?? '') === 'en') ? 'selected' : ''; ?>>English</option>
            </select>
        </div>

        <div class="form-group" style="display:flex;align-items:center;gap:.5rem;">
            <input type="checkbox" id="notify_push" name="notify_push" value="1" <?php echo isset($_POST['notify_push']) ? 'checked' : ''; ?>>
            <label for="notify_push" style="margin:0;">Enable push notifications</label>
        </div>
        
        <div class="form-group">
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>
        </div>
        
        <div class="form-group">
            <label for="confirm_password">Confirm Password:</label>
            <input type="password" id="confirm_password" name="confirm_password" required>
        </div>
        
        <button type="submit">Register</button>
        <p>Password must be at least 6 characters.</p>
        <p>Password must contain 1 uppercase letter, 1 lowercase letter, 1 digit, and 1 symbol.</p>
    </form>
    <p>Already have an account? <a href="index.php">Login here</a></p>
</body>
</html>