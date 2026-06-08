<?php
require_once '../includes/db.php';

$token = $_GET['token'] ?? '';
$type = $_GET['type'] ?? '';
$message = '';
$status = 'error';

if (empty($token) || empty($type)) {
    $message = "Invalid verification request.";
} else {
    $table = ($type == 'shop') ? 'shop_owners' : 'customers';
    
    // Check token
    $stmt = $pdo->prepare("SELECT id FROM $table WHERE verification_token = ? AND is_verified = 0");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        // Verify the user
        $update = $pdo->prepare("UPDATE $table SET is_verified = 1, verification_token = NULL WHERE id = ?");
        if ($update->execute([$user['id']])) {
            $status = 'success';
            $message = "Email verified successfully! You can now login to your account.";
        } else {
            $message = "Something went wrong. Please try again.";
        }
    } else {
        $message = "Link is invalid or has already been used.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8f9fc; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .verify-card { background: #fff; padding: 40px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); text-align: center; max-width: 400px; }
        .icon-box { width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 30px; margin: 0 auto 20px; }
        .icon-success { background: #ecfdf5; color: #059669; }
        .icon-error { background: #fef2f2; color: #dc2626; }
        .btn-login { background: #2563eb; color: #fff; border-radius: 10px; padding: 12px 30px; font-weight: 600; text-decoration: none; display: inline-block; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="verify-card">
        <div class="icon-box <?= $status == 'success' ? 'icon-success' : 'icon-error' ?>">
            <i class="fas <?= $status == 'success' ? 'fa-check' : 'fa-times' ?>"></i>
        </div>
        <h4 class="fw-bold mb-3"><?= $status == 'success' ? 'Verified!' : 'Oops!' ?></h4>
        <p class="text-muted"><?= $message ?></p>
        
        <?php if($status == 'success'): ?>
            <a href="login.php?type=<?= $type ?>" class="btn-login">Go to Login</a>
        <?php else: ?>
            <a href="../index.php" class="btn-login" style="background: #64748b;">Back to Home</a>
        <?php endif; ?>
    </div>
</body>
</html>