/**
 * Zyppy Search — live search client.
 *
 * Dependency free on purpose. A bundle must never pull its own copy of jQuery
 * in: a second copy silently breaks every plugin already bound to the first,
 * and depending on the layout's jQuery template makes the bundle fail on any
 * layout that does not enable it. Everything below is plain DOM API.
 *
 * WHAT THIS FILE IS NOT, since Step 10: a renderer.
 *
 * Step 7 had it assemble each result in JavaScript from a JSON array. That made
 * tl_module.searchTpl apply to the first paint and silently NOT to any update
 * after it — a site that customised its result template would have watched the
 * customisation vanish on the first keystroke. So results are rendered by ONE
 * renderer now, server side, through search_zyppy_results + search_zyppy, and
 * this file drops the finished markup in. First paint and every live update are
 * identical by construction rather than by two implementations agreeing.
 *
 * TRANSPORT. GET /_zyppy/search/{moduleId}, read from `data-endpoint` on the
 * keywords input. The response is JSON: window metadata plus one `html` string.
 *
 * ESCAPING — read this before changing the injection.
 *
 * `data.html` is assigned with innerHTML. That is the deliberate design, not an
 * oversight, and it is safe for three reasons that must all stay true:
 *
 *   1. It is produced by an AUTOESCAPING Twig template whose raw-vs-escaped
 *      contract is sentinel-tested and mutation-checked
 *      (tests/Template/TwigTemplateTest.php). Only `context` and `header` are
 *      |raw, and both are escaped in PHP before the markup they carry is added.
 *   2. innerHTML does not execute <script>. (jQuery's .append() did, which is
 *      what the pre-Step-7 code got wrong.)
 *   3. Result URLs are scheme-checked SERVER side, in SafeUrl::sanitise(),
 *      before they reach the template. Twig escaping alone does not stop a
 *      `javascript:` href — htmlspecialchars() touches no character in it.
 *
 * The error text is still set with textContent: it is the one string this file
 * puts on the page itself.
 */
(function () {
	'use strict';

	var DEBOUNCE_MS = 300;
	var DEFAULT_MIN_LENGTH = 3;
	var READY_FLAG = 'data-zyppy-search-ready';

	/**
	 * The wrapper class is Contao's `mod_` + module type, not the template
	 * name. contao-zyppy-popup's popup.js also relies on it.
	 */
	var WRAPPER_SELECTOR = 'div.mod_zyppy_search';

	function initModule(objWrapper) {
		if (objWrapper.getAttribute(READY_FLAG) === '1') {
			return;
		}

		var objInput = objWrapper.querySelector('input[name="keywords"]');

		if (!objInput) {
			return;
		}

		var strEndpoint = objInput.getAttribute('data-endpoint') || '';

		if (strEndpoint === '') {
			return;
		}

		objWrapper.setAttribute(READY_FLAG, '1');

		var objResults = objWrapper.querySelector('div.results');

		if (!objResults) {
			objResults = document.createElement('div');
			objResults.className = 'results';
			objWrapper.appendChild(objResults);
		}

		var intMinLength = parseInt(objInput.getAttribute('data-min-length'), 10);

		if (!(intMinLength > 0)) {
			intMinLength = DEFAULT_MIN_LENGTH;
		}

		var strErrorText = objInput.getAttribute('data-error-text') || '';

		// One controller and one timer per module instance: with two search
		// modules on a page, typing in one must not abort the other's request.
		var objController = null;
		var intTimer = null;
		var intPage = 1;

		function buildQuery() {
			var objParams = new URLSearchParams();

			objParams.set('keywords', objInput.value);

			var objQueryType = objWrapper.querySelector('input[name="query_type"]:checked');

			if (objQueryType) {
				objParams.set('query_type', objQueryType.value);
			}

			if (intPage > 1) {
				objParams.set('page', String(intPage));
			}

			return objParams;
		}

		function abortPending() {
			if (objController) {
				objController.abort();
				objController = null;
			}
		}

		function run() {
			var strKeywords = objInput.value.trim();

			if (strKeywords.length < intMinLength) {
				abortPending();
				objResults.textContent = '';
				intPage = 1;

				return;
			}

			abortPending();

			objController = new AbortController();

			var objOwn = objController;

			fetch(strEndpoint + '?' + buildQuery().toString(), {
				credentials: 'same-origin',
				headers: { 'Accept': 'application/json' },
				signal: objOwn.signal
			})
				.then(function (objResponse) {
					if (!objResponse.ok) {
						throw new Error('Zyppy Search request failed with status ' + objResponse.status);
					}

					return objResponse.json();
				})
				.then(function (objData) {
					if (objOwn !== objController) {
						return;
					}

					// The single injection point. See the header comment.
					objResults.innerHTML = objData.html || '';
				})
				.catch(function (objError) {
					if (objError && objError.name === 'AbortError') {
						return;
					}

					if (objOwn !== objController) {
						return;
					}

					// Never leave stale results on screen after a failure.
					objResults.textContent = strErrorText;

					if (window.console && window.console.warn) {
						window.console.warn('Zyppy Search: ' + (objError && objError.message ? objError.message : objError));
					}
				});
		}

		function schedule() {
			// Any new query starts at page one; only the pager moves off it.
			intPage = 1;
			window.clearTimeout(intTimer);
			intTimer = window.setTimeout(run, DEBOUNCE_MS);
		}

		// The `input` event does not fire for arrow keys, Shift, Ctrl or Tab,
		// so no keycode filtering is needed. It does fire for paste and for the
		// search field's native clear button.
		objInput.addEventListener('input', schedule);

		var arrQueryTypes = objWrapper.querySelectorAll('input[name="query_type"]');

		for (var j = 0; j < arrQueryTypes.length; j++) {
			arrQueryTypes[j].addEventListener('change', schedule);
		}

		// The form submits to the page, which renders the first page of results
		// server side — that is the no-JavaScript path, and it still works if
		// this handler is never reached. With JavaScript we intercept it so the
		// page does not reload for something we can fetch.
		var objForm = objWrapper.querySelector('form');

		if (objForm) {
			objForm.addEventListener('submit', function (objEvent) {
				objEvent.preventDefault();
				window.clearTimeout(intTimer);
				intPage = 1;
				run();
			});
		}

		// The pager is real `?keywords=…&page=N` links, so it works with no
		// JavaScript at all. Delegated, because the links are replaced wholesale
		// on every update — binding them directly would go stale immediately.
		objResults.addEventListener('click', function (objEvent) {
			var objLink = objEvent.target && objEvent.target.closest ? objEvent.target.closest('a[data-zyppy-page]') : null;

			if (!objLink || !objResults.contains(objLink)) {
				return;
			}

			var intTarget = parseInt(objLink.getAttribute('data-zyppy-page'), 10);

			if (!(intTarget > 0)) {
				return;
			}

			objEvent.preventDefault();
			window.clearTimeout(intTimer);
			intPage = intTarget;
			run();
		});
	}

	function init() {
		var arrWrappers = document.querySelectorAll(WRAPPER_SELECTOR);

		for (var i = 0; i < arrWrappers.length; i++) {
			initModule(arrWrappers[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
