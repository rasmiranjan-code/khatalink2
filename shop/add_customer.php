<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
require_once '../includes/notification_service.php';
track_visitor($pdo);
// ===== FLUTTER API =====
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    header('Content-Type: application/json');
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id_api = $parts[0] ?? 0;

    $data = json_decode(file_get_contents('php://input'), true);
    $search_value = trim($data['search_value'] ?? '');

    if (!$shop_id_api) { echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit(); }
    if (empty($search_value)) { echo json_encode(['success' => false, 'message' => 'Search value is required.']); exit(); }

    $stmt = $pdo->prepare("SELECT id, name, unique_id FROM customers WHERE email = ? OR unique_id = ?");
    $stmt->execute([$search_value, $search_value]);
    $customer = $stmt->fetch();

    if(!$customer) { echo json_encode(['success' => false, 'message' => 'No customer found with this Email or Unique ID.']); exit(); }
    $check = $pdo->prepare("SELECT id FROM shop_customers WHERE shop_id = ? AND customer_id = ?");
    $check->execute([$shop_id_api, $customer['id']]);
    if($check->fetch()) { echo json_encode(['success' => false, 'message' => 'This customer is already linked to your shop.']); exit(); }
    
    $pdo->prepare("INSERT INTO shop_customers (shop_id, customer_id) VALUES (?, ?)")->execute([$shop_id_api, $customer['id']]);

    // Fetch Shop Name for Notification
    $stmt_s = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
    $stmt_s->execute([$shop_id_api]);
    $s_name = $stmt_s->fetchColumn() ?: 'Shop';

    sendKhataPush($pdo, (int)$customer['id'], 'customer', "Khata Link Hua! 🎉", "$s_name ne aapka digital khata activate kar diya hai. Ab aap real-time updates pa sakte hain.");

    echo json_encode(['success' => true, 'message' => 'Customer linked successfully!', 'customer_id' => $customer['id'], 'customer_name' => $customer['name']]);
    exit();
}
// ===== END FLUTTER API =====

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$shop_name = $_SESSION['shop_name'];
$error = '';
$success = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $search_value = trim($_POST['search_value']);

    // Email ya Unique ID se dhundo
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ? OR unique_id = ?");
    $stmt->execute([$search_value, $search_value]);
    $customer = $stmt->fetch();

    if(!$customer) {
        $error = "No customer found with this Email or Unique ID. Ask customer to register first.";
    } else {
        // Already added check
        $check = $pdo->prepare("SELECT id FROM shop_customers WHERE shop_id = ? AND customer_id = ?");
        $check->execute([$shop_id, $customer['id']]);

        if($check->fetch()) {
            $error = "This customer is already added to your shop.";
        } else {
            $pdo->prepare("INSERT INTO shop_customers (shop_id, customer_id) VALUES (?, ?)")
                ->execute([$shop_id, $customer['id']]);

            sendKhataPush($pdo, (int)$customer['id'], 'customer', "Naya Khata Khula! 📖", "$shop_name ne aapka khata apni dukan mein link kar liya hai.");

            $success = "Customer added successfully!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Customer — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 text-slate-900 font-[Inter]">

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="hidden sm:flex items-center gap-2 bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-wider">
        <i class="fas fa-store"></i>
        <?= htmlspecialchars($_SESSION['shop_name']) ?>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">

    <?php include '../includes/shop_sidebar.php'; ?>

    <!-- Main -->
    <div class="flex-1 p-4 md:p-8 max-w-4xl mx-auto w-full">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900">Add Customer</h1>
            <p class="text-slate-500 text-sm">Onboard new customers via Email or Unique Khata ID.</p>
        </div>

        <div class="bg-white border border-slate-200 rounded-3xl p-6 md:p-10 shadow-xl shadow-slate-200/50 max-w-2xl">

            <?php if($error): ?>
            <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-sm font-medium mb-6 flex items-center gap-3">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <?php if($success): ?>
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-medium mb-6 flex items-center gap-3">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-6">
                    <label class="block text-xs font-black uppercase tracking-widest text-slate-400 mb-2">Customer Email or Unique ID</label>
                    <div class="flex gap-2">
                    <input type="text" name="search_value" id="customerSearchInput"
                        class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-4 text-sm focus:bg-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all"
                        placeholder="e.g. customer@email.com or CUST-XXXX"
                        required
                        value="<?= isset($_POST['search_value']) ? htmlspecialchars($_POST['search_value']) : '' ?>">
                    <button type="button" onclick="startCustomerScanner('customerSearchInput')" class="bg-blue-600 text-white w-14 rounded-2xl flex items-center justify-center hover:bg-blue-700 shadow-lg shadow-blue-100 transition-all"><i class="fas fa-qrcode"></i></button>
                    </div>
                </div>
                <button type="submit" class="w-full bg-slate-900 text-white font-bold py-4 rounded-2xl hover:bg-blue-600 transition-all shadow-lg shadow-slate-200 active:scale-[0.98] flex items-center justify-center gap-3">
                    <i class="fas fa-search"></i> Search & Add Customer
                </button>
            </form>

            <div class="mt-8 pt-8 border-t border-slate-100">
                <div class="flex gap-4 p-4 bg-slate-50 rounded-2xl">
                    <i class="fas fa-info-circle text-blue-500 mt-1"></i>
                    <div class="text-[13px] leading-relaxed text-slate-600">
                        <span class="font-bold text-slate-900">Pro Tip:</span> Ask your customer to register on <span class="text-blue-600 font-bold">KhataLink</span>. Once they provide their 10-digit Unique ID, onboarding takes less than 2 seconds.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Barcode Scanner Modal -->
<div id="customerScannerModal" class="fixed inset-0 z-[3000] hidden items-center justify-center p-4 bg-slate-900/80 backdrop-blur-md">
    <div class="bg-white w-full max-w-md rounded-[2.5rem] overflow-hidden shadow-2xl">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-black uppercase tracking-widest text-xs">Scan Customer QR</h3>
            <button onclick="stopCustomerScanner()" class="text-slate-400"><i class="fas fa-times"></i></button>
        </div>
        <div id="customerReader" class="w-full h-64 bg-black"></div>
        <div class="p-6 text-center">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Scan customer's KhataLink ID QR</p>
        </div>
    </div>
</div>

<script>
let customerQrCodeScanner = null;
function startCustomerScanner(targetInputId) {
    document.getElementById('customerScannerModal').classList.replace('hidden', 'flex');
    customerQrCodeScanner = new Html5Qrcode("customerReader");
    
    const config = { fps: 10, qrbox: { width: 250, height: 150 } };
    
    customerQrCodeScanner.start({ facingMode: "environment" }, config, (decodedText) => {
        document.getElementById(targetInputId).value = decodedText;
        stopCustomerScanner();
        // Optionally submit form automatically
        document.querySelector('form').submit();
    }).catch(err => {
        console.error("QR Scan Error:", err);
        // Fallback to user-facing camera if environment fails
        customerQrCodeScanner.start({ facingMode: "user" }, config, (decodedText) => {
            document.getElementById(targetInputId).value = decodedText;
            stopCustomerScanner();
            document.querySelector('form').submit();
        });
    });
}

function stopCustomerScanner() {
    if(customerQrCodeScanner) {
        customerQrCodeScanner.stop().then(() => {
            document.getElementById('customerScannerModal').classList.replace('flex', 'hidden');
        });
    } else {
        document.getElementById('customerScannerModal').classList.replace('flex', 'hidden');
    }
}
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.add('show');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.remove('show');
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>