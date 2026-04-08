<?php
require_once __DIR__ . '/../config/Database.php';

// 1. Validar Token del Candidato
$token = $_GET['token'] ?? null;
$preEvaluator = $_GET['evaluator'] ?? ''; // Opcional si Sara lo manda en el link
$clientRef = $_GET['client'] ?? 'direct-link'; // Para saber de qué cliente viene el feedback

if (!$token) { die("Invalid Access."); }

$database = new Database();
$pdo = $database->getConnection();

// 2. Obtener datos del Candidato
$stmt = $pdo->prepare("SELECT id, name, professional_title, video_processed_path, video_original_path, match_reasoning FROM gsd_candidates WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$c = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$c) { die("Candidate Not Found."); }

// 3. Lógica de Video (Procesado > Original)
$vPath = !empty($c['video_processed_path']) ? $c['video_processed_path'] : $c['video_original_path'];
$parts = explode('uploads', (string) $vPath);
$clean = (strpos((string) $vPath, 'uploads') !== false) ? 'uploads' . end($parts) : ltrim((string) $vPath, '/');
$videoUrl = rtrim(gsdRecruitmentUploadsBaseUrl(), '/') . '/' . ltrim($clean, '/\\');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interview Review | <?php echo htmlspecialchars($c['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #020617; color: #f8fafc; overflow: hidden; }
        
        /* Estrellas */
        .star-rating { direction: rtl; display: flex; gap: 8px; justify-content: flex-end; }
        .star-rating input { display: none; }
        .star-rating label { color: #334155; font-size: 1.8rem; cursor: pointer; transition: 0.2s; }
        .star-rating input:checked ~ label, .star-rating label:hover, .star-rating label:hover ~ label { color: #fbbf24; }
        
        .custom-scroll::-webkit-scrollbar { width: 4px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 10px; }
    </style>
</head>
<body class="h-screen flex flex-col md:flex-row">

    <!-- LADO IZQUIERDO: REPRODUCTOR (70%) -->
    <div class="flex-1 bg-black flex items-center justify-center relative">
        <video controls autoplay class="w-full h-full object-contain shadow-2xl">
            <source src="<?php echo $videoUrl; ?>" type="video/mp4">
            Tu navegador no soporta el video.
        </video>
        <!-- Logo Flotante -->
        <div class="absolute top-8 left-8 opacity-40">
            <img src="../assets/images/iconGSD.png" class="h-6">
        </div>
    </div>

    <!-- LADO DERECHO: SIDEBAR DE FEEDBACK (30%) -->
    <div class="w-full md:w-[350px] lg:w-[400px] bg-slate-950 border-l border-slate-900 flex flex-col shadow-2xl">
        
        <!-- Header del Candidato -->
        <div class="p-8 border-b border-slate-900 bg-slate-900/20">
            <h1 class="text-2xl font-black tracking-tighter uppercase leading-none"><?php echo $c['name']; ?></h1>
            <p class="text-purple-500 font-bold text-[10px] uppercase tracking-[0.2em] mt-3">
                <?php echo $c['professional_title']; ?>
            </p>
        </div>

        <div class="flex-1 overflow-y-auto custom-scroll p-8 space-y-10">
            
            <!-- FORMULARIO DE FEEDBACK -->
            <div class="bg-slate-900/40 p-6 rounded-3xl border border-slate-800 shadow-inner">
                <h4 class="text-[10px] font-bold text-slate-500 uppercase mb-4 tracking-widest italic text-center">Your Evaluation</h4>
                
                <form id="candidateFeedbackForm" class="space-y-5">
                    <input type="hidden" name="candidate_id" value="<?php echo $c['id']; ?>">
                    <input type="hidden" name="client_token" value="<?php echo htmlspecialchars($clientRef); ?>">

                    <!-- Estrellas -->
                    <div class="star-rating">
                        <input type="radio" id="s5" name="rating" value="5" required><label for="s5"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="s4" name="rating" value="4"><label for="s4"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="s3" name="rating" value="3"><label for="s3"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="s2" name="rating" value="2"><label for="s2"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="s1" name="rating" value="1"><label for="s1"><i class="fa-solid fa-star"></i></label>
                    </div>

                    <!-- Nombre evaluador -->
                    <div class="space-y-1">
                        <label class="text-[9px] font-bold text-slate-500 uppercase ml-2">Evaluator Name</label>
                        <input type="text" name="evaluator" value="<?php echo htmlspecialchars($preEvaluator); ?>" required
                               placeholder="e.g. Hiring Manager" 
                               class="w-full bg-slate-950 border border-slate-800 text-slate-200 p-3 rounded-xl text-xs outline-none focus:border-purple-600 transition">
                    </div>

                    <!-- Comentario -->
                    <div class="space-y-1">
                        <label class="text-[9px] font-bold text-slate-500 uppercase ml-2">Notes / Observations</label>
                        <textarea name="comment" placeholder="Write your thoughts here..." 
                                  class="w-full bg-slate-950 border border-slate-800 text-slate-400 p-3 rounded-xl text-xs outline-none focus:border-purple-600 transition h-24 resize-none"></textarea>
                    </div>

                    <button type="submit" id="submitBtn" class="w-full bg-white text-black font-black text-[11px] py-4 rounded-xl hover:bg-purple-600 hover:text-white transition-all shadow-lg uppercase tracking-widest">
                        Save Feedback
                    </button>
                </form>
            </div>

            <!-- GSD INSIGHTS (OPCIONAL) -->
            <div class="opacity-60 hover:opacity-100 transition duration-500">
                <h4 class="text-[10px] font-bold text-purple-500 uppercase tracking-widest mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-brain"></i> GSD Insights
                </h4>
                <div class="text-[12px] text-slate-400 leading-relaxed italic border-l-2 border-slate-800 pl-4">
                    "<?php echo $c['match_reasoning']; ?>"
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div class="p-6 text-center border-t border-slate-900 opacity-20">
            <p class="text-[8px] font-bold tracking-[0.5em] uppercase text-white">Get Stuff Done Talent Network</p>
        </div>
    </div>

    <script>
        document.getElementById('candidateFeedbackForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = document.getElementById('submitBtn');
            const formContainer = this.parentElement;
            
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> SAVING...';
            btn.disabled = true;
    
            try {
                const formData = new FormData(this);
                
                // CORRECCIÓN DE RUTA: Agregamos ../ para salir de la carpeta 'viewer'
                const response = await fetch('api/save_feedback_candidate.php', { 
                    method: 'POST', 
                    body: formData 
                });
                
                // Validamos si la respuesta es correcta antes de intentar leer el JSON
                if (!response.ok) {
                    throw new Error('Server returned ' + response.status + ' - Not Found');
                }
    
                const result = await response.json();
    
                if(result.status === 'success') {
                    formContainer.innerHTML = `
                        <div class="py-10 text-center animate-in fade-in duration-500">
                            <div class="w-20 h-20 bg-green-500/20 text-green-500 rounded-full flex items-center justify-center mx-auto mb-6 border border-green-500/30">
                                <i class="fa-solid fa-check text-3xl"></i>
                            </div>
                            <h4 class="text-white font-black uppercase tracking-[0.2em] text-sm">Feedback Received</h4>
                            <p class="text-slate-500 text-xs mt-3 italic leading-relaxed">
                                Thank you. Your evaluation has been stored successfully.
                            </p>
                        </div>
                    `;
                } else {
                    throw new Error(result.message || 'Error en la base de datos');
                }
            } catch (error) {
                console.error("Error detallado:", error);
                btn.innerHTML = "RETRY SAVING";
                btn.disabled = false;
                
                // Mostramos un mensaje más claro del error
                alert("Error: " + error.message + ". Verifica que el archivo api/save_feedback_candidate.php existe en la carpeta correcta.");
            }
        });
    </script>
</body>
</html>
