/**
 * @file cypress/tests/functional/OrcidManualEntry.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the typeable ORCID field on the registration page, in the
 * user profile and for submission contributors, with ORCID OAuth off.
 *
 * Contributors are saved through the REST endpoints the contributor form uses,
 * so the core validation and the plugin hooks run as in production.
 *
 * Parameters (--env): contextPath; adminUser, adminPassword (a journal manager
 * whose own profile is edited and restored; captcha on login must be off for
 * the run); submissionId, publicationId and authorUserGroupId for the
 * contributor tests, which are skipped without them. The contributors added
 * are deleted at the end. Assertions use names, ids and API data, never
 * labels, so the spec runs in any language.
 */

describe('ORCID Manual Entry plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const submissionId = Cypress.env('submissionId');
	const publicationId = Cypress.env('publicationId');
	const authorUserGroupId = Cypress.env('authorUserGroupId');

	const VALID = '0000-0002-1825-0097';
	const VALID_URL = 'https://orcid.org/' + VALID;
	const WRONG_CHECK_DIGIT = '0000-0002-1825-0098';

	const url = (path) => '/index.php/' + contextPath + '/' + path;
	const identityOrcid = 'form[id="identityForm"] input[name="orcid"]';

	let csrfToken = null;

	const login = () => {
		cy.clearCookies();
		cy.visit(url('login'));
		cy.get('input[id=username]').clear().type(adminUser, {delay: 0});
		cy.get('input[id=password]').clear().type(adminPassword, {delay: 0, log: false});
		cy.get('form[id=login] button').click();
		cy.get('form[id=login]', {timeout: 30000}).should('not.exist');
	};

	const openIdentity = () => {
		cy.visit(url('user/profile'));
		cy.get(identityOrcid, {timeout: 30000}).should('exist');
	};

	// Saves the profile form and yields the JSON the handler answered.
	const saveIdentity = (value) => {
		cy.intercept('POST', '**/save-identity*').as('saveIdentity');
		cy.get(identityOrcid).clear();
		if (value) {
			cy.get(identityOrcid).type(value, {delay: 0});
		}
		cy.get('form[id="identityForm"] button[type="submit"]').click();
		return cy.wait('@saveIdentity').its('response.body');
	};

	describe('Registration page', function() {
		it('Offers a typeable ORCID field instead of the OAuth button', function() {
			cy.visit(url('user/register'), {headers: {Cookie: 'OJSSID=cypress' + Date.now()}});
			cy.get('form#register input[type="text"][name="orcid"]').should('have.length', 1);
			cy.get('form#register #orcidManualEntryDescription').invoke('text').should('match', /\S/).and('not.contain', '##');
			cy.get('form#register #connect-orcid-button').should('not.exist');
		});
	});

	describe('User profile', function() {
		let original = '';

		it('Saves a bare iD as its canonical URL and refuses a wrong check digit', function() {
			login();
			openIdentity();
			cy.get(identityOrcid).should('have.length', 1).invoke('val').then((value) => { original = value; });
			cy.get('form[id="identityForm"] #connect-orcid-button').should('not.exist');

			saveIdentity(VALID).then((body) => {
				expect(JSON.stringify(body)).to.not.contain('orcidInvalid');
			});
			openIdentity();
			cy.get(identityOrcid).should('have.value', VALID_URL);

			saveIdentity(WRONG_CHECK_DIGIT).then((body) => {
				// The form comes back with the field error instead of a success.
				expect(body.content || '').to.contain('name="orcid"');
				expect(Cypress.$('<div>').html(body.content).find('.orcidManualEntry .error').length).to.eq(1);
			});
			openIdentity();
			cy.get(identityOrcid).should('have.value', VALID_URL);
		});

		it('Clears the iD when the field is emptied', function() {
			login();
			openIdentity();
			saveIdentity('');
			openIdentity();
			cy.get(identityOrcid).should('have.value', '');
		});

		after(function() {
			if (original) {
				login();
				openIdentity();
				saveIdentity(original);
			}
		});
	});

	describe('Contributors', function() {
		const added = [];
		const api = () => url('api/v1/submissions/' + submissionId + '/publications/' + publicationId + '/contributors');
		const request = (method, path, body) => cy.request({method, url: api() + path, body, headers: {'X-Csrf-Token': csrfToken}, failOnStatusCode: false});
		const contributor = (givenName, orcid) => ({
			givenName: {en: givenName},
			familyName: {en: 'Cypress'},
			email: givenName.toLowerCase() + '.' + Date.now() + '@example.invalid',
			userGroupId: Number(authorUserGroupId),
			includeInBrowse: true,
			orcid,
		});

		before(function() {
			if (!submissionId || !publicationId || !authorUserGroupId) {
				this.skip();
			}
		});

		beforeEach(function() {
			login();
			cy.visit(url('submissions'));
			cy.window().then((win) => { csrfToken = win.pkp.currentUser.csrfToken; });
		});

		it('Stores a typed iD, refuses it for a second contributor and removes it when emptied', function() {
			request('POST', '', contributor('Ana', VALID)).then((response) => {
				expect(response.status, JSON.stringify(response.body)).to.eq(200);
				expect(response.body.orcid).to.eq(VALID_URL);
				added.push(response.body.id);

				request('POST', '', contributor('Bruno', 'https://sandbox.orcid.org/' + VALID)).then((duplicate) => {
					expect(duplicate.status).to.eq(400);
					expect(duplicate.body).to.have.property('orcid');
					if (duplicate.body.id) {
						added.push(duplicate.body.id);
					}
				});

				request('POST', '', contributor('Carla', WRONG_CHECK_DIGIT)).then((invalid) => {
					expect(invalid.status).to.eq(400);
					expect(invalid.body).to.have.property('orcid');
				});

				request('PUT', '/' + response.body.id, {orcid: ''}).then((edited) => {
					expect(edited.status, JSON.stringify(edited.body)).to.eq(200);
					expect(edited.body.orcid).to.be.oneOf([null, '']);
				});
			});
		});

		after(function() {
			if (added.length) {
				login();
				cy.visit(url('submissions'));
				cy.window().then((win) => {
					csrfToken = win.pkp.currentUser.csrfToken;
					added.forEach((id) => request('DELETE', '/' + id));
				});
			}
		});
	});
});
