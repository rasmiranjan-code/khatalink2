<?php
session_start();

if(isset($_SESSION['customer_id'])) {
    header("Location: customer/dashboard.php");
    exit();
}
if(isset($_SESSION['shop_id'])) {
    header("Location: shop/dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KhataLink | India's Best Digital Udhar Khata Book & Shop Credit Manager</title>
    <meta name="description" content="KhataLink is a powerful digital credit management platform designed for Indian shopkeepers and customers. Replace your paper khata with our secure digital ledger to track udhar and payments.">
    <meta name="keywords" content="KhataLink, Digital Khata, Udhar Book, Credit Management, Shop Management India, Digital Ledger, Business Accounting App">
    <meta name="author" content="KhataLink">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/favicon.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8f9fc;
            min-height: 100vh;
        }

        /* Navbar */
        .navbar {
            background: #ffffff;
            border-bottom: 1px solid #e8ecf0;
            padding: 18px 40px;
        }

        .navbar-brand {
            font-size: 24px;
            font-weight: 800;
            color: #1a1a2e;
            letter-spacing: -0.5px;
        }

        .navbar-brand span {
            color: #2563eb;
        }

        .nav-tag {
            background: #eff6ff;
            color: #2563eb;
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 4px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        /* Hero */
        .hero-section {
            background: #ffffff;
            border-bottom: 1px solid #e8ecf0;
            padding: 60px 0;
        }

        .hero-label {
            display: inline-block;
            background: #eff6ff;
            color: #2563eb;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 4px;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 24px;
        }

        .hero-title {
            font-size: 48px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -1.5px;
            line-height: 1.15;
            margin-bottom: 16px;
        }

        .hero-title span {
            color: #2563eb;
        }

        .hero-subtitle {
            font-size: 17px;
            color: #64748b;
            font-weight: 400;
            line-height: 1.7;
            max-width: 100%;
            margin-bottom: 40px;
        }

        .hero-lottie {
            width: 100%;
            height: 450px;
            border: none;
        }

        /* Role Cards */
        .cards-section {
            padding: 60px 40px;
            max-width: 900px;
            margin: 0 auto;
        }

        .section-label {
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            color: #94a3b8;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 30px;
        }

        .role-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 36px;
            text-decoration: none;
            display: block;
            transition: all 0.25s ease;
            height: 100%;
        }

        .role-card:hover {
            border-color: #2563eb;
            box-shadow: 0 8px 30px rgba(37,99,235,0.12);
            transform: translateY(-2px);
            text-decoration: none;
        }

        .role-card.shop:hover {
            border-color: #059669;
            box-shadow: 0 8px 30px rgba(5,150,105,0.12);
        }

        .card-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 20px;
        }

        .customer .card-icon-wrap {
            background: #eff6ff;
            color: #2563eb;
        }

        .shop .card-icon-wrap {
            background: #ecfdf5;
            color: #059669;
        }

        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 10px;
        }

        .card-desc {
            font-size: 14px;
            color: #64748b;
            line-height: 1.7;
            margin-bottom: 24px;
        }

        .card-features {
            list-style: none;
            padding: 0;
            margin: 0 0 28px 0;
        }

        .card-features li {
            font-size: 13px;
            color: #475569;
            padding: 6px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #f1f5f9;
        }

        .card-features li:last-child {
            border-bottom: none;
        }

        .card-features li i {
            font-size: 11px;
            color: #2563eb;
        }

        .shop .card-features li i {
            color: #059669;
        }

        .card-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .customer .card-btn {
            background: #2563eb;
            color: #ffffff;
        }

        .shop .card-btn {
            background: #059669;
            color: #ffffff;
        }

        .role-card:hover .card-btn {
            opacity: 0.9;
        }

        /* Stats */
        .stats-section {
            background: #ffffff;
            border-top: 1px solid #e8ecf0;
            border-bottom: 1px solid #e8ecf0;
            padding: 30px 40px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -1px;
        }

        .stat-label {
            font-size: 13px;
            color: #94a3b8;
            margin-top: 4px;
            font-weight: 500;
        }

        .stat-divider {
            width: 1px;
            height: 40px;
            background: #e2e8f0;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 30px;
            font-size: 13px;
            color: #94a3b8;
        }

        @media (max-width: 768px) {
            .hero-lottie { height: 280px !important; }
            .hero-section { text-align: center; padding: 40px 20px; }
            .hero-title { font-size: 28px; }
            .hero-subtitle { margin-bottom: 30px; }

            .stats-section { padding: 20px; }
            .stats-section .d-flex { 
                flex-wrap: wrap; 
                gap: 1.5rem !important; 
            }
            .stat-divider { display: none; }
            .stat-number { font-size: 20px; }
            .stat-label { font-size: 11px; }

            .hero-title { font-size: 32px; }
            .navbar { padding: 16px 20px; }
            .hero-section { padding: 50px 20px; }
            .cards-section { padding: 40px 20px; }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar d-flex align-items-center justify-content-between">
    <a href="../index.php" class="kl-logo">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo" style="height: 50px;">
        </a>
    <div class="nav-tag">Manage Your Credit</div>
</nav>

<!-- Hero -->
<section class="hero-section">
    <div class="container">
        <div class="row align-items-center">
            <!-- Left: Animation -->
            <div class="col-md-6 mb-4 mb-md-0">
                <iframe src="https://lottie.host/embed/27906ec0-b5f5-4767-bb37-dd419fd33c8e/ZDTTsxyoxg.lottie" class="hero-lottie"></iframe>
            </div>
            <!-- Right: Content -->
            <div class="col-md-6">
                <div class="hero-label">Digital Credit Management</div>
                <h1 class="hero-title">
                    Your Shop's Credit Records,<br>
                    <span>Managed Digitally</span>
                </h1>
                <p class="hero-subtitle">
                    A smart platform for Indian shopkeepers and customers 
                    to manage credit, payments, and records — all in one place.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Stats -->
<section class="stats-section">
    <div class="d-flex align-items-center justify-content-center gap-5">
        <div class="stat-item">
            <div class="stat-number">100%</div>
            <div class="stat-label">Trusted & Verified</div>
        </div>
        <div class="stat-divider"></div>
        <div class="stat-item">
            <div class="stat-number">Secure</div>
            <div class="stat-label">Data Protected</div>
        </div>
        <div class="stat-divider"></div>
        <div class="stat-item">
            <div class="stat-number">Real Time</div>
            <div class="stat-label">Instant Updates</div>
        </div>
        <div class="stat-divider"></div>
        <div class="stat-item">
            <div class="stat-number">Multi Shop</div>
            <div class="stat-label">All in One Place</div>
        </div>
    </div>
</section>

<!-- Cards -->
<section class="cards-section">
    <div class="section-label">Select Your Role to Continue</div>
    <div class="row g-4">

        <!-- Customer -->
        <div class="col-md-6">
            <a href="auth/login.php?type=customer" class="role-card customer">
                <div class="card-icon-wrap">
                    <i class="fas fa-user"></i>
                </div>
                <div class="card-title">I am a Customer</div>
                <div class="card-desc">
                    View and track all your credit across 
                    multiple shops from one dashboard.
                </div>
                <ul class="card-features">
                    <li><i class="fas fa-check"></i> View credit from all shops</li>
                    <li><i class="fas fa-check"></i> Track full payment history</li>
                    <li><i class="fas fa-check"></i> Report any incorrect entries</li>
                    <li><i class="fas fa-check"></i> Unique Customer ID</li>
                </ul>
                <div class="card-btn">
                    Login as Customer
                    <i class="fas fa-arrow-right"></i>
                </div>
            </a>
        </div>

        <!-- Shop Owner -->
        <div class="col-md-6">
            <a href="auth/login.php?type=shop" class="role-card shop">
                <div class="card-icon-wrap">
                    <i class="fas fa-store"></i>
                </div>
                <div class="card-title">I am a Shop Owner</div>
                <div class="card-desc">
                    Manage all customer credit, payments 
                    and records digitally without paper.
                </div>
                <ul class="card-features">
                    <li><i class="fas fa-check"></i> Add & manage customers</li>
                    <li><i class="fas fa-check"></i> Custom fields for your shop</li>
                    <li><i class="fas fa-check"></i> Track payments & dues</li>
                    <li><i class="fas fa-check"></i> View customer reports</li>
                </ul>
                <div class="card-btn">
                    Login as Shop Owner
                    <i class="fas fa-arrow-right"></i>
                </div>
        </a>
        </div>

    </div>
</section>

<!-- Footer -->
<div class="footer">
    <div class="footer-links mb-3">
        <a href="about.php" class="text-decoration-none px-2" style="color: #64748b; font-weight: 600;">About Us</a>
        <span style="color: #e2e8f0;">|</span>
        <a href="privacy-policy.php" class="text-decoration-none px-2" style="color: #64748b; font-weight: 600;">Privacy Policy</a>
        <span style="color: #e2e8f0;">|</span>
        <a href="terms.php" class="text-decoration-none px-2" style="color: #64748b; font-weight: 600;">Terms & Conditions</a>
        <span style="color: #e2e8f0;">|</span>
        <a href="grievance.php" class="text-decoration-none px-2" style="color: #64748b; font-weight: 600;">Grievance</a>
    </div>
    © 2025 KhataLink — Digital Credit Management Platform for India
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>