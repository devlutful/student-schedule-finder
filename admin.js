(function () {
  'use strict';
  var all = document.getElementById('lssf-select-all');
  var form = document.getElementById('lssf-bulk');
  if (!all || !form) return;
  var boxes = Array.prototype.slice.call(document.querySelectorAll('.lssf-select'));
  var count = document.getElementById('lssf-selection');
  function update() {
    var selected = boxes.filter(function (box) { return box.checked; }).length;
    count.textContent = selected + ' selected';
    all.checked = boxes.length > 0 && selected === boxes.length;
    all.indeterminate = selected > 0 && selected < boxes.length;
    all.disabled = boxes.length === 0;
  }
  all.addEventListener('change', function () {
    boxes.forEach(function (box) { box.checked = all.checked; });
    update();
  });
  boxes.forEach(function (box) { box.addEventListener('change', update); });
  form.addEventListener('submit', function (event) {
    if (!boxes.some(function (box) { return box.checked; })) {
      event.preventDefault();
      count.textContent = 'Select at least one student.';
    }
  });
  update();
}());
