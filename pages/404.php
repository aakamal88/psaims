<?php
require_once __DIR__ . '/../config/config.php';
$page_title = 'Halaman Tidak Ditemukan';

if (isLoggedIn()) {
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/sidebar.php';
    echo '<div class="content-wrapper"><section class="content"><div class="container-fluid">';
}
?>
<div class="error-page my-5">
    <h2 class="headline text-warning"> 404</h2>
    <div class="error-content">
        <h3><i class="fas fa-exclamation-triangle text-warning"></i> Oops! Halaman tidak ditemukan.</h3>
        <p>Halaman yang Anda cari tidak ada di sistem.
            <a href="<?= BASE_URL ?>index.php">Kembali ke Dashboard</a>.</p>
    </div>
</div>
<?php
if (isLoggedIn()) {
    echo '</div></section></div>';
    require_once __DIR__ . '/../includes/footer.php';
}
