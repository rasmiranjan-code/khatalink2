<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';

// ===== AUTHENTICATION =====
$delivery_id = 0;
$is_api = false;

if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $delivery_id = (int)($parts[0] ?? 0);
} else {
    $delivery_id = $_SESSION['delivery_id'] ?? 0;
}

if(!$delivery_id) {
    if($is_api) exit(json_encode(['success'=>false, 'message'=>'Unauthorized']));
    header("Location: ../auth/login.php?type=delivery"); exit();
}

// Fetch Partner Details
$stmt = $pdo->prepare("SELECT * FROM delivery_partners WHERE id = ?");
$stmt->execute([$delivery_id]);
$partner = $stmt->fetch();

if($is_api) {
    echo json_encode(['success'=>true, 'data'=>$partner]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile — Delivery Partner</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

    <nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-6 shadow-sm">
        <a href="dashboard.php" class="flex items-center gap-2">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8">
            <span class="text-[10px] font-black bg-slate-900 text-white px-2 py-1 rounded-md uppercase tracking-widest">ID: <?= $partner['id'] ?></span>
        </a>
        <a href="dashboard.php" class="text-slate-400 hover:text-blue-600 font-bold text-xs uppercase tracking-widest">Dashboard <i class="fas fa-arrow-right ml-1"></i></a>
    </nav>

    <main class="p-4 md:p-8 max-w-2xl mx-auto">
        
        <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-xl shadow-slate-200/50 mb-8">
            <!-- Profile Header -->
            <div class="bg-slate-900 p-10 text-center relative">
                <div class="relative inline-block">
                    <?php if($partner['profile_image']): ?>
                        <img src="../assets/img/profiles/<?= $partner['profile_image'] ?>" class="w-32 h-32 rounded-[2rem] object-cover border-4 border-white shadow-2xl mx-auto">
                    <?php else: ?>
                        <div class="w-32 h-32 rounded-[2rem] bg-blue-600 border-4 border-white shadow-2xl mx-auto flex items-center justify-center text-4xl text-white font-black">
                            <?= strtoupper(substr($partner['name'],0,1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="absolute -bottom-2 -right-2 bg-emerald-500 w-8 h-8 rounded-full border-4 border-slate-900 flex items-center justify-center text-white text-[10px]">
                        <i class="fas fa-check"></i>
                    </div>
                </div>
                <h2 class="text-white text-2xl font-black mt-6"><?= htmlspecialchars($partner['name']) ?></h2>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mt-1">Delivery Partner (Online)</p>
            </div>

            <!-- Details Grid -->
            <div class="p-8 space-y-8">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Registered Email</label>
                        <div class="flex items-center gap-3 text-sm font-bold text-slate-700">
                            <i class="far fa-envelope text-blue-600"></i> <?= htmlspecialchars($partner['email']) ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">WhatsApp Number</label>
                        <div class="flex items-center gap-3 text-sm font-bold text-slate-700">
                            <i class="fab fa-whatsapp text-emerald-500"></i> <?= htmlspecialchars($partner['phone']) ?>
                        </div>
                    </div>
                </div>

                <div class="pt-8 border-t border-slate-100">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Service Location</label>
                    <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100">
                        <p class="text-sm font-bold text-slate-700 mb-2"><?= htmlspecialchars($partner['full_address']) ?></p>
                        <span class="bg-blue-100 text-blue-700 text-[10px] font-black px-3 py-1 rounded-lg uppercase">Pincode: <?= $partner['pincode'] ?></span>
                    </div>
                </div>

                <!-- Identity Section -->
                <div class="pt-8 border-t border-slate-100">
                    <div class="flex justify-between items-center mb-4">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Identity Verification</label>
                        <span class="text-[8px] font-black bg-red-50 text-red-600 px-2 py-1 rounded-md uppercase">KYC Complete</span>
                    </div>
                    <div class="relative group cursor-pointer" onclick="openAadhaarModal()">
                        <img src="../assets/img/aadhaar/<?= $partner['aadhaar_photo'] ?>" class="w-full h-48 object-cover rounded-3xl border-2 border-slate-100 grayscale hover:grayscale-0 transition-all duration-500">
                        <div class="absolute inset-0 bg-slate-900/40 rounded-3xl opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                            <span class="text-white text-xs font-black uppercase tracking-widest"><i class="fas fa-search-plus mr-2"></i> View Aadhaar Card</span>
                        </div>
                    </div>
                    <p class="text-[9px] text-slate-400 mt-4 text-center italic">* Aadhaar card image is used for shopkeeper trust verification only.</p>
                </div>

                <div class="pt-8 flex gap-4">
                    <a href="../auth/logout.php" class="flex-1 bg-red-50 text-red-600 font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest text-center">Logout</a>
                    <button onclick="alert('Profile updates are currently managed by Admin.')" class="flex-1 bg-slate-100 text-slate-400 font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest cursor-not-allowed">Edit Profile</button>
                </div>

            </div>
        </div>

        <div class="text-center pb-10">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">© <?= date('Y') ?> KhataLink Logistics — Premium Partner Network</div>
        </div>
    </main>

    <!-- Aadhaar Modal -->
    <div id="aadhaarModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/95 backdrop-blur-md" onclick="closeAadhaarModal()">
        <div class="max-w-3xl w-full">
            <img src="../assets/img/aadhaar/<?= $partner['aadhaar_photo'] ?>" class="w-full rounded-2xl shadow-2xl">
            <button class="absolute top-6 right-6 text-white text-2xl"><i class="fas fa-times"></i></button>
        </div>
    </div>

    <!-- Bottom Nav for Mobile -->
    <div class="fixed bottom-0 left-0 w-full bg-white border-t border-slate-200 p-4 flex justify-around items-center lg:hidden">
        <a href="dashboard.php" class="text-slate-400"><i class="fas fa-home text-xl"></i></a>
        <a href="earnings.php" class="text-slate-400"><i class="fas fa-wallet text-xl"></i></a>
        <a href="profile.php" class="text-blue-600"><i class="fas fa-user text-xl"></i></a>
    </div>

    <script>
        function openAadhaarModal() {
            document.getElementById('aadhaarModal').classList.remove('hidden');
            document.getElementById('aadhaarModal').classList.add('flex');
        }
        function closeAadhaarModal() {
            document.getElementById('aadhaarModal').classList.remove('flex');
            document.getElementById('aadhaarModal').classList.add('hidden');
        }
    </script>
</body>
</html>