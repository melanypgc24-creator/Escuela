(function () {
    const body = document.body;
    const menuButton = document.getElementById('tc-menu-btn');
    const overlay = document.getElementById('tc-overlay');

    const setOpen = (open) => {
        body.classList.toggle('tc-collapsed', !open);
        if (menuButton) {
            menuButton.setAttribute('aria-expanded', String(open));
        }
    };

    if (menuButton) {
        menuButton.setAttribute('aria-expanded', 'false');
        menuButton.addEventListener('click', () => setOpen(body.classList.contains('tc-collapsed')));
    }
    if (overlay) {
        overlay.addEventListener('click', () => setOpen(false));
    }
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('.tc-group-toggle').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const submenu = document.getElementById(toggle.getAttribute('aria-controls'));
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', String(open));
            toggle.classList.toggle('is-open', open);
            submenu.hidden = !open;
        });
    });
})();
