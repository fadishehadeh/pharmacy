<?php
$lang = $_GET['lang'] ?? (isset($_COOKIE['lang']) ? $_COOKIE['lang'] : 'ar');
$ar = ($lang === 'ar');
$dir = $ar ? 'rtl' : 'ltr';
?>
<!DOCTYPE html>
<html lang="<?= $ar ? 'ar' : 'en' ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PharmaPro: Pharmacy Software for Lebanon</title>
<meta name="description" content="Pharmacy management built for Lebanon. POS, MoPH compliance, Arabic & English.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&family=JetBrains+Mono:wght@500&display=swap">
<style>
/* ── TOKENS ── */
:root {
  --g900: #061410;
  --g800: #0A1F18;
  --g700: #0D2B22;
  --g600: #134E3A;
  --green: #10B981;
  --green-l: #34D399;
  --green-ll: #6EE7B7;
  --ground: #F0FDF8;
  --surface: #FFFFFF;
  --ink: #0A1F18;
  --ink2: #1D4035;
  --muted: #4B7A6A;
  --border: #C6E8DC;
  --border-d: #1A3D30;
  --r: 14px;
  --r-xl: 22px;
}

/* ── RESET ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; font-size: 16px; }
body { font-family: 'Inter', system-ui, sans-serif; background: var(--ground); color: var(--ink); -webkit-font-smoothing: antialiased; overflow-x: hidden; }
[dir="rtl"] body, [dir="rtl"] { font-family: 'Tajawal', system-ui, sans-serif; }
[dir="rtl"] .hero-h1 { font-family: 'Tajawal', system-ui, sans-serif; font-weight: 900; letter-spacing: 0; }
[dir="rtl"] .section-h, [dir="rtl"] .bento-h, [dir="rtl"] .how-h, [dir="rtl"] .price-tier,
[dir="rtl"] .cta-h, [dir="rtl"] .nav-wordmark, [dir="rtl"] .stat-val { font-family: 'Tajawal', system-ui, sans-serif; letter-spacing: 0; }
a { text-decoration: none; color: inherit; }
svg { display: block; }

/* ── NAV ── */
#nav {
  position: fixed; top: 0; inset-inline: 0; z-index: 200;
  padding: 0 28px;
  transition: background .3s, border-color .3s, box-shadow .3s;
}
#nav.scrolled {
  background: rgba(6,20,16,0.92);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border-bottom: 1px solid rgba(16,185,129,0.12);
  box-shadow: 0 4px 32px rgba(0,0,0,0.3);
}
.nav-inner {
  max-width: 1140px; margin: 0 auto;
  display: flex; align-items: center; justify-content: space-between;
  height: 68px;
}
.nav-logo { display: flex; align-items: center; gap: 10px; }
.nav-logo-icon {
  width: 36px; height: 36px;
  background: var(--green);
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
}
.nav-wordmark { font-family: 'Inter', system-ui, sans-serif; font-size: 18px; font-weight: 800; color: #fff; letter-spacing: -.01em; }
.nav-wordmark span { color: var(--green); }
.nav-right { display: flex; align-items: center; gap: 20px; }
.nav-link { font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.55); transition: color .2s; }
.nav-link:hover { color: #fff; }
.nav-lang {
  font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.45);
  border: 1px solid rgba(255,255,255,0.15); border-radius: 7px;
  padding: 4px 10px; transition: all .2s;
}
.nav-lang:hover { color: var(--green-l); border-color: rgba(52,211,153,0.4); }
.nav-cta {
  display: flex; align-items: center; gap: 7px;
  background: var(--green); color: var(--g900);
  font-size: 14px; font-weight: 600;
  padding: 9px 18px; border-radius: 9px;
  transition: background .2s, transform .15s, box-shadow .2s;
  box-shadow: 0 0 0 0 rgba(16,185,129,0.4);
}
.nav-cta:hover { background: var(--green-l); transform: translateY(-1px); box-shadow: 0 4px 20px rgba(16,185,129,0.4); }
@media (max-width: 640px) { .nav-link { display: none; } }

/* ── HERO ── */
#hero {
  min-height: 100vh;
  background: var(--g900);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 120px 24px 80px;
  position: relative;
  overflow: hidden;
  text-align: center;
}
/* Animated mesh */
.hero-mesh {
  position: absolute; inset: 0; pointer-events: none;
  background:
    radial-gradient(ellipse 65% 55% at 50% 0%, rgba(16,185,129,0.14) 0%, transparent 65%),
    radial-gradient(ellipse 45% 40% at 80% 80%, rgba(52,211,153,0.08) 0%, transparent 55%),
    radial-gradient(ellipse 35% 35% at 10% 60%, rgba(16,185,129,0.06) 0%, transparent 55%);
  animation: meshPulse 8s ease-in-out infinite alternate;
}
@keyframes meshPulse {
  0%   { opacity: 0.7; transform: scale(1); }
  100% { opacity: 1;   transform: scale(1.04); }
}
/* Grid overlay */
.hero-grid {
  position: absolute; inset: 0; pointer-events: none;
  background-image:
    linear-gradient(rgba(16,185,129,0.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(16,185,129,0.04) 1px, transparent 1px);
  background-size: 60px 60px;
  mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 20%, transparent 80%);
}
.hero-badge {
  display: inline-flex; align-items: center; gap: 8px;
  border: 1px solid rgba(16,185,129,0.3);
  background: rgba(16,185,129,0.08);
  border-radius: 100px;
  padding: 6px 16px 6px 10px;
  font-size: 13px; font-weight: 500; color: var(--green-l);
  margin-bottom: 32px;
  position: relative; z-index: 1;
  animation: fadeDown .7s ease both;
}
.hero-badge-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--green);
  box-shadow: 0 0 8px var(--green);
  animation: dotPulse 2s ease-in-out infinite;
}
@keyframes dotPulse { 0%,100% { opacity:1; } 50% { opacity:0.4; } }
.hero-h1 {
  font-family: 'Inter', system-ui, sans-serif;
  font-size: clamp(3rem, 7vw, 5.5rem);
  font-weight: 900;
  color: #fff;
  line-height: 1.05;
  letter-spacing: -.04em;
  text-wrap: balance;
  max-width: 820px;
  position: relative; z-index: 1;
  animation: fadeUp .7s .1s ease both;
}
.hero-h1 .gradient-text {
  background: linear-gradient(135deg, var(--green) 0%, var(--green-ll) 100%);
  -webkit-background-clip: text; background-clip: text;
  -webkit-text-fill-color: transparent;
}
.hero-sub {
  font-size: clamp(1rem, 2vw, 1.15rem);
  color: rgba(255,255,255,0.55);
  margin-top: 22px;
  max-width: 520px;
  line-height: 1.7;
  position: relative; z-index: 1;
  animation: fadeUp .7s .2s ease both;
}
.hero-actions {
  display: flex; gap: 14px; flex-wrap: wrap; justify-content: center;
  margin-top: 40px;
  position: relative; z-index: 1;
  animation: fadeUp .7s .3s ease both;
}
.btn-primary {
  display: inline-flex; align-items: center; gap: 9px;
  background: var(--green);
  color: var(--g900);
  font-size: 15px; font-weight: 600;
  padding: 14px 26px; border-radius: 11px;
  transition: background .2s, transform .15s, box-shadow .2s;
  box-shadow: 0 4px 24px rgba(16,185,129,0.35);
}
.btn-primary:hover { background: var(--green-l); transform: translateY(-2px); box-shadow: 0 8px 36px rgba(16,185,129,0.5); }
.btn-secondary {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(255,255,255,0.06);
  color: rgba(255,255,255,0.75);
  font-size: 15px; font-weight: 500;
  padding: 14px 24px; border-radius: 11px;
  border: 1px solid rgba(255,255,255,0.12);
  transition: all .2s;
}
.btn-secondary:hover { background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.25); }

/* Hero preview card */
.hero-preview {
  margin-top: 70px;
  position: relative; z-index: 1;
  width: 100%; max-width: 860px;
  animation: fadeUp .8s .4s ease both;
}
.hero-preview-glow {
  position: absolute; inset: -1px;
  border-radius: var(--r-xl);
  background: linear-gradient(135deg, rgba(16,185,129,0.4) 0%, rgba(52,211,153,0.15) 50%, rgba(16,185,129,0.05) 100%);
  filter: blur(1px);
  z-index: -1;
}
.hero-preview-card {
  background: var(--g800);
  border: 1px solid var(--border-d);
  border-radius: var(--r-xl);
  overflow: hidden;
  box-shadow: 0 32px 80px rgba(0,0,0,0.6);
}
.preview-topbar {
  background: var(--g700);
  padding: 12px 18px;
  display: flex; align-items: center; gap: 10px;
  border-bottom: 1px solid var(--border-d);
}
.preview-dots { display: flex; gap: 6px; }
.preview-dot { width: 10px; height: 10px; border-radius: 50%; }
.pd-r { background: rgba(255,80,80,0.4); }
.pd-y { background: rgba(255,189,40,0.4); }
.pd-g { background: rgba(52,211,153,0.4); }
.preview-url {
  flex: 1; text-align: center;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px; color: rgba(255,255,255,0.3);
  background: rgba(255,255,255,0.04);
  border-radius: 5px; padding: 4px 12px;
  max-width: 280px; margin: 0 auto;
}
.preview-body {
  display: grid; grid-template-columns: 220px 1fr;
  min-height: 320px;
}
@media (max-width: 640px) { .preview-body { grid-template-columns: 1fr; } }
.preview-sidebar {
  background: var(--g700);
  border-inline-end: 1px solid var(--border-d);
  padding: 16px;
  display: flex; flex-direction: column; gap: 4px;
}
@media (max-width: 640px) { .preview-sidebar { display: none; } }
.preview-sb-label {
  font-size: 9px; font-weight: 600; letter-spacing: .1em;
  text-transform: uppercase; color: rgba(255,255,255,0.2);
  padding: 8px 8px 4px;
}
.preview-sb-item {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 10px; border-radius: 7px;
  font-size: 12px; color: rgba(255,255,255,0.45);
  transition: background .15s;
}
.preview-sb-item.active { background: rgba(16,185,129,0.15); color: var(--green-l); }
.preview-sb-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.preview-main { padding: 20px; display: flex; flex-direction: column; gap: 12px; }
.preview-kpi-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.preview-kpi {
  background: var(--g700);
  border: 1px solid var(--border-d);
  border-radius: 10px;
  padding: 12px 14px;
}
.preview-kpi-label { font-size: 10px; color: rgba(255,255,255,0.35); font-weight: 500; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .07em; }
.preview-kpi-val { font-family: 'JetBrains Mono', monospace; font-size: 18px; color: #fff; font-weight: 500; }
.preview-kpi-val.green { color: var(--green); }
.preview-table-head {
  display: grid; grid-template-columns: 1fr 80px 70px 70px;
  font-size: 10px; color: rgba(255,255,255,0.25); font-weight: 600;
  letter-spacing: .07em; text-transform: uppercase;
  padding: 0 12px; margin-top: 4px;
}
.preview-row {
  display: grid; grid-template-columns: 1fr 80px 70px 70px;
  background: var(--g700);
  border: 1px solid var(--border-d);
  border-radius: 8px;
  padding: 9px 12px;
  align-items: center;
  gap: 8px;
}
.preview-med { font-size: 12px; color: rgba(255,255,255,0.8); font-weight: 500; }
.preview-generic { font-size: 10px; color: rgba(255,255,255,0.3); margin-top: 1px; }
.preview-cat {
  font-size: 10px; font-weight: 600;
  background: rgba(16,185,129,0.12);
  color: var(--green);
  padding: 2px 8px; border-radius: 20px;
  text-align: center;
}
.preview-qty { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: rgba(255,255,255,0.6); text-align: center; }
.preview-price { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--green-l); text-align: right; }

@keyframes fadeDown { from { opacity:0; transform:translateY(-14px); } to { opacity:1; transform:none; } }
@keyframes fadeUp   { from { opacity:0; transform:translateY(20px);  } to { opacity:1; transform:none; } }

/* ── STATS ── */
#stats {
  background: var(--g800);
  border-top: 1px solid var(--border-d);
  border-bottom: 1px solid var(--border-d);
  padding: 48px 24px;
}
.stats-grid {
  max-width: 900px; margin: 0 auto;
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 0;
}
@media (max-width: 640px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
.stat-item {
  text-align: center;
  padding: 24px 16px;
  border-inline-end: 1px solid var(--border-d);
}
.stat-item:last-child { border-inline-end: none; }
@media (max-width: 640px) {
  .stat-item:nth-child(2) { border-inline-end: none; }
  .stat-item:nth-child(3) { border-top: 1px solid var(--border-d); }
  .stat-item:nth-child(4) { border-top: 1px solid var(--border-d); border-inline-end: none; }
}
.stat-val {
  font-family: 'Inter', system-ui, sans-serif;
  font-size: 2.4rem; font-weight: 800;
  color: var(--green);
  line-height: 1;
  letter-spacing: -.02em;
}
.stat-label { font-size: 13px; color: rgba(255,255,255,0.4); margin-top: 6px; font-weight: 500; }

/* ── SECTION COMMON ── */
.section { padding: 100px 24px; }
.container { max-width: 1140px; margin: 0 auto; }
.section-eyebrow {
  display: inline-flex; align-items: center; gap: 7px;
  font-size: 12px; font-weight: 600; letter-spacing: .1em;
  text-transform: uppercase; color: var(--green);
  margin-bottom: 16px;
}
.section-eyebrow::before {
  content: '';
  display: block; width: 18px; height: 2px;
  background: var(--green); border-radius: 2px;
}
.section-h {
  font-family: 'Inter', system-ui, sans-serif;
  font-size: clamp(2rem, 4.5vw, 3rem);
  font-weight: 800;
  color: var(--ink);
  line-height: 1.1;
  letter-spacing: -.025em;
  text-wrap: balance;
}
.section-h.light { color: #fff; }
.section-sub {
  font-size: 1.05rem; color: var(--muted);
  margin-top: 14px; line-height: 1.7;
  max-width: 520px;
}
.section-sub.light { color: rgba(255,255,255,0.5); }

/* ── BENTO FEATURES ── */
#features { background: var(--ground); }
.bento {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  grid-template-rows: auto;
  gap: 16px;
  margin-top: 60px;
}
@media (max-width: 900px) { .bento { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 540px)  { .bento { grid-template-columns: 1fr; } }

.bento-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  padding: 32px;
  position: relative;
  overflow: hidden;
  transition: box-shadow .3s, transform .3s, border-color .3s;
}
.bento-card:hover {
  box-shadow: 0 16px 48px rgba(10,31,24,0.1);
  transform: translateY(-4px);
  border-color: rgba(16,185,129,0.3);
}
.bento-card::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(135deg, rgba(16,185,129,0.04) 0%, transparent 60%);
  opacity: 0;
  transition: opacity .3s;
  pointer-events: none;
}
.bento-card:hover::after { opacity: 1; }

/* grid spans */
.b-wide { grid-column: span 3; }
.b-mid  { grid-column: span 2; }
.b-sm   { grid-column: span 2; }
.b-full { grid-column: span 6; }
@media (max-width: 900px) {
  .b-wide, .b-mid, .b-sm, .b-full { grid-column: span 2; }
}
@media (max-width: 540px) {
  .b-wide, .b-mid, .b-sm, .b-full { grid-column: 1; }
}

.bento-icon {
  width: 48px; height: 48px;
  background: linear-gradient(135deg, rgba(16,185,129,0.15) 0%, rgba(52,211,153,0.08) 100%);
  border: 1px solid rgba(16,185,129,0.2);
  border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  color: var(--green);
  margin-bottom: 22px;
  transition: background .3s, box-shadow .3s;
}
.bento-card:hover .bento-icon {
  background: linear-gradient(135deg, rgba(16,185,129,0.25) 0%, rgba(52,211,153,0.15) 100%);
  box-shadow: 0 4px 16px rgba(16,185,129,0.25);
}
.bento-h { font-family: 'Inter', system-ui, sans-serif; font-size: 1.15rem; font-weight: 800; color: var(--ink); margin-bottom: 10px; }
.bento-b { font-size: .88rem; color: var(--muted); line-height: 1.7; }

/* Mini POS preview inside bento */
.mini-pos {
  margin-top: 20px;
  background: var(--g800);
  border-radius: 12px;
  padding: 14px;
  display: flex; flex-direction: column; gap: 7px;
}
.mini-pos-row {
  display: flex; justify-content: space-between; align-items: center;
  font-size: 11px;
  color: rgba(255,255,255,0.6);
  background: rgba(255,255,255,0.04);
  border-radius: 7px;
  padding: 7px 10px;
}
.mini-pos-row.total {
  background: rgba(16,185,129,0.2);
  border: 1px solid rgba(16,185,129,0.3);
  color: var(--green-l);
  font-weight: 600;
}
.mini-pos-price { font-family: 'JetBrains Mono', monospace; font-size: 11px; }

/* Mini chart inside bento */
.mini-chart {
  margin-top: 20px;
  display: flex; align-items: flex-end; gap: 5px;
  height: 60px;
}
.mini-bar {
  flex: 1;
  background: linear-gradient(180deg, var(--green) 0%, rgba(16,185,129,0.3) 100%);
  border-radius: 4px 4px 0 0;
  min-height: 8px;
  transition: opacity .2s;
}
.mini-bar:hover { opacity: 0.75; }

/* Pill/capsule visual */
.mini-pill {
  margin-top: 20px;
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.pill-tag {
  display: inline-flex; align-items: center; gap: 5px;
  background: rgba(16,185,129,0.1);
  border: 1px solid rgba(16,185,129,0.2);
  border-radius: 100px;
  padding: 5px 12px;
  font-size: 11px; font-weight: 600; color: var(--green);
}

/* ── MOPH SECTION ── */
#moph {
  background: var(--g900);
  position: relative; overflow: hidden;
}
.moph-mesh {
  position: absolute; inset: 0; pointer-events: none;
  background: radial-gradient(ellipse 60% 60% at 30% 50%, rgba(16,185,129,0.1) 0%, transparent 65%);
}
.moph-inner {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 80px; align-items: center;
}
@media (max-width: 760px) { .moph-inner { grid-template-columns: 1fr; gap: 48px; } }
.moph-checks { display: flex; flex-direction: column; gap: 16px; margin-top: 28px; }
.moph-check-item {
  display: flex; align-items: flex-start; gap: 12px;
}
.moph-check-icon {
  width: 24px; height: 24px; border-radius: 50%;
  background: rgba(16,185,129,0.15);
  border: 1px solid rgba(16,185,129,0.3);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; margin-top: 2px;
  color: var(--green);
}
.moph-check-title { font-size: .95rem; font-weight: 600; color: rgba(255,255,255,0.85); }
.moph-check-sub { font-size: .82rem; color: rgba(255,255,255,0.4); margin-top: 2px; }

/* MoPH data card */
.moph-card {
  background: var(--g800);
  border: 1px solid var(--border-d);
  border-radius: var(--r-xl);
  overflow: hidden;
  box-shadow: 0 24px 64px rgba(0,0,0,0.5);
}
.moph-card-header {
  padding: 16px 20px;
  background: var(--g700);
  border-bottom: 1px solid var(--border-d);
  display: flex; align-items: center; justify-content: space-between;
}
.moph-card-title { font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.7); }
.moph-badge-live {
  display: flex; align-items: center; gap: 5px;
  font-size: 10px; font-weight: 600; color: var(--green);
  background: rgba(16,185,129,0.12);
  border: 1px solid rgba(16,185,129,0.25);
  border-radius: 100px; padding: 3px 9px;
}
.moph-badge-live::before {
  content: ''; width: 5px; height: 5px; border-radius: 50%;
  background: var(--green); box-shadow: 0 0 6px var(--green);
}
.moph-search {
  margin: 14px 20px;
  background: rgba(255,255,255,0.05);
  border: 1px solid var(--border-d);
  border-radius: 8px;
  padding: 9px 14px;
  font-size: 12px; color: rgba(255,255,255,0.35);
  display: flex; align-items: center; gap: 8px;
}
.moph-rows { padding: 4px 16px 16px; display: flex; flex-direction: column; gap: 6px; }
.moph-row {
  display: grid; grid-template-columns: 1fr auto auto;
  gap: 16px; align-items: center;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.05);
  border-radius: 9px;
  padding: 10px 14px;
}
.moph-row-name { font-size: 12px; color: rgba(255,255,255,0.75); font-weight: 500; }
.moph-row-generic { font-size: 10px; color: rgba(255,255,255,0.3); margin-top: 1px; }
.moph-row-price { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--green); white-space: nowrap; }
.moph-row-badge {
  font-size: 10px; font-weight: 700;
  padding: 3px 8px; border-radius: 20px;
  white-space: nowrap;
}
.rb-ok  { background: rgba(16,185,129,0.12); color: var(--green); }
.rb-sub { background: rgba(251,191,36,0.12); color: #FBBF24; }

/* ── HOW IT WORKS ── */
#how { background: var(--surface); }
.how-grid {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 24px; margin-top: 60px;
}
@media (max-width: 640px) { .how-grid { grid-template-columns: 1fr; } }
.how-card {
  position: relative;
  padding: 36px 28px;
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  overflow: hidden;
  transition: border-color .3s, box-shadow .3s;
}
.how-card:hover { border-color: rgba(16,185,129,0.35); box-shadow: 0 8px 32px rgba(10,31,24,0.08); }
.how-num {
  font-family: 'Inter', system-ui, sans-serif;
  font-size: 5rem; font-weight: 800;
  color: rgba(16,185,129,0.08);
  line-height: 1;
  position: absolute; top: 16px;
  inset-inline-end: 24px;
  pointer-events: none;
  letter-spacing: -.04em;
}
.how-icon {
  width: 50px; height: 50px;
  background: linear-gradient(135deg, rgba(16,185,129,0.15), rgba(52,211,153,0.08));
  border: 1px solid rgba(16,185,129,0.2);
  border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  color: var(--green); margin-bottom: 22px;
}
.how-h { font-family: 'Inter', system-ui, sans-serif; font-size: 1.15rem; font-weight: 800; color: var(--ink); margin-bottom: 10px; }
.how-b { font-size: .88rem; color: var(--muted); line-height: 1.7; }

/* ── PRICING ── */
#pricing { background: var(--ground); }
.price-grid {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 20px; margin-top: 60px; align-items: start;
}
@media (max-width: 700px) { .price-grid { grid-template-columns: 1fr; max-width: 400px; } }
.price-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  padding: 36px 32px;
  position: relative;
  transition: box-shadow .3s, transform .3s;
}
.price-card:hover { box-shadow: 0 12px 40px rgba(10,31,24,0.1); transform: translateY(-4px); }
.price-card.hot {
  background: var(--g900);
  border-color: rgba(16,185,129,0.25);
  box-shadow: 0 0 0 1px rgba(16,185,129,0.15), 0 24px 64px rgba(0,0,0,0.5);
  transform: translateY(-12px);
}
@media (max-width: 700px) { .price-card.hot { transform: none; } }
.price-card.hot:hover { transform: translateY(-16px); }
.price-pop {
  position: absolute; top: -13px; left: 50%; transform: translateX(-50%);
  background: var(--green); color: var(--g900);
  font-size: 11px; font-weight: 700; letter-spacing: .07em;
  text-transform: uppercase; padding: 4px 14px; border-radius: 100px;
  white-space: nowrap;
}
.price-tier { font-size: 12px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); margin-bottom: 20px; }
.price-card.hot .price-tier { color: rgba(255,255,255,0.4); }
.price-amt {
  font-family: 'Inter', system-ui, sans-serif;
  font-size: 3rem; font-weight: 800; line-height: 1;
  color: var(--ink); letter-spacing: -.03em;
}
.price-card.hot .price-amt { color: #fff; }
.price-period { font-size: 14px; color: var(--muted); margin-top: 4px; margin-bottom: 28px; }
.price-card.hot .price-period { color: rgba(255,255,255,0.4); }
.price-divider { height: 1px; background: var(--border); margin-bottom: 24px; }
.price-card.hot .price-divider { background: var(--border-d); }
.price-feats { display: flex; flex-direction: column; gap: 12px; margin-bottom: 32px; }
.price-feat {
  display: flex; align-items: flex-start; gap: 10px;
  font-size: 14px; color: var(--ink2);
}
.price-card.hot .price-feat { color: rgba(255,255,255,0.7); }
.price-feat-check {
  width: 18px; height: 18px;
  background: rgba(16,185,129,0.15);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; margin-top: 2px;
  color: var(--green);
}
.btn-plan {
  display: block; width: 100%; text-align: center;
  padding: 13px; border-radius: 11px;
  font-size: 14px; font-weight: 600;
  transition: all .2s;
}
.btn-plan-outline {
  border: 1.5px solid var(--border);
  color: var(--ink);
}
.btn-plan-outline:hover { border-color: var(--green); color: var(--green); }
.btn-plan-green {
  background: var(--green);
  color: var(--g900);
  box-shadow: 0 4px 20px rgba(16,185,129,0.4);
}
.btn-plan-green:hover { background: var(--green-l); box-shadow: 0 8px 30px rgba(16,185,129,0.5); }
.btn-plan-ghost {
  border: 1.5px solid var(--border-d);
  color: rgba(255,255,255,0.5);
}
.btn-plan-ghost:hover { border-color: rgba(16,185,129,0.4); color: var(--green-l); }

/* ── OFFER CTA ── */
#cta {
  background: var(--g800);
  border-top: 1px solid var(--border-d);
  padding: 100px 24px;
  position: relative; overflow: hidden; text-align: center;
}
.cta-mesh {
  position: absolute; inset: 0; pointer-events: none;
  background: radial-gradient(ellipse 70% 70% at 50% 100%, rgba(16,185,129,0.12) 0%, transparent 65%);
}
.cta-h {
  font-family: 'Inter', system-ui, sans-serif;
  font-size: clamp(2rem, 5vw, 3.2rem);
  font-weight: 800; color: #fff;
  letter-spacing: -.03em; text-wrap: balance;
  line-height: 1.1;
  position: relative; z-index: 1;
}
.cta-sub {
  font-size: 1rem; color: rgba(255,255,255,0.45);
  margin-top: 16px; line-height: 1.7;
  position: relative; z-index: 1;
}
.cta-actions {
  display: flex; gap: 14px; justify-content: center; flex-wrap: wrap;
  margin-top: 40px; position: relative; z-index: 1;
}
.btn-wa {
  display: inline-flex; align-items: center; gap: 9px;
  background: #25D366; color: #fff;
  font-size: 15px; font-weight: 600;
  padding: 14px 26px; border-radius: 11px;
  box-shadow: 0 4px 24px rgba(37,211,102,0.35);
  transition: background .2s, transform .15s, box-shadow .2s;
}
.btn-wa:hover { background: #1DB954; transform: translateY(-2px); box-shadow: 0 8px 36px rgba(37,211,102,0.5); }
.offer-note {
  margin-top: 24px;
  font-size: 13px; color: rgba(255,255,255,0.3);
  position: relative; z-index: 1;
}
.offer-note strong { color: rgba(255,255,255,0.55); }

/* ── FOOTER ── */
footer {
  background: var(--g900);
  border-top: 1px solid var(--border-d);
  padding: 36px 24px;
}
.footer-inner {
  max-width: 1140px; margin: 0 auto;
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px;
}
.footer-left { display: flex; align-items: center; gap: 12px; }
.footer-logo-icon {
  width: 28px; height: 28px; background: var(--green); border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
}
.footer-copy { font-size: 13px; color: rgba(255,255,255,0.3); }
.footer-links { display: flex; gap: 24px; }
.footer-links a { font-size: 13px; color: rgba(255,255,255,0.35); transition: color .2s; }
.footer-links a:hover { color: var(--green-l); }

/* ── SCROLL REVEAL ── */
.reveal {
  opacity: 0;
  transform: translateY(24px);
  transition: opacity .65s cubic-bezier(.22,1,.36,1), transform .65s cubic-bezier(.22,1,.36,1);
}
.reveal.in { opacity: 1; transform: none; }
.reveal-d1 { transition-delay: .07s; }
.reveal-d2 { transition-delay: .14s; }
.reveal-d3 { transition-delay: .21s; }
.reveal-d4 { transition-delay: .28s; }

@media (prefers-reduced-motion: reduce) {
  .reveal { opacity: 1; transform: none; }
  .hero-mesh { animation: none; }
  .hero-badge-dot { animation: none; }
}
</style>
</head>
<body>

<!-- NAV -->
<nav id="nav">
  <div class="nav-inner">
    <a href="/landing.php" class="nav-logo">
      <div class="nav-logo-icon">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
          <g transform="translate(10,10) rotate(45)">
            <rect x="-6" y="-2.5" width="6" height="5" rx="2.5" fill="white"/>
            <rect x="0" y="-2.5" width="6" height="5" rx="2.5" fill="none" stroke="white" stroke-width="1"/>
            <line x1="0" y1="-3" x2="0" y2="3" stroke="rgba(6,20,16,0.6)" stroke-width=".8"/>
          </g>
        </svg>
      </div>
      <span class="nav-wordmark">Pharma<span>Pro</span></span>
    </a>
    <div class="nav-right">
      <a href="#features" class="nav-link"><?= $ar ? 'المميزات' : 'Features' ?></a>
      <a href="#pricing" class="nav-link"><?= $ar ? 'الأسعار' : 'Pricing' ?></a>
      <a href="?lang=<?= $ar ? 'en' : 'ar' ?>" class="nav-lang"><?= $ar ? 'English' : 'عربي' ?></a>
      <a href="https://wa.me/96170000000" class="nav-cta">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
        <?= $ar ? 'احجز عرضاً' : 'Book a Demo' ?>
      </a>
    </div>
  </div>
</nav>

<!-- HERO -->
<section id="hero">
  <div class="hero-mesh"></div>
  <div class="hero-grid"></div>

  <div class="hero-badge">
    <span class="hero-badge-dot"></span>
    <?= $ar ? 'مصمم للصيدليات اللبنانية' : 'Built for Lebanese pharmacies' ?>
  </div>

  <h1 class="hero-h1">
    <?= $ar
      ? 'إدارة الصيدلية<br><span class="gradient-text">بشكل أذكى</span>'
      : 'Pharmacy software<br><span class="gradient-text">that just works</span>'
    ?>
  </h1>

  <p class="hero-sub">
    <?= $ar
      ? 'نقطة بيع، مخزون، توافق مع وزارة الصحة. كل ما تحتاجه في مكان واحد. بالعربي والإنجليزي.'
      : 'POS, inventory, MoPH compliance, and financial reports. Everything your pharmacy needs, in Arabic and English.'
    ?>
  </p>

  <div class="hero-actions">
    <a href="https://wa.me/96170000000" class="btn-primary">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
      <?= $ar ? 'واتساب: احجز عرضاً مجانياً' : 'Book a free demo on WhatsApp' ?>
    </a>
    <a href="https://pharmapro.online/login.php" class="btn-secondary" target="_blank">
      <?= $ar ? 'دخول مباشر →' : 'Live demo →' ?>
    </a>
  </div>

  <!-- App preview -->
  <div class="hero-preview">
    <div class="hero-preview-glow"></div>
    <div class="hero-preview-card">
      <div class="preview-topbar">
        <div class="preview-dots">
          <div class="preview-dot pd-r"></div>
          <div class="preview-dot pd-y"></div>
          <div class="preview-dot pd-g"></div>
        </div>
        <div class="preview-url">pharmapro.online/modules/inventory</div>
      </div>
      <div class="preview-body">
        <div class="preview-sidebar">
          <div class="preview-sb-label">Navigation</div>
          <div class="preview-sb-item active"><span class="preview-sb-dot"></span>Inventory</div>
          <div class="preview-sb-item"><span class="preview-sb-dot"></span>Point of Sale</div>
          <div class="preview-sb-item"><span class="preview-sb-dot"></span>Prescriptions</div>
          <div class="preview-sb-item"><span class="preview-sb-dot"></span>Finance</div>
          <div class="preview-sb-item"><span class="preview-sb-dot"></span>MoPH Prices</div>
          <div class="preview-sb-item"><span class="preview-sb-dot"></span>Reports</div>
        </div>
        <div class="preview-main">
          <div class="preview-kpi-row">
            <div class="preview-kpi">
              <div class="preview-kpi-label">Total Medicines</div>
              <div class="preview-kpi-val green">34</div>
            </div>
            <div class="preview-kpi">
              <div class="preview-kpi-label">Low Stock</div>
              <div class="preview-kpi-val" style="color:#FBBF24">3</div>
            </div>
            <div class="preview-kpi">
              <div class="preview-kpi-label">Expiring Soon</div>
              <div class="preview-kpi-val" style="color:#F87171">1</div>
            </div>
          </div>
          <div class="preview-table-head">
            <div>Medicine</div><div>Category</div><div>Stock</div><div>Price</div>
          </div>
          <div class="preview-row">
            <div><div class="preview-med">Augmentin 1g</div><div class="preview-generic">Amoxicillin/Clavulanate</div></div>
            <div class="preview-cat">Antibiotic</div>
            <div class="preview-qty">118</div>
            <div class="preview-price">$5.50</div>
          </div>
          <div class="preview-row">
            <div><div class="preview-med">Glucophage 500mg</div><div class="preview-generic">Metformin</div></div>
            <div class="preview-cat" style="background:rgba(251,191,36,0.12);color:#FBBF24">Subsidized</div>
            <div class="preview-qty">396</div>
            <div class="preview-price">$0.60</div>
          </div>
          <div class="preview-row">
            <div><div class="preview-med">Seretide 250</div><div class="preview-generic">Fluticasone/Salmeterol</div></div>
            <div class="preview-cat">Respiratory</div>
            <div class="preview-qty">38</div>
            <div class="preview-price">$15.00</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- STATS -->
<section id="stats">
  <div class="stats-grid">
    <div class="stat-item reveal">
      <div class="stat-val" data-count="34">0</div>
      <div class="stat-label"><?= $ar ? 'دواء مُدار' : 'Medicines tracked' ?></div>
    </div>
    <div class="stat-item reveal reveal-d1">
      <div class="stat-val" data-count="142">0</div>
      <div class="stat-label"><?= $ar ? 'عملية بيع مسجلة' : 'Sales recorded' ?></div>
    </div>
    <div class="stat-item reveal reveal-d2">
      <div class="stat-val" data-prefix="10k+">10k+</div>
      <div class="stat-label"><?= $ar ? 'دواء في قائمة وزارة الصحة' : 'MoPH drug prices' ?></div>
    </div>
    <div class="stat-item reveal reveal-d3">
      <div class="stat-val" data-suffix="hr">1</div>
      <div class="stat-label"><?= $ar ? 'لتبدأ العمل من الآن' : 'Hour to go live' ?></div>
    </div>
  </div>
</section>

<!-- FEATURES -->
<section id="features" class="section">
  <div class="container">
    <p class="section-eyebrow"><?= $ar ? 'المميزات' : 'Features' ?></p>
    <h2 class="section-h"><?= $ar ? 'كل ما تحتاجه' : 'Everything you need' ?></h2>
    <p class="section-sub"><?= $ar ? 'مبني خصيصاً لواقع الصيدلية اللبنانية.' : 'Built around how Lebanese pharmacies actually operate.' ?></p>

    <div class="bento">
      <!-- POS wide -->
      <div class="bento-card b-wide reveal">
        <div class="bento-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        </div>
        <div class="bento-h"><?= $ar ? 'نقطة البيع' : 'Point of Sale' ?></div>
        <div class="bento-b"><?= $ar ? 'بيع سريع بالباركود، دفع نقدي أو بطاقة أو ل.ل. فواتير تلقائية وإيصالات قابلة للطباعة.' : 'Fast barcode scanning, cash / card / LBP payments, automatic invoice numbers, printable receipts.' ?></div>
        <div class="mini-pos">
          <div class="mini-pos-row">
            <span>Augmentin 1g × 2</span>
            <span class="mini-pos-price">$11.00</span>
          </div>
          <div class="mini-pos-row">
            <span>Vitamin D3 × 1</span>
            <span class="mini-pos-price">$1.20</span>
          </div>
          <div class="mini-pos-row total">
            <span><?= $ar ? 'المجموع' : 'Total' ?></span>
            <span class="mini-pos-price">$12.20</span>
          </div>
        </div>
      </div>

      <!-- Inventory -->
      <div class="bento-card b-mid reveal reveal-d1">
        <div class="bento-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        </div>
        <div class="bento-h"><?= $ar ? 'إدارة المخزون' : 'Inventory Control' ?></div>
        <div class="bento-b"><?= $ar ? 'تنبيهات انتهاء الصلاحية، حد المخزون الأدنى، تتبع الدفعات، تاريخ الحركات الكامل.' : 'Expiry alerts, low-stock warnings, batch tracking, full movement history.' ?></div>
      </div>

      <!-- Finance chart -->
      <div class="bento-card b-sm reveal reveal-d2">
        <div class="bento-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        </div>
        <div class="bento-h"><?= $ar ? 'التقارير المالية' : 'Financial Reports' ?></div>
        <div class="bento-b"><?= $ar ? 'أرباح وخسائر، مطابقة نهاية اليوم، تقارير ضريبية.' : 'P&L, end-of-day reconciliation, tax reports.' ?></div>
        <div class="mini-chart">
          <?php $bars = [35,52,41,68,55,72,48,80,63,75,58,90]; foreach ($bars as $h): ?>
          <div class="mini-bar" style="height:<?= $h ?>%"></div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- MoPH compliance -->
      <div class="bento-card b-mid reveal reveal-d1">
        <div class="bento-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
        </div>
        <div class="bento-h"><?= $ar ? 'توافق وزارة الصحة' : 'MoPH Compliance' ?></div>
        <div class="bento-b"><?= $ar ? 'قائمة أسعار وزارة الصحة مدمجة. سجل المواد الخاضعة للرقابة. الأدوية المدعومة.' : 'Built-in MoPH price list. Controlled substance register. Subsidized medicine tracking.' ?></div>
        <div class="mini-pill">
          <span class="pill-tag">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            MoPH Verified
          </span>
          <span class="pill-tag">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <?= $ar ? 'مدعوم' : 'Subsidized' ?>
          </span>
          <span class="pill-tag">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <?= $ar ? 'خاضع للرقابة' : 'Controlled' ?>
          </span>
        </div>
      </div>

      <!-- Prescriptions -->
      <div class="bento-card b-sm reveal reveal-d2">
        <div class="bento-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </div>
        <div class="bento-h"><?= $ar ? 'الوصفات والتأمين' : 'Prescriptions & Insurance' ?></div>
        <div class="bento-b"><?= $ar ? 'ربط الوصفات بالمبيعات، إنشاء مطالبات التأمين، ملفات المرضى.' : 'Link prescriptions to sales, generate insurance claims, patient records.' ?></div>
      </div>

      <!-- Bilingual full -->
      <div class="bento-card b-full reveal" style="display:flex;gap:32px;align-items:center;flex-wrap:wrap;">
        <div class="bento-icon" style="flex-shrink:0">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        </div>
        <div style="flex:1">
          <div class="bento-h"><?= $ar ? 'عربي وإنجليزي بالكامل' : 'Fully bilingual: Arabic & English' ?></div>
          <div class="bento-b" style="max-width:500px"><?= $ar ? 'واجهة كاملة بالعربية مع دعم RTL الكامل. كل مستخدم يختار لغته. انتقل في أي وقت.' : 'Full Arabic interface with complete RTL layout. Every user picks their language. Switch anytime with one click.' ?></div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-shrink:0">
          <div style="background:var(--g800);border:1px solid var(--border-d);border-radius:10px;padding:12px 18px;font-size:18px;color:rgba(255,255,255,0.7);font-weight:500;letter-spacing:.02em">English</div>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          <div style="background:var(--g800);border:1px solid rgba(16,185,129,0.3);border-radius:10px;padding:12px 18px;font-size:18px;color:var(--green-l);font-weight:500;letter-spacing:.02em" dir="rtl">عربي</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- MOPH SECTION -->
<section id="moph" class="section">
  <div class="moph-mesh"></div>
  <div class="container">
    <div class="moph-inner">
      <div class="reveal">
        <p class="section-eyebrow" style="color:var(--green)"><?= $ar ? 'ميزة حصرية' : 'Exclusive advantage' ?></p>
        <h2 class="section-h light"><?= $ar ? 'قائمة وزارة الصحة<br>مدمجة في النظام' : 'MoPH price list<br>built right in' ?></h2>
        <p class="section-sub light" style="margin-top:14px"><?= $ar ? 'قاعدة بيانات أسعار الأدوية الرسمية الصادرة عن وزارة الصحة اللبنانية مدمجة في النظام. تحقق من الأسعار وتتبع الدعم في ثوانٍ.' : 'The official Lebanese MoPH drug price database is embedded and always current. Verify prices, track subsidies, and avoid pricing violations in seconds.' ?></p>
        <div class="moph-checks">
          <?php
          $checks = $ar
            ? [
                ['أسعار 10,000+ دواء مُضمَّنة','لا حاجة للبحث اليدوي عن الأسعار'],
                ['مقارنة سعر البيع مقابل سعر الوزارة','تحذير فوري عند الانحراف'],
                ['تتبع الأدوية المدعومة','مُرتبطة بكل عملية بيع'],
                ['سجل المواد الخاضعة للرقابة','تقارير قانونية جاهزة للطباعة'],
              ]
            : [
                ['10,000+ drug prices embedded','No manual price lookups ever again'],
                ['Selling price vs MoPH price','Instant alert on any deviation'],
                ['Subsidized medicine tracking','Linked to every sale automatically'],
                ['Controlled substance register','Print-ready legal reports'],
              ];
          foreach ($checks as $c):
          ?>
          <div class="moph-check-item">
            <div class="moph-check-icon">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div>
              <div class="moph-check-title"><?= $c[0] ?></div>
              <div class="moph-check-sub"><?= $c[1] ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="moph-card reveal reveal-d1">
        <div class="moph-card-header">
          <div class="moph-card-title"><?= $ar ? 'قائمة أسعار وزارة الصحة' : 'MoPH Price List' ?></div>
          <div class="moph-badge-live"><?= $ar ? 'محدّث' : 'Live' ?></div>
        </div>
        <div class="moph-search">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <?= $ar ? 'ابحث عن دواء...' : 'Search medicine...' ?>
        </div>
        <div class="moph-rows">
          <?php
          $rows = [
            ['Augmentin 1g','Amoxicillin/Clavulanate','$5.50','ok'],
            ['Glucophage 500mg','Metformin','$0.60','sub'],
            ['Seretide 250','Fluticasone/Salmeterol','$15.00','sub'],
            ['Panadol 500mg','Paracetamol','$0.90','ok'],
            ['Januvia 100mg','Sitagliptin','$9.00','ok'],
          ];
          foreach ($rows as $r):
          ?>
          <div class="moph-row">
            <div>
              <div class="moph-row-name"><?= $r[0] ?></div>
              <div class="moph-row-generic"><?= $r[1] ?></div>
            </div>
            <div class="moph-row-price"><?= $r[2] ?></div>
            <div class="moph-row-badge rb-<?= $r[3] ?>">
              <?= $r[3] === 'ok' ? '✓ OK' : ($ar ? 'مدعوم' : 'Subsidized') ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- HOW IT WORKS -->
<section id="how" class="section">
  <div class="container" style="text-align:center">
    <p class="section-eyebrow" style="justify-content:center"><?= $ar ? 'البداية' : 'Getting started' ?></p>
    <h2 class="section-h"><?= $ar ? 'ابدأ خلال يوم واحد' : 'Live in one day' ?></h2>
    <div class="how-grid">
      <?php
      $steps = $ar
        ? [
            ['عرض مجاني','نريك النظام مباشرة ونجاوب على كل أسئلتك.'],
            ['إعداد مخصص','ندخل أدويتك وأسعارك وموظفيك معاً.'],
            ['تدريب ودعم','ساعة تدريب وأنت تشتغل. دعم دائم عبر واتساب.'],
          ]
        : [
            ['Free demo','We show you the system live and answer every question.'],
            ['Custom setup','We import your medicines, prices, and team together.'],
            ['Training & support','One hour of training and you\'re live. WhatsApp support always on.'],
          ];
      $icons = [
        '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 10l-4 4-4-4"/><path d="M19 15V9a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 1 9v6a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 19 15z"/></svg>',
        '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
        '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.07 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3 1.18h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.18a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 15z"/></svg>',
      ];
      foreach ($steps as $i => $s):
      ?>
      <div class="how-card reveal reveal-d<?= $i ?>">
        <div class="how-num"><?= $i+1 ?></div>
        <div class="how-icon"><?= $icons[$i] ?></div>
        <div class="how-h"><?= $s[0] ?></div>
        <div class="how-b"><?= $s[1] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- PRICING -->
<section id="pricing" class="section">
  <div class="container" style="text-align:center">
    <p class="section-eyebrow" style="justify-content:center"><?= $ar ? 'الأسعار' : 'Pricing' ?></p>
    <h2 class="section-h"><?= $ar ? 'أسعار شفافة' : 'Simple, honest pricing' ?></h2>
    <p class="section-sub" style="margin:14px auto 0"><?= $ar ? 'بدون رسوم مخفية. بدون عقود طويلة.' : 'No hidden fees. No long contracts.' ?></p>
    <div class="price-grid" style="margin-inline:auto">
      <!-- Starter -->
      <div class="price-card reveal">
        <div class="price-tier"><?= $ar ? 'أساسي' : 'Starter' ?></div>
        <div class="price-amt">$29</div>
        <div class="price-period"><?= $ar ? '/شهر' : '/month' ?></div>
        <div class="price-divider"></div>
        <ul class="price-feats">
          <?php foreach ($ar
            ? ['نقطة بيع كاملة','إدارة المخزون','تقارير يومية','مستخدم واحد']
            : ['Full POS','Inventory management','Daily reports','1 user'] as $f): ?>
          <li class="price-feat"><div class="price-feat-check"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div><?= $f ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="https://wa.me/96170000000" class="btn-plan btn-plan-outline"><?= $ar ? 'ابدأ مجاناً' : 'Start free' ?></a>
      </div>

      <!-- Pro (featured) -->
      <div class="price-card hot reveal reveal-d1">
        <div class="price-pop"><?= $ar ? 'الأكثر شيوعاً' : 'Most popular' ?></div>
        <div class="price-tier"><?= $ar ? 'احترافي' : 'Professional' ?></div>
        <div class="price-amt">$59</div>
        <div class="price-period"><?= $ar ? '/شهر' : '/month' ?></div>
        <div class="price-divider"></div>
        <ul class="price-feats">
          <?php foreach ($ar
            ? ['كل ما في الأساسي','توافق وزارة الصحة','الوصفات والتأمين','5 مستخدمين','تقارير متقدمة']
            : ['Everything in Starter','MoPH compliance','Prescriptions & insurance','5 users','Advanced reports'] as $f): ?>
          <li class="price-feat"><div class="price-feat-check"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div><?= $f ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="https://wa.me/96170000000" class="btn-plan btn-plan-green"><?= $ar ? 'احجز عرضاً' : 'Book a demo' ?></a>
      </div>

      <!-- Chain -->
      <div class="price-card reveal reveal-d2">
        <div class="price-tier"><?= $ar ? 'سلسلة صيدليات' : 'Chain' ?></div>
        <div class="price-amt" style="font-size:2.2rem"><?= $ar ? 'مخصص' : 'Custom' ?></div>
        <div class="price-period">&nbsp;</div>
        <div class="price-divider"></div>
        <ul class="price-feats">
          <?php foreach ($ar
            ? ['فروع متعددة','مستخدمون غير محدودون','تكامل مخصص','SLA ودعم مخصص']
            : ['Multiple branches','Unlimited users','Custom integrations','Dedicated SLA'] as $f): ?>
          <li class="price-feat"><div class="price-feat-check"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div><?= $f ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="https://wa.me/96170000000" class="btn-plan btn-plan-outline"><?= $ar ? 'تواصل معنا' : 'Contact us' ?></a>
      </div>
    </div>
  </div>
</section>

<!-- CTA -->
<section id="cta">
  <div class="cta-mesh"></div>
  <h2 class="cta-h reveal">
    <?= $ar ? 'أول 30 صيدلية<br>3 أشهر مجاناً' : 'First 30 pharmacies<br>3 months free' ?>
  </h2>
  <p class="cta-sub reveal"><?= $ar ? 'نحن في مرحلة الانطلاق. الصيدليات الأولى تحصل على 3 أشهر مجاناً مقابل تقديم ملاحظاتهم.' : "We're launching. The first pharmacies get 3 months free in exchange for honest feedback." ?></p>
  <div class="cta-actions reveal">
    <a href="https://wa.me/96170000000?text=<?= urlencode($ar ? 'مرحبا، أريد الاستفادة من عرض الـ 3 أشهر مجاناً' : "Hi, I'd like the 3 months free offer for PharmaPro") ?>" class="btn-wa">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
      <?= $ar ? 'أنا مهتم، واتساب' : "I'm interested, WhatsApp" ?>
    </a>
    <a href="/login.php" class="btn-secondary"><?= $ar ? 'تجربة مباشرة' : 'Live demo' ?></a>
  </div>
  <p class="offer-note"><?= $ar ? 'المقاعد <strong>المجانية محدودة</strong>. لا يوجد بطاقة ائتمانية مطلوبة.' : 'Free spots are <strong>limited</strong>. No credit card required.' ?></p>
</section>

<!-- FOOTER -->
<footer>
  <div class="footer-inner">
    <div class="footer-left">
      <div class="footer-logo-icon">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="none">
          <g transform="translate(10,10) rotate(45)">
            <rect x="-6" y="-2.5" width="6" height="5" rx="2.5" fill="white"/>
            <rect x="0" y="-2.5" width="6" height="5" rx="2.5" fill="none" stroke="white" stroke-width="1"/>
          </g>
        </svg>
      </div>
      <span class="footer-copy">© 2026 PharmaPro</span>
    </div>
    <div class="footer-links">
      <a href="?lang=<?= $ar ? 'en' : 'ar' ?>"><?= $ar ? 'English' : 'عربي' ?></a>
      <a href="/plan"><?= $ar ? 'خطتنا' : 'Our Plan' ?></a>
      <a href="https://wa.me/96170000000">WhatsApp</a>
      <a href="/login.php"><?= $ar ? 'دخول' : 'Login' ?></a>
    </div>
  </div>
</footer>

<script>
// Nav scroll effect
const nav = document.getElementById('nav');
window.addEventListener('scroll', () => {
  nav.classList.toggle('scrolled', window.scrollY > 40);
}, { passive: true });

// Scroll reveal
const revealObs = new IntersectionObserver(entries => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); revealObs.unobserve(e.target); } });
}, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
document.querySelectorAll('.reveal').forEach(el => revealObs.observe(el));

// CountUp animation
function countUp(el, target, suffix, prefix) {
  const dur = 1800;
  const step = 16;
  const steps = dur / step;
  let cur = 0;
  const inc = target / steps;
  const timer = setInterval(() => {
    cur = Math.min(cur + inc, target);
    el.textContent = (prefix || '') + Math.round(cur).toLocaleString() + (suffix || '');
    if (cur >= target) clearInterval(timer);
  }, step);
}
const countObs = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if (!e.isIntersecting) return;
    const el = e.target;
    const target = parseInt(el.dataset.count);
    if (!isNaN(target)) {
      const suffix = el.dataset.suffix || '';
      countUp(el, target, suffix);
    }
    countObs.unobserve(el);
  });
}, { threshold: 0.5 });
document.querySelectorAll('[data-count]').forEach(el => countObs.observe(el));
</script>
</body>
</html>
