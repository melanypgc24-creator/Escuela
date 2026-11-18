(() => {
    'use strict';

    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    const localAdminEmailPattern = /^[^\s@]+@localhost$/i;

    document.querySelectorAll('form[data-auth-validation]').forEach((form) => {
        const mode = form.dataset.authValidation;
        const email = form.querySelector('[name="correo"]');
        const password = form.querySelector('[name="password"]');
        const confirmation = form.querySelector('[name="password_confirmation"]');
        let submitAttempted = false;

        const escapeSelector = (value) => {
            if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
                return CSS.escape(value);
            }
            return value.replace(/([^\w-])/g, '\\$1');
        };

        form.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
            const inputId = toggle.getAttribute('aria-controls');
            const input = inputId ? form.querySelector(`#${escapeSelector(inputId)}`) : null;

            if (!input) {
                return;
            }

            toggle.addEventListener('click', () => {
                const isVisible = input.type === 'text';
                input.type = isVisible ? 'password' : 'text';
                const showLabel = toggle.getAttribute('data-show-label') || 'Mostrar contraseña';
                const hideLabel = toggle.getAttribute('data-hide-label') || 'Ocultar contraseña';
                toggle.setAttribute('aria-pressed', String(!isVisible));
                toggle.setAttribute('aria-label', isVisible ? showLabel : hideLabel);
                toggle.title = isVisible ? showLabel : hideLabel;
                toggle.querySelector('[data-eye-open]').hidden = isVisible;
                toggle.querySelector('[data-eye-closed]').hidden = !isVisible;
            });
        });

        const showFeedback = (input, message, invalid) => {
            if (!input) {
                return;
            }

            const feedback = form.querySelector(`[data-validation-for="${input.name}"]`);
            input.setCustomValidity(message);

            if (invalid) {
                input.setAttribute('aria-invalid', 'true');
            } else if (input.value.trim() !== '') {
                input.setAttribute('aria-invalid', 'false');
            } else {
                input.removeAttribute('aria-invalid');
            }

            if (feedback) {
                feedback.textContent = message;
                feedback.classList.toggle('is-error', invalid);
                feedback.classList.toggle('is-valid', !invalid && input.value.trim() !== '');
            }
        };

        const validateEmail = () => {
            if (!email || email.value.trim() === '') {
                const message = submitAttempted
                    ? 'Este campo es obligatorio.'
                    : '';
                showFeedback(email, message, message !== '');
                return false;
            }

            const value = email.value.trim();
            const isValid = value.length <= 100 && (
                emailPattern.test(value)
                || (mode === 'login' && localAdminEmailPattern.test(value))
            );
            const message = value.length > 100
                ? 'El correo no puede superar los 100 caracteres.'
                : isValid
                ? ''
                : 'Escribe un correo válido, por ejemplo nombre@dominio.com.';
            showFeedback(email, message, !isValid);
            return isValid;
        };

        const validatePassword = () => {
            if (!password) {
                return true;
            }

            if (password.value.length === 0) {
                const message = submitAttempted
                    ? 'Este campo es obligatorio.'
                    : '';
                showFeedback(password, message, message !== '');
                return false;
            }

            if (mode !== 'register') {
                showFeedback(password, '', false);
                return true;
            }

            const passwordLength = Array.from(password.value).length;
            const remaining = Math.max(0, 8 - passwordLength);
            const hasNumber = /\d/.test(password.value);
            const hasSpecialCharacter = /[^\p{L}\p{N}\s]/u.test(password.value);
            const hasNumberOrSpecialCharacter = hasNumber || hasSpecialCharacter;
            const isValid = remaining === 0 && hasNumberOrSpecialCharacter;
            const requirements = [];

            if (remaining > 0) {
                requirements.push(`8 caracteres (te falta${remaining === 1 ? '' : 'n'} ${remaining})`);
            }
            if (!hasNumberOrSpecialCharacter) {
                requirements.push('al menos un número o carácter especial');
            }

            const message = isValid
                ? 'Contraseña válida: cumple los requisitos.'
                : `Agrega ${requirements.join(' y ')}.`;
            showFeedback(password, message, !isValid);

            if (confirmation && confirmation.value !== '') {
                validateConfirmation();
            }

            return isValid;
        };

        const validateConfirmation = () => {
            if (!confirmation || !password || mode !== 'register') {
                return true;
            }

            if (confirmation.value === '') {
                const message = submitAttempted ? 'Este campo es obligatorio.' : '';
                showFeedback(confirmation, message, message !== '');
                return false;
            }

            const isValid = confirmation.value === password.value;
            showFeedback(
                confirmation,
                isValid ? 'Las contraseñas coinciden.' : 'Las contraseñas no coinciden.',
                !isValid
            );
            return isValid;
        };

        if (email) {
            email.addEventListener('input', validateEmail);
            email.addEventListener('blur', validateEmail);
        }

        if (password && mode === 'register') {
            password.addEventListener('input', validatePassword);
            password.addEventListener('blur', validatePassword);
        }

        if (confirmation) {
            confirmation.addEventListener('input', validateConfirmation);
            confirmation.addEventListener('blur', validateConfirmation);
        }

        form.addEventListener('submit', (event) => {
            submitAttempted = true;

            const checks = [validateEmail()];
            if (password) {
                checks.push(validatePassword());
            }
            if (mode === 'register') {
                checks.push(validateConfirmation());
            }

            if (checks.some((isValid) => !isValid)) {
                event.preventDefault();
                form.querySelector('[aria-invalid="true"]')?.focus();
            }
        });
    });
})();
