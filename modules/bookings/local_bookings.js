/**
 * Save bookings to local storage
 * @return {[type]} [description]
 */
(function () {
	var s = localStorage;
	var bookings = s.getItem('bookings');

	if (typeof bookings == 'string') {
		bookings = JSON.parse(bookings);
	}

	if (typeof bookings != 'object' || bookings === null) {
		bookings = {};
	}

	// Delete booking
	if (a = document.querySelector('[data-delete-booking]')) {
		var key = a.dataset.deleteBooking;

		if (bookings.hasOwnProperty(key)) {
			delete bookings[key];
			s.setItem('bookings', JSON.stringify(bookings));
		}
	}
	// Append booking
	else if (a = document.querySelector('[data-new-booking]')) {
		var data = JSON.parse(a.dataset.newBooking);
		bookings[data.key] = data;
		s.setItem('bookings', JSON.stringify(bookings));
		return;
	}

	if (!Object.keys(bookings).length) {
		return;
	}

	const escape = (unsafe) => {
		return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
	};

	const tpl = document.getElementById('booking');

	Object.values(bookings).forEach((b) => {
		if (b.date <= Math.floor(Date.now() / 1000)) {
			// Booking is old, delete it
			delete bookings[b.key];
			s.setItem('bookings', JSON.stringify(bookings));
		}

		let html = tpl.innerHTML;

		html = html.replace(/#(\w+)#/g, (_, m) => escape(b[m] ?? ''));
		tpl.parentNode.insertAdjacentHTML('beforeend', html);
	});
})();