<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/track_visitor.php';
track_visitor($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us — KhataLink Digital Ledger</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 shadow-sm">
        <a href="index.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8"></a>
        <a href="index.php" class="text-xs font-black text-blue-600 uppercase tracking-widest">Back to Home</a>
    </nav>

    <main class="max-w-4xl mx-auto py-16 px-6">
        <div class="text-center mb-16">
            <h1 class="text-4xl md:text-6xl font-black tracking-tighter text-slate-900 mb-6">Revolutionizing <span class="text-blue-600">Bharat's</span> Credit Economy.</h1>
            <p class="text-lg text-slate-500 max-w-2xl mx-auto leading-relaxed">KhataLink is India's premium digital ledger platform designed to bridge the trust gap between local merchants and their loyal customers.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-12 mb-20">
            <div class="space-y-4">
                <h2 class="text-2xl font-black uppercase tracking-tight">Our Mission</h2>
                <p class="text-slate-600 leading-relaxed text-sm">We aim to eliminate the inefficiencies of paper-based accounting. By providing tools like AI Voice POS and OCR Bill Scanning, we empower small shopkeepers to manage credit (Udhar) with 100% accuracy and transparency.</p>
            </div>
            <div class="space-y-4">
                <h2 class="text-2xl font-black uppercase tracking-tight">Financial Inclusion</h2>
                <p class="text-slate-600 leading-relaxed text-sm">Through our unique Trust Score (CIBIL-style) and Security Bond systems, we help customers build a digital credit profile, enabling them to access high-value goods on installments with confidence.</p>
            </div>
        </div>

        <div class="bg-slate-900 rounded-[3rem] p-10 md:p-16 text-white relative overflow-hidden">
            <div class="relative z-10">
                <h3 class="text-3xl font-black mb-8">What makes us different?</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="space-y-2">
                        <div class="text-blue-400 text-2xl font-black">AI POS</div>
                        <p class="text-xs text-slate-400">Natural Language Processing allows merchants to bill items just by speaking.</p>
                    </div>
                    <div class="space-y-2">
                        <div class="text-blue-400 text-2xl font-black">Cashfree PG</div>
                        <p class="text-xs text-slate-400">Integrated with Cashfree for split settlements and secure instant payments.</p>
                    </div>
                    <div class="space-y-2">
                        <div class="text-blue-400 text-2xl font-black">Unified Ledger</div>
                        <p class="text-xs text-slate-400">Real-time synchronization between merchant records and customer dashboards.</p>
                    </div>
                </div>
            </div>
            <div class="absolute -right-20 -bottom-20 text-[200px] opacity-5 font-black italic">LINK</div>
        </div>
    </main>

    <footer class="bg-white border-t border-slate-200 py-12 text-center">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">© 2026 KhataLink — Digital Infrastructure for Bharat</p>
    </footer>
</body>
</html>