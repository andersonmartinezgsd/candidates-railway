/**
 * GSD — Candidates Auto-Fill Snippet
 * Pega este bloque ANTES del </body> en candidates/apply/index.php
 *
 * Parámetros que lee desde la URL:
 *   name, first, last, email, phone, salary, english, city, role, token, summary, src
 *
 * Ejemplo de URL generada por Gessi:
 *   https://candidates.gsdoutsource.com/apply/index.php?name=Sample+Candidate
 *     &email=candidate%40example.com&phone=%2B570000000000
 *     &salary=Negotiable&english=B2&city=Bogota&role=Virtual+Assistant
 *     &token=GSD-AB12-CD34&summary=Sample+candidate+summary&src=whatsapp
 */
(function () {
  'use strict';

  // ── 1. Leer parámetros de la URL ─────────────────────────────
  const p = new URLSearchParams(window.location.search);
  const get = (k) => (p.get(k) || '').trim();

  const DATA = {
    name    : get('name')    || (get('first') + ' ' + get('last')).trim(),
    first   : get('first'),
    last    : get('last'),
    email   : get('email'),
    phone   : get('phone'),
    salary  : get('salary'),
    english : get('english'),
    city    : get('city'),
    role    : get('role'),
    token   : get('token'),
    summary : get('summary'),
    src     : get('src'),
  };

  // Si no hay ningún parámetro de Gessi, no hacer nada
  if (!DATA.name && !DATA.email) return;

  // ── 2. Mapeo de parámetros → IDs del formulario ──────────────
  // Ajusta los IDs si los de tu form son diferentes
  const FIELD_MAP = {
    name    : ['f-name', 'input[name="name"]',  'input[placeholder*="Name"]',  'input[placeholder*="name"]'],
    email   : ['f-email','input[name="email"]', 'input[type="email"]'],
    phone   : ['f-phone','input[name="phone"]', 'input[type="tel"]',            'input[placeholder*="phone"]', 'input[placeholder*="Phone"]'],
    salary  : ['f-salary','input[name="salary"]','input[placeholder*="salary"]','input[placeholder*="Salary"]','input[placeholder*="USD"]'],
    summary : ['f-sum',  'textarea[name="summary"]', 'textarea#summary', 'textarea'],
    token   : ['f-token','input[name="token"]', 'input[placeholder*="token"]',  'input[placeholder*="Token"]'],
  };

  // ── 3. Helper: rellenar campo por lista de selectores ────────
  function fill(selectors, value) {
    if (!value) return false;
    for (const sel of selectors) {
      const el = typeof sel === 'string'
        ? (document.getElementById(sel) || document.querySelector(sel))
        : sel;
      if (!el) continue;
      el.value = value;
      // Disparar eventos para que los frameworks (Vue, React, etc.) detecten el cambio
      ['input','change'].forEach(ev => el.dispatchEvent(new Event(ev, { bubbles: true })));
      el.classList.add('gessi-prefilled');
      return true;
    }
    return false;
  }

  // ── 4. Helper: seleccionar <option> por texto parcial ────────
  function selectByText(selEl, text) {
    if (!selEl || !text) return false;
    const t = text.toLowerCase();
    for (const opt of selEl.options) {
      if (opt.text.toLowerCase().includes(t) || opt.value.toLowerCase().includes(t)) {
        selEl.value = opt.value;
        selEl.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
      }
    }
    return false;
  }

  // ── 5. Rellenar campos de texto ──────────────────────────────
  function doFill() {
    fill(FIELD_MAP.name,    DATA.name);
    fill(FIELD_MAP.email,   DATA.email);
    fill(FIELD_MAP.phone,   DATA.phone);
    fill(FIELD_MAP.salary,  DATA.salary);
    fill(FIELD_MAP.summary, DATA.summary);
    fill(FIELD_MAP.token,   DATA.token);

    // Token pill / display (si tu página muestra el token en algún span)
    document.querySelectorAll('[id*="tok"], [id*="token"], [class*="token-pill"]').forEach(el => {
      if (el.tagName !== 'INPUT' && DATA.token) el.textContent = DATA.token;
    });

    // ── 6. Selector de posición ──────────────────────────────────
    // Mapeo de rol libre → opciones del select
    const roleMap = {
      'healthcare' : 'Healthcare Virtual Assistant',
      'hva'        : 'Healthcare Virtual Assistant',
      'sales'      : 'Sales / SDR / KSDR',
      'sdr'        : 'Sales / SDR / KSDR',
      'marketing'  : 'Marketing Specialist',
      'mva'        : 'Marketing Specialist',
      'virtual'    : 'Virtual Personal Assistant',
      'vpa'        : 'Virtual Personal Assistant',
      'it'         : 'Virtual Personal Assistant', // default para IT
    };

    const positionSel = document.getElementById('f-position')
      || document.querySelector('select[name="position"]')
      || document.querySelector('select');

    if (positionSel && DATA.role) {
      const roleLower = DATA.role.toLowerCase();
      // Intentar match directo primero
      if (!selectByText(positionSel, DATA.role)) {
        // Luego por palabras clave
        for (const [kw, label] of Object.entries(roleMap)) {
          if (roleLower.includes(kw)) {
            selectByText(positionSel, label);
            break;
          }
        }
      }
    }

    // ── 7. Banner "Pre-filled by Gessi" ─────────────────────────
    if (DATA.src === 'whatsapp' && DATA.name) {
      const banner = document.createElement('div');
      banner.id = 'gessi-banner';
      banner.style.cssText = [
        'position:fixed','bottom:20px','right:20px','z-index:9999',
        'background:linear-gradient(135deg,#5A3988,#8C52FF)',
        'color:#fff','padding:10px 16px','border-radius:12px',
        'font-size:12px','font-weight:700','box-shadow:0 4px 20px rgba(90,57,136,.5)',
        'display:flex','align-items:center','gap:8px','max-width:280px',
        'cursor:pointer','transition:all .2s',
      ].join(';');
      banner.innerHTML = '✅ <span>Pre-filled from your WhatsApp chat with Gessi!<br><span style="font-weight:400;font-size:10px;opacity:.8">Review your data and complete the form 🚀</span></span>';
      banner.onclick = () => banner.style.display = 'none';
      document.body.appendChild(banner);
      // Auto-ocultar en 8s
      setTimeout(() => { if (banner) banner.style.opacity = '0'; }, 8000);
      setTimeout(() => { if (banner) banner.remove(); }, 8600);
    }

    console.log('[Gessi] ✅ Auto-fill complete:', DATA);
  }

  // ── 8. Ejecutar cuando el DOM esté listo ────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', doFill);
  } else {
    // DOM ya cargado — pequeño delay para forms dinámicos (React, Vue, etc.)
    setTimeout(doFill, 400);
  }

  // ── 9. Estilos para campos pre-rellenados ────────────────────
  const style = document.createElement('style');
  style.textContent = `
    .gessi-prefilled {
      border-color: #8C52FF !important;
      background: #faf5ff !important;
      box-shadow: 0 0 0 3px rgba(140,82,255,.12) !important;
    }
    #gessi-banner:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(90,57,136,.6) !important; }
  `;
  document.head.appendChild(style);

})();
