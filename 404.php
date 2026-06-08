<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found | KhataLink</title>
    <meta name="robots" content="noindex, follow">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/favicon.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f8f9fc;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .navbar {
            background: #ffffff;
            border-bottom: 1px solid #e8ecf0;
            padding: 18px 40px;
        }
        .error-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            text-align: center;
        }
        .error-content {
            max-width: 600px;
        }
        .error-code {
            font-size: 120px;
            font-weight: 800;
            color: #2563eb;
            line-height: 1;
            margin-bottom: 10px;
            opacity: 0.1;
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            z-index: -1;
        }
        .error-title {
            font-size: 32px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 16px;
        }
        .error-text {
            font-size: 16px;
            color: #64748b;
            margin-bottom: 32px;
            line-height: 1.6;
        }
        .btn-home {
            background: #2563eb;
            color: #ffffff;
            font-weight: 600;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .btn-home:hover {
            background: #1d4ed8;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37,99,235,0.2);
        }
        .footer {
            text-align: center;
            padding: 24px;
            font-size: 12px;
            color: #94a3b8;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="container-fluid justify-content-center">
        <a href="index.php">
            <img src="https://i.ibb.co/3YCLw5T4/8e482bd2-edc3-46e3-ac52-28257d35d533.png" alt="KhataLink Logo" style="height: 40px;">
        </a>
    </div>
</nav>

<section class="error-section">
    <div class="error-content position-relative">
        <div class="error-code">404</div>
        <h1 class="error-title">Oops! Page Not Found</h1>
        <p class="error-text">
            The page you are looking for might have been removed, had its name changed, or is temporarily unavailable. 
            Don't worry, your credit records are safe!
        </p>
        <a href="index.php" class="btn-home">
            <i class="fas fa-home"></i> Back to Homepage
        </a>
    </div>
</section>

<div class="footer">
    © 2025 KhataLink — Digital Credit Management
</div>

</body>
</html>