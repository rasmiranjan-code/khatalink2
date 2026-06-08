<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['shop_id'])) { header("Location: ../auth/login.php"); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Trust Score — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 shadow-sm">
        <a href="dashboard.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8"></a>
        <div class="text-[10px] font-black text-indigo-600 uppercase tracking-widest bg-indigo-50 px-3 py-1.5 rounded-full">Risk Assessment Engine</div>
    </nav>

    <div class="flex">
        <?php include '../includes/shop_sidebar.php'; ?>
        <main class="flex-1 p-8 max-w-4xl mx-auto">
            <div class="mb-10">
                <h1 class="text-3xl font-black text-slate-900">Customer Credit Check</h1>
                <p class="text-slate-500">Verify a customer's trust score across all KhataLink shops.</p>
            </div>

            <div class="bg-indigo-900 rounded-[2.5rem] p-10 text-white shadow-2xl mb-12">
                <div class="max-w-xl mx-auto text-center">
                    <label class="block text-[11px] font-black uppercase tracking-[0.3em] text-indigo-300 mb-4">Verification Portal</label>
                    <div class="relative">
                        <div class="flex gap-2">
                        <input type="text" id="custID" placeholder="Enter Customer Unique ID (e.g. CUST-XXXX)" 
                               class="w-full bg-white/10 border-2 border-white/20 rounded-2xl px-6 py-4 text-lg font-bold placeholder:text-white/30 outline-none focus:bg-white focus:text-slate-900 focus:border-white transition-all">
                        <button type="button" onclick="startCibilScanner('custID')" class="bg-blue-600 text-white w-14 rounded-2xl flex items-center justify-center hover:bg-blue-700 shadow-lg shadow-blue-100 transition-all"><i class="fas fa-qrcode"></i></button>
                        </div>
                        <button onclick="checkCibil()" class="mt-6 w-full bg-amber-500 hover:bg-amber-400 text-slate-900 font-black py-4 rounded-2xl transition-all shadow-lg shadow-amber-500/20 uppercase tracking-widest text-xs">
                            <i class="fas fa-shield-check me-2"></i> Verify Credit Worthiness
                        </button>
                    </div>
                </div>
            </div>

            <div id="loader" class="hidden text-center py-10">
                <div class="animate-spin text-indigo-600 text-4xl mb-4"><i class="fas fa-circle-notch"></i></div>
                <p class="text-slate-400 font-bold uppercase tracking-widest text-[10px]">Scanning KhataLink Network...</p>
            </div>

            <div id="resultBox" class="hidden">
                <!-- Result injected here via JS -->
            </div>
        </main>
    </div>

    <script>
    async function checkCibil() {
        const id = document.getElementById('custID').value.trim();
        if(!id) return;

        const resultBox = document.getElementById('resultBox');
        const loader = document.getElementById('loader');

        resultBox.classList.add('hidden');
        loader.classList.remove('hidden');

        try {
            const res = await fetch(`ajax_customer_cibil_api.php?unique_id=${id}`);
            const data = await res.json();
            loader.classList.add('hidden');

            if(!data.success) {
                alert(data.message);
                return;
            }

            const s = data.credit_summary;
            const c = data.customer;
            let scoreColor = s.score > 75 ? 'text-emerald-500' : (s.score > 40 ? 'text-amber-500' : 'text-red-500');
            let scoreBg = s.score > 75 ? 'bg-emerald-50' : (s.score > 40 ? 'bg-amber-50' : 'bg-red-50');            

            const trendLabels = data.repayment_trend.map(t => t.month_label);
            const trendCredits = data.repayment_trend.map(t => parseFloat(t.credit));
            const trendPayments = data.repayment_trend.map(t => parseFloat(t.payment));

            resultBox.innerHTML = `
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm">
                    <div class="flex justify-end mb-4">
                        <a href="export_cibil_report.php?unique_id=${c.unique_id}" target="_blank" class="bg-indigo-50 text-indigo-600 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-indigo-600 hover:text-white transition-all">
                            <i class="fas fa-file-pdf me-1"></i> Download Detailed Report
                        </a>
                    </div>
                    <div class="flex flex-col md:flex-row items-center gap-8 mb-10 pb-10 border-b border-slate-100 relative">
                        <div class="w-32 h-32 rounded-full border-8 border-slate-50 flex items-center justify-center relative ${scoreBg}">
                            <div class="text-3xl font-black ${scoreColor}">${s.score}</div>
                            <div class="absolute -bottom-2 bg-slate-900 text-white text-[8px] font-black px-2 py-1 rounded-md uppercase">Trust Score</div>
                        </div>
                        <div class="text-center md:text-left">
                            <h2 class="text-2xl font-black text-slate-900">${c.name}</h2>
                            <p class="text-slate-400 font-bold text-sm">Unique ID: ${c.unique_id} • Member Since ${c.member_since}</p>
                            <div class="mt-3 flex gap-2 justify-center md:justify-start">
                                <span class="bg-blue-50 text-blue-600 text-[9px] font-black px-3 py-1 rounded-full uppercase">Network: ${s.total_shops} Shops</span>
                                ${s.is_defaulter ? '<span class="bg-red-50 text-red-600 text-[9px] font-black px-3 py-1 rounded-full uppercase animate-bounce">Defaulter Alert</span>' : '<span class="bg-emerald-50 text-emerald-600 text-[9px] font-black px-3 py-1 rounded-full uppercase">Clean History</span>'}
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="p-6 bg-slate-50 rounded-3xl border border-slate-100 text-center">
                            <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Borrowed</div>
                            <div class="text-xl font-black text-slate-900">₹${s.total_borrowed.toLocaleString()}</div>
                        </div>
                        <div class="p-6 bg-emerald-50 rounded-3xl border border-emerald-100 text-center">
                            <div class="text-[9px] font-black text-emerald-600 uppercase tracking-widest mb-1">Total Repaid</div>
                            <div class="text-xl font-black text-emerald-600">₹${s.total_paid.toLocaleString()}</div>
                        </div>
                        <div class="p-6 bg-red-50 rounded-3xl border border-red-100 text-center">
                            <div class="text-[9px] font-black text-red-600 uppercase tracking-widest mb-1">Active Dues</div>
                            <div class="text-xl font-black text-red-600">₹${s.total_due.toLocaleString()}</div>
                        </div>
                    </div>

                    <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="p-6 border border-slate-100 rounded-3xl">
                            <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Engagement Metrics</h4>
                            <div class="space-y-3">
                                <div class="flex justify-between text-sm"><span class="text-slate-500">Linked Shops</span> <span class="font-bold">${s.total_shops}</span></div>
                                <div class="flex justify-between text-sm"><span class="text-slate-500">Active Bonds</span> <span class="font-bold">${s.active_bonds}</span></div>
                                <div class="flex justify-between text-sm"><span class="text-slate-500">Monthly Cycles</span> <span class="font-bold">${s.active_monthly}</span></div>
                            </div>
                        </div>
                        <div class="p-6 border border-slate-100 rounded-3xl bg-blue-50/30">
                            <h4 class="text-[10px] font-black text-blue-700 uppercase tracking-widest mb-2"><i class="fas fa-info-circle me-1"></i> Shop Owner Insight</h4>
                            <p class="text-xs text-slate-600 leading-relaxed">
                                ${s.score > 80 ? 'Inka repayment behavior bahut accha hai. Aap inko confidence ke saath credit de sakte hain.' : 
                                 (s.score > 50 ? 'Inka record average hai. Purane dues check karne ke baad hi naya credit limit set karein.' : 
                                 'High Risk! Customer ke upar kaafi purane dues ya defaults pending hain. Cash transactions prefer karein.')}
                            </p>
                        </div>
                    </div>

                    <div class="mt-8 p-6 border border-slate-100 rounded-3xl">
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Repayment Trend (Last 6 Months)</h4>
                        <div style="height: 250px;"><canvas id="repaymentTrendChart"></canvas></div>
                    </div>
                </div>
            `;
            resultBox.classList.remove('hidden');

            // Initialize Chart
            const ctx = document.getElementById('repaymentTrendChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: trendLabels,
                    datasets: [
                        {
                            label: 'Credit Taken',
                            data: trendCredits,
                            backgroundColor: 'rgba(239, 68, 68, 0.7)', // Red
                            borderRadius: 4
                        },
                        {
                            label: 'Amount Paid',
                            data: trendPayments,
                            backgroundColor: 'rgba(5, 150, 105, 0.7)', // Green
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { font: { weight: 'bold', size: 10 } }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) { label += ': '; }
                                    if (context.parsed.y !== null) { label += '₹' + context.parsed.y.toLocaleString('en-IN'); }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } }
                    }
                }
            });
        } catch (e) {
            alert("Network error: " + e.message);
            loader.classList.add('hidden');
        }
    }
    </script>
    <!-- Scanner Modal -->
<div id="cibil-scanner-modal" class="hidden fixed inset-0 z-[9999] bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl p-6 w-full max-w-sm">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="font-black text-slate-900">QR / Barcode Scan</h3>
                <p class="text-xs text-slate-400">Customer ID card saamne rakho</p>
            </div>
            <button onclick="stopCibilScanner()" class="w-9 h-9 rounded-full bg-red-50 text-red-500 hover:bg-red-500 hover:text-white transition-all flex items-center justify-center">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="cibil-reader" class="w-full rounded-2xl overflow-hidden bg-slate-100" style="min-height:250px"></div>
        <p class="text-center text-[10px] text-slate-400 mt-3 font-bold uppercase tracking-widest">Camera ke saamne QR rakho</p>
    </div>
</div>

<script>
let cibilScanner = null;
let cibilTargetInput = null;

function startCibilScanner(inputId) {
    cibilTargetInput = inputId;
    document.getElementById('cibil-scanner-modal').classList.remove('hidden');
    cibilScanner = new Html5Qrcode("cibil-reader");
    cibilScanner.start(
        { facingMode: "environment" },
        { fps: 10, qrbox: { width: 220, height: 220 } },
        (decodedText) => {
            document.getElementById(cibilTargetInput).value = decodedText;
            stopCibilScanner();
        },
        () => {}
    ).catch(err => {
        alert("Camera access nahi mila: " + err);
        stopCibilScanner();
    });
}

function stopCibilScanner() {
    if (cibilScanner) {
        cibilScanner.stop().then(() => {
            cibilScanner.clear();
            cibilScanner = null;
        }).catch(() => {});
    }
    document.getElementById('cibil-scanner-modal').classList.add('hidden');
}
</script>
</body>
</html>