/**
 * LLMs.txt Generator - section form helper.
 *
 * In each menu section row, the "Only these items" and "Exclude items" fancy-selects
 * are filtered to show only the menu items of the menu (menutype) chosen in that same
 * row. The full item list ships as JSON on the <joomla-field-fancy-select> element;
 * filtering is done through the choices.js instance the web component exposes.
 *
 * @copyright   (C) 2026 Joomill Extensions
 * @license     GNU General Public License version 3 or later
 */
(function () {
	'use strict';

	var BOUND = 'llmstxtBound';

	function menuFields(row) {
		return row.querySelectorAll('joomla-field-fancy-select.llmstxt-menuitems');
	}

	// The web component builds its choices.js instance on window load; poll for it.
	function whenReady(el, cb, tries) {
		tries = tries || 0;

		if (el.choicesInstance) {
			cb(el.choicesInstance);
		} else if (tries < 120) {
			requestAnimationFrame(function () {
				whenReady(el, cb, tries + 1);
			});
		}
	}

	function filterField(el, menutype) {
		var all;

		try {
			all = JSON.parse(el.getAttribute('data-menuitems') || '[]');
		} catch (e) {
			all = [];
		}

		whenReady(el, function (choices) {
			var selected = (choices.getValue(true) || []).map(String);

			var list = all.filter(function (item) {
				return menutype === '' || String(item.menutype) === menutype;
			}).map(function (item) {
				return {
					value: String(item.value),
					label: item.label,
					selected: selected.indexOf(String(item.value)) !== -1
				};
			});

			choices.setChoices(list, 'value', 'label', true);
		});
	}

	function applyFilter(row) {
		var typeSelect = row.querySelector('select[name$="[menutype]"]');
		var menutype   = typeSelect ? typeSelect.value : '';

		menuFields(row).forEach(function (el) {
			filterField(el, menutype);
		});
	}

	function initRow(row) {
		if (!row || row.dataset[BOUND]) {
			return;
		}

		var typeSelect = row.querySelector('select[name$="[menutype]"]');

		if (!typeSelect || !menuFields(row).length) {
			return;
		}

		row.dataset[BOUND] = '1';
		typeSelect.addEventListener('change', function () {
			applyFilter(row);
		});
		applyFilter(row);
	}

	function initAll() {
		document.querySelectorAll('.subform-repeatable-group').forEach(initRow);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}

	// choices.js initialises on window load; new subform rows fire subform-row-add.
	window.addEventListener('load', initAll);
	document.addEventListener('subform-row-add', initAll);
})();
