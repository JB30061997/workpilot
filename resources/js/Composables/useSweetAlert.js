import Swal from 'sweetalert2';

const getSweetAlertTarget = () => {
    const openDialogs = document.querySelectorAll('dialog[open]');

    return openDialogs.item(openDialogs.length - 1) ?? document.body;
};

const modal = Swal.mixin({
    position: 'center',
    background: '#ffffff',
    color: '#0f172a',
    buttonsStyling: false,
    customClass: {
        container: 'wp-alert-container',
        popup: 'wp-alert-popup',
        title: 'wp-alert-title',
        htmlContainer: 'wp-alert-copy',
        confirmButton: 'wp-alert-confirm',
        cancelButton: 'wp-alert-cancel',
        actions: 'wp-alert-actions',
    },
});

const toast = Swal.mixin({
    toast: true,
    position: 'center',
    showConfirmButton: false,
    timer: 4200,
    timerProgressBar: true,
    customClass: {
        container: 'wp-toast-container',
        popup: 'wp-toast-popup',
        title: 'wp-toast-title',
        timerProgressBar: 'wp-toast-progress',
    },
});

export function useSweetAlert() {
    const notify = (message, icon = 'success') => toast.fire({
        target: getSweetAlertTarget(),
        icon,
        title: message,
    });

    const confirmAction = async ({
        title = 'Confirmer cette action ?',
        text = 'Cette opération ne pourra pas être annulée.',
        confirmButtonText = 'Oui, continuer',
        icon = 'warning',
    } = {}) => {
        const result = await modal.fire({
            target: getSweetAlertTarget(),
            title,
            text,
            icon,
            showCancelButton: true,
            confirmButtonText,
            cancelButtonText: 'Annuler',
            reverseButtons: true,
            focusCancel: true,
        });

        return result.isConfirmed;
    };

    return { confirmAction, notify };
}
