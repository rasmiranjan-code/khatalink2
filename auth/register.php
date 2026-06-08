<?php
header('Access-Control-Allow-Origin: *'); // Allow all origins for development
header('Access-Control-Allow-Methods: POST, GET, OPTIONS'); // Specify allowed methods
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
    $type = $data['type'] ?? 'customer';
    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $password = $data['password'] ?? '';
    $confirm = $data['confirm_password'] ?? '';
    $full_address = trim($data['full_address'] ?? '');
    $pincode = trim($data['pincode'] ?? '');
    $fcm_token = $data['fcm_token'] ?? null;
    $device_type = $data['device_type'] ?? 'android';
    $is_truecaller = (int)($data['truecaller_verified'] ?? 0); // NEW: flag from App

    if(empty($name) || empty($email) || empty($phone) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }
    if(strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        exit();
    }
    if($password !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit();
    }

    // Image Storage Logic for API
    $profile_image = null;
    if(!empty($data['profile_image_base64'])) {
        $upload_dir = '../assets/img/profiles/';
        if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $img_data = base64_decode($data['profile_image_base64']);
        $profile_image = uniqid('p_') . '.jpg';
        file_put_contents($upload_dir . $profile_image, $img_data);
    }

    if($type == 'customer') {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        if($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already registered.']);
            exit();
        }
        $unique_id = 'CUST-'.date('Y').'-'.strtoupper(substr(md5(uniqid()), 0, 6));
        $stmt = $pdo->prepare("INSERT INTO customers (unique_id, name, email, phone, password, full_address, pincode, profile_image, is_verified, truecaller_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
        $stmt->execute([$unique_id, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $full_address, $pincode, $profile_image, $is_truecaller]);
        $new_id = $pdo->lastInsertId();
        
        updateFCMToken($pdo, $new_id, 'customer', $fcm_token, $device_type);
        sendKhataPush($pdo, $new_id, 'customer', 'Welcome to KhataLink!', "Namaste Mr/Mrs $name! KhataLink par register karne ke liye dhanyawad.");

        echo json_encode(['success' => true, 'unique_id' => $unique_id, 'message' => 'Account created!']);

    } elseif ($type == 'shop' || $type == 'restaurant') {
        $shop_name = trim($data['shop_name'] ?? '');
        $shop_category = trim($data['shop_category'] ?? '');
        $shop_type = ($type == 'restaurant') ? 'restaurant' : 'grocery';
        $fssai_no = trim($data['fssai_no'] ?? '');
        $upi_id = trim($data['upi_id'] ?? '');
        $gst_number = trim($data['gst_number'] ?? '');

        $stmt = $pdo->prepare("SELECT id FROM shop_owners WHERE email = ?");
        $stmt->execute([$email]);
        if($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already registered.']);
            exit();
        }
        $stmt = $pdo->prepare("INSERT INTO shop_owners (name, shop_name, email, password, shop_category, shop_type, fssai_no, upi_id, gst_number, full_address, pincode, profile_image, is_verified, truecaller_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
        $stmt->execute([$name, $shop_name, $email, password_hash($password, PASSWORD_DEFAULT), $shop_category, $shop_type, $fssai_no, $upi_id, $gst_number, $full_address, $pincode, $profile_image, $is_truecaller]);
        $new_id = $pdo->lastInsertId();

        updateFCMToken($pdo, $new_id, 'shop', $fcm_token, $device_type);
        sendKhataPush($pdo, $new_id, 'shop', 'Business Registered!', "Congratulations! $shop_name ab KhataLink Network ka hissa hai.");

        echo json_encode(['success' => true, 'unique_id' => '', 'message' => 'Shop registered!']);
    } else {
        // Delivery Partner logic for API
        $aadhaar_photo = null;
        if(!empty($data['aadhaar_photo_base64'])) {
            $upload_dir = '../assets/img/aadhaar/';
            if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $img_data = base64_decode($data['aadhaar_photo_base64']);
            $aadhaar_photo = uniqid('a_') . '.jpg';
            file_put_contents($upload_dir . $aadhaar_photo, $img_data);
        } else {
            echo json_encode(['success' => false, 'message' => 'Aadhaar photo is mandatory for delivery partners.']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT id FROM delivery_partners WHERE email = ?");
        $stmt->execute([$email]);
        if($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already registered.']);
            exit();
        }
        $stmt = $pdo->prepare("INSERT INTO delivery_partners (name, email, phone, password, full_address, pincode, profile_image, aadhaar_photo, is_active, is_verified, truecaller_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?)");
        $stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $full_address, $pincode, $profile_image, $aadhaar_photo, $is_truecaller]);
        $new_id = $pdo->lastInsertId();

        updateFCMToken($pdo, $new_id, 'delivery', $fcm_token, $device_type);
        sendKhataPush($pdo, $new_id, 'delivery', 'Welcome Partner', "Aapka registration safal raha. Verification ke baad orders milna shuru ho jayenge.");

        echo json_encode(['success' => true, 'message' => 'Delivery Partner registered!']);
    }
    exit();
}
// ===== END FLUTTER API =====

// ===== TRUECALLER PROFILE HANDLER (WEB) =====
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['truecaller_data'])) {
    $profile = json_decode($_POST['truecaller_data'], true);
    if($profile && isset($profile['phoneNumber'])) {
        $name = ($profile['firstName'] ?? '') . ' ' . ($profile['lastName'] ?? '');
        $phone = $profile['phoneNumber'];
        $email = $profile['email'] ?? '';
        
        // JS ko signal dena form fill karne ke liye
        echo "<script>
            window.addEventListener('load', () => {
                document.getElementById('emailInput').value = '$email';
                document.getElementById('emailDisplay').value = '$email';
                document.getElementById('nameInput').value = '$name';
                document.querySelector('input[name=\"phone\"]').value = '$phone';
                document.getElementById('truecallerVerifiedInput').value = '1';
                document.getElementById('remainingFields').style.display = 'block';
                document.querySelector('.google-btn-wrapper').style.display = 'none';
                document.getElementById('truecaller-wrapper').style.display = 'none';
            });
        </script>";
    }
}

$type = isset($_GET['type']) ? $_GET['type'] : 'customer';
$error = '';
$success = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $type = $_POST['type'];
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $full_address = trim($_POST['full_address']);
    $pincode = trim($_POST['pincode']);
    $truecaller_verified = isset($_POST['truecaller_verified']) ? (int)$_POST['truecaller_verified'] : 0;
    $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;

    // Validation
    if(empty($name) || empty($email) || empty($phone) || empty($password)) {
        $error = "All fields are required.";
    } elseif(strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Image Upload Logic for Web
        $profile_image = null;
        if(isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
            $upload_dir = '../assets/img/profiles/';
            if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $profile_image = uniqid('p_') . '.' . $ext;
            move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $profile_image);
        }

        if($type == 'customer') {
            // Check email exists
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
            $stmt->execute([$email]);
            if($stmt->fetch()) {
                $error = "This Google account is already registered as a customer.";
            } else {
                // Generate Unique ID
                $unique_id = 'CUST-' . date('Y') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

                // Insert
                $stmt = $pdo->prepare("INSERT INTO customers (unique_id, name, email, phone, password, is_verified, truecaller_verified) VALUES (?, ?, ?, ?, ?, 1, ?)");
                $stmt->execute([
                    $unique_id,
                    $name,
                    $email,
                    $phone,
                    password_hash($password, PASSWORD_DEFAULT),
                    $truecaller_verified
                ]);

                $success = "Account created successfully! Your Unique ID is: <strong>" . $unique_id . "</strong>. You can now login.";
            }

        } elseif ($type == 'shop' || $type == 'restaurant') { 
            // Shop Owner
            $shop_name = trim($_POST['shop_name']);
            $shop_category = trim($_POST['shop_category']);
            $shop_type = ($type == 'restaurant') ? 'restaurant' : 'grocery';
            $fssai_no = trim($_POST['fssai_no'] ?? '');
            $upi_id = trim($_POST['upi_id']);
            $gst_number = trim($_POST['gst_number']);

            if(empty($shop_name) || empty($shop_category)) {
                $error = "All fields are required.";
            } else {
                // Check email exists
                $stmt = $pdo->prepare("SELECT id FROM shop_owners WHERE email = ?");
                $stmt->execute([$email]);
                if($stmt->fetch()) {
                    $error = "This Google account is already registered as a shop owner.";
                } else {
                    // FIX: Reconstructed broken SQL insertion
                    $stmt = $pdo->prepare("INSERT INTO shop_owners (name, shop_name, email, password, shop_category, shop_type, fssai_no, upi_id, gst_number, full_address, pincode, latitude, longitude, profile_image, is_verified, truecaller_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
                    $stmt->execute([
                        $name,
                        $shop_name,
                        $email,
                        password_hash($password, PASSWORD_DEFAULT),
                        $shop_category,
                        $shop_type,
                        $fssai_no,
                        $upi_id,
                        $gst_number,
                        $full_address,
                        $pincode,
                        $latitude,
                        $longitude,
                        $profile_image,
                        $truecaller_verified
                    ]);

                    $success = "Shop registered successfully! You can now login.";
                }
            }
        } elseif ($type == 'delivery') { // Added elseif for Delivery Partner
            // Delivery Partner
            $aadhaar_photo = null;
            if(isset($_FILES['aadhaar_photo']) && $_FILES['aadhaar_photo']['error'] == 0) {
                $upload_dir = '../assets/img/aadhaar/';
                if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $ext = pathinfo($_FILES['aadhaar_photo']['name'], PATHINFO_EXTENSION);
                $aadhaar_photo = uniqid('a_') . '.' . $ext;
                move_uploaded_file($_FILES['aadhaar_photo']['tmp_name'], $upload_dir . $aadhaar_photo);
            } else {
                $error = "Aadhaar card photo is mandatory for delivery registration.";
            }

            if(!$error) {
                $stmt = $pdo->prepare("SELECT id FROM delivery_partners WHERE email = ?");
                $stmt->execute([$email]);
                if($stmt->fetch()) {
                    $error = "This email is already registered as a delivery partner.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO delivery_partners (name, email, phone, password, full_address, pincode, profile_image, aadhaar_photo, is_active, is_verified, truecaller_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?)");
                    $stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $full_address, $pincode, $profile_image, $aadhaar_photo, $truecaller_verified]);
                    $success = "Delivery account registered successfully! You can now login.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/style.css" rel="stylesheet">
    <!-- Google Identity Services -->
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        :root { --primary: #2563eb; --accent: #10b981; }
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .main-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .register-box {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 40px;
            width: 100%;
            max-width: 500px;
        }

        .unique-id-box {
            background: var(--primary-light);
            border: 1px solid #bfdbfe;
            border-radius: var(--radius-sm);
            padding: 16px 20px;
            margin-bottom: 20px;
        }

        .unique-id-box .id-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .unique-id-box .id-value {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-dark);
            letter-spacing: 1px;
        }

        .unique-id-box .id-note {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        @media (max-width: 576px) {
            .register-box {
                padding: 24px 18px;
            }
        }

        .google-btn-wrapper {
            display: flex;
            justify-content: center;
            margin-bottom: 25px;
            border-bottom: 1px solid var(--border-light);
            padding-bottom: 20px;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="kl-navbar">
    <a href="../index.php" class="kl-logo">
        <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo" style="height: 50px;">
    </a>
    <a href="login.php?type=<?= $type ?>" class="kl-back-link">
        <i class="fas fa-arrow-left"></i> Back to Login
    </a>
</nav>

<!-- Main -->
<div class="main-wrapper">
    <div class="register-box">

        <div class="kl-form-title">Create Account</div>
        <div class="kl-form-subtitle">Verify with Google and fill your details</div>

        <!-- Toggle -->
        <div class="kl-toggle" style="grid-template-columns: repeat(4, 1fr);">
            <button class="kl-toggle-btn <?= $type == 'customer' ? 'active' : '' ?>"
                onclick="setType('customer')" type="button">
                <i class="fas fa-user"></i> Customer
            </button>
            <button class="kl-toggle-btn <?= $type == 'shop' ? 'active' : '' ?>"
                onclick="setType('shop')" type="button">
                <i class="fas fa-store"></i> Shop Owner
            </button>
            <button class="kl-toggle-btn <?= $type == 'restaurant' ? 'active' : '' ?>"
                onclick="setType('restaurant')" type="button">
                <i class="fas fa-utensils"></i> Kitchen
            </button>
            <button class="kl-toggle-btn <?= $type == 'delivery' ? 'active' : '' ?>"
                onclick="setType('delivery')" type="button">
                <i class="fas fa-motorcycle"></i> Delivery
            </button>
        </div>

        <!-- Success -->
        <?php if($success): ?>
        <div class="kl-alert kl-alert-success">
            <i class="fas fa-check-circle"></i>
            <div><?= $success ?></div>
        </div>

        <?php if($type == 'customer'): ?>
        <!-- Unique ID Box -->
        <?php
            $stmt2 = $pdo->prepare("SELECT unique_id FROM customers WHERE email = ?");
            $stmt2->execute([trim($_POST['email'])]);
            $new_customer = $stmt2->fetch();
        ?>
        <div class="unique-id-box">
            <div class="id-label"><i class="fas fa-id-card me-1"></i> Your Unique Customer ID</div>
            <div class="id-value"><?= $new_customer['unique_id'] ?></div>
            <div class="id-note">
                <i class="fas fa-exclamation-triangle me-1"></i>
                Please save this ID. Share it with shops to get added.
            </div>
        </div>
        <?php endif; ?>

        <a href="login.php?type=<?= $type ?>" class="kl-btn kl-btn-primary kl-btn-full kl-btn-lg">
            <i class="fas fa-sign-in-alt"></i> Go to Login
        </a>

        <?php else: ?>

        <!-- Error -->
        <?php if($error): ?>
        <div class="kl-alert kl-alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= $error ?>
        </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" id="registerForm" enctype="multipart/form-data">
            <input type="hidden" name="type" id="typeInput" value="<?= $type ?>">
            <!-- Hidden Location Fields -->
            <input type="hidden" name="latitude" id="latInput">
            <input type="hidden" name="longitude" id="lngInput">
            <!-- FCM Token Hidden Field -->
            <input type="hidden" name="fcm_token" id="fcmTokenInput">
            <input type="hidden" name="truecaller_verified" id="truecallerVerifiedInput" value="0">

            <!-- Google Sign-In -->
            <div class="google-btn-wrapper">
                <div id="g_id_onload"
                    data-client_id="879575309344-2m4hfdequo45h13s98ra0k16jctgr9iq.apps.googleusercontent.com"
                    data-callback="handleCredentialResponse"
                    data-auto_prompt="false">
                </div>
                <div class="g_id_signin" data-type="standard"></div>
            </div>

            <!-- Truecaller Verification (Only Mobile Web) -->
            <div id="truecaller-wrapper" class="mb-4 text-center" style="display: none;">
                <button type="button" onclick="triggerTruecaller()" class="w-full flex items-center justify-center gap-2 bg-[#2563eb] text-white py-3 rounded-xl font-bold text-sm shadow-sm transition-all hover:bg-blue-700">
                    <img src="https://www.truecaller.com/favicon.ico" class="w-4 h-4 rounded-sm">
                    Verify with Truecaller
                </button>
                <div class="divider text-[10px] text-slate-400 uppercase font-black my-3">or register via email</div>
            </div>

            <!-- Hidden Email (filled by Google) -->
            <input type="hidden" name="email" id="emailInput">

            <div id="remainingFields" style="display: none;">
                <!-- Email Display (Read-only) -->
                <div class="kl-mb">
                    <label class="kl-label">Verified Email</label>
                    <input type="text" id="emailDisplay" class="kl-input" style="background:#f8f9fc;" readonly>
                </div>

            <!-- Full Name -->
            <div class="kl-mb">
                <label class="kl-label">Full Name</label>
                <input type="text" name="name" id="nameInput" class="kl-input"
                    placeholder="Enter your full name" required
                    value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>">
            </div>

            <!-- Profile Image -->
            <div class="kl-mb">
                <label class="kl-label">Profile Photo</label>
                <input type="file" name="profile_image" class="kl-input" accept="image/*">
            </div>

            <!-- Phone -->
            <div class="kl-mb">
                <label class="kl-label">WhatsApp Number (with Country Code, e.g. 91...)</label>
                <input type="text" name="phone" class="kl-input"
                    placeholder="e.g. 919876543210" required
                    value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>">
            </div>

            <!-- Address & Pincode -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="kl-mb">
                    <label class="kl-label">Full Address</label>
                    <input type="text" name="full_address" class="kl-input" placeholder="House no, Street, Area" required>
                </div>
                <div class="kl-mb">
                    <label class="kl-label">Pincode</label>
                    <input type="text" name="pincode" class="kl-input" placeholder="6-digit Pincode" required maxlength="6">
                </div>
            </div>

            <!-- Delivery Specific Fields -->
            <div id="deliveryFields" style="display: <?= $type == 'delivery' ? 'block' : 'none' ?>">
                <div class="kl-mb p-4 bg-red-50 border border-dashed border-red-200 rounded-2xl">
                    <label class="kl-label text-red-600"><i class="fas fa-id-card me-1"></i> Aadhaar Card Photo (Mandatory)</label>
                    <input type="file" name="aadhaar_photo" class="text-xs" accept="image/*" id="aadhaarInput">
                    <p class="text-[9px] text-slate-400 mt-2">Identity verification ke bina orders assign nahi honge.</p>
                </div>
            </div>

            <!-- Shop & Restaurant Fields -->
            <div id="shopFields" style="display: <?= ($type == 'shop' || $type == 'restaurant') ? 'block' : 'none' ?>;">
                <div class="kl-mb">
                    <label class="kl-label" id="shopNameLabel"><?= $type == 'restaurant' ? 'Kitchen Name' : 'Shop Name' ?></label>
                    <input type="text" name="shop_name" class="kl-input"
                        placeholder="Enter your shop name"
                        value="<?= isset($_POST['shop_name']) ? htmlspecialchars($_POST['shop_name']) : '' ?>">
                </div>

                <div class="kl-mb">
                    <label class="kl-label" id="shopCatLabel"><?= $type == 'restaurant' ? 'Cuisine / Food Category' : 'Shop Category' ?></label>
                    <select name="shop_category" id="categorySelect" class="kl-input">
                        <option value="">Select Category</option>
                        <!-- Dynamic Options based on JS -->
                    </select>
                </div>

                <div id="restaurantExtraFields" style="display: <?= $type == 'restaurant' ? 'block' : 'none' ?>">
                    <div class="kl-mb">
                        <label class="kl-label text-orange-600"><i class="fas fa-certificate me-1"></i> FSSAI License Number (Verified)</label>
                        <input type="text" name="fssai_no" class="kl-input" placeholder="Enter 14-digit FSSAI number">
                    </div>
                </div>
                        <option value="Fashion" <?= (isset($_POST['shop_category']) && $_POST['shop_category'] == 'Fashion') ? 'selected' : '' ?>>Fashion & Clothing</option>
                        <option value="Electronics" <?= (isset($_POST['shop_category']) && $_POST['shop_category'] == 'Electronics') ? 'selected' : '' ?>>Electronics</option>
                        <option value="Grocery" <?= (isset($_POST['shop_category']) && $_POST['shop_category'] == 'Grocery') ? 'selected' : '' ?>>Grocery & Kirana</option>
                        <option value="Medical" <?= (isset($_POST['shop_category']) && $_POST['shop_category'] == 'Medical') ? 'selected' : '' ?>>Medical & Pharmacy</option>
                        <option value="Hardware" <?= (isset($_POST['shop_category']) && $_POST['shop_category'] == 'Hardware') ? 'selected' : '' ?>>Hardware</option>
                        <option value="Food" <?= (isset($_POST['shop_category']) && $_POST['shop_category'] == 'Food') ? 'selected' : '' ?>>Food & Restaurant</option>
                        <option value="General" <?= (isset($_POST['shop_category']) && $_POST['shop_category'] == 'General') ? 'selected' : '' ?>>General Store</option>
                        <option value="Other" <?= (isset($_POST['shop_category']) && $_POST['shop_category'] == 'Other') ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <div class="kl-mb">
                    <label class="kl-label">UPI ID (Optional - for online payments)</label>
                    <input type="text" name="upi_id" class="kl-input"
                        placeholder="e.g. yourname@okaxis"
                        value="<?= isset($_POST['upi_id']) ? htmlspecialchars($_POST['upi_id']) : '' ?>">
                </div>

                <div class="kl-mb">
                    <label class="kl-label">GST Number (Optional)</label>
                    <input type="text" name="gst_number" class="kl-input"
                        placeholder="e.g. 22AAAAA0000A1Z5"
                        value="<?= isset($_POST['gst_number']) ? htmlspecialchars($_POST['gst_number']) : '' ?>">
                </div>
            </div>

            <!-- Password -->
            <div class="kl-mb">
                <label class="kl-label">Password</label>
                <div class="kl-input-group">
                    <input type="password" name="password" class="kl-input"
                        placeholder="Min 6 characters" id="passwordField" required>
                    <span class="kl-input-suffix" onclick="togglePass('passwordField', 'eye1')">
                        <i class="fas fa-eye" id="eye1"></i>
                    </span>
                </div>
            </div>

            <!-- Confirm Password -->
            <div class="kl-mb">
                <label class="kl-label">Confirm Password</label>
                <div class="kl-input-group">
                    <input type="password" name="confirm_password" class="kl-input"
                        placeholder="Re-enter password" id="confirmField" required>
                    <span class="kl-input-suffix" onclick="togglePass('confirmField', 'eye2')">
                        <i class="fas fa-eye" id="eye2"></i>
                    </span>
                </div>
            </div>

            <button type="submit" class="kl-btn kl-btn-primary kl-btn-full kl-btn-lg" style="margin-top: 8px;">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
            </div>
        </form>

        <div class="kl-divider">or</div>

        <a href="login.php?type=<?= $type ?>" class="kl-btn kl-btn-outline kl-btn-full">
            Already have an account? Login
        </a>

        <?php endif; ?>

    </div>
</div>

<!-- Footer -->
<div class="kl-footer">
    © 2025 KhataLink — Digital Credit Management Platform
</div>

<script>
    // AUTO-CAPTURE LOCATION
    window.onload = function() {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(position => {
                document.getElementById('latInput').value = position.coords.latitude;
                document.getElementById('lngInput').value = position.coords.longitude;
                console.log("Location captured:", position.coords.latitude, position.coords.longitude);
            }, error => {
                console.error("Location access denied by user.");
                // Optional: You can show a message here asking to enable location for better service
            });
        } else {
            console.error("Geolocation not supported by this browser.");
        }
    };

    function decodeJwtResponse(token) {
        try {
            let base64Url = token.split('.')[1];
            let base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
            let jsonPayload = decodeURIComponent(atob(base64).split('').map(function(c) {
                return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
            }).join(''));
            return JSON.parse(jsonPayload);
        } catch (e) {
            console.error("Token decoding failed:", e);
            return null;
        }
    }

    function handleCredentialResponse(response) {
        const responsePayload = decodeJwtResponse(response.credential);
        
        if(!responsePayload) {
            alert("Failed to get data from Google. Please try again.");
            return;
        }

        // Fill data
        document.getElementById('emailInput').value = responsePayload.email;
        document.getElementById('emailDisplay').value = responsePayload.email;
        document.getElementById('nameInput').value = responsePayload.name;

        // Show the rest of the form
        document.getElementById('remainingFields').style.transition = "all 0.5s ease";
        document.getElementById('remainingFields').style.display = "block";

        // Hide Google Button after selection
        document.querySelector('.google-btn-wrapper').style.display = 'none';
        document.querySelector('.kl-form-subtitle').innerText = "Almost done! Just a few more details.";
    }

    function setType(type) {
        document.getElementById('typeInput').value = type;
        document.querySelectorAll('.kl-toggle-btn').forEach(btn => btn.classList.remove('active'));

        const isMerchant = (type === 'shop' || type === 'restaurant');
        document.getElementById('shopFields').style.display = isMerchant ? 'block' : 'none';
        document.getElementById('restaurantExtraFields').style.display = (type === 'restaurant') ? 'block' : 'none';
        
        const catSelect = document.getElementById('categorySelect');
        const nameLabel = document.getElementById('shopNameLabel');
        const catLabel = document.getElementById('shopCatLabel');

        if(type === 'restaurant') {
            nameLabel.innerText = "Kitchen / Restaurant Name";
            catLabel.innerText = "Primary Cuisine";
            catSelect.innerHTML = `
                <option value="North Indian">North Indian</option>
                <option value="South Indian">South Indian</option>
                <option value="Chinese">Chinese</option>
                <option value="Bakery">Bakery & Desserts</option>
                <option value="Fast Food">Fast Food / Snacks</option>
                <option value="Biryani">Biryani Specialists</option>`;
        } else {
            nameLabel.innerText = "Shop Name";
            catLabel.innerText = "Shop Category";
            catSelect.innerHTML = `
                <option value="Grocery">Grocery & Kirana</option>
                <option value="Fashion">Fashion & Clothing</option>
                <option value="Electronics">Electronics</option>
                <option value="Medical">Medical & Pharmacy</option>
                <option value="Other">Other</option>`;
        }

        // Show/hide delivery fields
        document.getElementById('deliveryFields').style.display = type == 'delivery' ? 'block' : 'none';
        checkTruecallerVisibility();
    }

    function checkTruecallerVisibility() {
        // Truecaller overlay mobile browsers par hi kaam karta hai
        if(/Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
            document.getElementById('truecaller-wrapper').style.display = 'block';
        }
    }

    function triggerTruecaller() {
        const partnerKey = "u5-fnz4k5cdqjakgmghnuduhcv26qrjrgm9xib6o4_c"; // Truecaller Partner Key
        const requestId = Math.random().toString(36).substring(7);
        
        // Standard Truecaller Deep Link for Web
        window.location.href = `truecallersdk://truesdk/pkg_name=com.khatalink&partner_key=${partnerKey}&req_id=${requestId}&title=Verify&msg=Complete KhataLink Setup`;
        
        // Note: Production mein Truecaller backend callback par data bhejega
    }

    function togglePass(fieldId, iconId) {
        const field = document.getElementById(fieldId);
        const icon = document.getElementById(iconId);
        if(field.type === 'password') {
            field.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            field.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    window.addEventListener('load', checkTruecallerVisibility);
</script>

<!-- Firebase SDKs for Token Capture -->
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-messaging-compat.js"></script>

<script>
    const firebaseConfig = {
        apiKey: "AIzaSyCM8z5Y_lMephKKsP9U0AtdIisIyKkounE",
        authDomain: "khatalink-63041.firebaseapp.com",
        projectId: "khatalink-63041",
        messagingSenderId: "905429197043",
        appId: "1:905429197043:web:2a0cbefa0fa176fd2c5786"
    };

    firebase.initializeApp(firebaseConfig);
    const messaging = firebase.messaging();

    async function getFCMTokenBeforeRegister() {
        try {
            const permission = await Notification.requestPermission();
            if (permission === 'granted') {
                const registration = await navigator.serviceWorker.register('../firebase-messaging-sw.js');
                await navigator.serviceWorker.ready;

                const token = await messaging.getToken({ 
                    vapidKey: 'BGixP4kke2vi5l1mpqb_P-GI5xh2OM4KcPQ_8lzQmJqvdJXHG4xFpkYvexfpD_lX7LvBQ1ORR3asE1LQkFeWFHo',
                    serviceWorkerRegistration: registration
                });

                if (token) {
                    document.getElementById('fcmTokenInput').value = token;
                    console.log("Token captured for registration:", token);
                }
            }
        } catch (error) {
            console.error("FCM Error during registration:", error);
        }
    }

    // Form interaction par trigger karein
    window.addEventListener('load', () => {
        if (Notification.permission === 'default') {
            document.querySelectorAll('input').forEach(input => {
                input.addEventListener('focus', getFCMTokenBeforeRegister, { once: true });
            });
        } else if (Notification.permission === 'granted') {
            getFCMTokenBeforeRegister();
        }
    });

    // Foreground notification listener for auth pages
    messaging.onMessage((payload) => {
        console.log('Message received on register page: ', payload);
        const title = payload.notification?.title || 'KhataLink Update';
        const body = payload.notification?.body || '';
        const image = payload.notification?.image;
        if (Notification.permission === "granted") {
            const options = { body: body, icon: '../assets/favicon.png' };
            if (image) { options.image = image; }
            const n = new Notification(title, options);
            n.onclick = function() { window.focus(); this.close(); };
        }
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>