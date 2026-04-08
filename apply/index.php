<?php
/**
 * GSD ASSOCIATES — Smart Recruitment Form (V3 + CV Extractor Max Coverage)
 * API keys are NEVER sent to the browser — all AI calls go through api-proxy4.php
 */
require_once dirname(__DIR__) . '/config/runtime.php';

$_gsd_env = gsdRecruitmentLoadEnv();
$_cloudName = htmlspecialchars($_gsd_env['CLOUDINARY_CLOUD_NAME'] ?? '');
$_cloudPreset = htmlspecialchars($_gsd_env['CLOUDINARY_UPLOAD_PRESET'] ?? '');

// Only expose boolean availability — NEVER expose the keys themselves
$_aiStatus = json_encode([
    'claude' => !empty($_gsd_env['CLAUDE_API_KEY']),
    'gemini' => !empty($_gsd_env['GEMINI_API_KEY']),
    'openai' => !empty($_gsd_env['OPENAI_API_KEY']),
    'groq' => !empty($_gsd_env['GROQ_API_KEY']),
    'openrouter' => !empty($_gsd_env['OPENROUTER_API_KEY']),
    'order' => $_gsd_env['AI_ORDER'] ?? 'gemini,claude,openai',
    'env_loaded' => !empty($_gsd_env),
    'env_path' => $_gsd_env['__path'] ?? '',
]) ?: '{"claude":false,"gemini":false,"openai":false,"groq":false,"openrouter":false,"order":"gemini,claude,openai"}';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GSD Associates — Smart Application</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
<script>pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js"></script>

<!-- Librerías para la Cámara y Eliminación de Fondo -->
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/selfie_segmentation.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/camera_utils/camera_utils.js"></script>

<script src="candidates-autofill.js"></script>

<!-- Imagen de fondo para el reemplazo -->
<img id="bg-image" src="assets/images/backgroundGSD.png" class="hidden" crossorigin="anonymous">

<style>
:root{--g:#5A3988;--gl:#8C52FF;--gd:#3d2460;--gbg:#f3e8ff;}
*{font-family:'Inter',system-ui,sans-serif;box-sizing:border-box;}

/* ── Form Steps ── */
.step-pane{display:none;animation:fi .35s ease;}
.step-pane.active{display:block;}
@keyframes fi{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

/* ── Dropzones ── */
.dz{position:relative;border:2px dashed #c4b5fd;border-radius:12px;padding:24px 16px;text-align:center;cursor:pointer;transition:all .25s;}
.dz:hover,.dz.drag{border-color:var(--gl);background:#faf5ff;}
.dz.ok{border-color:#22c55e;background:#f0fdf4;border-style:solid;}
.dz input[type=file]{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer;}

/* ── Step dots ── */
.dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;transition:all .25s;}
.dot.done{background:#22c55e;color:#fff;}
.dot.active{background:var(--g);color:#fff;box-shadow:0 0 0 4px rgba(90,57,136,.2);}
.dot.pending{background:#e5e7eb;color:#9ca3af;}

/* ── Form ── */
.lbl{display:block;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:4px;}
.req::after{content:" *";color:#ef4444;}
.inp{width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;background:#fff;}
.inp:focus{outline:none;border-color:var(--gl);box-shadow:0 0 0 3px rgba(140,82,255,.1);}
.ai-hi{border:2px solid var(--gl)!important;background:#faf5ff!important;}
.sc{background:#fff;padding:20px 22px;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.06);border:1px solid #f0f0f0;margin-bottom:12px;}
.sh{font-size:14px;font-weight:700;color:#1f2937;border-bottom:1px solid #f3f4f6;padding-bottom:10px;margin-bottom:16px;}

/* ── Pipeline status ── */
.xd-item{display:flex;align-items:center;gap:6px;font-size:9px;text-transform:uppercase;font-weight:800;color:#9ca3af;transition:all .3s;}
.xd-item.run{color:#8C52FF;animation:pulse 1s infinite;}
.xd-item.done{color:#16a34a;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}

/* ── Spinner ── */
.sp{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;}
.sp-dark{border-color:rgba(90,57,136,.2);border-top-color:var(--g);}
@keyframes spin{to{transform:rotate(360deg)}}

/* ══ EXTRACTOR SIDE PANEL ══ */
.ext-card{background:#fff;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid #e5e7eb;margin-bottom:10px;}
.ext-sh{font-size:10px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #f3f4f6;padding-bottom:8px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;}

.ef{background:#f9fafb;border:1px solid #e5e7eb;border-radius:7px;padding:8px 12px;margin-bottom:6px;border-left:4px solid #e5e7eb;}
.ef label{display:block;font-size:8px;font-weight:800;color:var(--g);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px;}
.ef .ev{font-size:11px;color:#1f2937;word-break:break-word;white-space:pre-wrap;line-height:1.4;}
.ef.regex-f{border-left-color:#16a34a!important;background:#f0fdf4!important;}
.ef.ai-f{border-left-color:var(--gl)!important;background:#f3e8ff!important;}

.cov-bar{height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;}
.cov-fill{height:100%;border-radius:3px;transition:width .6s;background:linear-gradient(90deg,var(--g),var(--gl));}

.badge{display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:700;padding:2px 8px;border-radius:8px;border:1px solid;}
.b-pdf{background:#fef2f2;color:#dc2626;border-color:#fecaca;}
.b-docx{background:#eff6ff;color:#2563eb;border-color:#bfdbfe;}
.b-regex{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;}
.b-ai{background:#f3e8ff;color:var(--g);border-color:#e9d5ff;}
.b-ai-ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0;}
.b-ai-warn{background:#fef3c7;color:#b45309;border-color:#fcd34d;}
.b-ai-off{background:#f3f4f6;color:#6b7280;border-color:#d1d5db;}
.b-merge{background:#fff7ed;color:#c2410c;border-color:#fed7aa;}

.tab-btn{padding:4px 12px;border-radius:5px;font-size:10px;font-weight:700;cursor:pointer;border:1.5px solid #e5e7eb;background:#fff;color:#6b7280;transition:all .2s;}
.tab-btn.active{background:var(--g);color:#fff;border-color:var(--g);}

.log-line{font-size:10px;padding:1.5px 0;font-family:monospace;}
.log-ok{color:#4ade80;}.log-warn{color:#fbbf24;}.log-err{color:#f87171;}.log-info{color:#93c5fd;}

.skill-radio-lbl:has(input:checked){border-color:var(--gl)!important;background:#f3e8ff!important;color:var(--g)!important;font-weight:700;}
.skills-block .sc{margin-bottom:0;}

.mono-area{width:100%;padding:8px 10px;border:1.5px solid #374151;border-radius:7px;font-size:10px;font-family:monospace;resize:vertical;background:#0f172a;color:#e2e8f0;}
.api-inp{width:100%;padding:6px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:10px;font-family:monospace;background:#fff;}
</style>
<link rel="stylesheet" href="assets/css/step7.css">
</head>
<body class="bg-gray-100 min-h-screen text-gray-800">

<!-- Server config via data attribute — keys NEVER reach the browser -->
<div id="gsd-server-config" class="hidden"
  data-ai-status="<?php echo htmlspecialchars($_aiStatus, ENT_QUOTES); ?>">
</div>

<!-- ══════════════════ LANDING SCREEN (reemplaza modal) ══════════════════ -->
<div id="landing-screen" style="position:fixed;inset:0;z-index:300;background:linear-gradient(135deg,#2d1b4e 0%,#1a0f2e 50%,#0f0a1e 100%);display:flex;align-items:center;justify-content:center;overflow-y:auto;padding:1.25rem;">
  <!-- Fondo decorativo -->
  <div style="position:absolute;inset:0;overflow:hidden;pointer-events:none;">
    <div style="position:absolute;width:600px;height:600px;border-radius:50%;background:radial-gradient(circle,rgba(140,82,255,.18) 0%,transparent 70%);top:-100px;right:-100px;"></div>
    <div style="position:absolute;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(90,57,136,.25) 0%,transparent 70%);bottom:-80px;left:-80px;"></div>
  </div>

  <div style="position:relative;width:100%;max-width:460px;padding:2rem;text-align:center;">
    <!-- Logo -->
    <div style="width:64px;height:64px;background:linear-gradient(135deg,#8C52FF,#5A3988);border-radius:18px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1.4rem;color:#fff;margin:0 auto 1.5rem;box-shadow:0 8px 32px rgba(140,82,255,.4);">GSD</div>

    <h1 style="color:#fff;font-size:1.6rem;font-weight:800;margin:0 0 .4rem;letter-spacing:-.02em;">GSD Associates</h1>
    <p style="color:rgba(255,255,255,.5);font-size:.8rem;text-transform:uppercase;letter-spacing:.12em;margin:0 0 2.5rem;font-weight:600;">Smart Recruitment Platform</p>

    <!-- Card -->
    <div style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:2rem;backdrop-filter:blur(20px);">

      <button id="btn-new" onclick="GSD.doNewSession()"
        style="width:100%;background:linear-gradient(135deg,#8C52FF,#5A3988);color:#fff;font-weight:800;padding:1rem;border-radius:12px;border:none;font-size:.9rem;cursor:pointer;letter-spacing:.02em;box-shadow:0 4px 20px rgba(140,82,255,.4);transition:all .2s;margin-bottom:1.25rem;"
        onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 28px rgba(140,82,255,.5)'"
        onmouseout="this.style.transform='';this.style.boxShadow='0 4px 20px rgba(140,82,255,.4)'">
        <span id="btn-new-lbl">✨ Start New Application</span>
      </button>

      <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;">
        <div style="flex:1;height:1px;background:rgba(255,255,255,.12);"></div>
        <span style="color:rgba(255,255,255,.35);font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;">or resume</span>
        <div style="flex:1;height:1px;background:rgba(255,255,255,.12);"></div>
      </div>

      <input type="text" id="ri-token"
        style="width:100%;padding:.75rem 1rem;background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.15);border-radius:10px;color:#fff;font-family:monospace;font-size:.85rem;text-align:center;margin-bottom:.75rem;outline:none;box-sizing:border-box;"
        placeholder="GSD-XXXX-XXXXXX"
        onfocus="this.style.borderColor='rgba(140,82,255,.8)'"
        onblur="this.style.borderColor='rgba(255,255,255,.15)'">

      <button onclick="GSD.doResume()"
        style="width:100%;background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.2);color:rgba(255,255,255,.8);font-weight:700;padding:.85rem;border-radius:10px;font-size:.85rem;cursor:pointer;transition:all .2s;"
        onmouseover="this.style.background='rgba(255,255,255,.15)'"
        onmouseout="this.style.background='rgba(255,255,255,.08)'">
        🔄 Resume Application
      </button>
    </div>
  </div>
</div>

<!-- ══════════════════ NAVBAR ══════════════════ -->
<nav class="bg-white border-b border-gray-100 px-4 py-3 sticky top-0 z-50 shadow-sm">
  <div class="max-w-[1600px] mx-auto flex justify-between items-center">
    <div class="flex items-center gap-2">
      <div class="w-8 h-8 bg-purple-800 rounded-lg flex items-center justify-center text-white font-black text-xs">GSD</div>
      <h1 class="text-sm font-bold text-purple-800 uppercase tracking-tight">Smart Recruitment</h1>
    </div>
    <div class="flex items-center gap-3">
      <div id="token-pill" class="hidden items-center gap-1.5 bg-purple-50 border border-purple-200 text-purple-700 text-xs font-bold px-3 py-1.5 rounded-full">
        🔖 <span id="tok-disp">—</span>
      </div>
      <button id="btn-toggle-ext" onclick="GSD.toggleExtPanel()" class="hidden items-center gap-1.5 bg-slate-800 text-white text-xs font-bold px-3 py-1.5 rounded-full hover:bg-slate-900 transition-all">
        🔬 <span id="ext-toggle-lbl">Show Extractor</span>
      </button>
    </div>
  </div>
</nav>

<!-- ══════════════════ MAIN LAYOUT ══════════════════ -->
<main class="max-w-[1600px] mx-auto px-4 py-5 pb-24">
  <div class="flex flex-col xl:flex-row gap-5">

    <!-- ══════════ LEFT: FORM COLUMN ══════════ -->
    <div class="flex-1 min-w-0">

      <!-- GATE: Upload -->
      <section id="gate" class="hidden">
        <div class="sc border-t-4 border-purple-800">
          <h2 class="text-xl font-bold text-gray-800 mb-1 text-center">Ready to join GSD?</h2>
          <p class="text-xs text-center text-gray-400 mb-6 uppercase font-bold tracking-widest">Step 1: Upload your Profile</p>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 my-6">
            <div class="dz" id="dz-cv">
              <input type="file" id="inp-cv" accept=".pdf,.docx" onchange="GSD.pickFile(this,'cv')">
              <div class="text-3xl mb-1">📄</div>
              <p class="font-bold text-xs uppercase">Resume/CV *</p>
              <p id="cv-nm" class="text-[10px] text-green-600 font-bold hidden mt-2"></p>
            </div>
            <div class="dz" id="dz-id">
              <input type="file" id="inp-id" accept="image/*,.pdf" onchange="GSD.pickFile(this,'id')">
              <div class="text-3xl mb-1">🆔</div>
              <p class="font-bold text-xs uppercase">ID/Passport</p>
              <p id="id-nm" class="text-[10px] text-green-600 font-bold hidden mt-2"></p>
            </div>
            <div class="dz" id="dz-ph">
              <input type="file" id="inp-ph" accept="image/*" onchange="GSD.pickFile(this,'photo')">
              <div class="text-3xl mb-1">📸</div>
              <p class="font-bold text-xs uppercase">Photo</p>
              <p id="photo-nm" class="text-[10px] text-green-600 font-bold hidden mt-2"></p>
            </div>
          </div>

          <div id="ext-pipeline" class="hidden bg-slate-50 rounded-xl p-4 border border-gray-100 mb-4">
            <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
              <div id="xd1" class="xd-item"><span class="w-4 h-4 rounded-full bg-gray-200 flex items-center justify-center text-[8px]">1</span> Reading</div>
              <div id="xd2" class="xd-item"><span class="w-4 h-4 rounded-full bg-gray-200 flex items-center justify-center text-[8px]">2</span> Parsing</div>
              <div id="xd3" class="xd-item"><span class="w-4 h-4 rounded-full bg-gray-200 flex items-center justify-center text-[8px]">3</span> AI Logic</div>
              <div id="xd4" class="xd-item"><span class="w-4 h-4 rounded-full bg-gray-200 flex items-center justify-center text-[8px]">4</span> Syncing</div>
            </div>
          </div>

          <button id="btn-analyze" onclick="GSD.runExtraction()" disabled
            class="w-full bg-gray-300 text-gray-500 font-bold py-4 rounded-xl cursor-not-allowed transition-all uppercase text-xs tracking-widest shadow-xl">
            🔍 Analyze CV &amp; Unlock Form
          </button>
        </div>
      </section>

      <!-- FORM STEPS -->
      <div id="form-wrap" class="hidden space-y-4">
        <!-- Progress -->
        <div class="sc p-4">
          <div class="flex justify-between items-center mb-3">
            <span id="prog-lbl" class="text-[10px] font-black text-purple-700 uppercase">Step 1 / 8</span>
            <div class="h-1.5 w-48 bg-gray-100 rounded-full overflow-hidden">
              <div id="pbar" class="h-full bg-purple-600 transition-all" style="width:12.5%"></div>
            </div>
          </div>
          <div class="flex justify-between">
            <div class="dot active" id="d0">1</div><div class="dot pending" id="d1">2</div>
            <div class="dot pending" id="d2">3</div><div class="dot pending" id="d3">4</div>
            <div class="dot pending" id="d4">5</div><div class="dot pending" id="d5">6</div>
            <div class="dot pending" id="d6">7</div><div class="dot pending" id="d7">8</div>
          </div>
        </div>

        <!-- STEP 0: Personal Details -->
        <div class="step-pane active" id="s0">
          <div class="sc">
            <h3 class="sh uppercase text-xs tracking-widest font-black text-purple-800">👤 Personal Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="md:col-span-2"><label class="lbl req">Full Name</label><input type="text" id="f-name" class="inp" placeholder="Full legal name"></div>
              <div><label class="lbl req">Email Address</label><input type="email" id="f-email" class="inp" placeholder="john@email.com"></div>
              <div><label class="lbl req">LinkedIn Profile</label><input type="url" id="f-linkedin" class="inp" placeholder="linkedin.com/in/username"></div>
              <div><label class="lbl req">Phone Number</label>
                <div class="grid grid-cols-[120px_1fr] gap-2">
                  <select id="f-phone-code" class="inp text-[11px] font-mono w-full"></select>
                  <input type="tel" id="f-phone" class="inp w-full" placeholder="300 123 4567">
                </div>
              </div>
              <div><label class="lbl req">WhatsApp Number</label>
                <div class="grid grid-cols-[120px_1fr] gap-2">
                  <select id="f-whatsapp-code" class="inp text-[11px] font-mono w-full"></select>
                  <input type="tel" id="f-whatsapp" class="inp w-full" placeholder="300 123 4567">
                </div>
              </div>
              <div><label class="lbl req">Country</label><select id="f-country" class="inp" onchange="GSD.loadCities(this.value)"></select></div>
              <div><label class="lbl req">City</label><select id="f-city" class="inp"></select></div>
              <div class="md:col-span-2">
                  <label class="lbl req" id="lbl-address">Full Address (Street, Apt, etc.)</label>
                  <input type="text" id="f-address" class="inp" placeholder="Calle 123 #45-67, Edificio X">
                </div>
                <div>
                  <label class="lbl" id="lbl-postal">Postal Code</label>
                  <input type="text" id="f-postal" class="inp" placeholder="110111">
                </div>
              <div><label class="lbl req">Salary Expectation (USD)</label><input type="number" id="f-salary" class="inp"></div>
              <div><label class="lbl req">Availability / Notice Period</label><input type="text" id="f-avail" class="inp" placeholder="Immediately / 2 weeks"></div>
            </div>
          </div>
          <div class="sc">
            <h3 class="sh uppercase text-xs tracking-widest font-black text-purple-800">📝 Professional Snapshot</h3>
            <label class="lbl req">Summary</label><textarea id="f-sum" rows="4" class="inp mb-4"></textarea>
            <label class="lbl req">Core Skills</label><input type="text" id="f-skills" class="inp" placeholder="Python, Project Management, CRM...">
          </div>
        </div>

        <!-- STEP 1: Education -->
        <div class="step-pane" id="s1">
          <div class="sc">
            <h3 class="sh uppercase text-xs tracking-widest font-black text-purple-800">🎓 Education</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="md:col-span-2"><label class="lbl req">Education Level</label><select id="f-edu-level" class="inp">
                <option value="">— Select —</option>
                <option>Less than high school (Secondary school)</option>
                <option>High school diploma / Secondary school certificate</option>
                <option>Associate degree or equivalent</option>
                <option>Bachelor's degree</option>
                <option>Master's degree</option>
                <option>Doctorate (PhD) or equivalent</option>
              </select></div>
              <div><label class="lbl">Main Degree</label><input type="text" id="f-deg1" class="inp" placeholder="B.Sc. Computer Science"></div>
              <div><label class="lbl">Institution</label><input type="text" id="f-ins1" class="inp" placeholder="University Name"></div>
              <div><label class="lbl">Years</label><input type="text" id="f-yr1" class="inp" placeholder="2018 – 2022"></div>
              <div><label class="lbl">Healthcare Education?</label><select id="f-edu-hc" class="inp"><option>No</option><option>Yes</option></select></div>
              <div><label class="lbl">Other Degree</label><input type="text" id="f-deg2" class="inp" placeholder="Certification / Diploma"></div>
              <div><label class="lbl">Other Institution</label><input type="text" id="f-ins2" class="inp"></div>
              <div><label class="lbl">Languages</label><input type="text" id="f-lang" class="inp" placeholder="English C1, Spanish Native"></div>
              <div><label class="lbl">Certifications</label><input type="text" id="f-certs" class="inp" placeholder="AWS, PMP, HubSpot…"></div>
            </div>
          </div>
        </div>

        <!-- STEP 2: Experience -->
        <div class="step-pane" id="s2">
          <div class="sc">
            <h3 class="sh uppercase text-xs tracking-widest font-black text-purple-800">💼 Work Experience</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div><label class="lbl req">Years of Experience</label><select id="f-exp-yrs" class="inp">
                <option value="">— Select —</option>
                <option>Less than 1 year</option><option>1 to 2 years</option>
                <option>3 to 4 years</option><option>5+ years</option>
                <option>Senior Level (7+ years)</option>
              </select></div>
              <div><label class="lbl">Worked in Healthcare?</label><select id="f-worked-hc" class="inp"><option>No</option><option>Yes</option></select></div>
              <div><label class="lbl">Worked as VA?</label><select id="f-worked-va" class="inp"><option>No</option><option>Yes</option></select></div>
              <div><label class="lbl">Suggested GSD Role</label><select id="f-role" class="inp">
                <option value="">— Select —</option>
                <option>VPA (Virtual Personal Assistant)</option>
                <option>HVA (Healthcare Virtual Assistant)</option>
                <option>HOP (Healthcare Operations)</option>
                <option>MVA (Marketing Virtual Assistant)</option>
                <option>MGR (Marketing Manager)</option>
                <option>ACM (Account Manager)</option>
                <option>SDR (Sales/SDR)</option>
                <option>HRO (HR Operations)</option>
              </select></div>
              <div><label class="lbl req">Main Company</label><input type="text" id="f-co1" class="inp" placeholder="Company Name"></div>
              <div><label class="lbl req">Job Title</label><input type="text" id="f-jt1" class="inp" placeholder="Senior Developer"></div>
              <div class="md:col-span-2"><label class="lbl">Responsibilities</label><textarea id="f-resp1" rows="4" class="inp"></textarea></div>
              <div><label class="lbl">Other Company</label><input type="text" id="f-co2" class="inp"></div>
              <div><label class="lbl">Other Job Title</label><input type="text" id="f-jt2" class="inp"></div>
              <div class="md:col-span-2"><label class="lbl">Other Responsibilities</label><textarea id="f-resp2" rows="3" class="inp"></textarea></div>
            </div>
          </div>
        </div>

        <!-- STEP 3: Referral + Role -->
        <div class="step-pane" id="s3">
          <div class="sc">
            <h3 class="sh uppercase text-xs tracking-widest font-black text-purple-800">📋 Application Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div><label class="lbl req">Who referred you?</label>
                <input type="text" id="f-referral" class="inp" placeholder="Name or N.A if not referred"></div>
              <div><label class="lbl req">Position applying for</label>
                <select id="f-position" class="inp" onchange="GSD.onRoleChange(this.value)">
                  <option value="">— Select position —</option>
                  <option value="VPA">Virtual Personal Assistant</option>
                  <option value="HVA">Healthcare Virtual Assistant</option>
                  <option value="HOP">Healthcare Operations</option>
                  <option value="MVA">Marketing Virtual Assistant</option>
                  <option value="MGR">Marketing Manager</option>
                  <option value="ACM">Account Manager</option>
                  <option value="SDR">Sales / SDR</option>
                  <option value="HRO">HR / Talent Operations</option>
                </select>
              </div>
            </div>
          </div>
          <div class="sc">
            <h3 class="sh uppercase text-xs tracking-widest font-black text-purple-800">⚕️ Background Screening</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div><label class="lbl req">Healthcare education?</label>
                <div class="flex gap-3 mt-1">
                  <label class="flex items-center gap-1.5 text-sm cursor-pointer"><input type="radio" name="r-edu-hc" value="Yes" class="accent-purple-700"> Yes</label>
                  <label class="flex items-center gap-1.5 text-sm cursor-pointer"><input type="radio" name="r-edu-hc" value="No" class="accent-purple-700" checked> No</label>
                </div>
              </div>
              <div><label class="lbl req">Worked in healthcare?</label>
                <div class="flex gap-3 mt-1">
                  <label class="flex items-center gap-1.5 text-sm cursor-pointer"><input type="radio" name="r-work-hc" value="Yes" class="accent-purple-700"> Yes</label>
                  <label class="flex items-center gap-1.5 text-sm cursor-pointer"><input type="radio" name="r-work-hc" value="No" class="accent-purple-700" checked> No</label>
                </div>
              </div>
              <div><label class="lbl req">Worked as VA?</label>
                <div class="flex gap-3 mt-1">
                  <label class="flex items-center gap-1.5 text-sm cursor-pointer"><input type="radio" name="r-va" value="Yes" class="accent-purple-700"> Yes</label>
                  <label class="flex items-center gap-1.5 text-sm cursor-pointer"><input type="radio" name="r-va" value="No" class="accent-purple-700" checked> No</label>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- STEP 4: Role Skills Assessment -->
        <div class="step-pane" id="s4">
          <div class="sc">
            <h3 class="sh uppercase text-xs tracking-widest font-black text-purple-800">🎯 Skills Assessment</h3>
            <p class="text-[10px] text-gray-400 mb-4 uppercase font-bold">Rate each skill: Not Familiar / Beginner / Intermediate / Expert</p>
            <div id="skills-VPA" class="skills-block hidden space-y-4">
              <div class="bg-purple-50 rounded-lg p-3 mb-2"><p class="text-xs font-black text-purple-800">Virtual Personal Assistant Skills</p></div>
              <div id="sq-vpa-1"></div><div id="sq-vpa-2"></div><div id="sq-vpa-3"></div>
              <div id="sq-vpa-4"></div><div id="sq-vpa-5"></div><div id="sq-vpa-6"></div>
            </div>
            <div id="skills-HVA" class="skills-block hidden space-y-4">
              <div class="bg-purple-50 rounded-lg p-3 mb-2"><p class="text-xs font-black text-purple-800">Healthcare Virtual Assistant Skills</p></div>
              <div id="sq-hva-1"></div><div id="sq-hva-2"></div><div id="sq-hva-3"></div>
              <div id="sq-hva-4"></div><div id="sq-hva-5"></div><div id="sq-hva-6"></div>
            </div>
            <div id="skills-HOP" class="skills-block hidden space-y-4">
              <div class="bg-purple-50 rounded-lg p-3 mb-2"><p class="text-xs font-black text-purple-800">Healthcare Operations Skills</p></div>
              <div id="sq-hop-1"></div><div id="sq-hop-2"></div><div id="sq-hop-3"></div>
              <div id="sq-hop-4"></div><div id="sq-hop-5"></div>
            </div>
            <div id="skills-MVA" class="skills-block hidden space-y-4">
              <div class="bg-purple-50 rounded-lg p-3 mb-2"><p class="text-xs font-black text-purple-800">Marketing Virtual Assistant Skills</p></div>
              <div id="sq-mva-1"></div><div id="sq-mva-2"></div><div id="sq-mva-3"></div>
              <div id="sq-mva-4"></div><div id="sq-mva-5"></div>
            </div>
            <div id="skills-HRO" class="skills-block hidden space-y-4">
              <div class="bg-purple-50 rounded-lg p-3 mb-2"><p class="text-xs font-black text-purple-800">HR Operations Skills</p></div>
              <div id="sq-hro-1"></div><div id="sq-hro-2"></div><div id="sq-hro-3"></div>
              <div id="sq-hro-4"></div><div id="sq-hro-5"></div>
            </div>
            <div id="skills-MGR" class="skills-block hidden space-y-4">
              <div class="bg-purple-50 rounded-lg p-3 mb-2"><p class="text-xs font-black text-purple-800">Marketing Manager Skills</p></div>
              <div id="sq-mgr-1"></div><div id="sq-mgr-2"></div><div id="sq-mgr-3"></div>
              <div id="sq-mgr-4"></div><div id="sq-mgr-5"></div>
            </div>
            <div id="skills-ACM" class="skills-block hidden space-y-4">
              <div class="bg-purple-50 rounded-lg p-3 mb-2"><p class="text-xs font-black text-purple-800">Account Manager Skills</p></div>
              <div id="sq-acm-1"></div><div id="sq-acm-2"></div><div id="sq-acm-3"></div>
              <div id="sq-acm-4"></div><div id="sq-acm-5"></div>
            </div>
            <div id="skills-SDR" class="skills-block hidden space-y-4">
              <div class="bg-purple-50 rounded-lg p-3 mb-2"><p class="text-xs font-black text-purple-800">Sales / SDR Skills</p></div>
              <div id="sq-sdr-1"></div><div id="sq-sdr-2"></div><div id="sq-sdr-3"></div>
              <div id="sq-sdr-4"></div><div id="sq-sdr-5"></div>
            </div>
            <div id="skills-NONE" class="text-center py-8 text-gray-400 text-sm">
              ⬅ Please select your position in Step 4 to see your skills assessment
            </div>
          </div>
        </div>

        <!-- STEP 5: Personality -->
        <div class="step-pane" id="s5">
          <div class="sc">
            <h3 class="sh uppercase text-xs tracking-widest font-black text-purple-800">🧠 Personality Assessment</h3>
            <p class="text-[10px] text-gray-400 mb-4">Respond honestly — choose the option that best represents you without overthinking.</p>
            <p class="text-[10px] text-gray-400 mb-4 uppercase font-bold">Scale: No Experience / Basic knowledge / Proficient / Advanced</p>
            <div class="space-y-4" id="personality-questions"></div>
          </div>
        </div>

        <!-- STEP 6: English Test -->
        <div class="step-pane" id="s6">
          <div class="sc">
            <h3 class="sh uppercase text-xs tracking-widest font-black text-purple-800">🇬🇧 English Level Assessment</h3>
            <p class="text-xs text-gray-500 mb-5">Complete the 15-minute screening test, then enter your scores below.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label class="lbl req">Reading Score</label>
                <div class="space-y-2 mt-1" id="rg-reading"></div>
              </div>
              <div>
                <label class="lbl req">Listening Score</label>
                <div class="space-y-2 mt-1" id="rg-listening"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- STEP 7: Video Submission -->
        <div class="step-pane" id="s7">
          <div class="sc" style="background:#0b0f1a;border-color:rgba(140,82,255,.2);padding:20px;">
            <h3 class="sh" style="color:#c4b5fd;border-color:rgba(140,82,255,.15);font-size:10px;text-transform:uppercase;letter-spacing:.15em;font-weight:900;">
              🎥 Virtual AI Interview
            </h3>

            <!-- Video container -->
            <div class="gsd-video-wrap" id="vid-wrap">
              <canvas id="output_canvas" style="width:100%;height:100%;object-fit:cover;display:block;"></canvas>

              <video id="input_video" style="display:none;" autoplay playsinline muted></video>

              <video id="review_video" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;background:#000;display:none;z-index:20;" controls></video>

              <div id="vid-idle-overlay" style="
                position:absolute;inset:0;display:flex;flex-direction:column;
                align-items:center;justify-content:center;z-index:10;
                background:linear-gradient(135deg,#0b0f1a 0%,#1a0f2e 100%);
              ">
                <div style="width:72px;height:72px;background:rgba(140,82,255,.15);border-radius:20px;display:flex;align-items:center;justify-content:center;font-size:2rem;margin-bottom:1rem;border:1px solid rgba(140,82,255,.3);">🎥</div>
                <p style="color:rgba(255,255,255,.5);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;text-align:center;">Click "Activate Camera" to begin</p>
              </div>

              <div class="rec-badge" id="rec-badge">
                <div class="rec-dot"></div>
                REC <span id="rec-timer">01:30</span>
              </div>

              <div class="progress-ring" id="progress-ring" style="display:none;">
                <svg width="48" height="48" viewBox="0 0 48 48">
                  <circle cx="24" cy="24" r="20" fill="none" stroke="rgba(255,255,255,.1)" stroke-width="3"/>
                  <circle id="ring-fill" cx="24" cy="24" r="20" fill="none" stroke="#8C52FF" stroke-width="3"
                    stroke-dasharray="125.66" stroke-dashoffset="0" stroke-linecap="round"
                    transform="rotate(-90 24 24)" style="transition:stroke-dashoffset .9s linear;"/>
                </svg>
              </div>

              <div class="vid-analysis-bar" id="analysis-bar" style="display:none;">
                <div class="row1">
                  <span class="vid-bar-label">AI Sentiment Analysis</span>
                  <span class="vid-score" id="sentiment-score">--</span>
                </div>
                <div class="vid-transcript" id="transcript-box">Waiting for audio...</div>
                <div style="display:flex;gap:4px;align-items:center;margin-top:3px;">
                  <span style="font-size:8px;color:rgba(255,255,255,.3);text-transform:uppercase;font-weight:700;margin-right:2px;">Face</span>
                  <div class="em-dot" id="em-happy"   title="Happy"></div>
                  <div class="em-dot" id="em-neutral"  title="Neutral"></div>
                  <div class="em-dot" id="em-surprised"title="Surprised"></div>
                  <div class="em-dot" id="em-sad"      title="Sad"></div>
                  <div class="em-dot" id="em-angry"    title="Angry"></div>
                  <span style="font-size:8px;color:rgba(255,255,255,.3);margin-left:4px;" id="face-label"></span>
                </div>
              </div>
            </div>

            <div class="upload-progress-bar" id="upload-progress-bar">
              <div class="upload-progress-fill" id="upload-progress-fill" style="width:0%"></div>
            </div>

            <div class="vid-controls">

              <div id="ctrl-activate">
                <button class="vid-btn vid-btn-primary" id="btn-activate-cam" onclick="GSD.initCamera()">
                  <span>⚡</span> Activate Camera & AI
                </button>
              </div>

              <div id="ctrl-record" style="display:none;">
                <div style="display:flex;gap:8px;">
                  <button class="vid-btn vid-btn-danger" id="btn-start-rec" onclick="GSD.startRecording()" style="flex:1;">
                    <span class="rec-dot" style="background:#fff;width:8px;height:8px;border-radius:50%;display:inline-block;"></span>
                    Start Recording
                  </button>
                </div>
              </div>

              <div id="ctrl-stop" style="display:none;">
                <button class="vid-btn vid-btn-stop" onclick="GSD.stopRecording()">
                  ⬛ Stop Recording
                </button>
              </div>

              <div id="ctrl-review" style="display:none;">
                <div style="
                  background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);
                  border-radius:10px;padding:10px 14px;margin-bottom:10px;
                  font-size:11px;color:rgba(255,255,255,.7);text-align:center;
                ">
                  ✅ Recording complete! Review your video above.
                </div>
                <div style="display:flex;gap:8px;">
                  <button class="vid-btn vid-btn-ghost" onclick="GSD.retryRecording()" style="flex:1;">
                    🔄 Retry
                  </button>
                  <button class="vid-btn vid-btn-success" id="btn-final-submit" onclick="GSD.submitFullApplication()" style="flex:2;">
                    <span>🚀</span> Submit Application
                  </button>
                </div>
              </div>

            </div>

            <div id="upload-status" style="display:none;margin-top:10px;text-align:center;">
              <div style="font-size:11px;color:rgba(255,255,255,.6);font-weight:700;" id="upload-status-text">Uploading...</div>
              <div style="height:4px;background:rgba(255,255,255,.08);border-radius:2px;margin-top:8px;overflow:hidden;">
                <div id="upload-bar-fill" style="height:100%;background:linear-gradient(90deg,#5A3988,#8C52FF);border-radius:2px;transition:width .3s;width:0%;"></div>
              </div>
            </div>

            <div class="tips-card" id="video-tips" style="margin-top:14px;">
              <p style="color:#a78bfa;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin:0 0 2px;">💡 Interview Tips</p>
              <ul>
                <li>Make sure you're in a <strong style="color:rgba(255,255,255,.8);">well-lit space</strong> — face the light source</li>
                <li>Speak <strong style="color:rgba(255,255,255,.8);">clearly and at normal pace</strong> — AI analyzes your speech in real time</li>
                <li>You have <strong style="color:rgba(255,255,255,.8);">45 seconds</strong> — introduce yourself, mention your key experience, and why you want to join GSD</li>
                <li>The system <strong style="color:rgba(255,255,255,.8);">replaces your background</strong> automatically with a professional GSD backdrop</li>
                <li>Maintain <strong style="color:rgba(255,255,255,.8);">eye contact with the camera</strong> — facial expression analysis is running</li>
              </ul>
            </div>

          </div>
        </div>

        <!-- Nav buttons -->
        <div class="flex gap-3 pt-4">
          <button id="btn-back" onclick="GSD.prevStep()" class="hidden bg-gray-100 py-3 px-8 rounded-xl font-black text-xs uppercase hover:bg-gray-200 transition-all">← Back</button>
          <button id="btn-next" onclick="GSD.nextStep()" class="flex-1 bg-purple-700 text-white py-4 rounded-xl font-black text-xs uppercase shadow-xl hover:bg-purple-800 transition-all">Continue Application →</button>
        </div>
      </div>
    </div><!-- /left column -->

    <!-- ══ RIGHT: CV EXTRACTOR PANEL ══ -->
    <aside id="side-extractor" class="hidden w-full xl:w-[480px] shrink-0 space-y-3 xl:sticky xl:top-20 xl:h-fit xl:max-h-[calc(100vh-6rem)] xl:overflow-y-auto">

      <div class="ext-card border-t-4 border-slate-800">
        <div class="ext-sh">
          <span>⚙️ AI Providers — Server Config</span>
          <span class="text-[8px] text-green-500 font-bold bg-green-50 px-2 py-0.5 rounded-full border border-green-200">🔒 Via .env</span>
        </div>
        <div id="sec-api" class="space-y-2">
          <div class="grid grid-cols-3 gap-2" id="ai-provider-status"></div>
          <p class="text-[9px] text-gray-400 mt-1">
            🔐 API keys stored server-side in <code class="bg-gray-100 px-1 rounded">.env</code>.
            Calls route through <code class="bg-gray-100 px-1 rounded">api-proxy4.php</code>.
          </p>
          <button onclick="GSD.pingProxy()" class="mt-2 w-full text-[9px] font-bold bg-slate-800 hover:bg-slate-700 text-white py-1.5 rounded-lg transition-all">
            🔌 Test Proxy Connection
          </button>
          <div id="ping-result" class="hidden mt-1 text-[9px] font-mono bg-slate-900 text-green-400 rounded p-2 leading-relaxed max-h-28 overflow-y-auto"></div>
          <div class="flex items-center gap-2 mt-1">
            <span class="text-[9px] text-gray-500 font-bold uppercase">Order:</span>
            <span id="ai-order-disp" class="text-[9px] font-mono text-purple-700 bg-purple-50 px-2 py-0.5 rounded"></span>
          </div>
        </div>
      </div>

      <div class="ext-card border-t-4 border-purple-700">
        <div class="ext-sh">
          <span>📊 GSD Form Coverage</span>
          <span id="side-provider-badge" class="badge b-ai">⏳ Waiting</span>
        </div>
        <div class="flex items-end gap-3 mb-2">
          <span class="text-4xl font-black text-purple-800" id="cov-pct">0%</span>
          <span class="text-xs text-gray-400 mb-1" id="cov-label">0 / 30 fields</span>
        </div>
        <div class="cov-bar"><div class="cov-fill" id="cov-fill" style="width:0%"></div></div>
      </div>

      <div class="ext-card">
        <div class="flex gap-1.5 mb-3">
          <button class="tab-btn active" id="tab-merged" onclick="GSD.switchExtTab('merged')">Fields</button>
          <button class="tab-btn" id="tab-raw" onclick="GSD.switchExtTab('raw')">Raw Text</button>
          <button class="tab-btn" id="tab-json" onclick="GSD.switchExtTab('json')">JSON</button>
          <button id="btn-copy-json" onclick="GSD.copyExtJSON()" class="hidden ml-auto tab-btn bg-green-600 text-white border-green-600 hover:bg-green-700">📋 Copy</button>
        </div>
        <div id="view-merged">
          <div class="ext-sh mt-0">
            <span>Step 2 — Regex <span class="badge b-regex ml-1">🔍 Offline</span></span>
            <span id="rx-count" class="text-[9px] text-gray-400">—</span>
          </div>
          <div id="regex-out"><p class="text-[10px] text-gray-400 italic text-center py-2">Upload a CV to begin.</p></div>
          <div class="ext-sh mt-3">
            <span>Step 3 — AI Extraction</span>
            <span id="ai-count" class="text-[9px] text-gray-400">—</span>
          </div>
          <div id="ai-out"><p class="text-[10px] text-gray-400 italic text-center py-2">Add an API key above.</p></div>
          <div class="ext-sh mt-3">
            <span>Step 4 — Final Merged <span class="badge b-merge ml-1">🧩</span></span>
          </div>
          <div id="merged-out"><p class="text-[10px] text-gray-400 italic text-center py-2">Run extraction to see merged data.</p></div>
        </div>
        <div id="view-raw" class="hidden">
          <div class="ext-sh">
            <span id="raw-badge" class="badge b-pdf">...</span>
            <span id="raw-meta" class="text-[9px] text-gray-400"></span>
          </div>
          <textarea class="mono-area" id="raw-txt" rows="18" readonly placeholder="Raw extracted text will appear here..."></textarea>
          <div class="flex gap-4 mt-1 text-[9px] text-gray-400">
            <span id="raw-chars"></span><span id="raw-lines"></span>
          </div>
        </div>
        <div id="view-json" class="hidden">
          <textarea class="mono-area" id="json-out" rows="18" readonly></textarea>
        </div>
      </div>

      <div class="ext-card" style="background:#0f172a;">
        <div class="ext-sh" style="border-color:#1e293b;">
          <span style="color:#a78bfa;">🔧 Pipeline Log</span>
          <button onclick="GSD.clearLog()" class="text-[9px] text-slate-500 hover:text-slate-300">Clear</button>
        </div>
        <div id="log-box" class="space-y-0 max-h-40 overflow-y-auto"></div>
      </div>

    </aside>
  </div>
</main>

<script>
(function() {
'use strict';

let AI_STATUS = (function() {
  try {
    const el = document.getElementById('gsd-server-config');
    return el ? JSON.parse(el.dataset.aiStatus) : {claude:false,gemini:false,openai:false,groq:false,openrouter:false,order:'gemini,claude,openai',env_loaded:false,env_path:''};
  } catch(e) {
    return {claude:false,gemini:false,openai:false,groq:false,openrouter:false,order:'gemini,claude,openai',env_loaded:false,env_path:''};
  }
})();
let AI_HEALTH = null;
const AI_PROVIDERS = [
  {key:'claude',label:'Claude',icon:'🟣'},
  {key:'gemini',label:'Gemini',icon:'🔵'},
  {key:'openai',label:'OpenAI',icon:'🟢'},
  {key:'groq',label:'Groq',icon:'🟠'},
  {key:'openrouter',label:'OpenRouter',icon:'⚪'},
];

const STEPS = 8;
let curStep = 0, cvText = '', SESSION_TOKEN = '';
let finalMerged = {};

const locationData = {
  "Colombia":      { code: "+57", cities: ["Bogotá","Medellín","Cali","Barranquilla","Bucaramanga","Envigado","Sabaneta","Cartagena"] },
  "United States": { code: "+1",  cities: ["New York","Miami","Los Angeles","Houston","Chicago","Atlanta","Dallas"] },
  "Mexico":        { code: "+52", cities: ["Ciudad de México","Guadalajara","Monterrey","Tijuana"] },
  "Spain":         { code: "+34", cities: ["Madrid","Barcelona","Valencia","Sevilla"] },
  "Argentina":     { code: "+54", cities: ["Buenos Aires","Córdoba","Rosario","Mendoza"] },
  "Chile":         { code: "+56", cities: ["Santiago","Valparaíso","Concepción"] },
};

const FM = {
  'f-name':'name','f-email':'email','f-phone':'phone','f-whatsapp':'phone',
  'f-address':'address','f-linkedin':'linkedin','f-avail':'availability','f-salary':'salary',
  'f-sum':'summary','f-skills':'skills','f-lang':'languages','f-certs':'certifications',
  'f-deg1':'main_degree','f-ins1':'main_institution','f-yr1':'main_years',
  'f-deg2':'other_degree','f-ins2':'other_institution',
  'f-co1':'main_company','f-jt1':'main_title','f-resp1':'main_responsibilities',
  'f-co2':'other_company','f-jt2':'other_title','f-resp2':'other_responsibilities',
};

const SELECT_FM = {
  'f-edu-level':'education_level','f-exp-yrs':'exp_years',
  'f-edu-hc':'edu_healthcare','f-worked-hc':'worked_healthcare',
  'f-worked-va':'worked_va','f-role':'suggested_role',
};

const GSD_FIELDS = [
  'name','email','phone','address','country','city','linkedin','availability','salary','summary',
  'skills','education_level','main_degree','main_institution','main_years',
  'other_degree','exp_years','main_company','main_title','main_responsibilities',
  'other_company','other_title','other_responsibilities',
  'languages','edu_healthcare','worked_healthcare','worked_va',
  'suggested_role','certifications','website','github','dateRanges'
];

const LABELS = {
  name:'Full Name',email:'Email',phone:'Phone',address:'Address/Location',
  country:'Country',city:'City',
  linkedin:'LinkedIn',github:'GitHub',twitter:'Twitter/X',website:'Website',
  email_alt:'Alt Email',availability:'Availability / Notice Period',
  availability_hint:'Availability (regex)',
  salary:'Salary Expectation',salary_mention:'Salary (in CV)',
  summary:'Professional Summary',skills:'Key Skills',education_level:'Education Level',
  main_degree:'Main Degree',main_institution:'Main Institution',main_years:'Main Edu Years',
  other_degree:'Other Degree',other_institution:'Other Institution',other_years:'Other Edu Years',
  exp_years:'Experience Range',exp_years_text:'Exp Years (regex)',dateRanges:'Date Ranges Found',
  main_company:'Main Company',main_title:'Main Job Title',main_responsibilities:'Main Responsibilities',
  other_company:'Other Company',other_title:'Other Job Title',
  other_years_exp:'Other Job Period',other_responsibilities:'Other Responsibilities',
  languages:'Languages',certifications:'Certifications',
  edu_healthcare:'Healthcare Education?',worked_healthcare:'Worked in Healthcare?',
  worked_va:'Worked as VA?',address_hint:'Address Hint',name_guess:'Name Guess',suggested_role:'Suggested GSD Role',
};

const AI_PROMPT = [
  'You are an expert HR data extractor. Read the resume/CV below and extract ALL available information.',
  '',
  'Return ONLY a valid JSON object with EXACTLY these keys. Use "" for missing fields, never omit a key.',
  '',
  '{"name":"","email":"","phone":"","address":"","country":"","city":"","linkedin":"","availability":"","salary":"","summary":"","skills":"",',
  '"education_level":"","main_degree":"","main_institution":"","main_years":"",',
  '"other_degree":"","other_institution":"","other_years":"",',
  '"exp_years":"","main_company":"","main_title":"","main_responsibilities":"",',
  '"other_company":"","other_title":"","other_years_exp":"","other_responsibilities":"",',
  '"languages":"","certifications":"","edu_healthcare":"","worked_healthcare":"","worked_va":"","suggested_role":""}',
  '',
  'RULES:',
  '- name: Full name from top of CV.',
  '- country: EXACTLY one of these when possible: Colombia | United States | Mexico | Spain | Argentina | Chile.',
  '- city: candidate city if clearly present in CV.',
  '- summary: 3-5 sentence professional bio in FIRST PERSON. Generate if not present.',
  '- skills: All technical and soft skills, comma-separated.',
  '- education_level: EXACTLY one of: Less than high school (Secondary school) | High school diploma / Secondary school certificate | Associate degree or equivalent | Bachelor\'s degree | Master\'s degree | Doctorate (PhD) or equivalent',
  '- exp_years: EXACTLY one of: Less than 1 year | 1 to 2 years | 3 to 4 years | 5+ years | Senior Level (7+ years)',
  '- main_responsibilities: Bullet points each starting with a dash (-).',
  '- languages: Format as "English C1, Spanish Native". Use CEFR levels.',
  '- edu_healthcare: "Yes" if education is healthcare/medicine/nursing/pharmacy, else "No".',
  '- worked_healthcare: "Yes" if worked in hospital/clinic/EMR/HIPAA context, else "No".',
  '- worked_va: "Yes" if worked as VA/Executive Assistant/Administrative Assistant, else "No".',
  '- suggested_role: EXACTLY ONE of: VPA | HVA | HOP | MVA | MGR | ACM | SDR | HRO',
  '',
  'Return ONLY the JSON object. No markdown. No explanation.',
  '',
  'RESUME:',
].join('\n');

/* ════ SESSION ════ */
function setToken(t) {
  SESSION_TOKEN = t;
  document.getElementById('tok-disp').textContent = t;
  document.getElementById('token-pill').classList.remove('hidden');
  document.getElementById('token-pill').classList.add('flex');
}

function generateToken() {
  return 'GSD-' + Math.random().toString(36).substr(2,4).toUpperCase() + '-' + Date.now().toString(36).toUpperCase();
}

function normalizedText(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

function pickCandidateName(text) {
  const blocked = /\b(resume|curriculum|cv|profile|summary|experience|education|skills|phone|email|linkedin)\b/i;
  const lines = text.split(/\r?\n/).map(line => line.trim()).filter(Boolean).slice(0, 12);
  for (const line of lines) {
    if (blocked.test(line) || /[@\d]|linkedin|http/i.test(line)) continue;
    const words = line.split(/\s+/).filter(Boolean);
    if (words.length < 2 || words.length > 5) continue;
    if (!words.every(word => /^[A-ZÀ-Ý][a-zà-ÿ'`-]+$/.test(word))) continue;
    return line;
  }
  return '';
}

function inferCountryFromPhone(phone) {
  const clean = normalizedText(phone);
  for (const [country, meta] of Object.entries(locationData)) {
    if (clean.startsWith(meta.code)) return country;
  }
  return '';
}

function inferLocationFromText(text, fallback = '') {
  const source = [fallback, text].filter(Boolean).join('\n');
  const result = { city: '', country: '', address: normalizedText(fallback) };

  for (const [country, meta] of Object.entries(locationData)) {
    if (new RegExp(`\\b${country.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'i').test(source)) {
      result.country = country;
      break;
    }
  }

  for (const [country, meta] of Object.entries(locationData)) {
    const city = meta.cities.find(entry => new RegExp(`\\b${entry.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'i').test(source));
    if (city) {
      result.city = city;
      result.country ||= country;
      break;
    }
  }

  if (!result.address) {
    const locationLine = text.split(/\r?\n/).map(line => line.trim()).find(line => result.city && line.toLowerCase().includes(result.city.toLowerCase()));
    result.address = normalizedText(locationLine || '');
  }

  return result;
}

function inferAvailability(text) {
  const patterns = [
    /\b(immediate(?:ly)?|available immediately)\b/i,
    /\b(available\s+(?:to\s+start\s+)?in\s+\d+\s*(?:day|days|week|weeks|month|months))\b/i,
    /\b(notice\s+period[:\s-]*[^\n.,;]+)/i,
    /\b(two\s+weeks?|three\s+weeks?|one\s+month)\b/i
  ];
  for (const pattern of patterns) {
    const match = text.match(pattern);
    if (match) return normalizedText(match[1] || match[0]);
  }
  return '';
}

function normalizeSalaryValue(value) {
  const raw = normalizedText(value);
  if (!raw) return '';
  const match = raw.match(/(\d[\d,.]*)/);
  return match ? match[1].replace(/,/g, '') : raw;
}

function extractRoleCode(value) {
  const match = normalizedText(value).toUpperCase().match(/\b(VPA|HVA|HOP|MVA|HRO|MGR|ACM|SDR)\b/);
  return match ? match[1] : '';
}

function normalizeExtractedData(data, originalText = '') {
  const out = {};
  Object.entries(data || {}).forEach(([key, value]) => {
    out[key] = typeof value === 'string' ? normalizedText(value) : value;
  });

  if (!out.name && originalText) out.name = pickCandidateName(originalText);
  if (out.salary) out.salary = normalizeSalaryValue(out.salary);
  if (out.salary_mention) out.salary_mention = normalizeSalaryValue(out.salary_mention);

  const location = inferLocationFromText(originalText, out.address || out.address_hint || '');
  out.country ||= location.country || inferCountryFromPhone(out.phone || '');
  out.city ||= location.city;
  out.address ||= location.address;
  out.availability ||= inferAvailability(originalText);
  out.suggested_role = extractRoleCode(out.suggested_role) || out.suggested_role || '';
  out.languages ||= inferLanguagesFromText(originalText);
  out.certifications ||= inferCertificationsFromText(originalText);
  out.website ||= inferWebsiteFromText(originalText);

  const healthcareSignals = /\b(EMR|EHR|HIPAA|medical\s+billing|ICD-?10|CPT\s+code|prior\s+authorization|patient\s+scheduling|clinical|healthcare|health\s+care|physician|hospital|clinic|nursing|dental|pharmacy|insurance\s+claim|RCM|athena|epic|cerner|modmed)\b/i.test(originalText);
  const vaSignals = /\b(virtual\s+assistant|executive\s+assistant|administrative\s+assistant|remote\s+support|calendar\s+management|personal\s+assistant|inbox\s+management|travel\s+coordination)\b/i.test(originalText);
  if (!out.edu_healthcare && out.education_level) out.edu_healthcare = healthcareSignals ? 'Yes' : 'No';
  if (!out.worked_healthcare && (out.main_title || out.main_company || out.skills)) out.worked_healthcare = healthcareSignals ? 'Yes' : 'No';
  if (!out.worked_va && (out.main_title || out.suggested_role)) out.worked_va = vaSignals || out.suggested_role === 'VPA' ? 'Yes' : 'No';

  return out;
}

function cleanLines(text) {
  return String(text || '').split(/\r?\n/).map(line => normalizedText(line)).filter(Boolean);
}

function isHeadingLine(line) {
  return /^(summary|profile|about|objective|skills?|core skills?|competencies|experience|professional experience|work experience|employment|education|academic background|certifications?|languages?|contact|references?)$/i.test(normalizedText(line).replace(/[:\-]+$/,''));
}

function extractSection(text, headings) {
  const lines = cleanLines(text);
  const wanted = headings.map(entry => entry.toLowerCase());
  let start = -1;

  for (let index = 0; index < lines.length; index++) {
    const normalized = lines[index].toLowerCase().replace(/[:\-]+$/,'');
    if (wanted.includes(normalized) || wanted.some(entry => normalized.includes(entry))) {
      start = index + 1;
      break;
    }
  }

  if (start === -1) return '';

  const section = [];
  for (let index = start; index < lines.length; index++) {
    if (isHeadingLine(lines[index])) break;
    section.push(lines[index]);
  }

  return section.join('\n').trim();
}

function looksLikeDateLine(line) {
  return /\b(?:(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+)?(?:19|20)\d{2}\s*(?:-|–|—|to)\s*(?:(?:present|current|now)|(?:(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+)?(?:19|20)\d{2})\b/i.test(line);
}

function looksLikeRoleLine(line) {
  return /\b(manager|assistant|specialist|coordinator|representative|recruiter|developer|analyst|executive|sales|marketing|virtual assistant|account manager|hr|operations|technician|engineer|consultant|advisor|agent|support|administrator)\b/i.test(line);
}

function looksLikeCompanyLine(line) {
  return /\b(llc|inc|corp|company|clinic|hospital|agency|solutions|services|ltda|sas|s\.a\.s|universidad|university|college|school|bank|group)\b/i.test(line)
    || /^[A-ZÀ-Ý][\wÀ-ÿ&.,' -]{3,}$/.test(line);
}

function getNearbyLines(lines, index, radius = 2) {
  return lines.slice(Math.max(0, index - radius), Math.min(lines.length, index + radius + 1));
}

function inferLanguagesFromText(text) {
  const section = extractSection(text, ['languages', 'language', 'idiomas']);
  const source = section || text;
  const catalog = ['English', 'Spanish', 'French', 'German', 'Portuguese', 'Italian', 'Mandarin', 'Chinese', 'Arabic', 'Japanese', 'Korean', 'Dutch', 'Russian', 'Hindi'];
  const levels = [
    { rx: /\b(native|bilingual)\b/i, value: 'Native' },
    { rx: /\b(c2|c1|b2|b1|a2|a1)\b/i, value: match => match[1].toUpperCase() },
    { rx: /\b(fluent)\b/i, value: 'C1' },
    { rx: /\b(advanced)\b/i, value: 'B2' },
    { rx: /\b(intermediate)\b/i, value: 'B1' },
    { rx: /\b(basic)\b/i, value: 'A2' },
  ];

  const found = [];
  for (const language of catalog) {
    const match = source.match(new RegExp(`\\b${language}\\b([^\\n]{0,30})`, 'i'));
    if (!match) continue;
    let label = language;
    const tail = match[1] || '';
    for (const level of levels) {
      const levelMatch = tail.match(level.rx);
      if (levelMatch) {
        label += ' ' + (typeof level.value === 'function' ? level.value(levelMatch) : level.value);
        break;
      }
    }
    found.push(label);
  }

  return [...new Set(found)].join(', ');
}

function inferCertificationsFromText(text) {
  const section = extractSection(text, ['certifications', 'certification', 'licenses', 'licenses & certifications']);
  const source = section || text;
  const certRx = /\b(AWS\s+(?:Certified\s+)?[\w\s]+|Google\s+(?:Certified|Analytics|Ads|Cloud)[\w\s]*|PMP|PMI[\-\s]ACP|Scrum\s+Master|CSM|CSPO|HubSpot[\w\s]*(?:Certified)?|Salesforce\s+\w+|HIPAA\s+(?:Compliant|Certified)?|CompTIA\s+\w+|Cisco\s+\w+|Microsoft\s+(?:Certified|Azure|Office)\s+[\w\s]*|Six\s+Sigma|ITIL|ISO\s+\d+|CPA|CMA|SHRM[-\s]?\w*)\b/gi;
  return [...new Set([...source.matchAll(certRx)].map(match => normalizedText(match[0])))]
    .slice(0, 8)
    .join(', ');
}

function inferWebsiteFromText(text) {
  const domainRx = /\b(?:https?:\/\/)?(?:www\.)?(?!linkedin\.com|github\.com|x\.com|twitter\.com)([a-z0-9][a-z0-9.-]+\.[a-z]{2,})(?:\/[^\s]*)?\b/gi;
  const matches = [...text.matchAll(domainRx)].map(match => normalizedText(match[0]).replace(/[,.)]+$/,''));
  return matches.find(entry => !entry.includes('@')) || '';
}

function inferSkillsFromText(text) {
  const section = extractSection(text, ['skills', 'core skills', 'technical skills', 'key skills', 'competencies', 'tools']);
  const source = section || text;
  const knownSkills = [
    'Excel', 'Google Sheets', 'Google Workspace', 'Microsoft Office', 'Word', 'PowerPoint',
    'HubSpot', 'Salesforce', 'CRM', 'Asana', 'Trello', 'Monday.com', 'Slack', 'Notion',
    'Customer Service', 'Project Management', 'Data Entry', 'Calendar Management',
    'Administrative Support', 'Executive Support', 'Lead Generation', 'Cold Calling',
    'Social Media', 'SEO', 'Google Ads', 'Meta Ads', 'Email Marketing', 'Recruiting',
    'Payroll', 'Onboarding', 'HIPAA', 'EHR', 'EMR', 'Medical Billing', 'Prior Authorization'
  ];

  const found = knownSkills.filter(skill => new RegExp(`\\b${skill.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'i').test(source));
  if (found.length) return [...new Set(found)].join(', ');

  const sectionTokens = section
    .split(/[\n,|•·]+/)
    .map(token => normalizedText(token))
    .filter(token => token && token.length >= 3 && token.length <= 40);

  return [...new Set(sectionTokens)].slice(0, 12).join(', ');
}

function inferSummaryFromText(text, data = {}) {
  const section = extractSection(text, ['summary', 'professional summary', 'profile', 'about', 'objective']);
  if (section) {
    return cleanLines(section).slice(0, 3).join(' ');
  }

  const title = data.main_title || data.suggested_role || '';
  const years = data.exp_years || data.exp_years_text || '';
  const skills = normalizedText(data.skills || '').split(',').map(token => normalizedText(token)).filter(Boolean).slice(0, 4);
  const locationBits = [data.city, data.country].filter(Boolean).join(', ');

  const parts = [];
  if (title) parts.push(`Professional with experience in ${title}.`);
  if (years) parts.push(`Background level identified as ${years}.`);
  if (skills.length) parts.push(`Core strengths include ${skills.join(', ')}.`);
  if (locationBits) parts.push(`Based in ${locationBits}.`);

  return parts.join(' ').trim();
}

function inferEducationData(text) {
  const section = extractSection(text, ['education', 'academic background', 'academic history', 'studies']);
  const lines = cleanLines(section || text);
  const degreeIndexes = lines
    .map((line, index) => ({ line, index }))
    .filter(entry => /\b(ph\.?d|doctorate|master'?s?|mba|msc|bachelor'?s?|b\.s\.|b\.a\.|engineer|licenci|associate|technician|t[eé]cnico|diploma|certificate)\b/i.test(entry.line));
  const [main, secondary] = degreeIndexes;
  const pickInstitution = (entry) => {
    if (!entry) return '';
    return getNearbyLines(lines, entry.index, 2).find(line => /\b(university|college|institute|school|academy|instituci[oó]n|universidad)\b/i.test(line)) || '';
  };
  const pickYears = (entry) => {
    if (!entry) return '';
    return getNearbyLines(lines, entry.index, 2).find(line => /\b(19|20)\d{2}\b/.test(line)) || '';
  };

  return {
    main_degree: main?.line || '',
    main_institution: pickInstitution(main),
    main_years: pickYears(main),
    other_degree: secondary?.line || '',
    other_institution: pickInstitution(secondary),
    other_years: pickYears(secondary),
  };
}

function inferExperienceData(text) {
  const section = extractSection(text, ['experience', 'professional experience', 'work experience', 'employment history']);
  const lines = cleanLines(section || text);
  const entries = [];
  lines.forEach((line, index) => {
    if (!looksLikeDateLine(line)) return;
    const nearby = getNearbyLines(lines, index, 2).filter(candidate => candidate !== line);
    const title = nearby.find(looksLikeRoleLine) || '';
    const company = nearby.find(candidate => candidate !== title && looksLikeCompanyLine(candidate)) || '';
    const responsibilities = lines
      .slice(index + 1, index + 6)
      .filter(candidate => /^[-•]/.test(candidate) || /\b(responsible|managed|supported|coordinated|handled|developed|executed|assisted|oversaw|led|maintained|organized)\b/i.test(candidate))
      .slice(0, 4)
      .map(candidate => candidate.replace(/^[•-]\s*/, '- '));

    entries.push({
      years: line,
      title,
      company,
      responsibilities: responsibilities.join('\n'),
    });
  });

  if (entries.length === 0) {
    const roleLines = lines.filter(looksLikeRoleLine);
    const companyLines = lines.filter(looksLikeCompanyLine);
    entries.push({
      title: roleLines[0] || '',
      company: companyLines[0] || '',
      responsibilities: lines
        .filter(line => /^[-•]/.test(line) || /\b(responsible|managed|supported|coordinated|handled|developed|executed|assisted|oversaw|led|maintained|organized)\b/i.test(line))
        .slice(0, 4)
        .map(line => line.replace(/^[•-]\s*/, '- '))
        .join('\n'),
    });
    if (roleLines[1] || companyLines[1]) {
      entries.push({
        title: roleLines[1] || '',
        company: companyLines[1] || '',
      });
    }
  }

  const [main, secondary] = entries;

  return {
    main_title: normalizedText(main?.title || ''),
    main_company: normalizedText((main?.company || '').replace(/\b(?:at|for)\s+/i, '')),
    main_responsibilities: normalizedText(main?.responsibilities || ''),
    other_title: normalizedText(secondary?.title || ''),
    other_company: normalizedText((secondary?.company || '').replace(/\b(?:at|for)\s+/i, '')),
    other_years_exp: normalizedText(secondary?.years || ''),
  };
}

function inferExperienceRangeFromDates(dateRangesText) {
  const years = [...String(dateRangesText || '').matchAll(/\b(19|20)\d{2}\b/g)].map(match => parseInt(match[0], 10));
  if (!years.length) return '';
  const minYear = Math.min(...years);
  const maxYear = Math.max(...years, new Date().getFullYear());
  const span = Math.max(0, maxYear - minYear);
  if (span >= 7) return 'Senior Level (7+ years)';
  if (span >= 5) return '5+ years';
  if (span >= 3) return '3 to 4 years';
  if (span >= 1) return '1 to 2 years';
  return 'Less than 1 year';
}

function inferSuggestedRole(text, data = {}) {
  const source = [data.main_title, data.skills, text].filter(Boolean).join('\n').toLowerCase();
  if (/\b(prior authorization|medical billing|rcm|insurance claims|cpt|icd-?10|scheduler|patient)\b/.test(source)) return 'HOP';
  if (/\b(hipaa|ehr|emr|clinic|hospital|healthcare|medical assistant|medical)\b/.test(source)) return 'HVA';
  if (/\b(seo|social media|google ads|meta ads|email marketing|content|marketing)\b/.test(source)) return /\b(manager|director|lead)\b/.test(source) ? 'MGR' : 'MVA';
  if (/\b(sales|sdr|lead generation|prospecting|cold call|appointment setting)\b/.test(source)) return 'SDR';
  if (/\b(account manager|client success|customer success|portfolio|renewals)\b/.test(source)) return 'ACM';
  if (/\b(recruiting|talent acquisition|hr|human resources|payroll|onboarding)\b/.test(source)) return 'HRO';
  if (/\b(executive assistant|virtual assistant|administrative assistant|calendar management|travel coordination|inbox management)\b/.test(source)) return 'VPA';
  return '';
}

function buildLocalFallbackData(text, seed = {}) {
  const out = normalizeExtractedData({...seed}, text);
  const education = inferEducationData(text);
  const experience = inferExperienceData(text);
  const location = inferLocationFromText(text, out.address || out.address_hint || '');

  out.name ||= pickCandidateName(text);
  out.address ||= location.address;
  out.country ||= location.country || inferCountryFromPhone(out.phone || '');
  out.city ||= location.city;
  out.skills ||= inferSkillsFromText(text);
  out.summary ||= inferSummaryFromText(text, {...out, ...experience});
  out.languages ||= inferLanguagesFromText(text);
  out.certifications ||= inferCertificationsFromText(text);
  out.website ||= inferWebsiteFromText(text);
  out.availability ||= inferAvailability(text);
  out.exp_years ||= out.exp_years_text || inferExperienceRangeFromDates(out.dateRanges || '');
  out.suggested_role ||= inferSuggestedRole(text, {...out, ...experience});

  for (const [key, value] of Object.entries(education)) {
    if (!out[key] && normalizedText(value)) out[key] = value;
  }
  for (const [key, value] of Object.entries(experience)) {
    if (!out[key] && normalizedText(value)) out[key] = value;
  }

  return normalizeExtractedData(out, text);
}

/* ════ LANDING SCREEN HANDLERS ════ */
function doNewSession() {
  // Ocultar landing screen inmediatamente — style directo, sin clases
  document.getElementById('landing-screen').style.display = 'none';
  // Mostrar gate
  document.getElementById('gate').classList.remove('hidden');
  // Generar token
  const token = generateToken();
  setToken(token);
  // Fetch en background — no bloquea nada
  fetch('save-progress.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'new_session', token})
  }).catch(() => {});
}

function doResume() {
  const token = document.getElementById('ri-token').value.trim();
  if (!token) { alert('Please enter your application token'); return; }
  setToken(token);
  document.getElementById('landing-screen').style.display = 'none';
  document.getElementById('gate').classList.remove('hidden');
}

/* ════ EXTRACTION PIPELINE ════ */
async function runExtraction() {
  if (!cvText || cvText.length < 20) return;
  document.getElementById('side-extractor').classList.remove('hidden');
  document.getElementById('btn-toggle-ext').classList.remove('hidden');
  document.getElementById('btn-toggle-ext').classList.add('flex');
  document.getElementById('regex-out').innerHTML = '';
  document.getElementById('ai-out').innerHTML = '';
  document.getElementById('merged-out').innerHTML = '';

  const btn = document.getElementById('btn-analyze');
  btn.innerHTML = '<span class="sp mr-2"></span> AI Processing...';
  btn.disabled = true;

  try {
    xStep(2,'run');
    const rxData = regexParse(cvText);
    renderExtFields('regex-out', rxData, 'regex');
    document.getElementById('rx-count').textContent = countFilled(rxData) + ' fields';
    xStep(2,'done');

    xStep(3,'run');
    const aiData = await aiExtract(cvText, rxData);
    renderExtFields('ai-out', aiData, 'ai');
    document.getElementById('ai-count').textContent = countFilled(aiData) + ' fields';
    xStep(3,'done');

    xStep(4,'run');
    finalMerged = mergeData(rxData, aiData);
    renderExtFields('merged-out', finalMerged, 'merge', aiData);
    document.getElementById('json-out').value = JSON.stringify(finalMerged, null, 2);
    document.getElementById('btn-copy-json').classList.remove('hidden');
    updateCoverage(finalMerged);
    xStep(4,'done');

    fillForm(finalMerged);

    setTimeout(() => {
      document.getElementById('gate').classList.add('hidden');
      document.getElementById('form-wrap').classList.remove('hidden');
      goStep(0);
    }, 700);

    extLog('✅ Pipeline complete!', 'ok');
  } catch(e) {
    extLog('❌ Error: ' + e.message, 'err');
    fillForm(regexParse(cvText));
  } finally {
    btn.innerHTML = '🔍 Analyze CV & Unlock Form';
    btn.disabled = false;
  }
}

/* ════ REGEX PARSER ════ */
function regexParse(text) {
  extLog('Regex → scanning...','info');
  const r = {};
  r.name_guess = pickCandidateName(text);
  if (r.name_guess) {
    r.name = r.name_guess;
    extLog('name: ' + r.name,'ok');
  }
  const emails = [...text.matchAll(/[\w.+\-]{2,}@[\w\-]+\.[\w.]{2,7}/g)].map(m => m[0].toLowerCase());
  r.email = emails[0] || '';
  if (emails.length > 1) r.email_alt = emails.slice(1).join(', ');
  if (r.email) extLog('email: ' + r.email,'ok');
  const phones = [...text.matchAll(/(?:\+\d{1,3}[\s\-.]?)?\(?\d{2,4}\)?[\s\-.]?\d{3,4}[\s\-.]?\d{3,5}(?!\d)/g)].map(m => m[0].trim()).filter(p => p.replace(/\D/g,'').length >= 7);
  r.phone = phones[0] || '';
  if (r.phone) extLog('phone: ' + r.phone,'ok');
  const li = text.match(/(?:www\.)?linkedin\.com\/in\/([\w\-]+)/i);
  r.linkedin = li ? 'linkedin.com/in/' + li[1] : '';
  if (r.linkedin) extLog('linkedin: ' + r.linkedin,'ok');
  const gh = text.match(/github\.com\/([\w\-]+)/i);
  r.github = gh ? 'github.com/' + gh[1] : '';
  const webRx = /https?:\/\/(?!(?:www\.)?(?:linkedin|github|twitter|x\.com))[^\s,)>"'<]+/gi;
  const webs = [...text.matchAll(webRx)].map(m => m[0].replace(/[,.)]+$/,''));
  r.website = webs[0] || inferWebsiteFromText(text);
  const cityRx = text.match(/\b(?:Based (?:in|at)|Location[:\s]+|Address[:\s]+|City[:\s]+)([A-Z][a-zA-ZÀ-ÿ\s\-]+(?:,\s*[A-Z][a-zA-ZÀ-ÿ\s]+)?)/i);
  r.address_hint = (cityRx?.[1] || '').trim();
  if (r.address_hint) extLog('address: ' + r.address_hint,'ok');
  const location = inferLocationFromText(text, r.address_hint);
  if (location.country) r.country = location.country;
  if (location.city) r.city = location.city;
  if (!r.address_hint && location.address) r.address_hint = location.address;
  if (r.country) extLog('country: ' + r.country,'ok');
  if (r.city) extLog('city: ' + r.city,'ok');
  const salRx = text.match(/(?:salary|compensation|rate|expected)[:\s]*[\$USD€£]?\s*(\d[\d,\.]+)/i);
  if (salRx) r.salary_mention = normalizeSalaryValue(salRx[1]);
  const availability = inferAvailability(text);
  if (availability) {
    r.availability_hint = availability;
    extLog('availability: ' + availability,'ok');
  }
  const months = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';
  const dRx = new RegExp(`(?:(?:${months})[a-z]*\\.?\\s+)?\\d{4}\\s*[-–—]\\s*(?:(?:${months})[a-z]*\\.?\\s+)?(?:\\d{4}|Present|Current|Now)`,'gi');
  const dates = [...text.matchAll(dRx)].map(m => m[0].trim());
  r.dateRanges = [...new Set(dates)].join(' | ');
  const educationData = inferEducationData(text);
  Object.assign(r, Object.fromEntries(Object.entries(educationData).filter(([, value]) => normalizedText(value))));
  const eduMap = {
    'Doctorate (PhD) or equivalent': /\b(ph\.?d|doctorate|doctor\s+of)\b/i,
    "Master's degree": /\b(master'?s?|m\.s\.|m\.a\.|mba|msc|m\.eng)\b/i,
    "Bachelor's degree": /\b(bachelor'?s?|b\.s\.|b\.a\.|bsc|b\.eng|licenci|pregrado)\b/i,
    'Associate degree or equivalent': /\b(associate|technician|técnico)\b/i,
    'High school diploma': /\b(high school|secondary|bachillerato)\b/i,
  };
  for (const [lvl, rx] of Object.entries(eduMap)) {
    if (rx.test(text)) { r.education_level = lvl; break; }
  }
  if (r.education_level) extLog('education: ' + r.education_level,'ok');
  const langRx = /\b(English|Spanish|French|German|Portuguese|Italian|Mandarin|Chinese|Arabic|Japanese|Korean|Dutch|Russian|Hindi)\b[^\n]{0,35}?\b([ABC][12]|[Nn]ative|[Ff]luent|[Aa]dvanced|[Uu]pper[\s\-][Ii]ntermediate|[Ii]ntermediate|[Bb]asic)\b/g;
  const langs = [...text.matchAll(langRx)].map(m => `${m[1]} (${m[2]})`);
  r.languages = [...new Set(langs)].join(', ') || inferLanguagesFromText(text);
  if (r.languages) extLog('languages: ' + r.languages,'ok');
  r.certifications = inferCertificationsFromText(text);
  if (r.certifications) extLog('certifications: ' + r.certifications,'ok');
  const hcKw = /\b(EMR|EHR|HIPAA|medical\s+billing|ICD-?10|CPT\s+code|prior\s+authorization|patient\s+scheduling|clinical|healthcare|health\s+care|physician|hospital|clinic|nursing|dental|pharmacy|insurance\s+claim|RCM|athena|epic|cerner|modmed)\b/gi;
  const hcM = text.match(hcKw) || [];
  if (hcM.length >= 2) { r.edu_healthcare = 'Yes'; r.worked_healthcare = 'Yes'; extLog(`healthcare (${hcM.length} keywords)`,'ok'); }
  const vaKw = /\b(virtual\s+assistant|executive\s+assistant|administrative\s+assistant|remote\s+support|calendar\s+management|personal\s+assistant)\b/gi;
  if ((text.match(vaKw) || []).length >= 1) { r.worked_va = 'Yes'; extLog('VA experience detected','ok'); }
  const expRx = text.match(/(\d+)\+?\s*years?\s+(?:of\s+)?(?:professional\s+)?(?:work\s+)?experience/i);
  if (expRx) {
    const yrs = parseInt(expRx[1]);
    r.exp_years_text = yrs >= 7 ? 'Senior Level (7+ years)' : yrs >= 5 ? '5+ years' : yrs >= 3 ? '3 to 4 years' : yrs >= 1 ? '1 to 2 years' : 'Less than 1 year';
    extLog('exp years: ' + r.exp_years_text,'ok');
  }
  if (!r.exp_years_text && r.dateRanges) {
    r.exp_years_text = inferExperienceRangeFromDates(r.dateRanges);
    if (r.exp_years_text) extLog('exp years (dates): ' + r.exp_years_text,'ok');
  }
  const experienceData = inferExperienceData(text);
  Object.assign(r, Object.fromEntries(Object.entries(experienceData).filter(([, value]) => normalizedText(value))));
  if (!r.skills) {
    r.skills = inferSkillsFromText(text);
    if (r.skills) extLog('skills: ' + r.skills,'ok');
  }
  r.suggested_role ||= inferSuggestedRole(text, r);
  if (r.suggested_role) extLog('suggested role: ' + r.suggested_role,'ok');
  if (!r.summary) {
    r.summary = inferSummaryFromText(text, r);
    if (r.summary) extLog('summary: generated','ok');
  }
  extLog(`Regex → ${countFilled(r)} fields`,'ok');
  return r;
}

/* ════ AI EXTRACTION ════ */
async function aiExtract(text, seedData = {}) {
  const anyActive = AI_PROVIDERS.some(provider => !!AI_STATUS[provider.key]);
  const localFallback = buildLocalFallbackData(text, seedData);
  if (!anyActive) {
    extLog('No AI providers configured in .env → using local smart fallback','warn');
    document.getElementById('side-provider-badge').textContent = '⚠ LOCAL';
    return localFallback;
  }
  extLog('Calling api-proxy4.php...','info');
  try {
    const resp = await fetch('api-proxy4.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'extract_cv', cv_text: text.slice(0,12000), prompt: AI_PROMPT})
    });
    if (!resp.ok) { const errText = await resp.text().catch(() => ''); throw new Error('HTTP ' + resp.status + ' — ' + errText.slice(0,150)); }
    const j = await resp.json();
    if (j.status === 'ok' && j.data) {
      const normalized = normalizeExtractedData(j.data, text);
      const count = countFilled(normalized);
      extLog('AI ' + j.provider.toUpperCase() + ' extracted ' + count + ' fields','ok');
      document.getElementById('side-provider-badge').textContent = '+ ' + j.provider.toUpperCase();
      document.getElementById('ai-count').textContent = count + ' fields';
      return normalized;
    }
    extLog('Proxy: ' + (j.error || 'Unknown error'),'err');
    if (j.details && j.details.length) j.details.forEach(d => extLog('  ' + d,'warn'));
    if (!j.env_loaded) extLog('  .env file NOT found by server!','err');
    extLog('Using local smart fallback for missing AI response','warn');
    document.getElementById('side-provider-badge').textContent = '⚠ LOCAL';
    return localFallback;
  } catch(err) {
    extLog('api-proxy4.php error: ' + err.message,'err');
    extLog('Using local smart fallback after proxy error','warn');
    document.getElementById('side-provider-badge').textContent = '⚠ LOCAL';
    return localFallback;
  }
}

/* ════ MERGE ════ */
function mergeData(rx, ai) {
  const out = {...normalizeExtractedData(ai, cvText)};
  const rxAuth = ['name','email','phone','linkedin','github','twitter','website','education_level','languages','certifications','edu_healthcare','worked_healthcare','worked_va','exp_years_text','dateRanges','address_hint','salary_mention','email_alt','country','city','availability_hint'];
  rxAuth.forEach(k => { if (rx[k]?.trim()) out[k] = rx[k]; });
  Object.entries(rx).forEach(([k,v]) => { if (v && !out[k]) out[k] = v; });
  if (!out.exp_years && rx.exp_years_text) out.exp_years = rx.exp_years_text;
  if (!out.address && rx.address_hint) out.address = rx.address_hint;
  if (!out.availability && rx.availability_hint) out.availability = rx.availability_hint;
  if (!out.salary && rx.salary_mention) out.salary = rx.salary_mention;
  return normalizeExtractedData(out, cvText);
}

/* ════ COVERAGE ════ */
function updateCoverage(data) {
  const filled = GSD_FIELDS.filter(f => data[f] && String(data[f]).trim()).length;
  const total = GSD_FIELDS.length;
  const pct = Math.round(filled / total * 100);
  document.getElementById('cov-pct').textContent = pct + '%';
  document.getElementById('cov-fill').style.width = pct + '%';
  document.getElementById('cov-label').textContent = `${filled} / ${total} fields`;
  const fill = document.getElementById('cov-fill');
  fill.style.background = pct >= 80 ? 'linear-gradient(90deg,#16a34a,#22c55e)' : pct >= 60 ? 'linear-gradient(90deg,#5A3988,#8C52FF)' : 'linear-gradient(90deg,#c2410c,#f97316)';
}

/* ════ FILL FORM ════ */
function fillForm(d) {
  d = normalizeExtractedData(d, cvText);
    // --- PROCESAMIENTO LIMPIO DE TELÉFONOS ---
  const phoneRaw = d.phone || '';
  if (phoneRaw) {
    // Extraemos el código (+XX) y el resto del número
    const match = phoneRaw.match(/^(\+\d+)\s*(.*)$/);
    if (match) {
      const code = match[1].trim();
      const number = match[2].replace(/\s+/g, ''); // Eliminamos todos los espacios internos

      // Asignar a Phone
      const pCode = document.getElementById('f-phone-code');
      const pNum = document.getElementById('f-phone');
      if (pCode) pCode.value = code;
      if (pNum) { pNum.value = number; pNum.classList.add('ai-hi'); }

      // Asignar a WhatsApp
      const wCode = document.getElementById('f-whatsapp-code');
      const wNum = document.getElementById('f-whatsapp');
      if (wCode) wCode.value = code;
      if (wNum) { wNum.value = number; wNum.classList.add('ai-hi'); }
    }
  }
  Object.entries(FM).forEach(([id,key]) => { const el = document.getElementById(id); if (el && d[key]) { el.value = d[key]; el.classList.add('ai-hi'); } });
  if (d.salary) {
    const salaryEl = document.getElementById('f-salary');
    if (salaryEl) {
      salaryEl.value = normalizeSalaryValue(d.salary);
      salaryEl.classList.add('ai-hi');
    }
  }
  if (d.suggested_role) {
    const roleCode = extractRoleCode(d.suggested_role);
    const roleEl = document.getElementById('f-role');
    if (roleCode && roleEl) {
      const option = Array.from(roleEl.options).find(opt => opt.text.includes(roleCode));
      if (option) {
        roleEl.value = option.value;
        roleEl.classList.add('ai-hi');
      }
    }
    if (roleCode && ['VPA','HVA','HOP','MVA','HRO','MGR','ACM','SDR'].includes(roleCode)) setTimeout(() => onRoleChange(roleCode), 300);
  }
  if (d.edu_healthcare)    fillRadio('r-edu-hc',  d.edu_healthcare === 'Yes' ? 'Yes' : 'No');
  if (d.worked_healthcare) fillRadio('r-work-hc', d.worked_healthcare === 'Yes' ? 'Yes' : 'No');
  if (d.worked_va)         fillRadio('r-va',       d.worked_va === 'Yes' ? 'Yes' : 'No');
  Object.entries(SELECT_FM).forEach(([id,key]) => {
    const el = document.getElementById(id);
    if (el && d[key]) { const match = Array.from(el.options).find(o => o.text.toLowerCase().includes(String(d[key]).toLowerCase()) || o.value.toLowerCase() === String(d[key]).toLowerCase()); if (match) { el.value = match.value; el.classList.add('ai-hi'); } }
  });
  if (d.country) { const cSel = document.getElementById('f-country'); if (cSel) { cSel.value = d.country; loadCities(d.country); } if (d.city) document.getElementById('f-city').value = d.city; }
}

function fillRadio(name, value) { const r = document.querySelector('input[name="' + name + '"][value="' + value + '"]'); if (r) r.checked = true; }

/* ════ RENDER FIELDS ════ */
function renderExtFields(containerId, data, mode = 'merge', aiSrc = {}) {
  const c = document.getElementById(containerId);
  if (!c) return;
  c.innerHTML = '';
  let count = 0;
  for (const [k,v] of Object.entries(data)) {
    if (!v || !String(v).trim()) continue;
    count++;
    const isAI = (mode === 'merge' && aiSrc && aiSrc[k]) || mode === 'ai';
    const cls = mode === 'regex' ? ' regex-f' : isAI ? ' ai-f' : '';
    const srcTag = mode === 'regex' ? '<span style="font-size:7px;background:#16a34a;color:#fff;padding:1px 4px;border-radius:3px;margin-left:3px;">REGEX</span>' : isAI ? '<span style="font-size:7px;background:#8C52FF;color:#fff;padding:1px 4px;border-radius:3px;margin-left:3px;">AI</span>' : '';
    const div = document.createElement('div');
    div.className = 'ef' + cls;
    div.innerHTML = `<label>${LABELS[k] || k}${srcTag}</label><div class="ev">${esc(String(v))}</div>`;
    c.appendChild(div);
  }
  if (count === 0) c.innerHTML = '<p class="text-[10px] text-gray-400 italic text-center py-2">No fields found.</p>';
  return count;
}

/* ════ FILE HANDLING ════ */
async function pickFile(input, type) {
  const file = input.files[0];
  if (!file) return;
  const nm = document.getElementById(type + '-nm');
  if (nm) { nm.textContent = '✓ ' + file.name; nm.classList.remove('hidden'); }
  const dz = document.getElementById('dz-' + (type === 'cv' ? 'cv' : type));
  if (dz) dz.classList.add('ok');
  if (type === 'cv') {
    try {
      xStep(1,'run');
      document.getElementById('ext-pipeline').classList.remove('hidden');
      cvText = file.name.endsWith('.pdf') ? await readPDF(file) : await readDOCX(file);
      document.getElementById('raw-txt').value = cvText;
      document.getElementById('raw-chars').textContent = cvText.length + ' chars';
      document.getElementById('raw-lines').textContent = cvText.split('\n').filter(l => l.trim()).length + ' lines';
      const ext = file.name.split('.').pop().toLowerCase();
      document.getElementById('raw-badge').className = 'badge ' + (ext === 'pdf' ? 'b-pdf' : 'b-docx');
      document.getElementById('raw-badge').textContent = ext === 'pdf' ? '🔴 PDF.js' : '🔵 mammoth.js';
      document.getElementById('raw-meta').textContent = file.name + ' · ' + (file.size/1024).toFixed(0) + ' KB';
      const btn = document.getElementById('btn-analyze');
      btn.disabled = false;
      btn.className = btn.className.replace('bg-gray-300','bg-purple-700').replace('text-gray-500','text-white').replace('cursor-not-allowed','cursor-pointer hover:bg-purple-800');
      xStep(1,'done');
      extLog(`File "${file.name}" loaded — ${cvText.length} chars`,'ok');
      document.getElementById('side-extractor').classList.remove('hidden');
      document.getElementById('btn-toggle-ext').classList.remove('hidden');
      document.getElementById('btn-toggle-ext').classList.add('flex');
    } catch(e) { extLog('Error reading file: ' + e.message,'err'); }
  }
}

async function readPDF(file) {
  extLog('PDF.js → reading...','info');
  const buf = await file.arrayBuffer();
  const pdf = await pdfjsLib.getDocument({data: buf}).promise;
  extLog(`PDF.js → ${pdf.numPages} page(s)`,'ok');
  let full = '';
  for (let p = 1; p <= pdf.numPages; p++) {
    const page = await pdf.getPage(p);
    const content = await page.getTextContent();
    const TOLERANCE = 3;
    const buckets = [];
    content.items.forEach(item => {
      if (!item.str.trim()) return;
      const y = item.transform[5];
      const bucket = buckets.find(b => Math.abs(b.y - y) <= TOLERANCE);
      if (bucket) bucket.items.push({x: item.transform[4], str: item.str});
      else buckets.push({y, items: [{x: item.transform[4], str: item.str}]});
    });
    buckets.sort((a,b) => b.y - a.y);
    full += buckets.map(b => b.items.sort((a,z) => a.x - z.x).map(i => i.str).join(' ')).join('\n') + '\n\n';
  }
  return full;
}

async function readDOCX(file) {
  extLog('mammoth.js → reading...','info');
  const buf = await file.arrayBuffer();
  const result = await mammoth.extractRawText({arrayBuffer: buf});
  extLog(`mammoth.js → ${result.value.length} chars`,'ok');
  return result.value;
}

/* ════ UI HELPERS ════ */
function xStep(n, s) {
  const el = document.getElementById('xd' + n);
  if (!el) return;
  const span = el.querySelector('span');
  if (s === 'run') { el.className = 'xd-item run'; if (span) span.className = 'w-4 h-4 rounded-full bg-purple-500 flex items-center justify-center text-[8px] text-white'; }
  else if (s === 'done') { el.className = 'xd-item done'; if (span) { span.className = 'w-4 h-4 rounded-full bg-green-500 flex items-center justify-center text-[8px] text-white'; span.textContent = '✓'; } }
}

function extLog(msg, type = 'info') {
  const log = document.getElementById('log-box');
  if (!log) return;
  const div = document.createElement('div');
  div.className = 'log-line log-' + type;
  div.textContent = '[' + new Date().toLocaleTimeString('en-US', {hour12:false}) + '] ' + msg;
  log.appendChild(div);
  log.scrollTop = log.scrollHeight;
}

function switchExtTab(tab) {
  ['merged','raw','json'].forEach(t => {
    document.getElementById('view-' + t).classList.toggle('hidden', t !== tab);
    document.getElementById('tab-' + t).classList.toggle('active', t === tab);
  });
}

function toggleExtPanel() {
  const panel = document.getElementById('side-extractor');
  const lbl = document.getElementById('ext-toggle-lbl');
  lbl.textContent = panel.classList.toggle('hidden') ? 'Show Extractor' : 'Hide Extractor';
}

function copyExtJSON() {
  navigator.clipboard.writeText(document.getElementById('json-out').value).then(() => {
    const btn = document.getElementById('btn-copy-json');
    const orig = btn.textContent;
    btn.textContent = '✅ Copied!';
    setTimeout(() => btn.textContent = orig, 2000);
  });
}

function clearLog() { const el = document.getElementById('log-box'); if (el) el.innerHTML = ''; }

/* ════ FORM NAVIGATION ════ */
function nextStep() {
  const pane = document.getElementById('s' + curStep);
  let valid = true;
  pane.querySelectorAll('.lbl.req').forEach(label => {
    const input = label.closest('div')?.querySelector('.inp');
    if (input && !input.value.trim()) { input.style.borderColor = '#ef4444'; valid = false; }
    else if (input) input.style.borderColor = '';
  });
  if (!valid) { alert('Please complete required fields (*)'); return; }
  goStep(curStep + 1);
}

function prevStep() { goStep(curStep - 1); }

function goStep(n) {
  if (n < 0 || n >= STEPS) return;
  document.querySelectorAll('.step-pane').forEach((s,i) => s.classList.toggle('active', i === n));
  curStep = n;
  document.getElementById('pbar').style.width = ((curStep + 1) / STEPS * 100) + '%';
  document.getElementById('prog-lbl').textContent = `Step ${curStep + 1} / ${STEPS}`;
  for (let i = 0; i < STEPS; i++) { const dot = document.getElementById('d' + i); if (dot) dot.className = 'dot ' + (i < curStep ? 'done' : i === curStep ? 'active' : 'pending'); }
  document.getElementById('btn-back').classList.toggle('hidden', curStep === 0);
  const btnNext = document.getElementById('btn-next');
  if (curStep === STEPS - 1) { btnNext.textContent = ''; btnNext.classList.add('hidden'); }
  else { btnNext.innerHTML = 'Continue Application →'; btnNext.classList.remove('hidden'); }
  window.scrollTo(0, 0);
}

/* ════ LOCATION ════ */
function initLocationSelectors() {
  const cSel = document.getElementById('f-country');
  const pCode = document.getElementById('f-phone-code');
  const wCode = document.getElementById('f-whatsapp-code');
  if (!cSel) return;
  cSel.innerHTML = '<option value="">— Select country —</option>';
  Object.keys(locationData).forEach(c => {
    cSel.add(new Option(c, c));
    const lbl = `${locationData[c].code} (${c.substring(0,8)})`;
    pCode.add(new Option(lbl, locationData[c].code));
    wCode.add(new Option(lbl, locationData[c].code));
  });
}

function loadCities(c) {
  const sel = document.getElementById('f-city');
  if (!sel) return;
  sel.innerHTML = '<option value="">— Select city —</option>';
  if (locationData[c]) locationData[c].cities.forEach(city => sel.add(new Option(city, city)));
}

/* ════ AI STATUS INIT ════ */
function initAIStatus() {
  const box = document.getElementById('ai-provider-status');
  const orderEl = document.getElementById('ai-order-disp');
  if (!box) return;
  box.innerHTML = AI_PROVIDERS.map(p => {
    const on = AI_STATUS[p.key];
    return `<div class="text-center py-2 px-1 rounded-lg border ${on ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-gray-50'}">
      <div class="text-base">${p.icon}</div>
      <div class="text-[9px] font-bold ${on ? 'text-green-700' : 'text-gray-400'}">${p.label}</div>
      <div class="text-[8px] ${on ? 'text-green-600' : 'text-gray-300'} font-bold">${on ? '⏳ Checking' : 'CFG'}</div>
    </div>`;
  }).join('');
  if (orderEl) orderEl.textContent = AI_STATUS.order || 'gemini,claude,openai';
  updateProviderBadge('⏳ Checking', 'b-ai');
  void pingProxy(true);
}

function providerCardState(provider) {
  if (!provider) return {wrap:'border-gray-200 bg-gray-50', name:'text-gray-400', status:'text-gray-300', label:'—'};
  if (provider.healthy) return {wrap:'border-green-200 bg-green-50', name:'text-green-700', status:'text-green-600', label:'✓ Online'};
  if (!provider.configured) return {wrap:'border-gray-200 bg-gray-50', name:'text-gray-400', status:'text-gray-300', label:provider.code || 'CFG'};
  return {wrap:'border-amber-200 bg-amber-50', name:'text-amber-700', status:'text-amber-600', label:provider.code || 'WARN'};
}

function updateProviderBadge(text, tone) {
  const badge = document.getElementById('side-provider-badge');
  if (!badge) return;
  badge.className = `badge ${tone}`;
  badge.textContent = text;
}

function renderProviderHealth(providerMap) {
  const box = document.getElementById('ai-provider-status');
  if (!box || !providerMap) return;

  box.innerHTML = AI_PROVIDERS.map(meta => {
    const provider = providerMap[meta.key];
    const state = providerCardState(provider);
    const tooltip = provider?.message ? ` title="${String(provider.message).replace(/"/g, '&quot;')}"` : '';
    return `<div class="text-center py-2 px-1 rounded-lg border ${state.wrap}"${tooltip}>
      <div class="text-base">${meta.icon}</div>
      <div class="text-[9px] font-bold ${state.name}">${meta.label}</div>
      <div class="text-[8px] ${state.status} font-bold">${state.label}</div>
    </div>`;
  }).join('');
}

function applyProviderHealth(health) {
  AI_HEALTH = health;
  if (health.providers) renderProviderHealth(health.providers);
  if (health.order) AI_STATUS.order = health.order;

  const orderEl = document.getElementById('ai-order-disp');
  if (orderEl) orderEl.textContent = AI_STATUS.order || 'gemini,claude,openai';

  if (health.all_healthy) {
    updateProviderBadge('✅ AI Online', 'b-ai-ok');
    return;
  }

  const failingCodes = AI_PROVIDERS
    .map(meta => health.providers?.[meta.key])
    .filter(provider => provider && provider.configured && !provider.healthy)
    .map(provider => provider.code || 'WARN');

  if (failingCodes.length) {
    updateProviderBadge('⚠ ' + failingCodes.join('/'), 'b-ai-warn');
    return;
  }

  updateProviderBadge('⚪ Offline', 'b-ai-off');
}

async function pingProxy(silentLog = false) {
  const resultEl = document.getElementById('ping-result');
  resultEl.classList.remove('hidden');
  resultEl.textContent = 'Pinging api-proxy4.php...';
  try {
    const resp = await fetch('api-proxy4.php?action=ping&notify=1');
    const j = await resp.json();
    applyProviderHealth(j);
    let out = 'STATUS: ' + j.status + '\nENV: ' + (j.env_loaded ? '✓ ' + j.env_path : '✗ NOT FOUND') + '\ncURL: ' + (j.curl || '?') + '\nPHP: ' + (j.php || '?') + '\n';
    if (j.providers) {
      AI_PROVIDERS.forEach(provider => {
        const snapshot = j.providers[provider.key];
        if (!snapshot) return;
        out += provider.label.toUpperCase() + ': ' + (snapshot.code || '?') + ' — ' + (snapshot.message || 'n/a') + '\n';
      });
    }
    out += 'ORDER: ' + (j.order || '?');
    if (j.alert?.reason) out += '\nALERT: ' + j.alert.reason + (j.alert.to ? ' → ' + j.alert.to : '');
    resultEl.textContent = out;
    resultEl.style.color = j.all_healthy ? '#4ade80' : '#fbbf24';
    if (!silentLog) extLog('Ping: env=' + (j.env_loaded ? 'OK' : 'NOT FOUND') + ' / allHealthy=' + (j.all_healthy ? 'YES' : 'NO'), j.all_healthy ? 'ok' : 'warn');
  } catch(e) {
    resultEl.textContent = 'ERROR: ' + e.message;
    resultEl.style.color = '#f87171';
    updateProviderBadge('⚠ HTTP', 'b-ai-warn');
    if (!silentLog) extLog('Ping failed: ' + e.message, 'err');
  }
}

/* ════ RADIO BUILDER ════ */
const SKILL_OPTS  = ['Not Familiar','Beginner','Intermediate','Expert'];
const PERSON_OPTS = ['No Experience','Basic knowledge','Proficient','Advanced'];
const CEFR_OPTS   = ['Pre-A1','A1 Beginner','A2 Elementary','B1 Intermediate','B2 Upper Intermediate','C1 Advanced','C2 Proficient'];

function buildRadioGroup(containerId, qId, qText, opts) {
  const el = document.getElementById(containerId);
  if (!el) return;
  el.innerHTML = '<div class="sc !mb-0 !py-3"><p class="text-xs font-semibold text-gray-700 mb-3">' + qText + ' <span class="text-red-500">*</span></p><div class="flex flex-wrap gap-2">' +
    opts.map(o => '<label class="flex items-center gap-1.5 text-xs cursor-pointer bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 hover:border-purple-400 hover:bg-purple-50 transition-all skill-radio-lbl"><input type="radio" name="' + qId + '" value="' + o + '" class="accent-purple-700"> ' + o + '</label>').join('') +
    '</div></div>';
}

/* ════ SKILL DEFINITIONS ════ */
const SKILL_DEFS = {
  VPA: [['vpa-1','Managing and organizing schedules using Google Calendar or Microsoft Outlook'],['vpa-2','Drafting and organizing professional emails'],['vpa-3','Using Asana, Trello or Monday.com for task and project tracking'],['vpa-4','Creating Microsoft PowerPoint or Google Slides presentations'],['vpa-5','Coordinating itineraries and planning business trips'],['vpa-6','Using Microsoft Excel or Google Sheets for data entry and analysis']],
  HVA: [['hva-1','Using EMR/EHR systems (Athena, ModMed, Epic, Cerner)'],['hva-2','Managing appointment scheduling and coordinating patients'],['hva-3','Medical billing and medical coding (CPT codes, ICD-10)'],['hva-4','Understanding and complying with HIPAA regulations'],['hva-5','Processing insurance claims for medical services'],['hva-6','Handling prior authorizations and verifying patient insurance coverage']],
  HOP: [['hop-1','Processing and following up on medical claims'],['hop-2','Managing and organizing medical records'],['hop-3','Medical transcription with accuracy and speed'],['hop-4','Understanding and applying Revenue Cycle Management (RCM) processes'],['hop-5','Auditing and reviewing medical codes (ICD-10 and CPT)']],
  MVA: [['mva-1','Setting up and monitoring PPC campaigns (Google Ads, Meta Ads)'],['mva-2','Creating lead magnets and landing pages'],['mva-3','Performing basic SEO and keyword research'],['mva-4','Setting up and managing email marketing automation tools (Mailchimp, HubSpot)'],['mva-5','Scheduling and analyzing social media content performance']],
  HRO: [['hro-1','Managing HRIS platforms'],['hro-2','Administering payroll and employee benefits cycles'],['hro-3','Managing employee onboarding and offboarding processes'],['hro-4','Ensuring HR compliance and preparing legal reports'],['hro-5','Supporting performance management cycles']],
  MGR: [['mgr-1','Developing go-to-market strategies for new products or services'],['mgr-2','Allocating marketing budgets and analyzing ROI'],['mgr-3','Using analytics platforms (Google Analytics, Looker Studio)'],['mgr-4','Leading and mentoring marketing teams'],['mgr-5','Defining and refining brand positioning and messaging']],
  ACM: [['acm-1','Building and maintaining strong client relationships'],['acm-2','Identifying upsell and cross-sell opportunities'],['acm-3','Using CRM platforms such as HubSpot'],['acm-4','Strategic account planning and conducting Quarterly Business Reviews (QBRs)'],['acm-5','Negotiating and renewing contracts']],
  SDR: [['sdr-1','Outbound prospecting through cold calls or emails'],['sdr-2','Qualifying leads using structured frameworks'],['sdr-3','Handling objections and communicating with gatekeepers'],['sdr-4','Using sales engagement platforms (e.g. HubSpot)'],['sdr-5','Meeting and exceeding monthly or quarterly quotas']],
};

const PERSONALITY_DEFS = [
  ['p-1','How confident are you in spotting errors in documents or data?'],
  ['p-2','How comfortable are you with organizing tasks and meeting deadlines?'],
  ['p-3','How skilled are you at handling difficult conversations professionally?'],
  ['p-4','How well do you adapt your communication based on who you\'re speaking to?'],
  ['p-5','How effectively do you prioritize tasks when managing multiple responsibilities?'],
  ['p-6','How do you handle unexpected problems in your work?'],
  ['p-7','How familiar are you with using digital tools (e.g., calendars, task managers) for organization?'],
  ['p-8','How comfortable are you with troubleshooting basic technical issues at work?'],
];

function initStaticQuestions() {
  const pCont = document.getElementById('personality-questions');
  if (pCont) {
    pCont.innerHTML = '';
    PERSONALITY_DEFS.forEach(([id,q]) => { const div = document.createElement('div'); div.id = 'pq-' + id; pCont.appendChild(div); buildRadioGroup('pq-' + id, id, q, PERSON_OPTS); });
  }
  const rdCont = document.getElementById('rg-reading');
  const liCont = document.getElementById('rg-listening');
  if (rdCont) rdCont.innerHTML = CEFR_OPTS.map(o => '<label class="flex items-center gap-2 text-xs cursor-pointer hover:text-purple-700"><input type="radio" name="eng-reading" value="' + o + '" class="accent-purple-700"> ' + o + '</label>').join('');
  if (liCont) liCont.innerHTML = CEFR_OPTS.map(o => '<label class="flex items-center gap-2 text-xs cursor-pointer hover:text-purple-700"><input type="radio" name="eng-listening" value="' + o + '" class="accent-purple-700"> ' + o + '</label>').join('');
}

function onRoleChange(role) {
  document.querySelectorAll('.skills-block').forEach(b => b.classList.add('hidden'));
  const noneEl = document.getElementById('skills-NONE');
  if (!role || !SKILL_DEFS[role]) { if (noneEl) noneEl.classList.remove('hidden'); return; }
  if (noneEl) noneEl.classList.add('hidden');
  const block = document.getElementById('skills-' + role);
  if (block) {
    block.classList.remove('hidden');
    SKILL_DEFS[role].forEach(([id,q], i) => buildRadioGroup('sq-' + role.toLowerCase() + '-' + (i+1), 'sk-' + id, q, SKILL_OPTS));
  }
}

/* ════ SUBMIT CORREGIDO CON REDIRECCIÓN ════ */
async function submitApplication() {
  const btn = event?.target;
  if (btn) { btn.disabled = true; btn.textContent = 'Submitting...'; }
  
  const formData = {};
  document.querySelectorAll('.inp, input[type=text], input[type=email], input[type=url], input[type=number], input[type=tel], textarea, select').forEach(el => { 
    if (el.id && el.value) formData[el.id] = el.value; 
  });
  
  document.querySelectorAll('input[type=radio]:checked').forEach(r => { 
    formData['radio_' + r.name] = r.value; 
  });
  
  formData.session_token = SESSION_TOKEN;
  formData.submitted_at = new Date().toISOString();

  try {
    const resp = await fetch('save-progress.php', { 
      method:'POST', 
      headers:{'Content-Type':'application/json'}, 
      body: JSON.stringify({action:'submit', data:formData}) 
    });
    
    const j = await resp.json();
    
    if (j.status === 'ok' || j.token) {
      // 1. Mostrar mensaje de éxito en la web
      document.getElementById('form-wrap').innerHTML = `
        <div class="sc border-t-4 border-green-500 text-center py-10">
          <div class="text-5xl mb-4">🎉</div>
          <h2 class="text-2xl font-black text-green-700 mb-2">Application Submitted!</h2>
          <p class="text-gray-500 mb-4">Redirecting you back to WhatsApp to finish...</p>
        </div>`;

      // 2. Configurar la redirección a WhatsApp
      // IMPORTANTE: Coloca tu número de WhatsApp del bot aquí (Solo números con código de país)
      const BOT_PHONE = "573103083169"; 
      const WA_MESSAGE = encodeURIComponent("termine mi postulacion");
      const waUrl = `https://wa.me/${BOT_PHONE}?text=${WA_MESSAGE}`;

      // 3. Redirigir después de 2 segundos para que el usuario vea el éxito
      setTimeout(() => {
        window.location.href = waUrl;
      }, 2000);

    } else {
      if (btn) { btn.disabled = false; btn.textContent = 'Submit Application'; }
      alert('Submission error: ' + (j.error || 'Unknown'));
    }
  } catch(e) {
    if (btn) { btn.disabled = false; btn.textContent = 'Submit Application'; }
    alert('Network error: ' + e.message);
  }
}

function onCountryChange(country) {
  const postalLabel = document.getElementById('lbl-postal');
  const postalInput = document.getElementById('f-postal');
  
  if (country === 'Colombia') {
    postalLabel.classList.remove('req'); // Opcional en Colombia
    postalInput.placeholder = "Optional for Colombia";
  } else {
    postalLabel.classList.add('req');    // Obligatorio en el resto
    postalInput.placeholder = "Required for your country";
  }
  GSD.loadCities(country); // Mantener tu carga de ciudades existente
}

/* ════ UTILS ════ */
function countFilled(d) { return Object.values(d).filter(v => v && String(v).trim()).length; }
function esc(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* ════ INIT ════ */
document.addEventListener('DOMContentLoaded', () => {
  initLocationSelectors();
  initAIStatus();
  initStaticQuestions();

  // Check if returning from review.php with a token (Re-record flow)
  const urlParams = new URLSearchParams(window.location.search);
  const resumeToken = urlParams.get('resume') || sessionStorage.getItem('gsd_resume_token');
  
  if (resumeToken) {
    // Restore session from previous recording
    sessionStorage.removeItem('gsd_resume_token');
    setToken(resumeToken);
    document.getElementById('landing-screen').style.display = 'none';
    document.getElementById('gate').classList.remove('hidden');
    document.getElementById('token-pill').classList.remove('hidden');
    document.getElementById('token-pill').classList.add('flex');
    document.getElementById('tok-disp').textContent = resumeToken;
    
    // Optionally load saved form data from localStorage
    try {
      const savedData = JSON.parse(localStorage.getItem('gsd_app_data') || '{}');
      if (savedData.name) {
        // Restore form fields if data exists
        ['f-name','f-email','f-phone','f-whatsapp','f-country','f-city','f-linkedin',
         'f-salary','f-avail','f-sum','f-skills','f-edu-level','f-deg1','f-ins1',
         'f-exp-yrs','f-co1','f-jt1','f-position','f-referral','f-lang'].forEach(id => {
          const el = document.getElementById(id);
          if (el && savedData[id.replace('f-','')]) {
            el.value = savedData[id.replace('f-','')];
          }
        });
      }
    } catch(e) { /* ignore */ }
  }
});

window.GSD = {
  doNewSession, doResume, runExtraction, pickFile,
  toggleExtPanel, switchExtTab, copyExtJSON, clearLog,
  nextStep, prevStep, loadCities, pingProxy,
  onRoleChange, submitApplication
};

})();
</script>
<script src="assets/js/step7-helpers.js"></script>
<script src="assets/js/video-step7.js"></script>
</body>
</html>
