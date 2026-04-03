<?php
/**
 * J&J Grocery POS — Terminal v4
 * Retail/Wholesale mode, customer name, per-item & transaction discounts,
 * held carts, atomic receipt numbers, VAT-inclusive display.
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) redirect(BASE_URL . '/index.php');
if (!hasAccess('pos')) redirect(BASE_URL . '/pages/dashboard.php');
checkSessionTimeout();

// Day-lock check: Z-Read already run for today
$biz        = getBusinessSettings($db);
$day_closed = $biz['day_closed'] ?? null;
$day_locked = $day_closed && $day_closed === date('Y-m-d');

// Products for client-side cache
$products = $db->fetchAll(
    "SELECT p.id, p.name, p.barcode, p.price_retail, p.price_wholesale,
            p.quantity, p.min_quantity, p.category_id, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     WHERE p.active = 1
     ORDER BY c.name, p.name"
);

$categories = $db->fetchAll("SELECT id, name FROM categories ORDER BY name");

// Extra barcodes
$extra_barcodes = [];
try {
    $eb = $db->fetchAll("SELECT product_id, barcode, unit_label FROM product_barcodes");
    foreach ($eb as $row) {
        $extra_barcodes[$row['product_id']][] = ['barcode' => $row['barcode'], 'unit' => $row['unit_label']];
    }
} catch (Exception $e) {}

// Pricing tiers with price_mode
$extra_tiers = [];
try {
    $et = $db->fetchAll(
        "SELECT product_id, tier_name, price, unit_label, qty_multiplier, sort_order, price_mode
         FROM product_price_tiers ORDER BY product_id, sort_order"
    );
    foreach ($et as $row) $extra_tiers[$row['product_id']][] = $row;
} catch (Exception $e) {}

// Merge tiers into products; build fallback tiers from base prices
foreach ($products as &$p) {
    $pid = $p['id'];
    $p['extra_barcodes'] = $extra_barcodes[$pid] ?? [];
    if (!empty($extra_tiers[$pid])) {
        $p['tiers'] = $extra_tiers[$pid];
    } else {
        $p['tiers'] = [];
        if ($p['price_retail']    > 0) $p['tiers'][] = ['tier_name' => 'Retail',    'price' => (float)$p['price_retail'],    'unit_label' => 'pcs',  'price_mode' => 'retail'];
        if ($p['price_wholesale'] > 0) $p['tiers'][] = ['tier_name' => 'Wholesale', 'price' => (float)$p['price_wholesale'], 'unit_label' => 'pcs',  'price_mode' => 'wholesale'];
    }
    $p['price_retail']    = (float)$p['price_retail'];
    $p['price_wholesale'] = (float)$p['price_wholesale'];
    $p['quantity']        = $p['quantity'] !== null ? (int)$p['quantity'] : null;
    $p['min_quantity']    = (int)($p['min_quantity'] ?? 5);
    $p['id']              = (int)$p['id'];
    $p['category_id']     = (int)$p['category_id'];
}
unset($p);

$products_json   = json_encode($products,   JSON_HEX_TAG);
$categories_json = json_encode($categories, JSON_HEX_TAG);
$csrf_token      = getCsrfToken();
$cashier_name    = $_SESSION['user_name'] ?? 'Cashier';
$theme           = isset($_COOKIE['pos_theme']) ? htmlspecialchars($_COOKIE['pos_theme']) : 'light';
$biz_name        = htmlspecialchars($biz['business_name'] ?? 'J&J Grocery');
$biz_address     = htmlspecialchars($biz['business_address'] ?? '');
$biz_tin         = htmlspecialchars($biz['tin'] ?? '');
$vat_registered  = (int)($biz['vat_registered'] ?? 1) === 1;
$vat_inclusive   = (int)($biz['vat_inclusive']  ?? 1) === 1;
$vat_rate        = (float)($biz['vat_rate'] ?? 0.12);
?>
<!DOCTYPE html>
<html lang="fil" data-theme="<?php echo $theme; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo APP_NAME; ?> — POS Terminal</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ════════════════════════════════════════════════════════════
   POS Terminal v4 — Design System
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
    --info:       #1565C0;
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
.ph-scan{flex:1;max-width:380px;position:relative}
.ph-scan .si{position:absolute;left:9px;top:50%;transform:translateY(-50%);opacity:.7;pointer-events:none}
#barcodeInput{width:100%;padding:6px 11px 6px 30px;border-radius:7px;border:2px solid rgba(255,255,255,.3);background:rgba(255,255,255,.14);color:#fff;font-family:var(--font);font-size:.88rem;outline:none;transition:var(--t)}
#barcodeInput::placeholder{color:rgba(255,255,255,.55)}
#barcodeInput:focus{border-color:rgba(255,255,255,.75);background:rgba(255,255,255,.2)}
.ph-right{display:flex;align-items:center;gap:8px;font-size:.76rem;white-space:nowrap}
/* Retail/Wholesale toggle */
.mode-toggle{display:flex;gap:2px;background:rgba(0,0,0,.2);border-radius:6px;padding:2px}
.mtb{padding:4px 10px;border-radius:5px;border:none;background:none;color:rgba(255,255,255,.65);cursor:pointer;font-family:var(--font);font-size:.72rem;font-weight:700;transition:var(--t)}
.mtb.active{background:#fff;color:var(--primary)}
.mtb:hover:not(.active){color:#fff;background:rgba(255,255,255,.15)}
/* Held carts badge */
.held-badge{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.28);border-radius:5px;padding:4px 9px;font-size:.72rem;font-weight:600;cursor:pointer;transition:var(--t);color:#fff}
.held-badge:hover{background:rgba(255,255,255,.25)}
.held-badge .hb-cnt{background:var(--warning);color:#fff;border-radius:20px;padding:0 5px;font-size:.68rem;font-weight:800;margin-left:4px}
.ph-cashier{opacity:.8}
.ph-cashier strong{opacity:1}
.ph-clock{font-weight:800;font-size:.98rem;font-variant-numeric:tabular-nums}
.ph-exit{color:#fff;text-decoration:none;opacity:.75;font-size:.77rem;padding:4px 8px;border-radius:5px;border:1px solid rgba(255,255,255,.22);transition:var(--t)}
.ph-exit:hover{opacity:1;background:rgba(0,0,0,.12)}
.ph-theme{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);color:#fff;cursor:pointer;padding:4px 8px;border-radius:5px;font-size:.78rem;font-family:var(--font);transition:var(--t)}
.ph-theme:hover{background:rgba(255,255,255,.22)}

/* ── Wholesale mode indicator ── */
body.wholesale-mode .ph{background:#1565C0}
body.wholesale-mode .mtb.active{color:#1565C0}

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
.cart{width:355px;flex-shrink:0;display:flex;flex-direction:column;background:var(--cart-bg);color:var(--cart-text)}
.cart-hdr{padding:10px 14px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(255,255,255,.07);flex-shrink:0}
.cart-hdr h3{font-size:.87rem;font-weight:700;opacity:.9}
.cart-cnt{background:var(--primary);color:#fff;border-radius:20px;padding:1px 9px;font-size:.72rem;font-weight:700}
.cart-body{flex:1;overflow-y:auto;padding:5px 0;min-height:0}
.cart-body::-webkit-scrollbar{width:2px}
.cart-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:2px}
.cart-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,.22);gap:8px;padding:20px}
.cart-empty .ce-i{font-size:2.3rem}
.cart-empty p{font-size:.77rem;text-align:center}
.ci{display:flex;align-items:flex-start;gap:7px;padding:7px 14px;border-bottom:1px solid rgba(255,255,255,.05);transition:background .14s}
.ci:hover{background:rgba(255,255,255,.04)}
.ci-info{flex:1;min-width:0}
.ci-nm{font-size:.8rem;font-weight:600;color:var(--cart-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ci-mt{font-size:.7rem;color:var(--cart-sub);margin-top:1px}
.ci-disc{font-size:.68rem;color:#FCD34D;margin-top:1px}
.ci-q{display:flex;align-items:center;gap:3px;margin-top:2px}
.ci-qb{width:22px;height:22px;border-radius:5px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.09);color:#fff;cursor:pointer;font-size:.82rem;display:flex;align-items:center;justify-content:center;transition:var(--t)}
.ci-qb:hover{background:rgba(255,255,255,.2)}
.ci-qn{min-width:26px;text-align:center;font-size:.83rem;font-weight:700;color:#fff}
.ci-sb{font-size:.82rem;font-weight:700;color:#fff;min-width:62px;text-align:right}
.ci-acts{display:flex;flex-direction:column;gap:3px;align-items:flex-end}
.ci-del{width:20px;height:20px;border-radius:4px;background:rgba(198,40,40,.28);border:none;color:#fca5a5;cursor:pointer;font-size:.75rem;display:flex;align-items:center;justify-content:center;transition:var(--t)}
.ci-del:hover{background:rgba(198,40,40,.55)}
.ci-dsc{width:20px;height:20px;border-radius:4px;background:rgba(253,197,0,.16);border:none;color:#FCD34D;cursor:pointer;font-size:.7rem;display:flex;align-items:center;justify-content:center;transition:var(--t)}
.ci-dsc:hover{background:rgba(253,197,0,.3)}

/* ── Customer name ── */
.cart-cust{padding:7px 14px 0;flex-shrink:0}
.cart-cust input{width:100%;padding:6px 9px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:6px;color:var(--cart-text);font-family:var(--font);font-size:.78rem;outline:none;transition:var(--t)}
.cart-cust input::placeholder{color:rgba(255,255,255,.3)}
.cart-cust input:focus{border-color:rgba(255,255,255,.4);background:rgba(255,255,255,.1)}

/* ── Totals ── */
.cart-tots{padding:10px 14px;border-top:1px solid rgba(255,255,255,.1);flex-shrink:0}
.ct-r{display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:3px;color:var(--cart-sub)}
.ct-r.disc{color:#FCD34D}
.ct-r.total{margin-top:7px;padding-top:7px;border-top:1px solid rgba(255,255,255,.15);font-size:1.35rem;font-weight:900;color:var(--cart-text)}
.ct-r.total .ctl{font-size:.95rem}
.ct-disc-btn{background:none;border:1px dashed rgba(255,255,255,.2);color:rgba(255,255,255,.45);border-radius:5px;padding:3px 8px;font-size:.7rem;cursor:pointer;font-family:var(--font);transition:var(--t);margin-top:4px;width:100%}
.ct-disc-btn:hover{border-color:rgba(253,197,0,.5);color:#FCD34D}
.ct-disc-btn.has-disc{border-color:rgba(253,197,0,.5);color:#FCD34D}

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

/* ── Shortcuts bar ── */
.pos-sc{display:flex;gap:10px;padding:4px 14px;background:rgba(0,0,0,.55);color:rgba(255,255,255,.5);font-size:.66rem;flex-shrink:0;overflow-x:auto;white-space:nowrap;align-items:center}
.pos-sc span{white-space:nowrap}
.pos-sc kbd{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:3px;padding:0 4px;font-family:var(--font);font-size:.66rem}

/* ── Modals ── */
.mo{display:none;position:fixed;inset:0;background:rgba(0,0,0,.62);z-index:500;align-items:center;justify-content:center}
.mo.open{display:flex}
.mb{background:#fff;border-radius:13px;box-shadow:var(--sh-lg);width:420px;max-width:95vw;overflow:hidden}
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
.mb-np{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;margin-top:12px}
.np{padding:10px;border-radius:7px;border:1.5px solid #E2E8F0;background:#F8FAFC;color:#1A202C;font-family:var(--font);font-size:1rem;font-weight:700;cursor:pointer;transition:var(--t);text-align:center}
[data-theme="dark"] .np{background:#0F172A;border-color:#334155;color:#F1F5F9}
.np:hover{background:#FFF5F5;border-color:var(--primary);color:var(--primary)}
.np.np0{grid-column:span 2}
.np.npd{background:#FFF5F5;color:var(--primary)}
.mb-acts{display:flex;gap:8px;margin-top:14px}
.mb-ok{flex:1;padding:12px;border-radius:8px;border:none;background:var(--primary);color:#fff;font-family:var(--font);font-size:.9rem;font-weight:700;cursor:pointer;transition:var(--t)}
.mb-ok:hover{background:var(--primary-d)}
.mb-ok:disabled{opacity:.42;pointer-events:none}
.mb-can{padding:12px 15px;border-radius:8px;border:1.5px solid #E2E8F0;background:none;font-family:var(--font);font-size:.82rem;font-weight:600;cursor:pointer;color:#64748B;transition:var(--t)}
[data-theme="dark"] .mb-can{border-color:#334155;color:#94A3B8}
.mb-can:hover{background:#F8FAFC}
.mb-quick{display:flex;gap:5px;margin-top:8px;flex-wrap:wrap}
.mb-quick button{padding:7px 11px;border-radius:6px;border:1.5px solid #E2E8F0;background:#F8FAFC;font-size:.82rem;font-weight:700;cursor:pointer;transition:var(--t)}
[data-theme="dark"] .mb-quick button{background:#0F172A;border-color:#334155;color:#F1F5F9}
.mb-quick button:hover{border-color:var(--primary);color:var(--primary);background:#FFF5F5}
/* Discount modal */
.disc-type-btns{display:flex;gap:8px;margin-bottom:12px}
.dtb{flex:1;padding:8px;border-radius:7px;border:2px solid #E2E8F0;background:#F8FAFC;font-family:var(--font);font-size:.83rem;font-weight:600;cursor:pointer;text-align:center;transition:var(--t)}
[data-theme="dark"] .dtb{background:#0F172A;border-color:#334155;color:#F1F5F9}
.dtb.active{border-color:var(--primary);background:#FFF5F5;color:var(--primary)}
[data-theme="dark"] .dtb.active{background:#2d1010}
/* Held carts modal */
.held-list{display:flex;flex-direction:column;gap:8px;max-height:300px;overflow-y:auto}
.held-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1.5px solid #E2E8F0;border-radius:8px;background:#F8FAFC;cursor:pointer;transition:var(--t)}
[data-theme="dark"] .held-item{background:#0F172A;border-color:#334155}
.held-item:hover{border-color:var(--primary);background:#FFF5F5}
[data-theme="dark"] .held-item:hover{background:#2d1010}
.held-item-info{flex:1}
.held-item-info strong{font-size:.83rem;display:block}
.held-item-info small{font-size:.7rem;color:#718096}
.held-item-total{font-size:.88rem;font-weight:700;color:var(--primary)}
.held-item-del{background:none;border:none;color:#C62828;cursor:pointer;font-size:.9rem;padding:2px 4px}

/* ── Receipt ── */
.rec-box{background:#fff;color:#111;max-width:360px;width:95vw;border-radius:13px;overflow:hidden;box-shadow:var(--sh-lg);max-height:92vh;display:flex;flex-direction:column}
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

/* ── Day-lock overlay ── */
.day-lock{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:900;display:flex;align-items:center;justify-content:center}
.day-lock-box{background:#fff;border-radius:16px;padding:40px;text-align:center;max-width:400px}
[data-theme="dark"] .day-lock-box{background:#1E293B;color:#F1F5F9}
.day-lock-box .dl-icon{font-size:3rem;margin-bottom:12px}
.day-lock-box h2{color:var(--primary);margin-bottom:8px}
.day-lock-box p{color:#555;font-size:.88rem;line-height:1.6}
[data-theme="dark"] .day-lock-box p{color:#94A3B8}

/* ── Toast ── */
.toast{position:fixed;bottom:46px;left:50%;transform:translateX(-50%);padding:9px 18px;border-radius:28px;font-family:var(--font);font-size:.82rem;font-weight:600;background:#1A202C;color:#fff;box-shadow:var(--sh-lg);z-index:900;opacity:0;pointer-events:none;transition:opacity .22s;white-space:nowrap}
.toast.show{opacity:1}
.toast.ok{background:#2E7D32}
.toast.err{background:#C62828}
.toast.warn{background:#E65100}

/* ── Mobile / Tablet responsive ── */
@media (max-width:1024px){
    .cart{width:300px}
    .pg{grid-template-columns:repeat(auto-fill,minmax(110px,1fr))}
}
@media (max-width:768px){
    .pm{flex-direction:column}
    .pp{border-right:none;border-bottom:2px solid var(--border);max-height:50vh}
    .cart{width:100%;flex-shrink:1}
    .pg{grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:5px;padding:6px}
    .ph-scan{max-width:200px}
    .ph-right{flex-wrap:wrap;gap:4px}
    .ph-logo{font-size:.8rem}
    .held-badge{padding:2px 6px;font-size:.65rem}
}
@media (max-width:480px){
    .pg{grid-template-columns:repeat(auto-fill,minmax(90px,1fr))}
    .pc{padding:7px 5px 6px}
    .pc .pc-icon{font-size:1.4rem}
    .pc .pc-name{font-size:.67rem}
    .pc .pc-price{font-size:.8rem}
    .ci{padding:5px 10px;gap:5px}
    .cart-tots td{padding:2px 14px!important;font-size:.78rem}
}

@media print{
    .ph,.pp,.pos-sc,.toast,.rec-hdr,.rec-acts{display:none!important}
    .cart,.cart-tots,.cart-body{background:#fff!important;color:#111!important;width:100%!important}
}
</style>
</head>
<body>

<?php if ($day_locked): ?>
<div class="day-lock">
    <div class="day-lock-box">
        <div class="dl-icon">🔒</div>
        <h2>Day Closed</h2>
        <p>Z-Read has been run for today (<?php echo date('F j, Y'); ?>).<br>No more transactions can be processed until tomorrow.</p>
        <a href="<?php echo BASE_URL; ?>/pages/dashboard.php" style="display:inline-block;margin-top:20px;padding:10px 24px;background:var(--primary);color:#fff;border-radius:8px;text-decoration:none;font-weight:700;">Back to Dashboard</a>
    </div>
</div>
<?php endif; ?>

<!-- ── HEADER ── -->
<header class="ph">
    <div class="ph-logo">🏪 <?php echo $biz_name; ?> <small>REG-01</small></div>
    <div class="ph-scan">
        <span class="si">🔍</span>
        <input type="text" id="barcodeInput" placeholder="Scan barcode or search…" autocomplete="off" autofocus>
    </div>
    <div class="ph-right">
        <div class="mode-toggle">
            <button class="mtb active" id="btnRetail"    onclick="setPriceMode('retail',this)">Retail</button>
            <button class="mtb"        id="btnWholesale" onclick="setPriceMode('wholesale',this)">Wholesale</button>
        </div>
        <button class="held-badge" id="heldBadge" onclick="openHeldModal()" title="Held carts (Ctrl+R)">
            🗂️ Held <span class="hb-cnt" id="heldCount">0</span>/3
        </button>
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
        <div class="cart-cust">
            <input type="text" id="custName" placeholder="👤 Customer name (optional)">
        </div>
        <div class="cart-tots" id="tots" style="display:none">
            <div class="ct-r"><span>Subtotal</span><span id="ts">₱0.00</span></div>
            <div class="ct-r disc" id="discRow" style="display:none"><span id="discLabel">Discount</span><span id="td">-₱0.00</span></div>
            <?php if ($vat_registered): ?>
            <div class="ct-r"><span id="vatLabel"><?php echo $vat_inclusive ? 'VAT (incl.)' : 'VAT 12%'; ?></span><span id="tv">₱0.00</span></div>
            <?php endif; ?>
            <div class="ct-r total"><span class="ctl">TOTAL</span><span id="tt">₱0.00</span></div>
            <button class="ct-disc-btn" id="txnDiscBtn" onclick="openTxnDiscount()">＋ Add Transaction Discount</button>
        </div>
        <div class="cart-pay">
            <button class="pay cash"  id="bC" onclick="openPay('cash')"  disabled><span class="pi">💵</span>CASH<br><small>Ctrl+1</small></button>
            <button class="pay gcash" id="bG" onclick="openPay('gcash')" disabled><span class="pi">📱</span>GCASH<br><small>Ctrl+2</small></button>
            <button class="pay card"  id="bK" onclick="openPay('card')"  disabled><span class="pi">💳</span>CARD<br><small>Ctrl+3</small></button>
        </div>
    </div>
</div>

<!-- ── SHORTCUTS ── -->
<div class="pos-sc">
    <span><kbd>Ctrl+1</kbd> Cash</span>
    <span><kbd>Ctrl+2</kbd> GCash</span>
    <span><kbd>Ctrl+3</kbd> Card</span>
    <span><kbd>Ctrl+H</kbd> Hold Cart</span>
    <span><kbd>Ctrl+R</kbd> Resume Held</span>
    <span><kbd>Ctrl+M</kbd> Toggle Mode</span>
    <span><kbd>Ctrl+Shift+C</kbd> Clear Cart</span>
    <span><kbd>Ctrl+B</kbd> Focus Scanner</span>
    <span><kbd>ESC</kbd> Close Modal</span>
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

<!-- ── ITEM DISCOUNT MODAL ── -->
<div class="mo" id="moID">
    <div class="mb" style="width:360px;max-width:95vw">
        <div class="mb-hdr"><h3>% Item Discount</h3><button class="mb-cl" onclick="closeMo()">✕</button></div>
        <div class="mb-body">
            <div class="mb-lbl" id="idItemName"></div>
            <div class="disc-type-btns">
                <button class="dtb active" id="idtPercent" onclick="setDiscType('item','percent')">% Percent</button>
                <button class="dtb"        id="idtFixed"   onclick="setDiscType('item','fixed')">₱ Fixed</button>
                <button class="dtb"        id="idtNone"    onclick="setDiscType('item','none')">✕ Remove</button>
            </div>
            <div class="mb-lbl">Discount value</div>
            <input type="number" class="mb-inp" id="idValue" min="0" step="0.01" placeholder="0" style="font-size:1.1rem;">
            <div class="mb-acts">
                <button class="mb-can" onclick="closeMo()">Cancel</button>
                <button class="mb-ok" onclick="applyItemDiscount()">Apply</button>
            </div>
        </div>
    </div>
</div>

<!-- ── TRANSACTION DISCOUNT MODAL ── -->
<div class="mo" id="moTD">
    <div class="mb" style="width:360px;max-width:95vw">
        <div class="mb-hdr"><h3>% Transaction Discount</h3><button class="mb-cl" onclick="closeMo()">✕</button></div>
        <div class="mb-body">
            <div class="disc-type-btns">
                <button class="dtb active" id="tdtPercent" onclick="setDiscType('txn','percent')">% Percent</button>
                <button class="dtb"        id="tdtFixed"   onclick="setDiscType('txn','fixed')">₱ Fixed</button>
                <button class="dtb"        id="tdtNone"    onclick="setDiscType('txn','none')">✕ Remove</button>
            </div>
            <div class="mb-lbl">Discount value</div>
            <input type="number" class="mb-inp" id="tdValue" min="0" step="0.01" placeholder="0" style="font-size:1.1rem;">
            <div class="mb-acts">
                <button class="mb-can" onclick="closeMo()">Cancel</button>
                <button class="mb-ok" onclick="applyTxnDiscount()">Apply</button>
            </div>
        </div>
    </div>
</div>

<!-- ── HELD CARTS MODAL ── -->
<div class="mo" id="moHeld">
    <div class="mb" style="width:420px;max-width:95vw">
        <div class="mb-hdr"><h3>🗂️ Held Carts</h3><button class="mb-cl" onclick="closeMo()">✕</button></div>
        <div class="mb-body">
            <div class="held-list" id="heldList">
                <p style="text-align:center;color:#718096;font-size:.83rem;">No held carts.</p>
            </div>
            <div class="mb-acts" style="margin-top:16px">
                <button class="mb-can" style="flex:1" onclick="closeMo()">Close</button>
                <button class="mb-ok" id="holdBtn" onclick="holdCart()">Hold Current Cart</button>
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
const PRODS  = <?php echo $products_json; ?>;
const BASE   = '<?php echo BASE_URL; ?>';
const CSRF   = '<?php echo $csrf_token; ?>';
const VAT_RATE       = <?php echo $vat_rate; ?>;
const VAT_INCLUSIVE  = <?php echo $vat_inclusive  ? 'true' : 'false'; ?>;
const VAT_REGISTERED = <?php echo $vat_registered ? 'true' : 'false'; ?>;
const BIZ_NAME    = '<?php echo addslashes($biz_name); ?>';
const BIZ_ADDRESS = '<?php echo addslashes($biz_address); ?>';
const BIZ_TIN     = '<?php echo addslashes($biz_tin); ?>';

const ICONS = {Grains:'🌾',Rice:'🌾',Oils:'🫙',Dairy:'🧀',Canned:'🥫',Meat:'🥩',Fruits:'🍎',Vegetables:'🥦',Cleaning:'🧹',Beverages:'🥤','_':'📦'};
const icon  = n => ICONS[n] || ICONS['_'];
const f2    = n => (+n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
const esc   = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

// ── State ─────────────────────────────────────────────────────
let cart        = [];     // [{key, pid, name, price, mode, qty, stock, discount_type, discount_value}]
let priceMode   = 'retail';
let curCat      = 'all';
let payMeth     = 'cash';
let lastSale    = null;
let txnDiscount = { type:'none', value:0 };
let heldCarts   = [];     // loaded from server
let itemDiscKey = null;   // which item is being discounted
let itemDiscType = 'percent';
let txnDiscType  = 'percent';
let cartEmptyEl = null;   // cached reference to the empty-cart element

// ── Clock ─────────────────────────────────────────────────────
function updateClock(){ document.getElementById('clk').textContent = new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true}); }
setInterval(updateClock, 1000); updateClock();

// ── Theme ─────────────────────────────────────────────────────
function toggleTheme(){
    const cur  = document.documentElement.getAttribute('data-theme') || 'light';
    const next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    document.cookie = 'pos_theme=' + next + ';path=/;max-age=31536000';
    localStorage.setItem('pos_theme', next);
}

// ── Price mode ─────────────────────────────────────────────────
function setPriceMode(mode) {
    priceMode = mode;
    document.getElementById('btnRetail').classList.toggle('active',    mode === 'retail');
    document.getElementById('btnWholesale').classList.toggle('active', mode === 'wholesale');
    document.body.classList.toggle('wholesale-mode', mode === 'wholesale');
    // Recalculate cart prices when mode changes
    cart.forEach(i => {
        const p = PRODS.find(x => x.id === i.pid);
        if (p) i.price = getPrice(p, mode);
    });
    renderProds();
    renderCart();
}

// ── Price resolution ──────────────────────────────────────────
function getPrice(p, mode) {
    mode = mode || priceMode;
    const tiers = p.tiers || [];
    // Find a tier matching this mode (or 'both')
    const modeTier = tiers.find(t => {
        const m = t.price_mode || 'both';
        return m === mode || m === 'both';
    });
    if (modeTier) return +modeTier.price;
    // Fallback to base prices
    if (mode === 'wholesale' && p.price_wholesale > 0) return p.price_wholesale;
    return p.price_retail || 0;
}

// ── Render products ───────────────────────────────────────────
function renderProds() {
    const grid = document.getElementById('pg');
    const q    = (document.getElementById('ps').value || '').toLowerCase().trim();
    const list = PRODS.filter(p => {
        const catOk = curCat === 'all' || String(p.category_id) === String(curCat);
        const qOk   = !q || p.name.toLowerCase().includes(q) || (p.barcode && p.barcode.includes(q));
        return catOk && qOk;
    });
    if (!list.length) { grid.innerHTML = '<div class="pg-empty">No products found.</div>'; return; }
    grid.innerHTML = list.map(p => {
        const pr = getPrice(p), qty = p.quantity, min = p.min_quantity || 5;
        const isOut = qty !== null && qty <= 0;
        const isLow = qty !== null && qty > 0 && qty <= min;
        let stk = '';
        if (qty !== null) {
            if (isOut) stk = `<span class="pc-stk out">OUT</span>`;
            else if (isLow) stk = `<span class="pc-stk low">LOW:${qty}</span>`;
            else stk = `<span class="pc-stk">${qty}</span>`;
        }
        return `<div class="pc${isOut ? ' out-of-stock' : ''}" onclick="addToCart(${p.id})">
            ${stk}
            <div class="pc-icon">${icon(p.category_name)}</div>
            <div class="pc-name">${esc(p.name)}</div>
            <div class="pc-price">₱${f2(pr)}</div>
        </div>`;
    }).join('');
}

function filterCat(id, btn) {
    curCat = String(id);
    document.querySelectorAll('.cb').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    renderProds();
}

// ── Cart ──────────────────────────────────────────────────────
function addToCart(pid) {
    const p  = PRODS.find(x => x.id === pid);
    if (!p) return;
    const pr = getPrice(p);
    if (pr <= 0) { toast('No price for ' + priceMode + ' mode', 'warn'); return; }
    const key = `${pid}_${priceMode}`;
    const ex  = cart.find(i => i.key === key);
    if (ex) {
        if (p.quantity !== null && ex.qty >= p.quantity) { toast('Not enough stock (' + p.quantity + ' left)', 'err'); return; }
        ex.qty++;
    } else {
        cart.push({ key, pid, name: p.name, price: pr, mode: priceMode, qty: 1, stock: p.quantity, discount_type: 'none', discount_value: 0 });
    }
    renderCart();
    toast(p.name + ' added', 'ok');
}

function addByBarcode(bc) {
    const b = bc.trim();
    let p = PRODS.find(x => x.barcode === b);
    if (!p) p = PRODS.find(x => x.extra_barcodes && x.extra_barcodes.some(e => e.barcode === b));
    if (p) { addToCart(p.id); return; }
    fetch(`${BASE}/api/search-product.php?q=${encodeURIComponent(b)}`)
        .then(r => r.json())
        .then(data => {
            if (!Array.isArray(data) || !data.length) { toast('Barcode not found: ' + b, 'err'); return; }
            const prod = data[0];
            if (!PRODS.find(x => x.id === prod.id)) PRODS.push(prod);
            addToCart(prod.id);
        })
        .catch(() => toast('Barcode not found: ' + b, 'err'));
}

function updQty(key, d) {
    const i = cart.find(x => x.key === key);
    if (!i) return;
    i.qty += d;
    if (i.qty <= 0) cart = cart.filter(x => x.key !== key);
    else if (i.stock !== null && i.qty > i.stock) { i.qty = i.stock; toast('Max stock reached', 'warn'); }
    renderCart();
}

function delItem(key) { cart = cart.filter(x => x.key !== key); renderCart(); }
function clearCart() { if (!cart.length) return; if (!confirm('Clear entire cart?')) return; cart = []; txnDiscount = {type:'none',value:0}; renderCart(); }
function voidLast() { if (!cart.length) { toast('Cart is empty', 'warn'); return; } const r = cart.pop(); toast('Removed: ' + r.name, 'warn'); renderCart(); }

// ── Cart totals calculation ────────────────────────────────────
function computeTotals() {
    let itemsSubtotal = 0;
    for (const i of cart) {
        const gross = i.price * i.qty;
        let disc = 0;
        if (i.discount_type === 'percent' && i.discount_value > 0) disc = Math.round(gross * (i.discount_value / 100) * 100) / 100;
        else if (i.discount_type === 'fixed' && i.discount_value > 0) disc = Math.min(i.discount_value, gross);
        itemsSubtotal += gross - disc;
    }
    let txnDiscAmt = 0;
    if (txnDiscount.type === 'percent' && txnDiscount.value > 0) txnDiscAmt = itemsSubtotal * (txnDiscount.value / 100);
    else if (txnDiscount.type === 'fixed' && txnDiscount.value > 0) txnDiscAmt = Math.min(txnDiscount.value, itemsSubtotal);

    const subtotal = itemsSubtotal - txnDiscAmt;
    let vat = 0, total = subtotal;
    if (VAT_REGISTERED) {
        if (VAT_INCLUSIVE) {
            vat = subtotal * (VAT_RATE / (1 + VAT_RATE));
        } else {
            vat = subtotal * VAT_RATE;
            total = subtotal + vat;
        }
    }
    return {
        itemsSubtotal: Math.round(itemsSubtotal * 100) / 100,
        txnDiscAmt:    Math.round(txnDiscAmt    * 100) / 100,
        subtotal:      Math.round(subtotal       * 100) / 100,
        vat:           Math.round(vat            * 100) / 100,
        total:         Math.round(total          * 100) / 100,
    };
}

function getTotal() { return computeTotals().total; }

function renderCart() {
    const body = document.getElementById('cb2');
    const tots = document.getElementById('tots');
    const cnt  = document.getElementById('ccnt');
    const ce   = cartEmptyEl;

    if (!cart.length) {
        body.innerHTML = ''; body.appendChild(ce); ce.style.display = 'flex';
        tots.style.display = 'none';
        ['bC','bG','bK'].forEach(id => document.getElementById(id).disabled = true);
        cnt.textContent = '0 items'; return;
    }
    ce.style.display = 'none'; tots.style.display = '';
    ['bC','bG','bK'].forEach(id => document.getElementById(id).disabled = false);

    body.innerHTML = cart.map(i => {
        const gross = i.price * i.qty;
        let disc = 0;
        if (i.discount_type === 'percent' && i.discount_value > 0) disc = Math.round(gross * (i.discount_value / 100) * 100) / 100;
        else if (i.discount_type === 'fixed'   && i.discount_value > 0) disc = Math.min(i.discount_value, gross);
        const lineTotal = gross - disc;
        const discLabel = i.discount_type !== 'none' && i.discount_value > 0
            ? (i.discount_type === 'percent' ? `-${i.discount_value}%` : `-₱${f2(disc)}`)
            : '';
        return `<div class="ci">
            <div class="ci-info">
                <div class="ci-nm">${esc(i.name)}</div>
                <div class="ci-mt">₱${f2(i.price)} × ${i.qty} (${i.mode})</div>
                ${discLabel ? `<div class="ci-disc">${discLabel} discount</div>` : ''}
                <div class="ci-q">
                    <button class="ci-qb" onclick="updQty('${i.key}',-1)">−</button>
                    <span class="ci-qn">${i.qty}</span>
                    <button class="ci-qb" onclick="updQty('${i.key}',1)">+</button>
                </div>
            </div>
            <div class="ci-acts">
                <div class="ci-sb">₱${f2(lineTotal)}</div>
                <button class="ci-dsc" onclick="openItemDiscount('${i.key}')" title="Discount">%</button>
                <button class="ci-del" onclick="delItem('${i.key}')">✕</button>
            </div>
        </div>`;
    }).join('');

    const t = computeTotals();
    document.getElementById('ts').textContent = '₱' + f2(t.itemsSubtotal);

    // Transaction discount row
    const discRow   = document.getElementById('discRow');
    const discLabel = document.getElementById('discLabel');
    const tdEl      = document.getElementById('td');
    const discBtn   = document.getElementById('txnDiscBtn');
    if (t.txnDiscAmt > 0) {
        discRow.style.display = '';
        discLabel.textContent = txnDiscount.type === 'percent'
            ? `Discount (${txnDiscount.value}%)`
            : 'Discount (Fixed)';
        tdEl.textContent = '-₱' + f2(t.txnDiscAmt);
        discBtn.textContent = '✎ Edit Transaction Discount';
        discBtn.classList.add('has-disc');
    } else {
        discRow.style.display = 'none';
        discBtn.textContent   = '＋ Add Transaction Discount';
        discBtn.classList.remove('has-disc');
    }

    if (VAT_REGISTERED) {
        document.getElementById('tv').textContent = '₱' + f2(t.vat);
    }
    document.getElementById('tt').textContent = '₱' + f2(t.total);

    const n = cart.reduce((s, i) => s + i.qty, 0);
    cnt.textContent = n + ' item' + (n !== 1 ? 's' : '');
}

// ── Item discount ─────────────────────────────────────────────
function openItemDiscount(key) {
    itemDiscKey = key;
    const i = cart.find(x => x.key === key);
    if (!i) return;
    document.getElementById('idItemName').textContent = i.name;
    itemDiscType = i.discount_type !== 'none' ? i.discount_type : 'percent';
    document.getElementById('idValue').value = i.discount_type !== 'none' ? i.discount_value : '';
    setDiscType('item', itemDiscType);
    document.getElementById('moID').classList.add('open');
    document.getElementById('idValue').focus();
}

function applyItemDiscount() {
    const i = cart.find(x => x.key === itemDiscKey);
    if (!i) return closeMo();
    i.discount_type  = itemDiscType;
    i.discount_value = itemDiscType === 'none' ? 0 : Math.max(0, parseFloat(document.getElementById('idValue').value) || 0);
    renderCart();
    closeMo();
    toast('Item discount applied', 'ok');
}

// ── Transaction discount ──────────────────────────────────────
function openTxnDiscount() {
    txnDiscType = txnDiscount.type !== 'none' ? txnDiscount.type : 'percent';
    document.getElementById('tdValue').value = txnDiscount.type !== 'none' ? txnDiscount.value : '';
    setDiscType('txn', txnDiscType);
    document.getElementById('moTD').classList.add('open');
    document.getElementById('tdValue').focus();
}

function applyTxnDiscount() {
    txnDiscount.type  = txnDiscType;
    txnDiscount.value = txnDiscType === 'none' ? 0 : Math.max(0, parseFloat(document.getElementById('tdValue').value) || 0);
    renderCart();
    closeMo();
    if (txnDiscType !== 'none') toast('Transaction discount applied', 'ok');
}

// Shared discount type selector
function setDiscType(which, type) {
    if (which === 'item') {
        itemDiscType = type;
        ['Percent','Fixed','None'].forEach(n => {
            document.getElementById('idt' + n).classList.toggle('active', n.toLowerCase() === type);
        });
        document.getElementById('idValue').style.display = type === 'none' ? 'none' : '';
    } else {
        txnDiscType = type;
        ['Percent','Fixed','None'].forEach(n => {
            document.getElementById('tdt' + n).classList.toggle('active', n.toLowerCase() === type);
        });
        document.getElementById('tdValue').style.display = type === 'none' ? 'none' : '';
    }
}

// ── Held carts ────────────────────────────────────────────────
function loadHeldCarts() {
    fetch(`${BASE}/api/held-carts.php`)
        .then(r => r.json())
        .then(d => {
            heldCarts = d.carts || [];
            document.getElementById('heldCount').textContent = heldCarts.length;
        })
        .catch(() => {});
}

function holdCart() {
    if (!cart.length) { toast('Cart is empty', 'warn'); return; }
    if (heldCarts.length >= 3) { toast('Maximum 3 held carts reached', 'err'); return; }
    const label = 'Cart ' + new Date().toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit'});
    fetch(`${BASE}/api/held-carts.php`, {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
        body: JSON.stringify({
            action:     'hold',
            label:      label,
            price_mode: priceMode,
            cart:       cart,
            txn_discount: txnDiscount,
            customer_name: document.getElementById('custName').value,
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            cart = []; txnDiscount = {type:'none',value:0};
            document.getElementById('custName').value = '';
            renderCart();
            loadHeldCarts();
            closeMo();
            toast('Cart held — ' + label, 'ok');
        } else { toast(d.message || 'Failed to hold cart', 'err'); }
    })
    .catch(() => toast('Network error', 'err'));
}

function openHeldModal() {
    renderHeldList();
    document.getElementById('moHeld').classList.add('open');
}

function renderHeldList() {
    const list = document.getElementById('heldList');
    if (!heldCarts.length) {
        list.innerHTML = '<p style="text-align:center;color:#718096;font-size:.83rem;">No held carts.</p>';
        return;
    }
    list.innerHTML = heldCarts.map(h => {
        const cartData = h.cart_data || [];
        const total = cartData.reduce((s, i) => s + (i.price * i.qty), 0);
        const items = cartData.reduce((s, i) => s + i.qty, 0);
        return `<div class="held-item" onclick="resumeCart(${h.id})">
            <div class="held-item-info">
                <strong>${esc(h.label || 'Held Cart')}</strong>
                <small>${items} item${items !== 1 ? 's' : ''} · ${h.price_mode || 'retail'} mode · ${h.held_at || ''}</small>
            </div>
            <div class="held-item-total">₱${f2(total)}</div>
            <button class="held-item-del" onclick="deleteHeldCart(event,${h.id})">🗑️</button>
        </div>`;
    }).join('');
}

function resumeCart(id) {
    if (cart.length && !confirm('Current cart has items. Hold it first or clear to resume?')) return;
    fetch(`${BASE}/api/held-carts.php`, {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
        body: JSON.stringify({action:'resume', id})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            cart = d.cart || [];
            txnDiscount = d.txn_discount || {type:'none',value:0};
            priceMode = d.price_mode || 'retail';
            setPriceMode(priceMode);
            document.getElementById('custName').value = d.customer_name || '';
            renderCart();
            loadHeldCarts();
            closeMo();
            toast('Cart resumed', 'ok');
        } else { toast(d.message || 'Failed to resume cart', 'err'); }
    })
    .catch(() => toast('Network error', 'err'));
}

function deleteHeldCart(e, id) {
    e.stopPropagation();
    if (!confirm('Delete this held cart?')) return;
    fetch(`${BASE}/api/held-carts.php`, {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
        body: JSON.stringify({action:'delete', id})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { loadHeldCarts(); renderHeldList(); toast('Held cart deleted', 'warn'); }
    })
    .catch(() => {});
}

// ── Payment ───────────────────────────────────────────────────
function openPay(m) {
    if (!cart.length) return;
    payMeth = m;
    const tot = getTotal();
    const titles = {cash:'💵 Cash Payment', gcash:'📱 GCash Payment', card:'💳 Card Payment'};
    document.getElementById('pmT').textContent = titles[m];
    document.getElementById('pmD').textContent = '₱' + f2(tot);
    const inp = document.getElementById('pmA');
    inp.value = m !== 'cash' ? f2(tot) : '';
    document.getElementById('pmNp').style.display = m === 'cash' ? 'grid' : 'none';
    const bills = [20, 50, 100, 200, 500, 1000].filter(b => b >= tot).slice(0, 4);
    document.getElementById('pmQ').innerHTML = bills.map(b =>
        `<button onclick="document.getElementById('pmA').value='${b}';updChg()" style="padding:6px 10px;border-radius:6px;border:1.5px solid #E2E8F0;background:#F8FAFC;font-size:.8rem;font-weight:700;cursor:pointer;">₱${b}</button>`
    ).join('');
    updChg();
    document.getElementById('moP').classList.add('open');
    if (m === 'cash') inp.focus();
}

function np(k) {
    const inp = document.getElementById('pmA');
    if (k === 'del') inp.value = inp.value.slice(0, -1);
    else if (k === '.' && inp.value.includes('.')) {}
    else inp.value += k;
    updChg();
}

function updChg() {
    const tot = getTotal(), ten = parseFloat(document.getElementById('pmA').value) || 0;
    const box = document.getElementById('pmC'), ok = document.getElementById('pmOk');
    if (payMeth !== 'cash') {
        document.getElementById('pmCv').textContent = '₱' + f2(tot);
        box.classList.remove('short'); ok.disabled = false; return;
    }
    if (ten >= tot) {
        document.getElementById('pmCv').textContent = '₱' + f2(ten - tot);
        box.classList.remove('short');
        box.querySelector('.chl').textContent = 'Change';
        ok.disabled = false;
    } else {
        document.getElementById('pmCv').textContent = '-₱' + f2(tot - ten);
        box.classList.add('short');
        box.querySelector('.chl').textContent = 'Short by';
        ok.disabled = true;
    }
}

function doPay() {
    const tot    = getTotal();
    const ten    = payMeth === 'cash' ? parseFloat(document.getElementById('pmA').value) : tot;
    const t      = computeTotals();
    const payload = {
        cart: cart.map(i => ({
            id:             i.pid,
            qty:            i.qty,
            discount_type:  i.discount_type,
            discount_value: i.discount_value,
        })),
        payment_method: payMeth,
        amount_paid:    ten,
        price_mode:     priceMode,
        discount_type:  txnDiscount.type,
        discount_value: txnDiscount.value,
        customer_name:  document.getElementById('custName').value.trim() || '',
    };
    const ok = document.getElementById('pmOk');
    ok.disabled = true; ok.textContent = 'Processing…';

    fetch(`${BASE}/api/sales.php`, {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
        body: JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            lastSale = { ...d, ten, snap: [...cart], meth: payMeth };
            closeMo();
            showRec(d);
        } else {
            toast(d.message || 'Transaction failed', 'err');
            ok.disabled = false; ok.textContent = 'Confirm';
        }
    })
    .catch(() => { toast('Network error. Retry.', 'err'); ok.disabled = false; ok.textContent = 'Confirm'; });
}

// ── Receipt ───────────────────────────────────────────────────
function showRec(d) {
    const items   = lastSale.snap;
    const m       = lastSale.meth.toUpperCase();
    const now     = new Date().toLocaleString('en-PH');
    const rno     = d.receipt_number || ('OR#' + String(d.sale_id).padStart(6, '0'));
    const custLine = d.customer_name ? `<div class="ri"><span>Customer</span><span>${esc(d.customer_name)}</span></div>` : '';
    const modeLine = `<div class="ri"><span>Mode</span><span>${esc(d.price_mode || 'Retail')}</span></div>`;

    const rows = items.map(i => {
        const gross = i.price * i.qty;
        let disc = 0;
        if (i.discount_type === 'percent' && i.discount_value > 0) disc = Math.round(gross * (i.discount_value / 100) * 100) / 100;
        else if (i.discount_type === 'fixed' && i.discount_value > 0) disc = Math.min(i.discount_value, gross);
        const lineTotal = gross - disc;
        let discRow = '';
        if (disc > 0) discRow = `<div class="ri" style="color:#888;font-size:.75rem"><span style="padding-left:10px">↳ Discount</span><span>-₱${f2(disc)}</span></div>`;
        return `<div class="ri"><span>${esc(i.name)} ×${i.qty}</span><span>₱${f2(lineTotal)}</span></div>${discRow}`;
    }).join('');

    let vatSection = '';
    if (d.vat_registered) {
        if (d.vat_inclusive) {
            const net = Math.round((d.subtotal - d.tax) * 100) / 100;
            vatSection = `<div class="ri"><span>VATable Sales</span><span>₱${f2(net)}</span></div><div class="ri"><span>VAT ${Math.round(VAT_RATE*100)}%</span><span>₱${f2(d.tax)}</span></div>`;
        } else {
            vatSection = `<div class="ri"><span>Subtotal</span><span>₱${f2(d.subtotal)}</span></div><div class="ri"><span>VAT ${Math.round(VAT_RATE*100)}%</span><span>₱${f2(d.tax)}</span></div>`;
        }
    }

    const discountLine = d.discount > 0
        ? `<div class="ri"><span>Discount</span><span>-₱${f2(d.discount)}</span></div>` : '';

    document.getElementById('recC').innerHTML = `
        <div class="rs">
            <strong>${esc(BIZ_NAME)}</strong><br>
            ${BIZ_ADDRESS ? `<small>${esc(BIZ_ADDRESS)}</small><br>` : ''}
            ${BIZ_TIN ? `<small>TIN: ${esc(BIZ_TIN)}</small><br>` : ''}
            ${d.vat_registered ? '<small>VAT Registered</small><br>' : '<small>Non-VAT</small><br>'}
            <small>${now}</small>
        </div>
        <hr>
        <div class="ri"><span>Receipt #</span><span><strong>${esc(rno)}</strong></span></div>
        ${custLine}${modeLine}
        <hr>
        ${rows}
        <hr>
        ${discountLine}
        ${vatSection}
        <div class="rt"><span>TOTAL</span><span>₱${f2(d.total)}</span></div>
        ${m === 'CASH' ? `<div class="ri" style="margin-top:5px"><span>Cash</span><span>₱${f2(lastSale.ten)}</span></div><div class="ri"><span>Change</span><span>₱${f2(d.change)}</span></div>` : ''}
        <hr>
        <div class="ri"><span>Payment</span><span>${m}</span></div>
        <div class="rf">Thank you for shopping at ${esc(BIZ_NAME)}!<br>This serves as your Official Receipt.</div>`;

    document.getElementById('moR').classList.add('open');
}

function newSale() {
    closeMo();
    cart = []; txnDiscount = {type:'none',value:0};
    document.getElementById('custName').value = '';
    renderCart(); renderProds();
    document.getElementById('barcodeInput').focus();
}

// ── Modals ────────────────────────────────────────────────────
function closeMo() {
    document.querySelectorAll('.mo').forEach(m => m.classList.remove('open'));
    const ok = document.getElementById('pmOk'); ok.disabled = false; ok.textContent = 'Confirm';
    document.getElementById('barcodeInput').focus();
}

// ── Toast ─────────────────────────────────────────────────────
let ttimer;
function toast(msg, type = '') {
    const el = document.getElementById('toast');
    el.textContent = msg; el.className = 'toast show' + (type ? ' ' + type : '');
    clearTimeout(ttimer); ttimer = setTimeout(() => el.classList.remove('show'), 2600);
}

// ── Barcode scanner input ─────────────────────────────────────
const bi = document.getElementById('barcodeInput');
let scanTmr;
bi.addEventListener('input', function() {
    clearTimeout(scanTmr);
    scanTmr = setTimeout(() => {
        const v = this.value.trim();
        if (v.length >= 4) { addByBarcode(v); this.value = ''; }
        else if (v.length > 0) { document.getElementById('ps').value = v; renderProds(); this.value = ''; }
    }, 100);
});
bi.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { clearTimeout(scanTmr); const v = this.value.trim(); if (v) { addByBarcode(v); this.value = ''; } }
});

// ── Keyboard shortcuts (Ctrl+key) ─────────────────────────────
document.addEventListener('keydown', function(e) {
    const mo = document.querySelector('.mo.open');
    if (e.key === 'Escape') { e.preventDefault(); closeMo(); return; }
    if (mo) return;

    if (e.ctrlKey && !e.shiftKey) {
        const map = {
            '1': () => openPay('cash'),
            '2': () => openPay('gcash'),
            '3': () => openPay('card'),
            'h': () => holdCart(),
            'r': () => openHeldModal(),
            'b': () => bi.focus(),
            'm': () => setPriceMode(priceMode === 'retail' ? 'wholesale' : 'retail'),
        };
        if (map[e.key.toLowerCase()]) { e.preventDefault(); map[e.key.toLowerCase()](); return; }
    }
    if (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'c') {
        e.preventDefault(); clearCart(); return;
    }
    // Auto-focus scanner on printable key
    const tag = e.target.tagName;
    const inInput = (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT');
    if (!inInput && e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) bi.focus();
});

// ── Init ──────────────────────────────────────────────────────
loadHeldCarts();
renderProds();
cartEmptyEl = document.getElementById('ce');
renderCart();
</script>
</body>
</html>
