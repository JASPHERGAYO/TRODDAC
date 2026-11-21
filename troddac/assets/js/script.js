// Show/hide password + simple client-side checks (PHP validates again on the server).
const form = document.getElementById('loginForm');
const identifier = document.getElementById('identifier');
const password = document.getElementById('password');
const toggle = document.getElementById('togglePw');

function setError(input, message) {
    document.getElementById(input.id + 'Error').textContent = message;
    input.classList.toggle('invalid', Boolean(message));
}

toggle?.addEventListener('click', () => {
    const hidden = password.type === 'password';
    password.type = hidden ? 'text' : 'password';
    toggle.textContent = hidden ? 'Hide' : 'Show';
    toggle.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
});

form?.addEventListener('submit', (e) => {
    let valid = true;
    if (identifier.value.trim() === '') { setError(identifier, 'Enter your username or email.'); valid = false; }
    else { setError(identifier, ''); }
    if (password.value === '') { setError(password, 'Enter your password.'); valid = false; }
    else { setError(password, ''); }
    if (!valid) e.preventDefault();
});
