document.querySelectorAll('[data-categoria-clases]').forEach((input) => {
    const output = document.getElementById(input.dataset.categoriaClases);
    if (!output) return;

    const update = () => {
        const birth = input.value;
        const today = input.dataset.fechaActual;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(birth) || birth < '1900-01-01' || birth > today) {
            output.textContent = 'Categoría para clases: pendiente de una fecha válida.';
            return;
        }
        let age = Number(today.slice(0, 4)) - Number(birth.slice(0, 4));
        if (today.slice(5) < birth.slice(5)) age--;
        output.textContent = `Categoría para clases: ${age >= 18 ? 'Adulta' : 'Infantil'}.`;
    };
    input.addEventListener('input', update);
    update();
});
