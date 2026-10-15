// Setup page: password visibility toggles and password-match check.
(function() {
    var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    document.querySelectorAll('.pw-toggle').forEach(function(btn) {
        var input = document.getElementById(btn.dataset.target);
        btn.innerHTML = EYE;
        btn.addEventListener('click', function() {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show ? EYE_OFF : EYE;
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        });
    });

    var pw = document.getElementById('admin_password');
    var confirmPw = document.getElementById('admin_password_confirm');
    var status = document.getElementById('pw-match');
    if (!pw || !confirmPw || !status) return;

    function checkMatch() {
        if (confirmPw.value === '') {
            status.textContent = '';
            status.className = 'hint';
            confirmPw.setCustomValidity('');
            return;
        }
        if (pw.value === confirmPw.value) {
            status.textContent = 'Passwords match.';
            status.className = 'hint good';
            confirmPw.setCustomValidity('');
        } else {
            status.textContent = 'Passwords do not match.';
            status.className = 'hint bad';
            confirmPw.setCustomValidity('Passwords do not match');
        }
    }
    pw.addEventListener('input', checkMatch);
    confirmPw.addEventListener('input', checkMatch);
})();
