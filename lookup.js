(function () {
  'use strict';
  function text(tag, value, className) {
    var element = document.createElement(tag);
    element.textContent = value;
    if (className) element.className = className;
    return element;
  }
  function group(title, fields, student) {
    var section = document.createElement('section');
    section.className = 'ssl-group';
    section.appendChild(text('h4', title));
    var list = document.createElement('dl');
    fields.forEach(function (field) {
      var item = document.createElement('div');
      item.appendChild(text('dt', field[1]));
      item.appendChild(text('dd', student[field[0]] || 'Not provided'));
      list.appendChild(item);
    });
    section.appendChild(list);
    return section;
  }
  document.querySelectorAll('.ssl-lookup').forEach(function (root) {
    var form = root.querySelector('form');
    var button = form.querySelector('button');
    var status = root.querySelector('.ssl-status');
    var result = root.querySelector('.ssl-result');
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      result.textContent = '';
      status.textContent = 'Searching…';
      button.disabled = true;
      root.setAttribute('aria-busy', 'true');
      var data = new FormData();
      data.append('action', 'ssl_lookup');
      data.append('student_name', form.elements.student_name.value);
      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timer = controller ? setTimeout(function () { controller.abort(); }, 20000) : null;
      fetch(root.getAttribute('data-endpoint'), {
        method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store',
        signal: controller ? controller.signal : undefined
      }).then(function (response) { return response.json(); }).then(function (payload) {
        if (!payload.success) {
          status.textContent = payload.data && payload.data.message ? payload.data.message : 'Search unavailable. Please try again.';
          return;
        }
        var student = payload.data.student;
        var card = document.createElement('article');
        card.className = 'ssl-card';
        card.appendChild(text('h3', student.first_name + ' ' + student.last_name));
        card.appendChild(group('Student information', [['first_name', 'First name'], ['last_name', 'Last name'], ['level', 'Level'], ['show_assigned', 'Show assigned'], ['class_count', 'Number of classes']], student));
        card.appendChild(group('Dress rehearsal', [['rehearsal', 'Assigned arrival time']], student));
        card.appendChild(group('Performance', [['dropoff', 'Show day drop-off time'], ['performance', 'Performance date / time']], student));
        card.appendChild(group('Details & instructions', [['details', 'Details']], student));
        result.appendChild(card);
        status.textContent = 'Student schedule found.';
      }).catch(function () {
        status.textContent = 'Search unavailable. Please try again or contact the office.';
      }).then(function () {
        if (timer) clearTimeout(timer);
        button.disabled = false;
        root.removeAttribute('aria-busy');
      });
    });
  });
}());
