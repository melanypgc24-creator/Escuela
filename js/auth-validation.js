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
            const isValid = remaining === 0;
            const message = isValid
                ? 'La contraseña tiene al menos 8 caracteres.'
                : `La contraseña debe tener 8 caracteres. Te falta${remaining === 1 ? '' : 'n'} ${remaining}.`;
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
