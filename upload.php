<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GSD Associates - Video Processor</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/selfie_segmentation.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style type="text/tailwindcss">
        @theme {
            --color-gsd-primary: #5A3988;
            --color-gsd-dark: #240F3A;
            --color-gsd-light: #C16AFF;
            --color-gsd-vibrant: #8C52FF;
            --font-sans: 'Roboto', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-50 font-sans text-gray-800 h-screen flex flex-col">

    <nav class="shrink-0 w-full border-b border-gray-100 bg-white z-50 px-4 py-3 flex justify-between items-center">
        <div class="flex items-center gap-2">
            <img src="assets/images/iconGSD.png" alt="GSD Logo" class="w-8 h-8 object-contain">
            <div>
                <h1 class="text-base font-bold text-gsd-primary tracking-tight leading-none">GSD ASSOCIATES</h1>
                <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest leading-none">Video Processor</p>
            </div>
        </div>
    </nav>

    <main class="flex-grow flex flex-col items-center justify-center p-6 gap-6">
        <!-- NUEVO FORMULARIO DE USUARIO (EN INGLÉS) -->
            <div id="user-info-form" class="w-full max-w-4xl bg-white p-6 rounded-2xl shadow-md border border-gray-200">
                <h2 class="text-sm font-bold text-gsd-primary mb-4 uppercase tracking-wider">Candidate Information</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- First Name -->
                    <div>
                        <label for="first-name" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">First Name</label>
                        <input type="text" id="first-name" placeholder="John" class="w-full px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-gsd-primary outline-none transition-colors">
                    </div>
                    <!-- Last Name -->
                    <div>
                        <label for="last-name" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Last Name</label>
                        <input type="text" id="last-name" placeholder="Doe" class="w-full px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-gsd-primary outline-none transition-colors">
                    </div>
                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Email Address</label>
                        <input type="email" id="email" placeholder="john@example.com" class="w-full px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-gsd-primary outline-none transition-colors">
                    </div>
                    <!-- Position -->
                    <div>
                        <label for="position" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Position</label>
                        <input type="text" id="position" placeholder="Virtual Assistant" class="w-full px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-gsd-primary outline-none transition-colors">
                    </div>
                </div>
            </div>
            <!-- FIN DEL FORMULARIO -->
        <!-- Área de Carga -->
        <div id="upload-area" class="w-full max-w-xl bg-white p-8 rounded-2xl shadow-lg border border-dashed border-gray-300 text-center hover:border-gsd-primary transition-colors cursor-pointer">
            <input type="file" id="video-input" accept="video/mp4,video/webm,video/quicktime,.mov" class="hidden">
            <div class="pointer-events-none">
                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <p class="mt-4 text-sm text-gray-600">Click to upload a video file</p>
                <p class="text-xs text-gray-400 mt-1">MP4, WebM or MOV (Max 50MB recommended)</p>
            </div>
        </div>

        <!-- Área de Procesamiento -->
        <div id="process-area" class="hidden w-full flex flex-col items-center gap-4">
            <div class="relative w-full max-w-3xl aspect-video bg-black rounded-xl overflow-hidden shadow-2xl">
                <!-- CORRECCIÓN 1: Quitamos 'muted' para poder capturar el audio -->
                <video id="source-video" class="absolute opacity-0 pointer-events-none" playsinline></video>
                
                <canvas id="output-canvas" class="w-full h-full object-contain"></canvas>
                <video id="result-video" class="absolute inset-0 w-full h-full object-contain hidden z-20 bg-black" controls></video>

                <div id="loader" class="absolute inset-0 bg-white/90 z-30 flex flex-col items-center justify-center hidden">
                    <div class="w-64 bg-gray-200 rounded-full h-2.5 mb-2">
                        <div id="progress-bar" class="bg-gsd-primary h-2.5 rounded-full" style="width: 0%"></div>
                    </div>
                    <p id="status-text" class="text-gsd-primary font-bold text-xs tracking-widest animate-pulse">PREPARING AI...</p>
                </div>
            </div>

            <!-- CORRECCIÓN 2: Arreglado el doble <<div -->
            <div class="flex flex-wrap justify-center gap-4 mt-4">
                <button id="btn-process" class="bg-gsd-primary hover:bg-gsd-dark text-white font-bold py-3 px-8 rounded-xl shadow-lg transition disabled:opacity-50">
                    START BACKGROUND REMOVAL
                </button>
                
                <!-- Descarga Local (WebM) -->
                <a id="btn-download-webm" class="hidden bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-8 rounded-xl shadow-lg transition flex items-center gap-2 cursor-pointer">
                    DOWNLOAD WEBM
                </a>
                
                <!-- Botón para enviar al server y generar MP4 -->
                <button id="btn-upload-server" class="hidden bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 px-8 rounded-xl shadow-lg transition flex items-center gap-2">
                    SAVE & GENERATE MP4
                </button>
            
                <!-- Descarga del Servidor (MP4) -->
                <a id="btn-download-mp4" target="_blank" class="hidden bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-8 rounded-xl shadow-lg transition flex items-center gap-2 cursor-pointer">
                    DOWNLOAD MP4
                </a>
                
                <button id="btn-reset" class="hidden bg-gray-200 text-gray-700 font-bold py-3 px-8 rounded-xl shadow transition">
                    NEW VIDEO
                </button>
            </div>
        </div>
    </main>

    <img id="bg-image" src="assets/images/backgroundGSD.png" class="hidden" crossorigin="anonymous">

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const els = {
            uploadArea: document.getElementById('upload-area'),
            fileInput: document.getElementById('video-input'),
            processArea: document.getElementById('process-area'),
            sourceVideo: document.getElementById('source-video'),
            canvas: document.getElementById('output-canvas'),
            ctx: document.getElementById('output-canvas').getContext('2d', { alpha: true }),
            resultVideo: document.getElementById('result-video'),
            bgImage: document.getElementById('bg-image'),
            btnProcess: document.getElementById('btn-process'),
            btnDownloadWebm: document.getElementById('btn-download-webm'),
            btnDownloadMp4: document.getElementById('btn-download-mp4'),
            btnReset: document.getElementById('btn-reset'),
            btnServer: document.getElementById('btn-upload-server'),
            loader: document.getElementById('loader'),
            progressBar: document.getElementById('progress-bar'),
            statusText: document.getElementById('status-text')
        };
    
        let state = {
            mediaRecorder: null,
            chunks:[],
            isProcessing: false,
            audioCtx: null,
            audioSource: null,
            streamDest: null
        };
    
        // Resolución fija de salida (Full HD)
        const TARGET_WIDTH = 1920;
        const TARGET_HEIGHT = 1080;
    
        let bgLoaded = false;
        if (els.bgImage.complete && els.bgImage.naturalHeight !== 0) bgLoaded = true;
        els.bgImage.onload = () => { bgLoaded = true; };
        els.bgImage.onerror = () => { console.warn("Fondo no encontrado, usando color sólido."); };
    
        // --- CONFIGURACIÓN MEDIAPIPE ---
        const seg = new SelfieSegmentation({ locateFile: (f) => `https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/${f}` });
        seg.setOptions({ 
            modelSelection: 0, // CORRECCIÓN: Cambiado a 0. Es mucho más rápido y evita cuelgues en videos largos.
            selfieMode: false 
        });
    
        seg.onResults((results) => {
            const w = els.canvas.width;
            const h = els.canvas.height;
            const imgW = results.image.width;
            const imgH = results.image.height;
            
            const scale = Math.min(w / imgW, h / imgH);
            const newW = imgW * scale;
            const newH = imgH * scale;
            const offsetX = (w - newW) / 2;
            const offsetY = (h - newH) / 2;
    
            els.ctx.save();
            els.ctx.clearRect(0, 0, w, h);
            
            els.ctx.drawImage(results.image, offsetX, offsetY, newW, newH);
            
            els.ctx.globalCompositeOperation = 'destination-in';
            els.ctx.drawImage(results.segmentationMask, offsetX, offsetY, newW, newH);
            
            els.ctx.globalCompositeOperation = 'destination-over';
            if (bgLoaded) {
                els.ctx.drawImage(els.bgImage, 0, 0, w, h);
            } else {
                els.ctx.fillStyle = "#5A3988"; 
                els.ctx.fillRect(0, 0, w, h);
            }
            els.ctx.restore();
        });
    
        els.uploadArea.addEventListener('click', () => els.fileInput.click());
        
        els.fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;
            els.sourceVideo.src = URL.createObjectURL(file);
            els.sourceVideo.volume = 0; 
            
            els.uploadArea.classList.add('hidden');
            els.processArea.classList.remove('hidden');
            els.loader.classList.remove('hidden');
            els.statusText.innerText = "LOADING...";
    
            els.sourceVideo.onloadedmetadata = () => {
                els.canvas.width = TARGET_WIDTH;
                els.canvas.height = TARGET_HEIGHT;
                
                const imgW = els.sourceVideo.videoWidth;
                const imgH = els.sourceVideo.videoHeight;
                const scale = Math.min(TARGET_WIDTH / imgW, TARGET_HEIGHT / imgH);
                const newW = imgW * scale;
                const newH = imgH * scale;
                const offsetX = (TARGET_WIDTH - newW) / 2;
                const offsetY = (TARGET_HEIGHT - newH) / 2;
    
                if (bgLoaded) {
                    els.ctx.drawImage(els.bgImage, 0, 0, TARGET_WIDTH, TARGET_HEIGHT);
                    els.ctx.drawImage(els.sourceVideo, offsetX, offsetY, newW, newH);
                }
                els.loader.classList.add('hidden');
            };
        });
    
        els.btnProcess.addEventListener('click', async () => {
            if (state.isProcessing) return;
            
            // Validación del formulario (Si agregaste el formulario HTML de la respuesta anterior)
            const userNameInput = document.getElementById('user-name');
            const firstNameInput = document.getElementById('first-name');
            const lastNameInput = document.getElementById('last-name');
            const emailInput = document.getElementById('email');
            const positionInput = document.getElementById('position');
    
            if(firstNameInput) {
                if(!firstNameInput.value.trim() || !lastNameInput.value.trim() || !emailInput.value.trim() || !positionInput.value.trim()) {
                    alert("Please fill in all candidate information fields (First Name, Last Name, Email, and Position).");
                    return;
                }
            }
            if(userNameInput) {
                if(!userNameInput.value.trim() || !document.getElementById('user-email').value.trim()) {
                    alert("Please fill in your Name and Email before starting.");
                    return;
                }
            }
    
            state.isProcessing = true;
            els.btnProcess.classList.add('hidden');
            els.loader.classList.remove('hidden');
            els.statusText.innerText = "INITIALIZING...";
            state.chunks =[];
    
            try {
                if (!state.audioCtx) state.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                if (state.audioCtx.state === 'suspended') await state.audioCtx.resume();
    
                const dest = state.audioCtx.createMediaStreamDestination();
                state.streamDest = dest;
                
                if (!state.audioSource) {
                    state.audioSource = state.audioCtx.createMediaElementSource(els.sourceVideo);
                    state.audioSource.connect(dest);
                }
    
                const canvasStream = els.canvas.captureStream(30); 
                if (dest.stream.getAudioTracks().length > 0) {
                    canvasStream.addTrack(dest.stream.getAudioTracks()[0]);
                }
    
                let options = { mimeType: 'video/webm;codecs=vp9', videoBitsPerSecond: 2500000 }; 
                if (!MediaRecorder.isTypeSupported(options.mimeType)) options = { mimeType: 'video/webm' };
                
                state.mediaRecorder = new MediaRecorder(canvasStream, options);
                
                state.mediaRecorder.ondataavailable = (e) => { 
                    if (e.data && e.data.size > 0) state.chunks.push(e.data); 
                };
                
                state.mediaRecorder.onstop = finishProcessing;
    
                els.sourceVideo.onplaying = () => {
                    console.log("Video Playing -> Start Recording");
                    if (state.mediaRecorder.state === "inactive") {
                        // CORRECCIÓN 1: Grabamos en trozos de 1000ms (1 segundo) para evitar que la RAM colapse
                        state.mediaRecorder.start(1000); 
                        processLoop(); 
                    }
                };
    
                els.sourceVideo.onended = () => {
                    console.log("Video Ended -> Stop Recording");
                    if (state.mediaRecorder.state === "recording") {
                        state.mediaRecorder.stop();
                    }
                };
    
                els.sourceVideo.muted = false; 
                els.sourceVideo.volume = 1; 
                els.sourceVideo.currentTime = 0; // Asegurarnos que empiece desde el inicio
                
                await els.sourceVideo.play();
    
            } catch (err) {
                console.error(err);
                alert("Error: " + err.message);
                location.reload();
            }
        });
    
        // CORRECCIÓN 2: Candado lógico para evitar que la GPU se sature
        let isProcessingFrame = false;
    
        function processLoop() {
            if (els.sourceVideo.paused || els.sourceVideo.ended) return;
    
            // Si la IA sigue trabajando en un cuadro, no le mandamos otro (previene que el video se congele)
            if (isProcessingFrame) {
                scheduleNextFrame();
                return;
            }
    
            isProcessingFrame = true; // Bloqueamos
    
            seg.send({ image: els.sourceVideo }).then(() => {
                isProcessingFrame = false; // Desbloqueamos
    
                const progress = (els.sourceVideo.currentTime / els.sourceVideo.duration) * 100;
                els.progressBar.style.width = `${progress}%`;
                els.statusText.innerText = `PROCESSING... ${Math.round(progress)}%`;
    
                scheduleNextFrame();
            }).catch(err => {
                console.error("AI Error:", err);
                isProcessingFrame = false; // Desbloqueamos incluso si hay error para que no muera el proceso
                scheduleNextFrame();
            });
        }
    
        // Separamos la solicitud del siguiente cuadro para mantener el código limpio
        function scheduleNextFrame() {
            if (!els.sourceVideo.paused && !els.sourceVideo.ended) {
                if ('requestVideoFrameCallback' in els.sourceVideo) {
                    els.sourceVideo.requestVideoFrameCallback(processLoop);
                } else {
                    requestAnimationFrame(processLoop);
                }
            }
        }
    
        function finishProcessing() {
            console.log("Processing sequence finished.");
            const blob = new Blob(state.chunks, { type: 'video/webm' });
            const videoURL = URL.createObjectURL(blob);
            
            els.resultVideo.src = videoURL;
            els.resultVideo.classList.remove('hidden');
            els.canvas.classList.add('hidden');
            els.loader.classList.add('hidden');
            
            if (els.btnDownloadWebm) {
                els.btnDownloadWebm.href = videoURL;
                els.btnDownloadWebm.download = "processed_gsd.webm";
                els.btnDownloadWebm.classList.remove('hidden');
                els.btnDownloadWebm.classList.add('flex');
            }
        
            if (els.btnServer) {
                els.btnServer.classList.remove('hidden');
                els.btnServer.classList.add('flex');
                
                els.btnServer.onclick = () => {
                    
                    const firstName = document.getElementById('first-name').value.trim();
                    const lastName = document.getElementById('last-name').value.trim();
                    const email = document.getElementById('email').value.trim();
                    const position = document.getElementById('position').value.trim();
                    
                    els.btnServer.innerText = "SAVING & CONVERTING...";
                    els.btnServer.disabled = true;
                    
                    const fd = new FormData();
                    fd.append('video_blob', blob, 'video.webm');
                    
                    fd.append('first_name', firstName);
                    fd.append('last_name', lastName);
                    fd.append('email', email);
                    fd.append('position', position);
                    
                    fetch('api/upload_processed.php', { method: 'POST', body: fd })
                    .then(async r => {
                        // Verificamos si la respuesta del servidor NO es OK (ej. Error 500)
                        if (!r.ok) {
                            const text = await r.text(); // Intentar leer el error real
                            throw new Error(`Server Error ${r.status}: ${text}`);
                        }
                        return r.json();
                    })
                    .then(d => {
                        if(d.status === 'success') { 
                            alert('✅ Candidate Data & Video Saved!'); 
                            els.btnServer.classList.add('hidden');
                            
                            els.btnDownloadMp4.href = d.mp4_url;
                            els.btnDownloadMp4.download = "processed_" + firstName + ".mp4";
                            els.btnDownloadMp4.classList.remove('hidden');
                            els.btnDownloadMp4.classList.add('flex');
                        } else { 
                            alert('❌ Server Error: ' + d.message); 
                            els.btnServer.innerText = "RETRY";
                            els.btnServer.disabled = false;
                            console.error("Debug info:", d.debug);
                        }
                    })
                    .catch(e => {
                        console.error(e);
                        alert('❌ Connection Error. Check console for details.');
                        els.btnServer.innerText = "RETRY";
                        els.btnServer.disabled = false;
                    });
                };
            }
        
            if (els.btnReset) els.btnReset.classList.remove('hidden');
            state.isProcessing = false;
        }
    
        els.btnReset.addEventListener('click', () => location.reload());
    });
    </script>
</body>
</html>