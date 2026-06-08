<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Broadcast Center — KhataLink Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 p-4 md:p-8 text-slate-900">
    <div class="max-w-6xl mx-auto">
        <div class="flex justify-between items-center mb-10">
            <div>
                <h1 class="text-3xl font-black tracking-tight">Broadcast Control Center</h1>
                <p class="text-slate-500 text-sm">Send real-time push notifications to shops, customers, or all users.</p>
            </div>
            <a href="dashboard.php" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg hover:bg-blue-600 transition-all">
                <i class="fas fa-arrow-left me-1"></i> Dashboard
            </a>
        </div>

        <!-- Banner Management Section -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm mb-10">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-10 h-10 bg-indigo-100 text-indigo-600 rounded-xl flex items-center justify-center"><i class="fas fa-images"></i></div>
                <h2 class="text-xl font-black uppercase tracking-tight">App Banner Management (Max 5)</h2>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Upload Form -->
                <div class="space-y-4">
                    <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">Add New Banner</p>
                    <select id="banner_target" class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-indigo-500">
                        <option value="all">Everyone</option>
                        <option value="shop">Shops Only</option>
                        <option value="customer">Customers Only</option>
                        <option value="groceries">Groceries Mall Only</option>
                    </select>
                    <input type="file" id="banner_file" accept="image/*" class="w-full text-xs text-slate-400 font-bold">
                    <button onclick="uploadBanner()" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg hover:bg-indigo-700 transition-all">Upload Banner</button>
                </div>

                <!-- Current Banners List -->
                <div id="currentBanners" class="grid grid-cols-5 gap-2">
                    <?php 
                    $existing = $pdo->query("SELECT * FROM banners ORDER BY created_at DESC")->fetchAll();
                    foreach($existing as $b): ?>
                        <div class="relative group aspect-square rounded-xl overflow-hidden border border-slate-100">
                            <img src="<?= $b['image_path'] ?>" class="w-full h-full object-cover">
                            <div class="absolute top-1 left-1 bg-black/60 text-white text-[7px] px-1.5 py-0.5 rounded font-black uppercase tracking-tighter backdrop-blur-sm">
                                <?= $b['target'] ?>
                            </div>
                            <button onclick="deleteBanner(<?= $b['id'] ?>)" class="absolute inset-0 bg-red-600/80 text-white opacity-0 group-hover:opacity-100 transition-all flex items-center justify-center text-xs"><i class="fas fa-trash"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- Card: Shops -->
            <div class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm flex flex-col">
                <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-xl mb-6">
                    <i class="fas fa-store"></i>
                </div>
                <h3 class="text-lg font-black mb-1">To All Shops</h3>
                <p class="text-slate-400 text-xs font-medium mb-6 uppercase tracking-wider">Merchant Segment</p>
                <div class="space-y-4 mt-auto">
                    <input type="text" id="title_shop" placeholder="Alert Title" class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500 transition-all">
                    <textarea id="body_shop" rows="3" placeholder="Message to merchants..." class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm font-medium outline-none focus:border-blue-500 transition-all"></textarea>
                    <div>
                        <label class="block text-[8px] font-black text-slate-400 uppercase mb-1">Banner Image (Optional)</label>
                        <input type="file" id="image_shop" accept="image/*" class="w-full text-[10px] text-slate-400 font-bold">
                    </div>
                    <button onclick="sendBroadcast('shop')" class="w-full bg-blue-600 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg shadow-blue-100 hover:bg-blue-700 transition-all">
                        Push to Shops
                    </button>
                </div>
            </div>

            <!-- Card: Customers -->
            <div class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm flex flex-col">
                <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center text-xl mb-6">
                    <i class="fas fa-users"></i>
                </div>
                <h3 class="text-lg font-black mb-1">To All Customers</h3>
                <p class="text-slate-400 text-xs font-medium mb-6 uppercase tracking-wider">User Segment</p>
                <div class="space-y-4 mt-auto">
                    <input type="text" id="title_customer" placeholder="Alert Title" class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-emerald-500 transition-all">
                    <textarea id="body_customer" rows="3" placeholder="Message to customers..." class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm font-medium outline-none focus:border-emerald-500 transition-all"></textarea>
                    <div>
                        <label class="block text-[8px] font-black text-slate-400 uppercase mb-1">Banner Image (Optional)</label>
                        <input type="file" id="image_customer" accept="image/*" class="w-full text-[10px] text-slate-400 font-bold">
                    </div>
                    <button onclick="sendBroadcast('customer')" class="w-full bg-emerald-600 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg shadow-emerald-100 hover:bg-emerald-700 transition-all">
                        Push to Customers
                    </button>
                </div>
            </div>

            <!-- Card: Universal -->
            <div class="bg-slate-900 border border-slate-800 rounded-[2.5rem] p-8 shadow-xl flex flex-col text-white">
                <div class="w-12 h-12 bg-white/10 text-blue-400 rounded-2xl flex items-center justify-center text-xl mb-6">
                    <i class="fas fa-globe"></i>
                </div>
                <h3 class="text-lg font-black mb-1">Universal Alert</h3>
                <p class="text-slate-500 text-xs font-medium mb-6 uppercase tracking-wider">Whole Network</p>
                <div class="space-y-4 mt-auto">
                    <input type="text" id="title_all" placeholder="Announcement Title" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-400 transition-all placeholder:text-slate-600">
                    <textarea id="body_all" rows="3" placeholder="Universal message..." class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm font-medium outline-none focus:border-blue-400 transition-all placeholder:text-slate-600"></textarea>
                    <div>
                        <label class="block text-[8px] font-black text-slate-500 uppercase mb-1">Universal Banner</label>
                        <input type="file" id="image_all" accept="image/*" class="w-full text-[10px] text-slate-500 font-bold">
                    </div>
                    <button onclick="sendBroadcast('all')" class="w-full bg-white text-slate-900 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg shadow-white/5 hover:bg-blue-400 transition-all">
                        Broadcast Globally
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    async function sendBroadcast(type) {
        const title = document.getElementById(`title_${type}`).value.trim();
        const body = document.getElementById(`body_${type}`).value.trim();
        const imageInput = document.getElementById(`image_${type}`);

        if(!title || !body) { alert("Please fill both title and message."); return; }
        if(!confirm(`Are you sure you want to broadcast this to ${type === 'all' ? 'EVERYONE' : type + 's'}?`)) return;

        const formData = new FormData();
        formData.append('type', type);
        formData.append('title', title);
        formData.append('body', body);
        if(imageInput.files[0]) formData.append('image', imageInput.files[0]);

        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        try {
            const res = await fetch('ajax_send_broadcast.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if(data.success) {
                alert(`Success! Sent to ${data.count} devices.`);
                document.getElementById(`title_${type}`).value = '';
                document.getElementById(`body_${type}`).value = '';
            } else {
                alert("Error: " + data.message);
            }
        } catch (e) {
            alert("Failed to reach server.");
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
    </script>

    <script>
    async function uploadBanner() {
        const fileInput = document.getElementById('banner_file');
        const target = document.getElementById('banner_target').value;
        if(!fileInput.files[0]) { alert("Please select a photo."); return; }

        const formData = new FormData();
        formData.append('banner', fileInput.files[0]);
        formData.append('target', target);
        formData.append('action', 'upload');

        const res = await fetch('ajax_manage_banners.php', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.success) location.reload();
        else alert(data.message);
    }

    async function deleteBanner(id) {
        if(!confirm("Delete this banner?")) return;
        const formData = new FormData();
        formData.append('id', id);
        formData.append('action', 'delete');

        const res = await fetch('ajax_manage_banners.php', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.success) location.reload();
    }
    </script>

    <!-- Firebase Professional Notifications (Native OS) -->
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
        if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
        const messaging = firebase.messaging();
        
        // Register Service Worker from root
        navigator.serviceWorker.register('../firebase-messaging-sw.js').then((registration) => {
            console.log("Admin SW Registered");
        });

        messaging.onMessage((payload) => {
            console.log('Broadcast Received (Foreground): ', payload);
            const title = payload.notification?.title || 'Broadcast Alert';
            const body = payload.notification?.body || '';
            const image = payload.notification?.image; // Get the image URL
            
            if (Notification.permission === "granted") {
                const options = {
                    body: body,
                    icon: '../assets/favicon.png',
                    requireInteraction: true
                };
                if (image) {
                    options.image = image; // Add image to options
                }
                const n = new Notification(title, options);
                n.onclick = function() { window.focus(); this.close(); };
            }
        });
    </script>

</body>
</html>