<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once '../includes/db.php';
require_once '../includes/notification_service.php';

// ===== FLUTTER API =====
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);
    $email = trim($data['email'] ?? '');
    $password = trim($data['password'] ?? '');
    $type = trim($data['type'] ?? 'customer');
    $fcm_token = $data['fcm_token'] ?? null;
    $device_type = $data['device_type'] ?? 'android';
    $truecaller_phone = trim($data['truecaller_phone'] ?? '');

    // Truecaller Login via App
    if(!empty($truecaller_phone)) {
        $table = ($type == 'shop' || $type == 'restaurant') ? 'shop_owners' : (($type == 'delivery') ? 'delivery_partners' : 'customers');
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE phone = ?");
        $stmt->execute([$truecaller_phone]);
        $user = $stmt->fetch();

        if($user) {
            $pdo->prepare("UPDATE $table SET truecaller_verified = 1 WHERE id = ?")->execute([$user['id']]);
            updateFCMToken($pdo, $user['id'], $type, $fcm_token, $device_type);
            error_log("LOGIN_SUCCESS: User ID: " . $user['id'] . ", Type: " . $type . " (Truecaller), IP: " . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));

            $resp = [
                'success' => true,
                'role' => $type,
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'token' => generate_secure_token($user['id'], $user['email'], $type)
            ];
            if($type === 'shop' || $type === 'restaurant') $resp['shop_name'] = $user['shop_name'];
            echo json_encode($resp);
        } else {
            echo json_encode(['success' => false, 'message' => 'No account found with this phone number.']);
        }
        exit();
    }

    // Email/Password Login via App
    if($type == 'customer') {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if($user && password_verify($password, $user['password'])) {
            if($user['is_verified'] == 0) {
                echo json_encode(['success' => false, 'message' => 'Please verify your email first.']);
            } else {
                updateFCMToken($pdo, $user['id'], 'customer', $fcm_token, $device_type);
                error_log("LOGIN_SUCCESS: User ID: " . $user['id'] . ", Type: customer, IP: " . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
                sendKhataPush($pdo, $user['id'], 'customer', 'Login Successful', "Welcome back Mr/Mrs " . $user['name'] . "! Aapka digital khata surakshit hai.");
                echo json_encode([
                    'success' => true,
                    'role' => 'customer',
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'token' => generate_secure_token($user['id'], $user['email'], 'customer')
                ]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        }

    } elseif ($type == 'shop' || $type == 'restaurant') {
        $stmt = $pdo->prepare("SELECT * FROM shop_owners WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if($user && password_verify($password, $user['password'])) {
            if($user['is_verified'] == 0) {
                echo json_encode(['success' => false, 'message' => 'Shop account not verified.']);
            } else {
                updateFCMToken($pdo, $user['id'], 'shop', $fcm_token, $device_type);
                error_log("LOGIN_SUCCESS: User ID: " . $user['id'] . ", Type: shop, IP: " . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
                sendKhataPush($pdo, $user['id'], 'shop', 'Login Successful', "Namaste " . $user['shop_name'] . "! Business dashboard par aapka swagat hai.");
                $role_redirect = ($user['shop_type'] == 'restaurant') ? 'restaurant' : 'shop';
                echo json_encode([
                    'success' => true,
                    'role' => $role_redirect,
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'shop_name' => $user['shop_name'],
                    'email' => $user['email'],
                    'token' => generate_secure_token($user['id'], $user['email'], 'shop')
                ]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        }

    } elseif ($type == 'delivery') {
        $stmt = $pdo->prepare("SELECT * FROM delivery_partners WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if($user && password_verify($password, $user['password'])) {
            if((int)$user['is_verified'] === 0) {
                echo json_encode(['success' => false, 'message' => 'Your account is pending approval. Please wait for admin verification.']);
            } else {
                updateFCMToken($pdo, $user['id'], 'delivery', $fcm_token, $device_type);
                error_log("LOGIN_SUCCESS: User ID: " . $user['id'] . ", Type: delivery, IP: " . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
                sendKhataPush($pdo, $user['id'], 'delivery', 'Login Successful', "Hello Partner! Aaj ke orders ke liye taiyar rahein.");
                echo json_encode([
                    'success' => true,
                    'role' => 'delivery',
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'token' => generate_secure_token($user['id'], $user['email'], 'delivery')
                ]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        }
    }
    exit();
}
// ===== END FLUTTER API =====

// ===== TRUECALLER LOGIN HANDLER (WEB) =====
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['truecaller_login_phone'])) {
    $phone = $_POST['truecaller_login_phone'];
    $type_tc = $_POST['type'] ?? 'customer';

    $table = ($type_tc == 'shop' || $type_tc == 'restaurant') ? 'shop_owners' : (($type_tc == 'delivery') ? 'delivery_partners' : 'customers');
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE phone = ?");
    $stmt->execute([$phone]);
    $user = $stmt->fetch();

    if($user) {
        $pdo->prepare("UPDATE $table SET truecaller_verified = 1 WHERE id = ?")->execute([$user['id']]);
        error_log("LOGIN_SUCCESS: User ID: " . $user['id'] . ", Type: " . $type_tc . " (Web-Truecaller), IP: " . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));

        if($type_tc == 'customer') {
            $_SESSION['customer_id'] = $user['id'];
            $_SESSION['customer_name'] = $user['name'];
            header("Location: ../customer/dashboard.php");
        } elseif($type_tc == 'shop' || $type_tc == 'restaurant') {
            $_SESSION['shop_id'] = $user['id'];
            $_SESSION['shop_name'] = $user['shop_name'];
            header("Location: ../shop/dashboard.php");
        } elseif($type_tc == 'delivery') {
            $_SESSION['delivery_id'] = $user['id'];
            $_SESSION['delivery_name'] = $user['name'];
            header("Location: ../delivery/dashboard.php");
        }
        exit();
    } else {
        $error = "Is phone number se koi account nahi mila. Kripya register karein.";
    }
}

// ===== WEB LOGIN =====
$type = isset($_GET['type']) ? $_GET['type'] : 'customer';
$error = '';

if($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['truecaller_login_phone'])) {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $type     = $_POST['type'] ?? 'customer';
    $lat      = !empty($_POST['latitude'])  ? $_POST['latitude']  : null;
    $lng      = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
    $fcm_token   = $_POST['fcm_token'] ?? null;

    if($type == 'customer') {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if($user && password_verify($password, $user['password'])) {
            if(isset($user['is_verified']) && $user['is_verified'] == 0) {
                $error = "Please verify your email address first. Check your inbox for the link.";
            } else {
                $_SESSION['customer_id']   = $user['id'];
                $_SESSION['customer_name'] = $user['name'];
                if($lat && $lng) {
                    $pdo->prepare("UPDATE customers SET latitude = ?, longitude = ? WHERE id = ?")->execute([$lat, $lng, $user['id']]);
                }
                error_log("LOGIN_SUCCESS: User ID: " . $user['id'] . ", Type: customer (Web), IP: " . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
                updateFCMToken($pdo, $user['id'], 'customer', $fcm_token, 'web');
                sendKhataPush($pdo, $user['id'], 'customer', 'Login Notification', "Welcome back " . $user['name'] . "! KhataLink par aapka firse swagat hai.");
                header("Location: ../customer/dashboard.php");
                exit();
            }
        } else {
            $error = "Invalid email or password.";
        }

    } elseif ($type == 'shop' || $type == 'restaurant') {
        $stmt = $pdo->prepare("SELECT * FROM shop_owners WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if($user && password_verify($password, $user['password'])) {
            if(isset($user['is_verified']) && $user['is_verified'] == 0) {
                $error = "Shop account not verified. Please check your email.";
            } else {
                $_SESSION['shop_id']   = $user['id'];
                $_SESSION['shop_name'] = $user['shop_name'];
                if($lat && $lng) {
                    $pdo->prepare("UPDATE shop_owners SET latitude = ?, longitude = ? WHERE id = ?")->execute([$lat, $lng, $user['id']]);
                }
                error_log("LOGIN_SUCCESS: User ID: " . $user['id'] . ", Type: shop (Web), IP: " . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
                updateFCMToken($pdo, $user['id'], 'shop', $fcm_token, 'web');
                sendKhataPush($pdo, $user['id'], 'shop', 'Login Successful', "Namaste " . $user['shop_name'] . "! Digital khata management shuru karein.");
                $target = ($user['shop_type'] == 'restaurant') ? 'restaurant_dashboard.php' : 'dashboard.php';
                header("Location: ../shop/$target");
                exit();
            }
        } else {
            $error = "Invalid email or password.";
        }

    } elseif ($type == 'delivery') {
        $stmt = $pdo->prepare("SELECT * FROM delivery_partners WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if($user && password_verify($password, $user['password'])) {
            if((int)$user['is_verified'] === 0) {
                $error = "Account Pending Approval: Your registration is under review. You will receive access once admin verifies your identity.";
            } else {
                $_SESSION['delivery_id']   = $user['id'];
                $_SESSION['delivery_name'] = $user['name'];
                if($lat && $lng) {
                    $pdo->prepare("UPDATE delivery_partners SET latitude = ?, longitude = ? WHERE id = ?")->execute([$lat, $lng, $user['id']]);
                }
                error_log("LOGIN_SUCCESS: User ID: " . $user['id'] . ", Type: delivery (Web), IP: " . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
                updateFCMToken($pdo, $user['id'], 'delivery', $fcm_token, 'web');
                sendKhataPush($pdo, $user['id'], 'delivery', 'Login Alert', "Partner Dashboard active ho chuka hai.");
                header("Location: ../delivery/dashboard.php");
                exit();
            }
        } else {
            $error = "Invalid email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $type == 'restaurant' ? 'Kitchen Login — KhataLink' : 'Login — KhataLink' ?></title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/style.css" rel="stylesheet">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        /* =============================================
           BASE THEME (Default: Blue / KhataLink)
        ============================================= */
        :root {
            --primary:            #2563eb;
            --primary-dark:       #1d4ed8;
            --accent:             #10b981;
            --bg-body:            #f1f5f9;
            --card-bg:            #ffffff;
            --card-border:        #e2e8f0;
            --navbar-bg:          #ffffff;
            --navbar-border:      #e2e8f0;
            --toggle-bg:          #f1f5f9;
            --toggle-active-bg:   #ffffff;
            --toggle-active-clr:  #0f172a;
            --input-bg:           #ffffff;
            --input-border:       #e2e8f0;
            --input-focus-border: #2563eb;
            --input-focus-shadow: rgba(37,99,235,0.08);
            --label-color:        #374151;
            --text-dark:          #0f172a;
            --text-muted:         #94a3b8;
            --footer-color:       #94a3b8;
            --footer-bg:          #ffffff;
            --footer-border:      #e2e8f0;
            --divider-color:      #e2e8f0;
            --btn-login-bg:       #2563eb;
            --btn-login-hover:    #1d4ed8;
            --btn-login-shadow:   rgba(37,99,235,0.30);
            --btn-reg-bg:         #f8f9fc;
            --btn-reg-border:     #e2e8f0;
            --btn-reg-text:       #0f172a;
            --page-title-clr:     #0f172a;
            --page-sub-clr:       #64748b;
            --error-bg:           #fef2f2;
            --error-border:       #fecaca;
            --error-text:         #dc2626;
        }

        /* =============================================
           KITCHEN MODE — Full Zomato / Swiggy Dark Vibe
           (Mirrors register.php kitchen theme exactly)
        ============================================= */
        body.kitchen-mode {
            --primary:            #e8500a;
            --primary-dark:       #c9430a;
            --accent:             #1ba94c;
            --bg-body:            #1a0a00;
            --card-bg:            #1f1007;
            --card-border:        #3d1f08;
            --navbar-bg:          #140700;
            --navbar-border:      #e8500a;
            --toggle-bg:          #2b1108;
            --toggle-active-bg:   #e8500a;
            --toggle-active-clr:  #ffffff;
            --input-bg:           #2b1108;
            --input-border:       #5a2a10;
            --input-focus-border: #e8500a;
            --input-focus-shadow: rgba(232,80,10,0.15);
            --label-color:        #f4a87a;
            --text-dark:          #fff7f0;
            --text-muted:         #a07050;
            --footer-color:       #7a5030;
            --footer-bg:          #140700;
            --footer-border:      #2b1108;
            --divider-color:      #3d1f08;
            --btn-login-bg:       #e8500a;
            --btn-login-hover:    #c9430a;
            --btn-login-shadow:   rgba(232,80,10,0.40);
            --btn-reg-bg:         #2b1108;
            --btn-reg-border:     #5a2a10;
            --btn-reg-text:       #f4a87a;
            --page-title-clr:     #ffffff;
            --page-sub-clr:       #f4a87a;
            --error-bg:           rgba(232,80,10,0.10);
            --error-border:       #e8500a;
            --error-text:         #f4a87a;
        }

        /* Animated radial glow behind page in kitchen mode */
        body.kitchen-mode::before {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background:
                radial-gradient(ellipse 60% 40% at 80% 20%, rgba(232,80,10,0.18) 0%, transparent 70%),
                radial-gradient(ellipse 50% 35% at 20% 80%, rgba(27,169,76,0.12) 0%, transparent 70%),
                radial-gradient(ellipse 40% 30% at 50% 50%, rgba(232,80,10,0.07) 0%, transparent 80%);
            animation: kitchenGlow 6s ease-in-out infinite alternate;
        }

        @keyframes kitchenGlow {
            0%   { opacity: 0.7; }
            100% { opacity: 1;   }
        }

        /* Floating food emoji particles */
        body.kitchen-mode .food-particles { display: block !important; }

        .food-particles {
            display: none;
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .food-particles span {
            position: absolute;
            font-size: 22px;
            opacity: 0;
            animation: floatUp 8s linear infinite;
            user-select: none;
        }

        .food-particles span:nth-child(1) { left:  5%; animation-delay: 0s;   animation-duration:  9s; }
        .food-particles span:nth-child(2) { left: 15%; animation-delay: 1.5s; animation-duration:  7s; }
        .food-particles span:nth-child(3) { left: 28%; animation-delay: 3s;   animation-duration: 10s; }
        .food-particles span:nth-child(4) { left: 42%; animation-delay: 0.8s; animation-duration:  8s; }
        .food-particles span:nth-child(5) { left: 58%; animation-delay: 2.2s; animation-duration: 11s; }
        .food-particles span:nth-child(6) { left: 70%; animation-delay: 4s;   animation-duration: 7.5s; }
        .food-particles span:nth-child(7) { left: 82%; animation-delay: 1s;   animation-duration: 9.5s; }
        .food-particles span:nth-child(8) { left: 92%; animation-delay: 3.5s; animation-duration: 8.5s; }

        @keyframes floatUp {
            0%   { bottom: -40px; opacity: 0;    transform: rotate(-10deg) scale(0.8); }
            10%  {                opacity: 0.5; }
            80%  {                opacity: 0.35; }
            100% { bottom: 110vh; opacity: 0;    transform: rotate(15deg)  scale(1.1); }
        }

        /* =============================================
           GLOBAL SMOOTH TRANSITIONS
        ============================================= */
        body,
        .login-navbar,
        .login-box,
        .type-toggle,
        .toggle-btn,
        .form-control,
        .form-label,
        .btn-login,
        .btn-register,
        .page-title,
        .page-subtitle,
        .site-footer,
        .divider,
        .input-group-text {
            transition:
                background       0.5s ease,
                background-color 0.5s ease,
                border-color     0.4s ease,
                color            0.4s ease,
                box-shadow       0.4s ease;
        }

        /* =============================================
           LAYOUT
        ============================================= */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        /* =============================================
           NAVBAR
        ============================================= */
        .login-navbar {
            background: var(--navbar-bg);
            border-bottom: 1px solid var(--navbar-border);
            padding: 14px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            z-index: 10;
        }

        body.kitchen-mode .login-navbar {
            border-bottom: 2px solid var(--navbar-border);
            box-shadow: 0 2px 16px rgba(232,80,10,0.18);
        }

        .navbar-brand-logo { height: 50px; }

        .back-link {
            color: var(--text-muted);
            font-size: 13px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }

        .back-link:hover { color: var(--primary); }

        /* =============================================
           MAIN WRAPPER
        ============================================= */
        .main-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            position: relative;
            z-index: 1;
        }

        .login-container {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            max-width: 1100px;
            gap: 60px;
        }

        /* Lottie visual — desktop only */
        .login-visual {
            display: none;
            flex: 1;
            max-width: 480px;
        }

        @media (min-width: 992px) {
            .login-visual { display: block; }
        }

        /* =============================================
           LOGIN CARD
        ============================================= */
        .login-box {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06);
            position: relative;
            z-index: 2;
        }

        /* Orange-green top accent bar in kitchen mode */
        body.kitchen-mode .login-box::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #e8500a 0%, #f97316 50%, #1ba94c 100%);
            border-radius: 20px 20px 0 0;
        }

        /* =============================================
           PAGE TITLE & SUBTITLE
        ============================================= */
        .page-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--page-title-clr);
            letter-spacing: -0.5px;
            margin-bottom: 6px;
        }

        body.kitchen-mode .page-title {
            background: linear-gradient(135deg, #ff6b2b 0%, #f97316 40%, #1ba94c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            font-size: 14px;
            color: var(--page-sub-clr);
            margin-bottom: 28px;
        }

        /* =============================================
           GOOGLE SIGN-IN WRAPPER
        ============================================= */
        .google-wrapper {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--divider-color);
        }

        /* =============================================
           TRUECALLER BUTTON
        ============================================= */
        #truecaller-login-wrapper {
            margin-bottom: 16px;
        }

        .btn-truecaller {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--primary);
            color: #fff;
            padding: 12px 16px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: background 0.3s ease, box-shadow 0.3s ease;
        }

        .btn-truecaller:hover {
            background: var(--primary-dark);
            box-shadow: 0 4px 14px var(--btn-login-shadow);
        }

        /* =============================================
           TYPE TOGGLE TABS
        ============================================= */
        .type-toggle {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            background: var(--toggle-bg);
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 28px;
            gap: 4px;
            border: 1px solid var(--card-border);
        }

        .toggle-btn {
            padding: 9px 4px;
            border: none;
            background: transparent;
            border-radius: 9px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            flex-direction: column;
            line-height: 1.3;
        }

        .toggle-btn i { font-size: 14px; }

        .toggle-btn.active {
            background: var(--toggle-active-bg);
            color: var(--toggle-active-clr);
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
        }

        body.kitchen-mode .toggle-btn:not(.active):hover {
            color: #f4a87a;
        }

        /* Kitchen badge */
        .kitchen-badge {
            display: none;
            font-size: 8px;
            background: linear-gradient(90deg, #e8500a, #1ba94c);
            color: #fff;
            border-radius: 4px;
            padding: 1px 4px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        body.kitchen-mode .kitchen-badge { display: inline; }

        /* =============================================
           ALERT (ERROR)
        ============================================= */
        .alert-error {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error-text);
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* =============================================
           FORM LABELS & INPUTS
        ============================================= */
        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--label-color);
            margin-bottom: 6px;
            display: block;
        }

        .form-control {
            background: var(--input-bg);
            border: 1.5px solid var(--input-border);
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 14px;
            color: var(--text-dark);
            font-family: 'Inter', sans-serif;
            width: 100%;
        }

        .form-control::placeholder { color: var(--text-muted); }

        .form-control:focus {
            border-color: var(--input-focus-border);
            box-shadow: 0 0 0 3px var(--input-focus-shadow);
            outline: none;
        }

        /* Input group (password eye) */
        .input-group {
            display: flex;
            align-items: stretch;
        }

        .input-group .form-control {
            border-right: none;
            border-radius: 10px 0 0 10px;
            flex: 1;
        }

        .input-group-text {
            background: var(--input-bg);
            border: 1.5px solid var(--input-border);
            border-left: none;
            border-radius: 0 10px 10px 0;
            cursor: pointer;
            color: var(--text-muted);
            padding: 0 14px;
            display: flex;
            align-items: center;
        }

        .input-group-text:hover { color: var(--primary); }

        /* =============================================
           BUTTONS
        ============================================= */
        .btn-login {
            background: var(--btn-login-bg);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 13px;
            font-size: 15px;
            font-weight: 600;
            width: 100%;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            margin-top: 8px;
        }

        .btn-login:hover {
            background: var(--btn-login-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px var(--btn-login-shadow);
        }

        .btn-register {
            background: var(--btn-reg-bg);
            color: var(--btn-reg-text);
            border: 1.5px solid var(--btn-reg-border);
            border-radius: 10px;
            padding: 13px;
            font-size: 15px;
            font-weight: 600;
            width: 100%;
            font-family: 'Inter', sans-serif;
            text-decoration: none;
            display: block;
            text-align: center;
        }

        .btn-register:hover {
            background: var(--toggle-bg);
            color: var(--primary);
            border-color: var(--primary);
        }

        /* =============================================
           DIVIDER
        ============================================= */
        .divider {
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
            margin: 20px 0;
            position: relative;
        }

        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 42%;
            height: 1px;
            background: var(--divider-color);
        }

        .divider::before { left: 0; }
        .divider::after  { right: 0; }

        /* =============================================
           DROPDOWN OPTIONS in kitchen mode
        ============================================= */
        body.kitchen-mode select option {
            background: #2b1108;
            color: #fff7f0;
        }

        /* =============================================
           FOOTER
        ============================================= */
        .site-footer {
            text-align: center;
            padding: 20px;
            font-size: 12px;
            color: var(--footer-color);
            border-top: 1px solid var(--footer-border);
            background: var(--footer-bg);
            position: relative;
            z-index: 2;
        }

        /* =============================================
           RESPONSIVE
        ============================================= */
        @media (max-width: 576px) {
            .login-box { padding: 28px 18px; }
            .login-navbar { padding: 14px 20px; }
            .toggle-btn { font-size: 10px; padding: 8px 2px; }
        }
    </style>
</head>
<body>

<!-- Floating Food Particles (CSS shows only in kitchen-mode) -->
<div class="food-particles">
    <span>🍕</span>
    <span>🍔</span>
    <span>🌮</span>
    <span>🍜</span>
    <span>🍛</span>
    <span>🧆</span>
    <span>🍱</span>
    <span>🥘</span>
</div>

<!-- Navbar -->
<nav class="login-navbar">
    <a href="../index.php">
        <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png"
            alt="KhataLink Logo" class="navbar-brand-logo">
    </a>
    <a href="../index.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Home
    </a>
</nav>

<!-- Main -->
<div class="main-wrapper">
    <div class="login-container">

        <!-- Lottie Animation (desktop only) -->
        <div class="login-visual">
            <iframe
                src="https://lottie.host/embed/071477c4-0eb1-4852-b49d-1764ee63c988/NXXkmWmGiS.lottie"
                style="width:100%; height:500px; border:none;"
                title="KhataLink Animation">
            </iframe>
        </div>

        <!-- Login Card -->
        <div class="login-box">

            <div class="page-title" id="pageTitle">
                <?= $type == 'restaurant' ? '🍽️ Kitchen Login' : 'Welcome Back' ?>
            </div>
            <div class="page-subtitle" id="pageSubtitle">
                <?= $type == 'restaurant' ? 'Login to your KhataLink Kitchen dashboard' : 'Login to your KhataLink account' ?>
            </div>

            <!-- Google Sign-In -->
            <div class="google-wrapper">
                <div id="g_id_onload"
                    data-client_id="879575309344-2m4hfdequo45h13s98ra0k16jctgr9iq.apps.googleusercontent.com"
                    data-callback="handleCredentialResponse"
                    data-auto_prompt="false">
                </div>
                <div class="g_id_signin" data-type="standard"></div>
            </div>

            <!-- Truecaller One-Tap (mobile only) -->
            <div id="truecaller-login-wrapper" style="display: none;">
                <button type="button" class="btn-truecaller" onclick="triggerTruecallerLogin()">
                    <img src="https://www.truecaller.com/favicon.ico"
                        style="width:16px;height:16px;border-radius:3px;" alt="TC">
                    One-tap Login with Truecaller
                </button>
                <div class="divider">or use credentials</div>
            </div>

            <!-- Type Toggle — 4 tabs, NO DUPLICATES -->
            <div class="type-toggle">
                <button class="toggle-btn <?= $type == 'customer' ? 'active' : '' ?>"
                    onclick="setType('customer', this)" type="button">
                    <i class="fas fa-user"></i>
                    Customer
                </button>
                <button class="toggle-btn <?= $type == 'shop' ? 'active' : '' ?>"
                    onclick="setType('shop', this)" type="button">
                    <i class="fas fa-store"></i>
                    Shop Owner
                </button>
                <button class="toggle-btn <?= $type == 'restaurant' ? 'active' : '' ?>"
                    onclick="setType('restaurant', this)" type="button">
                    <i class="fas fa-utensils"></i>
                    Kitchen
                    <span class="kitchen-badge">🔥</span>
                </button>
                <button class="toggle-btn <?= $type == 'delivery' ? 'active' : '' ?>"
                    onclick="setType('delivery', this)" type="button">
                    <i class="fas fa-motorcycle"></i>
                    Delivery
                </button>
            </div>

            <!-- Error Alert -->
            <?php if(!empty($error)): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" id="loginForm">
                <input type="hidden" name="type"      id="typeInput"    value="<?= htmlspecialchars($type) ?>">
                <input type="hidden" name="latitude"  id="latInput">
                <input type="hidden" name="longitude" id="lngInput">
                <input type="hidden" name="fcm_token" id="fcmTokenInput">

                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control"
                        placeholder="Enter your email" required
                        value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                </div>

                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" name="password" id="passwordField"
                            class="form-control" placeholder="Enter your password" required>
                        <span class="input-group-text" onclick="togglePassword()">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </span>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt me-1"></i> Login to Account
                </button>
            </form>

            <div class="divider">or</div>

            <a href="register.php?type=<?= htmlspecialchars($type) ?>" class="btn-register">
                <i class="fas fa-user-plus me-1"></i> Create New Account
            </a>

        </div><!-- end login-box -->
    </div><!-- end login-container -->
</div><!-- end main-wrapper -->

<!-- Footer -->
<div class="site-footer">
    © 2025 KhataLink — Digital Credit Management Platform
</div>

<script>
    // ── Auto-capture location ──────────────────────────────────────────────
    window.onload = function() {
        if(navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                pos => {
                    document.getElementById('latInput').value = pos.coords.latitude;
                    document.getElementById('lngInput').value = pos.coords.longitude;
                },
                err => console.error("Location denied:", err)
            );
        }
        checkTruecallerVisibility();
    };

    // ── Google Sign-In JWT decode ──────────────────────────────────────────
    function decodeJwtResponse(token) {
        try {
            let base64Url = token.split('.')[1];
            let base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
            let jsonPayload = decodeURIComponent(
                atob(base64).split('').map(c =>
                    '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)
                ).join('')
            );
            return JSON.parse(jsonPayload);
        } catch(e) {
            console.error("JWT decode failed:", e);
            return null;
        }
    }

    // ── Google Sign-In callback ────────────────────────────────────────────
    async function handleCredentialResponse(response) {
        const payload = decodeJwtResponse(response.credential);
        if(!payload) { alert("Google verification failed. Please try again."); return; }

        const type = document.getElementById('typeInput').value;

        const res = await fetch('google_login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: payload.email, name: payload.name, type: type })
        });

        const data = await res.json();
        if(data.success) {
            if(data.role === 'customer')                  window.location.href = '../customer/dashboard.php';
            else if(data.role === 'shop' || data.role === 'restaurant') window.location.href = '../shop/dashboard.php';
            else if(data.role === 'delivery')             window.location.href = '../delivery/dashboard.php';
        } else {
            alert(data.message || 'Login failed. Please try again.');
        }
    }

    // ── Tab switcher + kitchen theme toggle ───────────────────────────────
    function setType(type, btn) {
        document.getElementById('typeInput').value = type;

        // Active tab highlight
        document.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        // Update labels based on type without changing the theme
        document.getElementById('pageTitle').innerText    = (type === 'restaurant') ? '🍽️ Kitchen Login' : 'Welcome Back';
        document.getElementById('pageSubtitle').innerText = (type === 'restaurant') ? 'Login to your KhataLink Kitchen dashboard' : 'Login to your KhataLink account';
        document.title = (type === 'restaurant') ? 'Kitchen Login — KhataLink' : 'Login — KhataLink';

        checkTruecallerVisibility();
    }

    // ── Show Truecaller only on mobile ────────────────────────────────────
    function checkTruecallerVisibility() {
        if(/Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
            document.getElementById('truecaller-login-wrapper').style.display = 'block';
        }
    }

    // ── Truecaller deep link ──────────────────────────────────────────────
    function triggerTruecallerLogin() {
        const partnerKey  = "u5-fnz4k5cdqjakgmghnuduhcv26qrjrgm9xib6o4_c";
        const requestId   = Math.random().toString(36).substring(7);
        window.location.href = `truecallersdk://truesdk/pkg_name=com.khatalink&partner_key=${partnerKey}&req_id=${requestId}&title=Login&msg=Secure Login to KhataLink`;
    }

    // ── Password show / hide ──────────────────────────────────────────────
    function togglePassword() {
        const field = document.getElementById('passwordField');
        const icon  = document.getElementById('eyeIcon');
        if(field.type === 'password') {
            field.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            field.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }
</script>

<!-- Firebase SDKs -->
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-messaging-compat.js"></script>
<script>
    firebase.initializeApp({
        apiKey:            "AIzaSyCM8z5Y_lMephKKsP9U0AtdIisIyKkounE",
        authDomain:        "khatalink-63041.firebaseapp.com",
        projectId:         "khatalink-63041",
        messagingSenderId: "905429197043",
        appId:             "1:905429197043:web:2a0cbefa0fa176fd2c5786"
    });

    const messaging = firebase.messaging();

    async function getFCMTokenBeforeLogin() {
        try {
            const permission = await Notification.requestPermission();
            if(permission === 'granted') {
                const reg   = await navigator.serviceWorker.register('../firebase-messaging-sw.js');
                await navigator.serviceWorker.ready;
                const token = await messaging.getToken({
                    vapidKey: 'BGixP4kke2vi5l1mpqb_P-GI5xh2OM4KcPQ_8lzQmJqvdJXHG4xFpkYvexfpD_lX7LvBQ1ORR3asE1LQkFeWFHo',
                    serviceWorkerRegistration: reg
                });
                if(token) {
                    document.getElementById('fcmTokenInput').value = token;
                    console.log("FCM Token captured:", token);
                }
            }
        } catch(err) {
            console.error("FCM Error:", err);
        }
    }

    window.addEventListener('load', () => {
        if(Notification.permission === 'default') {
            document.querySelectorAll('input').forEach(inp => {
                inp.addEventListener('focus', getFCMTokenBeforeLogin, { once: true });
            });
        } else if(Notification.permission === 'granted') {
            getFCMTokenBeforeLogin();
        }
    });

    messaging.onMessage(payload => {
        const title = payload.notification?.title || 'KhataLink';
        const body  = payload.notification?.body  || '';
        if(Notification.permission === 'granted') {
            const n = new Notification(title, { body, icon: '../assets/favicon.png' });
            n.onclick = () => { window.focus(); n.close(); };
        }
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>