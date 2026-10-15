// Admin pages: confirmation prompts and inline-edit toggles.
// Lives in an external file so the Content-Security-Policy can forbid inline scripts.
document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-toggle-edit]');
    if (!toggle) {
        return;
    }
    var form = document.getElementById('edit-form-' + toggle.getAttribute('data-toggle-edit'));
    if (!form) {
        return;
    }
    form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
});

document.addEventListener('submit', function (event) {
    var message = event.target.getAttribute && event.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
