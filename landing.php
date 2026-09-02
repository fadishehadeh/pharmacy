<?php
$lang = $_GET['lang'] ?? (isset($_COOKIE['lang']) ? $_COOKIE['lang'] : 'ar');
$ar = ($lang === 'ar');
$dir = $ar ? 'rtl' : 'ltr';

require_once __DIR__ . '/config/app.php';
$_gaId    = getSetting('ga_measurement_id', '');
$_gscCode = getSetting('gsc_verification_code', '');
?>
<!DOCTYPE html>
<html lang="<?= $ar ? 'ar' : 'en' ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
$canonicalBase = 'https://pharmapro.online/landing.php';
$canonical = $ar ? $canonicalBase.'?lang=ar' : $canonicalBase.'?lang=en';
$titleAr = 'برنامج إدارة الصيدلية في لبنان | PharmaPro';
$titleEn = 'Pharmacy Management Software for Lebanon | PharmaPro';
$descAr  = 'PharmaPro نظام إدارة صيدلية متكامل مصمم للصيدليات اللبنانية.';
$descEn  = 'PharmaPro is a complete pharmacy management system built for Lebanese pharmacies.';
$pageTitle = $ar ? $titleAr : $titleEn;
$pageDesc  = $ar ? $descAr  : $descEn;
?>
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">
<link rel="alternate" hreflang="ar" href="<?= htmlspecialchars($canonicalBase) ?>?lang=ar">
<link rel="alternate" hreflang="en" href="<?= htmlspecialchars($canonicalBase) ?>?lang=en">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">
<meta property="og:image" content="https://pharmapro.online/assets/og-image.png">
<meta name="geo.region" content="LB">
<?php if ($_gscCode): ?><meta name="google-site-verification" content="<?= htmlspecialchars($_gscCode, ENT_QUOTES) ?>"><?php endif; ?>
<?php if ($_gaId): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($_gaId, ENT_QUOTES) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= htmlspecialchars($_gaId, ENT_QUOTES) ?>');</script>
<?php endif; ?>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"SoftwareApplication","name":"PharmaPro","applicationCategory":"BusinessApplication","operatingSystem":"Web","offers":{"@type":"Offer","price":"399","priceCurrency":"USD"}}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&family=Tajawal:wght@400;500;700;900&display=swap">
<style>
:root {
  --blue:  #2563EB;
  --ink:   #0F172A;
  --muted: #64748B;
  --border:#E2E8F0;
  --wa:    #25D366;
  --wa-d:  #1DAB52;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Inter', system-ui, sans-serif;
  background: #fff;
  color: var(--ink);
  -webkit-font-smoothing: antialiased;
  min-height: 100dvh;
  display: flex; flex-direction: column;
}
[dir="rtl"], [dir="rtl"] * { font-family: 'Tajawal', system-ui, sans-serif; }
a { text-decoration: none; color: inherit; }
svg { display: block; flex-shrink: 0; }

/* NAV */
nav {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 24px;
  border-bottom: 1px solid var(--border);
}
.logo {
  display: flex; align-items: center; gap: 8px;
  font-size: 17px; font-weight: 800; color: var(--ink);
}
.logo-box {
  width: 30px; height: 30px; background: var(--blue);
  border-radius: 7px; display: flex; align-items: center; justify-content: center;
}
.logo-name { direction: ltr; unicode-bidi: isolate; }
.logo-name b { color: var(--blue); font-weight: 800; }
.nav-r { display: flex; align-items: center; gap: 10px; }
.lang-btn {
  font-size: 13px; font-weight: 600; color: var(--muted);
  border: 1px solid var(--border); border-radius: 7px;
  padding: 5px 12px;
}
.wa-btn {
  display: flex; align-items: center; gap: 6px;
  background: var(--wa); color: #fff;
  font-size: 13px; font-weight: 700;
  padding: 7px 14px; border-radius: 8px;
}

/* MAIN */
main {
  flex: 1;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center;
  padding: 48px 24px 56px;
  max-width: 600px; margin: 0 auto; width: 100%;
}

.pain {
  font-size: clamp(1rem, 2.8vw, 1.15rem);
  font-weight: 600; color: var(--muted);
  margin-bottom: 20px;
}

.headline {
  font-size: clamp(2.4rem, 7vw, 3.8rem);
  font-weight: 900; color: var(--ink);
  line-height: 1.08; letter-spacing: -.04em;
  text-wrap: balance;
  margin-bottom: 16px;
}
.headline span { color: var(--blue); }

.tagline {
  font-size: clamp(.95rem, 2.4vw, 1.05rem);
  color: var(--muted); line-height: 1.65;
  margin-bottom: 36px;
  max-width: 420px;
}

/* PRICE */
.price-block {
  margin-bottom: 36px;
}
.price-amount {
  font-size: clamp(4rem, 14vw, 7rem);
  font-weight: 900; color: var(--ink);
  letter-spacing: -.05em; line-height: 1;
  direction: ltr; unicode-bidi: isolate;
}
.price-amount sup { font-size: 40%; vertical-align: super; font-weight: 800; }
.price-meta {
  font-size: 14px; color: var(--muted); margin-top: 6px;
}
.price-meta strong { color: var(--ink); font-weight: 700; }

/* INCLUDES */
.includes {
  display: flex; flex-wrap: wrap; justify-content: center; gap: 8px;
  margin-bottom: 36px;
}
.tag {
  font-size: 13px; font-weight: 600;
  background: #F1F5F9; color: #475569;
  padding: 5px 12px; border-radius: 100px;
}

/* CTA */
.cta-btn {
  display: inline-flex; align-items: center; gap: 10px;
  background: var(--wa); color: #fff;
  font-size: 17px; font-weight: 800;
  padding: 17px 36px; border-radius: 14px;
  box-shadow: 0 6px 28px rgba(37,211,102,.35);
  transition: background .2s, transform .15s;
  width: 100%; max-width: 360px; justify-content: center;
}
.cta-btn:hover { background: var(--wa-d); transform: translateY(-2px); }
.cta-note {
  font-size: 13px; color: var(--muted); margin-top: 14px;
}
.cta-note a { color: var(--blue); font-weight: 600; }

/* FEATURES SECTION */
.features-section {
  background: #F8FAFF;
  border-top: 1px solid var(--border);
  padding: 60px 24px;
}
.feat-section-label {
  text-align: center;
  font-size: 11px; font-weight: 700; letter-spacing: .12em;
  text-transform: uppercase; color: var(--blue); margin-bottom: 10px;
}
.feat-section-title {
  text-align: center;
  font-size: clamp(1.5rem, 4vw, 2.1rem);
  font-weight: 900; color: var(--ink);
  letter-spacing: -.03em; text-wrap: balance;
  margin-bottom: 36px;
}
.feat-inner { max-width: 820px; margin: 0 auto; }
.feat-2col {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px;
}
@media (max-width: 540px) { .feat-2col { grid-template-columns: 1fr; } }
.feat-box {
  background: #fff; border: 1px solid var(--border);
  border-radius: 16px; padding: 22px;
  display: flex; gap: 14px; align-items: flex-start;
}
.feat-box-ico {
  width: 40px; height: 40px; flex-shrink: 0;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
}
.ico-green { background: #ECFDF5; color: #059669; }
.ico-blue  { background: #EFF6FF; color: var(--blue); }
.ico-amber { background: #FFFBEB; color: #D97706; }
.ico-violet{ background: #F5F3FF; color: #7C3AED; }
.feat-box-h { font-size: .92rem; font-weight: 700; color: var(--ink); margin-bottom: 4px; }
.feat-box-b { font-size: .82rem; color: var(--muted); line-height: 1.6; }

/* FOOTER */
footer {
  border-top: 1px solid var(--border);
  padding: 16px 24px;
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 10px;
}
.foot-copy { font-size: 12px; color: var(--muted); }
.foot-links { display: flex; gap: 18px; }
.foot-links a { font-size: 12px; color: var(--muted); }
.foot-links a:hover { color: var(--blue); }
</style>
</head>
<body>

<nav>
  <a href="/landing.php" class="logo">
    <div class="logo-box">
      <svg width="16" height="16" viewBox="0 0 20 20" fill="none">
        <g transform="translate(10,10) rotate(45)">
          <rect x="-6" y="-2.5" width="6" height="5" rx="2.5" fill="white"/>
          <rect x="0" y="-2.5" width="6" height="5" rx="2.5" fill="none" stroke="white" stroke-width="1"/>
        </g>
      </svg>
    </div>
    <span class="logo-name">Pharma<b>Pro</b></span>
  </a>
  <div class="nav-r">
    <a href="?lang=<?= $ar ? 'en' : 'ar' ?>" class="lang-btn"><?= $ar ? 'English' : 'عربي' ?></a>
    <a href="https://wa.me/96170000000" class="wa-btn">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
      WhatsApp
    </a>
  </div>
</nav>

<main>
  <p class="pain">
    <?= $ar ? 'لسا بتشتغل بالورقة والقلم؟' : 'Still running your pharmacy on paper?' ?>
  </p>

  <h1 class="headline">
    <?php if ($ar): ?>
      حوّل صيدليتك<br><span>لبرنامج احترافي</span>
    <?php else: ?>
      Run your pharmacy<br><span>like a pro</span>
    <?php endif; ?>
  </h1>

  <p class="tagline">
    <?= $ar
      ? 'نقطة بيع. مخزون. أسعار وزارة الصحة. تقارير. كل شيء بالعربي والإنجليزي.'
      : 'POS. Inventory. MoPH prices. Reports. Everything in Arabic and English.' ?>
  </p>

  <div class="price-block">
    <div class="price-amount"><sup>$</sup>399</div>
    <div class="price-meta">
      <?= $ar
        ? '<strong>السنة الأولى</strong> &nbsp;|&nbsp; تجديد $150/سنة'
        : '<strong>First year</strong> &nbsp;|&nbsp; Renewal $150/year' ?>
    </div>
  </div>

  <div class="includes">
    <?php
    $tags = $ar
      ? ['نقطة بيع بالباركود', 'إدارة المخزون', 'أسعار وزارة الصحة', 'تقارير مالية', 'وصفات وتأمين', 'دعم واتساب']
      : ['Barcode POS', 'Inventory', 'MoPH Prices', 'Financial Reports', 'Prescriptions', 'WhatsApp Support'];
    foreach ($tags as $t): ?>
    <span class="tag"><?= $t ?></span>
    <?php endforeach; ?>
  </div>

  <a href="https://wa.me/96170000000?text=<?= urlencode($ar ? 'مرحبا، أريد تجربة PharmaPro' : "Hi, I'd like to try PharmaPro") ?>" class="cta-btn">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
    <?= $ar ? 'احجز عرض مجاني الآن' : 'Book a free demo now' ?>
  </a>

  <p class="cta-note">
    <?= $ar
      ? 'أو <a href="/login.php">جرب البرنامج مباشرة</a> بدون اشتراك'
      : 'Or <a href="/login.php">try the live demo</a> with no commitment' ?>
  </p>
</main>

<!-- FEATURES: MoPH & Finance -->
<section class="features-section">
  <div class="feat-inner">

    <!-- MoPH -->
    <p class="feat-section-label"><?= $ar ? 'وزارة الصحة اللبنانية' : 'MoPH Compliance' ?></p>
    <h2 class="feat-section-title">
      <?= $ar ? 'متوافق مع وزارة الصحة. دائماً.' : 'Always compliant with Lebanese MoPH.' ?>
    </h2>
    <div class="feat-2col" style="margin-bottom:52px">

      <div class="feat-box">
        <div class="feat-box-ico ico-blue">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
        </div>
        <div>
          <div class="feat-box-h"><?= $ar ? 'قائمة أسعار مدمجة' : 'Built-in price list' ?></div>
          <div class="feat-box-b"><?= $ar ? 'أكثر من 10,000 دواء بأسعارهم الرسمية مدمجة مباشرة في النظام. لا حاجة لتحديث يدوي.' : 'Over 10,000 drugs with official MoPH prices built directly into the system. No manual updates needed.' ?></div>
        </div>
      </div>

      <div class="feat-box">
        <div class="feat-box-ico ico-amber">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <div>
          <div class="feat-box-h"><?= $ar ? 'تنبيه سعر فوري' : 'Instant price alert' ?></div>
          <div class="feat-box-b"><?= $ar ? 'إذا باع الصيدلاني بسعر خارج المعتمد، ينبّه النظام فوراً قبل إتمام البيع.' : 'If a pharmacist sells outside the approved price, the system alerts immediately before completing the sale.' ?></div>
        </div>
      </div>

      <div class="feat-box">
        <div class="feat-box-ico ico-violet">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
        </div>
        <div>
          <div class="feat-box-h"><?= $ar ? 'سجل المواد المخدرة' : 'Controlled substance register' ?></div>
          <div class="feat-box-b"><?= $ar ? 'سجل تلقائي لكل مادة خاضعة للرقابة مع التاريخ والكميات، جاهز لأي تدقيق.' : 'Automatic register for every controlled substance with dates and quantities, ready for any audit.' ?></div>
        </div>
      </div>

      <div class="feat-box">
        <div class="feat-box-ico ico-green">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
        </div>
        <div>
          <div class="feat-box-h"><?= $ar ? 'تتبع الأدوية المدعومة' : 'Subsidized drug tracking' ?></div>
          <div class="feat-box-b"><?= $ar ? 'تتبع تلقائي للأدوية المدعومة من وزارة الصحة والتأمينات الاجتماعية.' : 'Automatic tracking of drugs subsidized by MoPH and social security.' ?></div>
        </div>
      </div>

    </div>

    <!-- Finance -->
    <p class="feat-section-label"><?= $ar ? 'المالية والتقارير' : 'Finance & Reports' ?></p>
    <h2 class="feat-section-title">
      <?= $ar ? 'شوف أرباحك بضغطة زر.' : 'See your profits in one click.' ?>
    </h2>
    <div class="feat-2col">

      <div class="feat-box">
        <div class="feat-box-ico ico-green">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        </div>
        <div>
          <div class="feat-box-h"><?= $ar ? 'تقرير نهاية اليوم' : 'End-of-day report' ?></div>
          <div class="feat-box-b"><?= $ar ? 'ملخص يومي كامل: المبيعات، الصندوق، المصاريف، والأرباح. كل شيء دفعة واحدة.' : 'Full daily summary: sales, cash, expenses, and profit. Everything at once.' ?></div>
        </div>
      </div>

      <div class="feat-box">
        <div class="feat-box-ico ico-blue">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
        </div>
        <div>
          <div class="feat-box-h"><?= $ar ? 'ربح وخسارة' : 'Profit & loss' ?></div>
          <div class="feat-box-b"><?= $ar ? 'شوف هامش الربح على كل دواء. اعرف وين بتربح ووين بتخسر.' : 'See the profit margin on every drug. Know exactly where you earn and where you lose.' ?></div>
        </div>
      </div>

      <div class="feat-box">
        <div class="feat-box-ico ico-amber">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        </div>
        <div>
          <div class="feat-box-h"><?= $ar ? 'دولار وليرة' : 'USD & LBP' ?></div>
          <div class="feat-box-b"><?= $ar ? 'النظام يعمل بالدولار والليرة مع تحديث سعر الصرف. مناسب لواقع لبنان.' : 'Works in both USD and LBP with exchange rate support. Built for Lebanon\'s reality.' ?></div>
        </div>
      </div>

      <div class="feat-box">
        <div class="feat-box-ico ico-violet">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </div>
        <div>
          <div class="feat-box-h"><?= $ar ? 'إدارة المصاريف' : 'Expense management' ?></div>
          <div class="feat-box-b"><?= $ar ? 'سجّل كل مصروف وتابع أين يذهب المال. تقارير شهرية جاهزة للمحاسب.' : 'Log every expense and track where money goes. Monthly reports ready for your accountant.' ?></div>
        </div>
      </div>

    </div>
  </div>
</section>

<footer>
  <span class="foot-copy">© 2026 PharmaPro</span>
  <div class="foot-links">
    <a href="?lang=<?= $ar ? 'en' : 'ar' ?>"><?= $ar ? 'English' : 'عربي' ?></a>
    <a href="https://wa.me/96170000000">WhatsApp</a>
    <a href="/login.php"><?= $ar ? 'دخول' : 'Login' ?></a>
  </div>
</footer>

<?php if ($_gaId): ?>
<script>
document.querySelectorAll('a[href*="wa.me"]').forEach(el => {
  el.addEventListener('click', () => {
    gtag('event', 'whatsapp_cta_click', { event_category: 'engagement', language: '<?= $ar ? 'ar' : 'en' ?>' });
  });
});
</script>
<?php endif; ?>
</body>
</html>
