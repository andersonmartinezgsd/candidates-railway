<?php
// ==========================================
// 1. CONFIGURACIÓN Y SEGURIDAD
// ==========================================
$clientToken = $_GET['token'] ?? null;

if (!$clientToken) {
    header("Location: https://gsdoutsource.com/"); 
    exit;
}

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/helpers.php';
$database = new Database();
$pdo = $database->getConnection();

$MASTER_TOKEN = "GSD-HR-Sara-Collazos";
// Mostramos candidatos en fases avanzadas
$visibleStatuses = gsdViewerStatusListSql();

try {
    if ($clientToken === $MASTER_TOKEN) {
        $clientName = "GSD Global Review";
        $sql = "SELECT * FROM gsd_candidates WHERE ".gsdViewerVisibleCandidateClause('gsd_candidates')." ORDER BY name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtClient = $pdo->prepare("SELECT id, client_name FROM gsd_clients WHERE share_token = :token AND status = 'active' LIMIT 1");
        $stmtClient->execute(['token' => $clientToken]);
        $client = $stmtClient->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            die('<div style="text-align:center;padding:50px;font-family:sans-serif;"><h1>Access Denied</h1><p>Invalid link.</p></div>');
        }

        $clientName = $client['client_name'];
        $sql = "SELECT * FROM gsd_candidates WHERE client_id = :client_id AND ".gsdViewerVisibleCandidateClause('gsd_candidates')." ORDER BY name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['client_id' => $client['id']]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    die("System Error.");
}

/**
 * Función para corregir rutas de archivos
 */
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Talent Portal | <?php echo htmlspecialchars($clientName); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        body.modal-active { overflow: hidden; }
        .custom-scroll::-webkit-scrollbar { width: 5px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .accordion-content { transition: max-height 0.3s ease-in-out, opacity 0.3s ease-in-out; max-height: 0; opacity: 0; overflow: hidden; }
        .accordion-content.open { max-height: 500px; opacity: 1; }
        .accordion-icon { transition: transform 0.3s ease; }
        .accordion-btn[aria-expanded="true"] .accordion-icon { transform: rotate(180deg); }
        .star-rating { direction: rtl; } 
        .star-rating input:checked ~ label, .star-rating label:hover, .star-rating label:hover ~ label { color: #fbbf24; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 selection:bg-purple-200">

    <nav class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm backdrop-blur-md bg-white/90">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <div class="flex items-center gap-4">
                <img src="../assets/images/iconGSD.png" alt="Logo" class="h-8 w-auto">
                <div class="h-6 w-px bg-slate-300"></div>
                <h1 class="font-bold text-lg text-slate-700 tracking-tight">Talent Portal: <span class="text-purple-700"><?php echo htmlspecialchars($clientName); ?></span></h1>
            </div>
            <span class="text-[10px] font-bold bg-slate-100 text-slate-500 px-3 py-1 rounded-full border border-slate-200 tracking-wider">CONFIDENTIAL</span>
        </div>
    </nav>

    <div class="container mx-auto px-6 py-10">
        <div class="mb-8">
            <h2 class="text-3xl font-black text-slate-900 tracking-tight">Your Candidates</h2>
            <p class="text-slate-500 mt-1">Review the profiles selected specifically for your open positions.</p>
        </div>

        <?php if (empty($candidates)): ?>
            <div class="bg-white p-20 rounded-3xl border-2 border-dashed border-slate-200 text-center">
                <i class="fa-solid fa-folder-open text-3xl text-slate-300 mb-4"></i>
                <h3 class="text-lg font-bold text-slate-700">No candidates assigned yet.</h3>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php foreach ($candidates as $c): ?>
                    <?php 
                        // --- LÓGICA DE VALIDACIÓN DE VIDEO ---
                        $rawVideoPath = !empty($c['video_processed_path']) ? $c['video_processed_path'] : $c['video_original_path'];
                        $urlFinalVideo = gsdViewerCandidateStreamUrl($c);

                        $jsData = [
                            'id' => $c['id'],
                            'name' => $c['name'],
                            'title' => $c['professional_title'] ?? 'Candidate',
                            'summary' => $c['ai_analysis'],
                            'transcript' => $c['transcript'],
                            'reasoning' => $c['match_reasoning']
                        ];
                        $jsonData = htmlspecialchars(json_encode($jsData, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group cursor-pointer"
                         onclick='openModal(<?php echo $jsonData; ?>, "<?php echo $urlFinalVideo; ?>")'>
                        
                        <div class="aspect-video bg-slate-900 relative flex items-center justify-center overflow-hidden">
                            <?php if (!empty($rawVideoPath)): ?>
                                <div class="w-14 h-14 bg-white/20 backdrop-blur-md rounded-full flex items-center justify-center group-hover:scale-110 transition-transform z-10 border border-white/30">
                                    <i class="fa-solid fa-play text-white text-xl ml-1"></i>
                                </div>
                            <?php else: ?>
                                <div class="z-10 text-slate-500 text-center">
                                    <i class="fa-solid fa-video-slash text-2xl mb-2"></i>
                                    <p class="text-[10px] font-bold uppercase">No Video available</p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-900/90 via-transparent to-transparent"></div>
                            <div class="absolute bottom-5 left-6 text-white z-10">
                                <p class="font-bold text-lg leading-tight"><?php echo htmlspecialchars($c['name']); ?></p>
                                <p class="text-[11px] text-purple-300 font-bold uppercase tracking-wider mt-1">
                                    <?php echo htmlspecialchars($c['professional_title'] ?? 'Candidate'); ?>
                                </p>
                            </div>
                        </div>
                        <div class="p-5 flex justify-between items-center bg-white">
                            <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Review Profile</span>
                            <i class="fa-solid fa-arrow-right text-purple-600"></i>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- MODAL DE VISUALIZACIÓN -->
    <div id="videoModal" class="fixed inset-0 z-50 hidden bg-slate-950/95 backdrop-blur-sm flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
        <div class="bg-white w-[95%] h-[90vh] rounded-3xl overflow-hidden shadow-2xl flex flex-col md:flex-row relative">
            <button onclick="closeModal()" class="absolute top-4 right-4 z-50 bg-black/20 hover:bg-black/40 text-white w-10 h-10 rounded-full flex items-center justify-center transition backdrop-blur-sm">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>

            <!-- VIDEO -->
            <div class="w-full md:w-[70%] bg-black flex items-center justify-center relative">
                <video id="modalPlayer" class="w-full h-full max-h-full object-contain focus:outline-none" controls controlsList="nodownload">
                    <source id="videoSource" src="" type="video/mp4">
                    Your browser does not support video.
                </video>
            </div>

            <!-- INFO COL -->
            <div class="w-full md:w-[30%] bg-slate-50 flex flex-col border-l border-slate-200">
                <div class="p-6 bg-white border-b border-slate-100 shadow-sm z-10">
                    <h3 class="font-black text-2xl text-slate-800" id="mName">--</h3>
                    <p class="text-purple-600 font-bold text-xs uppercase tracking-wider" id="mTitle">--</p>
                </div>

                <div class="flex-1 overflow-y-auto custom-scroll p-6 space-y-6">
                    <!-- Feedback Form -->
                    <?php if($clientToken !== $MASTER_TOKEN): ?>
                    <div class="bg-white p-5 rounded-2xl border border-purple-100 shadow-sm">
                        <h4 class="text-[10px] font-bold text-slate-400 uppercase mb-3 italic">Rate this candidate</h4>
                        <form id="feedbackForm">
                            <input type="hidden" id="fCandidateId" name="candidate_id">
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($clientToken); ?>">
                            <div class="flex gap-1 star-rating mb-3">
                                <input type="radio" id="s5" name="rating" value="5" class="hidden peer" required><label for="s5" class="text-slate-200 text-xl cursor-pointer hover:scale-110 transition"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s4" name="rating" value="4" class="hidden peer"><label for="s4" class="text-slate-200 text-xl cursor-pointer hover:scale-110 transition"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s3" name="rating" value="3" class="hidden peer"><label for="s3" class="text-slate-200 text-xl cursor-pointer hover:scale-110 transition"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s2" name="rating" value="2" class="hidden peer"><label for="s2" class="text-slate-200 text-xl cursor-pointer hover:scale-110 transition"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s1" name="rating" value="1" class="hidden peer"><label for="s1" class="text-slate-200 text-xl cursor-pointer hover:scale-110 transition"><i class="fa-solid fa-star"></i></label>
                            </div>
                            <textarea name="comment" placeholder="Comments..." class="w-full p-3 text-xs rounded-xl bg-slate-50 border mb-3 outline-none" rows="2"></textarea>
                            <button type="submit" id="btnSendFeedback" class="w-full bg-slate-900 text-white text-xs font-bold py-3 rounded-xl transition">SEND FEEDBACK</button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Accordion -->
                    <div class="space-y-3">
                        <div class="border border-slate-200 bg-white rounded-xl overflow-hidden">
                            <button class="accordion-btn w-full px-5 py-4 flex justify-between items-center text-left" onclick="toggleAccordion('acc-insights', this)">
                                <span class="text-xs font-bold text-slate-700 uppercase">GSD Insights</span>
                                <i class="fa-solid fa-chevron-down text-slate-400 text-xs accordion-icon"></i>
                            </button>
                            <div id="acc-insights" class="accordion-content open">
                                <div class="px-5 pb-5 text-sm text-slate-600" id="mReasoning">--</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('videoModal');
        const player = document.getElementById('modalPlayer');

        function openModal(data, videoUrl) {
            document.getElementById('mName').textContent = data.name;
            document.getElementById('mTitle').textContent = data.title;
            document.getElementById('mReasoning').textContent = data.reasoning || data.summary || 'No details.';
            
            const fInput = document.getElementById('fCandidateId');
            if(fInput) fInput.value = data.id;

            // CARGA DE VIDEO
            if (videoUrl && videoUrl !== '../') {
                player.src = videoUrl;
                player.load();
                player.play().catch(e => console.log("Autoplay prevented"));
            } else {
                player.src = "";
            }

            modal.classList.remove('hidden');
            setTimeout(() => { modal.classList.remove('opacity-0'); }, 10);
            document.body.classList.add('modal-active');
        }

        function closeModal() {
            modal.classList.add('opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden');
                document.body.classList.remove('modal-active');
                player.pause();
                player.src = "";
            }, 300);
        }

        function toggleAccordion(id, btn) {
            const content = document.getElementById(id);
            content.classList.toggle('open');
            btn.setAttribute('aria-expanded', content.classList.contains('open'));
        }

        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    </script>
</body>
</html>
