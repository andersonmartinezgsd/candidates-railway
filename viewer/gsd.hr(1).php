<?php
require_once __DIR__ . '/../config/Database.php';
$MASTER_TOKEN = "GSD-HR-Sara-Collazos";
$token = $_GET['token'] ?? null;
if ($token !== $MASTER_TOKEN) { header("Location: https://gsdoutsource.com/"); exit; }

$database = new Database();
$pdo = $database->getConnection();
$visibleStatuses = "'completed', 'client_review', 'interviewing', 'pending', 'reviewing'";

// SQL MEJORADO: Trae rating, total feedback y etiquetas concatenadas
$sql = "SELECT c.*, 
        AVG(f.rating) as avg_rating, 
        COUNT(f.id) as total_feedback,
        GROUP_CONCAT(DISTINCT t.name SEPARATOR ',') as tags
        FROM gsd_candidates c 
        LEFT JOIN gsd_candidate_feedback f ON c.id = f.candidate_id
        LEFT JOIN gsd_candidate_tag_map ctm ON c.id = ctm.candidate_id
        LEFT JOIN gsd_tags t ON ctm.tag_id = t.id
        WHERE c.processing_status IN ($visibleStatuses) 
        GROUP BY c.id
        ORDER BY name ASC";

$candidates = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Obtener todas las etiquetas disponibles para el filtro
$allTags = $pdo->query("SELECT * FROM gsd_tags ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

function fixPath($rawPath) {
    if (empty($rawPath)) return '';
    $cleanPath = (strpos($rawPath, 'uploads') !== false) ? 'uploads' . end(explode('uploads', $rawPath)) : ltrim($rawPath, '/');
    return '../' . ltrim($cleanPath, '/\\');
}

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
        .custom-scroll::-webkit-scrollbar { width: 4px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .tag-pill { transition: all 0.2s; }
        .tag-pill:hover { filter: brightness(0.9); }
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
                <input type="text" id="searchInput" placeholder="Search by name or title..." 
                       class="pl-10 pr-4 py-2 bg-slate-100 border-none rounded-xl text-sm w-64 focus:ring-2 focus:ring-purple-500 outline-none transition-all">
            </div>
            <!-- CAMPANA DE NOTIFICACIÓN -->
            <div class="relative cursor-pointer group" onclick="showNotificationDetails()">
                <i class="fa-solid fa-bell text-slate-400 text-xl group-hover:text-purple-600 transition"></i>
                <!-- El puntito rojo -->
                <span id="notifBadge" class="hidden absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white text-[9px] font-black rounded-full flex items-center justify-center border-2 border-white">0</span>
                
                <!-- Tooltip / Mini menú desplegable -->
                <div id="notifDropdown" class="hidden absolute right-0 mt-3 w-64 bg-white shadow-2xl rounded-2xl border border-slate-100 p-4 z-50 animate-in slide-in-from-top-2">
                    <h5 class="text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest">Recent Updates</h5>
                    <div id="notifContent" class="space-y-3">
                        <!-- Dinámico -->
                    </div>
                </div>
            </div>
    
            <div class="flex items-center gap-3 border-l pl-6 border-slate-200">
                <span class="text-[10px] font-bold bg-purple-100 text-purple-700 px-3 py-1 rounded-full border border-purple-200 uppercase tracking-widest">Sara Master</span>
            </div>
        </div>
    </nav>
    
    <!-- CONTENEDOR DE TOASTS (Flotante abajo a la derecha) -->
    <div id="toastContainer" class="fixed bottom-6 right-6 z-[200] space-y-3"></div>

    <!-- Toolbar: Filtros -->
    <div class="container mx-auto px-6 mt-6">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Filter by tags:</span>
            <button onclick="filterByTag('all')" class="filter-tag-btn px-3 py-1 bg-purple-600 text-white text-[10px] font-bold rounded-lg border border-purple-600">ALL</button>
            <?php foreach($allTags as $tag): ?>
                <button onclick="filterByTag('<?php echo $tag['name']; ?>')" 
                        class="filter-tag-btn px-3 py-1 bg-white text-slate-500 text-[10px] font-bold rounded-lg border border-slate-200 hover:border-purple-300 transition-all">
                    <?php echo strtoupper($tag['name']); ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Grid -->
    <div class="container mx-auto px-6 py-8">
        <div id="candidatesGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
            <?php foreach ($candidates as $c): 
                $vPath = !empty($c['video_processed_path']) ? $c['video_processed_path'] : $c['video_original_path'];
                $urlFinal = fixPath($vPath);
                $tagArray = $c['tags'] ? explode(',', $c['tags']) : [];
            ?>
            <div class="candidate-card bg-white rounded-[2.2rem] shadow-sm border border-slate-200 overflow-hidden p-3 hover:shadow-xl transition-all duration-300 flex flex-col"
                 data-name="<?php echo strtolower($c['name']); ?>" 
                 data-title="<?php echo strtolower($c['professional_title']); ?>"
                 data-tags="<?php echo strtolower($c['tags']); ?>">
                
                <!-- VIDEO -->
                <div class="aspect-video bg-slate-900 rounded-2xl relative overflow-hidden shadow-inner mb-4">
                    <video class="w-full h-full object-cover" controls preload="none">
                        <source src="<?php echo $urlFinal; ?>" type="video/mp4">
                    </video>
                </div>

                <!-- INFO & RATING -->
                <div class="px-2 flex-1">
                    <div class="flex justify-between items-start mb-2">
                        <div>
                            <h4 class="text-xs font-black text-slate-800 truncate"><?php echo $c['name']; ?></h4>
                            <p class="text-[9px] text-purple-600 font-bold uppercase tracking-tighter"><?php echo $c['professional_title']; ?></p>
                        </div>
                        <div class="text-right">
                            <div class="flex gap-0.5 justify-end"><?php echo renderStars($c['avg_rating']); ?></div>
                            <p class="text-[8px] font-bold text-slate-400 uppercase"><?php echo $c['total_feedback']; ?> Reviews</p>
                        </div>
                    </div>

                    <!-- Etiquetas del Candidato -->
                    <div class="flex flex-wrap gap-1 mb-3">
                        <?php foreach($tagArray as $tName): ?>
                            <span class="px-2 py-0.5 bg-slate-100 text-slate-500 text-[8px] font-bold rounded-md border border-slate-200 uppercase tracking-tighter">
                                <?php echo $tName; ?>
                            </span>
                        <?php endforeach; ?>
                        <button onclick="openTagManager(<?php echo $c['id']; ?>, '<?php echo addslashes($c['name']); ?>')" 
                                class="w-5 h-5 flex items-center justify-center bg-purple-50 text-purple-500 rounded-md border border-purple-100 hover:bg-purple-500 hover:text-white transition-all text-[10px]">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>

                    <button onclick="viewFeedbacks(<?php echo $c['id']; ?>, '<?php echo addslashes($c['name']); ?>')" 
                            class="w-full py-2 bg-slate-50 border border-slate-100 text-slate-500 text-[9px] font-bold rounded-xl hover:bg-slate-100 transition flex items-center justify-center gap-2">
                        <i class="fa-solid fa-comment-dots text-purple-500"></i> VIEW FEEDBACKS
                    </button>
                </div>

                <div class="mt-4 px-2 pb-2 border-t border-slate-50 pt-4 space-y-2">
                    <button onclick="copyLink(this, '<?php echo $c['token']; ?>')" class="w-full py-2.5 bg-slate-900 text-white text-[10px] font-bold rounded-xl hover:bg-black transition flex items-center justify-center gap-2 shadow-sm">
                        <i class="fa-solid fa-link"></i> COPY LINK
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- MODAL: GESTIÓN DE ETIQUETAS -->
    <div id="tagModal" class="fixed inset-0 z-[110] hidden bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-sm rounded-[2.5rem] shadow-2xl p-8">
            <h3 id="tagModalName" class="font-black text-xl text-slate-800 mb-1">--</h3>
            <p class="text-[10px] font-bold text-purple-600 uppercase tracking-widest mb-6 italic">Manage Candidate Tags</p>
            
            <div id="tagOptionsList" class="grid grid-cols-2 gap-2 mb-6">
                <!-- Etiquetas se cargan aquí -->
            </div>

            <div class="flex gap-2">
                <input type="text" id="newTagName" placeholder="New tag name..." class="flex-1 px-3 py-2 bg-slate-100 border-none rounded-xl text-xs outline-none focus:ring-1 focus:ring-purple-400">
                <button onclick="createNewTag()" class="px-4 py-2 bg-purple-600 text-white rounded-xl text-xs font-bold hover:bg-black transition">ADD</button>
            </div>

            <button onclick="closeTagModal()" class="w-full mt-8 py-3 bg-slate-900 text-white text-[10px] font-bold rounded-xl uppercase tracking-widest">Done</button>
        </div>
    </div>

    <!-- El Modal de Feedbacks (ESTRUCTURA AÑADIDA/CORREGIDA) -->
    <div id="feedbackModal" class="fixed inset-0 z-[100] hidden bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-lg rounded-[2.5rem] shadow-2xl p-8 max-h-[90vh] overflow-y-auto">
            <h3 id="feedbackModalTitle" class="font-black text-xl text-slate-800 mb-4">Loading...</h3>
            <!-- ESTE ID ES CRUCIAL PARA QUE NO HAYA ERROR DE NULL -->
            <div id="feedbackModalContent" class="space-y-4">
                <!-- El contenido (spinner o feedback) se cargará aquí -->
            </div>
            <button onclick="closeFeedbackModal()" class="w-full mt-8 py-3 bg-slate-900 text-white text-[10px] font-bold rounded-xl uppercase tracking-widest">Close</button>
        </div>
    </div>

    <script>
        // --- BUSCADOR Y FILTROS ---
        const searchInput = document.getElementById('searchInput');
        const cards = document.querySelectorAll('.candidate-card');

        function performFilter() {
            const query = searchInput.value.toLowerCase();
            const activeTag = document.querySelector('.filter-tag-btn.bg-purple-600').innerText.toLowerCase();

            cards.forEach(card => {
                const name = card.dataset.name;
                const title = card.dataset.title;
                const tags = card.dataset.tags;

                const matchesSearch = name.includes(query) || title.includes(query);
                const matchesTag = activeTag === 'all' || tags.includes(activeTag);

                card.style.display = (matchesSearch && matchesTag) ? 'flex' : 'none';
            });
        }

        searchInput.addEventListener('input', performFilter);

        function filterByTag(tagName) {
            document.querySelectorAll('.filter-tag-btn').forEach(btn => {
                btn.classList.remove('bg-purple-600', 'text-white');
                btn.classList.add('bg-white', 'text-slate-500');
            });
            event.currentTarget.classList.replace('bg-white', 'bg-purple-600');
            event.currentTarget.classList.replace('text-slate-500', 'text-white');
            performFilter();
        }

        // --- GESTIÓN DE ETIQUETAS (AJAX) ---
        let activeCandidateId = null;

        async function openTagManager(candId, candName) {
            activeCandidateId = candId;
            document.getElementById('tagModalName').innerText = candName;
            document.getElementById('tagModal').classList.remove('hidden');
            loadCandidateTags();
        }

        async function loadCandidateTags() {
            const container = document.getElementById('tagOptionsList');
            container.innerHTML = '<div class="col-span-2 text-center py-4"><i class="fa-solid fa-spinner fa-spin text-purple-500"></i></div>';
            
            try {
                const response = await fetch('api/tags.php?candidate_id=' + activeCandidateId);
                const data = await response.json();
                
                container.innerHTML = data.map(tag => `
                    <button onclick="toggleTag(${tag.id}, ${tag.selected})" 
                            class="px-3 py-2 rounded-xl text-[10px] font-bold border transition-all ${tag.selected ? 'bg-purple-600 border-purple-600 text-white' : 'bg-white border-slate-200 text-slate-400'}">
                        ${tag.name.toUpperCase()}
                    </button>
                `).join('');
            } catch (e) { container.innerHTML = 'Error loading tags'; }
        }

        async function toggleTag(tagId, isSelected) {
            const action = isSelected ? 'remove' : 'add';
            await fetch('api/tags.php', {
                method: 'POST',
                body: JSON.stringify({ candidate_id: activeCandidateId, tag_id: tagId, action: action })
            });
            loadCandidateTags();
        }

        async function createNewTag() {
            const name = document.getElementById('newTagName').value;
            if(!name) return;
            await fetch('api/tags.php', {
                method: 'POST',
                body: JSON.stringify({ action: 'create', name: name })
            });
            document.getElementById('newTagName').value = '';
            loadCandidateTags();
        }

        function closeTagModal() {
            document.getElementById('tagModal').classList.add('hidden');
            location.reload();
        }

        // --- FUNCIONES DE ACCIONES ---
        
        function renderStarsInModal(rating) {
            const fullStars = Math.round(rating);
            let html = '';
            for (let i = 1; i <= 5; i++) {
                const color = (i <= fullStars) ? 'text-yellow-400' : 'text-slate-200';
                html += `<i class="fa-solid fa-star ${color} text-sm"></i>`; 
            }
            return html;
        }
        
        function renderFeedbackList(feedbacks) {
            if (!feedbacks || feedbacks.length === 0) {
                return '<p class="text-center py-6 text-slate-500 italic">No feedback found for this candidate.</p>';
            }
            
            return feedbacks.map(feedback => `
                <div class="p-4 border border-slate-200 rounded-xl bg-white shadow-sm">
                    <div class="flex justify-between items-center mb-2">
                        <p class="text-sm font-bold text-slate-800">${feedback.evaluator_name || 'Unknown Reviewer'}</p>
                        <p class="text-[9px] text-slate-400">${feedback.created_at || 'N/A'}</p>
                    </div>
                    <div class="flex gap-0.5 mb-3">
                        ${renderStarsInModal(feedback.rating)}
                    </div>
                    <p class="text-sm text-slate-700 leading-relaxed">${feedback.comment || 'No comment provided.'}</p>
                </div>
            `).join('');
        }

        async function viewFeedbacks(candidateId, candidateName) {
            const modal = document.getElementById('feedbackModal');
            const modalTitle = document.getElementById('feedbackModalTitle');
            const modalContentContainer = document.getElementById('feedbackModalContent');

            if (!modalContentContainer) {
                console.error("Error: El elemento con id='feedbackModalContent' no fue encontrado en el HTML.");
                modal.classList.remove('hidden');
                modal.querySelector('div').innerHTML = `<p class="text-red-500 p-8">Error: Contenedor de feedback no encontrado.</p><button onclick="closeFeedbackModal()" class="mt-4">Cerrar</button>`;
                return; 
            }

            if (modalTitle) {
                modalTitle.innerText = `Feedback for ${candidateName}`;
            }
            
            modalContentContainer.innerHTML = `<div class="text-center py-10"><i class="fa-solid fa-spinner fa-spin text-2xl text-purple-500"></i><p class="mt-2 text-sm text-slate-500">Loading feedbacks for ${candidateName}...</p></div>`;
            
            modal.classList.remove('hidden');

            try {
                // **LÓGICA AJAX PARA CARGAR EL FEEDBACK**
                // La URL debe apuntar a donde guardaste el script de PHP que acabas de crear.
                const response = await fetch(`api/get_feedbacks.php?id=${candidateId}`);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const feedbacks = await response.json();
                
                // 4. Renderizar los feedbacks en modalContentContainer
                modalContentContainer.innerHTML = renderFeedbackList(feedbacks); 

            } catch (error) {
                console.error("Error fetching feedbacks:", error);
                modalContentContainer.innerHTML = `
                    <div class="p-4 bg-red-100 border border-red-300 text-red-800 rounded-xl">
                        <p class="font-bold mb-1">Error al cargar Feedbacks</p>
                        <p class="text-sm">No se pudieron cargar los datos. Por favor, verifica la consola del navegador para más detalles.</p>
                    </div>
                `;
            }
        }
        
        function copyLink(btn, t) {
            const url = window.location.origin + window.location.pathname.replace('gsd.hr.php', 'candidate.php') + '?token=' + t;
            navigator.clipboard.writeText(url).then(() => {
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> COPIED!';
                btn.classList.add('bg-green-600');
                setTimeout(() => { btn.innerHTML = originalHTML; btn.classList.remove('bg-green-600'); }, 2000);
            });
        }

        function closeFeedbackModal() { document.getElementById('feedbackModal').classList.add('hidden'); }
        // --- SISTEMA DE NOTIFICACIONES & TOASTS ---

        function showToast(message, type = 'purple') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            const icon = type === 'purple' ? 'fa-bell' : 'fa-circle-check';
            
            toast.className = `flex items-center gap-4 bg-slate-900 text-white p-4 rounded-2xl shadow-2xl border-l-4 border-purple-500 min-w-[300px] transform transition-all duration-500 translate-y-20 opacity-0`;
            toast.innerHTML = `
                <div class="w-8 h-8 rounded-full bg-purple-500/20 flex items-center justify-center text-purple-400">
                    <i class="fa-solid ${icon}"></i>
                </div>
                <div class="flex-1">
                    <p class="text-[11px] font-bold leading-tight">${message}</p>
                </div>
            `;
        
            container.appendChild(toast);
        
            setTimeout(() => {
                toast.classList.remove('translate-y-20', 'opacity-0');
            }, 100);
        
            setTimeout(() => {
                toast.classList.add('translate-y-20', 'opacity-0');
                setTimeout(() => toast.remove(), 500);
            }, 5000);
        }
        
        async function checkNotifications() {
            try {
                const response = await fetch('api/get_notifications.php');
                const data = await response.json();
        
                const badge = document.getElementById('notifBadge');
                const content = document.getElementById('notifContent');
        
                if (data.total > 0) {
                    badge.innerText = data.total;
                    badge.classList.remove('hidden');
        
                    let html = '';
                    if (data.unread_feedback > 0) {
                        html += `
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center text-xs"><i class="fa-solid fa-star"></i></div>
                                <div><p class="text-[11px] font-bold text-slate-700">${data.unread_feedback} New Feedback(s)</p></div>
                            </div>
                        `;
                    }
                    if (data.new_candidates > 0) {
                        html += `
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-xs"><i class="fa-solid fa-video"></i></div>
                                <div><p class="text-[11px] font-bold text-slate-700">${data.new_candidates} New Videos added</p></div>
                            </div>
                        `;
                    }
                    content.innerHTML = html;
                } else {
                    badge.classList.add('hidden');
                    content.innerHTML = '<p class="text-xs text-slate-400 italic">No new updates.</p>';
                }
            } catch (e) { console.error("Error fetching notifications"); }
        }
        
        function showNotificationDetails() {
            const dropdown = document.getElementById('notifDropdown');
            dropdown.classList.toggle('hidden');
        }
        
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.relative.cursor-pointer.group')) {
                document.getElementById('notifDropdown').classList.add('hidden');
            }
        });
        
        setInterval(checkNotifications, 60000);
        checkNotifications();
    </script>
</body>
</html>