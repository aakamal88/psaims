=================================================================
PANDUAN INTEGRASI EVIDENCE UPLOAD KE assessment.php
=================================================================

File ini berisi 3 snippet yang perlu ditambahkan ke pages/assessment.php

---

## SNIPPET 1: Require helper di atas file assessment.php

Tambahkan setelah require config.php:

```php
require_once __DIR__ . '/../includes/evidence.php';
```

---

## SNIPPET 2: HTML untuk upload area (taruh setelah kolom "Kondisi Saat Ini / Evidence")

Cari bagian textarea evidence di loop render pertanyaan, di bawahnya tambahkan:

```php
<!-- Evidence File Upload Area -->
<div class="form-group mt-2">
    <label style="font-size:12px;">
        <i class="fas fa-paperclip text-secondary"></i> Lampiran Evidence (optional)
    </label>

    <!-- File list existing -->
    <div class="evidence-files-list" data-question-id="<?= $q['id'] ?>"
         data-session-id="<?= $active_session['id'] ?>">
        <?php
        $existing_files = getEvidenceFiles($active_session['id'], $q['id'], $user['id']);
        foreach ($existing_files as $f):
            [$icon, $color] = getFileIcon($f['file_extension']);
        ?>
            <div class="evidence-file-item" data-file-id="<?= $f['id'] ?>">
                <i class="fas <?= $icon ?> text-<?= $color ?>"></i>
                <a href="<?= BASE_URL ?>pages/download_evidence.php?id=<?= $f['id'] ?>"
                   target="_blank" class="evidence-file-link">
                    <?= e($f['original_name']) ?>
                </a>
                <span class="evidence-file-size"><?= formatFileSize($f['file_size']) ?></span>
                <?php if (!$is_locked_status): ?>
                    <button type="button" class="btn-remove-evidence" title="Hapus">
                        <i class="fas fa-times"></i>
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$is_locked_status): ?>
        <!-- Upload button -->
        <div class="evidence-upload-wrap"
             data-question-id="<?= $q['id'] ?>"
             data-element-id="<?= $element['id'] ?>"
             data-element-number="<?= $element['element_number'] ?>"
             data-element-name="<?= e($element['element_name']) ?>"
             data-session-id="<?= $active_session['id'] ?>">
            <label class="btn btn-sm btn-outline-primary mb-0" style="cursor:pointer;">
                <i class="fas fa-upload"></i> Upload File
                <input type="file" class="evidence-file-input" multiple
                       style="display:none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv,.mp4,.mov">
            </label>
            <small class="text-muted ml-2">Max 10MB/file · Multi-file OK</small>
        </div>

        <!-- Progress -->
        <div class="evidence-progress mt-2" style="display:none;">
            <div class="progress" style="height:18px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">
                    <span class="progress-text">0%</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
```

NOTE: `$is_locked_status` adalah variabel yang Anda set kalau `verification_status` = 'submitted' atau 'verified'. Kalau belum ada, bisa set default `$is_locked_status = false`.

---

## SNIPPET 3: JavaScript untuk handle upload (taruh sebelum </body> atau di script section)

Tambahkan di bagian bawah file, sebelum `require_once footer.php`:

```html
<script>
jQuery(function($) {
    const BASE = '<?= BASE_URL ?>';

    // Handle file input change → upload
    $(document).on('change', '.evidence-file-input', function() {
        const files = this.files;
        if (!files.length) return;

        const $wrap = $(this).closest('.evidence-upload-wrap');
        const $listWrap = $wrap.closest('.form-group').find('.evidence-files-list');
        const $progress = $wrap.siblings('.evidence-progress');
        const $progressBar = $progress.find('.progress-bar');
        const $progressText = $progress.find('.progress-text');

        const ctx = {
            session_id:     $wrap.data('session-id'),
            question_id:    $wrap.data('question-id'),
            element_id:     $wrap.data('element-id'),
            element_number: $wrap.data('element-number'),
            element_name:   $wrap.data('element-name'),
        };

        // Upload satu per satu
        uploadFilesSequential(files, 0, ctx, $progress, $progressBar, $progressText, $listWrap);

        // Reset input supaya bisa upload file yang sama lagi
        $(this).val('');
    });

    function uploadFilesSequential(files, index, ctx, $progress, $progressBar, $progressText, $listWrap) {
        if (index >= files.length) {
            $progress.fadeOut();
            return;
        }

        const file = files[index];
        const total = files.length;

        $progress.show();
        $progressBar.css('width', '0%').removeClass('bg-danger').addClass('bg-info');
        $progressText.text(`Upload ${index + 1}/${total}: ${file.name}`);

        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('file', file);
        $.each(ctx, function(key, val) { fd.append(key, val); });

        $.ajax({
            url: BASE + 'pages/ajax_evidence.php',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(evt) {
                    if (evt.lengthComputable) {
                        const pct = Math.round((evt.loaded / evt.total) * 100);
                        $progressBar.css('width', pct + '%');
                        $progressText.text(`${index + 1}/${total}: ${pct}%`);
                    }
                }, false);
                return xhr;
            },
            success: function(res) {
                if (res.ok) {
                    // Tambahkan ke list
                    appendFileItem($listWrap, res.file);
                    $progressBar.css('width', '100%').removeClass('bg-info').addClass('bg-success');
                    setTimeout(() => uploadFilesSequential(files, index + 1, ctx, $progress, $progressBar, $progressText, $listWrap), 300);
                } else {
                    $progressBar.removeClass('bg-info').addClass('bg-danger');
                    $progressText.text('Gagal: ' + res.error);
                    alert('Upload gagal: ' + res.error);
                    setTimeout(() => uploadFilesSequential(files, index + 1, ctx, $progress, $progressBar, $progressText, $listWrap), 1000);
                }
            },
            error: function(xhr) {
                $progressBar.removeClass('bg-info').addClass('bg-danger');
                $progressText.text('Error jaringan');
                alert('Error: ' + (xhr.responseText || 'Unknown error'));
                setTimeout(() => uploadFilesSequential(files, index + 1, ctx, $progress, $progressBar, $progressText, $listWrap), 1000);
            }
        });
    }

    function appendFileItem($listWrap, file) {
        const iconMap = {
            pdf: ['fa-file-pdf','danger'], doc: ['fa-file-word','primary'], docx: ['fa-file-word','primary'],
            xls: ['fa-file-excel','success'], xlsx: ['fa-file-excel','success'],
            ppt: ['fa-file-powerpoint','warning'], pptx: ['fa-file-powerpoint','warning'],
            jpg: ['fa-file-image','info'], jpeg: ['fa-file-image','info'],
            png: ['fa-file-image','info'], gif: ['fa-file-image','info'],
            zip: ['fa-file-archive','secondary'], txt: ['fa-file-alt','secondary'],
            csv: ['fa-file-csv','success'], mp4: ['fa-file-video','danger'], mov: ['fa-file-video','danger'],
        };
        const [icon, color] = iconMap[file.file_extension] || ['fa-file','secondary'];

        const html = `
            <div class="evidence-file-item" data-file-id="${file.id}">
                <i class="fas ${icon} text-${color}"></i>
                <a href="${BASE}pages/download_evidence.php?id=${file.id}" target="_blank" class="evidence-file-link">
                    ${escapeHtml(file.original_name)}
                </a>
                <span class="evidence-file-size">${file.size_formatted}</span>
                <button type="button" class="btn-remove-evidence" title="Hapus">
                    <i class="fas fa-times"></i>
                </button>
            </div>`;
        $listWrap.append(html);
    }

    function escapeHtml(text) {
        const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'};
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    // Handle delete
    $(document).on('click', '.btn-remove-evidence', function(e) {
        e.preventDefault();
        const $item = $(this).closest('.evidence-file-item');
        const fileId = $item.data('file-id');

        if (!confirm('Hapus file ini?')) return;

        $.post(BASE + 'pages/ajax_evidence.php', {
            action: 'delete', file_id: fileId
        }).done(function(res) {
            if (res.ok) {
                $item.fadeOut(200, function() { $(this).remove(); });
            } else {
                alert('Gagal hapus: ' + res.error);
            }
        }).fail(function() {
            alert('Error jaringan');
        });
    });
});
</script>

<style>
.evidence-files-list { margin-top: 6px; }
.evidence-file-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    font-size: 12px;
    margin: 2px 4px 2px 0;
}
.evidence-file-item:hover {
    background: #e9ecef;
}
.evidence-file-link {
    color: #333;
    text-decoration: none;
    font-weight: 500;
}
.evidence-file-link:hover {
    color: #007bff;
    text-decoration: underline;
}
.evidence-file-size {
    color: #888;
    font-size: 11px;
}
.btn-remove-evidence {
    background: none;
    border: none;
    color: #dc3545;
    cursor: pointer;
    padding: 0 4px;
    font-size: 14px;
}
.btn-remove-evidence:hover {
    color: #721c24;
}
.evidence-progress .progress-bar {
    transition: width 0.2s;
}
</style>
```

---

## Tempat Idealnya:

Di `assessment.php`, layoutnya biasanya seperti ini per pertanyaan:

```
┌─ Pertanyaan 1 ──────────────────────────────┐
│ Kriteria:                                    │
│ Nilai Pemenuhan: [radio buttons]             │
│ Evidence:        [textarea]                  │
│ → TARUH SNIPPET 2 DI SINI ←                  │
│ Gap Analysis:    [textarea]                  │
│ Action Plan:     [textarea]                  │
│ Target Date:     [date]                      │
│ PIC:             [text]                      │
└─────────────────────────────────────────────┘
```

## Kalau Mau Saya Bantu Merge Otomatis:

Upload file `pages/assessment.php` versi Anda sekarang, nanti saya generate versi baru yang sudah include semua upload feature + progress bar + delete handler. Tinggal timpa.