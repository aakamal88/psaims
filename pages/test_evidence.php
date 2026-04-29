<?php
// =====================================================
// DIAGNOSTIC SCRIPT v2 — Auto-detect lokasi
// Bisa ditaruh di root ATAU di pages/
// =====================================================

// Auto-detect root folder
$current_dir = __DIR__;
$root_dir = null;

// Cek kalau kita di pages/
if (file_exists($current_dir . '/../config/config.php')) {
    $root_dir = realpath($current_dir . '/..');
}
// Cek kalau kita di root
elseif (file_exists($current_dir . '/config/config.php')) {
    $root_dir = $current_dir;
}

if (!$root_dir) {
    die('<pre style="color:red;">ERROR: Tidak bisa menemukan config/config.php.
Pastikan file test_evidence.php ditaruh di:
- C:\\inetpub\\wwwroot\\PTG_PSAIMS\\test_evidence.php (root), atau
- C:\\inetpub\\wwwroot\\PTG_PSAIMS\\pages\\test_evidence.php (pages/)</pre>');
}

require_once $root_dir . '/config/config.php';
require_once $root_dir . '/includes/evidence.php';

requireLogin();

if (!canAdminister()) {
    die('Akses ditolak. Hanya admin.');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Evidence Storage Diagnostic v2</title>
    <style>
        body { font-family: Consolas, monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; font-size: 13px; }
        h2 { color: #4ec9b0; border-bottom: 1px solid #444; padding-bottom: 5px; margin-top: 30px; }
        .ok { color: #4ec9b0; }
        .fail { color: #f48771; font-weight: bold; }
        .warn { color: #dcdcaa; }
        .info { color: #9cdcfe; }
        table { border-collapse: collapse; margin: 10px 0; font-size: 12px; }
        td, th { padding: 6px 12px; border: 1px solid #444; text-align: left; }
        th { background: #252526; color: #4ec9b0; }
        pre { background: #252526; padding: 10px; border-radius: 4px; overflow-x: auto; color: #ce9178; }
        code { background: #252526; padding: 2px 6px; border-radius: 3px; color: #ce9178; }
    </style>
</head>
<body>
<h1>🔍 Evidence Storage Diagnostic v2</h1>
<p class="info">User: <?= e($_SESSION['username'] ?? '?') ?> (ID: <?= $_SESSION['user_id'] ?? '?' ?>)</p>
<p class="info">Root detected: <code><?= e($root_dir) ?></code></p>

<h2>1. Settings dari Database</h2>
<?php
$settings = getEvidenceSettings();
echo '<table>';
foreach ($settings as $key => $val) {
    echo '<tr><td><strong>' . e($key) . '</strong></td><td><code>' . e($val) . '</code></td></tr>';
}
echo '</table>';
?>

<h2>2. Cek Base Path</h2>
<?php
$base = $settings['evidence_base_path'];
echo "Base path dari DB: <code>" . e($base) . "</code><br>";
echo "Panjang string: <strong>" . strlen($base) . "</strong> karakter<br>";
echo "Hex dump: <code>" . bin2hex($base) . "</code><br><br>";

// Normalize: handle double backslash atau forward slash
$base_normalized = str_replace('/', DIRECTORY_SEPARATOR, $base);
$base_normalized = str_replace('\\\\', '\\', $base_normalized);
echo "Normalized: <code>" . e($base_normalized) . "</code><br><br>";

if (is_dir($base)) {
    echo '<span class="ok">✓ Folder ADA di <code>' . e($base) . '</code></span><br>';
} elseif (is_dir($base_normalized)) {
    echo '<span class="warn">⚠ Folder ada di versi normalized: <code>' . e($base_normalized) . '</code></span><br>';
    echo '<span class="warn">→ Saran: update DB dengan path yang benar</span><br>';
    $base = $base_normalized;
} else {
    echo '<span class="fail">✗ Folder TIDAK ADA</span><br>';
    echo '<span class="warn">→ Mencoba auto-create folder <code>' . e($base) . '</code>...</span><br>';
    if (@mkdir($base, 0755, true)) {
        echo '<span class="ok">✓ Berhasil create folder</span><br>';
    } else {
        $err = error_get_last();
        echo '<span class="fail">✗ Gagal create: ' . e($err['message'] ?? 'unknown') . '</span><br>';
    }
}

if (is_dir($base)) {
    if (is_writable($base)) {
        echo '<span class="ok">✓ Folder WRITABLE</span><br>';
    } else {
        echo '<span class="fail">✗ Folder TIDAK WRITABLE (ini masalah utama!)</span><br>';
        echo '<pre>Jalankan di PowerShell as Admin:
icacls "' . e($base) . '" /grant "IIS_IUSRS:(OI)(CI)M" /T</pre>';
    }
}
?>

<h2>3. Info PHP Runtime</h2>
<table>
<tr><td>PHP version</td><td><?= PHP_VERSION ?></td></tr>
<tr><td>OS</td><td><?= PHP_OS ?></td></tr>
<tr><td>upload_max_filesize</td><td><?= ini_get('upload_max_filesize') ?></td></tr>
<tr><td>post_max_size</td><td><?= ini_get('post_max_size') ?></td></tr>
<tr><td>max_file_uploads</td><td><?= ini_get('max_file_uploads') ?></td></tr>
<tr><td>file_uploads</td><td><?= ini_get('file_uploads') ? 'On ✓' : 'Off ✗' ?></td></tr>
<tr><td>upload_tmp_dir</td><td><?= ini_get('upload_tmp_dir') ?: sys_get_temp_dir() ?></td></tr>
<tr><td>open_basedir</td><td><?= ini_get('open_basedir') ?: '(tidak dibatasi)' ?></td></tr>
<tr><td>Current user PHP</td><td><?= get_current_user() ?></td></tr>
</table>

<h2>4. Test Create Subfolder + File (Live Test)</h2>
<?php
if (is_dir($base) && is_writable($base)) {
    $test_folder = $base . DIRECTORY_SEPARATOR . '99_TEST';
    $test_file = $test_folder . DIRECTORY_SEPARATOR . 'test_' . date('YmdHis') . '.txt';

    echo "Test folder: <code>" . e($test_folder) . "</code><br>";
    echo "Test file: <code>" . e($test_file) . "</code><br><br>";

    // Create subfolder
    if (!is_dir($test_folder)) {
        if (@mkdir($test_folder, 0755, true)) {
            echo '<span class="ok">✓ Subfolder test berhasil dibuat</span><br>';
        } else {
            $err = error_get_last();
            echo '<span class="fail">✗ Gagal create subfolder: ' . e($err['message'] ?? 'unknown') . '</span><br>';
        }
    } else {
        echo '<span class="info">ℹ Subfolder test sudah ada sebelumnya</span><br>';
    }

    // Write test file
    if (is_dir($test_folder)) {
        $bytes = @file_put_contents($test_file, 'Test evidence write ' . date('Y-m-d H:i:s'));
        if ($bytes !== false) {
            echo '<span class="ok">✓ File test berhasil ditulis (' . $bytes . ' bytes)</span><br>';
            echo '<span class="info">→ Cek di Windows Explorer: ' . e($test_file) . '</span><br>';

            // Cleanup
            if (@unlink($test_file)) {
                echo '<span class="ok">✓ File test berhasil dihapus (cleanup)</span><br>';
            }
            if (@rmdir($test_folder)) {
                echo '<span class="ok">✓ Subfolder test berhasil dihapus (cleanup)</span><br>';
            }
            echo '<br><span class="ok" style="font-size:16px;">🎉 WRITE TEST LULUS — PHP bisa tulis file di base path!</span><br>';
            echo '<span class="warn">Jadi issue bukan di permission folder.</span>';
        } else {
            $err = error_get_last();
            echo '<span class="fail">✗ Gagal write file: ' . e($err['message'] ?? 'unknown') . '</span><br>';
        }
    }
} else {
    echo '<span class="fail">Skip — base path tidak OK</span>';
}
?>

<h2>5. Data evidence_files di Database</h2>
<?php
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM evidence_files");
    $total = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM evidence_files WHERE is_deleted = FALSE");
    $active = $stmt->fetchColumn();

    echo "Total records: <strong>{$total}</strong><br>";
    echo "Active (is_deleted=false): <strong>{$active}</strong><br><br>";

    if ($total > 0) {
        $stmt = $pdo->query(
            "SELECT id, original_name, stored_name, relative_path, file_size, is_deleted, uploaded_at, uploaded_by
             FROM evidence_files ORDER BY id DESC LIMIT 10"
        );
        echo '<table>';
        echo '<tr><th>ID</th><th>Original</th><th>Stored</th><th>Path</th><th>Size</th><th>Deleted</th><th>Uploaded</th><th>By</th><th>File Exists?</th></tr>';
        while ($row = $stmt->fetch()) {
            $full = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['relative_path']);
            $exists = file_exists($full);
            $exists_html = $exists
                ? '<span class="ok">✓</span>'
                : '<span class="fail">✗ ' . e($full) . '</span>';
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>" . e(mb_strimwidth($row['original_name'], 0, 30, '…')) . "</td>";
            echo "<td>" . e(mb_strimwidth($row['stored_name'], 0, 40, '…')) . "</td>";
            echo "<td>" . e($row['relative_path']) . "</td>";
            echo "<td>" . formatFileSize($row['file_size']) . "</td>";
            echo "<td>" . ($row['is_deleted'] ? 'YES' : 'no') . "</td>";
            echo "<td>" . e($row['uploaded_at']) . "</td>";
            echo "<td>" . e($row['uploaded_by']) . "</td>";
            echo "<td>{$exists_html}</td>";
            echo "</tr>";
        }
        echo '</table>';
    } else {
        echo '<span class="warn">⚠ evidence_files KOSONG — upload belum pernah berhasil insert ke DB.</span><br>';
        echo '<span class="info">Artinya upload gagal SEBELUM insert DB. Kemungkinan:</span>';
        echo '<ul>';
        echo '<li>AJAX endpoint error (cek Network tab browser)</li>';
        echo '<li>Move_uploaded_file gagal</li>';
        echo '<li>Validasi file gagal (extension tidak ada di whitelist, size terlalu besar)</li>';
        echo '</ul>';
    }
} catch (Exception $e) {
    echo '<span class="fail">Error query: ' . e($e->getMessage()) . '</span>';
}
?>

<h2>6. Test AJAX Endpoint Langsung</h2>
<p class="info">Test apakah endpoint <code>ajax_evidence.php</code> bisa diakses:</p>
<button id="btnTestAjax" style="padding:8px 20px; cursor:pointer;">Test GET list (via AJAX)</button>
<pre id="ajaxResult" style="margin-top:10px;">Klik tombol untuk test...</pre>

<h2>7. Scan Folder Fisik</h2>
<?php
if (is_dir($base)) {
    $items = @scandir($base);
    if ($items === false) {
        echo '<span class="fail">Gagal baca folder</span>';
    } else {
        $items = array_diff($items, ['.', '..']);
        if (empty($items)) {
            echo '<span class="warn">⚠ Folder KOSONG — tidak ada file atau subfolder.</span><br>';
            echo '<span class="info">Normal kalau belum pernah ada upload berhasil.</span>';
        } else {
            echo '<strong>Isi folder ' . e($base) . ':</strong><br><ul>';
            foreach ($items as $item) {
                $full = $base . DIRECTORY_SEPARATOR . $item;
                if (is_dir($full)) {
                    $sub = @scandir($full);
                    $sub_count = $sub ? count(array_diff($sub, ['.', '..'])) : 0;
                    echo '<li>📁 <strong>' . e($item) . '/</strong> ('.$sub_count.' items)</li>';
                } else {
                    echo '<li>📄 ' . e($item) . ' (' . formatFileSize(filesize($full)) . ')</li>';
                }
            }
            echo '</ul>';
        }
    }
}
?>

<hr>
<p class="warn">⚠ HAPUS <code>test_evidence.php</code> setelah debug selesai.</p>

<script>
document.getElementById('btnTestAjax').addEventListener('click', function() {
    const $out = document.getElementById('ajaxResult');
    $out.textContent = 'Loading...';

    // Determine AJAX URL berdasarkan script location
    const isInPages = window.location.pathname.indexOf('/pages/') >= 0;
    const ajaxUrl = isInPages
        ? 'ajax_evidence.php?action=list&session_id=1&question_id=1'
        : 'pages/ajax_evidence.php?action=list&session_id=1&question_id=1';

    fetch(ajaxUrl, { credentials: 'same-origin' })
        .then(r => r.text())
        .then(t => {
            $out.textContent = 'URL: ' + ajaxUrl + '\n\nResponse:\n' + t;
            try {
                const json = JSON.parse(t);
                $out.style.color = json.ok ? '#4ec9b0' : '#f48771';
            } catch(e) {
                $out.style.color = '#f48771';
            }
        })
        .catch(err => {
            $out.textContent = 'Error: ' + err;
            $out.style.color = '#f48771';
        });
});
</script>
</body>
</html>