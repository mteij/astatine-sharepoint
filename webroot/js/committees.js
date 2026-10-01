// Loads the committee list after the page is shown, so sign-in does not wait for Microsoft Graph.
(function () {
    'use strict';

    var box = document.getElementById('committees');
    if (!box || !window.fetch) {
        return;
    }

    box.setAttribute('aria-busy', 'true');

    fetch(box.getAttribute('data-url'), {
        credentials: 'same-origin',
        redirect: 'manual',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function (response) {
            // An expired session redirects to the login page, or Graph rejected the token.
            if (response.type === 'opaqueredirect' || response.status === 401) {
                window.location.href = box.getAttribute('data-login');
                return null;
            }
            if (!response.ok) {
                throw new Error('Unexpected status ' + response.status);
            }
            return response.text();
        })
        .then(function (html) {
            if (html !== null) {
                box.innerHTML = html;
            }
        })
        .catch(function () {
            var note = document.createElement('p');
            note.className = 'muted';
            note.textContent = 'The list could not be refreshed. Reload the page to try again.';
            box.appendChild(note);
        })
        .then(function () {
            box.removeAttribute('aria-busy');
        });
}());
