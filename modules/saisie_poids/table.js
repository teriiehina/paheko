var b = document.querySelector('tbody');

function selectObject(s) {
	var el = s;

	while (el.tagName.toLowerCase() != 'tr') {
		el = el.parentNode;
	}

	Object.entries(objects).forEach((obj) => {
		const [key, o] = obj;

		if (key !== s.value) {
			return;
		}

		var w = el.querySelector('input[name*=weight]');
		w.value = o.weight / 1000;
		updateTotal(w);
	});
}

function addEvents(row) {
	row.querySelectorAll('input[name*=weight], input[name*=qty]').forEach(e => e.onkeyup = () => updateTotal(e));
	row.querySelectorAll('select[name*=object]').forEach(e => e.onchange = () => selectObject(e));
	row.querySelector('button').onclick = removeLine;
}

function updateTotal (el) {
	while (el.tagName.toLowerCase() != 'tr') {
		el = el.parentNode;
	}

	var total = el.querySelector('input[name*=weight]').value * el.querySelector('input[name*=qty]').value;
	el.querySelector('.total').innerText = ('' + total).replace('.', ',');
}

function removeLine (e) {
	var el = e.target;

	while (el.tagName.toLowerCase() != 'tr') {
		el = el.parentNode;
	}

	if (b.querySelectorAll('tr').length <= 1) {
		el.querySelector('input[name*=weight]').value = '0';
		el.querySelector('input[name*=qty]').value = '1';
	}
	else {
		el.remove();
	}

	b.querySelector('tr:last-child select').focus();
}

document.querySelector('button.add').onclick = () => {
	var el = b.querySelector('tr').cloneNode(true);
	el.querySelector('input[name*=weight]').value = '0';
	el.querySelector('input[name*=qty]').value = '1';
	addEvents(el);
	b.appendChild(el);
	b.querySelector('tr:last-child select').focus();
};

b.querySelectorAll('tr').forEach(e => addEvents(e));
b.querySelectorAll('select[name*=object]').forEach(e => selectObject(e));
