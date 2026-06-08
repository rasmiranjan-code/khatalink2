<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['shop_id'])) { header("Location: ../auth/login.php?type=shop"); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashfree Setup Guide — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'DM Sans', sans-serif; }
        code, .mono { font-family: 'JetBrains Mono', monospace; }

        .step-card {
            position: relative;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .step-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 48px rgba(0,0,0,0.08);
        }
        .connector-line {
            position: absolute;
            left: 35px;
            top: 100%;
            width: 2px;
            height: 32px;
            background: linear-gradient(to bottom, #dbeafe, transparent);
            z-index: 0;
        }
        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 4px 12px;
            border-radius: 99px;
        }
        .highlight-box {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 50%, #1d4ed8 100%);
            position: relative;
            overflow: hidden;
        }
        .highlight-box::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 200px; height: 200px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
        }
        .highlight-box::after {
            content: '';
            position: absolute;
            bottom: -40px; left: -40px;
            width: 150px; height: 150px;
            background: rgba(255,255,255,0.04);
            border-radius: 50%;
        }
        .step-num {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: #1d4ed8;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 14px;
            flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(29,78,216,0.35);
        }
        .tip-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-left: 4px solid #16a34a;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 600;
            color: #15803d;
        }
        .warn-box {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-left: 4px solid #ea580c;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 600;
            color: #c2410c;
        }
        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-left: 4px solid #3b82f6;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 600;
            color: #1d4ed8;
        }
        .screenshot-placeholder {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin: 12px 0;
        }
        .ol-steps {
            list-style: none;
            padding: 0;
            margin: 0;
            counter-reset: step-counter;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .ol-steps li {
            counter-increment: step-counter;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
            color: #475569;
            line-height: 1.6;
        }
        .ol-steps li::before {
            content: counter(step-counter);
            background: #e0e7ff;
            color: #3730a3;
            font-weight: 800;
            font-size: 11px;
            width: 22px;
            height: 22px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .path-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #1e1b4b;
            color: #a5b4fc;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 6px;
        }
        .path-tag i { color: #818cf8; }
        nav-arrow {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #1d4ed8;
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        .btn-primary:hover { background: #1e40af; transform: translateY(-1px); box-shadow: 0 8px 24px rgba(29,78,216,0.3); }

        .done-banner {
            background: linear-gradient(135deg, #064e3b, #065f46);
            border-radius: 24px;
            padding: 32px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .done-banner::before {
            content: '✓';
            position: absolute;
            font-size: 200px;
            font-weight: 900;
            opacity: 0.04;
            right: -20px;
            bottom: -40px;
            line-height: 1;
        }
        .cf-logo-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            border-radius: 12px;
            padding: 6px 14px;
            font-weight: 800;
            font-size: 13px;
            color: #1a1a2e;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 8px;
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="badge-pill">
        <i class="fas fa-shield-check"></i> Payment Setup
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/shop_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 max-w-3xl mx-auto w-full">

        <!-- Header -->
        <div class="mb-10">
            <div class="cf-logo-badge mb-3">
                <i class="fas fa-bolt text-blue-600"></i> Cashfree Payments
            </div>
            <h1 class="text-3xl font-black tracking-tight text-slate-900 mb-2 leading-tight">Cashfree Vendor ID<br>Setup Guide</h1>
            <p class="text-slate-500 text-sm leading-relaxed">Yeh guide follow karein aur apna <strong>Cashfree Vendor ID</strong> banayein — taaki KhataLink par aane wale payments seedha aapke bank mein aaye.</p>
        </div>

        <!-- What is this? Banner -->
        <div class="bg-blue-600 rounded-2xl p-6 text-white mb-8 flex gap-4 items-start">
            <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center shrink-0">
                <i class="fas fa-question text-white"></i>
            </div>
            <div>
                <p class="font-black text-sm mb-1 uppercase tracking-wide">Yeh Vendor ID Kya Hoti Hai?</p>
                <p class="text-blue-100 text-xs leading-relaxed">Cashfree EasySplit ek split payment system hai. Jab koi customer KhataLink par pay karta hai, toh payment do hisson mein split hoti hai — KhataLink ka commission alag, aur <strong>baaki paisa seedha aapke bank account mein</strong>. Iske liye Cashfree aapko ek unique <span class="mono font-bold">Vendor ID</span> deta hai. Wahi ID hume deni hai.</p>
            </div>
        </div>

        <div class="space-y-6">

            <!-- ═══ STEP 1 ═══ -->
            <div class="step-card bg-white border border-slate-200 rounded-2xl p-6 md:p-8 shadow-sm">
                <div class="flex gap-4 mb-5">
                    <div class="step-num">01</div>
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-blue-600 mb-0.5">Pehla Kadam</p>
                        <h3 class="text-base font-black text-slate-900 leading-tight">Cashfree Account Banayein Ya Login Karein</h3>
                    </div>
                </div>

                <ul class="ol-steps mb-4">
                    <li>Browser mein <strong>merchant.cashfree.com</strong> kholen ya Google par <em>"Cashfree Merchant Login"</em> search karein.</li>
                    <li>Agar pehli baar hain toh <strong>"Sign Up"</strong> par click karein. Email ID, mobile number aur password bharein.</li>
                    <li>Agar pehle se account hai toh seedha <strong>"Login"</strong> karein.</li>
                    <li>Login hone ke baad aapko Cashfree ka main dashboard dikhega.</li>
                </ul>

                <div class="screenshot-placeholder">
                    <i class="fas fa-image text-2xl mb-2 block"></i>
                    Screenshot: Cashfree Merchant Login/Signup Page
                </div>

                <div class="info-box mt-3">
                    <i class="fas fa-link me-1"></i> Direct link: <a href="https://merchant.cashfree.com" target="_blank" class="underline font-bold">merchant.cashfree.com</a>
                </div>
            </div>

            <!-- Connector line -->
            <div class="flex justify-center">
                <div class="w-0.5 h-8 bg-gradient-to-b from-blue-300 to-transparent"></div>
            </div>

            <!-- ═══ STEP 2 ═══ -->
            <div class="step-card bg-white border border-slate-200 rounded-2xl p-6 md:p-8 shadow-sm">
                <div class="flex gap-4 mb-5">
                    <div class="step-num">02</div>
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-blue-600 mb-0.5">Doosra Kadam</p>
                        <h3 class="text-base font-black text-slate-900 leading-tight">KYC (Verification) Poori Karein</h3>
                    </div>
                </div>

                <p class="text-sm text-slate-600 leading-relaxed mb-4">Paise receive karne ke liye Cashfree ko aapki identity verify karni hoti hai. Yeh ek baar ka process hai.</p>

                <ul class="ol-steps mb-4">
                    <li>Dashboard mein upar <strong>"Complete Activation"</strong> ya <strong>"KYC"</strong> banner dikhega — us par click karein.</li>
                    <li><strong>Business Type</strong> chunein: Individual / Proprietorship / Partnership / Pvt Ltd — apne hisaab se sahi wala chunein.</li>
                    <li><strong>PAN Card Number</strong> bharein (Personal ya Business dono ka, business type ke hisaab se).</li>
                    <li><strong>Aadhaar Card</strong> se address verify karein — OTP aayega aapke Aadhaar registered number par.</li>
                    <li><strong>Bank Account details</strong> bharein: Account Number + IFSC Code jahan aapko paise chahiye.</li>
                    <li>Sab details fill karne ke baad <strong>"Submit for Review"</strong> karein.</li>
                </ul>

                <div class="screenshot-placeholder">
                    <i class="fas fa-image text-2xl mb-2 block"></i>
                    Screenshot: Cashfree KYC Form Page
                </div>

                <div class="warn-box mt-3">
                    <i class="fas fa-exclamation-triangle me-1"></i> <strong>Zaroori:</strong> Jab tak KYC approve nahi hoti (usually 1-2 business days), tab tak payments receive nahi honge. KYC approve hone ke baad hi aage badhein.
                </div>
            </div>

            <!-- Connector line -->
            <div class="flex justify-center">
                <div class="w-0.5 h-8 bg-gradient-to-b from-blue-300 to-transparent"></div>
            </div>

            <!-- ═══ STEP 3 ═══ (Main / Dark Card) -->
            <div class="highlight-box rounded-2xl p-6 md:p-10 text-white shadow-xl">
                <div class="relative z-10">
                    <div class="flex gap-4 mb-5">
                        <div class="w-11 h-11 rounded-xl bg-white/20 border border-white/30 flex items-center justify-center font-black text-sm shrink-0">03</div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-blue-300 mb-0.5">Teesra Kadam — Sabse Zaroori!</p>
                            <h3 class="text-base font-black text-white leading-tight">EasySplit Section Mein Jayein</h3>
                        </div>
                    </div>

                    <p class="text-blue-100 text-sm leading-relaxed mb-4">Cashfree Dashboard ke left sidebar mein <strong>"EasySplit"</strong> naam ka section hoga. Yahi woh jagah hai jahan se aapka Vendor ID milega.</p>

                    <ul class="ol-steps mb-5" style="counter-reset: step-counter;">
                        <li style="color: #bfdbfe;"><span></span>Left sidebar mein scroll karein — <strong style="color:white;">"Payouts & Transfer"</strong> ya <strong style="color:white;">"EasySplit"</strong> option dhundhein.</li>
                        <li style="color: #bfdbfe;"><span></span><strong style="color:white;">"EasySplit"</strong> par click karein. Ek naya section khulega.</li>
                        <li style="color: #bfdbfe;"><span></span>Agar pehli baar hain toh <strong style="color:white;">"Enable EasySplit"</strong> button dikhega — use click karein aur activate karein.</li>
                    </ul>

                    <div class="screenshot-placeholder" style="background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.2); color: rgba(255,255,255,0.5);">
                        <i class="fas fa-image text-2xl mb-2 block"></i>
                        Screenshot: Cashfree Dashboard → EasySplit Menu
                    </div>

                    <div class="mt-4 bg-white/10 border border-white/20 rounded-xl p-4 text-xs text-blue-100 font-medium">
                        <i class="fas fa-info-circle me-1 text-blue-300"></i> 
                        <strong class="text-white">Navigation path:</strong> 
                        <span class="mono text-blue-200 ml-1">Dashboard → Sidebar → EasySplit</span>
                    </div>
                </div>
            </div>

            <!-- Connector line -->
            <div class="flex justify-center">
                <div class="w-0.5 h-8 bg-gradient-to-b from-blue-300 to-transparent"></div>
            </div>

            <!-- ═══ STEP 4 ═══ -->
            <div class="step-card bg-white border border-slate-200 rounded-2xl p-6 md:p-8 shadow-sm">
                <div class="flex gap-4 mb-5">
                    <div class="step-num">04</div>
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-blue-600 mb-0.5">Chautha Kadam</p>
                        <h3 class="text-base font-black text-slate-900 leading-tight">Vendor Add Karein (Apna Bank Connect Karein)</h3>
                    </div>
                </div>

                <p class="text-sm text-slate-600 leading-relaxed mb-4">EasySplit section mein jayein aur apna bank account ek "Vendor" ke roop mein add karein:</p>

                <ul class="ol-steps mb-4">
                    <li>EasySplit page mein <strong>"Vendors"</strong> tab par click karein.</li>
                    <li><strong>"+ Add Vendor"</strong> ya <strong>"Create Vendor"</strong> button dhundhein aur click karein.</li>
                    <li>Form mein <strong>Vendor Name</strong> bharein (apna naam ya shop ka naam — jaise "Ram Kirana Store").</li>
                    <li><strong>Email ID</strong> aur <strong>Mobile Number</strong> bharein (apna hi).</li>
                    <li><strong>Bank Account Number</strong> bharein — wahi account jisme aapko paise chahiye.</li>
                    <li><strong>IFSC Code</strong> bharein apne bank branch ka (4 letters + 7 digits hota hai, jaise: <span class="mono font-bold text-slate-800">SBIN0001234</span>).</li>
                    <li><strong>"Add Vendor"</strong> ya <strong>"Save"</strong> button par click karein.</li>
                </ul>

                <div class="screenshot-placeholder">
                    <i class="fas fa-image text-2xl mb-2 block"></i>
                    Screenshot: EasySplit → Add Vendor Form
                </div>

                <div class="tip-box mt-3">
                    <i class="fas fa-check-circle me-1"></i> <strong>Tip:</strong> Account Number aur IFSC sahi bharein — ek baar bank verify ho jaata hai toh aap badal nahi sakte easily. Passbook ya cheque se copy karein.
                </div>
            </div>

            <!-- Connector line -->
            <div class="flex justify-center">
                <div class="w-0.5 h-8 bg-gradient-to-b from-blue-300 to-transparent"></div>
            </div>

            <!-- ═══ STEP 5 ═══ -->
            <div class="step-card bg-white border border-slate-200 rounded-2xl p-6 md:p-8 shadow-sm border-l-4 border-l-blue-600">
                <div class="flex gap-4 mb-5">
                    <div class="step-num">05</div>
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-blue-600 mb-0.5">Paanchwa Kadam</p>
                        <h3 class="text-base font-black text-slate-900 leading-tight">Apna Vendor ID Copy Karein</h3>
                    </div>
                </div>

                <p class="text-sm text-slate-600 leading-relaxed mb-4">Vendor add hone ke baad, Cashfree aapko ek unique <strong>Vendor ID</strong> deta hai. Yahi ID KhataLink mein dalni hai.</p>

                <ul class="ol-steps mb-4">
                    <li>EasySplit → <strong>"Vendors"</strong> tab par jayein — aapka newly created vendor dikhega.</li>
                    <li>Us vendor ke naam par click karein ya <strong>"View Details"</strong> par click karein.</li>
                    <li>Wahan aapko ek <strong>"Vendor ID"</strong> field dikhega. Format kuch aisa hoga:</li>
                </ul>

                <!-- ID Examples -->
                <div class="bg-slate-900 rounded-xl p-4 mb-4">
                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-3">Vendor ID ka format kuch aisa dikhta hai:</p>
                    <div class="space-y-2">
                        <div class="flex items-center gap-3">
                            <span class="w-2 h-2 rounded-full bg-green-400 shrink-0"></span>
                            <code class="text-green-400 font-bold text-sm">cf_vendor_abc123xyz</code>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-2 h-2 rounded-full bg-blue-400 shrink-0"></span>
                            <code class="text-blue-400 font-bold text-sm">VND_4821039571</code>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-2 h-2 rounded-full bg-yellow-400 shrink-0"></span>
                            <code class="text-yellow-400 font-bold text-sm">vendor_00AbCdEf123</code>
                        </div>
                    </div>
                </div>

                <ul class="ol-steps mb-4">
                    <li>Is ID ke saath ek <strong>"Copy"</strong> icon hoga — us par click karein. Ya manually select karke Ctrl+C se copy karein.</li>
                    <li>Ise kahin safe rakhein — notepad mein paste kar lein.</li>
                </ul>

                <div class="screenshot-placeholder">
                    <i class="fas fa-image text-2xl mb-2 block"></i>
                    Screenshot: EasySplit Vendor Details Page with Vendor ID Highlighted
                </div>

                <div class="warn-box mt-3">
                    <i class="fas fa-exclamation-triangle me-1"></i> <strong>Dhyan rakhein:</strong> Yahan apna Bank Account Number <u>nahi</u> copy karna. Sirf woh ID copy karein jo Cashfree ne generate ki hai — jo alphanumeric hogi.
                </div>
            </div>

            <!-- Connector line -->
            <div class="flex justify-center">
                <div class="w-0.5 h-8 bg-gradient-to-b from-blue-300 to-transparent"></div>
            </div>

            <!-- ═══ STEP 6 ═══ (Final Action) -->
            <div class="step-card bg-white border-2 border-blue-600 rounded-2xl p-6 md:p-8 shadow-lg">
                <div class="flex gap-4 mb-5">
                    <div class="step-num" style="background: #16a34a; box-shadow: 0 4px 14px rgba(22,163,74,0.35);">06</div>
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-green-600 mb-0.5">Aakhri Kadam — Almost Done!</p>
                        <h3 class="text-base font-black text-slate-900 leading-tight">KhataLink Profile Mein Vendor ID Update Karein</h3>
                    </div>
                </div>

                <p class="text-sm text-slate-600 leading-relaxed mb-4">Jo Vendor ID aapne Cashfree se copy ki hai, use ab KhataLink mein daalni hai:</p>

                <ul class="ol-steps mb-5">
                    <li>KhataLink Dashboard mein left sidebar mein <strong>"Profile"</strong> par click karein.</li>
                    <li>Profile page par <strong>"Cashfree Vendor ID"</strong> field dhundhein.</li>
                    <li>Wahan apni copied Vendor ID <strong>paste</strong> karein (Ctrl+V).</li>
                    <li>Page ke neeche <strong>"Commit Profile Updates"</strong> / <strong>"Save Changes"</strong> button par click karein.</li>
                    <li>Green success message aayega — matlab ho gaya!</li>
                </ul>

                <div class="tip-box mb-5">
                    <i class="fas fa-check-circle me-1"></i> <strong>Verify Karein:</strong> Save karne ke baad page refresh karein — agar wahi Vendor ID field mein dikhe, toh correctly save ho gayi hai.
                </div>

                <a href="profile.php" class="btn-primary">
                    <i class="fas fa-user-circle"></i> Profile Page Par Jayein
                    <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>

            <!-- Done Banner -->
            <div class="done-banner">
                <div class="relative z-10">
                    <div class="w-16 h-16 bg-emerald-500 rounded-full flex items-center justify-center text-2xl mx-auto mb-4 shadow-lg shadow-emerald-900/50">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <h3 class="text-xl font-black mb-2">Setup Complete! 🎉</h3>
                    <p class="text-emerald-100 text-sm max-w-md mx-auto leading-relaxed">Ab jab bhi koi customer KhataLink par online pay karega, Cashfree automatically payment split karega — KhataLink ka platform fee alag, aur <strong>baaki poora paisa seedha aapke registered bank account mein</strong> chala jaayega. Koi manual kaam nahi!</p>
                </div>
            </div>

            <!-- FAQ / Common Issues -->
            <div class="bg-white border border-slate-200 rounded-2xl p-6 md:p-8 shadow-sm">
                <h3 class="text-base font-black text-slate-900 mb-5 flex items-center gap-2">
                    <i class="fas fa-circle-question text-blue-600"></i> Aam Problems & Solutions
                </h3>

                <div class="space-y-4">
                    <details class="group">
                        <summary class="flex items-center justify-between cursor-pointer text-sm font-bold text-slate-700 py-2 border-b border-slate-100 list-none">
                            <span><i class="fas fa-chevron-right text-xs text-blue-500 me-2 group-open:rotate-90 transition-transform"></i> EasySplit option sidebar mein nahi dikh raha</span>
                        </summary>
                        <p class="text-xs text-slate-600 leading-relaxed mt-3 pl-4">Pehle apni KYC poori karein aur approve hone ka wait karein. KYC bina EasySplit activate nahi hota. KYC approve hone par Cashfree email bhejta hai.</p>
                    </details>

                    <details class="group">
                        <summary class="flex items-center justify-between cursor-pointer text-sm font-bold text-slate-700 py-2 border-b border-slate-100 list-none">
                            <span><i class="fas fa-chevron-right text-xs text-blue-500 me-2 group-open:rotate-90 transition-transform"></i> Vendor add hone ke baad ID nahi dikh rahi</span>
                        </summary>
                        <p class="text-xs text-slate-600 leading-relaxed mt-3 pl-4">Vendors list mein apne vendor ke naam par click karein — detail page mein Vendor ID dikhi. Kabhi kabhi list view mein ID hidden hoti hai, detail page par jaana padta hai.</p>
                    </details>

                    <details class="group">
                        <summary class="flex items-center justify-between cursor-pointer text-sm font-bold text-slate-700 py-2 border-b border-slate-100 list-none">
                            <span><i class="fas fa-chevron-right text-xs text-blue-500 me-2 group-open:rotate-90 transition-transform"></i> Bank account verify nahi ho raha</span>
                        </summary>
                        <p class="text-xs text-slate-600 leading-relaxed mt-3 pl-4">Account Number aur IFSC dobara check karein passbook se. Account active hona chahiye. Cashfree ek small penny drop (Re. 1) bhej ke verify karta hai — 24 hrs mein ho jaata hai normally.</p>
                    </details>

                    <details class="group">
                        <summary class="flex items-center justify-between cursor-pointer text-sm font-bold text-slate-700 py-2 list-none">
                            <span><i class="fas fa-chevron-right text-xs text-blue-500 me-2 group-open:rotate-90 transition-transform"></i> KhataLink mein save karne ke baad kuch nahi hua</span>
                        </summary>
                        <p class="text-xs text-slate-600 leading-relaxed mt-3 pl-4">Vendor ID bilkul wahi paste karein jo Cashfree ne diya — koi extra space ya character nahi hona chahiye. Save karne ke baad page refresh karein aur field check karein. Aur problem ho toh support se contact karein.</p>
                    </details>
                </div>
            </div>

        </div><!-- end space-y-6 -->

        <!-- Support Footer -->
        <div class="mt-10 text-center p-8 border-t border-slate-200">
            <p class="text-xs text-slate-400 font-black uppercase tracking-widest mb-4">Aur Help Chahiye?</p>
            <div class="flex justify-center gap-6 flex-wrap">
                <a href="https://wa.me/91XXXXXXXXXX" class="flex items-center gap-2 text-slate-600 hover:text-emerald-600 font-bold text-xs uppercase tracking-widest transition-colors">
                    <i class="fab fa-whatsapp text-xl text-emerald-500"></i> WhatsApp Support
                </a>
                <a href="https://docs.cashfree.com/docs/easysplit" target="_blank" class="flex items-center gap-2 text-slate-600 hover:text-blue-600 font-bold text-xs uppercase tracking-widest transition-colors">
                    <i class="fas fa-book text-blue-500"></i> Cashfree Docs
                </a>
            </div>
        </div>

    </main>
</div>

<script>
function openSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) sidebar.classList.add('open');
}
function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) sidebar.classList.remove('open');
}

// Smooth details open/close animation for FAQ
document.querySelectorAll('details').forEach(detail => {
    const summary = detail.querySelector('summary');
    summary.addEventListener('click', (e) => {
        e.preventDefault();
        if (detail.open) {
            detail.removeAttribute('open');
        } else {
            document.querySelectorAll('details[open]').forEach(d => d.removeAttribute('open'));
            detail.setAttribute('open', '');
        }
    });
});
</script>
</body>
</html>