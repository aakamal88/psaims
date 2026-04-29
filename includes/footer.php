<!-- Main Footer -->
<footer class="main-footer">
    <div class="footer-grid">
        <div class="footer-left">
            <i class="fas fa-user-cog text-muted" style="font-size:11px;"></i>
            <span class="text-muted" style="font-size:12px;">Created by</span>
            <strong style="color:#1e3c72;">Ahmad Kamaludin</strong>
            <span class="text-muted" style="font-size:12px;">— Asset Reliability and Integrity</span>
        </div>
        <div class="footer-center">
            <strong>Copyright &copy; <?= date('Y') ?>
                <a href="#"><?= APP_AUTHOR ?></a>.</strong>
            All rights reserved.
        </div>
        <div class="footer-right d-none d-sm-block">
            <b>Version</b> <?= APP_VERSION ?>
        </div>
    </div>
</footer>

<style>
.main-footer {
    padding: 10px 1rem !important;
    line-height: 1.5;
}
.main-footer .footer-grid {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 12px;
}
.main-footer .footer-left {
    font-size: 12px;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
}
.main-footer .footer-left i {
    margin-right: 2px;
}
.main-footer .footer-center {
    font-size: 13px;
    text-align: center;
    white-space: nowrap;
}
.main-footer .footer-center a {
    color: #1e3c72;
    font-weight: 500;
}
.main-footer .footer-right {
    font-size: 13px;
    text-align: right;
    white-space: nowrap;
    color: #6c757d;
}
.main-footer .footer-right b {
    font-weight: 500;
}

/* Mobile/Tablet: stack vertikal semua center */
@media (max-width: 991px) {
    .main-footer .footer-grid {
        grid-template-columns: 1fr;
        gap: 4px;
        text-align: center;
    }
    .main-footer .footer-left,
    .main-footer .footer-center,
    .main-footer .footer-right {
        text-align: center;
        white-space: normal;
    }
}
</style>

</div>
<!-- ./wrapper -->

<!-- ============ JavaScript ============ -->
<!-- jQuery -->
<script src="<?= ADMINLTE_PATH ?>plugins/jquery/jquery.min.js"></script>
<!-- jQuery UI -->
<script src="<?= ADMINLTE_PATH ?>plugins/jquery-ui/jquery-ui.min.js"></script>
<script>
  // Resolve conflict in jQuery UI tooltip with Bootstrap tooltip
  $.widget.bridge('uibutton', $.ui.button);
</script>

<!-- Bootstrap 4 -->
<script src="<?= ADMINLTE_PATH ?>plugins/bootstrap/js/bootstrap.bundle.min.js"></script>

<!-- ChartJS -->
<script src="<?= ADMINLTE_PATH ?>plugins/chart.js/Chart.min.js"></script>

<!-- Sparkline -->
<script src="<?= ADMINLTE_PATH ?>plugins/sparklines/sparkline.js"></script>

<!-- JQVMap -->
<script src="<?= ADMINLTE_PATH ?>plugins/jqvmap/jquery.vmap.min.js"></script>
<script src="<?= ADMINLTE_PATH ?>plugins/jqvmap/maps/jquery.vmap.usa.js"></script>

<!-- jQuery Knob Chart -->
<script src="<?= ADMINLTE_PATH ?>plugins/jquery-knob/jquery.knob.min.js"></script>

<!-- daterangepicker -->
<script src="<?= ADMINLTE_PATH ?>plugins/moment/moment.min.js"></script>
<script src="<?= ADMINLTE_PATH ?>plugins/daterangepicker/daterangepicker.js"></script>

<!-- Tempusdominus Bootstrap 4 -->
<script src="<?= ADMINLTE_PATH ?>plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>

<!-- Summernote -->
<script src="<?= ADMINLTE_PATH ?>plugins/summernote/summernote-bs4.min.js"></script>

<!-- overlayScrollbars -->
<script src="<?= ADMINLTE_PATH ?>plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>

<!-- DataTables -->
<script src="<?= ADMINLTE_PATH ?>plugins/datatables/jquery.dataTables.min.js"></script>
<script src="<?= ADMINLTE_PATH ?>plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="<?= ADMINLTE_PATH ?>plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
<script src="<?= ADMINLTE_PATH ?>plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>

<!-- SweetAlert2 -->
<script src="<?= ADMINLTE_PATH ?>plugins/sweetalert2/sweetalert2.min.js"></script>

<!-- Select2 -->
<script src="<?= ADMINLTE_PATH ?>plugins/select2/js/select2.full.min.js"></script>

<!-- AdminLTE App -->
<script src="<?= ADMINLTE_PATH ?>dist/js/adminlte.js"></script>

<!-- Custom JS -->
<script src="<?= ASSETS_PATH ?>js/custom.js"></script>

<script>
$(function () {
    // Inisialisasi DataTable umum
    $(".datatable").DataTable({
        "responsive": true,
        "autoWidth": false,
        "language": {
            "search": "Cari:",
            "lengthMenu": "Tampilkan _MENU_ data",
            "info": "Menampilkan _START_ - _END_ dari _TOTAL_ data",
            "infoEmpty": "Tidak ada data",
            "zeroRecords": "Tidak ditemukan data",
            "paginate": {
                "first": "Pertama",
                "last": "Terakhir",
                "next": "Berikutnya",
                "previous": "Sebelumnya"
            }
        }
    });

    // Inisialisasi Select2
    $('.select2').select2({ theme: 'bootstrap4' });

    // Summernote dengan tinggi sedang
    $('.summernote').summernote({ height: 180 });
});
</script>

</body>
</html>