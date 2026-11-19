const form = document.getElementById('loginForm');
const username = document.getElementById('username');
const password = document.getElementById('password');
const toggle = document.getElementById('togglePw');

function setError(input, message) {
    document.getElementById(input.id + 'Error').textContent = message;
    input.classList.toggle('invalid', Boolean(message));
}

// Show / hide password
toggle.addEventListener('click', () => {
    const hidden = password.type === 'password';
    password.type = hidden ? 'text' : 'password';
    toggle.textContent = hidden ? 'Hide' : 'Show';
    toggle.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
});

// Client-side check before the form goes to PHP (PHP still validates again)
form.addEventListener('submit', (e) => {
    let valid = true;

    if (username.value.trim() === '') {
        setError(username, 'Enter your username or email.');
        valid = false;
    } else {
        setError(username, '');
    }

    if (password.value === '') {
        setError(password, 'Enter your password.');
        valid = false;
    } else {
        setError(password, '');
    }

    if (!valid) e.preventDefault();
});
