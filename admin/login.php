<?php
session_start();
require_once '../includes/db.php';

if(isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_name'] = $admin['name'];
        error_log("LOGIN_SUCCESS: User ID: " . $admin['id'] . ", Type: admin, IP: " . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ---- NAVBAR ---- */
        .kl-navbar {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0 32px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }

        .kl-logo {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            text-decoration: none;
            letter-spacing: -0.5px;
        }

        .kl-logo span { color: #2563eb; }

        .kl-nav-badge {
            background: #fef2f2;
            color: #dc2626;
            font-size: 11px;
            font-weight: 700;
            padding: 5px 14px;
            border-radius: 6px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border: 1px solid #fecaca;
        }

        /* ---- MAIN ---- */
        .main-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .login-container {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            max-width: 1100px;
            gap: 60px;
        }

        .login-visual {
            display: none;
            flex: 1;
            max-width: 500px;
        }

        /* ---- LOGIN BOX ---- */
        .login-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 44px 40px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06);
        }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            font-size: 11px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 6px;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin-bottom: 22px;
        }

        .form-title {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
        }

        .form-subtitle {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 30px;
        }

        /* ---- FORM ELEMENTS ---- */
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-control {
            width: 100%;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 13px 16px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            color: #0f172a;
            background: #ffffff;
            transition: all 0.2s;
            outline: none;
        }

        .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
        }

        .form-control::placeholder { color: #94a3b8; }

        .input-group {
            display: flex;
        }

        .input-group .form-control {
            border-radius: 10px 0 0 10px;
            border-right: none;
        }

        .input-suffix {
            border: 1.5px solid #e2e8f0;
            border-left: none;
            border-radius: 0 10px 10px 0;
            padding: 0 16px;
            background: #ffffff;
            display: flex;
            align-items: center;
            color: #94a3b8;
            cursor: pointer;
            transition: color 0.2s;
            font-size: 15px;
        }

        .input-suffix:hover { color: #2563eb; }

        .mb-field { margin-bottom: 20px; }

        /* ---- ERROR ---- */
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            border-radius: 10px;
            padding: 13px 16px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ---- BUTTON ---- */
        .btn-login {
            width: 100%;
            background: #dc2626;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-size: 15px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }

        .btn-login:hover {
            background: #b91c1c;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(220,38,38,0.25);
        }

        /* ---- SECURE NOTE ---- */
        .secure-note {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 13px 16px;
            font-size: 12px;
            color: #64748b;
            margin-top: 20px;
            line-height: 1.5;
        }

        .secure-note i {
            color: #059669;
            font-size: 16px;
            flex-shrink: 0;
        }

        /* ---- FOOTER ---- */
        .kl-footer {
            text-align: center;
            padding: 22px;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            background: #ffffff;
        }

        /* ---- RESPONSIVE ---- */
        @media (max-width: 576px) {
            .kl-navbar { padding: 0 20px; }
            .login-box { padding: 28px 22px; border-radius: 16px; }
            .form-title { font-size: 22px; }
            .login-visual { display: none !important; }
        }

        @media (min-width: 992px) {
            .login-visual { display: block; }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="kl-navbar">
    <a href="../index.php" class="kl-logo">
        <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo" style="height: 50px;">
    </a>
    <div class="kl-nav-badge">
        <i class="fas fa-shield-alt"></i> Admin Panel
    </div>
</nav>

<!-- Main -->
<div class="main-wrapper">
    <div class="login-container">
        <div class="login-visual">
            <iframe src="https://lottie.host/embed/071477c4-0eb1-4852-b49d-1764ee63c988/NXXkmWmGiS.lottie" style="width: 100%; height: 500px; border: none;"></iframe>
        </div>

    <div class="login-box">

        <div class="admin-badge">
            <i class="fas fa-shield-alt"></i>
            Restricted Area
        </div>

        <div class="form-title">Admin Login</div>
        <div class="form-subtitle">Authorized personnel only</div>

        <?php if($error): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST">

            <div class="mb-field">
                <label class="form-label">Email Address</label>
                <input
                    type="email"
                    name="email"
                    class="form-control"
                    placeholder="Enter admin email"
                    required
                    value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
            </div>

            <div class="mb-field">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <input
                        type="password"
                        name="password"
                        class="form-control"
                        placeholder="Enter admin password"
                        id="passwordField"
                        required>
                    <span class="input-suffix" onclick="togglePass()">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </span>
                </div>
            </div>

            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i>
                Login to Admin Panel
            </button>

        </form>

        <div class="secure-note">
            <i class="fas fa-lock"></i>
            This area is monitored and restricted to authorized users only. Unauthorized access is prohibited.
        </div>

    </div>
    </div>
</div>

<!-- Footer -->
<div class="kl-footer">
    © 2025 KhataLink — Admin Panel
</div>

<script>
    function togglePass() {
        const field = document.getElementById('passwordField');
        const icon = document.getElementById('eyeIcon');
        if (field.type === 'password') {
            field.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            field.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }
</script>

</body>
</html>