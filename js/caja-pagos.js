// Comportamiento del formulario de caja: saldo del alumno, efectivo/cambio y tarjeta simulada.
(function () {
  'use strict';

  var form = document.getElementById('caja-form');
  if (!form) { return; }

  var select = document.getElementById('id_alumno');
  var saldoHint = document.getElementById('caja-saldo');
  var monto = document.getElementById('monto');
  var recibido = document.getElementById('efectivo_recibido');
  var cambio = document.getElementById('caja-cambio');
  var panels = form.querySelectorAll('.caja-panel');
  var radios = form.querySelectorAll('input[name="metodo_pago"]');
  var numero = document.getElementById('tarjeta_numero');
  var venc = document.getElementById('tarjeta_vencimiento');
  var cvv = document.getElementById('tarjeta_cvv');
  var referencia = document.getElementById('transferencia_referencia');

  function money(n) {
    return '$' + (Math.round(n * 100) / 100).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function currentMethod() {
    for (var i = 0; i < radios.length; i++) {
      if (radios[i].checked) { return radios[i].value; }
    }
    return '';
  }

  function updateSaldo(autofill) {
    var option = select.options[select.selectedIndex];
    var saldo = option ? parseFloat(option.getAttribute('data-saldo') || '0') : 0;
    if (!option || !option.value) {
      saldoHint.textContent = '';
      return;
    }
    if (saldo > 0) {
      saldoHint.textContent = 'Saldo pendiente: ' + money(saldo);
      if (autofill && monto) { monto.value = saldo.toFixed(2); }
    } else {
      saldoHint.textContent = 'Este alumno no tiene cargos pendientes. Si falta el cargo del mes, pide al administrador que genere los cargos.';
      if (autofill && monto) { monto.value = ''; }
    }
    updateChange();
  }

  function updateChange() {
    if (!cambio) { return; }
    var m = parseFloat(monto && monto.value) || 0;
    var r = parseFloat(recibido && recibido.value) || 0;
    cambio.textContent = r >= m && m > 0 ? money(r - m) : (r > 0 ? 'Falta ' + money(m - r) : '$0.00');
  }

  function updatePanels() {
    var method = currentMethod();
    for (var i = 0; i < panels.length; i++) {
      var active = panels[i].getAttribute('data-metodo') === method;
      panels[i].hidden = !active;
      var fields = panels[i].querySelectorAll('input');
      for (var j = 0; j < fields.length; j++) {
        fields[j].required = active && fields[j].id !== '';
        fields[j].disabled = !active; // los datos de tarjeta nunca viajan si el método es otro
      }
    }
  }

  select.addEventListener('change', function () { updateSaldo(true); });
  if (monto) { monto.addEventListener('input', updateChange); }
  if (recibido) { recibido.addEventListener('input', updateChange); }
  for (var i = 0; i < radios.length; i++) { radios[i].addEventListener('change', updatePanels); }

  if (numero) {
    numero.addEventListener('input', function () {
      var d = numero.value.replace(/\D/g, '').slice(0, 19);
      numero.value = d.replace(/(.{4})/g, '$1 ').trim();
    });
  }
  if (venc) {
    venc.addEventListener('input', function () {
      var d = venc.value.replace(/\D/g, '').slice(0, 4);
      venc.value = d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
    });
  }
  if (referencia) {
    referencia.addEventListener('input', function () {
      referencia.value = referencia.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 40);
    });
  }
  if (cvv) {
    cvv.addEventListener('input', function () { cvv.value = cvv.value.replace(/\D/g, '').slice(0, 4); });
  }

  updatePanels();
  updateSaldo(!(monto && monto.value));
  updateChange();
})();
