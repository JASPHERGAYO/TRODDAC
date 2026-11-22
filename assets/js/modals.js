// Modal dialogs that work with or without the native <dialog>.showModal().
(function () {
    const TRD = window.TRD || (window.TRD = {});
    const $ = (id) => document.getElementById(id);

    function open(dialog) {
        if (!dialog) return;
        if (typeof dialog.showModal === 'function') {
            try { dialog.showModal(); } catch (e) { dialog.setAttribute('open', ''); }
        } else {
            dialog.setAttribute('open', '');
        }
        document.body.classList.add('modal-open');
    }
    function close(dialog) {
        if (!dialog) return;
        if (typeof dialog.close === 'function') { try { dialog.close(); } catch (e) { dialog.removeAttribute('open'); } }
        else { dialog.removeAttribute('open'); }
        if (!document.querySelector('dialog[open]')) document.body.classList.remove('modal-open');
    }
    TRD.modal = { open, close };

    document.addEventListener('click', (e) => {
        const opener = e.target.closest('[data-open]');
        if (opener) { open($(opener.dataset.open)); return; }
        const closer = e.target.closest('[data-close]');
        if (closer) { close(closer.closest('dialog')); return; }
        if (e.target.tagName === 'DIALOG') close(e.target);      // the dimmed area outside the sheet
    });
    document.addEventListener('close', () => {
        if (!document.querySelector('dialog[open]')) document.body.classList.remove('modal-open');
    }, true);
    document.querySelectorAll('dialog[data-autoopen]').forEach(open);
})();
