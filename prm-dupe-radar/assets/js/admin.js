(function () {
	'use strict';

	function toggleGroup(button) {
		var section = button.closest('.pdr-group');

		if (!section) {
			return;
		}

		var collapsed = section.classList.toggle('is-collapsed');
		button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
	}

	document.addEventListener('click', function (event) {
		var target = event.target;

		if (!(target instanceof Element)) {
			return;
		}

		var toggle = target.closest('.pdr-group-toggle');

		if (toggle) {
			event.preventDefault();
			toggleGroup(toggle);
		}
	});
})();
