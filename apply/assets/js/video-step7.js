/**
 * GSD VIDEO SYSTEM — Step 7 Complete v2
 * ═══════════════════════════════════════════════════════════════
 * 100% Browser-side processing (PHP pure server, no FFmpeg):
 *  1. MediaPipe Selfie Segmentation → background replacement
 *  2. MediaRecorder → records processed canvas (bg already applied)
 *  3. Web Speech API → real-time transcript + language detection
 *  4. face-api.js → facial expression analysis
 *  5. Sentiment scoring → keyword-based on transcript
 *  6. Upload: video blob + all files + JSON → upload-video.php
 *  7. Confirmation screen → candidate validates CV + video
 *  8. finalConfirm → notify-recruitment.php → email + G.Space
 *
 * SETUP IN index4.php:
 *  1. Add before </body>:   <script src="video-step7.js"></script>
 *  2. At the bottom of main JS block, replace:
 *       window.GSD = { doNewSession, doResume, ... }
 *     with:
 *       window.GSD = Object.assign(
 *         { doNewSession, doResume, runExtraction, pickFile,
 *           toggleExtPanel, switchExtTab, copyExtJSON, clearLog,
 *           nextStep, prevStep, loadCities, pingProxy,
 *           onRoleChange, submitApplication, goStep },
 *         typeof GSDVideo !== 'undefined' ? GSDVideo : {}
 *       );
 * ═══════════════════════════════════════════════════════════════
 */

const GSDVideo = (function () {
  'use strict';

  // Get base URL for API calls (handles subdirectory deployments)
  const getBaseUrl = () => {
    const path = window.location.pathname;
    const lastSlash = path.lastIndexOf('/');
    return lastSlash > 0 ? path.substring(0, lastSlash + 1) : './';
  };
  const BASE_URL = typeof GSD_BASE_URL !== 'undefined' ? GSD_BASE_URL : getBaseUrl();

  /* ─── STATE ─── */
  let mediaStream      = null;
  let mediaRecorder    = null;
  let recordedChunks   = [];
  let recordingTimer   = null;
  let recordingSeconds = 0;
  const MAX_SECONDS    = 45;
  let cameraRunning    = false;
  let cameraReady      = false;
  let selfieSegmentation = null;

  let faceEmotions = { happy:0, neutral:0, sad:0, angry:0, surprised:0, fearful:0, disgusted:0 };
  let faceFrames   = 0;
  let faceApiReady = false;
  let faceInterval = null;

  let recognition    = null;
  let fullTranscript = '';
  let speechActive   = false;
  let sentimentScore = 50;

  let finalVideoBlob = null;
  let videoAnalysis  = {};

  const $ = id => document.getElementById(id);

  /* ════════════════════════════════════
     1. INIT CAMERA + MEDIAPIPE
  ════════════════════════════════════ */
  async function initCamera() {
    const btn = $('btn-activate-cam');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="sp mr-2"></span> Activating AI…'; }

    try {
      mediaStream = await navigator.mediaDevices.getUserMedia({
        video: { width:{ideal:1280}, height:{ideal:720}, facingMode:'user' },
        audio: { echoCancellation:true, noiseSuppression:true, autoGainControl:true, sampleRate:48000 }
      });

      const vid = $('input_video');
      if (vid) { vid.srcObject = mediaStream; await vid.play().catch(()=>{}); }

      await initSegmentation();
      loadFaceApi().then(() => { faceApiReady = true; });

      cameraReady = true;
      if (window.GSDPhase) window.GSDPhase.activate();
      setStatus('Camera ready — click Start Recording when ready.', 'ok');

    } catch(err) {
      if (btn) { btn.disabled = false; btn.innerHTML = '⚡ Activate Camera & AI'; }
      setStatus(err.name === 'NotAllowedError'
        ? 'Camera permission denied — allow access in browser settings.'
        : 'Camera error: ' + err.message, 'err');
    }
  }

  async function initSegmentation() {
    if (typeof SelfieSegmentation === 'undefined') { startRawLoop(); return; }
    return new Promise(res => {
      selfieSegmentation = new SelfieSegmentation({
        locateFile: f => `https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/${f}`
      });
      selfieSegmentation.setOptions({ modelSelection: 1, selfieMode: true });
      selfieSegmentation.onResults(onSegmentResults);
      const vid = $('input_video');
      const cam = new Camera(vid, {
        onFrame: async () => {
          if (selfieSegmentation && cameraRunning) await selfieSegmentation.send({ image: vid }).catch(()=>{});
        },
        width: 1280, height: 720
      });
      cameraRunning = true;
      cam.start().then(res).catch(() => { startRawLoop(); res(); });
    });
  }

  function onSegmentResults(results) {
    const canvas = $('output_canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const bg  = $('bg-image');
    const W = results.image.width, H = results.image.height;
    canvas.width = W; canvas.height = H;

    // Offscreen: person with mask
    const off = document.createElement('canvas');
    off.width = W; off.height = H;
    const octx = off.getContext('2d');
    octx.drawImage(results.image, 0, 0, W, H);
    octx.globalCompositeOperation = 'destination-in';
    octx.drawImage(results.segmentationMask, 0, 0, W, H);

    // Background
    ctx.clearRect(0, 0, W, H);
    if (bg && bg.complete && bg.naturalWidth) {
      ctx.drawImage(bg, 0, 0, W, H);
    } else {
      const g = ctx.createLinearGradient(0, 0, W, H);
      g.addColorStop(0, '#2d1b4e'); g.addColorStop(1, '#0f0a1e');
      ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    }
    ctx.drawImage(off, 0, 0, W, H);

    // Watermark
    ctx.save(); ctx.font = 'bold 10px Inter,sans-serif';
    ctx.fillStyle = 'rgba(255,255,255,.22)'; ctx.textAlign = 'right';
    ctx.fillText('GSD Associates | Smart Recruitment', W - 12, H - 12); ctx.restore();
  }

  function startRawLoop() {
    cameraRunning = true;
    const canvas = $('output_canvas'), ctx = canvas.getContext('2d'), vid = $('input_video');
    (function loop() {
      if (!cameraRunning) return;
      if (vid && vid.videoWidth) {
        canvas.width = vid.videoWidth; canvas.height = vid.videoHeight;
        ctx.drawImage(vid, 0, 0);
        ctx.save(); ctx.font = 'bold 10px Inter,sans-serif';
        ctx.fillStyle = 'rgba(255,255,255,.22)'; ctx.textAlign = 'right';
        ctx.fillText('GSD Associates | Smart Recruitment', canvas.width-12, canvas.height-12); ctx.restore();
      }
      requestAnimationFrame(loop);
    })();
  }

  /* ════════════════════════════════════
     2. FACE-API LOAD
  ════════════════════════════════════ */
  async function loadFaceApi() {
    if (typeof faceapi !== 'undefined') return;
    await new Promise((res, rej) => {
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js';
      s.onload = res; s.onerror = rej;
      document.head.appendChild(s);
    });
    const MODEL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
    await Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri(MODEL),
      faceapi.nets.faceExpressionNet.loadFromUri(MODEL)
    ]);
  }

  /* ════════════════════════════════════
     3. START RECORDING
  ════════════════════════════════════ */
  function startRecording() {
    if (!mediaStream) { setStatus('Activate camera first', 'warn'); return; }

    recordedChunks = []; fullTranscript = ''; sentimentScore = 50;
    faceEmotions   = { happy:0, neutral:0, sad:0, angry:0, surprised:0, fearful:0, disgusted:0 };
    faceFrames     = 0;

    const canvas = $('output_canvas');
    const stream = canvas.captureStream(30);
    mediaStream.getAudioTracks().forEach(t => stream.addTrack(t));

    const mime = ['video/webm;codecs=vp9,opus','video/webm;codecs=vp8,opus','video/webm','video/mp4']
      .find(t => MediaRecorder.isTypeSupported(t)) || 'video/webm';

    mediaRecorder = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 2_500_000 });
    mediaRecorder.ondataavailable = e => { if (e.data.size > 0) recordedChunks.push(e.data); };
    mediaRecorder.onstop = onRecordingStopped;
    mediaRecorder.start(1000);

    startSpeechRecognition();
    if (faceApiReady) faceInterval = setInterval(analyzeFace, 1500);

    if (window.GSDPhase) window.GSDPhase.recording();

    recordingSeconds = MAX_SECONDS;
    updateBadge();
    recordingTimer = setInterval(() => {
      recordingSeconds--;
      updateBadge();
      if (window.updateCountdownRing) window.updateCountdownRing(recordingSeconds, MAX_SECONDS);
      if (recordingSeconds <= 0) stopRecording();
    }, 1000);

    setStatus('Recording — speak clearly.', 'rec');
  }

  function updateBadge() {
    const el = $('rec-timer');
    if (!el) return;
    const m = String(Math.floor(recordingSeconds / 60)).padStart(2,'0');
    const s = String(recordingSeconds % 60).padStart(2,'0');
    el.textContent = `${m}:${s}`;
    el.style.color = recordingSeconds <= 10 ? '#fca5a5' : '#fff';
  }

  /* ════════════════════════════════════
     4. SPEECH RECOGNITION
  ════════════════════════════════════ */
  function startSpeechRecognition() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { setTranscript('Speech recognition not available.'); return; }

    recognition                = new SR();
    recognition.continuous     = true;
    recognition.interimResults = true;
    recognition.lang           = detectLang();

    recognition.onresult = evt => {
      let interim = '';
      for (let i = evt.resultIndex; i < evt.results.length; i++) {
        const t = evt.results[i][0].transcript;
        evt.results[i].isFinal ? (fullTranscript += t + ' ', updateSentiment(fullTranscript)) : (interim += t);
      }
      setTranscript((fullTranscript + interim).slice(-140));
    };
    recognition.onerror = e => { if (e.error !== 'no-speech') console.warn('SR:', e.error); };
    recognition.onend   = () => { if (speechActive) { try { recognition.start(); } catch(_){} } };

    speechActive = true;
    try { recognition.start(); } catch(_) {}
  }

  function detectLang() {
    const lf = document.getElementById('f-lang');
    if (lf) {
      const v = lf.value.toLowerCase();
      if (v.includes('english')) return 'en-US';
      if (v.includes('spanish') || v.includes('español')) return 'es-ES';
      if (v.includes('french'))  return 'fr-FR';
      if (v.includes('portuguese') || v.includes('portugués')) return 'pt-BR';
    }
    return navigator.language || 'en-US';
  }

  function setTranscript(t) { const el = $('transcript-box'); if (el) el.textContent = t || 'Listening…'; }

  /* ════════════════════════════════════
     5. SENTIMENT
  ════════════════════════════════════ */
  const POS = ['experience','skilled','passionate','achieve','success','team','lead','manage','develop','create','improve','grow','excellent','strong','dedicated','motivated','communicate','organize','strategic','creative','proactive','resultado','experiencia','logro','equipo','lider','mejorar','crecer','excelente'];
  const NEG = ['difficult','struggle','problem','issue','fail','never','cannot','hard','stressed','dificil','problema','nunca','fracaso'];

  function updateSentiment(text) {
    const words = text.toLowerCase().split(/\s+/);
    let p = 0, n = 0;
    words.forEach(w => { if (POS.some(k => w.includes(k))) p++; if (NEG.some(k => w.includes(k))) n++; });
    if (p + n > 0) sentimentScore = Math.round(p / (p + n) * 100);
    const el = $('sentiment-score');
    if (el) {
      el.textContent = sentimentScore + '%';
      el.style.color = sentimentScore >= 65 ? '#4ade80' : sentimentScore >= 40 ? '#fbbf24' : '#f87171';
    }
  }

  /* ════════════════════════════════════
     6. FACE ANALYSIS
  ════════════════════════════════════ */
  async function analyzeFace() {
    if (!faceApiReady || typeof faceapi === 'undefined') return;
    try {
      const det = await faceapi
        .detectSingleFace($('output_canvas'), new faceapi.TinyFaceDetectorOptions({ inputSize:224 }))
        .withFaceExpressions();
      if (det) {
        const e = det.expressions;
        Object.keys(faceEmotions).forEach(k => { if (e[k] != null) faceEmotions[k] += e[k]; });
        faceFrames++;
        const dominant = Object.entries(e).sort((a,b) => b[1]-a[1])[0][0];
        if (window.updateEmotionDots) window.updateEmotionDots(dominant);
      }
    } catch(_) {}
  }

  function getFaceAnalysis() {
    if (!faceFrames) return { available:false };
    const avg = {};
    Object.keys(faceEmotions).forEach(k => avg[k] = +(faceEmotions[k] / faceFrames).toFixed(3));
    const dominant = Object.entries(avg).sort((a,b) => b[1]-a[1])[0][0];
    return { available:true, dominant, averages:avg, frames:faceFrames };
  }

  /* ════════════════════════════════════
     STOP RECORDING
  ════════════════════════════════════ */
  function stopRecording() {
    if (!mediaRecorder || mediaRecorder.state === 'inactive') return;
    clearInterval(recordingTimer); clearInterval(faceInterval);
    speechActive = false; try { recognition?.stop(); } catch(_) {}
    mediaRecorder.stop();
  }

  function onRecordingStopped() {
    finalVideoBlob = new Blob(recordedChunks, { type: mediaRecorder.mimeType || 'video/webm' });

    const lang    = detectSpokenLang(fullTranscript);
    const face    = getFaceAnalysis();
    const faceScore = face.available
      ? Math.round(((face.averages.happy||0)*100 + (face.averages.neutral||0)*50))
      : 50;

    videoAnalysis = {
      generated_at:     new Date().toISOString(),
      duration_seconds: MAX_SECONDS - recordingSeconds,
      language:         lang.code,
      language_label:   lang.label,
      transcript:       fullTranscript.trim(),
      word_count:       fullTranscript.trim().split(/\s+/).filter(Boolean).length,
      sentiment: {
        score:   sentimentScore,
        label:   sentimentScore >= 65 ? 'Positive' : sentimentScore >= 40 ? 'Neutral' : 'Needs Work',
        pos_kw:  POS.filter(k => fullTranscript.toLowerCase().includes(k)).length,
        neg_kw:  NEG.filter(k => fullTranscript.toLowerCase().includes(k)).length
      },
      facial_analysis:     face,
      combined_score:      Math.round(sentimentScore * 0.6 + faceScore * 0.4),
      background_replaced: typeof SelfieSegmentation !== 'undefined',
      audio_enhanced:      true
    };

    const rv = $('review_video');
    if (rv) rv.src = URL.createObjectURL(finalVideoBlob);
    if (window.GSDPhase) window.GSDPhase.review();

    const ss = $('sentiment-score');
    if (ss) ss.textContent = `Score ${videoAnalysis.combined_score}%`;
    setTranscript(`"${(videoAnalysis.transcript || 'No speech detected').slice(0,130)}…"`);
    setStatus('Recording complete — review then submit.', 'ok');
  }

  function detectSpokenLang(text) {
    const es = ['soy','tengo','trabajo','experiencia','años','empresa'];
    return es.filter(w => text.toLowerCase().includes(w)).length >= 2
      ? { code:'es', label:'Spanish' }
      : { code:'en', label:'English' };
  }

  /* ════════════════════════════════════
     RETRY
  ════════════════════════════════════ */
  function retryRecording() {
    finalVideoBlob = null; recordedChunks = []; fullTranscript = ''; sentimentScore = 50;
    const rv = $('review_video');
    if (rv) { rv.src = ''; rv.style.display = 'none'; }
    if (window.GSDPhase) window.GSDPhase.reset();
    setStatus('Ready to record again.', 'info');
  }

  function retryFromConfirm() {
    if (window.GSD?.goStep) window.GSD.goStep(7);
    setTimeout(retryRecording, 100);
  }

  /* ════════════════════════════════════
     UPLOAD
  ════════════════════════════════════ */
  async function submitFullApplication() {
    console.log('submitFullApplication called', { finalVideoBlob: !!finalVideoBlob, videoAnalysis });
    
    if (!finalVideoBlob) { alert('Please record your video first.'); return; }

    const btn = $('btn-final-submit');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="sp mr-2"></span> Uploading…'; }

    try {
      // Generate token if not exists
      const genToken = window.SESSION_TOKEN || ('GSD-' + Math.random().toString(36).substr(2,4).toUpperCase() + '-' + Date.now().toString(36).toUpperCase());
      window.SESSION_TOKEN = genToken;
      const fd = buildFormData(genToken);
      const result = await uploadWithProgress(fd);
      console.log('Upload result:', result);
      
      // Even if DB fails, we can still show review page with local data
      if (!result.token) {
        result.token = window.SESSION_TOKEN || ('GSD-' + Math.random().toString(36).substr(2,4).toUpperCase() + '-' + Date.now().toString(36).toUpperCase());
      }

      // Save data to localStorage for review.php
      const appData = {
        name: $('f-name')?.value || '',
        email: $('f-email')?.value || '',
        phone: $('f-phone')?.value || '',
        whatsapp: $('f-whatsapp')?.value || '',
        country: $('f-country')?.value || '',
        city: $('f-city')?.value || '',
        linkedin: $('f-linkedin')?.value || '',
        salary: $('f-salary')?.value || '',
        availability: $('f-avail')?.value || '',
        summary: $('f-sum')?.value || '',
        skills: $('f-skills')?.value || '',
        education_level: $('f-edu-level')?.value || '',
        degree: $('f-deg1')?.value || '',
        institution: $('f-ins1')?.value || '',
        exp_years: $('f-exp-yrs')?.value || '',
        company: $('f-co1')?.value || '',
        job_title: $('f-jt1')?.value || '',
        position: $('f-position')?.value || '',
        referral: $('f-referral')?.value || '',
        languages: $('f-lang')?.value || '',
        videoUrl: result.video_url || (finalVideoBlob ? URL.createObjectURL(finalVideoBlob) : ''),
        score: videoAnalysis.combined_score || '',
        sentimentLabel: result.ai?.sentiment_analysis?.label || result.db?.ai?.sentiment_analysis?.label || videoAnalysis.sentiment?.label || '',
        languageLabel: result.ai?.transcript_analysis?.language_label || result.db?.ai?.transcript_analysis?.language_label || videoAnalysis.language_label || '',
        dominantEmotion: result.ai?.visual_analysis?.dominant_emotion || result.db?.ai?.visual_analysis?.dominant_emotion || videoAnalysis.facial_analysis?.dominant || '',
        transcript: videoAnalysis.transcript || '',
        videoAnalysis: videoAnalysis,
        aiProfile: result.ai || result.db?.ai || null,
        overallScore: result.ai?.overall_score || result.db?.ai?.overall_score || videoAnalysis.combined_score || '',
        bestPosition: result.ai?.best_position || result.db?.ai?.best_position || $('f-position')?.value || '',
        bestPositionCode: result.ai?.best_position_code || result.db?.ai?.best_position_code || '',
        matchReasoning: result.ai?.match_reasoning || result.db?.ai?.match_reasoning || '',
        spontaneityLabel: result.ai?.spontaneity_analysis?.label || result.db?.ai?.spontaneity_analysis?.label || '',
        spontaneitySummary: result.ai?.spontaneity_analysis?.summary || result.db?.ai?.spontaneity_analysis?.summary || '',
        englishLevel: result.ai?.english_level || result.db?.ai?.english_level || '',
        englishScore: result.ai?.english_score || result.db?.ai?.english_score || '',
        gestureSummary: result.ai?.gesture_word_alignment?.summary || result.db?.ai?.gesture_word_alignment?.summary || '',
        roleScores: result.ai?.role_scores || result.db?.ai?.role_scores || []
      };
      localStorage.setItem('gsd_app_data', JSON.stringify(appData));

      // Redirect to review.php with token
      const redirectUrl = BASE_URL + 'review.php?token=' + result.token + '&t=' + Date.now();
      console.log('Redirecting to:', redirectUrl);
      window.location.href = redirectUrl;

    } catch(err) {
      console.error('Submit error:', err);
      if (btn) { btn.disabled = false; btn.innerHTML = '🚀 Submit Application'; }
      setStatus('Upload failed: ' + err.message, 'err');
    }
  }

  function buildFormData(token) {
    const fd    = new FormData();
    const t = token || window.SESSION_TOKEN || ('GSD-' + Math.random().toString(36).substr(2,4).toUpperCase() + '-' + Date.now().toString(36).toUpperCase());

    // Text inputs (all text, email, url, number, tel inputs and textareas and selects)
    document.querySelectorAll('input[type=text], input[type=email], input[type=url], input[type=number], input[type=tel], textarea, select').forEach(el => {
      if (el.id && el.value) fd.append(el.id, el.value);
    });
    
    // Collect ALL questionnaire answers into a single JSON object
    const allAnswers = {
      skills: {},
      personality: {}
    };
    
    // Group skill answers
    document.querySelectorAll('input[type=radio]:checked').forEach(r => {
      const name = r.name;
      const value = r.value;

      fd.append('radio_' + name, value);
      
      if (name.startsWith('sk-vpa-')) allAnswers.skills['vpa_' + name.replace('sk-vpa-', 'q')] = value;
      else if (name.startsWith('sk-hva-')) allAnswers.skills['hva_' + name.replace('sk-hva-', 'q')] = value;
      else if (name.startsWith('sk-hop-')) allAnswers.skills['hop_' + name.replace('sk-hop-', 'q')] = value;
      else if (name.startsWith('sk-mva-')) allAnswers.skills['mva_' + name.replace('sk-mva-', 'q')] = value;
      else if (name.startsWith('sk-hro-')) allAnswers.skills['hro_' + name.replace('sk-hro-', 'q')] = value;
      else if (name.startsWith('sk-mgr-')) allAnswers.skills['mgr_' + name.replace('sk-mgr-', 'q')] = value;
      else if (name.startsWith('sk-acm-')) allAnswers.skills['acm_' + name.replace('sk-acm-', 'q')] = value;
      else if (name.startsWith('sk-sdr-')) allAnswers.skills['sdr_' + name.replace('sk-sdr-', 'q')] = value;
      else if (name.startsWith('p-')) allAnswers.personality[name] = value;
      else if (name.startsWith('pq-')) allAnswers.personality[name] = value;
    });
    
    console.log('ALL ANSWERS:', allAnswers);
    
    // Add to formData as single JSON
    if (Object.keys(allAnswers.skills).length || Object.keys(allAnswers.personality).length) {
      fd.append('answers_all', JSON.stringify(allAnswers));
    }

    fd.append('token',        t);
    fd.append('submitted_at', new Date().toISOString());
    fd.append('video_analysis', JSON.stringify(videoAnalysis));
    fd.append('cv_text_raw', window.cvText || document.getElementById('raw-txt')?.value || '');
    fd.append('cv_extracted_json', document.getElementById('json-out')?.value || '');

    // File inputs
    const files = { cv_file:$('inp-cv'), id_file:$('inp-id'), photo_file:$('inp-ph') };
    Object.entries(files).forEach(([key, el]) => { if (el?.files[0]) fd.append(key, el.files[0]); });

    const ext = (mediaRecorder?.mimeType || 'webm').includes('mp4') ? 'mp4' : 'webm';
    fd.append('video_original', finalVideoBlob, `video_original.${ext}`);
    fd.append('analysis_json',
      new Blob([JSON.stringify(videoAnalysis, null, 2)], { type:'application/json' }),
      'video_analysis.json'
    );

    return fd;
  }

  function uploadWithProgress(fd) {
    return new Promise(res => {
      const xhr = new XMLHttpRequest();
      xhr.upload.onprogress = e => {
        if (!e.lengthComputable) return;
        const pct = Math.round(e.loaded / e.total * 100);
        if (window.updateUploadUI) window.updateUploadUI(pct);
        const btn = $('btn-final-submit');
        if (btn) btn.innerHTML = `<span class="sp mr-2"></span> ${pct}%…`;
      };
      xhr.onload = () => {
        console.log('Upload response:', xhr.responseText);
        try { 
          const j = JSON.parse(xhr.responseText); 
          const ok = j.status === 'ok' || j.status === 'success';
          console.log('Upload success:', ok, 'response:', j);
          res({ success: ok, ...j }); 
        }
        catch(_) { 
          console.error('Invalid JSON response:', xhr.responseText);
          res({ success:false, error:'Invalid response' }); 
        }
      };
      xhr.onerror = () => {
        console.error('Network error uploading');
        res({ success:false, error:'Network error' }); 
      };
      xhr.open('POST', BASE_URL + 'upload-video.php');
      xhr.send(fd);
    });
  }

  // OLD CORRUPTED CODE REMOVED - See finalConfirm below for redirect

  /* ════════════════════════════════════
     FINAL CONFIRM — Redirect to review.php
  ════════════════════════════════════ */
  async function finalConfirm(token, email) {
    window.location.href = BASE_URL + 'review.php?token=' + token;
    return;
  }

  function cleanupCamera() {
    cameraRunning = false;
    mediaStream?.getTracks().forEach(t => t.stop());
    mediaStream = null;
    try { selfieSegmentation?.close(); } catch(_) {}
    selfieSegmentation = null;
    cameraReady = false;
  }

  /* ════════════════════════════════════
     FINAL SUCCESS
  ════════════════════════════════════ */
  function showFinalSuccess(token) {
    const el = $('confirm-screen');
    if (!el) return;
    el.innerHTML = `
      <style>
        @keyframes pop  { from{transform:scale(.2);opacity:0} to{transform:scale(1);opacity:1} }
        @keyframes glow { 0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.4)} 50%{box-shadow:0 0 0 22px rgba(34,197,94,0)} }
      </style>
      <div style="padding:4rem 2rem;text-align:center;">
        <div style="width:88px;height:88px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.75rem;animation:pop .5s cubic-bezier(.175,.885,.32,1.275),glow 2s 1s infinite;">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h2 style="color:#fff;font-size:1.85rem;font-weight:900;margin:0 0 .6rem;letter-spacing:-.04em;">Application Submitted!</h2>
        <p style="color:rgba(255,255,255,.5);font-size:.88rem;line-height:1.7;max-width:400px;margin:0 auto 1.75rem;">
          Thank you for applying to <strong style="color:#a78bfa;">GSD Associates</strong>.<br>
          Our team will review your application and contact you within <strong style="color:#fff;">2–3 business days</strong>.
        </p>
        <div style="background:rgba(140,82,255,.12);border:1px solid rgba(140,82,255,.3);border-radius:14px;padding:1.25rem;display:inline-block;margin-bottom:1.75rem;">
          <p style="color:rgba(255,255,255,.3);font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;margin-bottom:.4rem;">Your Token</p>
          <p style="color:#c4b5fd;font-family:monospace;font-size:1.15rem;font-weight:900;">${token}</p>
          <p style="color:rgba(255,255,255,.22);font-size:.6rem;margin-top:.3rem;">Screenshot this for your records</p>
        </div>
        <br>
        <a href="views/new-candidate.php?token=${token}" target="_blank"
           style="display:inline-flex;align-items:center;gap:.5rem;background:linear-gradient(135deg,#5A3988,#8C52FF);color:#fff;text-decoration:none;padding:.85rem 1.75rem;border-radius:12px;font-weight:800;font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;font-family:'Inter',sans-serif;box-shadow:0 4px 20px rgba(140,82,255,.4);">
          🔗 View My Candidate Profile
        </a>
      </div>
    `;
  }

  function cleanupCamera() {
    cameraRunning = false;
    mediaStream?.getTracks().forEach(t => t.stop());
    mediaStream = null;
    try { selfieSegmentation?.close(); } catch(_) {}
    selfieSegmentation = null;
    cameraReady = false;
  }

  /* ════════════════════════════════════
     STATUS
  ════════════════════════════════════ */
  function setStatus(msg, type = 'info') {
    const el = $('transcript-box');
    if (el) {
      el.textContent = msg;
      el.style.color = ({ok:'#4ade80',err:'#f87171',rec:'#fbbf24',warn:'#fb923c',info:'#93c5fd'})[type]||'#93c5fd';
    }
  }

  /* ════════════════════════════════════
     PUBLIC
  ════════════════════════════════════ */
  return {
    initCamera, startRecording, stopRecording,
    retryRecording, retryFromConfirm,
    submitFullApplication, finalConfirm
  };

})();

// Merge into window.GSD (set AFTER main script defines window.GSD)
window.GSDVideo = GSDVideo;

// Make functions directly available on window.GSD for onclick handlers
Object.keys(GSDVideo).forEach(key => {
  if (typeof GSDVideo[key] === 'function') {
    window[key] = GSDVideo[key];
  }
});

// Also merge into window.GSD
if (window.GSD) Object.assign(window.GSD, GSDVideo);
else window.GSD = Object.assign({}, GSDVideo);
