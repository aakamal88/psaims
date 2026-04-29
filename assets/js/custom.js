/* ===== Custom JS untuk PSAIMS Self Assessment Tool ===== */

$(function() {
    // Tooltip
    $('[data-toggle="tooltip"]').tooltip();

    // Popover
    $('[data-toggle="popover"]').popover();

    // Konfirmasi tombol hapus
    $(document).on('click', '.btn-delete', function(e) {
        e.preventDefault();
        const href = $(this).attr('href');
        Swal.fire({
            title: 'Yakin menghapus data ini?',
            text: 'Data yang dihapus tidak bisa dikembalikan!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor:  '#3085d6',
            confirmButtonText: 'Ya, hapus!',
            cancelButtonText:  'Batal'
        }).then((res) => {
            if (res.isConfirmed) window.location.href = href;
        });
    });

    // Auto-hide alert setelah 5 detik
    setTimeout(function() {
        $('.alert-dismissible').fadeOut('slow');
    }, 5000);

    // Active sidebar menu tetap terbuka kalau ada submenu aktif
    $('.nav-sidebar .nav-treeview a.active')
        .closest('.nav-item')
        .addClass('menu-open');
});
