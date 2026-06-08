<?php
session_start();
require_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'nuclear_wipe') {
    file_put_contents('../debug_error.log', "");
    header("Location: hacker_terminal.php?msg=Logs+Wiped");
    exit();
}

if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

// ── SECONDARY AUTH: DANGER ZONE ACCESS ──
if (!isset($_SESSION['danger_zone_authorized'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['danger_pass'])) {
        if ($_POST['danger_pass'] === 'khata@master') { // Change this key for security
            $_SESSION['danger_zone_authorized'] = true;
            header("Location: hacker_terminal.php"); exit();
        }
        $auth_err = "INVALID ACCESS KEY. UNAUTHORIZED ATTEMPT LOGGED.";
    }
?>
<!DOCTYPE html>
<html>
<head><title>RESTRICTED ACCESS</title><script src="https://cdn.tailwindcss.com"></script><link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono&display=swap" rel="stylesheet"></head>
<body class="bg-black text-red-500 h-screen flex items-center justify-center font-['JetBrains_Mono']">
    <div class="max-w-md w-full p-8 border-2 border-red-900 bg-zinc-950 rounded-lg shadow-2xl">
        <div class="text-center mb-8">
            <div class="text-5xl mb-4 animate-pulse">☠</div>
            <h1 class="text-sm font-black tracking-[.3em] uppercase leading-relaxed">System Trace Restricted</h1>
            <p class="text-[10px] text-zinc-600 mt-2">DANGER ZONE: Authorized Personnel Only</p>
        </div>
        <?php if(isset($auth_err)): ?><div class="bg-red-900/20 text-red-400 p-3 rounded mb-6 text-[10px] border border-red-900/50 text-center font-bold italic"><?= $auth_err ?></div><?php endif; ?>
        <form method="POST" class="space-y-4">
            <input type="password" name="danger_pass" autofocus placeholder="ENTER MASTER ACCESS KEY" class="w-full bg-black border border-zinc-800 p-3 rounded text-center text-xs tracking-widest outline-none focus:border-red-600 transition-all placeholder:text-zinc-800">
            <button type="submit" class="w-full bg-red-600 text-black font-black py-3 rounded text-[10px] uppercase tracking-widest hover:bg-red-500 transition-all shadow-[0_0_15px_rgba(220,38,38,0.4)]">Authenticate Shell</button>
        </form>
        <div class="mt-8 text-center"><a href="dashboard.php" class="text-[9px] text-zinc-700 hover:text-zinc-500 uppercase tracking-widest">← Return to Dashboard</a></div>
    </div>
</body>
</html>
<?php exit(); }

$db_name = $pdo->query("SELECT DATABASE()")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>KHATALINK | CYBER-OPS TERMINAL</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Orbitron:wght@400;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBU0peOhBAVgAA9YWAPk_oNi9-zTbhC74s&libraries=geometry"></script>
<style>
:root {
  --ng: #00ff41; --nc: #00f3ff; --np: #bc13fe;
  --nr: #ff003c; --na: #ffb300; --tbg: rgba(2,6,23,0.93);
}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{width:100vw;height:100vh;overflow:hidden;background:#000;color:var(--ng);font-family:'JetBrains Mono',monospace;}
.orb{font-family:'Orbitron',sans-serif;}

/* SCAN LINES */
body::after{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,255,65,.012) 2px,rgba(0,255,65,.012) 4px);pointer-events:none;z-index:9990;}

/* GRID */
.main-grid{position:relative;z-index:1;height:100vh;display:grid;grid-template-rows:52px 1fr 32px;gap:4px;padding:4px;}
.mid-grid{display:grid;grid-template-columns:260px 1fr 250px;gap:4px;min-height:0;}
.left-col{display:flex;flex-direction:column;gap:4px;min-height:0;}
.center-col{display:flex;flex-direction:column;gap:4px;min-height:0;}
.right-col{display:flex;flex-direction:column;gap:4px;min-height:0;overflow-y:auto;}
.right-col::-webkit-scrollbar{width:2px;}
.right-col::-webkit-scrollbar-thumb{background:rgba(0,255,65,.2);}

/* PANELS */
.gp{background:var(--tbg);backdrop-filter:blur(12px);border:1px solid rgba(0,255,65,.12);border-radius:5px;position:relative;overflow:hidden;flex-shrink:0;}
.gp::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(0,255,65,.25),transparent);}
.gp.cyan{border-color:rgba(0,243,255,.2);box-shadow:0 0 20px rgba(0,243,255,.06);}
.gp.cyan::before{background:linear-gradient(90deg,transparent,rgba(0,243,255,.35),transparent);}
.gp.purple{border-color:rgba(188,19,254,.2);box-shadow:0 0 20px rgba(188,19,254,.06);}
.gp.purple::before{background:linear-gradient(90deg,transparent,rgba(188,19,254,.35),transparent);}
.gp.red{border-color:rgba(255,0,60,.35);box-shadow:0 0 20px rgba(255,0,60,.1);}
.gp.amber{border-color:rgba(255,179,0,.2);box-shadow:0 0 20px rgba(255,179,0,.06);}
.gp.amber::before{background:linear-gradient(90deg,transparent,rgba(255,179,0,.35),transparent);}

/* PANEL HEADER */
.ph{padding:5px 10px;border-bottom:1px solid rgba(255,255,255,.05);background:rgba(255,255,255,.025);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.pt{font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:.18em;}

/* HEADER STAT CARDS */
.hdr{display:grid;grid-template-columns:repeat(9,1fr);gap:4px;}
.sc{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:6px 4px;gap:3px;}
.sl{font-size:7px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;opacity:.4;}
.sv{font-family:'Orbitron',sans-serif;font-size:14px;font-weight:900;line-height:1;}
.sbar{width:100%;height:2px;background:rgba(255,255,255,.07);border-radius:10px;overflow:hidden;margin-top:2px;}
.sbf{height:100%;border-radius:10px;transition:width 1s ease,background .5s;}

/* WORLD MAP */
#world-map{width:100%;height:100%;position:absolute;top:0;left:0;z-index:1;}
.gm-style-iw { background: var(--tbg) !important; border: 1px solid var(--nc); color: var(--nc) !important; font-family: 'JetBrains Mono', monospace; font-size: 10px; }
/* RADAR */
.radar-container{position:relative;width:100%;height:100%;overflow:hidden;}
.radar-sweep{position:absolute;top:50%;left:50%;width:200%;height:200%;background:conic-gradient(from 0deg,transparent 0%,rgba(0,243,255,.1) 7%,transparent 14%);transform:translate(-50%,-50%);animation:rspin 4s linear infinite;pointer-events:none;z-index:500;}
.radar-grid{position:absolute;inset:0;pointer-events:none;z-index:499;background-image:linear-gradient(rgba(0,243,255,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(0,243,255,.035) 1px,transparent 1px);background-size:50px 50px;}
@keyframes rspin{from{transform:translate(-50%,-50%) rotate(0deg);}to{transform:translate(-50%,-50%) rotate(360deg);}}
.cbracket{position:absolute;width:16px;height:16px;border-color:rgba(0,243,255,.5);border-style:solid;z-index:600;pointer-events:none;}
.cbracket.tl{top:8px;left:8px;border-width:2px 0 0 2px;}
.cbracket.tr{top:8px;right:8px;border-width:2px 2px 0 0;}
.cbracket.bl{bottom:8px;left:8px;border-width:0 0 2px 2px;}
.cbracket.br{bottom:8px;right:8px;border-width:0 2px 2px 0;}

/* ATTACK OVERLAY */
#attack-overlay{position:absolute;inset:0;background:rgba(220,38,38,.15);display:none;align-items:center;justify-content:center;flex-direction:column;border:2px solid rgba(239,68,68,.7);animation:pred .5s infinite;z-index:2000;pointer-events:none;border-radius:5px;}
@keyframes pred{0%{box-shadow:0 0 20px rgba(239,68,68,.3);}50%{box-shadow:0 0 60px rgba(239,68,68,.8);}100%{box-shadow:0 0 20px rgba(239,68,68,.3);}}

/* ALERT POPUP */
#alert-popup{position:fixed;top:20px;right:20px;z-index:9997;display:none;flex-direction:column;gap:6px;max-width:280px;}
.alert-card{background:rgba(2,6,23,.97);border:1px solid rgba(239,68,68,.5);border-radius:5px;padding:10px 12px;font-size:9px;animation:slide-in .3s ease;box-shadow:0 0 20px rgba(239,68,68,.2);}
.alert-card.warn{border-color:rgba(245,158,11,.5);box-shadow:0 0 20px rgba(245,158,11,.2);}
@keyframes slide-in{from{transform:translateX(100%);opacity:0;}to{transform:translateX(0);opacity:1;}}

/* TERMINAL */
.term-out{flex:1;padding:8px 10px;font-size:9px;line-height:1.8;overflow-y:auto;}
.term-out::-webkit-scrollbar{width:2px;}
.term-out::-webkit-scrollbar-thumb{background:rgba(0,255,65,.25);}

/* SQL MONITOR */
.sql-row{padding:4px 0;border-bottom:1px solid rgba(255,255,255,.03);font-size:8px;display:flex;align-items:flex-start;gap:6px;}
.sql-badge{flex-shrink:0;font-size:7px;font-weight:900;padding:1px 5px;border-radius:2px;}
.sql-badge.SELECT{background:rgba(0,243,255,.15);color:#00f3ff;border:1px solid rgba(0,243,255,.2);}
.sql-badge.INSERT{background:rgba(34,197,94,.15);color:#22c55e;border:1px solid rgba(34,197,94,.2);}
.sql-badge.UPDATE{background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.2);}
.sql-badge.QUERY{background:rgba(148,163,184,.1);color:#94a3b8;border:1px solid rgba(148,163,184,.15);}

/* REVENUE TICKER */
.rev-ticker-wrap{overflow:hidden;}
.rev-counter{font-family:'Orbitron',sans-serif;font-size:22px;font-weight:900;color:var(--na);line-height:1;letter-spacing:-.02em;}
.rev-prev-val{font-size:9px;opacity:.5;margin-top:2px;}

/* FINGERPRINT TABLE */
.fp-row{display:grid;grid-template-columns:80px 1fr 1fr auto;gap:4px;padding:3px 0;border-bottom:1px solid rgba(255,255,255,.03);font-size:8px;align-items:center;}
.fp-badge{font-size:7px;font-weight:900;padding:1px 4px;border-radius:2px;white-space:nowrap;}
.fp-badge.Mobile{background:rgba(188,19,254,.15);color:#c084fc;border:1px solid rgba(188,19,254,.2);}
.fp-badge.Desktop{background:rgba(0,243,255,.1);color:#67e8f9;border:1px solid rgba(0,243,255,.2);}
.fp-badge.Tablet{background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.2);}

/* PROCESS LIST */
.proc-row{display:grid;grid-template-columns:1fr auto auto;gap:6px;padding:3px 0;border-bottom:1px solid rgba(255,255,255,.03);font-size:8px;}

/* HEATMAP DOT */
.h-dot{width:12px;height:12px;border-radius:50%;position:relative;}
.h-dot::after{content:'';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:100%;height:100%;border-radius:50%;animation:hpulse 2s ease-out infinite;}
@keyframes hpulse{0%{transform:translate(-50%,-50%) scale(1);opacity:.8;}100%{transform:translate(-50%,-50%) scale(3.5);opacity:0;}}

/* FOOTER TICKER */
.ticker-wrap{overflow:hidden;white-space:nowrap;}
.ticker-content{display:inline-flex;gap:28px;animation:tick 25s linear infinite;}
@keyframes tick{0%{transform:translateX(0);}100%{transform:translateX(-50%);}}

/* PING DOT */
.pdot{width:5px;height:5px;border-radius:50%;background:var(--ng);animation:pdblink 1s infinite;}
@keyframes pdblink{0%,100%{opacity:1;box-shadow:0 0 5px var(--ng);}50%{opacity:.2;box-shadow:none;}}

/* STATUS ONLINE */
.sonline::before{content:'';display:inline-block;width:5px;height:5px;border-radius:50%;background:#22c55e;animation:pdblink 1.5s infinite;box-shadow:0 0 6px #22c55e;margin-right:5px;}

/* GLITCH */
.glitch{animation:glitch .7s linear infinite;}
@keyframes glitch{2%,64%{transform:translate(2px,0)skew(0);}4%,60%{transform:translate(-2px,0)skew(0);}62%{transform:translate(0,0)skew(4deg);text-shadow:-2px 0 var(--np),2px 0 var(--nc);}}

/* SPIN */
@keyframes spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}

/* NUCLEAR BTN */
.nuke-btn{background:rgba(239,68,68,.08);color:#ef4444;border:1px solid rgba(239,68,68,.25);padding:7px;border-radius:4px;font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;width:100%;cursor:pointer;transition:all .2s;font-family:'JetBrains Mono',monospace;}
.nuke-btn:hover{background:rgba(239,68,68,.75);color:#fff;box-shadow:0 0 20px rgba(239,68,68,.4);}

/* BOOT */
#boot-screen{position:fixed;inset:0;background:#000;z-index:9999;display:flex;align-items:center;justify-content:center;}

/* CANVAS */
#matrix-canvas{position:fixed;top:0;left:0;z-index:0;opacity:.06;width:100%;height:100%;pointer-events:none;}

/* SCROLLBAR GLOBAL */
::-webkit-scrollbar{width:2px;}
::-webkit-scrollbar-thumb{background:rgba(0,255,65,.2);}
</style>
</head>
<body>

<canvas id="matrix-canvas"></canvas>

<!-- ALERT POPUP CONTAINER -->
<div id="alert-popup"></div>

<!-- BOOT SCREEN -->
<div id="boot-screen">
  <div style="width:460px;padding:32px;">
    <div class="orb" style="font-size:10px;color:var(--ng);letter-spacing:.3em;margin-bottom:20px;text-align:center;opacity:.5;">KHATALINK OS v4.0.1 — SECURE BOOT</div>
    <div id="boot-logs" style="font-size:9px;line-height:2;"></div>
    <div style="width:100%;height:2px;background:rgba(255,255,255,.05);margin-top:16px;border-radius:10px;overflow:hidden;">
      <div id="boot-bar" style="height:100%;background:var(--ng);width:0%;transition:width .3s;box-shadow:0 0 8px var(--ng);"></div>
    </div>
    <div id="boot-pct" style="font-size:8px;text-align:right;margin-top:4px;opacity:.35;">0%</div>
  </div>
</div>

<!-- MAIN -->
<div class="main-grid">

  <!-- ══ HEADER ══ -->
  <header class="hdr">
    <div class="gp sc">
      <span class="sl">CPU</span>
      <span class="sv" id="stat-cpu" style="color:#00f3ff">--%</span>
      <div class="sbar"><div id="bar-cpu" class="sbf" style="width:0%;background:#00f3ff;"></div></div>
    </div>
    <div class="gp sc">
      <span class="sl">RAM</span>
      <span class="sv" id="stat-ram" style="color:#bc13fe">--MB</span>
      <div class="sbar"><div id="bar-ram" class="sbf" style="width:0%;background:#bc13fe;"></div></div>
    </div>
    <div class="gp sc">
      <span class="sl">Nodes</span>
      <span class="sv" id="stat-users" style="color:var(--ng)">0</span>
      <div class="sbar"><div id="bar-users" class="sbf" style="width:0%;background:var(--ng);"></div></div>
    </div>
    <div class="gp cyan sc">
      <span class="sl" style="color:#00f3ff;">Orders</span>
      <span class="sv" id="stat-orders" style="color:#fff">0</span>
    </div>
    <div class="gp amber sc">
      <span class="sl" style="color:var(--na);">Revenue Today</span>
      <span class="sv" id="hdr-rev" style="color:var(--na);font-size:12px;">₹0</span>
    </div>
    <div class="gp sc">
      <span class="sl">API/min</span>
      <span class="sv" id="stat-api-hits" style="color:#f59e0b">0</span>
    </div>
    <div class="gp sc">
      <span class="sl">Ping</span>
      <span class="sv" id="stat-ping" style="color:#22c55e;font-size:12px;">--ms</span>
    </div>
    <div class="gp sc">
      <span class="sl">DB Sync</span>
      <span class="sonline" style="font-size:8px;font-weight:900;">LIVE</span>
    </div>
    <div class="gp sc">
      <span class="sl">Threat</span>
      <span class="sv" id="stat-threat" style="color:#22c55e;font-size:9px;font-family:'JetBrains Mono'">SECURE</span>
    </div>
  </header>

  <!-- ══ MIDDLE ══ -->
  <div class="mid-grid">

    <!-- LEFT COLUMN -->
    <div class="left-col">

      <!-- TERMINAL -->
      <div class="gp" style="flex:1;display:flex;flex-direction:column;min-height:0;">
        <div class="ph">
          <span class="pt"><i class="fas fa-terminal" style="color:var(--ng);margin-right:5px;"></i>System Trace</span>
          <span style="font-size:7px;opacity:.3;font-weight:900;">KOS v4.0.1</span>
        </div>
        <div id="terminal-output" class="term-out">
          <div style="color:#3b82f6;">root@khatalink:~# init_</div>
        </div>
      </div>

      <!-- SQL QUERY MONITOR -->
      <div class="gp cyan" style="flex-shrink:0;">
        <div class="ph">
          <span class="pt" style="color:#00f3ff;"><i class="fas fa-database" style="margin-right:5px;"></i>SQL Query Monitor</span>
          <span id="sql-qps" style="font-size:7px;color:#475569;font-weight:900;">0 q/s</span>
        </div>
        <div id="sql-monitor" style="padding:6px 10px;max-height:110px;overflow-y:auto;">
          <div style="color:#475569;font-size:8px;">Waiting for queries...</div>
        </div>
      </div>

      <!-- PROCESS LIST -->
      <div class="gp" style="flex-shrink:0;">
        <div class="ph">
          <span class="pt"><i class="fas fa-microchip" style="color:#00f3ff;margin-right:5px;"></i>Processes</span>
          <span class="sonline" style="font-size:7px;font-weight:900;color:#22c55e;">LIVE</span>
        </div>
        <div style="padding:6px 10px;">
          <div class="proc-row" style="color:rgba(255,255,255,.25);font-size:7px;margin-bottom:2px;"><span>PROCESS</span><span>MEM</span><span>STATUS</span></div>
          <div id="process-list"></div>
        </div>
      </div>

    </div>

    <!-- CENTER COLUMN -->
    <div class="center-col">

      <!-- WORLD MAP (ORDER HEATMAP + API HITS) -->
      <div class="gp cyan" style="flex:1;display:flex;flex-direction:column;min-height:0;">
        <div class="ph">
          <div style="display:flex;align-items:center;gap:8px;">
            <span class="pt" style="color:#00f3ff;"><i class="fas fa-satellite-dish" style="margin-right:5px;"></i>Live Network + Order Heatmap</span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <div class="pdot"></div>
            <span style="font-size:8px;font-weight:900;" id="ping-stat">0ms</span>
            <span style="font-size:7px;background:rgba(0,243,255,.1);border:1px solid rgba(0,243,255,.2);color:#00f3ff;padding:2px 7px;border-radius:3px;font-weight:900;">SAT_LIVE</span>
            <!-- LEGEND -->
            <span style="font-size:7px;font-weight:900;color:#00f3ff;">■ API</span>
            <span style="font-size:7px;font-weight:900;color:#f59e0b;">■ ORDER</span>
          </div>
        </div>
        <div class="radar-container" style="flex:1;min-height:0;">
          <div id="attack-overlay">
            <div class="orb glitch" style="font-size:24px;font-weight:900;color:#fff;">⚠ CRITICAL BREACH</div>
            <div style="font-size:9px;font-weight:900;color:#fff;letter-spacing:.2em;margin-top:6px;">DDoS Traffic Spike Detected</div>
          </div>
          <div class="radar-sweep"></div>
          <div class="radar-grid"></div>
          <div class="cbracket tl"></div>
          <div class="cbracket tr"></div>
          <div class="cbracket bl"></div>
          <div class="cbracket br"></div>
          <div style="position:absolute;bottom:10px;left:10px;z-index:700;font-size:8px;font-weight:900;color:rgba(0,243,255,.6);pointer-events:none;">
            API HITS: <span id="map-hit-count">0</span> &nbsp;|&nbsp; ORDERS: <span id="map-order-count">0</span>
          </div>
          <div id="world-map"></div>
        </div>
      </div>

      <!-- TRAFFIC CHART -->
      <div class="gp" style="height:100px;flex-shrink:0;display:flex;flex-direction:column;">
        <div class="ph">
          <span class="pt"><i class="fas fa-chart-area" style="color:#00f3ff;margin-right:5px;"></i>API Request Rate</span>
          <div style="display:flex;gap:10px;font-size:7px;font-weight:900;">
            <span style="color:#00f3ff;">■ REQUESTS</span>
            <span style="color:#ef4444;">■ ERRORS</span>
            <span style="color:#f59e0b;">■ ORDERS</span>
          </div>
        </div>
        <div style="flex:1;padding:3px 8px;min-height:0;">
          <canvas id="trafficChart" style="width:100%;height:100%;"></canvas>
        </div>
      </div>

    </div>

    <!-- RIGHT COLUMN -->
    <div class="right-col">

      <!-- REVENUE TICKER -->
      <div class="gp amber" style="flex-shrink:0;">
        <div class="ph">
          <span class="pt" style="color:var(--na);"><i class="fas fa-rupee-sign" style="margin-right:5px;"></i>Revenue Tracker</span>
          <span id="rev-growth" style="font-size:7px;font-weight:900;color:#22c55e;">+0%</span>
        </div>
        <div style="padding:10px 12px;display:flex;flex-direction:column;gap:6px;">
          <div>
            <div style="font-size:7px;opacity:.4;font-weight:900;text-transform:uppercase;margin-bottom:2px;">TODAY</div>
            <div class="rev-counter" id="rev-today">₹0</div>
          </div>
          <div style="border-top:1px solid rgba(255,179,0,.1);padding-top:6px;">
            <div style="font-size:7px;opacity:.4;font-weight:900;text-transform:uppercase;margin-bottom:2px;">THIS MONTH</div>
            <div style="font-family:'Orbitron',sans-serif;font-size:14px;font-weight:900;color:rgba(255,179,0,.7);" id="rev-month">₹0</div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;font-size:8px;font-weight:900;padding-top:4px;border-top:1px solid rgba(255,255,255,.04);">
            <span style="opacity:.4;">vs Last Month</span>
            <span id="rev-growth-badge" style="color:#22c55e;">↑ 0%</span>
          </div>
          <!-- mini sparkline bars -->
          <div id="rev-sparkline" style="display:flex;align-items:flex-end;gap:2px;height:24px;margin-top:4px;"></div>
        </div>
      </div>

      <!-- AI SECURITY -->
      <div class="gp purple" style="flex-shrink:0;">
        <div class="ph">
          <span class="pt" style="color:#bc13fe;"><i class="fas fa-user-shield" style="margin-right:5px;"></i>AI Security</span>
        </div>
        <div style="padding:10px 12px;">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
            <div style="width:40px;height:40px;border-radius:50%;border:2px dashed rgba(188,19,254,.3);display:flex;align-items:center;justify-content:center;animation:spin 10s linear infinite;flex-shrink:0;">
              <i class="fas fa-shield-alt" style="font-size:16px;color:#bc13fe;"></i>
            </div>
            <div>
              <div style="font-size:9px;font-weight:900;color:#c084fc;" id="ai-status">ANALYZING...</div>
              <div style="font-size:7px;color:#475569;">Fraud Risk: <span id="fraud-pct" style="color:#22c55e;">0.02%</span></div>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:3px;border-top:1px solid rgba(255,255,255,.04);padding-top:6px;">
            <div style="display:flex;justify-content:space-between;font-size:8px;font-weight:900;"><span>FIREWALL</span><span style="color:#22c55e;">ACTIVE</span></div>
            <div style="display:flex;justify-content:space-between;font-size:8px;font-weight:900;"><span>SSL</span><span style="color:#22c55e;">256-BIT</span></div>
            <div style="display:flex;justify-content:space-between;font-size:8px;font-weight:900;"><span>DDOS</span><span id="ddos-status" style="color:#22c55e;">ACTIVE</span></div>
            <div style="display:flex;justify-content:space-between;font-size:8px;font-weight:900;"><span>WAF RULES</span><span style="color:#22c55e;">247</span></div>
          </div>
        </div>
      </div>

      <!-- VISITOR FINGERPRINTING -->
      <div class="gp purple" style="flex-shrink:0;">
        <div class="ph">
          <span class="pt" style="color:#bc13fe;"><i class="fas fa-fingerprint" style="margin-right:5px;"></i>Visitor Fingerprint</span>
          <span id="fp-count" style="font-size:7px;background:rgba(188,19,254,.15);border:1px solid rgba(188,19,254,.2);color:#c084fc;padding:1px 5px;border-radius:3px;font-weight:900;">0</span>
        </div>
        <div style="padding:4px 10px;">
          <div class="fp-row" style="color:rgba(255,255,255,.2);font-size:7px;margin-bottom:2px;"><span>IP</span><span>OS/Browser</span><span>Device</span><span>Hits</span></div>
          <div id="fp-output" style="max-height:110px;overflow-y:auto;">
            <div style="color:#475569;font-size:8px;padding:4px 0;">No visitors detected.</div>
          </div>
        </div>
      </div>

      <!-- SECURITY EVENTS -->
      <div class="gp red" style="flex-shrink:0;">
        <div class="ph">
          <span class="pt" style="color:#ef4444;"><i class="fas fa-exclamation-triangle" style="margin-right:5px;"></i>Security Events</span>
          <span id="sec-evt-count" style="font-size:7px;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.25);color:#ef4444;padding:1px 5px;border-radius:3px;font-weight:900;">0</span>
        </div>
        <div id="security-events-output" style="padding:6px 10px;font-size:8px;max-height:75px;overflow-y:auto;line-height:1.7;">
          <div style="color:#475569;">No recent security events.</div>
        </div>
      </div>

      <!-- AUTH ACTIVITY -->
      <div class="gp cyan" style="flex-shrink:0;">
        <div class="ph">
          <span class="pt" style="color:#00f3ff;"><i class="fas fa-fingerprint" style="margin-right:5px;"></i>Auth Activity</span>
        </div>
        <div id="auth-activity-output" style="padding:6px 10px;font-size:8px;max-height:75px;overflow-y:auto;line-height:1.7;">
          <div style="color:#475569;">No recent auth events.</div>
        </div>
      </div>

      <!-- DB HEALTH -->
      <div class="gp" style="flex-shrink:0;">
        <div class="ph">
          <span class="pt"><i class="fas fa-database" style="color:#f59e0b;margin-right:5px;"></i>DB Health</span>
        </div>
        <div style="padding:8px 12px;display:flex;flex-direction:column;gap:6px;">
          <div>
            <div style="display:flex;justify-content:space-between;font-size:8px;font-weight:900;margin-bottom:3px;"><span>STORAGE</span><span id="db-storage">-- MB</span></div>
            <div class="sbar"><div id="bar-storage" class="sbf" style="width:0%;background:#3b82f6;"></div></div>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:8px;font-weight:900;"><span>QUERY TIME</span><span id="db-qtime" style="color:#22c55e;">-- ms</span></div>
          <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.04);border-radius:3px;padding:5px 7px;">
            <div style="font-size:7px;font-weight:900;color:#475569;text-transform:uppercase;margin-bottom:2px;">Last Backup</div>
            <div style="font-size:9px;font-weight:900;color:#fff;"><?= date('d M, h:i A') ?></div>
          </div>
        </div>
      </div>

      <!-- NUCLEAR WIPE -->
      <div class="gp red" style="flex-shrink:0;padding:8px 10px;">
        <form method="POST" onsubmit="return confirm('⚠️ DANGER: Delete ALL debug logs permanently?')">
          <input type="hidden" name="action" value="nuclear_wipe">
          <button type="submit" class="nuke-btn"><i class="fas fa-radiation" style="margin-right:5px;"></i>NUCLEAR WIPE LOGS</button>
        </form>
      </div>

    </div>
  </div>

  <!-- ══ FOOTER TICKER ══ -->
  <footer class="gp" style="display:flex;align-items:center;gap:0;padding:0;overflow:hidden;">
    <div style="padding:0 10px;border-right:1px solid rgba(255,255,255,.05);display:flex;align-items:center;gap:5px;flex-shrink:0;height:100%;">
      <div class="pdot"></div>
      <span class="orb" style="font-size:7px;font-weight:900;color:#22c55e;letter-spacing:.15em;">LIVE</span>
    </div>
    <div class="ticker-wrap" style="flex:1;">
      <div class="ticker-content" id="api-ticker">
        <span style="font-size:8px;font-weight:900;color:#475569;">WAITING FOR EVENTS...</span>
      </div>
    </div>
    <div id="live-clock" style="padding:0 10px;border-left:1px solid rgba(255,255,255,.05);font-size:7px;font-weight:900;color:#475569;flex-shrink:0;white-space:nowrap;"></div>
  </footer>

</div>

<!-- AUDIO CONTEXT FOR ALERTS -->
<script>
// ══ AUDIO ALERT ══
let audioCtx = null;
function ensureAudio() {
  if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
}
function playAlertBeep(freq = 880, type = 'sine', duration = 0.3) {
  try {
    ensureAudio();
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.connect(gain); gain.connect(audioCtx.destination);
    osc.frequency.value = freq; osc.type = type;
    gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
    osc.start(audioCtx.currentTime);
    osc.stop(audioCtx.currentTime + duration);
  } catch(e){}
}
function playErrorBeep() { playAlertBeep(220, 'sawtooth', 0.5); }
function playWarnBeep()  { playAlertBeep(440, 'square', 0.25); }

// ══ ALERT POPUP SYSTEM ══
let alertQueue = [];
function showAlert(title, msg, type = 'error') {
  const popup = document.getElementById('alert-popup');
  popup.style.display = 'flex';
  const card = document.createElement('div');
  card.className = 'alert-card' + (type === 'warn' ? ' warn' : '');
  const icon = type === 'warn' ? '⚠️' : '🚨';
  const color = type === 'warn' ? '#f59e0b' : '#ef4444';
  card.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
      <span style="font-weight:900;color:${color};">${icon} ${title}</span>
      <span onclick="this.parentElement.parentElement.remove()" style="cursor:pointer;opacity:.5;font-size:10px;">✕</span>
    </div>
    <div style="color:#94a3b8;">${msg}</div>
    <div style="font-size:7px;opacity:.4;margin-top:4px;">${new Date().toLocaleTimeString()}</div>
  `;
  popup.appendChild(card);
  if (type === 'error') playErrorBeep();
  else playWarnBeep();
  setTimeout(() => {
    card.style.transition = 'opacity .4s';
    card.style.opacity = '0';
    setTimeout(() => { card.remove(); if (!popup.children.length) popup.style.display = 'none'; }, 400);
  }, 6000);
}

// ══ MATRIX ══
const canvas = document.getElementById('matrix-canvas');
const ctx = canvas.getContext('2d');
canvas.width = window.innerWidth; canvas.height = window.innerHeight;
const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789$#@%&*><[]{}|";
const fontSize = 12; const columns = Math.floor(canvas.width / fontSize);
const drops = Array(columns).fill(1);
function drawMatrix() {
  ctx.fillStyle = "rgba(0,0,0,0.05)"; ctx.fillRect(0,0,canvas.width,canvas.height);
  ctx.fillStyle="#0F0"; ctx.font=fontSize+"px monospace";
  for(let i=0;i<drops.length;i++){
    ctx.fillText(chars[Math.floor(Math.random()*chars.length)], i*fontSize, drops[i]*fontSize);
    if(drops[i]*fontSize>canvas.height&&Math.random()>.975)drops[i]=0;
    drops[i]++;
  }
}
setInterval(drawMatrix, 50);
window.addEventListener('resize',()=>{canvas.width=window.innerWidth;canvas.height=window.innerHeight;});

// ══ CLOCK ══
function updateClock() {
  document.getElementById('live-clock').textContent = new Date().toLocaleString('en-IN',{hour12:false});
}
setInterval(updateClock, 1000); updateClock();

// ══ WORLD MAP ══
let map = null, mapHitCount = 0, mapOrderCount = 0, processedEvents = new Set(), entityMarkers = {};
function initMap() {
  if(map) return;
  const el = document.getElementById('world-map');
  if(!el || el.offsetHeight < 10) return;
  
  const darkStyle = [
    { "elementType": "geometry", "stylers": [{ "color": "#121212" }] },
    { "elementType": "labels.text.fill", "stylers": [{ "color": "#4f4f4f" }] },
    { "featureType": "water", "elementType": "geometry", "stylers": [{ "color": "#000000" }] },
    { "featureType": "road", "elementType": "geometry", "stylers": [{ "color": "#1d1d1d" }] },
    { "featureType": "administrative", "elementType": "geometry.stroke", "stylers": [{ "color": "#333333" }, { "weight": 1 }] }
  ];

  map = new google.maps.Map(el, {
    center: { lat: 20.5937, lng: 78.9629 },
    zoom: 5,
    mapTypeId: google.maps.MapTypeId.HYBRID, // Satellite imagery with labels/names
    disableDefaultUI: false,
    backgroundColor: '#000',
    zoomControl: true,
    mapTypeControl: false,
    streetViewControl: false
  });
}

function addApiHit(lat, lng, uri, ms) {
  if(!map) return;
  mapHitCount++;
  document.getElementById('map-hit-count').textContent = mapHitCount;
  
  const pos = { lat: parseFloat(lat), lng: parseFloat(lng) };
  const marker = new google.maps.Marker({
    position: pos,
    map: map,
    icon: { path: google.maps.SymbolPath.CIRCLE, scale: 4, fillColor: "#00f3ff", fillOpacity: 0.8, strokeWeight: 0 }
  });

  // Removed the problematic line drawing. API hits will now just be pulses.
  setTimeout(() => { marker.setMap(null); }, 6000);
}

function addOrderHit(lat, lng, data) {
  if(!map || !lat || isNaN(lat)) return;
  // ── FIX: Prevent duplicate markers for the same order ──
  const orderId = 'order_' + data.id;
  if(processedEvents.has(orderId)) return;
  processedEvents.add(orderId);

  mapOrderCount++;
  document.getElementById('map-order-count').textContent = mapOrderCount;
  
  const pos = { lat: parseFloat(lat), lng: parseFloat(lng) };
  const marker = new google.maps.Marker({
    position: pos,
    map: map,
    icon: { path: google.maps.SymbolPath.CIRCLE, scale: 7, fillColor: "#f59e0b", fillOpacity: 0.9, strokeWeight: 2, strokeColor: "#fff" }
  });

  const info = new google.maps.InfoWindow({ content: `<div style="padding:5px;"><b>ORDER #${data.id}</b><br>₹${data.amount}<br>${data.customer}</div>` });
  marker.addListener('click', () => info.open(map, marker));
  
  // Keep markers longer if data is from DB (not just a 3-second live hit)
  setTimeout(() => {
      marker.setMap(null);
      processedEvents.delete(orderId);
  }, 60000);
}

function addAuthMarker(lat, lng, actionType, ip, loc, role) {
  if(!map || lat === null || isNaN(lat) || parseFloat(lat) === 0) return;
  const pos = { lat: parseFloat(lat), lng: parseFloat(lng) };
  
  // Pin color logic based on role
  let color = '#94a3b8'; // Default gray
  if(role === 'customer') color = '#22c55e'; // Green
  else if(role === 'shop') color = '#f59e0b'; // Yellow
  else if(role === 'delivery' || role === 'rider') color = '#3b82f6'; // Blue
  
  const marker = new google.maps.Marker({
    position: pos,
    map: map,
    label: { text: actionType === 'login' ? 'IN' : 'OUT', color: '#fff', fontSize: '7px', fontWeight: '900' },
    icon: {
      path: "M 0,0 m -7,-7 a 7,7 0 1,0 14,0 a 7,7 0 1,0 -14,0",
      fillColor: color,
      fillOpacity: 1,
      strokeWeight: 2,
      strokeColor: "#fff",
      scale: 1.2
    }
  });

  const info = new google.maps.InfoWindow({ content: `<div style="padding:5px; color:${color}; font-weight:900;">${role.toUpperCase()} ${actionType.toUpperCase()}<br><span style="color:#fff;">IP: ${ip}</span><br><span style="color:#94a3b8;">${loc}</span></div>` });
  marker.addListener('click', () => info.open(map, marker));
  
  setTimeout(() => marker.setMap(null), 30000);
}

// ── NEW: Manage Persistent DB Sync Pins ──
function updateLiveEntityPins(entities) {
    if(!map) return;
    const currentIds = new Set(entities.map(e => e.id));

    // 1. Remove markers for entities no longer online
    for (let id in entityMarkers) {
        if (!currentIds.has(id)) {
            entityMarkers[id].setMap(null);
            delete entityMarkers[id];
        }
    }

    // 2. Add or Update markers
    entities.forEach(en => {
        const pos = { lat: parseFloat(en.lat), lng: parseFloat(en.lng) };
        let color = en.role === 'customer' ? '#22c55e' : (en.role === 'shop' ? '#f59e0b' : '#3b82f6');
        
        if (entityMarkers[en.id]) {
            // Smoothly move marker if it exists
            entityMarkers[en.id].setPosition(pos);
        } else {
            // Create new persistent marker
            const marker = new google.maps.Marker({
                position: pos,
                map: map,
                title: en.name,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: en.is_new ? 8 : 6,
                    fillColor: color,
                    fillOpacity: 0.9,
                    strokeWeight: en.is_new ? 3 : 1,
                    strokeColor: "#fff",
                }
            });
            
            const label = en.is_new ? '<span style="color:#ef4444;font-weight:900;">[NEW] </span>' : '';
            const info = new google.maps.InfoWindow({ 
                content: `<div style="padding:5px;color:${color};font-weight:900;">${label}${en.role.toUpperCase()}<br><span style="color:#fff;">${en.name}</span></div>` 
            });
            marker.addListener('click', () => info.open(map, marker));
            
            entityMarkers[en.id] = marker;
        }
    });
}

// ══ BOOT ══
const bootLines = [
  {t:"Initializing KhataLink OS v4.0.1...",c:"#94a3b8"},
  {t:"Mounting encrypted filesystem... [OK]",c:"#22c55e"},
  {t:"Database connection pool established.",c:"#22c55e"},
  {t:"Session verified. Auth OK.",c:"#94a3b8"},
  {t:"Loading AI threat engine...",c:"#94a3b8"},
  {t:"Revenue tracking module: ONLINE",c:"#ffb300"},
  {t:"GeoIP resolver initialized.",c:"#94a3b8"},
  {t:"Order heatmap uplink: LIVE",c:"#ffb300"},
  {t:"SQL monitor: WATCHING",c:"#00f3ff"},
  {t:"Visitor fingerprinting: ACTIVE",c:"#bc13fe"},
  {t:"Alert system: ARMED 🔔",c:"#ef4444"},
  {t:"All systems nominal. ACCESS GRANTED.",c:"#00ff41"},
];
async function startBoot() {
  const lb=document.getElementById('boot-logs'), bar=document.getElementById('boot-bar'), pct=document.getElementById('boot-pct');
  for(let i=0;i<bootLines.length;i++){
    const p=document.createElement('p');
    const prog=Math.round((i+1)/bootLines.length*100);
    p.innerHTML=`<span style="color:#2d3748;">[${String(i).padStart(2,'0')}]</span> <span style="color:${bootLines[i].c};">${bootLines[i].t}</span>`;
    lb.appendChild(p);
    bar.style.width=prog+'%';
    pct.textContent=prog+'%';
    await new Promise(r=>setTimeout(r,280));
  }
  await new Promise(r=>setTimeout(r,300));
  const bs=document.getElementById('boot-screen');
  bs.style.transition='opacity .5s'; bs.style.opacity='0';
  setTimeout(()=>{ bs.style.display='none'; initMap(); },500);
}
window.onload = startBoot;

// ══ CHART ══
const trafficCtx = document.getElementById('trafficChart').getContext('2d');
const trafficChart = new Chart(trafficCtx, {
  type:'line',
  data:{
    labels:Array(25).fill(''),
    datasets:[
      {label:'Requests',data:Array(25).fill(0),borderColor:'#00f3ff',backgroundColor:'rgba(0,243,255,.06)',borderWidth:1.5,fill:true,tension:.4,pointRadius:0},
      {label:'Errors',data:Array(25).fill(0),borderColor:'#ef4444',backgroundColor:'rgba(239,68,68,.05)',borderWidth:1,fill:true,tension:.4,pointRadius:0},
      {label:'Orders',data:Array(25).fill(0),borderColor:'#ffb300',backgroundColor:'rgba(255,179,0,.05)',borderWidth:1,fill:true,tension:.4,pointRadius:0},
    ]
  },
  options:{
    responsive:true,maintainAspectRatio:false,animation:{duration:200},
    plugins:{legend:{display:false},tooltip:{backgroundColor:'rgba(2,6,23,.97)',borderColor:'rgba(0,243,255,.3)',borderWidth:1,titleColor:'#00f3ff',bodyColor:'#94a3b8',titleFont:{family:'JetBrains Mono',size:9},bodyFont:{family:'JetBrains Mono',size:8}}},
    scales:{
      x:{display:false},
      y:{grid:{color:'rgba(255,255,255,.03)',drawBorder:false},ticks:{color:'#475569',font:{family:'JetBrains Mono',size:7},maxTicksLimit:3},border:{display:false}}
    }
  }
});

// ══ REVENUE ANIMATION ══
let displayedRevToday = 0;
function animateRevenue(target) {
  const step = (target - displayedRevToday) / 20;
  let count = 0;
  const interval = setInterval(()=>{
    displayedRevToday += step;
    count++;
    document.getElementById('rev-today').textContent = '₹' + Math.round(displayedRevToday).toLocaleString('en-IN');
    if(count >= 20){ displayedRevToday = target; clearInterval(interval); }
  }, 50);
}

// Revenue sparkline history
let revHistory = Array(14).fill(0);
function updateRevSparkline(val) {
  revHistory.push(val); revHistory.shift();
  const max = Math.max(...revHistory, 1);
  const el = document.getElementById('rev-sparkline');
  el.innerHTML = revHistory.map(v=>{
    const pct = Math.round((v/max)*100);
    return `<div style="flex:1;background:rgba(255,179,0,${0.2 + (pct/100)*0.6});border-radius:2px 2px 0 0;height:${Math.max(pct,4)}%;min-height:3px;"></div>`;
  }).join('');
}

// ══ PROCESS SIMULATION ══
const procs = [
  {n:'db_sync.service',b:12},{n:'auth_guard.service',b:8},{n:'order_worker.service',b:22},
  {n:'log_daemon.service',b:4},{n:'geo_resolver.service',b:6},{n:'cache_mgr.service',b:16},
];
const statOpts = [{l:'RUN',c:'#22c55e'},{l:'RUN',c:'#22c55e'},{l:'BUSY',c:'#f59e0b'},{l:'IDLE',c:'#475569'}];
function updateProcessList() {
  document.getElementById('process-list').innerHTML = procs.map(p=>{
    const m=p.b+Math.floor(Math.random()*5), st=statOpts[Math.floor(Math.random()*statOpts.length)];
    return `<div class="proc-row"><span style="color:#94a3b8;">${p.n}</span><span style="color:#22c55e;">${m}MB</span><span style="color:${st.c};">${st.l}</span></div>`;
  }).join('');
}

// ══ TERMINAL HELPER ══
const termOut = document.getElementById('terminal-output');
function addTermLine(html) {
  const r=document.createElement('div');
  r.innerHTML=html;
  r.style.borderBottom='1px solid rgba(255,255,255,.02)';
  r.style.paddingBottom='1px';
  termOut.appendChild(r);
  if(termOut.children.length>100) termOut.removeChild(termOut.firstChild);
  termOut.scrollTop=termOut.scrollHeight;
}

// ══ PREVIOUS ERROR TRACKING ══
let prevErrorCount = 0;
let prevAttack = false;

// ══ MAIN FETCH ══
async function fetchStats() {
  // ── FIX: Don't fetch if map isn't ready during boot sequence ──
  if(!map) return;

  try {
    const res = await fetch('ajax_hacker_stats.php');
            if (!res.ok) throw new Error("HTTP error " + res.status);
            
            const text = await res.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch(e) {
                addTermLine(`<span style="color:#ef4444;">✗ Engine Error: Received HTML instead of JSON. Check PHP logs.</span>`);
                return;
            }

    // ── HEADER STATS ──
    const cpu = data.cpu;
    document.getElementById('stat-cpu').textContent = cpu+'%';
    document.getElementById('bar-cpu').style.width = cpu+'%';
    document.getElementById('bar-cpu').style.background = cpu>75?'#ef4444':cpu>50?'#f59e0b':'#00f3ff';
    document.getElementById('stat-ram').textContent = data.ram+'MB';
    document.getElementById('bar-ram').style.width = Math.min(data.ram/8,100)+'%';
    document.getElementById('stat-users').textContent = data.active_users;
    document.getElementById('bar-users').style.width = Math.min(data.active_users*10,100)+'%';
    document.getElementById('stat-orders').textContent = data.orders_today;
    document.getElementById('stat-api-hits').textContent = data.api_hits;
    document.getElementById('db-storage').textContent = data.db_size+' MB';
    document.getElementById('bar-storage').style.width = data.db_percent+'%';
    document.getElementById('db-qtime').textContent = (Math.random()*4+0.5).toFixed(2)+' ms';

    // ── REVENUE ──
    animateRevenue(data.revenue_today || 0);
    document.getElementById('hdr-rev').textContent = '₹'+(data.revenue_today||0).toLocaleString('en-IN');
    document.getElementById('rev-month').textContent = '₹'+(data.revenue_month||0).toLocaleString('en-IN');
    const growth = data.revenue_growth || 0;
    const gEl = document.getElementById('rev-growth');
    const gBadge = document.getElementById('rev-growth-badge');
    const gColor = growth >= 0 ? '#22c55e' : '#ef4444';
    const gArrow = growth >= 0 ? '↑' : '↓';
    gEl.textContent = (growth>=0?'+':'')+growth+'%'; gEl.style.color = gColor;
    gBadge.textContent = gArrow+' '+Math.abs(growth)+'%'; gBadge.style.color = gColor;
    updateRevSparkline(data.revenue_today || 0);

    // ── THREAT + ALERTS ──
    const threatEl = document.getElementById('stat-threat');
    if(data.is_attack || data.recent_errors > 5) {
      threatEl.textContent='⚠ CRITICAL'; threatEl.style.color='#ef4444'; threatEl.classList.add('glitch');
      if(!prevAttack){ showAlert('DDoS DETECTED','Traffic spike above threshold. Shield activated.','error'); }
    } else if(data.recent_errors > 2) {
      threatEl.textContent='ELEVATED'; threatEl.style.color='#f59e0b'; threatEl.classList.remove('glitch');
      if(prevErrorCount <= 2){ showAlert('Error Spike','Multiple errors detected in logs.','warn'); }
    } else {
      threatEl.textContent='SECURE'; threatEl.style.color='#22c55e'; threatEl.classList.remove('glitch');
    }
    prevAttack = data.is_attack;
    prevErrorCount = data.recent_errors;

    // ── ATTACK OVERLAY ──
    const ao = document.getElementById('attack-overlay');
    ao.style.display = data.is_attack ? 'flex' : 'none';
    document.getElementById('ddos-status').textContent = data.is_attack ? '⚠ HIT' : 'ACTIVE';
    document.getElementById('ddos-status').style.color = data.is_attack ? '#ef4444' : '#22c55e';

    // ── AI STATUS ──
    const aiMsg = ['ANALYZING NODES...','SCANNING TRAFFIC...','ML INFERENCE RUN','PATTERN MATCH OK','THREAT EVAL DONE','BEHAVIORAL SCAN...'];
    document.getElementById('ai-status').textContent = aiMsg[Math.floor(Math.random()*aiMsg.length)];
    const fraud = data.is_attack ? (Math.random()*30+60).toFixed(1) : (Math.random()*.5).toFixed(2);
    const fEl = document.getElementById('fraud-pct');
    fEl.textContent = fraud+'%'; fEl.style.color = parseFloat(fraud)>10?'#ef4444':'#22c55e';

    // ── LIVE ENTITY SYNC (Persistent Pins) ──
    if(data.live_entities) {
      updateLiveEntityPins(data.live_entities);
    }

    // ── ORDER HEATMAP ──
    if(data.order_heatmap && data.order_heatmap.length) {
      data.order_heatmap.forEach((o,i)=>setTimeout(()=>addOrderHit(o.lat,o.lng,o),i*200));
    }

    // ── API METRICS + MAP ──
    if(data.api_metrics && data.api_metrics.length) {
      data.api_metrics.forEach((hit,i)=>{
        document.getElementById('ping-stat').textContent = hit.ms+'ms';
        document.getElementById('stat-ping').textContent = hit.ms+'ms';
        if(hit.lat && hit.lng) setTimeout(()=>addApiHit(hit.lat,hit.lng,hit.uri,hit.ms),i*120);
        addTermLine(`<span style="color:rgba(255,255,255,.15)">➜</span> [${new Date().toLocaleTimeString()}] <span style="color:#00f3ff;font-weight:900;">HIT</span> /${hit.uri} <span style="color:#fff;">${hit.ms}ms</span> <span style="color:#475569;">${hit.location||''}</span>`);
      });
    }

    // ── SQL MONITOR ──
    const sqlEl = document.getElementById('sql-monitor');
    document.getElementById('sql-qps').textContent = (data.sql_queries||[]).length+' q/s';
    if(data.sql_queries && data.sql_queries.length) {
      sqlEl.innerHTML = data.sql_queries.map(q=>`
        <div class="sql-row">
          <span class="sql-badge ${q.type||'QUERY'}">${q.type||'SQL'}</span>
          <div style="flex:1;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${q.query}">${q.query}</div>
          <span style="color:${(q.time||0)>10?'#ef4444':'#22c55e'};font-size:7px;flex-shrink:0;">${q.time||0}ms</span>
        </div>
      `).join('');
    } else {
      sqlEl.innerHTML='<div style="color:#475569;font-size:8px;">No active queries.</div>';
    }

    // ── VISITOR FINGERPRINTING ──
    const fpEl = document.getElementById('fp-output');
    const fps = data.visitor_fingerprints || [];
    document.getElementById('fp-count').textContent = fps.length;
    if(fps.length) {
      fpEl.innerHTML = fps.map(fp=>`
        <div class="fp-row">
          <span style="color:#64748b;">${fp.ip}</span>
          <span style="color:#94a3b8;">${fp.os} / ${fp.browser}</span>
          <span class="fp-badge ${fp.device}">${fp.device}</span>
          <span style="color:#22c55e;">${fp.hits}x</span>
        </div>
      `).join('');
    } else {
      fpEl.innerHTML='<div style="color:#475569;font-size:8px;padding:4px 0;">No visitors detected.</div>';
    }

    // ── CHART ──
    trafficChart.data.datasets[0].data.push(data.api_hits); trafficChart.data.datasets[0].data.shift();
    trafficChart.data.datasets[1].data.push(data.recent_errors); trafficChart.data.datasets[1].data.shift();
    trafficChart.data.datasets[2].data.push(data.orders_today); trafficChart.data.datasets[2].data.shift();
    trafficChart.update('none');

    // ── TERMINAL LOGS ──
    (data.logs||[]).forEach(log=>{
      let c='color:#475569';
      if(['FATAL','ERROR','DB_ERROR'].includes(log.level)) c='color:#ef4444;font-weight:900';
      else if(log.level==='WARNING') c='color:#f59e0b';
      else if(log.level==='DEBUG') c='color:#3b82f6';
      addTermLine(`<span style="color:#22c55e;">➜</span> [${log.timestamp}] <span style="color:rgba(255,255,255,.2);">[${log.module}]</span> <span style="${c}">${log.level}</span>: ${(log.message||'').substring(0,100)}`);
    });

    // ── SECURITY EVENTS ──
    const secOut = document.getElementById('security-events-output');
    document.getElementById('sec-evt-count').textContent = (data.security_events||[]).length;
    if(data.security_events && data.security_events.length) {
      secOut.innerHTML = data.security_events.map(ev=>`
        <div style="color:#ef4444;border-bottom:1px solid rgba(255,255,255,.04);padding:2px 0;">
          <span style="color:#dc2626;font-weight:900;">[ALERT]</span> ${ev.ip_address} → ${ev.request_type}
        </div>`).join('');
    } else secOut.innerHTML='<div style="color:#475569;">No recent security events.</div>';

    // ── AUTH ACTIVITY ──
    const authOut = document.getElementById('auth-activity-output');
    const allAuth = [...(data.login_events||[]),...(data.logout_events||[])];
    if(allAuth.length) {
      authOut.innerHTML = allAuth.map(ev=>{
        const isL = ev.message && ev.message.includes('LOGIN_SUCCESS');
        const c = isL?'#22c55e':'#f59e0b';
        const icon = isL?'fa-sign-in-alt':'fa-sign-out-alt';
        const msg = (ev.message||'').replace(/LOGIN_SUCCESS:|LOGOUT_SUCCESS:/,'').trim();
        
        // ── ONLY PIN NEW EVENTS: Prevent map clutter from re-reading logs ──
        if(ev.lat !== null && !processedEvents.has(ev.event_id)) {
            addAuthMarker(ev.lat, ev.lng, isL ? 'login' : 'logout', ev.ip, ev.location, ev.role);
            processedEvents.add(ev.event_id);
        }
        return `<div style="color:${c};padding:2px 0;"><i class="fas ${icon}" style="margin-right:3px;"></i>${msg} <span style="color:#475569;">${ev.location||''}</span></div>`;
      }).join('');
    } else authOut.innerHTML='<div style="color:#475569;">No recent auth events.</div>';

    // ── TICKER ──
    if(data.recent_api_calls && data.recent_api_calls.length) {
      const items = [...data.recent_api_calls,...data.recent_api_calls];
      document.getElementById('api-ticker').innerHTML = items.map(a=>`
        <span style="display:inline-flex;align-items:center;gap:5px;">
          <span style="font-size:7px;font-weight:900;color:#00f3ff;">GET</span>
          <span style="font-size:8px;color:#64748b;">/${a}</span>
          <span style="color:rgba(255,255,255,.08);">|</span>
        </span>`).join('');
    }

  } catch(e) {
    addTermLine(`<span style="color:#ef4444;">✗ Sync failed: ${e.message}</span>`);
  }
}

setInterval(fetchStats, 3000);
setInterval(updateProcessList, 6000);
fetchStats();
updateProcessList();

// Unlock audio on first click
document.addEventListener('click', ()=>ensureAudio(), {once:true});
</script>
</body>
</html>