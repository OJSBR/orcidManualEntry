/**
 * @file cypress/tests/functional/OrcidManualEntry.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the typeable ORCID field on the registration page, in the
 * user profile and for submission contributors, with ORCID OAuth off; and the
 * three places where a journal can require it — registering, saving a
 * contributor and completing a submission.
 *
 * Contributors are saved through the REST endpoints the contributor form uses,
 * so the core validation and the plugin hooks run as in production.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run; this user's own profile is edited and put back). The
 * defaults match the data set of PKP's continuous integration; the first test
 * enables the plugin when it is off, and the contributor test uses the first
 * submission in progress of the journal. The contributors it adds are deleted
 * and the settings of the plugin are put back as they were.
 * Assertions use names, ids and API data, never labels.
 */

describe('ORCID Manual Entry plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';

	const VALID = '0000-0002-1825-0097';
	const VALID_URL = 'https://orcid.org/' + VALID;
	const WRONG_CHECK_DIGIT = '0000-0002-1825-0098';
	const ROLE_ID_MANAGER = 16;
	const ROLE_ID_SUB_EDITOR = 17;

	const identityOrcid = 'form[id="identityForm"] input[name="orcid"]';
	let original = null;
	// Contributors created by the contributor test, deleted in after() even when an assertion fails.
	const created = [];

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// The Plugins tab can keep requests open for a while (the plugin gallery), hence the timeout.
	// jQuery may not be on the page yet when this runs, so the check retries on the window
	// itself instead of on a property that would resolve as undefined.
	const waitJQuery = () => cy.window({timeout: 60000}).should((win) => {
		expect(win.jQuery && win.jQuery.active, 'pending jQuery requests').to.eq(0);
	});

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The website settings page on its Plugins tab (a new query string forces a load). Load it
	// once per test: loading it again while its plugin gallery request is pending stalls the
	// web server of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// Opens the settings modal from the grid, without reloading the page: a reload right
	// after saving can stall the web server of PKP's CI. The form is fetched each time.
	const openPluginSettings = (rowName, formSelector) => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		// The grid may still be animating the extras row: the link is clicked once it exists.
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(formSelector).data('pkp.handler')).to.exist;
		});
	};

	// ---- end of helpers ----

	const openIdentity = () => {
		cy.visit(pageUrl('user/profile') + '?reload=' + Date.now());
		cy.get(identityOrcid, {timeout: 30000}).should('exist');
	};

	// Saves the profile form and yields the JSON the handler answered.
	const saveIdentity = (value) => {
		cy.intercept('POST', '**/save-identity*').as('saveIdentity');
		cy.get(identityOrcid).invoke('val', value || '');
		cy.get('form[id="identityForm"] button[type="submit"]').click();
		return cy.wait('@saveIdentity').its('response.body');
	};

	const withToken = (method, body) => cy.window({log: false}).then((win) => ({
		method,
		headers: {'Content-Type': 'application/json', 'X-Csrf-Token': win.pkp.currentUser.csrfToken},
		body: body ? JSON.stringify(body) : undefined,
	}));

	// A request whose error answer is kept, not thrown: yields {status, body}.
	const send = (path, method, body) => withToken(method, body).then((options) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => response.json().then((json) => ({status: response.status, body: json}))),
		{log: false, timeout: 30000}
	)));

	// Another plugin of the journal may require the affiliation and the biography
	// of every contributor: this spec is about the iD, so it carries them.
	const OTHER_METADATA = (submission) => ({
		affiliations: [{name: {[submission.locale]: 'Universidade Federal do Cypress'}}],
		biography: {[submission.locale]: '<p>Pesquisadora do Cypress.</p>'},
	});

	// ---- the settings of the plugin: where the iD is asked for, and where it is required ----

	const SETTINGS = ['showOnRegistration', 'requireOnRegistration', 'requireOnContributor', 'requireOnSubmit', 'editorsExempt'];
	const settingsForm = 'form[id="orcidManualEntrySettingsForm"]';
	// The URL the settings form posts to, read from the form itself, and the
	// values the journal had before this run.
	let settingsAction = null;
	let settingsWere = null;

	// Saves the settings from whatever page is open, exactly as the modal does.
	// The settings page is only loaded once in a run: loading it again while its
	// plugin gallery request is pending stalls the web server of PKP's CI.
	const saveSettings = (values) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(settingsAction, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
			body: SETTINGS.filter((name) => values[name])
				.map((name) => name + '=on')
				.concat('csrfToken=' + encodeURIComponent(win.pkp.currentUser.csrfToken))
				.join('&'),
		}).then((response) => {
			if (!response.ok) {
				throw new Error('the settings answered ' + response.status);
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The settings form as the server renders it now, without loading a page.
	const fetchSettings = () => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(settingsAction.replace(/([?&])save=[^&]*&?/, '$1').replace(/[?&]$/, ''), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
			.then((response) => response.json())
			.then((json) => json.content || ''),
		{log: false, timeout: 30000}
	));

	// Which boxes are ticked in a rendered settings form.
	const checkedIn = (html) => SETTINGS.filter((name) => Cypress.$('<div>').html(html).find('input[name="' + name + '"]').is(':checked'));

	// ---- end of settings helpers ----

	// The submission the contributor tests work on: the first one in progress, or
	// one this spec creates — and then deletes — where the installation has none.
	let work = null;
	const workSubmission = () => {
		if (work) {
			return cy.wrap(work, {log: false});
		}
		return api(pageUrl('api/v1/submissions?status=1&count=20')).then((submissions) => {
			const found = (submissions.items || []).find((item) => item.currentPublicationId);
			if (found) {
				work = {id: found.id, publicationId: found.currentPublicationId, locale: found.locale, created: false};
				return cy.wrap(work, {log: false});
			}
			return cy.window({log: false}).then((win) => {
				const locale = win.pkp.context.primaryLocale;
				const create = (body) => send(pageUrl('api/v1/submissions'), 'POST', body);
				// A journal needs the section; a press takes the submission without one.
				return create({locale}).then((answer) => {
					if (answer.status === 200) {
						return answer;
					}
					return api(pageUrl('api/v1/sections?count=1')).then((sections) => create({locale, sectionId: sections.items[0].id}));
				}).then((answer) => {
					expect(answer.status, JSON.stringify(answer.body)).to.eq(200);
					work = {id: answer.body.id, publicationId: answer.body.currentPublicationId, locale, created: true};
					return cy.wrap(work, {log: false});
				});
			});
		});
	};

	// The user group a contributor is filed under: the one the submission already
	// uses, or the one the submission wizard itself would file them under (no
	// user has to hold the role for the group to exist).
	const authorGroupId = (publication, submission) => {
		if (publication.authors.length) {
			return cy.wrap(publication.authors[0].userGroupId, {log: false});
		}
		return request(pageUrl('submission') + '?id=' + submission.id).then((response) => {
			// The contributor form carries the group, as a field or as a hidden value.
			const found = /userGroupId(?:&quot;|")[\s\S]{0,600}?(?:&quot;|")value(?:&quot;|")\s*:\s*(\d+)/.exec(response.body);
			expect(found, 'the submission wizard names an author user group').to.not.eq(null);
			return cy.wrap(Number(found[1]), {log: false});
		});
	};

	// The publication of that submission, where its contributors live.
	// A submission of its own, still in the wizard: only then does the page carry
	// the contributor form. Deleted in after().
	const extra = [];
	const newSubmission = () => cy.window({log: false}).then((win) => {
		const locale = win.pkp.context.primaryLocale;
		const create = (body) => send(pageUrl('api/v1/submissions'), 'POST', body);

		// A journal needs the section; a press takes the submission without one.
		return create({locale}).then((answer) => answer.status === 200
			? answer
			: api(pageUrl('api/v1/sections?count=1')).then((sections) => create({locale, sectionId: sections.items[0].id})))
			.then((answer) => {
				expect(answer.status, JSON.stringify(answer.body)).to.eq(200);
				extra.push(answer.body.id);
				return cy.wrap({id: answer.body.id, publicationId: answer.body.currentPublicationId, locale}, {log: false});
			});
	});

	const workPublication = (submission) => pageUrl('api/v1/submissions/' + submission.id + '/publications/' + submission.publicationId);

	it('Enables the plugin and offers a settings form for the journal', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin('orcidmanualentryplugin');
		openPluginSettings('orcidmanualentryplugin', settingsForm);

		// A box for each place the iD can be asked for or required.
		SETTINGS.forEach((name) => cy.get(settingsForm + ' input[name="' + name + '"]').should('have.length', 1));
		cy.get(settingsForm).invoke('text').should('not.contain', '##');
		cy.get(settingsForm).invoke('attr', 'action').then((action) => {
			settingsAction = action;
		});
		cy.get(settingsForm).then(($form) => {
			settingsWere = {};
			SETTINGS.forEach((name) => {
				settingsWere[name] = $form.find('input[name="' + name + '"]').is(':checked');
			});
		});

		// The tests that follow check the plugin as it comes: the field is
		// offered on the registration page and required nowhere.
		cy.then(() => saveSettings({showOnRegistration: true}));
		fetchSettings().then((html) => {
			expect(checkedIn(html), 'saved through the form itself').to.deep.eq(['showOnRegistration']);
		});
	});

	it('Offers a typeable ORCID field instead of the OAuth button on the registration page', function() {
		cy.clearCookies();
		cy.visit(pageUrl('user/register') + '?reload=' + Date.now());
		cy.get('form#register input[type="text"][name="orcid"]').should('have.length', 1);
		cy.get('form#register #orcidManualEntryDescription').invoke('text').should('match', /\S/).and('not.contain', '##');
		cy.get('form#register #connect-orcid-button').should('not.exist');
	});

	it('Saves a bare iD in the profile as its canonical URL', function() {
		login(adminUser, adminPassword);
		openIdentity();
		cy.get(identityOrcid).should('have.length', 1).invoke('val').then((value) => {
			original = value || '';
		});
		cy.get('form[id="identityForm"] #connect-orcid-button').should('not.exist');
		saveIdentity(VALID).then((body) => {
			expect(JSON.stringify(body)).to.not.contain('orcidInvalid');
		});
	});

	it('Refuses a wrong check digit in the profile and keeps the stored iD', function() {
		login(adminUser, adminPassword);
		openIdentity();
		cy.get(identityOrcid).should('have.value', VALID_URL);
		saveIdentity(WRONG_CHECK_DIGIT).then((body) => {
			// The form comes back with the field error instead of a success.
			expect(body.content || '').to.contain('name="orcid"');
			expect(Cypress.$('<div>').html(body.content).find('.orcidManualEntry .error').length).to.eq(1);
		});
	});

	it('Clears the iD when the profile field is emptied, then puts the original back', function() {
		login(adminUser, adminPassword);
		openIdentity();
		cy.get(identityOrcid).should('have.value', VALID_URL);
		saveIdentity('');
		cy.then(() => {
			if (original) {
				saveIdentity(original);
			}
		});
	});

	it('Stores a typed iD for a contributor, refuses it for a second one and removes it when emptied', function() {
		login(adminUser, adminPassword);
		workSubmission().then((submission) => {
			const base = workPublication(submission);
			api(base).then((publication) => authorGroupId(publication, submission).then((userGroupId) => {
				// Names in the language of the submission, which the schema requires.
				const contributor = (givenName, orcid) => Object.assign({
					givenName: {[submission.locale]: givenName},
					familyName: {[submission.locale]: 'Cypress'},
					email: givenName.toLowerCase() + '.' + Date.now() + '@example.invalid',
					userGroupId,
					includeInBrowse: true,
					orcid,
				}, OTHER_METADATA(submission));
				const keep = (answer) => {
					if (answer.body && answer.body.id) {
						created.push({base, id: answer.body.id});
					}
					return answer;
				};

				send(base + '/contributors', 'POST', contributor('Ana', VALID)).then(keep).then((first) => {
					expect(first.status, JSON.stringify(first.body)).to.eq(200);
					expect(first.body.orcid).to.eq(VALID_URL);

					send(base + '/contributors', 'POST', contributor('Bruno', 'https://sandbox.orcid.org/' + VALID)).then(keep).then((duplicate) => {
						expect(duplicate.status).to.eq(400);
						expect(duplicate.body).to.have.property('orcid');
					});

					send(base + '/contributors', 'POST', contributor('Carla', WRONG_CHECK_DIGIT)).then(keep).then((invalid) => {
						expect(invalid.status).to.eq(400);
						expect(invalid.body).to.have.property('orcid');
					});

					send(base + '/contributors/' + first.body.id, 'PUT', {orcid: ''}).then((edited) => {
						expect(edited.status, JSON.stringify(edited.body)).to.eq(200);
						expect(edited.body.orcid).to.be.oneOf([null, '']);
					});
				});
			}));
		});
	});

	it('Requires the iD wherever the journal asks for it', function() {
		login(adminUser, adminPassword);
		// Every box ticked, the exemption included: what the journal saved is what it gets back.
		cy.then(() => saveSettings({showOnRegistration: true, requireOnRegistration: true, requireOnContributor: true, requireOnSubmit: true, editorsExempt: true}));
		fetchSettings().then((html) => {
			expect(checkedIn(html), 'the journal keeps every box it ticked').to.deep.eq(SETTINGS);
		});

		// The rules that follow are checked on whoever is running this: the
		// exemption is put aside so that they are about the rule itself.
		cy.then(() => saveSettings({showOnRegistration: true, requireOnRegistration: true, requireOnContributor: true, requireOnSubmit: true}));
	});

	it('Marks the field as required where the journal requires it', function() {
		login(adminUser, adminPassword);

		// In the contributor form, the application's own mark on its own label.
		newSubmission().then((submission) => {
			request(pageUrl('submission') + '?id=' + submission.id).then((response) => {
				const config = response.body.replace(/&quot;/g, '"');
				const start = config.indexOf('"name":"orcid"');
				expect(start, 'the contributor form carries the iD field').to.be.greaterThan(-1);
				const next = config.indexOf('"name":"', start + 1);
				expect(/"isRequired":true/.test(config.slice(start, next === -1 ? undefined : next)), 'the iD is marked as required').to.eq(true);
			});
		});

		// And on the registration page, the mark that page uses.
		cy.clearCookies();
		request(pageUrl('user/register')).then((response) => {
			const field = response.body.slice(response.body.indexOf('orcidManualEntry'));
			expect(field).to.contain('<span class="required" aria-hidden="true">*</span>');
			expect(field).to.contain('required aria-required="true"');
		});
	});

	it('Refuses a registration with no iD while the journal requires one', function() {
		// A complete registration, missing nothing but the iD: it is turned down
		// and no account is created. The form is posted as the page posts it, so
		// the captcha of the journal — if any — is never touched.
		const mark = 'orcidcypress' + Date.now();
		const email = mark + '@example.invalid';

		cy.clearCookies();
		request(pageUrl('user/register')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			const action = /<form[^>]*id="register"[^>]*action="([^"]+)"/.exec(response.body)[1];
			expect(response.body, 'the field is on the page').to.contain('name="orcid"');

			request({
				method: 'POST',
				url: action,
				form: true,
				failOnStatusCode: false,
				body: {
					csrfToken: token,
					givenName: 'Orcid',
					familyName: 'Cypress',
					affiliation: 'OJSBR',
					country: 'BR',
					email: email,
					username: mark,
					password: 'Cypress-' + Date.now(),
					password2: 'Cypress-' + Date.now(),
					privacyConsent: 'on',
					orcid: '',
				},
			}).then((answer) => {
				// The page comes back with the field marked instead of an account.
				expect(answer.body).to.match(/orcidManualEntry[\s\S]{0,2000}?<span class="error">/);
				expect(answer.body).to.not.contain('##plugins.generic.orcidManualEntry');
			});
		});

		// And there is no such user in the journal.
		login(adminUser, adminPassword);
		api(pageUrl('api/v1/users?searchPhrase=' + mark)).then((users) => {
			expect(users.itemsMax, 'no account may be created without the iD the journal requires').to.eq(0);
		});
	});

	it('Refuses a contributor with no iD while the journal requires one', function() {
		login(adminUser, adminPassword);
		workSubmission().then((submission) => {
			const base = workPublication(submission);
			api(base).then((publication) => authorGroupId(publication, submission).then((userGroupId) => {
				const contributor = Object.assign({
					givenName: {[submission.locale]: 'Dora'},
					familyName: {[submission.locale]: 'Cypress'},
					email: 'dora.' + Date.now() + '@example.invalid',
					userGroupId,
					includeInBrowse: true,
				}, OTHER_METADATA(submission));

				send(base + '/contributors', 'POST', contributor).then((refused) => {
					expect(refused.status, JSON.stringify(refused.body)).to.eq(400);
					expect(refused.body).to.have.property('orcid');
					expect(JSON.stringify(refused.body.orcid)).to.not.contain('##');
				});

				// The same contributor is accepted once they have one.
				send(base + '/contributors', 'POST', Object.assign({orcid: VALID}, contributor)).then((accepted) => {
					expect(accepted.status, JSON.stringify(accepted.body)).to.eq(200);
					if (accepted.body && accepted.body.id) {
						created.push({base, id: accepted.body.id});
					}
				});
			}));
		});
	});

	it('Leaves the autonomy the journal chose to leave', function() {
		login(adminUser, adminPassword);
		cy.then(() => saveSettings({showOnRegistration: true, requireOnContributor: true, editorsExempt: true}));

		cy.window().then((win) => {
			const roles = win.pkp.currentUser.roles || [];
			const runsTheJournal = roles.includes(ROLE_ID_MANAGER) || roles.includes(ROLE_ID_SUB_EDITOR);

			workSubmission().then((submission) => {
				const base = workPublication(submission);
				api(base).then((publication) => authorGroupId(publication, submission).then((userGroupId) => {
					const contributor = () => Object.assign({
						givenName: {[submission.locale]: 'Elena'},
						familyName: {[submission.locale]: 'Cypress'},
						email: 'elena.' + Date.now() + '.' + Math.floor(Math.random() * 100000) + '@example.invalid',
						userGroupId,
						includeInBrowse: true,
					}, OTHER_METADATA(submission));

					// Whatever is created here is removed in after().
					const keepIt = (answer) => {
						if (answer.body && answer.body.id) {
							created.push({base, id: answer.body.id});
						}
						return answer;
					};

					send(base + '/contributors', 'POST', contributor()).then(keepIt).then((answer) => {
						if (runsTheJournal) {
							expect(answer.status, 'whoever runs the journal saves it anyway: ' + JSON.stringify(answer.body)).to.eq(200);
						} else {
							expect(answer.status, 'the exemption is not for this account: ' + JSON.stringify(answer.body)).to.eq(400);
							expect(answer.body).to.have.property('orcid');
						}
					});

					// With the autonomy taken away, the same account is held to the rule.
					cy.then(() => saveSettings({showOnRegistration: true, requireOnContributor: true}));
					send(base + '/contributors', 'POST', contributor()).then(keepIt).then((answer) => {
						expect(answer.status, JSON.stringify(answer.body)).to.eq(400);
						expect(answer.body).to.have.property('orcid');
					});
				}));
			});
		});
	});

	it('Does not let a submission be completed while a contributor has no iD', function() {
		login(adminUser, adminPassword);
		// Only the last step is guarded here, so a contributor can be left
		// without an iD for the rule to catch.
		cy.then(() => saveSettings({showOnRegistration: true, requireOnSubmit: true}));

		workSubmission().then((submission) => {
			const publicationUrl = workPublication(submission);
			const submitUrl = pageUrl('api/v1/submissions/' + submission.id + '/submit');

			api(publicationUrl).then((publication) => {
				expect(publication.authors.length, 'a contributor on the submission').to.be.greaterThan(0);
				const author = publication.authors[0];
				const name = author.givenName[submission.locale] || author.familyName[submission.locale] || author.fullName;
				expect(name, 'a name to be shown to the author').to.be.a('string');

				// Whatever the installation came with, this one has no iD now.
				if (author.orcid) {
					send(publicationUrl + '/contributors/' + author.id, 'PUT', {orcid: ''}).then((cleared) => {
						expect(cleared.status, JSON.stringify(cleared.body)).to.eq(200);
					});
				}

				// Every plugin writes under the same key of the core, so what
				// another one has to say about the same submission is left aside
				// here: living beside it is the point, not the subject.
				const heldForTheOrcid = (answer) => JSON.stringify(
					((answer.body && answer.body.contributors) || []).filter((message) => String(message).toUpperCase().includes('ORCID'))
				);

				// What the wizard asks the server when the author presses Submit.
				send(submitUrl, 'PUT', {_validateOnly: true}).then((answer) => {
					expect(answer.status, JSON.stringify(answer.body)).to.eq(400);
					const held = heldForTheOrcid(answer);
					// The core's own key: the message shows in the contributors
					// panel of the review step, with its own errors.
					expect(held, 'nothing was held against the contributors').to.not.eq('[]');
					expect(held).to.contain(name);
					expect(held).to.not.contain('##');
				});

				// And with the journal no longer asking for it, nothing is held.
				cy.then(() => saveSettings({showOnRegistration: true}));
				send(submitUrl, 'PUT', {_validateOnly: true}).then((answer) => {
					expect(heldForTheOrcid(answer), 'the rule only applies where the journal asked for it').to.eq('[]');
				});
			});
		});
	});

	after(function() {
		if (created.length || settingsWere || extra.length || (work && work.created)) {
			login(adminUser, adminPassword);
			created.forEach(({base, id}) => withToken('DELETE').then((options) => api(base + '/contributors/' + id, options)));
			if (work && work.created) {
				extra.push(work.id);
			}
			extra.forEach((id) => withToken('DELETE').then((options) => api(pageUrl('api/v1/submissions/' + id), options)));
			if (settingsWere) {
				cy.then(() => saveSettings(settingsWere));
			}
		}
	});
});
