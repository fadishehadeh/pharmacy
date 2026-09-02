<?php
// Marketing plan page — no auth required, public
// Served at pharmapro.online/plan
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PharmaPro — Go-to-Market Plan</title>
<meta name="description" content="Lebanon pharmacy SaaS — first 6 months. Concrete, specific, actionable this week.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,600;0,9..144,700;1,9..144,400&family=IBM+Plex+Sans+Arabic:wght@300;400;500&family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
<style>
:root {
  --bg: #F4F6F4;
  --bg-card: #FFFFFF;
  --bg-subtle: #EAEEED;
  --text: #1B2120;
  --text-muted: #546060;
  --text-faint: #8A9898;
  --accent: #17685A;
  --accent-light: #ECF4F1;
  --amber: #A86318;
  --amber-light: #FAF3E6;
  --border: #D0D7D0;
  --stripe: #F0F3F0;
  --tag-bg: #E2EDEA;
  --tag-text: #1A5D50;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --bg: #131918;
    --bg-card: #1C2422;
    --bg-subtle: #22302D;
    --text: #E2EAE7;
    --text-muted: #8DAAA3;
    --text-faint: #5A7570;
    --accent: #2DB89A;
    --accent-light: #182E29;
    --amber: #D4893A;
    --amber-light: #271F0F;
    --border: #2B3A36;
    --stripe: #182220;
    --tag-bg: #1E3430;
    --tag-text: #2DB89A;
  }
}
:root[data-theme="dark"] {
  --bg: #131918;
  --bg-card: #1C2422;
  --bg-subtle: #22302D;
  --text: #E2EAE7;
  --text-muted: #8DAAA3;
  --text-faint: #5A7570;
  --accent: #2DB89A;
  --accent-light: #182E29;
  --amber: #D4893A;
  --amber-light: #271F0F;
  --border: #2B3A36;
  --stripe: #182220;
  --tag-bg: #1E3430;
  --tag-text: #2DB89A;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'Outfit','Segoe UI',system-ui,sans-serif;font-size:16px;line-height:1.65;-webkit-font-smoothing:antialiased}
.doc{max-width:860px;margin:0 auto;padding:52px 28px 88px}
.doc-header{margin-bottom:44px;padding-bottom:36px;border-bottom:2px solid var(--accent)}
.eyebrow{font-family:'JetBrains Mono',monospace;font-size:10.5px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--accent);margin-bottom:14px}
.doc-title{font-family:'Fraunces','Georgia',serif;font-size:clamp(34px,5.5vw,52px);font-weight:700;line-height:1.08;color:var(--text);text-wrap:balance;letter-spacing:-.02em;margin-bottom:14px}
.doc-subtitle{font-size:17px;font-weight:400;color:var(--text-muted);line-height:1.5;max-width:560px;margin-bottom:20px}
.doc-meta{display:flex;gap:0;flex-wrap:wrap}
.doc-meta-item{font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--text-faint);padding-right:18px;margin-right:18px;border-right:1px solid var(--border);line-height:1.6}
.doc-meta-item:last-child{border-right:none;padding-right:0;margin-right:0}
.doc-meta-item strong{color:var(--text-muted);font-weight:500}
.toc{background:var(--bg-subtle);border:1px solid var(--border);border-left:3px solid var(--accent);padding:20px 24px 18px;margin-bottom:52px}
.toc .eyebrow{margin-bottom:14px}
.toc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:2px 20px;list-style:none}
.toc-grid a{display:flex;align-items:baseline;gap:9px;color:var(--text-muted);text-decoration:none;font-size:13.5px;padding:5px 0;border-bottom:1px solid transparent;transition:color .15s}
.toc-grid a:hover{color:var(--accent)}
.toc-num{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--text-faint);min-width:16px;flex-shrink:0}
.section{margin-bottom:64px}
.section-label{font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--accent);margin-bottom:8px;display:block}
.section-title{font-family:'Fraunces','Georgia',serif;font-size:clamp(22px,3.5vw,30px);font-weight:600;line-height:1.2;color:var(--text);text-wrap:balance;letter-spacing:-.015em;margin-bottom:22px;padding-bottom:18px;border-bottom:1px solid var(--border)}
p{color:var(--text);font-size:15.5px;line-height:1.68;margin-bottom:14px;max-width:68ch}
p:last-child{margin-bottom:0}
strong{font-weight:600}
h3{font-family:'Outfit',sans-serif;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-faint);margin:30px 0 12px}
h4{font-family:'Fraunces',serif;font-size:18px;font-weight:600;color:var(--text);margin:22px 0 10px;letter-spacing:-.01em}
.table-wrap{overflow-x:auto;margin:20px 0 28px;border:1px solid var(--border);border-radius:5px}
table{width:100%;border-collapse:collapse;font-size:14px}
thead th{background:var(--bg-subtle);font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--text-faint);text-align:left;padding:10px 15px;border-bottom:1px solid var(--border);white-space:nowrap}
tbody td{padding:12px 15px;border-bottom:1px solid var(--stripe);color:var(--text);vertical-align:top;line-height:1.45;font-size:14px}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:var(--bg-subtle)}
.tier{font-weight:700;color:var(--accent);font-size:15px}
.price{font-family:'JetBrains Mono',monospace;font-weight:500;font-size:16px;color:var(--text);font-variant-numeric:tabular-nums}
.price-yr{font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--text-muted);display:block;margin-top:2px}
.mrr{font-family:'JetBrains Mono',monospace;font-weight:500;color:var(--accent);font-variant-numeric:tabular-nums}
.tag{display:inline-block;background:var(--tag-bg);color:var(--tag-text);font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.06em;text-transform:uppercase;padding:2px 7px;border-radius:3px;margin-top:2px}
.callout{padding:18px 22px;margin:20px 0;border-radius:0 4px 4px 0}
.callout-green{background:var(--accent-light);border-left:3px solid var(--accent)}
.callout-amber{background:var(--amber-light);border-left:3px solid var(--amber)}
.callout .eyebrow{margin-bottom:8px}
.callout-amber .eyebrow{color:var(--amber)}
.callout p{font-size:14.5px;margin-bottom:6px;max-width:none}
.callout p:last-child{margin-bottom:0}
.script-block{border:1px solid var(--border);border-radius:5px;margin:16px 0;overflow:hidden}
.script-head{background:var(--bg-subtle);padding:8px 16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border)}
.wa-dot{width:10px;height:10px;background:#25D366;border-radius:50%;flex-shrink:0}
.script-lang{font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--text-faint)}
.script-body{padding:18px 20px;font-size:14.5px;line-height:1.65;color:var(--text);background:var(--bg-card);white-space:pre-wrap;font-family:'Outfit',sans-serif}
.script-body-ar{font-family:'IBM Plex Sans Arabic','Tahoma','Arial',sans-serif;direction:rtl;text-align:right}
.deal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin:16px 0}
.deal-card{background:var(--bg-card);border:1px solid var(--border);border-top:3px solid var(--accent);padding:16px}
.deal-label{font-family:'JetBrains Mono',monospace;font-size:9.5px;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:var(--text-faint);margin-bottom:6px}
.deal-value{font-family:'Fraunces',serif;font-size:26px;font-weight:700;color:var(--accent);line-height:1;margin-bottom:5px}
.deal-note{font-size:12.5px;color:var(--text-muted);line-height:1.4}
.week-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;margin:16px 0 24px}
.week-card{background:var(--bg-card);border:1px solid var(--border);padding:16px}
.week-day{font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid var(--border)}
.week-card ul{list-style:none;padding:0;margin:0}
.week-card li{font-size:13px;color:var(--text-muted);padding:4px 0;margin:0;max-width:none;display:flex;gap:7px;line-height:1.4}
.week-card li::before{content:'→';color:var(--accent);flex-shrink:0;font-weight:600}
.action-list{list-style:none;padding:0;margin:16px 0}
.action-list li{display:flex;gap:14px;align-items:baseline;padding:10px 0;border-bottom:1px solid var(--stripe);max-width:none;font-size:14.5px}
.action-list li:last-child{border-bottom:none}
.action-num{font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:500;color:var(--accent);min-width:22px;flex-shrink:0;padding-top:2px}
.obj-list{margin:16px 0;display:flex;flex-direction:column;gap:10px}
.obj-item{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--border);border-radius:4px;overflow:hidden}
.obj-q,.obj-a{padding:14px 18px;font-size:14px;line-height:1.5}
.obj-q{background:var(--bg-subtle);border-right:1px solid var(--border);font-style:italic;color:var(--text-muted)}
.obj-a{background:var(--bg-card);color:var(--text)}
.obj-a strong{color:var(--accent)}
@media (max-width:580px){.obj-item{grid-template-columns:1fr}.obj-q{border-right:none;border-bottom:1px solid var(--border)}}
ul.plain,ol.plain{padding-left:20px;margin:12px 0 18px}
ul.plain li,ol.plain li{font-size:15px;color:var(--text);margin-bottom:7px;line-height:1.55;max-width:68ch}
ul.plain li strong{color:var(--accent)}
tr.highlight td{background:var(--accent-light) !important}
tr.highlight td:first-child{font-weight:700;color:var(--accent)}
.divider{border:none;border-top:1px solid var(--border);margin:32px 0}
.doc-footer{margin-top:72px;padding-top:24px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.footer-brand{font-family:'Fraunces',serif;font-size:17px;font-weight:700;color:var(--accent);letter-spacing:-.01em}
.footer-note{font-family:'JetBrains Mono',monospace;font-size:10.5px;color:var(--text-faint)}
.mb8{margin-bottom:8px}
.mt24{margin-top:24px}
.color-amber{color:var(--amber)}
table p,.script-body p,.callout p,.obj-q p,.obj-a p{max-width:none}
</style>
</head>
<body>
<div class="doc">

  <header class="doc-header">
    <p class="eyebrow">Confidential &middot; Go-to-Market Strategy &middot; August 2026</p>
    <h1 class="doc-title">PharmaPro<br>Marketing Plan</h1>
    <p class="doc-subtitle">Lebanon pharmacy SaaS &mdash; first 6 months. Concrete, specific, actionable this week.</p>
    <div class="doc-meta">
      <span class="doc-meta-item"><strong>Market</strong> ~3,000 Lebanese pharmacies</span>
      <span class="doc-meta-item"><strong>Competitor</strong> Pharmalink (expensive, desktop)</span>
      <span class="doc-meta-item"><strong>Product</strong> pharmapro.online</span>
    </div>
  </header>

  <nav class="toc">
    <p class="eyebrow">Contents</p>
    <ul class="toc-grid">
      <li><a href="#pricing"><span class="toc-num">01</span> Pricing Model</a></li>
      <li><a href="#gtm"><span class="toc-num">02</span> First 30 Pharmacies</a></li>
      <li><a href="#partner"><span class="toc-num">03</span> The Pharmacist Partner</a></li>
      <li><a href="#opl"><span class="toc-num">04</span> OPL &amp; Professional Networks</a></li>
      <li><a href="#digital"><span class="toc-num">05</span> Digital Presence</a></li>
      <li><a href="#demo"><span class="toc-num">06</span> Demo Strategy</a></li>
      <li><a href="#retention"><span class="toc-num">07</span> Retention</a></li>
      <li><a href="#milestones"><span class="toc-num">08</span> 6-Month Milestones</a></li>
    </ul>
  </nav>

  <section class="section" id="pricing">
    <span class="section-label">Strategy &middot; Pricing</span>
    <h2 class="section-title">Pricing Model</h2>
    <p>Lebanese pharmacies operate in two currencies and under persistent dollar scarcity. Your pricing has to feel safe &mdash; low enough that a pharmacist signs up on their own authority without asking anyone, and clearly cheaper than Pharmalink by a margin they can quote to their spouse. Three tiers, USD-first, with an annual option that softens the cashflow ask.</p>
    <h3>Tier Structure</h3>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tier</th><th>Monthly</th><th>Annual</th><th>Who it&rsquo;s for</th><th>What&rsquo;s included</th></tr></thead>
        <tbody>
          <tr><td><span class="tier">Starter</span></td><td><span class="price">$19<span class="price-yr">$190 / yr</span></span></td><td><span class="price-yr">Save $38</span></td><td>Solo pharmacist, paper-based today, wants to start digital</td><td>1 workstation &middot; Core inventory &middot; MoPH pricing &middot; Basic sales log &middot; Offline PWA</td></tr>
          <tr><td><span class="tier">Professional</span></td><td><span class="price">$35<span class="price-yr">$350 / yr</span></span></td><td><span class="price-yr">Save $70</span></td><td>Active pharmacy wanting full software replacement</td><td>Unlimited workstations &middot; Full inventory &middot; Dual USD+LBP &middot; Loyalty &middot; Supplier orders &middot; Complete reports</td></tr>
          <tr><td><span class="tier">Chain / Clinic</span></td><td><span class="price">$65<span class="price-yr">$650 / yr</span></span></td><td><span class="price-yr">Save $130</span></td><td>Multi-branch owner or clinic-attached pharmacy</td><td>Multi-branch dashboard &middot; Staff roles &middot; Advanced analytics &middot; Priority WhatsApp support &middot; Custom report exports</td></tr>
        </tbody>
      </table>
    </div>
    <div class="callout callout-green">
      <p class="eyebrow">Free Trial Strategy</p>
      <p><strong>30 days free, no credit card required.</strong> After 30 days, send a WhatsApp message &mdash; not an automated email &mdash; from the pharmacist partner. Keep all data accessible during the grace period. Convert on day 35, not day 30.</p>
      <p>The ask on day 35: "آخر يوم للتجربة المجانية. هلق شو بتفضل؟" &mdash; this personal follow-up converts 3&times; better than a payment wall.</p>
    </div>
    <h3>Payment Methods &mdash; Designed for Lebanon</h3>
    <p>Do not require a credit card at any point. Lebanese pharmacists distrust card payments for recurring SaaS. Accept:</p>
    <ul class="plain">
      <li><strong>Cash USD</strong> &mdash; collected in person by the pharmacist partner (most common, fastest to close)</li>
      <li><strong>OMT / Western Union / Wish Money</strong> &mdash; for pharmacies outside Beirut</li>
      <li><strong>Wise transfer</strong> &mdash; for tech-comfortable owners</li>
      <li><strong>LBP equivalent</strong> at the day&rsquo;s Sayrafa rate &mdash; accept this, document it, move on</li>
    </ul>
    <div class="callout callout-amber">
      <p class="eyebrow">Positioning vs. Pharmalink</p>
      <p>Pharmalink&rsquo;s entry price is rumored at $60&ndash;80/month for a single license, with setup fees and no web access. Your Professional tier at $35 is less than half the cost, runs in the browser, works offline, and has bilingual UI. Lead with this comparison &mdash; pharmacists who already pay Pharmalink are your easiest conversions.</p>
    </div>
  </section>

  <section class="section" id="gtm">
    <span class="section-label">Go-to-Market</span>
    <h2 class="section-title">First 30 Pharmacies</h2>
    <h3>Who to Target First</h3>
    <p>Avoid Pharmalink&rsquo;s existing heavy users in month one &mdash; converting them means fighting inertia and data migration anxiety. Your best early targets are the ~40% of Lebanese pharmacies still running on paper or Excel. Find them by looking for pharmacies with no online presence, no delivery app partnerships, and pharmacists under 45.</p>
    <p><strong>Priority geography:</strong> Metn, Jdeideh, Zalka, Dekwaneh, Bauchrieh. Dense pharmacy clusters, tech-progressive owners, easy in-person follow-up from Beirut. Avoid Tripoli and the South in month one &mdash; good markets eventually, but logistics for cash collection and demos are harder.</p>
    <h3>The 12-Step Outreach Sequence</h3>
    <ol class="action-list">
      <li><span class="action-num">01</span><div>Map 60 target pharmacies on Google Maps in a 5km radius. Note: has WhatsApp Business profile? Has delivery app? Appears modern or traditional? Rate each 1&ndash;3 on approachability.</div></li>
      <li><span class="action-num">02</span><div>Get the pharmacist&rsquo;s personal WhatsApp number &mdash; ask the pharmacist partner to call the pharmacy directly and ask for it, framing it as "we&rsquo;re doing a local survey."</div></li>
      <li><span class="action-num">03</span><div>Send the EN or AR script below. Do NOT use a broadcast list &mdash; send individually from a real phone number. WhatsApp Business API broadcasts get blocked and feel impersonal.</div></li>
      <li><span class="action-num">04</span><div>Wait 48 hours. If no reply, send a follow-up voice note (30 seconds max) from the pharmacist partner in Lebanese dialect. Voice notes have dramatically higher open rates than text in Lebanon.</div></li>
      <li><span class="action-num">05</span><div>On reply, offer a demo at their pharmacy &mdash; not Zoom. Walk in with a laptop or tablet. The in-person visit signals that you&rsquo;re local and serious.</div></li>
      <li><span class="action-num">06</span><div>At the demo, start with MoPH price integration. It&rsquo;s the feature that makes every pharmacist say "wait, show me that again." (See Section 06.)</div></li>
      <li><span class="action-num">07</span><div>Leave a one-page Arabic/English leave-behind. QR code to pharmapro.online, pricing, 30-day trial. Print 100 copies &mdash; $8 at any print shop.</div></li>
      <li><span class="action-num">08</span><div>If they say yes: set up the trial together, on the spot, before you leave. Every day of delay is a 20% chance they forget.</div></li>
      <li><span class="action-num">09</span><div>Send a WhatsApp message 3 days after trial start: "كيف عم تمشي التجربة؟ في شي بدك مساعدة فيه؟" &mdash; check in personally, fix any issue instantly.</div></li>
      <li><span class="action-num">10</span><div>On day 20 of trial, send a specific conversion message based on what they actually used. "شفت إنك استخدمت المخزون كتير &mdash; Pro tier بضيفلك الطلبيات من الموردين."</div></li>
      <li><span class="action-num">11</span><div>Collect cash payment in person. Ask for a referral at the same time: "في صيدليين تانيين بتعرفهم ممكن يستفيدوا؟" With payment in hand, they&rsquo;ll almost always name someone.</div></li>
      <li><span class="action-num">12</span><div>Ask for a Google review and a WhatsApp testimonial you can share (with permission). These are gold for the next wave of outreach.</div></li>
    </ol>
    <h3>WhatsApp Outreach Scripts</h3>
    <div class="script-block">
      <div class="script-head"><span class="wa-dot"></span><span class="script-lang">English &middot; Cold Outreach</span></div>
      <div class="script-body">Hi Dr. [Name],

I'm [Your name] — we're building PharmaPro, a pharmacy management system made specifically for Lebanon.

Here's what makes it different from Pharmalink:
• Works offline during power cuts (PWA — no internet needed)
• Handles USD and LBP with live exchange rates
• MoPH price list built in and auto-updated
• Costs less than half of what Pharmalink charges

We're giving 30-day free trials — no credit card, no commitment — to the first pharmacies to try it.

Could we do a quick 10-minute walkthrough? I can come to your pharmacy at a time that suits you, or we can do it on a video call.

PharmaPro.online — built in Lebanon, for Lebanon.</div>
    </div>
    <div class="script-block">
      <div class="script-head"><span class="wa-dot"></span><span class="script-lang">Arabic &middot; Cold Outreach</span></div>
      <div class="script-body script-body-ar">السلام عليكم دكتور [الاسم]،

أنا [اسمك] — عم نشتغل على PharmaPro، برنامج إدارة صيدلية مصمم خصيصاً للسوق اللبناني.

شو بيميزنا عن Pharmalink:
• بيشتغل بدون نت أو كهرباء (PWA)
• بيدعم الدولار والليرة مع سعر الصرف
• أسعار نقابة الصيادلة محدّثة تلقائياً
• بأقل من نص تكلفة Pharmalink

عم نعطي 30 يوم تجربة مجانية — بدون بطاقة ائتمان، بدون أي التزام — لأول الصيدليات اللي بتجرب.

في وقت مناسب نعمل جولة على البرنامج؟ ممكن آجي عندك بالصيدلية، أو واتساب/زوم — اللي أريحلك.

PharmaPro.online — مصنوع بلبنان، للبنان.</div>
    </div>
    <div class="callout callout-amber">
      <p class="eyebrow">The Voice Note Follow-Up</p>
      <p>If no reply after 48 hours, send a 25-second WhatsApp voice note in Lebanese dialect. Voice notes convert 3&times; better than text in Lebanon. They signal a human, not a bot.</p>
    </div>
  </section>

  <section class="section" id="partner">
    <span class="section-label">Team &middot; Partnerships</span>
    <h2 class="section-title">The Pharmacist Partner as Your Greatest Asset</h2>
    <p>An unemployed pharmacist is not a liability &mdash; they are an unfair advantage. They have a professional license, peer credibility, and time. Structure this relationship with precision: clear title, transparent commission, defined tasks. A pharmacist who earns $150/month from your referral pipeline has income, purpose, and skin in your game.</p>
    <h3>Title &amp; Role</h3>
    <p>Give them the title <strong>"Founding Pharmacist Partner."</strong> This matters &mdash; it gives them status when walking into a peer&rsquo;s pharmacy. They are not a sales rep. They are a pharmacist who happens to know about a software they personally recommend. This framing works in Lebanon&rsquo;s trust-based professional culture.</p>
    <h3>Commission Structure</h3>
    <div class="deal-grid">
      <div class="deal-card"><p class="deal-label">Referral Commission</p><p class="deal-value">20%</p><p class="deal-note">Of first 12 months&rsquo; subscription for every pharmacy they sign. Paid monthly as cash or OMT.</p></div>
      <div class="deal-card"><p class="deal-label">Active Account Bonus</p><p class="deal-value">$5</p><p class="deal-note">Per active paying account monthly, as long as the account stays active. Recurring incentive to provide support.</p></div>
      <div class="deal-card"><p class="deal-label">Example Month 3</p><p class="deal-value">~$85</p><p class="deal-note">If they&rsquo;ve signed 6 pharmacies at $35 avg: $42 commission + $30 active bonus + 2 new referrals.</p></div>
      <div class="deal-card"><p class="deal-label">Written Agreement</p><p class="deal-value">Yes</p><p class="deal-note">One page. Signed. Even between friends. Clarifies expectations and protects the relationship.</p></div>
    </div>
    <h3>Week 1 Task Plan</h3>
    <div class="week-grid">
      <div class="week-card"><p class="week-day">Day 1&ndash;2</p><ul><li>Full walk-through of PharmaPro &mdash; every screen</li><li>Document the top 3 confusing UX moments</li><li>Try to break the offline mode; report bugs</li><li>Confirm MoPH prices are accurate</li></ul></div>
      <div class="week-card"><p class="week-day">Day 3</p><ul><li>List 20 pharmacist contacts personally known</li><li>Rate each: likely early adopter vs. skeptic</li><li>Identify which 5 to approach first</li><li>Draft voice note intro in their natural speech</li></ul></div>
      <div class="week-card"><p class="week-day">Day 4&ndash;5</p><ul><li>Send outreach to top 5 contacts</li><li>Book 2 in-person demo visits</li><li>Conduct both demos; take notes on objections</li><li>Share unfiltered feedback with you same day</li></ul></div>
      <div class="week-card"><p class="week-day">Day 6&ndash;7</p><ul><li>Review objection list together</li><li>Help write the Arabic FAQ section</li><li>Identify which WhatsApp groups they&rsquo;re in</li><li>Plan week 2 outreach targets</li></ul></div>
    </div>
    <div class="callout callout-green">
      <p class="eyebrow">Domain Validator Role</p>
      <p>Everything you build should pass through the pharmacist partner first. Before shipping any new feature, ask them one question: <em>"Would you use this? Would your colleagues care?"</em> Their instinct saves you from building things pharmacists don&rsquo;t need. This is the highest-leverage use of the relationship &mdash; more valuable than any sale they make.</p>
    </div>
  </section>

  <section class="section" id="opl">
    <span class="section-label">Partnerships &middot; Community</span>
    <h2 class="section-title">OPL &amp; Professional Network Strategy</h2>
    <p>The Order of Pharmacists of Lebanon (OPL / نقابة الصيادلة) is both a channel and a credibility signal. A single endorsement or meeting slot from the OPL is worth 50 cold WhatsApp messages. Play the long game here &mdash; don&rsquo;t lead with a sales pitch.</p>
    <h3>OPL Approach &mdash; Three Phases</h3>
    <ul class="plain">
      <li><strong>Phase 1 (Month 1&ndash;2): Observe and contribute.</strong> Attend any open OPL meetings. Do not pitch. Ask questions. Understand what problems the OPL is trying to solve with technology.</li>
      <li><strong>Phase 2 (Month 2&ndash;3): Request a 15-minute slot.</strong> Frame it as "a Lebanon-built tool that integrates the OPL&rsquo;s own price list." Bring the pharmacist partner as a co-presenter &mdash; peer-to-peer credibility.</li>
      <li><strong>Phase 3 (Month 4+): Propose a formal partnership.</strong> Offer OPL members a discounted rate (10% off annual plans) in exchange for a logo/endorsement on the website.</li>
    </ul>
    <h3>WhatsApp Groups &mdash; The Real Channel</h3>
    <ul class="plain">
      <li><strong>Never cold-post to a group you haven&rsquo;t been introduced to.</strong> It reads as spam and will get you removed. Ask the pharmacist partner to post instead.</li>
      <li><strong>Post as a genuine announcement, not an ad.</strong> "آخيرًا في برنامج لبناني بيدعم أسعار النقابة ومتوافق مع الانقطاع بالكهرباء &mdash; شو رأيكم؟"</li>
      <li><strong>Let positive reactions pull you in.</strong> If someone replies with interest, that&rsquo;s the invitation to share your link or offer a demo.</li>
      <li><strong>Time your posts for 8&ndash;9pm Sunday or Monday.</strong> Lebanese professionals check WhatsApp groups most actively Sunday evening before the work week starts.</li>
    </ul>
    <div class="callout callout-amber">
      <p class="eyebrow">MoPH as a Partnership Angle</p>
      <p>The Ministry of Public Health publishes the price list PharmaPro integrates. Consider sending a formal email to the MoPH Digital Health unit explaining what PharmaPro does with their data. Cost: one well-written email. Potential return: a credibility signal worth 10&times; any advertising.</p>
    </div>
  </section>

  <section class="section" id="digital">
    <span class="section-label">Digital &middot; Brand</span>
    <h2 class="section-title">Digital Presence</h2>
    <h4>pharmapro.online &mdash; Landing Page Priorities</h4>
    <p>The landing page has one job: make a skeptical Lebanese pharmacist say "هيدا بيفهم مشاكلنا." Structure it around pain, not features.</p>
    <ul class="plain">
      <li><strong>Above the fold:</strong> A headline that names the problem. Something like: "Your pharmacy software should work during a power cut. / برنامج صيدلتك لازم يشتغل وقت انقطاع الكهرباء."</li>
      <li><strong>First section:</strong> The three Lebanon-specific problems you solve: offline mode, dual currency, MoPH pricing. Each with a 15-second GIF of the actual feature.</li>
      <li><strong>Pricing section:</strong> All three tiers. Make the monthly price prominent. Show the "save 2 months" annual offer. Add a comparison table with Pharmalink.</li>
      <li><strong>Social proof:</strong> Even 3 pharmacist quotes (real names, pharmacy name, area) are worth more than any design feature. Get these in week 2.</li>
      <li><strong>CTA:</strong> One button &mdash; "ابدأ تجربتك المجانية / Start Free Trial." WhatsApp contact visible in the header.</li>
    </ul>
    <h3>SEO &mdash; Lebanon-Specific</h3>
    <ul class="plain">
      <li>pharmacy software Lebanon / برنامج صيدلية لبنان</li>
      <li>MoPH price list Lebanon pharmacy / أسعار نقابة الصيادلة</li>
      <li>Pharmalink alternative Lebanon</li>
      <li>offline pharmacy software Lebanon</li>
      <li>برنامج محاسبة صيدلية لبنان</li>
    </ul>
    <div class="callout callout-green">
      <p class="eyebrow">This Week&rsquo;s Digital Actions</p>
      <p>1. Verify pharmapro.online is live with a WhatsApp click-to-chat button.<br>2. Create Google Business Profile (20 minutes).<br>3. Create Instagram @pharmaprolebanon, write Arabic bio, post first content.<br>4. Write one blog post targeting "برنامج صيدلية لبنان."</p>
    </div>
  </section>

  <section class="section" id="demo">
    <span class="section-label">Sales &middot; Conversion</span>
    <h2 class="section-title">Demo Strategy</h2>
    <p>The demo is not a product tour. It is a series of moments designed to make a specific pharmacist recognize their own problem and immediately see it solved. Run it in 12 minutes or less. In-person at the pharmacy is always better than video.</p>
    <h3>Feature Order &mdash; Non-Negotiable</h3>
    <ol class="plain">
      <li><strong>MoPH Price Integration (2 min).</strong> Ask them: "كيف عم تتحقق من أسعار النقابة هلق؟" Then show the lookup. Watch their face. This is the moment.</li>
      <li><strong>Offline Mode / PWA (2 min).</strong> Turn off your phone&rsquo;s WiFi. Keep working. Show a sale going through. Then reconnect and show the sync.</li>
      <li><strong>Dual Currency (1 min).</strong> Switch between USD and LBP. Show the exchange rate. Show that a receipt can show both.</li>
      <li><strong>Sales Flow / POS (2 min).</strong> Walk through a typical transaction from start to receipt. Count the taps.</li>
      <li><strong>Reports (1 min).</strong> Show the daily sales report. "هيدا الريبورت بيظهرلك كل يوم تلقائياً."</li>
      <li><strong>The 30-day trial offer (1 min).</strong> After the MoPH moment: "خليني أفتحلك حساب هلق &mdash; عندك 30 يوم مجاناً، بدون بطاقة."</li>
    </ol>
    <h3>Objection Handling</h3>
    <div class="obj-list">
      <div class="obj-item"><div class="obj-q">"الإنترنت عنا ما في &mdash; البرنامج شو بيصير إذا قطع؟"</div><div class="obj-a"><strong>Show it live.</strong> Turn off WiFi on the spot. Let them watch you process a sale. "هيدا الـ PWA &mdash; بيشتغل كمتل تطبيق على هاتفك حتى لو ما في نت."</div></div>
      <div class="obj-item"><div class="obj-q">"كيف بعرف إنكم ما رح تختفوا بعد شهر؟"</div><div class="obj-a"><strong>Be specific.</strong> "أنا لبناني، محاسبي هون. رقم واتسابي هيدا هو نفس الرقم اللي رح يرد عليك إذا في مشكلة &mdash; مش support ticket."</div></div>
      <div class="obj-item"><div class="obj-q">"عنا Pharmalink بيشتغل &mdash; شو الفايدة؟"</div><div class="obj-a"><strong>Don&rsquo;t fight it.</strong> "3 أسئلة: بيشتغل بدون نت؟ بيدعم الليرة؟ بدفع أقل من $35؟ إذا الجواب نعم على الثلاثة، بنبلش."</div></div>
      <div class="obj-item"><div class="obj-q">"$35 بالشهر كتير &mdash; ميزانيتنا ضيقة."</div><div class="obj-a"><strong>Reframe the cost.</strong> "حساب واحد غلط بالأسعار بيكلفك أكتر من $35. البرنامج عم يوفرلك وقت ومصاري &mdash; مش مصروف إضافي."</div></div>
    </div>
  </section>

  <section class="section" id="retention">
    <span class="section-label">Product &middot; Retention</span>
    <h2 class="section-title">What Keeps Them</h2>
    <p>A pharmacist who has entered 3 months of inventory data, configured their price lists, and trained their staff on PharmaPro will not switch back without a significant reason. Your job is to make sure that reason never exists.</p>
    <ul class="plain">
      <li><strong>Data lock-in (natural, not hostile).</strong> Once inventory, purchase history, and customer loyalty points are in the system, switching costs are real. Make sure the data feels owned by the pharmacist: export-to-CSV always available, no surprises.</li>
      <li><strong>The MoPH update as a monthly touchpoint.</strong> Every time the MoPH updates the price list, send a WhatsApp message: "تنبيه: النقابة حدّثت الأسعار &mdash; PharmaPro اتحدث تلقائياً 🟢"</li>
      <li><strong>Personal support as retention.</strong> In the first 6 months, every support request gets a personal WhatsApp reply within 2 hours during business hours. A pharmacist who feels seen and supported will not leave for a $10 cheaper alternative.</li>
      <li><strong>Monthly usage summary.</strong> "هيدا شهر [Month] على PharmaPro: X فاتورة، Y مبيعات بالدولار." Even small numbers feel validating when surfaced.</li>
    </ul>
    <div class="callout callout-amber">
      <p class="eyebrow">What Kills Retention</p>
      <p><strong>Silent bugs.</strong> A pharmacist who finds an incorrect MoPH price will leave and tell 10 others. <strong>Downtime.</strong> Over-communicate: WhatsApp message, ETA, apology, explanation. Never go silent.</p>
    </div>
  </section>

  <section class="section" id="milestones">
    <span class="section-label">Targets &middot; Timeline</span>
    <h2 class="section-title">6-Month Milestones</h2>
    <p>These numbers assume you and the pharmacist partner are both actively working on sales and support. Month 1 MRR is zero by design: you want real data before you ask for money.</p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Month</th><th>Active Trials</th><th>Paying</th><th>MRR (USD)</th><th>Key Milestone</th></tr></thead>
        <tbody>
          <tr class="highlight"><td>Month 1</td><td>5&ndash;8</td><td>0</td><td class="mrr">$0</td><td>Pharmacist partner onboarded. First 5 demos. Collect brutal feedback. No charging yet &mdash; learn.</td></tr>
          <tr><td>Month 2</td><td>15</td><td>4</td><td class="mrr">$100</td><td>First paying customers (cash in person). WhatsApp group presence begins. Google Business live.</td></tr>
          <tr><td>Month 3</td><td>25</td><td>12</td><td class="mrr">$300</td><td>OPL meeting initiated. First 3 Google reviews. Partner earns first commission payment.</td></tr>
          <tr><td>Month 4</td><td>38</td><td>22</td><td class="mrr">$620</td><td>Referral program active. NSSF claim log launched. First chain pharmacy demo.</td></tr>
          <tr><td>Month 5</td><td>52</td><td>35</td><td class="mrr">$1,000</td><td>$1k MRR milestone. WhatsApp receipts feature live. First customer case study published.</td></tr>
          <tr><td>Month 6</td><td>70</td><td>50</td><td class="mrr">$1,450</td><td>$1.4k MRR (~$17k ARR). OPL partnership discussion. Raise Starter tier to $22 for new signups.</td></tr>
        </tbody>
      </table>
    </div>
    <div class="callout callout-green">
      <p class="eyebrow">The Only Number That Matters in Month 1</p>
      <p>Not MRR. Not signups. It&rsquo;s the number of pharmacists who, after their trial, say they would pay for it. If that number is 4 out of 5, you have product-market fit. If it&rsquo;s 1 out of 5, stop selling and start listening.</p>
    </div>
  </section>

  <footer class="doc-footer">
    <span class="footer-brand">PharmaPro</span>
    <span class="footer-note">pharmapro.online &middot; Confidential &middot; August 2026</span>
  </footer>

</div>
</body>
</html>
