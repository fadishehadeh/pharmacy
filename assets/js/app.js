$(document).ready(function() {
    $('#sidebarToggle').on('click', function() {
        var sidebar = $('#sidebar');
        if ($(window).width() <= 768) {
            sidebar.toggleClass('show');
        } else {
            sidebar.toggleClass('collapsed');
        }
    });

    if ($.fn.DataTable) {
        $('.data-table').each(function() {
            if (!$.fn.DataTable.isDataTable(this)) {
                $(this).DataTable({
                    pageLength: 25,
                    language: { search: "", searchPlaceholder: "Search...", emptyTable: "No records found" },
                    dom: '<"row"<"col-sm-6"l><"col-sm-6"f>>rtip'
                });
            }
        });
    }

    $('[data-confirm]').on('click', function(e) {
        if (!confirm($(this).data('confirm'))) {
            e.preventDefault();
        }
    });

    setTimeout(function() {
        $('.alert-dismissible').fadeOut(500, function() { $(this).remove(); });
    }, 5000);
});

function formatNumber(n, decimals) {
    decimals = decimals === undefined ? 2 : decimals;
    return parseFloat(n).toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
