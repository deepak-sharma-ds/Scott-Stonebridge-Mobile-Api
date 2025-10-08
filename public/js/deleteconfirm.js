// public/js/delete-confirm.js

function attachDeleteConfirm(selector = '.delete-confirm') {
    document.querySelectorAll(selector).forEach(button => {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            const form = this.closest('form');

            const swalOptions = {
                title: this.dataset.title || 'Are you sure?',
                text: this.dataset.text || "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true, // ✅ ensure this is present
                confirmButtonText: this.dataset.confirmButton || 'Yes, delete it!',
                cancelButtonText: this.dataset.cancelButton || 'Cancel',
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                reverseButtons: true
            };

            Swal.fire(swalOptions).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
}
