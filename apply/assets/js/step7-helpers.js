/**
 * GSD VIDEO SYSTEM — Step 7 Helpers
 * Contains: GSDPhase, updateEmotionDots, updateUploadUI, updateCountdownRing
 */

window.updateEmotionDots = function(dominant) {
  const map = {
    happy: 'em-happy', neutral: 'em-neutral', surprised: 'em-surprised',
    sad: 'em-sad', angry: 'em-angry', fearful: 'em-sad', disgusted: 'em-angry'
  };
  Object.values(map).forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
  });
  const id = map[dominant];
  if (id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('active');
  }
  const lbl = document.getElementById('face-label');
  if (lbl) lbl.textContent = dominant ? dominant.charAt(0).toUpperCase() + dominant.slice(1) : '';
};

window.updateUploadUI = function(pct) {
  const bar   = document.getElementById('upload-bar-fill');
  const txt   = document.getElementById('upload-status-text');
  const wrap  = document.getElementById('upload-status');
  const progBar = document.getElementById('upload-progress-bar');
  if (wrap)    wrap.style.display    = 'block';
  if (progBar) progBar.style.display = 'block';
  if (bar)     bar.style.width       = pct + '%';
  if (txt)     txt.textContent       = pct < 100 ? `Uploading ${pct}%…` : '✅ Upload complete!';
};

window.GSDPhase = {
  activate: function() {
    const els = ['vid-idle-overlay', 'analysis-bar', 'ctrl-activate', 'ctrl-record', 'video-tips', 'progress-ring'];
    els.forEach(id => { const el = document.getElementById(id); if (el) el.style.display = id === 'ctrl-record' ? 'block' : (id === 'video-tips' ? 'none' : (id === 'progress-ring' ? 'none' : 'none')); });
    const overlay = document.getElementById('vid-idle-overlay');
    if (overlay) overlay.style.display = 'none';
    const analysis = document.getElementById('analysis-bar');
    if (analysis) analysis.style.display = 'flex';
    const ctrlActivate = document.getElementById('ctrl-activate');
    if (ctrlActivate) ctrlActivate.style.display = 'none';
    const ctrlRecord = document.getElementById('ctrl-record');
    if (ctrlRecord) ctrlRecord.style.display = 'block';
    const tips = document.getElementById('video-tips');
    if (tips) tips.style.display = 'none';
    const ring = document.getElementById('progress-ring');
    if (ring) ring.style.display = 'none';
  },
  recording: function() {
    const ctrlRecord = document.getElementById('ctrl-record');
    if (ctrlRecord) ctrlRecord.style.display = 'none';
    const ctrlStop = document.getElementById('ctrl-stop');
    if (ctrlStop) ctrlStop.style.display = 'block';
    const recBadge = document.getElementById('rec-badge');
    if (recBadge) recBadge.style.display = 'flex';
    const progressRing = document.getElementById('progress-ring');
    if (progressRing) progressRing.style.display = 'block';
  },
  review: function() {
    const ctrlStop = document.getElementById('ctrl-stop');
    if (ctrlStop) ctrlStop.style.display = 'none';
    const recBadge = document.getElementById('rec-badge');
    if (recBadge) recBadge.style.display = 'none';
    const ctrlReview = document.getElementById('ctrl-review');
    if (ctrlReview) ctrlReview.style.display = 'block';
    const canvas = document.getElementById('output_canvas');
    if (canvas) canvas.style.opacity = '0';
    const reviewVideo = document.getElementById('review_video');
    if (reviewVideo) reviewVideo.style.display = 'block';
  },
  reset: function() {
    const canvas = document.getElementById('output_canvas');
    if (canvas) canvas.style.opacity = '1';
    const reviewVideo = document.getElementById('review_video');
    if (reviewVideo) reviewVideo.style.display = 'none';
    const ctrlReview = document.getElementById('ctrl-review');
    if (ctrlReview) ctrlReview.style.display = 'none';
    const ctrlRecord = document.getElementById('ctrl-record');
    if (ctrlRecord) ctrlRecord.style.display = 'block';
    const uploadStatus = document.getElementById('upload-status');
    if (uploadStatus) uploadStatus.style.display = 'none';
  }
};

window.updateCountdownRing = function(secondsLeft, maxSeconds) {
  const ring = document.getElementById('ring-fill');
  if (!ring) return;
  const circumference = 125.66;
  const offset = circumference * (1 - secondsLeft / maxSeconds);
  ring.style.strokeDashoffset = offset;
  ring.style.stroke = secondsLeft <= 15 ? '#ef4444' : secondsLeft <= 30 ? '#f59e0b' : '#8C52FF';
};
