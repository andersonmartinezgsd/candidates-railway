<?php
require_once __DIR__ . '/../config/Database.php';
$clientToken = $_GET['token'] ?? null;
$preEvaluator = $_GET['evaluator'] ?? '';

if (!$clientToken) { header("Location: https://gsdoutsource.com/"); exit; }

$database = new Database();
$pdo = $database->getConnection();

// 1. Validar Cliente
$stmtClient = $pdo->prepare("SELECT id, client_name FROM gsd_clients WHERE share_token = ? AND status = 'active' LIMIT 1");
$stmtClient->execute([$clientToken]);
$client = $stmtClient->fetch();
if (!$client) { die("Access Denied."); }

// 2. Obtener Candidatos y ocultar los que tengan 5 o más feedbacks
$sql = "SELECT c.*, 
        (SELECT COUNT(*) FROM gsd_candidate_feedback f WHERE f.candidate_id = c.id) as total_reviews
        FROM gsd_candidates c 
        WHERE c.client_id = :client_id 
        AND c.processing_status IN ('completed', 'client_review', 'interviewing', 'hired') 
        HAVING total_reviews < 5 
        ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute(['client_id' => $client['id']]);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fixPath($rawPath) {
    if (empty($rawPath)) return '';
    if (strpos($rawPath, 'http') === 0) return $rawPath;
    $parts = explode('uploads', $rawPath);
    $cleanPath = (strpos($rawPath, 'uploads') !== false) ? 'uploads' . end($parts) : ltrim($rawPath, '/');
    return rtrim(gsdRecruitmentUploadsBaseUrl(), '/') . '/' . ltrim($cleanPath, '/\\');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Talent Portal | <?php echo htmlspecialchars($client['client_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
        .star-rating { direction: rtl; display: flex; gap: 4px; }
        .star-rating input { display: none; }
        .star-rating label { color: #e2e8f0; font-size: 1.5rem; cursor: pointer; transition: 0.2s; }
        .star-rating input:checked ~ label, .star-rating label:hover, .star-rating label:hover ~ label { color: #fbbf24; }
        .custom-scroll::-webkit-scrollbar { width: 4px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
    </style>
</head>
<body>

    <nav class="bg-white border-b sticky top-0 z-50 p-6 flex justify-between items-center">
        <img src="../assets/images/iconGSD.png" class="h-7">
        <h1 class="text-xs font-bold text-slate-400 uppercase tracking-widest">Client Portal: <span class="text-purple-700"><?php echo $client['client_name']; ?></span></h1>
    </nav>

    <div class="container mx-auto px-6 py-12">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-10">
            <?php foreach ($candidates as $c): 
                $vPath = !empty($c['video_processed_path']) ? $c['video_processed_path'] : $c['video_original_path'];
                $jsData = htmlspecialchars(json_encode([
                    'id'=>$c['id'], 'name'=>$c['name'], 'title'=>$c['professional_title'], 'reasoning'=>$c['match_reasoning']
                ]), ENT_QUOTES, 'UTF-8');
            ?>
            <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden hover:shadow-xl transition-all cursor-pointer group"
                 onclick='openModal(<?php echo $jsData; ?>, "<?php echo fixPath($vPath); ?>")'>
                <div class="aspect-video bg-slate-900 relative flex items-center justify-center">
                    <div class="w-12 h-12 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-md border border-white/20 group-hover:scale-110 transition">
                        <i class="fa-solid fa-play text-white text-xs"></i>
                    </div>
                    <div class="absolute bottom-6 left-8 text-white">
                        <p class="font-bold text-lg"><?php echo $c['name']; ?></p>
                        <p class="text-[9px] uppercase font-black text-purple-400 tracking-[0.2em]"><?php echo $c['professional_title']; ?></p>
                    </div>
                </div>
                <div class="p-5 text-center text-[9px] font-bold text-slate-400 uppercase tracking-[0.3em]">Review Candidate Interview</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- MODAL DE CINE -->
    <div id="videoModal" class="fixed inset-0 z-50 hidden bg-slate-900/95 flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
        <div class="bg-white w-full max-w-[1200px] h-[85vh] rounded-[2rem] overflow-hidden shadow-2xl flex flex-col md:flex-row relative">
            
            <button onclick="closeModal()" class="absolute top-5 right-5 z-50 bg-slate-100 hover:bg-slate-200 w-10 h-10 rounded-full flex items-center justify-center transition">
                <i class="fa-solid fa-xmark text-slate-400"></i>
            </button>

            <!-- VIDEO PLAYER (65%) -->
            <div class="w-full md:w-[65%] bg-black flex items-center justify-center relative">
                <video id="modalPlayer" class="w-full h-full object-contain" controls controlsList="nodownload"></video>
            </div>

            <!-- SIDEBAR FEEDBACK (35%) -->
            <div class="w-full md:w-[35%] bg-white flex flex-col border-l border-slate-50">
                <div class="p-8 border-b border-slate-50">
                    <h3 class="font-bold text-2xl text-slate-800" id="mName">--</h3>
                </div>

                <div class="flex-1 overflow-y-auto custom-scroll p-8 space-y-8">
                    
                    <div class="bg-slate-50/50 p-6 rounded-3xl border border-slate-100">
                        <h4 class="text-[9px] font-bold text-slate-400 uppercase mb-4 tracking-widest italic">Submit your feedback</h4>
                        <form id="feedbackForm" class="space-y-4">
                            <input type="hidden" id="fCandidateId" name="candidate_id">
                            <input type="hidden" name="client_token" value="<?php echo htmlspecialchars($clientToken); ?>">
                            
                            <div class="star-rating">
                                <input type="radio" id="s5" name="rating" value="5" required><label for="s5"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s4" name="rating" value="4"><label for="s4"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s3" name="rating" value="3"><label for="s3"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s2" name="rating" value="2"><label for="s2"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s1" name="rating" value="1"><label for="s1"><i class="fa-solid fa-star"></i></label>
                            </div>

                            <input type="text" name="evaluator" value="<?php echo htmlspecialchars($preEvaluator); ?>" 
                                   placeholder="Evaluator Name" class="w-full p-3 text-xs rounded-xl border-none bg-white shadow-sm outline-none">

                            <textarea name="comment" placeholder="What is your impression?" 
                                      class="w-full p-3 text-xs rounded-xl border-none bg-white shadow-sm outline-none h-20 resize-none"></textarea>
                            
                            <button type="submit" id="submitBtn" class="w-full bg-[#0f172a] text-white text-[10px] font-bold py-4 rounded-xl hover:bg-black transition">
                                SEND FEEDBACK
                            </button>
                        </form>
                    </div>

                    <div>
                        <h4 class="text-[10px] font-bold text-slate-800 uppercase tracking-widest mb-4">GSD Insights</h4>
                        <div class="text-[13px] text-slate-500 leading-relaxed italic" id="mReasoning">--</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('videoModal');
        const player = document.getElementById('modalPlayer');

        function openModal(data, url) {
            document.getElementById('mName').textContent = data.name;
            document.getElementById('mReasoning').textContent = data.reasoning;
            document.getElementById('fCandidateId').value = data.id;
            player.src = url;
            modal.classList.remove('hidden');
            setTimeout(() => modal.classList.remove('opacity-0'), 10);
            player.play().catch(()=>{});
        }

        function closeModal() {
            modal.classList.add('opacity-0');
            setTimeout(() => { modal.classList.add('hidden'); player.pause(); player.src = ""; }, 300);
        }

        document.getElementById('feedbackForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            btn.innerHTML = "SENDING..."; btn.disabled = true;

            const formData = new FormData(this);
            const response = await fetch('api/save_feedback.php', { method: 'POST', body: formData });
            const result = await response.json();

            if(result.status === 'success') {
                btn.innerHTML = "SENT!";
                btn.classList.replace('bg-[#0f172a]', 'bg-green-600');
                setTimeout(() => { closeModal(); location.reload(); }, 1500);
            }
        });
    </script>
</body>
</html>
