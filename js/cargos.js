// Pantalla de cargos mensuales: al cambiar el periodo, la fecha de vencimiento se mueve a ese mes
// conservando el día elegido (o el día predeterminado), recortado al último día del mes.
(function () {
  'use strict';

  function lastDayOfMonth(year, month) {
    return new Date(year, month, 0).getDate();
  }

  function pad(n) {
    return (n < 10 ? '0' : '') + n;
  }

  var forms = document.querySelectorAll('form[data-due-sync]');
  for (var i = 0; i < forms.length; i++) {
    (function (form) {
      var period = form.querySelector('input[name="periodo"]');
      var due = form.querySelector('input[name="fecha_vencimiento"]');
      if (!period || !due) { return; }

      var defaultDay = parseInt(form.getAttribute('data-default-day'), 10) || 10;
      // Día que el administrador quiere conservar al cambiar de mes.
      var wantedDay = parseInt((due.value || '').slice(8, 10), 10) || defaultDay;

      due.addEventListener('change', function () {
        var day = parseInt((due.value || '').slice(8, 10), 10);
        if (day) { wantedDay = day; }
      });

      period.addEventListener('change', function () {
        var match = /^(\d{4})-(\d{2})$/.exec(period.value);
        if (!match) { return; }
        var year = parseInt(match[1], 10);
        var month = parseInt(match[2], 10);
        var day = Math.min(wantedDay, lastDayOfMonth(year, month));
        due.min = match[1] + '-' + match[2] + '-01';
        due.value = match[1] + '-' + match[2] + '-' + pad(day);
      });
    })(forms[i]);
  }
})();
