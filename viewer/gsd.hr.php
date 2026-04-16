<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/helpers.php';
$MASTER_TOKEN = "GSD-HR-Sara-Collazos";
$token = $_GET['token'] ?? null;
if ($token !== $MASTER_TOKEN) { header("Location: https://gsdoutsource.com/"); exit; }

$database = new Database();
$pdo = $database->getConnection();
$visibleStatuses = gsdViewerStatusListSql();

// Consulta principal
$sql = "SELECT c.*, 
        AVG(f.rating) as avg_rating, 
        COUNT(DISTINCT f.id) as total_feedback,
        GROUP_CONCAT(DISTINCT t.name SEPARATOR ',') as tags,
        (SELECT COUNT(c2.id) FROM gsd_candidates c2 WHERE c2.email = c.email AND ".gsdViewerVisibleCandidateClause('c2').") as version_count
        FROM gsd_candidates c 
        LEFT JOIN gsd_candidate_feedback f ON c.id = f.candidate_id
        LEFT JOIN gsd_candidate_tag_map ctm ON c.id = ctm.candidate_id
        LEFT JOIN gsd_tags t ON ctm.tag_id = t.id
        WHERE ".gsdViewerVisibleCandidateClause('c')."
        GROUP BY c.id
        ORDER BY c.is_main DESC, c.name ASC";

$candidates = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Obtener todas las etiquetas disponibles para el filtro y el modal
$allTags = $pdo->query("SELECT * FROM gsd_tags ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

function renderStars($rating) {
    $fullStars = round($rating);
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        $color = ($i <= $fullStars) ? 'text-yellow-400' : 'text-slate-200';
        $html .= '<i class="fa-solid fa-star '.$color.' text-[10px]"></i>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GSD Master Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="bg-slate-50">
    <!-- Navbar -->
    <nav class="bg-white border-b border-slate-200 sticky top-0 z-50 shadow-sm p-6 flex justify-between items-center">
        <div class="flex items-center gap-4">
            <img src="../assets/images/iconGSD.png" alt="Logo" class="h-8">
            <h1 class="font-bold text-lg text-slate-700 uppercase tracking-tighter">GSD <span class="text-purple-700 text-sm">INTERNAL MASTER</span></h1>
        </div>
        
        <div class="flex items-center gap-6">
            <div class="relative hidden md:block">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-400 text-sm"></i>
                <input type="text" id="searchInput" placeholder="Search by name or title..." class="pl-10 pr-4 py-2 bg-slate-100 border-none rounded-xl text-sm w-64 focus:ring-2 focus:ring-purple-500 outline-none transition-all">
            </div>
            
            <!-- SOLUCIÓN 2: ICONO DE PAPELERA PARA VER RECHAZADOS -->
            <div class="relative cursor-pointer group" onclick="openTrashModal()" title="View Rejected Candidates">
                <i class="fa-solid fa-trash-can text-slate-400 text-xl group-hover:text-red-500 transition"></i>
            </div>

            <!-- Notificaciones -->
            <div class="relative cursor-pointer group" onclick="showNotificationDetails()">
                <i class="fa-solid fa-bell text-slate-400 text-xl group-hover:text-purple-600 transition"></i>
                <span id="notifBadge" class="hidden absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white text-[9px] font-black rounded-full flex items-center justify-center border-2 border-white">0</span>
                <div id="notifDropdown" class="hidden absolute right-0 mt-3 w-64 bg-white shadow-2xl rounded-2xl border border-slate-100 p-4 z-50 animate-in slide-in-from-top-2">
                    <h5 class="text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest">Recent Updates</h5>
                    <div id="notifContent" class="space-y-3"></div>
                </div>
            </div>
    
            <div class="flex items-center gap-3 border-l pl-6 border-slate-200">
                <span class="text-[10px] font-bold bg-purple-100 text-purple-700 px-3 py-1 rounded-full border border-purple-200 uppercase tracking-widest">Sara Master</span>
            </div>
        </div>
    </nav>
    
    <div id="toastContainer" class="fixed bottom-6 right-6 z-[200] space-y-3"></div>

    <!-- Toolbar: Filtros -->
    <div class="container mx-auto px-6 mt-6">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Filter by tags:</span>
            <button onclick="filterByTag('all')" class="filter-tag-btn px-3 py-1 bg-purple-600 text-white text-[10px] font-bold rounded-lg border border-purple-600">ALL</button>
            <?php foreach($allTags as $tag): ?>
                <button onclick="filterByTag('<?php echo $tag['name']; ?>')" class="filter-tag-btn px-3 py-1 bg-white text-slate-500 text-[10px] font-bold rounded-lg border border-slate-200 hover:border-purple-300 transition-all"><?php echo strtoupper($tag['name']); ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Grid de Candidatos -->
    <div class="container mx-auto px-6 py-8">
        <div id="candidatesGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
            <?php foreach ($candidates as $c): 
                $previewMp4Url = gsdViewerCandidateStreamUrl($c, 'mp4');
                $previewFallbackUrl = gsdViewerCandidateStreamUrl($c);
                $tagArray = $c['tags'] ? explode(',', $c['tags']) :[];
            ?>
            <div class="candidate-card relative bg-white rounded-[2.2rem] shadow-sm border border-slate-200 overflow-hidden p-3 hover:shadow-xl transition-all duration-300 flex flex-col <?php echo (isset($c['is_main']) && $c['is_main'] == 0) ? 'opacity-70 hover:opacity-100' : ''; ?>"
                 data-candidate-id="<?php echo (int) $c['id']; ?>"
                 data-name="<?php echo strtolower($c['name']); ?>" 
                 data-title="<?php echo strtolower($c['professional_title']); ?>"
                 data-tags="<?php echo strtolower($c['tags']); ?>">
                
                <?php if (isset($c['is_main']) && $c['is_main'] == 1): ?>
                    <div class="absolute top-5 left-5 z-10 pointer-events-none">
                        <span class="bg-green-500 text-white text-[9px] font-black px-3 py-1.5 rounded-full shadow-lg border border-green-600 flex items-center gap-1">
                            <i class="fa-solid fa-star"></i> MAIN VIDEO
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($c['version_count'] > 1): ?>
                    <div class="absolute top-5 right-5 z-10">
                        <button onclick="openVersionsModal(<?php echo (int) $c['id']; ?>, '<?php echo htmlspecialchars($c['email']); ?>')" 
                                class="bg-amber-500 hover:bg-amber-600 text-white text-[10px] font-black px-3 py-1.5 rounded-full shadow-lg border border-amber-600 flex items-center gap-1 transition animate-pulse">
                            <i class="fa-solid fa-layer-group"></i> <?php echo $c['version_count']; ?> VERSIONS
                        </button>
                    </div>
                <?php endif; ?>

                <div class="aspect-video bg-slate-900 rounded-2xl relative overflow-hidden shadow-inner mb-4">
                    <video class="w-full h-full object-cover" controls playsinline webkit-playsinline preload="metadata">
                        <source src="<?php echo htmlspecialchars($previewMp4Url); ?>" type="video/mp4">
                        <source src="<?php echo htmlspecialchars($previewFallbackUrl); ?>" type="video/webm">
                    </video>
                </div>

                <div class="px-2 flex-1">
                    <div class="flex justify-between items-start mb-2">
                        <div>
                            <h4 class="text-xs font-black text-slate-800 truncate"><?php echo htmlspecialchars($c['name']); ?></h4>
                            <p class="text-[9px] text-purple-600 font-bold uppercase tracking-tighter"><?php echo htmlspecialchars($c['professional_title']); ?></p>
                            <p class="text-[9px] text-slate-400 font-bold tracking-tight"><?php echo htmlspecialchars($c['token']); ?></p>
                        </div>
                        <div class="text-right">
                            <div class="flex gap-0.5 justify-end"><?php echo renderStars($c['avg_rating']); ?></div>
                            <p class="text-[8px] font-bold text-slate-400 uppercase"><?php echo $c['total_feedback']; ?> Reviews</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1 mb-3">
                        <?php foreach($tagArray as $tName): ?>
                            <?php if(!empty($tName)): ?>
                                <span class="px-2 py-0.5 bg-slate-100 text-slate-500 text-[8px] font-bold rounded-md border border-slate-200 uppercase tracking-tighter"><?php echo htmlspecialchars($tName); ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <button onclick="openTagManager(<?php echo $c['id']; ?>, '<?php echo addslashes($c['name']); ?>')" class="w-5 h-5 flex items-center justify-center bg-purple-50 text-purple-500 rounded-md border border-purple-100 hover:bg-purple-500 hover:text-white transition-all text-[10px]"><i class="fa-solid fa-plus"></i></button>
                    </div>

                    <button onclick="viewFeedbacks(<?php echo $c['id']; ?>, '<?php echo addslashes($c['name']); ?>')" class="w-full py-2 bg-slate-50 border border-slate-100 text-slate-500 text-[9px] font-bold rounded-xl hover:bg-slate-100 transition flex items-center justify-center gap-2">
                        <i class="fa-solid fa-comment-dots text-purple-500"></i> VIEW FEEDBACKS
                    </button>
                </div>

                <div class="mt-4 px-2 pb-2 border-t border-slate-50 pt-4 flex gap-2">
                    <button onclick="copyLink(this, '<?php echo $c['token']; ?>')" class="flex-1 py-2 bg-slate-900 text-white text-[10px] font-bold rounded-xl hover:bg-black transition flex items-center justify-center gap-2 shadow-sm">
                        <i class="fa-solid fa-link"></i> COPY LINK
                    </button>
                    
                    <!-- EDIT BUTTON (Pasamos también el email) -->
                    <button onclick="openEditModal(<?php echo $c['id']; ?>, '<?php echo addslashes($c['name']); ?>', '<?php echo addslashes($c['professional_title']); ?>', '<?php echo addslashes($c['email']); ?>')" class="w-9 h-9 flex items-center justify-center bg-blue-50 text-blue-600 border border-blue-100 rounded-xl hover:bg-blue-600 hover:text-white transition shadow-sm" title="Edit">
                        <i class="fa-solid fa-pen text-[10px]"></i>
                    </button>

                    <!-- REJECT BUTTON (Rechazar candidato, en vez de borrar duro) -->
                    <button onclick="rejectCandidate(<?php echo $c['id']; ?>, '<?php echo addslashes($c['name']); ?>')" class="w-9 h-9 flex items-center justify-center bg-red-50 text-red-600 border border-red-100 rounded-xl hover:bg-red-600 hover:text-white transition shadow-sm" title="Reject / Send to Trash">
                        <i class="fa-solid fa-trash text-[10px]"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ================= MODALES ================= -->

    <!-- SOLUCIÓN 1: MODAL DE VERSIONES (VIDEOS MÁS GRANDES) -->
    <div id="versionsModal" class="fixed inset-0 z-[130] hidden bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4">
        <!-- Aumentado a max-w-3xl para que quepan videos más grandes -->
        <div class="bg-white w-full max-w-3xl rounded-[2.5rem] shadow-2xl p-8 max-h-[90vh] flex flex-col">
            <h3 class="font-black text-xl text-slate-800 mb-1">Manage Videos</h3>
            <p class="text-[10px] font-bold text-amber-600 uppercase tracking-widest mb-6 italic">Select which video to share with the client</p>
            
            <div id="versionsModalContent" class="overflow-y-auto custom-scroll pr-2 space-y-4 flex-1 mb-6">
                <!-- Se inyecta por JS -->
            </div>

            <button onclick="closeModal('versionsModal')" class="w-full py-3 bg-slate-900 text-white text-[10px] font-bold rounded-xl uppercase tracking-widest hover:bg-black transition">Done / Close</button>
        </div>
    </div>

    <!-- SOLUCIÓN 2: MODAL PAPELERA (RECHAZADOS) -->
    <div id="trashModal" class="fixed inset-0 z-[130] hidden bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-2xl rounded-[2.5rem] shadow-2xl p-8 max-h-[90vh] flex flex-col">
            <h3 class="font-black text-xl text-slate-800 mb-1"><i class="fa-solid fa-trash-can text-red-500 mr-2"></i> Rejected Candidates</h3>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-6">Candidates sent to trash</p>
            
            <div id="trashModalContent" class="overflow-y-auto custom-scroll pr-2 space-y-3 flex-1 mb-6"></div>

            <button onclick="closeModal('trashModal')" class="w-full py-3 bg-slate-900 text-white text-[10px] font-bold rounded-xl uppercase tracking-widest hover:bg-black transition">Close</button>
        </div>
    </div>

    <!-- SOLUCIÓN 3: MODAL EDITAR CANDIDATO -->
    <div id="editModal" class="fixed inset-0 z-[140] hidden bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-[2.5rem] shadow-2xl p-8">
            <h3 class="font-black text-xl text-slate-800 mb-6">Edit Candidate Info</h3>
            
            <form id="editCandidateForm" onsubmit="submitEditForm(event)">
                <input type="hidden" id="editCandidateId">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Full Name</label>
                        <input type="text" id="editName" required class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-purple-500">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Email</label>
                        <input type="email" id="editEmail" required class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-purple-500">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Professional Title</label>
                        <input type="text" id="editTitle" required class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-purple-500">
                    </div>
                </div>

                <div class="flex gap-3 mt-8">
                    <button type="button" onclick="closeModal('editModal')" class="flex-1 py-3 bg-slate-100 text-slate-600 text-[10px] font-bold rounded-xl uppercase hover:bg-slate-200 transition">Cancel</button>
                    <button type="submit" id="btnSaveEdit" class="flex-1 py-3 bg-purple-600 text-white text-[10px] font-bold rounded-xl uppercase hover:bg-purple-700 transition">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SOLUCIÓN 4: MODAL FEEDBACKS -->
    <div id="feedbackModal" class="fixed inset-0 z-[140] hidden bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-lg rounded-[2.5rem] shadow-2xl p-8 max-h-[90vh] flex flex-col">
            <h3 class="font-black text-xl text-slate-800 mb-1">Client Feedbacks</h3>
            <p id="feedbackCandidateName" class="text-[10px] font-bold text-purple-600 uppercase tracking-widest mb-6"></p>
            
            <div id="feedbackModalContent" class="overflow-y-auto custom-scroll pr-2 space-y-4 flex-1 mb-6"></div>

            <button onclick="closeModal('feedbackModal')" class="w-full py-3 bg-slate-900 text-white text-[10px] font-bold rounded-xl uppercase tracking-widest hover:bg-black transition">Close</button>
        </div>
    </div>

    <!-- SOLUCIÓN 5: MODAL TAG MANAGER -->
    <div id="tagModal" class="fixed inset-0 z-[140] hidden bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-[2.5rem] shadow-2xl p-8">
            <h3 class="font-black text-xl text-slate-800 mb-1">Manage Tags</h3>
            <p id="tagCandidateName" class="text-[10px] font-bold text-purple-600 uppercase tracking-widest mb-6"></p>
            
            <!-- Lista de tags actuales -->
            <div id="currentTagsContainer" class="flex flex-wrap gap-2 mb-6"></div>

            <hr class="border-slate-100 mb-6">

            <!-- Agregar nuevo tag -->
            <div class="flex gap-2">
                <select id="newTagSelect" class="flex-1 px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-purple-500">
                    <option value="">-- Select a Tag --</option>
                    <?php foreach($allTags as $tag): ?>
                        <option value="<?php echo $tag['id']; ?>"><?php echo strtoupper($tag['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button onclick="addTagToCandidate()" class="px-4 py-2 bg-purple-600 text-white rounded-xl text-[10px] font-bold uppercase hover:bg-purple-700 transition">Add</button>
            </div>

            <input type="hidden" id="tagCandidateId">
            <button onclick="closeModal('tagModal')" class="w-full mt-8 py-3 bg-slate-900 text-white text-[10px] font-bold rounded-xl uppercase tracking-widest hover:bg-black transition">Done</button>
        </div>
    </div>


    <!-- SCRIPTS -->
    <script>
        // Función utilitaria para cerrar cualquier modal
        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
        }

        // --- SOLUCIÓN 1: GESTIONAR VERSIONES (VIDEOS MÁS GRANDES) ---
        async function openVersionsModal(candidateId, email) {
            document.getElementById('versionsModal').classList.remove('hidden');
            const content = document.getElementById('versionsModalContent');
            content.innerHTML = '<div class="text-center py-10"><i class="fa-solid fa-spinner fa-spin text-2xl text-amber-500"></i><p class="mt-2 text-sm text-slate-500">Loading versions...</p></div>';
            
            try {
                const res = await fetch(`api/get_candidate_videos.php?candidate_id=${encodeURIComponent(candidateId)}&email=${encodeURIComponent(email)}`);
                const versions = await res.json();
                
                if(versions.length === 0) {
                    content.innerHTML = '<p class="text-center text-sm text-slate-500 py-6">No versions found.</p>';
                    return;
                }

                let html = '';
                versions.forEach(v => {
                    const isMain = parseInt(v.is_main) === 1;
                    const mp4Url = v.mp4_stream_url || v.stream_url || '';
                    const streamUrl = v.stream_url || mp4Url;

                    // Cambiado w-24 a w-48 o aspect-video w-full md:w-64 para que el video sea mucho más grande y claro
                    html += `
                        <div class="flex flex-col md:flex-row items-center justify-between p-4 border ${isMain ? 'border-green-400 bg-green-50' : 'border-slate-200 bg-slate-50'} rounded-2xl gap-6 shadow-sm">
                            <div class="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
                                <video class="w-full md:w-56 aspect-video object-cover rounded-xl bg-black shadow-inner" controls playsinline preload="metadata">
                                    <source src="${mp4Url}" type="video/mp4">
                                    <source src="${streamUrl}" type="video/webm">
                                </video>
                                <div class="text-center md:text-left">
                                    <p class="text-xs font-bold text-slate-800">Uploaded: ${v.created_at || 'Unknown Date'}</p>
                                    <p class="mt-1 text-[10px] font-bold text-slate-400">Token: ${v.token || 'N/A'}</p>
                                    ${isMain 
                                        ? '<span class="inline-block mt-2 text-[10px] font-black text-green-700 bg-green-200 px-3 py-1 rounded-md uppercase tracking-widest border border-green-300">MAIN VIDEO</span>' 
                                        : '<span class="inline-block mt-2 text-[10px] font-black text-slate-500 bg-slate-200 px-3 py-1 rounded-md uppercase tracking-widest border border-slate-300">Alternate</span>'}
                                </div>
                            </div>
                            <div class="w-full md:w-auto text-right">
                                ${!isMain 
                                    ? `<button onclick="setMainVideo(${v.id}, '${v.email}', ${candidateId}, this)" class="w-full bg-slate-900 text-white text-[10px] font-bold px-6 py-3 rounded-xl hover:bg-amber-500 transition shadow">SET AS MAIN</button>` 
                                    : `<button class="w-full bg-green-200 text-green-800 text-[10px] font-bold px-6 py-3 rounded-xl cursor-not-allowed border border-green-300 shadow-sm" disabled><i class="fa-solid fa-check"></i> ACTIVE LINK</button>`}
                            </div>
                        </div>
                    `;
                });
                content.innerHTML = html;
            } catch (e) {
                content.innerHTML = '<p class="text-red-500 text-sm text-center py-6">Error loading versions.</p>';
            }
        }

        async function setMainVideo(id, email, candidateId, btn) {
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            const fd = new FormData();
            fd.append('action', 'set_main'); fd.append('id', id); fd.append('email', email);
            try {
                const res = await fetch('api/manage_candidate.php', { method: 'POST', body: fd });
                if (res.ok) {
                    showToast('Main video updated!', 'green');
                    openVersionsModal(candidateId, email); 
                    setTimeout(() => location.reload(), 1200);
                }
            } catch (e) {}
        }


        // --- SOLUCIÓN 2: RECHAZAR Y PAPELERA ---
        function rejectCandidate(id, name) {
            if (confirm(`Are you sure you want to REJECT ${name}? They will be moved to the trash.`)) {
                const fd = new FormData();
                fd.append('action', 'reject'); // Esto cambia el status a 'rejected' en la BD
                fd.append('id', id);
                
                fetch('api/manage_candidate.php', { method: 'POST', body: fd })
                    .then(res => {
                        if(res.ok) {
                            showToast(`${name} has been moved to trash.`, 'red');
                            setTimeout(() => location.reload(), 1000);
                        }
                    });
            }
        }

        async function openTrashModal() {
            document.getElementById('trashModal').classList.remove('hidden');
            const content = document.getElementById('trashModalContent');
            content.innerHTML = '<div class="text-center py-10"><i class="fa-solid fa-spinner fa-spin text-xl text-slate-400"></i></div>';
            
            try {
                // El backend debe devolver los candidatos con status 'rejected'
                const res = await fetch(`api/manage_candidate.php?action=get_rejected`);
                const candidates = await res.json();
                
                if(!candidates || candidates.length === 0) {
                    content.innerHTML = '<p class="text-center text-sm text-slate-500 py-6">Trash is empty.</p>';
                    return;
                }

                let html = '';
                candidates.forEach(c => {
                    html += `
                        <div class="flex items-center justify-between p-3 border border-slate-200 bg-slate-50 rounded-xl">
                            <div>
                                <p class="text-xs font-bold text-slate-800">${c.name}</p>
                                <p class="text-[9px] text-slate-500 uppercase">${c.professional_title}</p>
                            </div>
                            <button onclick="restoreCandidate(${c.id}, '${c.name}')" class="bg-blue-100 text-blue-600 px-3 py-1.5 rounded-lg text-[9px] font-bold hover:bg-blue-600 hover:text-white transition"><i class="fa-solid fa-rotate-left"></i> RESTORE</button>
                        </div>
                    `;
                });
                content.innerHTML = html;
            } catch (e) {
                content.innerHTML = '<p class="text-center text-red-500 py-4">Make sure API action=get_rejected exists.</p>';
            }
        }

        function restoreCandidate(id, name) {
            const fd = new FormData();
            fd.append('action', 'restore'); // Vuelve el status a 'pending' o 'reviewing'
            fd.append('id', id);
            
            fetch('api/manage_candidate.php', { method: 'POST', body: fd })
                .then(res => {
                    if(res.ok) {
                        showToast(`${name} restored successfully.`, 'green');
                        openTrashModal(); // Recargar lista
                        setTimeout(() => location.reload(), 1000); // Recargar página
                    }
                });
        }


        // --- SOLUCIÓN 3: MODAL EDITAR ---
        function openEditModal(id, name, title, email) {
            document.getElementById('editModal').classList.remove('hidden');
            document.getElementById('editCandidateId').value = id;
            document.getElementById('editName').value = name;
            document.getElementById('editTitle').value = title;
            document.getElementById('editEmail').value = email;
        }

        async function submitEditForm(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSaveEdit');
            btn.innerHTML = 'Saving...'; btn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'edit_candidate');
            fd.append('id', document.getElementById('editCandidateId').value);
            fd.append('name', document.getElementById('editName').value);
            fd.append('title', document.getElementById('editTitle').value);
            fd.append('email', document.getElementById('editEmail').value);

            try {
                const res = await fetch('api/manage_candidate.php', { method: 'POST', body: fd });
                if(res.ok) {
                    showToast('Candidate info updated!', 'green');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    alert('Failed to update candidate');
                }
            } catch (err) { console.error(err); }
            
            btn.innerHTML = 'Save Changes'; btn.disabled = false;
        }


        // --- SOLUCIÓN 4: MODAL FEEDBACKS ---
        async function viewFeedbacks(id, name) {
            document.getElementById('feedbackModal').classList.remove('hidden');
            document.getElementById('feedbackCandidateName').innerText = `Feedback for: ${name}`;
            const content = document.getElementById('feedbackModalContent');
            content.innerHTML = '<div class="text-center py-6"><i class="fa-solid fa-spinner fa-spin text-xl text-purple-500"></i></div>';

            try {
                const res = await fetch(`api/manage_candidate.php?action=get_feedbacks&id=${id}`);
                const feedbacks = await res.json();

                if(!feedbacks || feedbacks.length === 0) {
                    content.innerHTML = '<p class="text-center text-sm text-slate-500 py-6">No feedbacks received yet.</p>';
                    return;
                }

                let html = '';
                feedbacks.forEach(f => {
                    let stars = '';
                    for(let i=1; i<=5; i++) {
                        stars += `<i class="fa-solid fa-star ${i <= f.rating ? 'text-yellow-400' : 'text-slate-200'} text-[10px]"></i>`;
                    }
                    html += `
                        <div class="p-4 bg-slate-50 rounded-xl border border-slate-100 mb-3">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-[10px] font-black text-slate-700">${f.evaluator_name || 'Anonymous Client'}</span>
                                <div class="flex gap-0.5">${stars}</div>
                            </div>
                            <p class="text-xs text-slate-600 italic">"${f.comments || 'No comments provided.'}"</p>
                            <p class="text-[8px] text-slate-400 mt-2 text-right">${f.created_at}</p>
                        </div>
                    `;
                });
                content.innerHTML = html;
            } catch (err) {
                content.innerHTML = '<p class="text-center text-red-500 py-4">Make sure API action=get_feedbacks exists.</p>';
            }
        }


        // --- SOLUCIÓN 5: MODAL TAGS ---
        async function openTagManager(id, name) {
            document.getElementById('tagModal').classList.remove('hidden');
            document.getElementById('tagCandidateId').value = id;
            document.getElementById('tagCandidateName').innerText = name;
            loadCandidateTags(id);
        }

        async function loadCandidateTags(id) {
            const container = document.getElementById('currentTagsContainer');
            container.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-slate-400 text-sm"></i>';
            try {
                const res = await fetch(`api/manage_candidate.php?action=get_tags&id=${id}`);
                const tags = await res.json();
                
                container.innerHTML = '';
                if(tags.length === 0) container.innerHTML = '<p class="text-xs text-slate-400 italic">No tags assigned.</p>';
                
                tags.forEach(t => {
                    container.innerHTML += `
                        <span class="px-3 py-1 bg-purple-100 text-purple-700 text-[10px] font-bold rounded-lg flex items-center gap-2 border border-purple-200">
                            ${t.name.toUpperCase()}
                            <button onclick="removeTag(${id}, ${t.id})" class="hover:text-red-500 transition"><i class="fa-solid fa-xmark"></i></button>
                        </span>
                    `;
                });
            } catch (e) {
                container.innerHTML = '<span class="text-red-500 text-xs">Error loading tags</span>';
            }
        }

        function addTagToCandidate() {
            const id = document.getElementById('tagCandidateId').value;
            const tagId = document.getElementById('newTagSelect').value;
            if(!tagId) return;

            const fd = new FormData();
            fd.append('action', 'add_tag'); fd.append('id', id); fd.append('tag_id', tagId);
            
            fetch('api/manage_candidate.php', { method: 'POST', body: fd }).then(() => {
                loadCandidateTags(id);
                document.getElementById('newTagSelect').value = "";
                // Opcional: recargar web al cerrar para ver tags en la card principal
            });
        }

        function removeTag(candidateId, tagId) {
            const fd = new FormData();
            fd.append('action', 'remove_tag'); fd.append('id', candidateId); fd.append('tag_id', tagId);
            fetch('api/manage_candidate.php', { method: 'POST', body: fd }).then(() => {
                loadCandidateTags(candidateId);
            });
        }


        // --- SISTEMA TOASTS Y COPIAR (COMO ANTES) ---
        function showToast(message, type = 'purple') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            let icon = 'fa-bell', borderColor = 'border-purple-500', iconColor = 'text-purple-400 bg-purple-500/20';
            if(type === 'blue') { icon = 'fa-pen'; borderColor = 'border-blue-500'; iconColor = 'text-blue-400 bg-blue-500/20'; }
            if(type === 'red') { icon = 'fa-trash'; borderColor = 'border-red-500'; iconColor = 'text-red-400 bg-red-500/20'; }
            if(type === 'green') { icon = 'fa-check'; borderColor = 'border-green-500'; iconColor = 'text-green-400 bg-green-500/20'; }
            
            toast.className = `flex items-center gap-4 bg-slate-900 text-white p-4 rounded-2xl shadow-2xl border-l-4 ${borderColor} min-w-[300px] transform transition-all duration-500 translate-y-20 opacity-0`;
            toast.innerHTML = `<div class="w-8 h-8 rounded-full ${iconColor} flex items-center justify-center"><i class="fa-solid ${icon}"></i></div><div class="flex-1"><p class="text-[11px] font-bold leading-tight">${message}</p></div>`;
            container.appendChild(toast);
            setTimeout(() => toast.classList.remove('translate-y-20', 'opacity-0'), 100);
            setTimeout(() => { toast.classList.add('translate-y-20', 'opacity-0'); setTimeout(() => toast.remove(), 500); }, 4000);
        }

        const candidateShareBase = <?php echo json_encode(gsdRecruitmentBaseUrl('viewer/candidate.php')); ?>;

        function copyLink(btn, token) {
            const shareUrl = `${candidateShareBase}?token=${encodeURIComponent(token)}`;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(shareUrl).then(() => { successCopy(btn); });
            } else {
                let ta = document.createElement("textarea"); ta.value = shareUrl; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); successCopy(btn);
            }
        }

        function successCopy(btn) {
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> COPIED!';
            btn.classList.replace('bg-slate-900', 'bg-green-500');
            showToast('Link copied to clipboard!', 'green');
            setTimeout(() => { btn.innerHTML = originalHTML; btn.classList.replace('bg-green-500', 'bg-slate-900'); }, 2000);
        }

        // BÚSQUEDA Y FILTROS
        document.getElementById('searchInput')?.addEventListener('input', function(e) {
            const term = e.target.value.toLowerCase();
            document.querySelectorAll('.candidate-card').forEach(card => {
                card.style.display = (card.getAttribute('data-name').includes(term) || card.getAttribute('data-title').includes(term)) ? 'flex' : 'none';
            });
        });

        function filterByTag(tag) {
            document.querySelectorAll('.candidate-card').forEach(card => {
                card.style.display = (tag === 'all' || (card.getAttribute('data-tags')||'').split(',').includes(tag.toLowerCase())) ? 'flex' : 'none';
            });
            document.querySelectorAll('.filter-tag-btn').forEach(btn => {
                if (btn.innerText.toLowerCase() === tag.toLowerCase()) { btn.classList.add('bg-purple-600', 'text-white'); btn.classList.remove('bg-white', 'text-slate-500'); } 
                else { btn.classList.remove('bg-purple-600', 'text-white'); btn.classList.add('bg-white', 'text-slate-500'); }
            });
        }
        // --- FUNCIONALIDAD: NOTIFICACIONES DROPDOWN ---
        function showNotificationDetails() {
            const dropdown = document.getElementById('notifDropdown');
            if(dropdown) {
                dropdown.classList.toggle('hidden');
            }
        }
    </script>
</body>
</html>
