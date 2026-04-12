<?php
declare(strict_types=1);
session_start();

$root = dirname(__FILE__);
require_once $root . '/config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

$isLoggedIn = !empty($_SESSION['user_id']);
$user       = $_SESSION['user'] ?? [];

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$ads     = [];
$total   = 0;
$pag     = paginate(0, $perPage, 1);

try {
    $db   = db();
    $cntStmt = $db->prepare(
        "SELECT COUNT(*) FROM movie_ads WHERE status = 'approved' AND (expires_at IS NULL OR expires_at > NOW())"
    );
    $cntStmt->execute();
    $total = (int)$cntStmt->fetchColumn();
    $pag   = paginate($total, $perPage, $page);

    $stmt = $db->prepare(
        "SELECT ma.*, u.username FROM movie_ads ma
         LEFT JOIN users u ON u.id = ma.user_id
         WHERE ma.status = 'approved' AND (ma.expires_at IS NULL OR ma.expires_at > NOW())
         ORDER BY ma.starts_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $pag['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $ads = $stmt->fetchAll();

    // Track impressions
    if (!empty($ads)) {
        $ids = implode(',', array_map('intval', array_column($ads, 'id')));
        $db->exec("UPDATE movie_ads SET impression_count = impression_count + 1 WHERE id IN ({$ids})");
    }
} catch (Throwable) {}

$siteUrl = rtrim(getSetting('site_url', ''), '/');

renderHead('Movie Ads');
renderNav($isLoggedIn, $user);
?>
<div style="min-height:80vh;padding:40px 0">
<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="fw-900 mb-1" style="font-size:2rem">🎬 Movies</h1>
            <p class="text-muted mb-0" style="font-size:0.9rem">Discover upcoming and featured movies from independent creators.</p>
        </div>
        <?php if ($isLoggedIn): ?>
        <a href="/advertise" class="btn btn-accent btn-sm">🎬 Advertise Your Movie</a>
        <?php endif; ?>
    </div>

    <?php if (empty($ads)): ?>
    <div class="card-dark p-5 text-center">
        <div style="font-size:3rem;margin-bottom:12px">🎬</div>
        <h5 class="fw-700">No movies featured yet</h5>
        <p class="text-muted" style="font-size:0.9rem;margin-bottom:20px">Be the first to advertise your movie on Clipaza!</p>
        <?php if ($isLoggedIn): ?>
        <a href="/advertise" class="btn btn-accent">Advertise Your Movie</a>
        <?php else: ?>
        <a href="/auth/register" class="btn btn-accent">Sign Up to Advertise</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="row g-4">
        <?php foreach ($ads as $ad): ?>
        <?php
            $trailerEmbed = '';
            if (!empty($ad['trailer_url'])) {
                $ytId = '';
                if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/', $ad['trailer_url'], $m)) {
                    $ytId = $m[1];
                }
                if ($ytId) {
                    $trailerEmbed = 'https://www.youtube.com/embed/' . $ytId;
                }
            }
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="card-dark h-100 d-flex flex-column" style="border:1px solid #1a1a1a;border-radius:12px;overflow:hidden">
                <!-- Poster -->
                <?php if ($ad['poster_path']): ?>
                <div style="aspect-ratio:2/3;overflow:hidden;background:#0d0d0d">
                    <img src="<?= e($siteUrl . $ad['poster_path']) ?>" alt="<?= e($ad['movie_title']) ?>"
                         style="width:100%;height:100%;object-fit:cover">
                </div>
                <?php else: ?>
                <div style="aspect-ratio:2/3;background:#0d0d0d;display:flex;align-items:center;justify-content:center">
                    <span style="font-size:4rem">🎬</span>
                </div>
                <?php endif; ?>

                <div class="p-3 d-flex flex-column flex-grow-1">
                    <!-- Title & Genre -->
                    <div class="fw-700 mb-1" style="font-size:1rem"><?= e($ad['movie_title']) ?></div>
                    <?php if ($ad['genre']): ?>
                    <div class="mb-1">
                        <span class="badge-muted" style="font-size:0.72rem"><?= e($ad['genre']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($ad['tagline']): ?>
                    <div class="text-muted mb-2" style="font-size:0.82rem;font-style:italic">"<?= e($ad['tagline']) ?>"</div>
                    <?php endif; ?>
                    <?php if ($ad['release_date']): ?>
                    <div class="text-muted mb-2" style="font-size:0.78rem">
                        📅 <?= e(formatDate($ad['release_date'], 'M j, Y')) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($ad['description']): ?>
                    <div style="font-size:0.82rem;color:#aaa;margin-bottom:10px;line-height:1.5">
                        <?= e(mb_strimwidth($ad['description'], 0, 120, '…')) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Contact info -->
                    <div class="mt-auto">
                        <?php if ($ad['contact_email'] || $ad['contact_phone'] || $ad['website_url']): ?>
                        <div class="mb-2" style="font-size:0.78rem;color:#888">
                            <?php if ($ad['contact_email']): ?>
                            <div>📧 <a href="mailto:<?= e($ad['contact_email']) ?>" style="color:#aaa"><?= e($ad['contact_email']) ?></a></div>
                            <?php endif; ?>
                            <?php if ($ad['contact_phone']): ?>
                            <div>📞 <?= e($ad['contact_phone']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($trailerEmbed): ?>
                            <button class="btn btn-sm btn-accent trailer-btn"
                                    data-embed="<?= e($trailerEmbed) ?>"
                                    data-title="<?= e($ad['movie_title']) ?>"
                                    style="font-size:0.78rem">
                                ▶ Watch Trailer
                            </button>
                            <?php elseif ($ad['trailer_url']): ?>
                            <a href="<?= e($ad['trailer_url']) ?>" target="_blank"
                               class="btn btn-sm btn-outline-accent" style="font-size:0.78rem">
                                ▶ Trailer
                            </a>
                            <?php endif; ?>
                            <?php if ($ad['website_url']): ?>
                            <a href="<?= e($ad['website_url']) ?>" target="_blank"
                               class="btn btn-sm btn-outline-accent" style="font-size:0.78rem">
                                🌐 Website
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($pag['pages'] > 1): ?>
    <nav class="mt-5"><ul class="pagination pagination-dark justify-content-center">
        <?php for ($i = 1; $i <= $pag['pages']; $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
    </ul></nav>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<!-- Trailer Modal -->
<div class="modal fade modal-dark" id="trailerModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-700" id="trailerModalTitle">Trailer</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" style="aspect-ratio:16/9">
        <iframe id="trailerFrame" src="" allow="autoplay; encrypted-media" allowfullscreen
                style="width:100%;height:100%;border:0;border-radius:0 0 8px 8px"></iframe>
      </div>
    </div>
  </div>
</div>

<?php renderFooter(); ?>
<script>
const trailerModalEl = document.getElementById('trailerModal');
const trailerModal   = new bootstrap.Modal(trailerModalEl);

document.querySelectorAll('.trailer-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('trailerModalTitle').textContent = this.dataset.title;
        document.getElementById('trailerFrame').src = this.dataset.embed + '?autoplay=1';
        trailerModal.show();
    });
});

trailerModalEl.addEventListener('hidden.bs.modal', () => {
    document.getElementById('trailerFrame').src = '';
});
</script>
