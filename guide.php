<?php
session_start();
require_once 'includes/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Guide — KhataLink Digital Ledger</title>
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Essential CSS for layout stability */
        :root { --nav-h: 64px; }
        body { margin: 0; background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        .kl-navbar { 
            height: var(--nav-h); 
            background: #fff; 
            border-bottom: 1px solid #e2e8f0; 
            position: sticky; 
            top: 0; 
            z-index: 1000; 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            padding: 0 1.5rem; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); 
        }
        .main-wrapper { display: flex; min-height: calc(100vh - var(--nav-h)); }
        .main-content { flex: 1; padding: 1.5rem; width: 100%; }
        .guide-section { display: none; }
        .guide-section.active { display: block; animation: fadeIn 0.4s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        @media (min-width: 1024px) { .main-content { padding: 3rem; } }
    </style>
</head>
<body class="text-slate-900 bg-[#f8fafc]">

<!-- Overlay for Mobile Sidebar -->
<div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[998] hidden" id="overlay" onclick="closeSidebar()"></div>

<!-- Global Navbar -->
<nav class="kl-navbar">
    <div class="flex items-center gap-4">
        <button class="lg:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-lg transition-all" onclick="openSidebar()">
            <i class="fas fa-bars text-lg"></i>
        </button>
        <a href="index.php" class="flex items-center shrink-0">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" style="height: 40px;">
        </a>
    </div>
    <div>
        <?php if(isset($_SESSION['shop_id'])): ?>
            <div class="flex items-center gap-2 bg-blue-50 border border-blue-100 text-blue-700 px-4 py-2 rounded-full font-bold text-[10px] uppercase tracking-wider">
                <i class="fas fa-store"></i>
                <span class="hidden sm:inline"><?= htmlspecialchars($_SESSION['shop_name']) ?></span>
            </div>
        <?php else: ?>
            <a href="index.php" class="flex items-center gap-2 text-slate-500 hover:text-blue-600 font-bold text-sm transition-all">
                <i class="fas fa-arrow-left"></i> <span>Home</span>
            </a>
        <?php endif; ?>
    </div>
</nav>

<div class="main-wrapper">
    <!-- Sidebar (Only for Logged in Shops) -->
    <?php 
    if(isset($_SESSION['shop_id'])) {
        include 'includes/shop_sidebar.php';
    } elseif(isset($_SESSION['customer_id'])) {
        include 'includes/customer_sidebar.php';
    } else { echo '<style>.main-content { padding-left: 1.5rem !important; padding-right: 1.5rem !important; }</style>'; } 
    ?>

    <main class="main-content">
        <div class="max-w-3xl mx-auto w-full">
            
            <!-- Header Section -->
            <div class="text-center mb-10">
                <div class="inline-block px-3 py-1 bg-indigo-100 text-indigo-700 rounded-lg font-black text-[10px] uppercase tracking-widest mb-4">Support Center</div>
                <h1 class="text-3xl md:text-5xl font-black text-slate-900 tracking-tight mb-3">Help Center</h1>
                <p class="text-slate-500 md:text-lg">Detailed guide to help you manage digital khata efficiently.</p>
            </div>

            <!-- Tab Switcher -->
            <div class="flex flex-col sm:flex-row justify-center gap-3 mb-10">
                <button class="tab-btn active flex items-center justify-center gap-3 px-6 py-3 rounded-xl border-2 border-slate-200 bg-white font-bold text-slate-500 transition-all duration-300 hover:border-blue-500 hover:text-blue-600 [&.active]:bg-blue-600 [&.active]:text-white [&.active]:border-blue-600 [&.active]:shadow-lg shadow-blue-200" onclick="showGuide(event, 'shop')">
                    <i class="fas fa-store"></i> I'm a Shop Owner
                </button>
                <button class="tab-btn flex items-center justify-center gap-3 px-6 py-3 rounded-xl border-2 border-slate-200 bg-white font-bold text-slate-500 transition-all duration-300 hover:border-blue-500 hover:text-blue-600 [&.active]:bg-blue-600 [&.active]:text-white [&.active]:border-blue-600 [&.active]:shadow-lg shadow-blue-200" onclick="showGuide(event, 'customer')">
                    <i class="fas fa-user-tag"></i> I'm a Customer
                </button>
            </div>

            <!-- SHOP OWNER GUIDE -->
            <div id="shopGuide" class="guide-section active space-y-6">
                <!-- STAGE 1: BUSINESS SETUP -->
                <div class="bg-white border border-slate-200 rounded-[24px] p-6 md:p-10 shadow-sm">
                    <div class="flex items-center gap-4 mb-6">
                        <div class="w-14 h-14 rounded-2xl bg-blue-600 text-white flex items-center justify-center text-xl font-black shadow-lg shadow-blue-200">01</div>
                        <div>
                            <h3 class="text-xl md:text-2xl font-black text-slate-900 tracking-tight">Foundation & Personalization</h3>
                            <p class="text-slate-500 text-sm">Configure your digital storefront for professional operations.</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100">
                            <h4 class="font-bold text-slate-800 mb-2"><i class="fas fa-store text-blue-600 me-2"></i>Business Identity</h4>
                            <p class="text-xs text-slate-600 leading-relaxed">Use the <strong>Profile Management</strong> to set your Shop Name, Category, and UPI ID. Providing a UPI ID allows customers to settle their debts instantly via QR codes generated in their statements.</p>
                        </div>
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100">
                            <h4 class="font-bold text-slate-800 mb-2"><i class="fas fa-medal text-blue-600 me-2"></i>Loyalty Tiers</h4>
                            <p class="text-xs text-slate-600 leading-relaxed">The <strong>Customer Tiers</strong> system lets you create ranks like 'VIP' or 'Regular'. Assigning a tier to a customer automatically applies pre-configured discounts to every credit entry you record.</p>
                        </div>
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100">
                            <h4 class="font-bold text-slate-800 mb-2"><i class="fas fa-sliders-h text-blue-600 me-2"></i>Custom Data Fields</h4>
                            <p class="text-xs text-slate-600 leading-relaxed">Every business is unique. With <strong>Smart Custom Fields</strong>, you can define specific item categories (like 'Milk Delivery' or 'Repairs') for each customer to make manual entry faster and itemized.</p>
                        </div>
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100">
                            <h4 class="font-bold text-slate-800 mb-2"><i class="fas fa-user-plus text-blue-600 me-2"></i>Digital Handshake</h4>
                            <p class="text-xs text-slate-600 leading-relaxed">Use <strong>Customer Onboarding</strong> to link users via their Email or Unique ID. This creates a secure, synchronized bridge between your shop's records and the customer's personal dashboard.</p>
                        </div>
                    </div>
                </div>

                <!-- STAGE 2: INTELLIGENT OPERATIONS -->
                <div class="bg-indigo-900 rounded-[32px] p-8 md:p-12 text-white shadow-xl relative overflow-hidden">
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-8">
                            <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur-md flex items-center justify-center text-xl font-black">02</div>
                            <div>
                                <h3 class="text-xl md:text-3xl font-black tracking-tight">AI-Powered Daily Workflow</h3>
                                <p class="text-indigo-200 text-sm">Harness the power of AI to eliminate manual data entry errors.</p>
                            </div>
                        </div>
                        <div class="space-y-6">
                            <div class="flex gap-4">
                                <div class="w-10 h-10 rounded-full bg-emerald-500/20 flex-shrink-0 flex items-center justify-center border border-emerald-500/30"><i class="fas fa-microphone"></i></div>
                                <div>
                                    <h5 class="font-bold mb-1">Voice POS Assistant</h5>
                                    <p class="text-sm text-indigo-100/80">The <strong>Voice Billing Engine</strong> uses NLP to listen to your voice commands. Just speak items like "10kg Rice and 2 liters Oil" and the system automatically matches them with your inventory and generates an instant bill.</p>
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div class="w-10 h-10 rounded-full bg-blue-500/20 flex-shrink-0 flex items-center justify-center border border-blue-500/30"><i class="fas fa-expand"></i></div>
                                <div>
                                    <h5 class="font-bold mb-1">AI Bill Scanner (OCR)</h5>
                                    <p class="text-sm text-indigo-100/80">Updating inventory is now instant. The <strong>Bill Reader</strong> allows you to snap a photo of a supplier's invoice. Our AI extracts product names, prices, and quantities to populate your <strong>Smart Inventory</strong> automatically.</p>
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div class="w-10 h-10 rounded-full bg-purple-500/20 flex-shrink-0 flex items-center justify-center border border-purple-500/30"><i class="fas fa-boxes-stacked"></i></div>
                                <div>
                                    <h5 class="font-bold mb-1">Inventory & Stock Control</h5>
                                    <p class="text-sm text-indigo-100/80">Monitor your <strong>Product Catalog</strong> with real-time stock tracking. Set <strong>Low-Stock Alerts</strong> to receive notifications before items run out. Export high-quality PDF spec sheets for any product with a single click.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="absolute -right-20 -bottom-20 opacity-10 text-[250px] rotate-12"><i class="fas fa-robot"></i></div>
                </div>

                <!-- STAGE 3: FINANCIAL RECONCILIATION -->
                <div class="bg-white border border-slate-200 rounded-[24px] p-6 md:p-10 shadow-sm">
                    <div class="flex items-center gap-4 mb-8">
                        <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-xl font-black shadow-lg shadow-emerald-200">03</div>
                        <div>
                            <h3 class="text-xl md:text-2xl font-black text-slate-900 tracking-tight">Ledger Management & Growth</h3>
                            <p class="text-slate-500 text-sm">Control your cash flow and ensure 100% debt recovery.</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div>
                            <h5 class="font-bold text-slate-800 flex items-center gap-2 mb-3"><i class="fas fa-hand-holding-dollar text-emerald-600"></i>Collection System</h5>
                            <p class="text-sm text-slate-600 mb-4">The <strong>Payment Recorder</strong> uses a FIFO (First-In, First-Out) algorithm. When a customer pays, it automatically clears the oldest outstanding bills first, ensuring an accurate and fair aging report.</p>
                            <h5 class="font-bold text-slate-800 flex items-center gap-2 mb-3"><i class="fas fa-chart-line text-emerald-600"></i>Business Intelligence</h5>
                            <p class="text-sm text-slate-600">Access deep <strong>Visual Analytics</strong>. View 6-month trends comparing 'Total Credit Given' vs 'Total Collected'. Identify your top-debtor customers and optimize your credit cycles based on hard data.</p>
                        </div>
                        <div>
                            <h5 class="font-bold text-slate-800 flex items-center gap-2 mb-3"><i class="fas fa-flag text-emerald-600"></i>Dispute Resolution Center</h5>
                            <p class="text-sm text-slate-600 mb-4">Maintain 100% trust with the <strong>Customer Reporting</strong> module. If a customer reports an error, you receive an instant alert. You can review the dispute, reply, and correct entries directly to maintain a healthy business relationship.</p>
                            <h5 class="font-bold text-slate-800 flex items-center gap-2 mb-3"><i class="fas fa-file-pdf text-emerald-600"></i>Professional Recovery</h5>
                            <p class="text-sm text-slate-600">From <strong>Individual Ledger Details</strong>, generate professional PDF Statements or send automated <strong>WhatsApp Reminders</strong> with a direct UPI payment link to speed up your collections.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CUSTOMER GUIDE -->
            <div id="customerGuide" class="guide-section space-y-6">
                <!-- STAGE 1: IDENTITY & DISCOVERY -->
                <div class="bg-white border border-slate-200 rounded-[24px] p-6 md:p-10 shadow-sm">
                    <div class="flex items-center gap-4 mb-6">
                        <div class="w-14 h-14 rounded-2xl bg-blue-600 text-white flex items-center justify-center text-xl font-black shadow-lg shadow-blue-200">01</div>
                        <div>
                            <h3 class="text-xl md:text-2xl font-black text-slate-900 tracking-tight">Digital Identity & Access</h3>
                            <p class="text-slate-500 text-sm">Establish your presence across the digital merchant network.</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100">
                            <h4 class="font-bold text-slate-800 mb-2"><i class="fas fa-id-card text-blue-600 me-2"></i>Global Unique ID</h4>
                            <p class="text-xs text-slate-600 leading-relaxed">Upon registration, you receive a cryptographic <strong>Unique ID</strong>. Simply present this ID to any store owner to instantly link your digital credit records without sharing sensitive personal details every time.</p>
                        </div>
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100">
                            <h4 class="font-bold text-slate-800 mb-2"><i class="fas fa-store-alt text-blue-600 me-2"></i>Merchant Discovery</h4>
                            <p class="text-xs text-slate-600 leading-relaxed">The <strong>My Shops</strong> directory allows you to view a consolidated list of every merchant you are linked with, categorized by business type, along with their owner details and contact information.</p>
                        </div>
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100">
                            <h4 class="font-bold text-slate-800 mb-2"><i class="fas fa-user-gear text-blue-600 me-2"></i>Profile Optimization</h4>
                            <p class="text-xs text-slate-600 leading-relaxed">Maintain your <strong>Personal Profile</strong> to ensure merchants have your correct WhatsApp number for automated transaction alerts and payment reminders.</p>
                        </div>
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100">
                            <h4 class="font-bold text-slate-800 mb-2"><i class="fas fa-shield-halved text-blue-600 me-2"></i>Security Protocols</h4>
                            <p class="text-xs text-slate-600 leading-relaxed">Manage your <strong>Security Settings</strong> to update passwords and monitor active sessions, ensuring your personal credit history remains private and secure.</p>
                        </div>
                    </div>
                </div>

                <!-- STAGE 2: TRANSACTIONAL INTELLIGENCE -->
                <div class="bg-blue-600 rounded-[32px] p-8 md:p-12 text-white shadow-xl relative overflow-hidden">
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-8">
                            <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur-md flex items-center justify-center text-xl font-black">02</div>
                            <div>
                                <h3 class="text-xl md:text-3xl font-black tracking-tight">Hyper-Transparent Ledger</h3>
                                <p class="text-blue-100 text-sm">Monitor your spending and credit interactions in real-time.</p>
                            </div>
                        </div>
                        <div class="space-y-6">
                            <div class="flex gap-4">
                                <div class="w-10 h-10 rounded-full bg-white/10 flex-shrink-0 flex items-center justify-center border border-white/20"><i class="fas fa-microphone"></i></div>
                                <div>
                                    <h5 class="font-bold mb-1">Interactive Voice Participation</h5>
                                    <p class="text-sm text-blue-50">During checkout, you can interact with the merchant's <strong>AI Voice Assistant</strong> to confirm items and pricing verbally, ensuring no errors are made during the billing process.</p>
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div class="w-10 h-10 rounded-full bg-white/10 flex-shrink-0 flex items-center justify-center border border-white/20"><i class="fas fa-book-open"></i></div>
                                <div>
                                    <h5 class="font-bold mb-1">Global Transaction History</h5>
                                    <p class="text-sm text-blue-50">The <strong>Digital Ledger</strong> provides a unified timeline of all purchases and payments across your entire merchant network, complete with itemized breakdowns for every entry.</p>
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div class="w-10 h-10 rounded-full bg-white/10 flex-shrink-0 flex items-center justify-center border border-white/20"><i class="fas fa-file-pdf"></i></div>
                                <div>
                                    <h5 class="font-bold mb-1">On-Demand Statements</h5>
                                    <p class="text-sm text-blue-50">Generate professional <strong>Digital Statements</strong> for any specific shop. These documents include valid QR codes for individual bills and a comprehensive summary of your credit utilization.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="absolute -right-20 -bottom-20 opacity-10 text-[250px] rotate-12"><i class="fas fa-qrcode"></i></div>
                </div>

                <!-- STAGE 3: SETTLEMENTS & CONFLICTS -->
                <div class="bg-white border border-slate-200 rounded-[24px] p-6 md:p-10 shadow-sm">
                    <div class="flex items-center gap-4 mb-8">
                        <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-xl font-black shadow-lg shadow-emerald-200">03</div>
                        <div>
                            <h3 class="text-xl md:text-2xl font-black text-slate-900 tracking-tight">Settlements & Dispute Resolution</h3>
                            <p class="text-slate-500 text-sm">Efficiently manage payments and ensure record accuracy.</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div>
                            <h5 class="font-bold text-slate-800 flex items-center gap-2 mb-3"><i class="fas fa-mobile-screen-button text-emerald-600"></i>One-Click UPI Bridge</h5>
                            <p class="text-sm text-slate-600 mb-4">Settle your debts instantly using the <strong>Automated Payment Bridge</strong>. The system generates dynamic QR codes that link directly to your PhonePe, GPay, or Paytm app with the exact amount pre-filled.</p>
                            <h5 class="font-bold text-slate-800 flex items-center gap-2 mb-3"><i class="fas fa-medal text-emerald-600"></i>Loyalty & Tier Tracking</h5>
                            <p class="text-sm text-slate-600">Monitor your <strong>Reward Tier</strong> at each shop. High-trust customers are promoted to VIP tiers, which automatically apply discounts to every future credit purchase you make.</p>
                        </div>
                        <div>
                            <h5 class="font-bold text-slate-800 flex items-center gap-2 mb-3"><i class="fas fa-flag text-emerald-600"></i>Formal Dispute Center</h5>
                            <p class="text-sm text-slate-600 mb-4">If you identify an incorrect entry, utilize the <strong>Report Discrepancy</strong> tool. This logs a formal dispute in the merchant's system, allowing for a structured review and correction of your balance.</p>
                            <h5 class="font-bold text-slate-800 flex items-center gap-2 mb-3"><i class="fas fa-bell text-emerald-600"></i>Real-time Reconciliation</h5>
                            <p class="text-sm text-slate-600">Receive instant updates on your <strong>Customer Dashboard</strong> as soon as a merchant records a payment or settles a reported dispute, ensuring your "Balance Due" is always 100% accurate.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ROOT ARCHITECTURE EXPLAINER -->
            <div class="mt-16 border-t border-slate-200 pt-12">
                <h2 class="text-center text-3xl font-black text-slate-900 mb-4">Root Workflow Ecosystem</h2>
                <p class="text-center text-slate-500 mb-12 max-w-xl mx-auto">A deep-dive into the technical architecture and transactional journey of the KhataLink platform.</p>
                
                <div class="relative">
                    <!-- Vertical Line -->
                    <div class="absolute left-1/2 top-0 bottom-0 w-px bg-slate-200 -translate-x-1/2"></div>
                    
                    <div class="space-y-12 overflow-x-hidden">
                        <!-- Stage 01 -->
                        <div class="relative flex flex-row items-center gap-4 md:gap-8 group">
                            <div class="w-1/2 text-right">
                                <h4 class="font-black text-indigo-600 uppercase tracking-widest text-[10px] md:text-xs mb-1">Stage 01: Global Identity Creation</h4>
                                <p class="text-slate-600 text-[11px] md:text-sm">Users establish secured identities via the <strong>Authentication Core</strong>. Merchants configure storefront metadata while Customers receive a <strong>Global Unique ID</strong> for cross-platform recognition.</p>
                            </div>
                            <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-indigo-100 border-4 border-white shadow-md z-10 flex items-center justify-center flex-shrink-0"><div class="w-1.5 h-1.5 md:w-2 md:h-2 rounded-full bg-indigo-600"></div></div>
                            <div class="w-1/2"></div>
                        </div>

                        <!-- Stage 02 -->
                        <div class="relative flex flex-row items-center gap-4 md:gap-8 group">
                            <div class="w-1/2"></div>
                            <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-blue-100 border-4 border-white shadow-md z-10 flex items-center justify-center flex-shrink-0"><div class="w-1.5 h-1.5 md:w-2 md:h-2 rounded-full bg-blue-600"></div></div>
                            <div class="w-1/2 text-left">
                                <h4 class="font-black text-blue-600 uppercase tracking-widest text-[10px] md:text-xs mb-1">Stage 02: Merchant Node Calibration</h4>
                                <p class="text-slate-600 text-[11px] md:text-sm">Shop owners initialize their business environment by setting <strong>UPI Settlement Gateways</strong> and defining <strong>Automated Loyalty Tiers</strong> and <strong>Custom Service Fields</strong>.</p>
                            </div>
                        </div>

                        <!-- Stage 03 -->
                        <div class="relative flex flex-row items-center gap-4 md:gap-8 group">
                            <div class="w-1/2 text-right">
                                <h4 class="font-black text-emerald-600 uppercase tracking-widest text-[10px] md:text-xs mb-1">Stage 03: Relationship Mapping</h4>
                                <p class="text-slate-600 text-[11px] md:text-sm">The <strong>Handshake Protocol</strong> links Customers to Merchants via Unique ID matching, establishing the relational database bridge required for 1:1 ledger synchronization.</p>
                            </div>
                            <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-emerald-100 border-4 border-white shadow-md z-10 flex items-center justify-center flex-shrink-0"><div class="w-1.5 h-1.5 md:w-2 md:h-2 rounded-full bg-emerald-600"></div></div>
                            <div class="w-1/2"></div>
                        </div>

                        <!-- Stage 04 -->
                        <div class="relative flex flex-row items-center gap-4 md:gap-8 group">
                            <div class="w-1/2"></div>
                            <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-purple-100 border-4 border-white shadow-md z-10 flex items-center justify-center flex-shrink-0"><div class="w-1.5 h-1.5 md:w-2 md:h-2 rounded-full bg-purple-600"></div></div>
                            <div class="w-1/2 text-left">
                                <h4 class="font-black text-purple-600 uppercase tracking-widest text-[10px] md:text-xs mb-1">Stage 04: AI Resource Ingestion</h4>
                                <p class="text-slate-600 text-[11px] md:text-sm">Stock catalogs are populated using the <strong>AI OCR Bill Scanner</strong>. Supplier invoices are parsed into structured data, updating inventory levels and pricing benchmarks automatically.</p>
                            </div>
                        </div>

                        <!-- Stage 05 -->
                        <div class="relative flex flex-row items-center gap-4 md:gap-8 group">
                            <div class="w-1/2 text-right">
                                <h4 class="font-black text-pink-600 uppercase tracking-widest text-[10px] md:text-xs mb-1">Stage 05: Transactional Intelligence</h4>
                                <p class="text-slate-600 text-[11px] md:text-sm">Entries are recorded via <strong>Voice Assistant NLP</strong> or <strong>Smart Forms</strong>. The engine performs real-time balance recalculation and itemized invoice generation for both parties.</p>
                            </div>
                            <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-pink-100 border-4 border-white shadow-md z-10 flex items-center justify-center flex-shrink-0"><div class="w-1.5 h-1.5 md:w-2 md:h-2 rounded-full bg-pink-600"></div></div>
                            <div class="w-1/2"></div>
                        </div>

                        <!-- Stage 06 -->
                        <div class="relative flex flex-row items-center gap-4 md:gap-8 group">
                            <div class="w-1/2"></div>
                            <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-cyan-100 border-4 border-white shadow-md z-10 flex items-center justify-center flex-shrink-0"><div class="w-1.5 h-1.5 md:w-2 md:h-2 rounded-full bg-cyan-600"></div></div>
                            <div class="w-1/2 text-left">
                                <h4 class="font-black text-cyan-600 uppercase tracking-widest text-[10px] md:text-xs mb-1">Stage 06: Notification Outreach</h4>
                                <p class="text-slate-600 text-[11px] md:text-sm">The <strong>Communication Stack</strong> dispatches real-time alerts and automated <strong>WhatsApp Reminders</strong>, embedding direct payment links to facilitate rapid debt recovery.</p>
                            </div>
                        </div>

                        <!-- Stage 07 -->
                        <div class="relative flex flex-row items-center gap-4 md:gap-8 group">
                            <div class="w-1/2 text-right">
                                <h4 class="font-black text-orange-600 uppercase tracking-widest text-[10px] md:text-xs mb-1">Stage 07: Cryptographic Payment Bridge</h4>
                                <p class="text-slate-600 text-[11px] md:text-sm">Customers utilize the <strong>UPI Protocol Bridge</strong> to settle dues. Dynamic QR codes pre-fill exact outstanding amounts into banking applications for zero-error settlements.</p>
                            </div>
                            <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-orange-100 border-4 border-white shadow-md z-10 flex items-center justify-center flex-shrink-0"><div class="w-1.5 h-1.5 md:w-2 md:h-2 rounded-full bg-orange-600"></div></div>
                            <div class="w-1/2"></div>
                        </div>

                        <!-- Stage 08 -->
                        <div class="relative flex flex-row items-center gap-4 md:gap-8 group">
                            <div class="w-1/2"></div>
                            <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-slate-800 border-4 border-white shadow-md z-10 flex items-center justify-center flex-shrink-0"><div class="w-1.5 h-1.5 md:w-2 md:h-2 rounded-full bg-white"></div></div>
                            <div class="w-1/2 text-left">
                                <h4 class="font-black text-slate-800 uppercase tracking-widest text-[10px] md:text-xs mb-1">Stage 08: Ledger Integrity & BI</h4>
                                <p class="text-slate-600 text-[11px] md:text-sm">Data integrity is maintained through the <strong>Dispute Resolution Center</strong>. Concurrently, the <strong>BI Engine</strong> processes historical data to generate growth and credit risk analytics.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- THANK YOU -->
            <div class="text-center p-8 md:p-12 bg-slate-900 rounded-[32px] text-white mt-12 shadow-xl relative overflow-hidden">
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_var(--tw-gradient-stops))] from-blue-500/10 via-transparent to-transparent"></div>
                <img src="assets/favicon.png" alt="KhataLink" class="h-12 mx-auto brightness-0 invert opacity-40 mb-6">
                <h2 class="text-2xl md:text-3xl font-black mb-4">Manage Udhar Effortlessly</h2>
                <p class="text-slate-400 text-base md:text-lg mb-0">Empowering small businesses for a Digital India.</p>
            </div>
        </div>
    </main>
</div>

<div class="mt-auto">
    <?php include 'includes/footer.php'; ?>
</div>

<script>
    function showGuide(event, type) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        event.currentTarget.classList.add('active');

        document.querySelectorAll('.guide-section').forEach(sec => sec.classList.remove('active'));
        if(type === 'shop') {
            document.getElementById('shopGuide').classList.add('active');
        } else {
            document.getElementById('customerGuide').classList.add('active');
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function openSidebar() {
        document.getElementById('sidebar')?.classList.add('open');
        document.getElementById('overlay').classList.add('show');
    }

    function closeSidebar() {
        document.getElementById('sidebar')?.classList.remove('open');
        document.getElementById('overlay').classList.remove('show');
    }
</script>
</body>
</html>