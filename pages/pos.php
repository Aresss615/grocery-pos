<?php
/**
 * J&J Grocery POS — Terminal v3
 * Supermarket-grade checkout interface
 * Roles: cashier, admin, manager
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) redirect(BASE_URL . '/index.php');
if (!hasRole('cashier') && !hasRole('admin') && !hasRole('manager')) {
    redirect(BASE_URL . '/pages/dashboard.php');
}
checkSessionTimeout();

// ── Load products for client-side ───────────────────────────
$products = $db->fetchAll(
    "SELECT p.id, p.name, p.barcode, p.price_retail, p.price_sarisar, p.price_bulk,
            p.bulk_unit, p.quantity, p.min_quantity, p.category_id, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     WHERE p.active = 1
     ORDER BY c.name, p.name"
);

$categories = $db->fetchAll("SELECT id, name FROM categories ORDER BY name");

// Extra barcodes (migration_v3 table)
$extra_barcodes = [];
try {
    $eb = $db->fetchAll("SELECT product_id, barcode, unit_label FROM product_barcodes");
    foreach ($eb as $row) $extra_barcodes[$row['product_id']][] = ['barcode'=>$row['barcode'],'unit'=>$row['unit_label']];
} catch (Exception $e) { /* table may not exist yet */ }

// Extra pricing tiers (migration_v3 table)
$extra_tiers = [];
try {
    $et = $db->fetchAll("SELECT product_id, tier_name, price, unit_label, sort_order FROM product_price_tiers ORDER BY product_id, sort_order");
    foreach ($et as $row) $extra_tiers[$row['product_id']][] = $row;
} catch (Exception $e) { /* table may not exist yet */ }

foreach ($products as &$p) {
    $pid = $p['id'];
    $p['extra_barcodes'] = $extra_barcodes[$pid] ?? [];
    if (!empty($extra_tiers[$pid])) {
        $p['tiers'] = $extra_tiers[$pid];
    } else {
        $p['tiers'] = [];
        if ($p['price_retail'] > 0) $p['tiers'][] = ['tier_name'=>'Retail','price'=>(float)$p['price_retail'],'unit_label'=>'pcs'];
        if (!empty($p['price_sarisar'])) $p['tiers'][] = ['tier_name'=>'Pack','price'=>(float)$p['price_sarisar'],'unit_label'=>'pack'];
        if (!empty($p['price_bulk']))    $p['tiers'][] = ['tier_name'=>($p['bulk_unit']?:'Bulk'),'price'=>(float)$p['price_bulk'],'unit_label'=>strtolower($p['bulk_unit']?:'bulk')];
    }
}
unset($p);

$products_json   = json_encode($products,   JSON_HEX_TAG);
$categories_json = json_encode($categories, JSON_HEX_TAG);
$csrf_token      = getCsrfToken();
$cashier_name    = $_SESSION['user_name'] ?? 'Cashier';
$theme           = isset($_COOKIE['pos_theme']) ? htmlspecialchars($_COOKIE['pos_theme']) : 'light';
?>
<!DOCTYPE html>
<html lang="fil" data-theme="<?php echo $theme; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo APP_NAME; ?> — POS Terminal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ════════════════════════════════════════════════════════════
   POS Terminal Design System
   ════════════════════════════════════════════════════════════ */
:root {
    --bg:         #F0F2F5;
    --surface:    #FFFFFF;
    --border:     #E2E8F0;
    --text:       #1A202C;
    --muted:      #718096;
    --primary:    #D32F2F;
    --primary-d:  #B71C1C;
    --success:    #2E7D32;
    --warning:    #E65100;
    --cart-bg:    #1A202C;
    --cart-text:  #F7FAFC;
    --cart-sub:   #A0AEC0;
    --card-hover: #FFF5F5;
    --r:8px; --r-lg:12px;
    --sh: 0 2px 8px rgba(0,0,0,.1);
    --sh-lg: 0 8px 32px rgba(0,0,0,.18);
    --t: all .18s cubic-bezier(.4,0,.2,1);
    --font:'Inter',system-ui,sans-serif;
}
[data-theme="dark"] {
    --bg:         #0F172A;
    --surface:    #1E293B;
    --border:     #334155;
    --text:       #F1F5F9;
    --muted:      #94A3B8;
    --cart-bg:    #0F172A;
    --cart-text:  #F1F5F9;
    --cart-sub:   #94A3B8;
    --card-hover: #1E293B;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden;font-family:var(--font);font-size:14px}
body{display:flex;flex-direction:column;background:var(--bg);color:var(--text)}

/* ── Header ── */
.ph{display:flex;align-items:center;gap:10px;padding:6px 14px;background:var(--primary);color:#fff;flex-shrink:0;height:52px;z-index:100}
.ph-logo{font-weight:800;font-size:.95rem;white-space:nowrap}
.ph-logo small{opacity:.65;font-weight:400;font-size:.72rem;margin-left:5px}
.ph-scan{flex:1;max-width:400px;position:relative}
.ph-scan .si{position:absolute;left:9px;top:50%;transform:translateY(-50%);opacity:.7;pointer-events:none}
#barcodeInput{width:100%;padding:6px 11px 6px 30px;border-radius:7px;border:2px solid rgba(255,255,255,.3);background:rgba(255,255,255,.14);color:#fff;font-family:var(--font);font-size:.88rem;outline:none;transition:var(--t)}
#barcodeInput::placeholder{color:rgba(255,255,255,.55)}
#barcodeInput:focus{border-color:rgba(255,255,255,.75);background:rgba(255,255,255,.2)}
.ph-right{display:flex;align-items:center;gap:12px;font-size:.76rem;white-space:nowrap}
.ph-tiers{display:flex;gap:3px}
.tb{padding:4px 9px;border-radius:5px;border:1px solid rgba(255,255,255,.28);background:rgba(255,255,255,.1);color:#fff;cursor:pointer;font-family:var(--font);font-size:.73rem;font-weight:600;transition:var(--t)}
.tb.active{background:rgba(255,255,255,.28);border-color:rgba(255,255,255,.7)}
.tb:hover:not(.active){background:rgba(255,255,255,.17)}
.ph-cashier{opacity:.8}
.ph-cashier strong{opacity:1}
.ph-clock{font-weight:800;font-size:.98rem;font-variant-numeric:tabular-nums}
.ph-exit{color:#fff;text-decoration:none;opacity:.75;font-size:.77rem;padding:4px 8px;border-radius:5px;border:1px solid rgba(255,255,255,.22);transition:var(--t)}
.ph-exit:hover{opacity:1;background:rgba(0,0,0,.12)}
.ph-theme{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);color:#fff;cursor:pointer;padding:4px 8px;border-radius:5px;font-size:.78rem;font-family:var(--font);transition:var(--t)}
.ph-theme:hover{background:rgba(255,255,255,.22)}

/* ── Main ── */
.pm{display:flex;flex:1;min-height:0;overflow:hidden}

/* ── Products Panel ── */
.pp{flex:1;display:flex;flex-direction:column;overflow:hidden;background:var(--bg);border-right:2px solid var(--border)}
.pp-cats{display:flex;gap:5px;padding:8px 10px;overflow-x:auto;flex-shrink:0;background:var(--surface);border-bottom:1px solid var(--border);scrollbar-width:none}
.pp-cats::-webkit-scrollbar{display:none}
.cb{padding:4px 12px;border-radius:20px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);cursor:pointer;font-family:var(--font);font-size:.75rem;font-weight:600;white-space:nowrap;transition:var(--t)}
.cb:hover{background:#FFF5F5;border-color:var(--primary);color:var(--primary)}
.cb.active{background:var(--primary);border-color:var(--primary);color:#fff}
[data-theme="dark"] .cb:hover{background:#2d1010}
[data-theme="dark"] .cb.active{background:var(--primary)}
.pp-search{padding:7px 10px;background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0}
.pp-search input{width:100%;padding:6px 10px;border:1.5px solid var(--border);border-radius:7px;font-family:var(--font);font-size:.83rem;background:var(--bg);color:var(--text);outline:none;transition:var(--t)}
.pp-search input:focus{border-color:var(--primary)}
.pg{flex:1;overflow-y:auto;padding:8px;display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:7px;align-content:start}
.pg::-webkit-scrollbar{width:3px}
.pg::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.pc{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r-lg);padding:10px 8px 8px;cursor:pointer;transition:var(--t);display:flex;flex-direction:column;align-items:center;gap:3px;text-align:center;user-select:none;position:relative;overflow:hidden}
.pc:hover{border-color:var(--primary);background:var(--card-hover);box-shadow:var(--sh);transform:translateY(-2px)}
.pc:active{transform:scale(.97)}
.pc .pc-icon{font-size:1.9rem;line-height:1}
.pc .pc-name{font-size:.75rem;font-weight:600;color:var(--text);line-height:1.3;max-height:2.4em;overflow:hidden}
.pc .pc-price{font-size:.92rem;font-weight:800;color:var(--primary)}
.pc .pc-stk{font-size:.65rem;color:var(--muted);position:absolute;top:4px;right:5px}
.pc .pc-stk.low{color:#E65100;font-weight:700}
.pc .pc-stk.out{color:#C62828;font-weight:700}
.pc.out-of-stock{opacity:.42;pointer-events:none}
.pg-empty{grid-column:1/-1;text-align:center;color:var(--muted);padding:40px 20px;font-size:.88rem}

/* ── Cart ── */
.cart{width:350px;flex-shrink:0;display:flex;flex-direction:column;background:var(--cart-bg);color:var(--cart-text)}
.cart-hdr{padding:10px 14px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(255,255,255,.07);flex-shrink:0}
.cart-hdr h3{font-size:.87rem;font-weight:700;opacity:.9}
.cart-cnt{background:var(--primary);color:#fff;border-radius:20px;padding:1px 9px;font-size:.72rem;font-weight:700}
.cart-body{flex:1;overflow-y:auto;padding:5px 0;min-height:0}
.cart-body::-webkit-scrollbar{width:2px}
.cart-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:2px}
.cart-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,.22);gap:8px;padding:20px}
.cart-empty .ce-i{font-size:2.3rem}
.cart-empty p{font-size:.77rem;text-align:center}
.ci{display:flex;align-items:center;gap:7px;padding:7px 14px;border-bottom:1px solid rgba(255,255,255,.05);transition:background .14s}
.ci:hover{background:rgba(255,255,255,.04)}
.ci-info{flex:1;min-width:0}
.ci-nm{font-size:.8rem;font-weight:600;color:var(--cart-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ci-mt{font-size:.7rem;color:var(--cart-sub);margin-top:1px}
.ci-q{display:flex;align-items:center;gap:3px}
.ci-qb{width:22px;height:22px;border-radius:5px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.09);color:#fff;cursor:pointer;font-size:.82rem;display:flex;align-items:center;justify-content:center;transition:var(--t)}
.ci-qb:hover{background:rgba(255,255,255,.2)}
.ci-qn{min-width:26px;text-align:center;font-size:.83rem;font-weight:700;color:#fff}
.ci-sb{font-size:.82rem;font-weight:700;color:#fff;min-width:62px;text-align:right}
.ci-del{width:20px;height:20px;border-radius:4px;background:rgba(198,40,40,.28);border:none;color:#fca5a5;cursor:pointer;font-size:.75rem;display:flex;align-items:center;justify-content:center;transition:var(--t)}
.ci-del:hover{background:rgba(198,40,40,.55)}

/* ── Totals ── */
.cart-tots{padding:10px 14px;border-top:1px solid rgba(255,255,255,.1);flex-shrink:0}
.ct-r{display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:3px;color:var(--cart-sub)}
.ct-r.total{margin-top:7px;padding-top:7px;border-top:1px solid rgba(255,255,255,.15);font-size:1.35rem;font-weight:900;color:var(--cart-text)}
.ct-r.total .ctl{font-size:.95rem}

/* ── Pay buttons ── */
.cart-pay{padding:8px 14px 10px;display:flex;gap:6px;flex-shrink:0}
.pay{flex:1;padding:11px 6px;border-radius:var(--r);border:none;font-family:var(--font);font-weight:700;font-size:.78rem;cursor:pointer;transition:var(--t);display:flex;flex-direction:column;align-items:center;gap:2px;line-height:1}
.pay:disabled{opacity:.38;pointer-events:none}
.pay .pi{font-size:1.25rem}
.pay.cash {background:#2E7D32;color:#fff}
.pay.gcash{background:#0277BD;color:#fff}
.pay.card {background:#6A1B9A;color:#fff}
.pay.cash:hover {background:#1B5E20}
.pay.gcash:hover{background:#01579B}
.pay.card:hover {background:#4A148C}

/* ── Shortcuts ── */
.pos-sc{display:flex;gap:10px;padding:4px 14px;background:rgba(0,0,0,.55);color:rgba(255,255,255,.5);font-size:.66rem;flex-shrink:0;overflow-x:auto;white-space:nowrap;align-items:center}
.pos-sc span{white-space:nowrap}
.pos-sc kbd{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:3px;padding:0 4px;font-family:var(--font);font-size:.66rem}

/* ── Modals ── */
.mo{display:none;position:fixed;inset:0;background:rgba(0,0,0,.62);z-index:500;align-items:center;justify-content:center}
.mo.open{display:flex}
.mb{background:#fff;border-radius:13px;box-shadow:var(--sh-lg);width:410px;max-width:95vw;overflow:hidden}
[data-theme="dark"] .mb{background:#1E293B;color:#F1F5F9}
.mb-hdr{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;background:var(--primary);color:#fff}
.mb-hdr h3{font-size:1rem;font-weight:700}
.mb-cl{background:rgba(255,255,255,.14);border:none;color:#fff;width:26px;height:26px;border-radius:6px;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;transition:var(--t)}
.mb-cl:hover{background:rgba(255,255,255,.28)}
.mb-body{padding:18px}
.mb-lbl{font-size:.77rem;font-weight:600;color:#64748B;margin-bottom:4px}
[data-theme="dark"] .mb-lbl{color:#94A3B8}
.mb-due{font-size:1.9rem;font-weight:900;color:var(--primary);margin-bottom:14px;font-variant-numeric:tabular-nums}
.mb-inp{width:100%;padding:10px 12px;border:2px solid #E2E8F0;border-radius:7px;font-size:1.3rem;font-weight:700;text-align:right;font-family:var(--font);outline:none;transition:var(--t);color:#1A202C}
[data-theme="dark"] .mb-inp{background:#0F172A;border-color:#334155;color:#F1F5F9}
.mb-inp:focus{border-color:var(--primary)}
.mb-chg{display:flex;justify-content:space-between;margin-top:10px;padding:9px 12px;background:#F0FFF4;border-radius:7px;font-weight:700}
[data-theme="dark"] .mb-chg{background:#064E3B22}
.mb-chg .chl{color:#2E7D32;font-size:.82rem}
.mb-chg .chv{color:#2E7D32;font-size:1rem}
.mb-chg.short .chl,.mb-chg.short .chv{color:#C62828}
[data-theme="dark"] .mb-chg.short{background:#450A0A22}
.mb-np{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;margin-top:12px}
.np{padding:10px;border-radius:7px;border:1.5px solid #E2E8F0;background:#F8FAFC;color:#1A202C;font-family:var(--font);font-size:1rem;font-weight:700;cursor:pointer;transition:var(--t);text-align:center}
[data-theme="dark"] .np{background:#0F172A;border-color:#334155;color:#F1F5F9}
.np:hover{background:#FFF5F5;border-color:var(--primary);color:var(--primary)}
[data-theme="dark"] .np:hover{background:#2d1010}
.np.np0{grid-column:span 2}
.np.npd{background:#FFF5F5;color:var(--primary)}
.mb-acts{display:flex;gap:8px;margin-top:14px}
.mb-ok{flex:1;padding:12px;border-radius:8px;border:none;background:var(--primary);color:#fff;font-family:var(--font);font-size:.9rem;font-weight:700;cursor:pointer;transition:var(--t)}
.mb-ok:hover{background:var(--primary-d)}
.mb-ok:disabled{opacity:.42;pointer-events:none}
.mb-can{padding:12px 15px;border-radius:8px;border:1.5px solid #E2E8F0;background:none;font-family:var(--font);font-size:.82rem;font-weight:600;cursor:pointer;color:#64748B;transition:var(--t)}
[data-theme="dark"] .mb-can{border-color:#334155;color:#94A3B8}
.mb-can:hover{background:#F8FAFC}
[data-theme="dark"] .mb-can:hover{background:#0F172A}
.mb-quick{display:flex;gap:5px;margin-top:8px;flex-wrap:wrap}
.mb-quick button{padding:7px 11px;border-radius:6px;border:1.5px solid #E2E8F0;background:#F8FAFC;font-size:.82rem;font-weight:700;cursor:pointer;transition:var(--t)}
[data-theme="dark"] .mb-quick button{background:#0F172A;border-color:#334155;color:#F1F5F9}
.mb-quick button:hover{border-color:var(--primary);color:var(--primary);background:#FFF5F5}

/* ── Receipt ── */
.rec-box{background:#fff;color:#111;max-width:350px;width:95vw;border-radius:13px;overflow:hidden;box-shadow:var(--sh-lg);max-height:90vh;display:flex;flex-direction:column}
.rec-hdr{background:var(--primary);color:#fff;padding:13px 18px;text-align:center;font-weight:700;font-size:.95rem}
.rec-body{padding:14px;overflow-y:auto;flex:1;font-size:.8rem;line-height:1.6}
.rec-body .rs{text-align:center;margin-bottom:8px}
.rec-body hr{border:none;border-top:1px dashed #ccc;margin:6px 0}
.rec-body .ri{display:flex;justify-content:space-between;margin:2px 0}
.rec-body .rt{display:flex;justify-content:space-between;font-size:.95rem;font-weight:800;margin-top:5px}
.rec-body .rf{text-align:center;margin-top:8px;font-size:.73rem;color:#666}
.rec-acts{padding:10px 14px;display:flex;gap:7px;border-top:1px solid #eee}
.rec-new{flex:1;padding:11px;border-radius:8px;border:none;background:var(--primary);color:#fff;font-family:var(--font);font-size:.88rem;font-weight:700;cursor:pointer}
.rec-prt{padding:11px 14px;border-radius:8px;border:1.5px solid #E2E8F0;background:none;font-family:var(--font);font-size:.82rem;cursor:pointer;color:#555}

/* ── Toast ── */
.toast{position:fixed;bottom:46px;left:50%;transform:translateX(-50%);padding:9px 18px;border-radius:28px;font-family:var(--font);font-size:.82rem;font-weight:600;background:#1A202C;color:#fff;box-shadow:var(--sh-lg);z-index:900;opacity:0;pointer-events:none;transition:opacity .22s;white-space:nowrap}
.toast.show{opacity:1}
.toast.ok{background:#2E7D32}
.toast.err{background:#C62828}
.toast.warn{background:#E65100}
@media print{.ph,.pp,.pos-sc,.toast,.rec-hdr,.rec-acts{display:none!important}.cart,.cart-tots,.cart-body{background:#fff!important;color:#111!important;width:100%!important}}
</style>
</head>
<body>

<!-- ── HEADER ── -->
<header class="ph">
    <div class="ph-logo">🏪 J&amp;J POS <small>REG-01</small></div>
    <div class="ph-scan">
        <span class="si">🔍</span>
        <input type="text" id="barcodeInput" placeholder="Scan barcode or type to search…" autocomplete="off" autofocus>
    </div>
    <div class="ph-right">
        <div class="ph-tiers">
            <button class="tb active" onclick="setTier('retail',this)">Retail</button>
            <button class="tb" onclick="setTier('pack',this)">Pack</button>
            <button class="tb" onclick="setTier('wholesale',this)">Bulk</button>
        </div>
        <div class="ph-cashier">👤 <strong><?php echo htmlspecialchars($cashier_name); ?></strong></div>
        <div class="ph-clock" id="clk">--:--</div>
        <button class="ph-theme" onclick="toggleTheme()" title="Toggle dark/light">🌙</button>
        <a href="<?php echo BASE_URL; ?>/pages/dashboard.php" class="ph-exit">✕ Exit</a>
    </div>
</header>

<!-- ── MAIN ── -->
<div class="pm">
    <!-- Products panel -->
    <div class="pp">
        <div class="pp-cats">
            <button class="cb active" data-cat="all" onclick="filterCat('all',this)">All</button>
            <?php foreach ($categories as $c): ?>
            <button class="cb" data-cat="<?php echo $c['id']; ?>" onclick="filterCat(<?php echo $c['id']; ?>,this)"><?php echo htmlspecialchars($c['name']); ?></button>
            <?php endforeach; ?>
        </div>
        <div class="pp-search">
            <input type="text" id="ps" placeholder="Search product name…" oninput="renderProds()">
        </div>
        <div class="pg" id="pg"></div>
    </div>

    <!-- Cart -->
    <div class="cart">
        <div class="cart-hdr">
            <h3>🛒 Sale</h3>
            <span class="cart-cnt" id="ccnt">0 items</span>
        </div>
        <div class="cart-body" id="cb2">
            <div class="cart-empty" id="ce">
                <div class="ce-i">🛒</div>
                <p>Cart is empty.<br>Scan a barcode or select a product.</p>
            </div>
        </div>
        <div class="cart-tots" id="tots" style="display:none">
            <div class="ct-r"><span>Subtotal</span><span id="ts">₱0.00</span></div>
            <div class="ct-r"><span>VAT 12%</span><span id="tv">₱0.00</span></div>
            <div class="ct-r total"><span class="ctl">TOTAL</span><span id="tt">₱0.00</span></div>
        </div>
        <div class="cart-pay">
            <button class="pay cash"  id="bC"  onclick="openPay('cash')"  disabled><span class="pi">💵</span>CASH<br><small>F1</small></button>
            <button class="pay gcash" id="bG"  onclick="openPay('gcash')" disabled><span class="pi">📱</span>GCASH<br><small>F2</small></button>
            <button class="pay card"  id="bK"  onclick="openPay('card')"  disabled><span class="pi">💳</span>CARD<br><small>F3</small></button>
        </div>
    </div>
</div>

<!-- ── SHORTCUTS ── -->
<div class="pos-sc">
    <span><kbd>F1</kbd> Cash</span>
    <span><kbd>F2</kbd> GCash</span>
    <span><kbd>F3</kbd> Card</span>
    <span><kbd>F4</kbd> Void Last</span>
    <span><kbd>F5</kbd> Clear All</span>
    <span><kbd>F8</kbd> Focus Scanner</span>
    <span><kbd>ESC</kbd> Close</span>
    <span style="margin-left:auto;opacity:.35;"><?php echo date('l, F j Y'); ?></span>
</div>

<!-- ── PAYMENT MODAL ── -->
<div class="mo" id="moP">
    <div class="mb">
        <div class="mb-hdr"><h3 id="pmT">💵 Cash Payment</h3><button class="mb-cl" onclick="closeMo()">✕</button></div>
        <div class="mb-body">
            <div class="mb-lbl">AMOUNT DUE</div>
            <div class="mb-due" id="pmD">₱0.00</div>
            <div class="mb-lbl">AMOUNT TENDERED</div>
            <input type="text" class="mb-inp" id="pmA" placeholder="0.00" oninput="updChg()" autocomplete="off">
            <div class="mb-chg" id="pmC"><span class="chl">Change</span><span class="chv" id="pmCv">₱0.00</span></div>
            <div class="mb-np" id="pmNp">
                <?php foreach([[7,8,9],[4,5,6],[1,2,3]] as $row): foreach($row as $n): ?>
                <button class="np" onclick="np('<?php echo $n; ?>')"><?php echo $n; ?></button>
                <?php endforeach; endforeach; ?>
                <button class="np np0" onclick="np('0')">0</button>
                <button class="np" onclick="np('.')">.</button>
                <button class="np npd" onclick="np('del')">⌫</button>
            </div>
            <div class="mb-quick" id="pmQ"></div>
            <div class="mb-acts">
                <button class="mb-can" onclick="closeMo()">Cancel</button>
                <button class="mb-ok" id="pmOk" onclick="doPay()" disabled>Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- ── RECEIPT MODAL ── -->
<div class="mo" id="moR">
    <div class="rec-box">
        <div class="rec-hdr">✅ Transaction Complete</div>
        <div class="rec-body" id="recC"></div>
        <div class="rec-acts">
            <button class="rec-prt" onclick="window.print()">🖨️ Print</button>
            <button class="rec-new" onclick="newSale()">🛒 New Sale</button>
        </div>
    </div>
</div>

<!-- ── TOAST ── -->
<div class="toast" id="toast"></div>

<script>
// ══════════════════════════════════════════════════════════════
const PRODS = <?php echo $products_json; ?>;
const BASE  = '<?php echo BASE_URL; ?>';
const CSRF  = '<?php echo $csrf_token; ?>';
const VAT   = 0.12;
const ICONS = {Grains:'🌾',Rice:'🌾',Oils:'🫙',Dairy:'🧀',Canned:'🥫',Meat:'🥩',Fruits:'🍎',Vegetables:'🥦',Cleaning:'🧹',Beverages:'🥤','_':'📦'};
const icon  = n => ICONS[n] || ICONS['_'];
const f2    = n => (+n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
const esc   = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

// State
let cart=[],tier='retail',curCat='all',payMeth='cash',lastSale=null;

// Clock
setInterval(()=>{ document.getElementById('clk').textContent=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true}); },1000);
document.getElementById('clk').textContent=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true});

// Theme
function toggleTheme(){
    const cur=document.documentElement.getAttribute('data-theme')||'light';
    const next=cur==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',next);
    document.cookie='pos_theme='+next+';path=/;max-age=31536000';
    localStorage.setItem('pos_theme',next);
}

// ── Price from product ──────────────────────────────────────
function getPrice(p){
    const tiers=p.tiers||[];
    if(tier==='pack'){
        const t=tiers.find(t=>t.tier_name.toLowerCase()==='pack'||t.unit_label==='pack');
        if(t)return+t.price;
    }
    if(tier==='wholesale'){
        const t=tiers.find(t=>{const n=t.tier_name.toLowerCase();return n!=='retail'&&n!=='pack';});
        if(t)return+t.price;
    }
    const r=tiers.find(t=>t.tier_name.toLowerCase()==='retail');
    return r?+r.price:+(p.price_retail||0);
}

// ── Render products ─────────────────────────────────────────
function renderProds(){
    const grid=document.getElementById('pg');
    const q=(document.getElementById('ps').value||'').toLowerCase().trim();
    const list=PRODS.filter(p=>{
        const catOk=curCat==='all'||String(p.category_id)===String(curCat);
        const qOk=!q||p.name.toLowerCase().includes(q)||(p.barcode&&p.barcode.includes(q));
        return catOk&&qOk;
    });
    if(!list.length){grid.innerHTML='<div class="pg-empty">No products found.</div>';return;}
    grid.innerHTML=list.map(p=>{
        const pr=getPrice(p),qty=p.quantity,min=p.min_quantity||5;
        const isOut=qty!==null&&qty<=0,isLow=qty!==null&&qty>0&&qty<=min;
        let stk='';
        if(qty!==null){
            if(isOut) stk=`<span class="pc-stk out">OUT</span>`;
            else if(isLow) stk=`<span class="pc-stk low">LOW:${qty}</span>`;
            else stk=`<span class="pc-stk">${qty}</span>`;
        }
        return `<div class="pc${isOut?' out-of-stock':''}" onclick="addToCart(${p.id})">
            ${stk}
            <div class="pc-icon">${icon(p.category_name)}</div>
            <div class="pc-name">${esc(p.name)}</div>
            <div class="pc-price">₱${f2(pr)}</div>
        </div>`;
    }).join('');
}

function filterCat(id,btn){
    curCat=String(id);
    document.querySelectorAll('.cb').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    renderProds();
}

function setTier(t,btn){
    tier=t;
    document.querySelectorAll('.tb').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    renderProds();renderCart();
}

// ── Cart ────────────────────────────────────────────────────
function addToCart(pid){
    const p=PRODS.find(x=>x.id===pid);
    if(!p)return;
    const pr=getPrice(p);
    if(pr<=0){toast('No price for this tier','warn');return;}
    const key=`${pid}_${tier}`;
    const ex=cart.find(i=>i.key===key);
    if(ex){
        if(p.quantity!==null&&ex.qty>=p.quantity){toast('Not enough stock ('+p.quantity+' left)','err');return;}
        ex.qty++;
    } else {
        cart.push({key,pid,name:p.name,price:pr,tier,qty:1,stock:p.quantity});
    }
    renderCart();
    toast(p.name+' added','ok');
}

function addByBarcode(bc){
    const b=bc.trim();
    let p=PRODS.find(x=>x.barcode===b);
    if(!p)p=PRODS.find(x=>x.extra_barcodes&&x.extra_barcodes.some(e=>e.barcode===b));
    if(p){addToCart(p.id);return;}
    // Server-side fallback — product may have been added after page load
    fetch(`${BASE}/api/search-product.php?q=${encodeURIComponent(b)}`)
        .then(r=>r.json())
        .then(data=>{
            if(!Array.isArray(data)||!data.length){toast('Barcode not found: '+b,'err');return;}
            const prod=data[0];
            if(!PRODS.find(x=>x.id===prod.id)) PRODS.push(prod);
            addToCart(prod.id);
        })
        .catch(()=>toast('Barcode not found: '+b,'err'));
}

function updQty(key,d){
    const i=cart.find(x=>x.key===key);
    if(!i)return;
    i.qty+=d;
    if(i.qty<=0)cart=cart.filter(x=>x.key!==key);
    else if(i.stock!==null&&i.qty>i.stock){i.qty=i.stock;toast('Max stock reached','warn');}
    renderCart();
}
function delItem(key){cart=cart.filter(x=>x.key!==key);renderCart();}
function clearCart(){if(!cart.length)return;if(!confirm('Clear cart?'))return;cart=[];renderCart();}
function voidLast(){if(!cart.length){toast('Cart is empty','warn');return;}const r=cart.pop();toast('Removed: '+r.name,'warn');renderCart();}

function renderCart(){
    const body=document.getElementById('cb2'),tots=document.getElementById('tots'),cnt=document.getElementById('ccnt');
    const ce=document.getElementById('ce');
    if(!cart.length){
        body.innerHTML='';body.appendChild(ce);ce.style.display='flex';
        tots.style.display='none';['bC','bG','bK'].forEach(id=>document.getElementById(id).disabled=true);
        cnt.textContent='0 items';return;
    }
    ce.style.display='none';tots.style.display='';
    ['bC','bG','bK'].forEach(id=>document.getElementById(id).disabled=false);
    let sub=0;
    body.innerHTML=cart.map(i=>{const s=i.price*i.qty;sub+=s;return`<div class="ci">
        <div class="ci-info"><div class="ci-nm">${esc(i.name)}</div><div class="ci-mt">₱${f2(i.price)} × ${i.qty} (${i.tier})</div></div>
        <div class="ci-q"><button class="ci-qb" onclick="updQty('${i.key}',-1)">−</button><span class="ci-qn">${i.qty}</span><button class="ci-qb" onclick="updQty('${i.key}',1)">+</button></div>
        <div class="ci-sb">₱${f2(s)}</div>
        <button class="ci-del" onclick="delItem('${i.key}')">✕</button>
    </div>`;}).join('');
    const vat=Math.round(sub*VAT*100)/100,tot=Math.round((sub+vat)*100)/100;
    document.getElementById('ts').textContent='₱'+f2(sub);
    document.getElementById('tv').textContent='₱'+f2(vat);
    document.getElementById('tt').textContent='₱'+f2(tot);
    const n=cart.reduce((s,i)=>s+i.qty,0);
    cnt.textContent=n+' item'+(n!==1?'s':'');
}

// ── Payment ─────────────────────────────────────────────────
function getTotal(){const s=cart.reduce((x,i)=>x+i.price*i.qty,0);return Math.round((s*(1+VAT))*100)/100;}
function getSubtotal(){return cart.reduce((x,i)=>x+i.price*i.qty,0);}

function openPay(m){
    if(!cart.length)return;
    payMeth=m;
    const tot=getTotal();
    const titles={cash:'💵 Cash Payment',gcash:'📱 GCash Payment',card:'💳 Card Payment'};
    document.getElementById('pmT').textContent=titles[m];
    document.getElementById('pmD').textContent='₱'+f2(tot);
    const inp=document.getElementById('pmA');
    inp.value=m!=='cash'?f2(tot):'';
    document.getElementById('pmNp').style.display=m==='cash'?'grid':'none';
    // quick bills
    const bills=[20,50,100,200,500,1000].filter(b=>b>=tot).slice(0,4);
    document.getElementById('pmQ').innerHTML=bills.map(b=>`<button onclick="document.getElementById('pmA').value='${b}';updChg()" style="padding:6px 10px;border-radius:6px;border:1.5px solid #E2E8F0;background:#F8FAFC;font-size:.8rem;font-weight:700;cursor:pointer;">₱${b}</button>`).join('');
    updChg();
    document.getElementById('moP').classList.add('open');
    if(m==='cash')inp.focus();
}

function np(k){
    const inp=document.getElementById('pmA');
    if(k==='del')inp.value=inp.value.slice(0,-1);
    else if(k==='.'&&inp.value.includes('.')){}
    else inp.value+=k;
    updChg();
}

function updChg(){
    const tot=getTotal(),ten=parseFloat(document.getElementById('pmA').value)||0;
    const chg=ten-tot,box=document.getElementById('pmC'),ok=document.getElementById('pmOk');
    if(payMeth!=='cash'){document.getElementById('pmCv').textContent='₱'+f2(tot);box.classList.remove('short');ok.disabled=false;return;}
    if(ten>=tot){document.getElementById('pmCv').textContent='₱'+f2(chg);box.classList.remove('short');box.querySelector('.chl').textContent='Change';ok.disabled=false;}
    else{document.getElementById('pmCv').textContent='-₱'+f2(tot-ten);box.classList.add('short');box.querySelector('.chl').textContent='Short by';ok.disabled=true;}
}

function doPay(){
    const tot=getTotal(),ten=payMeth==='cash'?parseFloat(document.getElementById('pmA').value):tot;
    const payload={cart:cart.map(i=>({id:i.pid,qty:i.qty,price_tier:i.tier,unit_price:i.price})),payment_method:payMeth,amount_paid:ten,price_tier:tier};
    const ok=document.getElementById('pmOk');ok.disabled=true;ok.textContent='Processing…';
    fetch(`${BASE}/api/sales.php`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},body:JSON.stringify(payload)})
    .then(r=>r.json()).then(d=>{
        if(d.success){lastSale={...d,ten,snap:[...cart],meth:payMeth};closeMo();showRec(d);}
        else{toast(d.message||'Transaction failed','err');ok.disabled=false;ok.textContent='Confirm';}
    }).catch(()=>{toast('Network error. Retry.','err');ok.disabled=false;ok.textContent='Confirm';});
}

// ── Receipt ─────────────────────────────────────────────────
function showRec(d){
    const items=lastSale.snap,m=lastSale.meth.toUpperCase(),now=new Date().toLocaleString('en-PH'),sid=String(d.sale_id).padStart(6,'0');
    const rows=items.map(i=>`<div class="ri"><span>${esc(i.name)} ×${i.qty}</span><span>₱${f2(i.price*i.qty)}</span></div>`).join('');
    document.getElementById('recC').innerHTML=`<div class="rs"><strong>J&amp;J GROCERY</strong><br><small>Official Receipt</small><br><small>${now}</small><br><small>OR#: ${sid} | ${m}</small></div>
    <hr>${rows}<hr>
    <div class="ri"><span>Subtotal</span><span>₱${f2(d.subtotal)}</span></div>
    <div class="ri"><span>VAT 12%</span><span>₱${f2(d.tax)}</span></div>
    <div class="rt"><span>TOTAL</span><span>₱${f2(d.total)}</span></div>
    ${d.payment==='cash'?`<div class="ri" style="margin-top:5px"><span>Cash</span><span>₱${f2(lastSale.ten)}</span></div><div class="ri"><span>Change</span><span>₱${f2(d.change)}</span></div>`:''}
    <hr><div class="rf">Thank you for shopping at J&amp;J Grocery!<br>This serves as your official receipt.<br>VAT Reg. No. 000-000-000-000 VAT</div>`;
    document.getElementById('moR').classList.add('open');
}

function newSale(){closeMo();cart=[];renderCart();renderProds();document.getElementById('barcodeInput').focus();}

// ── Close modals ────────────────────────────────────────────
function closeMo(){
    document.querySelectorAll('.mo').forEach(m=>m.classList.remove('open'));
    const ok=document.getElementById('pmOk');ok.disabled=false;ok.textContent='Confirm';
    document.getElementById('barcodeInput').focus();
}

// ── Toast ────────────────────────────────────────────────────
let tt2;
function toast(msg,type=''){const el=document.getElementById('toast');el.textContent=msg;el.className='toast show'+(type?' '+type:'');clearTimeout(tt2);tt2=setTimeout(()=>el.classList.remove('show'),2400);}

// ── Barcode input ────────────────────────────────────────────
const bi=document.getElementById('barcodeInput');
let scanTmr;
bi.addEventListener('input',function(){
    clearTimeout(scanTmr);
    scanTmr=setTimeout(()=>{
        const v=this.value.trim();
        if(v.length>=4){addByBarcode(v);this.value='';}
        else if(v.length>0){document.getElementById('ps').value=v;renderProds();this.value='';}
    },100);
});
bi.addEventListener('keydown',function(e){
    if(e.key==='Enter'){clearTimeout(scanTmr);const v=this.value.trim();if(v){addByBarcode(v);this.value='';}}}
);

// ── Keyboard shortcuts ────────────────────────────────────────
document.addEventListener('keydown',function(e){
    const tag=e.target.tagName,inIn=(tag==='INPUT'||tag==='TEXTAREA'||tag==='SELECT');
    const mo=document.querySelector('.mo.open');
    if(e.key==='Escape'){e.preventDefault();closeMo();return;}
    if(mo)return;
    const map={F1:()=>openPay('cash'),F2:()=>openPay('gcash'),F3:()=>openPay('card'),F4:voidLast,F5:clearCart,F8:()=>bi.focus()};
    if(map[e.key]){e.preventDefault();map[e.key]();return;}
    if(!inIn&&e.key.length===1&&!e.ctrlKey&&!e.altKey&&!e.metaKey)bi.focus();
});

// ── Init ──────────────────────────────────────────────────────
renderProds();renderCart();
</script>
</body>
</html>
