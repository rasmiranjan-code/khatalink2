<?php
session_start();
require_once 'includes/db.php';

// Fetch banner for delivery page from DB (Admin handles this via 'target')
$stmt_banner = $pdo->prepare("SELECT image_path FROM banners WHERE target IN ('delivery', 'rider', 'all') ORDER BY created_at DESC LIMIT 1");
$stmt_banner->execute();
$hero_banner = $stmt_banner->fetchColumn() ?: 'https://images.unsplash.com/photo-1626225454282-30736029148b?q=80&w=2070&auto=format&fit=crop';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Become a Delivery Partner — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; scroll-behavior: smooth; }
        
        /* 100% Match Custom Blinkit-Style Gradients and Layouts */
        .blinkit-hero-bg {
            background: linear-gradient(180deg, #FAD95F 0%, #F5C443 35%, #7CB438 75%, #278235 100%);
        }
        .blinkit-card-bg {
            background: linear-gradient(180deg, #FAD759 0%, #E3BC36 25%, #76AF35 75%, #247F33 100%);
        }
        
        /* Smooth color transition on scrolling layout */
        .nav-transition {
            transition: background-color 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }
    </style>
</head>
<body class="bg-white text-slate-900">

<nav id="blinkitNav" class="absolute top-0 left-0 right-0 z-[1000] bg-transparent border-b border-transparent nav-transition">
    <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
        <a href="index.php" class="flex items-center gap-2">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-8">
            <span class="font-extrabold text-xl tracking-tighter uppercase text-slate-900">Delivery</span>
        </a>
        
        <div class="flex items-center gap-8 font-medium text-[15px] text-slate-900">
            <a href="index.php" class="hover:text-amber-900 transition-all hidden sm:inline-block">Home</a>
            <a href="#" class="hover:text-amber-900 transition-all hidden sm:inline-block">About</a>
            <a href="#" class="hover:text-amber-900 transition-all hidden sm:inline-block">Careers</a>
            <a href="#benefits" class="hover:text-amber-900 transition-all hidden sm:inline-block">Benefits</a>
            <a href="#" class="hover:text-amber-900 transition-all hidden sm:inline-block">Blog</a>
            
            <button class="text-slate-900 text-2xl focus:outline-none pl-2 flex items-center">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>
    </div>
</nav>

<header class="relative blinkit-hero-bg min-h-[620px] flex items-center overflow-hidden pt-24 pb-12">
    
    <div class="absolute inset-0 z-0 pointer-events-none">
        <?php if($hero_banner): ?>
            <img src="<?= htmlspecialchars($hero_banner) ?>" class="w-full h-full object-cover opacity-15 mix-blend-overlay" alt="">
        <?php endif; ?>
    </div>

    <div class="w-full max-w-6xl mx-auto px-6 relative z-10 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
        
        <div class="lg:col-span-7 text-left text-white space-y-6">
            <div class="w-24 h-24 mb-4 opacity-90">
                <i class="fa-solid fa-motorcycle text-7xl text-white drop-shadow-sm"></i>
            </div>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-black tracking-tight leading-[1.1] drop-shadow-sm">
                Earn upto ₹ 50,000 with Blinkit Delivery. JOIN NOW!
            </h1>
            <p class="text-base md:text-lg font-bold tracking-wide text-white/95 uppercase drop-shadow-xs">
                JOINING BONUS of upto ₹ 4,000 | Upto ₹ 10 lacs medical insurance
            </p>
        </div>

        <div class="lg:col-span-5 flex justify-end w-full">
            <div class="bg-white p-8 rounded-2xl shadow-xl max-w-md w-full text-left">
                <h2 class="text-2xl font-extrabold text-slate-900 mb-1">Become a Blinkit rider</h2>
                <p class="text-slate-500 text-[13px] font-medium mb-6">To deliver orders for Blinkit, please fill this form</p>
                
                <form action="process/rider_register.php" method="POST" class="space-y-4">
                    <div>
                        <input type="text" name="name" required placeholder="name*" class="w-full px-4 py-3 bg-slate-50 border border-slate-200/80 rounded-lg text-[14px] font-medium focus:outline-none focus:bg-white focus:border-slate-900 transition-all">
                    </div>
                    <div>
                        <input type="tel" name="phone" required placeholder="phone*" class="w-full px-4 py-3 bg-slate-50 border border-slate-200/80 rounded-lg text-[14px] font-medium focus:outline-none focus:bg-white focus:border-slate-900 transition-all">
                        <span class="text-[11px] text-slate-400 mt-1 block pl-1">10 character(s) remaining</span>
                    </div>
                    <div>
                        <div class="relative">
                            <select name="city" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200/80 rounded-lg text-[14px] font-medium focus:outline-none focus:bg-white focus:border-slate-900 transition-all appearance-none text-slate-500">
                                <option value="" disabled selected>select the city</option>
                                <option value="Bhubaneswar">Bhubaneswar</option>
                                <option value="Cuttack">Cuttack</option>
                                <option value="Puri">Puri</option>
                            </select>
                            <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400 text-xs">
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>
                        </div>
                    </div>
                    <div class="pt-2">
                        <button type="submit" class="w-full bg-[#24292F] text-white font-bold text-[14px] py-3.5 rounded-lg hover:bg-black transition-all shadow-sm uppercase tracking-wider">join to earn</button>
                    </div>
                </form>

                <div class="mt-6 pt-5 border-t border-slate-100 flex justify-center">
                    <div class="bg-black text-white px-4 py-2 rounded-lg flex items-center gap-3 cursor-pointer select-none max-w-max">
                        <i class="fab fa-google-play text-2xl text-emerald-400"></i>
                        <div class="text-left">
                            <div class="text-[9px] uppercase tracking-tight text-slate-400 font-bold leading-none">GET IT ON</div>
                            <div class="text-[14px] font-bold font-sans tracking-wide leading-tight">Google Play</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</header>

<section id="benefits" class="py-20 bg-white">
    <div class="max-w-6xl mx-auto px-6">
        <div class="mb-12 text-left">
            <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight mb-2">Join India’s most loved quick commerce platform</h2>
            <p class="text-slate-600 text-[15px] font-normal max-w-4xl">Become a delivery partner on your own schedule and get best in class pay, among other other benefits. We are looking for dedicated people who take pride in serving fellow Indians.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            
            <div class="bg-white border border-slate-100/90 rounded-2xl p-6 text-center flex flex-col items-center justify-start shadow-sm min-h-[280px]">
                <div class="w-24 h-24 bg-gradient-to-br from-amber-50 to-amber-100 rounded-full flex items-center justify-center text-amber-600 text-3xl mb-6 border border-amber-200/40 shadow-inner">
                    <i class="fa-solid fa-indian-rupee-sign"></i>
                </div>
                <h3 class="text-lg font-extrabold text-slate-900 mb-3">Monthly earnings</h3>
                <p class="text-slate-500 text-[13px] font-medium leading-relaxed">Earn upto ₹50,000 with incentives and other benefits</p>
            </div>

            <div class="bg-white border border-slate-100/90 rounded-2xl p-6 text-center flex flex-col items-center justify-start shadow-sm min-h-[280px]">
                <div class="w-24 h-24 bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-full flex items-center justify-center text-emerald-600 text-3xl mb-6 border border-emerald-200/40 shadow-inner">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <h3 class="text-lg font-extrabold text-slate-900 mb-3">Weekly payout</h3>
                <p class="text-slate-500 text-[13px] font-medium leading-relaxed">Payment made every 7 days</p>
            </div>

            <div class="bg-white border border-slate-100/90 rounded-2xl p-6 text-center flex flex-col items-center justify-start shadow-sm min-h-[280px]">
                <div class="w-24 h-24 bg-gradient-to-br from-blue-50 to-blue-100 rounded-full flex items-center justify-center text-blue-600 text-3xl mb-6 border border-blue-200/40 shadow-inner">
                    <i class="fa-solid fa-business-time"></i>
                </div>
                <h3 class="text-lg font-extrabold text-slate-900 mb-3">Flexible schedule</h3>
                <p class="text-slate-500 text-[13px] font-medium leading-relaxed">Be your own boss; choose your work hours (4, 8 or 10 hours)</p>
            </div>

            <div class="bg-white border border-slate-100/90 rounded-2xl p-6 text-center flex flex-col items-center justify-start shadow-sm min-h-[280px]">
                <div class="w-24 h-24 bg-gradient-to-br from-rose-50 to-rose-100 rounded-full flex items-center justify-center text-rose-600 text-3xl mb-6 border border-rose-200/40 shadow-inner">
                    <i class="fa-solid fa-hand-holding-heart"></i>
                </div>
                <h3 class="text-lg font-extrabold text-slate-900 mb-3">Insurance coverage</h3>
                <p class="text-slate-500 text-[13px] font-medium leading-relaxed">Deliver safe with our accidental and medical insurance</p>
            </div>

        </div>
    </div>
</section>

<section class="py-16 bg-slate-50">
    <div class="max-w-4xl mx-auto px-6">
        <h2 class="text-2xl font-bold text-slate-900 mb-8 text-center">What do you need to start?</h2>
        <div class="bg-white p-8 rounded-3xl shadow-sm border border-slate-100">
            <ul class="grid grid-cols-1 md:grid-cols-2 gap-6 font-medium text-[15px] text-slate-700">
                <li class="flex items-start gap-3">
                    <i class="fa-solid fa-circle-check text-emerald-600 mt-1"></i>
                    <span>An Android Smartphone (version 7.0 or above)</span>
                </li>
                <li class="flex items-start gap-3">
                    <i class="fa-solid fa-circle-check text-emerald-600 mt-1"></i>
                    <span>Valid Driving License (not required for bicycles)</span>
                </li>
                <li class="flex items-start gap-3">
                    <i class="fa-solid fa-circle-check text-emerald-600 mt-1"></i>
                    <span>Aadhar Card & PAN Card for verification</span>
                </li>
                <li class="flex items-start gap-3">
                    <i class="fa-solid fa-circle-check text-emerald-600 mt-1"></i>
                    <span>Bank Account Details (for weekly transfers)</span>
                </li>
            </ul>
        </div>
    </div>
</section>

<section class="py-16 bg-white">
    <div class="max-w-5xl mx-auto px-6 space-y-10">
        <div>
            <h4 class="text-base font-bold text-slate-900 mb-3">Support all across the city</h4>
            <p class="text-slate-600 text-[14px] leading-relaxed">
                Our dedicated central routing engine works efficiently around local partner stores to minimize individual travel times. Once you accept a package run, our navigation layout optimizes pathways right up to point-to-point customer doors so you complete orders fast and clear milestones effectively.
            </p>
        </div>
    </div>
</section>

<footer class="bg-slate-900 text-white py-12 border-t border-white/5">
    <div class="max-w-7xl mx-auto px-6 flex flex-col md:flex-row justify-between items-center gap-8">
        <div class="flex items-center gap-3">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-8 brightness-0 invert">
            <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">© <?= date('Y') ?> KhataLink Logistics</span>
        </div>
        <nav class="flex gap-8">
            <a href="#" class="text-[10px] font-black uppercase text-slate-400 hover:text-white transition-all">Privacy</a>
            <a href="#" class="text-[10px] font-black uppercase text-slate-400 hover:text-white transition-all">Terms</a>
            <a href="guide.php" class="text-[10px] font-black uppercase text-slate-400 hover:text-white transition-all">Help Center</a>
        </nav>
        <div class="flex gap-4">
            <a href="#" class="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center text-slate-400 hover:bg-emerald-600 hover:text-white transition-all"><i class="fab fa-instagram"></i></a>
            <a href="#" class="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center text-slate-400 hover:bg-emerald-600 hover:text-white transition-all"><i class="fab fa-linkedin-in"></i></a>
        </div>
    </div>
</footer>

<script>
    window.addEventListener('scroll', function() {
        const nav = document.getElementById('blinkitNav');
        if (window.scrollY > 40) {
            nav.classList.remove('absolute', 'bg-transparent', 'border-transparent');
            nav.classList.add('fixed', 'bg-[#FAD95F]', 'shadow-md', 'border-slate-200/70');
        } else {
            nav.classList.remove('fixed', 'bg-[#FAD95F]', 'shadow-md', 'border-slate-200/70');
            nav.classList.add('absolute', 'bg-transparent', 'border-transparent');
        }
    });
</script>

</body>
</html>