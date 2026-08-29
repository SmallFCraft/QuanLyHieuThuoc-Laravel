import $ from 'jquery';
import * as bootstrap from 'bootstrap';
import Swal from 'sweetalert2';
import Chart from 'chart.js/auto';
import 'datatables.net-bs5';
import 'datatables.net-responsive-bs5';
import 'datatables.net-buttons-bs5';
import 'datatables.net-buttons/js/buttons.html5.mjs';

window.$ = window.jQuery = $;
window.bootstrap = bootstrap;
window.Swal = Swal;
window.Chart = Chart;

const normalizeCsvCell = (data) => {
    const raw = data == null ? '' : String(data);
    const doc = new DOMParser().parseFromString(raw, 'text/html');
    const text = doc.body.textContent || raw;

    return text.replace(/\s+/g, ' ').trim();
};

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': csrfToken || '',
    },
});

document.addEventListener('DOMContentLoaded', () => {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const adminSidebar = document.getElementById('adminSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    const closeSidebar = () => {
        adminSidebar?.classList.remove('show');
        sidebarOverlay?.classList.remove('show');
        sidebarToggle?.setAttribute('aria-expanded', 'false');
    };

    sidebarToggle?.addEventListener('click', () => {
        const isOpen = adminSidebar?.classList.toggle('show') ?? false;
        sidebarOverlay?.classList.toggle('show', isOpen);
        sidebarToggle.setAttribute('aria-expanded', String(isOpen));
    });

    sidebarOverlay?.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });
});

window.AdminUI = {
    excelButton(options = {}) {
        return {
            text: options.text || '<i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Xuất Excel',
            className: options.className || 'btn btn-sm btn-outline-success',
            action: function (_event, dt) {
                const url = new URL(options.url, window.location.origin);
                const params = typeof options.params === 'function' ? options.params(dt) : (options.params || {});

                Object.entries(params).forEach(([key, value]) => {
                    if (value !== undefined && value !== null && String(value).trim() !== '') {
                        url.searchParams.set(key, value);
                    }
                });

                window.location.href = url.toString();
            },
        };
    },
    csvButton(options = {}) {
        return {
            extend: 'csvHtml5',
            text: options.text || '<i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Xuất CSV',
            className: options.className || 'btn btn-sm btn-outline-success',
            filename: options.filename || 'admin-export',
            title: null,
            bom: true,
            charset: 'utf-8',
            fieldSeparator: ';',
            fieldBoundary: '"',
            extension: '.csv',
            customize: (csv) => `sep=;\r\n${csv}`,
            exportOptions: {
                columns: options.columns || ':visible:not(:last-child)',
                stripHtml: true,
                format: {
                    header: normalizeCsvCell,
                    body: normalizeCsvCell,
                },
            },
        };
    },
    dtLanguage: {
        processing: 'Đang xử lý...',
        search: 'Tìm kiếm:',
        lengthMenu: 'Hiển thị _MENU_ mục',
        info: 'Hiển thị _START_ đến _END_ của _TOTAL_ mục',
        infoEmpty: 'Hiển thị 0 đến 0 của 0 mục',
        infoFiltered: '(được lọc từ _MAX_ mục)',
        loadingRecords: 'Đang tải...',
        zeroRecords: 'Không tìm thấy kết quả',
        emptyTable: 'Không có dữ liệu trong bảng',
        paginate: {
            first: 'Đầu',
            previous: 'Trước',
            next: 'Tiếp',
            last: 'Cuối',
        },
        aria: {
            sortAscending: ': sắp xếp tăng dần',
            sortDescending: ': sắp xếp giảm dần',
        },
    },
    confirmDelete(options = {}) {
        return Swal.fire({
            icon: 'warning',
            title: options.title || 'Xác nhận xóa',
            text: options.text || 'Thao tác này không thể hoàn tác.',
            showCancelButton: true,
            confirmButtonText: options.confirmButtonText || 'Xóa',
            cancelButtonText: options.cancelButtonText || 'Hủy',
            confirmButtonColor: '#b91c1c',
            cancelButtonColor: '#64748b',
        });
    },
    toast(icon, title) {
        return Swal.fire({
            toast: true,
            position: 'top-end',
            icon,
            title,
            showConfirmButton: false,
            timer: 2600,
            timerProgressBar: true,
        });
    },
};
