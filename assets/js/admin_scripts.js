// assets/js/admin_scripts.js

// ============================================================
// 1. VARIABLES GLOBALES & CONFIGURACIÓN
// ============================================================
let currentId = null;
let modelsLoaded = false;
let isAnalyzing = false;
let detectionInterval;
let myChart = null; 
let myRadar = null;
const video = document.getElementById('main-video');

// Variables Biométricas
let sessionData = []; 
let blinkCounter = 0; 
let isBlinking = false;

// Capas Visuales (HUD)
const visualLayers = { emotions: true, landmarks: false, demographics: true, attention: true, box: false };

// Toggle de botones HUD
function toggleLayer(layer) {
    visualLayers[layer] = !visualLayers[layer];
    const btn = document.getElementById(`btn-layer-${layer}`);
    if(btn) visualLayers[layer] ? btn.classList.add('active') : btn.classList.remove('active');
}

// ============================================================
// 2. FILTRO Y ASIGNACIÓN DE CARGO
// ============================================================
function filterCandidates() {
    const term = document.getElementById('search-input').value.toLowerCase();
    document.querySelectorAll('.candidate-item').forEach(item => {
        const name = item.getAttribute('data-name');
        item.style.display = name.includes(term) ? 'block' : 'none';
    });
}

function assignJob() {
    const selector = document.getElementById('job-selector');
    if (!selector || !currentId || !selector.value) return;

    // UI Feedback
    const matchDisplay = document.getElementById('match-score-display');
    const aiBox = document.getElementById('ai-analysis-content');
    const marker = document.getElementById('match-marker');

    if (matchDisplay) matchDisplay.innerHTML = '<span class="text-sm animate-pulse">...</span>';
    if (aiBox) aiBox.innerHTML = '<div class="h-full flex flex-col items-center justify-center text-purple-500 animate-pulse gap-3 p-6"><span class="text-3xl">✨</span><div class="text-center"><p class="font-bold text-sm">Generating Analysis...</p></div></div>';
    if (marker) marker.style.left = "0%";

    fetch('api/assign_job.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ candidate_id: currentId, job_id: selector.value })
    })
    .then(async response => {
        const text = await response.text();
        try { return JSON.parse(text); } 
        catch (e) { throw new Error("Server Error: " + text.substring(0, 100)); }
    })
    .then(res => {
        if (res.status === 'success' && res.data) {
            updateUIWithAnalysis(res.data);
            // Actualizar Memoria Local
            const idx = candidates.findIndex(c => c.id == currentId);
            if (idx > -1) {
                candidates[idx].job_id = selector.value;
                candidates[idx].match_score = res.data.match_score;
                candidates[idx].ai_analysis = res.data.ai_analysis;
                candidates[idx].match_reasoning = res.data.match_reasoning;
                candidates[idx].english_level = res.data.english_level;
                candidates[idx].english_score = res.data.english_score;
            }
        } else {
            throw new Error(res.message || "Unknown Error");
        }
    })
    .catch(err => {
        console.error(err);
        if(aiBox) aiBox.innerHTML = `<div class="p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-xs"><strong>Error:</strong> ${err.message}</div>`;
    });
}

// --- FUNCIÓN AUXILIAR UI (IMPORTANTE PARA LOAD Y ASSIGN) ---
function updateUIWithAnalysis(data) {
    const score = parseFloat(data.match_score || 0);
    
    // Match Score
    const display = document.getElementById('match-score-display');
    if(display) {
        display.innerText = parseInt(score) + "%";
        display.className = "text-3xl font-black w-16 text-right transition-colors duration-500 " + (score >= 80 ? "text-green-600" : (score >= 50 ? "text-yellow-500" : "text-red-500"));
    }

    // Marker
    const marker = document.getElementById('match-marker');
    if (marker) {
        const pos = Math.max(0, Math.min(96, score));
        marker.style.left = pos + "%";
    }

    // Textos
    const reason = document.getElementById('match-reason');
    if(reason) reason.innerText = data.match_reasoning || "Calculated.";
    
    const aiBox = document.getElementById('ai-analysis-content');
    if(aiBox) aiBox.innerHTML = data.ai_analysis;

    // English Level
    const engLvl = data.english_level || "N/A";
    const engScr = parseFloat(score > 0 ? (data.english_score || 0) : 0);
    const engDisplay = document.getElementById('eng-level-display');
    const engScoreDisplay = document.getElementById('eng-score-display');
    const engCircle = document.getElementById('eng-circle');

    if (engDisplay) {
        engDisplay.innerText = engLvl;
        if(engScoreDisplay) engScoreDisplay.innerText = `(${engScr}%)`;
        
        engDisplay.className = "text-xl font-black transition-colors";
        if(['C1','C2'].includes(engLvl)) engDisplay.classList.add('text-green-600');
        else if(['B2'].includes(engLvl)) engDisplay.classList.add('text-blue-600');
        else if(['B1'].includes(engLvl)) engDisplay.classList.add('text-yellow-600');
        else engDisplay.classList.add('text-gray-400');
        
        if (engCircle) {
            const circumference = 126;
            const offset = circumference - (engScr / 100) * circumference;
            engCircle.style.strokeDashoffset = offset;
            engCircle.setAttribute('stroke', ['C1','C2'].includes(engLvl) ? '#16a34a' : (['B2'].includes(engLvl) ? '#2563eb' : (['B1'].includes(engLvl) ? '#ca8a04' : '#9ca3af')));
        }
    }
}

// ============================================================
// 3. LOAD CANDIDATE (CORE FUNCTION)
// ============================================================
async function loadCandidate(id) {
    currentId = id;
    stopAIAnalysis();

    if (!candidates || !Array.isArray(candidates)) return;
    const c = candidates.find(x => x.id == id);
    if (!c) return;

    // A. UI RESET
    try {
        document.getElementById('empty-state').classList.add('hidden');
        document.getElementById('detail-view').classList.remove('hidden');
        document.querySelectorAll('.candidate-item').forEach(el => el.classList.remove('bg-purple-50', 'border-l-4', 'border-gsd-primary'));
        const activeItem = document.getElementById('item-' + id);
        if (activeItem) activeItem.classList.add('bg-purple-50', 'border-l-4', 'border-gsd-primary');
    } catch (e) {}

    // B. DATOS BÁSICOS
    try {
        document.getElementById('detail-name').innerText = c.name || "Unknown";
        document.getElementById('detail-email').innerText = c.email || "--";
        document.getElementById('avatar-initial').innerText = (c.name || "?").charAt(0).toUpperCase();
        document.getElementById('transcript-text').innerText = c.transcript || "No transcript available.";
        
        if (video && c.video_filename) {
            video.src = c.video_filename;
            video.load();
        }
    } catch (e) {}

    // C. JOB MATCH & AI UI
    try {
        const jobSelect = document.getElementById('job-selector');
        if (jobSelect) jobSelect.value = c.job_id || "";

        updateUIWithAnalysis({
            match_score: c.match_score,
            match_reasoning: c.match_reasoning,
            ai_analysis: c.ai_analysis || "<div class='flex flex-col items-center justify-center h-full text-gray-400 italic'><span class='text-2xl mb-2'>🤖</span><p>Select a position...</p></div>",
            english_level: c.english_level,
            english_score: c.english_score
        });
    } catch (e) {}

    // D. SCORES
    try {
        const rawScore = parseFloat(c.sentiment_score || 0);
        const aiScore = rawScore.toFixed(1);
        
        const displayScoreEl = document.getElementById('display-ai-score');
        if(displayScoreEl) {
            displayScoreEl.innerText = aiScore;
            displayScoreEl.className = "text-2xl font-bold " + (rawScore >= 60 ? "text-green-600" : (rawScore >= 40 ? "text-yellow-600" : "text-red-600"));
        }

        const manualInput = document.getElementById('manual-score');
        if(manualInput) manualInput.value = c.manual_score ? parseFloat(c.manual_score) : aiScore;

        const badge = document.getElementById('sentiment-badge');
        if(badge) {
            badge.innerText = `AI Score: ${aiScore}/100`;
            badge.className = "text-[10px] font-bold px-2 py-0.5 rounded bg-white border border-gray-200 text-gray-500 shadow-sm";
        }
    } catch (e) {}

    // E. ASYNC MODULES
    setTimeout(() => {
        try { setupCVButton(c); } catch (e) {}
        try { fetchNotes(id); } catch (e) {}
        try { renderBiometrics(c); } catch (e) {}
        try { fetchEmotionStats(id); } catch (e) {}
    }, 50);
}

// ============================================================
// 4. CV FUNCTIONS
// ============================================================
function setupCVButton(c) {
    const btnUpload = document.getElementById('btn-upload-cv');
    const displayCV = document.getElementById('cv-display');
    const cvLink = document.getElementById('cv-link');
    if(!btnUpload || !displayCV) return;

    btnUpload.classList.remove('hidden');
    displayCV.classList.add('hidden');
    if(cvLink) cvLink.href = "#";

    if (c.cv_filename && c.cv_filename !== "") {
        if(cvLink) cvLink.href = c.cv_filename.includes('uploads') ? c.cv_filename : 'uploads/cvs/' + c.cv_filename;
        btnUpload.classList.add('hidden');
        displayCV.classList.remove('hidden');
        displayCV.classList.add('flex');
    }
}

function triggerCvUpload() { document.getElementById('cv-upload-input').click(); }

function uploadCV() {
    const input = document.getElementById('cv-upload-input');
    if (input.files.length === 0 || !currentId) return;
    const fd = new FormData();
    fd.append('cv_file', input.files[0]);
    fd.append('candidate_id', currentId);
    
    document.getElementById('btn-upload-cv').classList.add('hidden');
    document.getElementById('cv-loading').classList.remove('hidden');
    
    fetch('api/upload_cv.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => {
        document.getElementById('cv-loading').classList.add('hidden');
        if (res.status === 'success') {
            const idx = candidates.findIndex(c => c.id == currentId);
            if(idx > -1) candidates[idx].cv_filename = res.cv_url;
            
            // Recalcular UI
            const c = candidates[idx];
            setupCVButton(c);
            if(document.getElementById('job-selector').value) assignJob();
        } else {
            document.getElementById('btn-upload-cv').classList.remove('hidden');
            alert("Error: " + res.message);
        }
    });
}

// ============================================================
// 5. NOTES & MANUAL SCORE
// ============================================================
function fetchNotes(id) {
    const cont = document.getElementById('notes-container');
    if(!cont) return;
    cont.innerHTML = '<div class="text-center text-xs text-gray-400 mt-4">Loading...</div>';
    fetch(`api/notes_handler.php?id=${id}`).then(r => r.json()).then(data => {
        cont.innerHTML = "";
        if (!Array.isArray(data) || data.length === 0) { cont.innerHTML = '<div class="text-center text-xs text-gray-300 mt-4">No notes yet.</div>'; return; }
        data.forEach(n => {
            cont.innerHTML += `<div class="bg-white p-3 rounded-lg border border-gray-100 shadow-sm mb-2 animate-fade-in"><div class="flex justify-between mb-1"><span class="font-bold text-xs text-gsd-primary">${n.author}</span><span class="text-[9px] text-gray-400">${new Date(n.created_at).toLocaleString()}</span></div><p class="text-xs text-gray-600">${n.note_text}</p></div>`;
        });
    });
}

function saveAllChanges() {
    if (!currentId) return;
    const score = document.getElementById('manual-score').value;
    const note = document.getElementById('new-note-text').value;
    const author = document.getElementById('note-author').value;
    const btn = event.target;
    btn.innerText = "SAVING..."; btn.disabled = true;

    fetch('api/save_evaluation.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id: currentId, notes: "", score: score }) });
    if(note.trim()) {
        fetch('api/notes_handler.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ candidate_id: currentId, note: note, author: author }) })
        .then(r=>r.json()).then(res=>{ if(res.status==='success') { document.getElementById('new-note-text').value=""; fetchNotes(currentId); } });
    }
    setTimeout(() => { btn.innerText = "POST NOTE"; btn.disabled = false; }, 1000);
}

document.addEventListener('DOMContentLoaded', () => {
    const manualInput = document.getElementById('manual-score');
    if (manualInput) {
        manualInput.addEventListener('change', () => {
            if (!currentId) return;
            const newScore = manualInput.value;
            manualInput.classList.add('bg-yellow-50', 'border-yellow-400');
            fetch('api/save_evaluation.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: currentId, score: newScore, mode: 'score_only' }) })
            .then(r => r.json()).then(res => {
                if (res.status === 'success') {
                    manualInput.classList.replace('bg-yellow-50', 'bg-green-50'); manualInput.classList.replace('border-yellow-400', 'border-green-500');
                    const idx = candidates.findIndex(c => c.id == currentId); if(idx > -1) candidates[idx].manual_score = newScore;
                    setTimeout(() => { manualInput.className = "w-full text-2xl font-bold text-center text-gsd-dark outline-none border-b border-transparent hover:border-gray-200 transition"; }, 1500);
                }
            });
        });
    }
});

// ============================================================
// 6. FACE API & BIOMETRICS
// ============================================================
async function loadModels() {
    if (modelsLoaded) return true;
    document.getElementById('model-loading').classList.remove('hidden');
    try {
        await Promise.all([
            faceapi.nets.ssdMobilenetv1.loadFromUri('assets/models'),
            faceapi.nets.faceLandmark68Net.loadFromUri('assets/models'),
            faceapi.nets.faceExpressionNet.loadFromUri('assets/models'),
            faceapi.nets.ageGenderNet.loadFromUri('assets/models')
        ]);
        modelsLoaded = true;
        document.getElementById('model-loading').classList.add('hidden');
        return true;
    } catch(e) { return false; }
}

async function toggleAIAnalysis() {
    const panel = document.getElementById('ai-layers-panel');
    if (isAnalyzing) { stopAIAnalysis(); if(panel) panel.classList.remove('visible'); return; }
    if (await loadModels()) {
        isAnalyzing = true;
        sessionData = []; blinkCounter = 0;
        document.getElementById('btn-toggle-ai').classList.replace('bg-black/60', 'bg-gsd-vibrant');
        document.getElementById('btn-toggle-ai').innerHTML = "<span class='w-2 h-2 rounded-full bg-green-500 shadow-md'></span><span>STOP & SAVE</span>";
        if(panel) panel.classList.add('visible');
        startDetectionLoop();
    }
}

function stopAIAnalysis() {
    isAnalyzing = false;
    clearInterval(detectionInterval);
    if (document.querySelector('canvas.overlay')) document.querySelector('canvas.overlay').remove();
    const btn = document.getElementById('btn-toggle-ai');
    if (btn) {
        btn.classList.replace('bg-gsd-vibrant', 'bg-black/60');
        btn.innerHTML = "<span>ACTIVATE AI</span>";
    }
    if(sessionData.length > 0 && currentId) saveBulkBiometrics();
}

function saveBulkBiometrics() {
    fetch('api/save_biometrics.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ candidate_id: currentId, analysis_data: sessionData }) })
    .then(r => r.json()).then(res => {
        const btn = document.getElementById('btn-toggle-ai');
        if(btn) btn.innerHTML = "<span>✅ SAVED</span>";
        const idx = candidates.findIndex(c => c.id == currentId);
        if(idx > -1) {
            candidates[idx].biometric_json = JSON.stringify(sessionData);
            renderBiometrics(candidates[idx]);
        }
        setTimeout(() => { if(btn) btn.innerHTML = "<span>ACTIVATE AI</span>"; }, 2000);
    });
}

// Biometric Logic
function getDistance(p1, p2) { return Math.sqrt(Math.pow(p1.x - p2.x, 2) + Math.pow(p1.y - p2.y, 2)); }
function getEAR(eye) { return (getDistance(eye[1], eye[5]) + getDistance(eye[2], eye[4])) / (2.0 * getDistance(eye[0], eye[3])); }
function getMAR(mouth) { return (getDistance(mouth[13], mouth[19]) + getDistance(mouth[14], mouth[18]) + getDistance(mouth[15], mouth[17])) / (2.0 * getDistance(mouth[12], mouth[16])); }
function getHeadPose(nose, jaw) {
    const ratio = Math.abs(nose[3].x - jaw[0].x) / (Math.abs(nose[3].x - jaw[16].x) + 0.01);
    if (ratio < 0.6) return "Right ▶"; if (ratio > 1.4) return "◀ Left"; return "Center";
}

async function startDetectionLoop() {
    const canvas = faceapi.createCanvasFromMedia(video);
    canvas.className = 'overlay';
    document.querySelector('.video-container').append(canvas);
    const displaySize = { width: video.clientWidth, height: video.clientHeight };
    faceapi.matchDimensions(canvas, displaySize);

    window.addEventListener('resize', () => faceapi.matchDimensions(canvas, { width: video.clientWidth, height: video.clientHeight }));

    detectionInterval = setInterval(async () => {
        if (video.paused) return;

        const detections = await faceapi.detectAllFaces(video).withFaceLandmarks().withFaceExpressions().withAgeAndGender();
        const resized = faceapi.resizeResults(detections, displaySize);
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        if (resized.length > 0) {
            const d = resized[0];
            const landmarks = d.landmarks;
            const expr = d.expressions;
            const box = d.detection.box;
            const dom = Object.keys(expr).sort((a, b) => expr[b] - expr[a])[0];
            
            const attention = getHeadPose(landmarks.getNose(), landmarks.getJawOutline());
            const leftEye = landmarks.getLeftEye();
            const rightEye = landmarks.getRightEye();
            const ear = (getEAR(leftEye) + getEAR(rightEye)) / 2;
            const isSpeaking = getMAR(landmarks.getMouth()) > 0.1;
            if (ear < 0.25) { if(!isBlinking) { blinkCounter++; isBlinking=true; } } else isBlinking=false;

            // HUD Draw
            if (visualLayers.landmarks) faceapi.draw.drawFaceLandmarks(canvas, resized, { drawLines: true, color: 'rgba(0,255,255,0.2)', lineWidth: 1 });
            if (visualLayers.box) { ctx.strokeStyle = 'rgba(255,255,255,0.3)'; ctx.lineWidth = 1; ctx.strokeRect(box.x, box.y, box.width, box.height); }
            if (visualLayers.emotions) {
                const txt = `${dom.toUpperCase()} ${(expr[dom]*100).toFixed(0)}%`;
                ctx.font = "bold 12px Roboto";
                const txtW = ctx.measureText(txt).width + 20;
                const x = box.x + (box.width/2) - (txtW/2);
                const y = box.y - 30;
                ctx.fillStyle = getEmotionColor(dom);
                roundRect(ctx, x, y, txtW, 24, 12, true, false);
                ctx.fillStyle = "#fff"; ctx.textAlign = "center"; ctx.fillText(txt, box.x + (box.width/2), y + 16);
            }
            if (visualLayers.demographics) {
                const label = `${d.gender==='male'?'♂':'♀'} ${Math.round(d.age)}`;
                ctx.fillStyle = "rgba(90, 57, 136, 0.8)";
                ctx.fillRect(box.x + box.width - 45, box.y - 10, 45, 18);
                ctx.fillStyle = "#fff"; ctx.font = "bold 11px Arial"; ctx.fillText(label, box.x + box.width - 22, box.y + 3);
            }
            if (visualLayers.attention) {
                const attText = `${isSpeaking ? '🗣️' : '😶'} | ${attention} | Blink: ${blinkCounter}`;
                const attW = 180;
                const attX = box.x + (box.width/2) - (attW/2);
                const attY = box.y + box.height + 15;
                ctx.fillStyle = "rgba(0,0,0,0.7)";
                roundRect(ctx, attX, attY, attW, 24, 5, true, false);
                ctx.fillStyle = "#ccc"; ctx.textAlign = "center"; ctx.fillText(attText, box.x + (box.width/2), attY + 16);
            }

            sessionData.push({ time: video.currentTime.toFixed(2), emotion: dom, confidence: expr[dom].toFixed(2), attention: attention, is_speaking: isSpeaking, blink_count: blinkCounter });
        }
    }, 100);
}

// Charting
function renderBiometrics(candidate) {
    const panel = document.getElementById('biometric-panel');
    if (!panel) return;
    
    if (!candidate.biometric_json) { panel.classList.add('hidden'); return; }
    panel.classList.remove('hidden');

    let dataPoints = [];
    try { dataPoints = typeof candidate.biometric_json === 'string' ? JSON.parse(candidate.biometric_json) : candidate.biometric_json; } catch(e) { return; }

    if (!dataPoints || dataPoints.length === 0) return;

    const focused = dataPoints.filter(d => d.attention && d.attention.includes('Center')).length;
    document.getElementById('score-attention').innerText = Math.round((focused/dataPoints.length)*100) + "%";
    
    const blinks = dataPoints[dataPoints.length-1].blink_count || 0;
    const duration = dataPoints.length / 10; 
    let comp = 100;
    if(duration > 0) {
        const bpm = (blinks / duration) * 60;
        if(bpm > 20) comp = Math.max(0, 100 - ((bpm - 20) * 2));
    }
    document.getElementById('score-blinks').innerText = blinks;
    document.getElementById('score-composure').innerText = Math.round(comp) + "%";
    
    const speaking = dataPoints.filter(d => d.is_speaking).length;
    document.getElementById('score-speaking').innerText = Math.round((speaking/dataPoints.length)*100) + "%";

    const canvasLine = document.getElementById('timelineChart');
    if(canvasLine) {
        const ctx = canvasLine.getContext('2d');
        if (myChart) myChart.destroy();
        const labels = dataPoints.filter((d,i)=>i%10===0).map(d=>d.time);
        const happy = dataPoints.filter((d,i)=>i%10===0).map(d=>d.emotion==='happy'?d.confidence:0);
        const stress = dataPoints.filter((d,i)=>i%10===0).map(d=>['fearful','sad','angry'].includes(d.emotion)?d.confidence:0);
        myChart = new Chart(ctx, { type: 'line', data: { labels: labels, datasets: [ { label: 'Positivity', data: happy, borderColor: '#4ade80', borderWidth:1.5, pointRadius:0 }, { label: 'Stress', data: stress, borderColor: '#f87171', borderWidth:1.5, pointRadius:0 } ] }, options: { plugins:{legend:{display:false}}, scales:{x:{display:false}, y:{display:false}} } });
    }

    const canvasRadar = document.getElementById('radarChart');
    if(canvasRadar) {
        const ctxR = canvasRadar.getContext('2d');
        if (myRadar) myRadar.destroy();
        const sums = { happy:0, neutral:0, surprised:0, sad:0, angry:0, fearful:0 };
        dataPoints.forEach(d => { if(sums[d.emotion]!==undefined) sums[d.emotion]++; });
        const radarData = Object.values(sums).map(v => (v/dataPoints.length)*100);
        myRadar = new Chart(ctxR, { type: 'radar', data: { labels: Object.keys(sums).map(k=>k.charAt(0).toUpperCase()+k.slice(1)), datasets: [{ data: radarData, backgroundColor:'rgba(140,82,255,0.2)', borderColor:'#8C52FF', borderWidth:1 }] }, options: { plugins:{legend:{display:false}}, scales:{r:{ticks:{display:false}}} } });
    }
}

// Helper Utils
function roundRect(ctx, x, y, width, height, radius, fill, stroke) { if (typeof stroke === 'undefined') stroke = true; if (typeof radius === 'undefined') radius = 5; ctx.beginPath(); ctx.moveTo(x + radius, y); ctx.lineTo(x + width - radius, y); ctx.quadraticCurveTo(x + width, y, x + width, y + radius); ctx.lineTo(x + width, y + height - radius); ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height); ctx.lineTo(x + radius, y + height); ctx.quadraticCurveTo(x, y + height, x, y + height - radius); ctx.lineTo(x, y + radius); ctx.quadraticCurveTo(x, y, x + radius, y); ctx.closePath(); if (fill) ctx.fill(); if (stroke) ctx.stroke(); }
function getEmotionColor(e) { const c = { neutral: 'rgba(156,163,175,0.9)', happy: 'rgba(34,197,94,0.9)', sad: 'rgba(59,130,246,0.9)', angry: 'rgba(239,68,68,0.9)', fearful: 'rgba(168,85,247,0.9)', disgusted: 'rgba(234,179,8,0.9)', surprised: 'rgba(236,72,153,0.9)' }; return c[e] || 'rgba(0,0,0,0.8)'; }
function fetchEmotionStats(id) {
    const wrapper = document.getElementById('emotion-stats-wrapper');
    const container = document.getElementById('emotion-bars');
    if (!wrapper || !container) return;
    wrapper.classList.add('hidden'); container.innerHTML = '';
    fetch(`api/get_emotion_stats.php?id=${id}`).then(r => r.json()).then(res => {
        if (res.status === 'success' && res.data.length > 0) {
            wrapper.classList.remove('hidden');
            res.data.forEach(s => {
                const color = getEmotionColor(s.emotion); // Reutilizamos la función de color
                container.innerHTML += `<div class="flex items-center text-xs"><div class="w-20 capitalize font-bold text-gray-500">${s.emotion}</div><div class="flex-1 h-2 bg-gray-100 rounded-full mx-2"><div class="h-full" style="background-color:${color.replace('0.9','1')}; width:${s.percent}%"></div></div><div class="w-8 text-right font-mono text-gray-400">${s.percent}%</div></div>`;
            });
        }
    }).catch(e => console.log("No stats"));
}

video.addEventListener('loadedmetadata', () => { if (isAnalyzing) stopAIAnalysis(); });
video.addEventListener('ended', () => { if (isAnalyzing) stopAIAnalysis(); });