=================================================================
PATCH UNTUK sidebar.php — TAMBAH MENU EVIDENCE FILES
=================================================================

Di file C:\inetpub\wwwroot\PTG_PSAIMS\includes\sidebar.php,
cari bagian section VERIFIKASI:

```php
<?php if (function_exists('canVerify') && canVerify()): ?>
<li class="nav-header">VERIFIKASI</li>
<li class="nav-item">
    <a href="<?= BASE_URL ?>pages/verification.php"
       class="nav-link <?= $current_page == 'verification.php' ? 'active' : '' ?>">
        <i class="nav-icon fas fa-clipboard-check text-warning"></i>
        <p>
            Verifikasi Assessment
            <?php if ($pending_verification > 0): ?>
                <span class="badge badge-warning right"><?= $pending_verification ?></span>
            <?php endif; ?>
        </p>
    </a>
</li>
<?php endif; ?>
```

TAMBAHKAN menu Evidence Files BARU di dalam block VERIFIKASI (sebelum `<?php endif; ?>`):

```php
<li class="nav-item">
    <a href="<?= BASE_URL ?>pages/evidence_list.php"
       class="nav-link <?= $current_page == 'evidence_list.php' ? 'active' : '' ?>">
        <i class="nav-icon fas fa-paperclip text-info"></i>
        <p>Evidence Files</p>
    </a>
</li>
```

Hasil akhir section VERIFIKASI:

```php
<?php if (function_exists('canVerify') && canVerify()): ?>
<li class="nav-header">VERIFIKASI</li>
<li class="nav-item">
    <a href="<?= BASE_URL ?>pages/verification.php"
       class="nav-link <?= $current_page == 'verification.php' ? 'active' : '' ?>">
        <i class="nav-icon fas fa-clipboard-check text-warning"></i>
        <p>
            Verifikasi Assessment
            <?php if ($pending_verification > 0): ?>
                <span class="badge badge-warning right"><?= $pending_verification ?></span>
            <?php endif; ?>
        </p>
    </a>
</li>
<li class="nav-item">
    <a href="<?= BASE_URL ?>pages/evidence_list.php"
       class="nav-link <?= $current_page == 'evidence_list.php' ? 'active' : '' ?>">
        <i class="nav-icon fas fa-paperclip text-info"></i>
        <p>Evidence Files</p>
    </a>
</li>
<?php endif; ?>
```

JUGA: di section ADMINISTRASI, tambahkan link ke Evidence Settings
(optional, tapi recommended):

```php
<li class="nav-item">
    <a href="<?= BASE_URL ?>pages/evidence_settings.php"
       class="nav-link <?= $current_page == 'evidence_settings.php' ? 'active' : '' ?>">
        <i class="nav-icon fas fa-folder-tree"></i>
        <p>Evidence Settings</p>
    </a>
</li>
```