/**
 * @file plugins/generic/orcidManualEntry/js/orcidManualEntry.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Registers the `field-orcid-manual` form field, a plain text input that
 *        also understands the `orcid` prop the core sets when it opens the
 *        contributor edit modal.
 *
 * ContributorsListPanel.openEditModal() copies the fetched contributor onto the
 * form fields, but it special-cases the field named `orcid`:
 *
 *     fields.map(field =>
 *         field.name === 'orcid'
 *             ? (field.orcid = contributor.orcid ?? '',
 *                field.authorId = contributor.id,
 *                field.orcidDisplayValue = ...,
 *                field.isVerified = ...,
 *                field.orcidVerificationRequested = ...)
 *             : field.name === 'affiliations'
 *                 ? (field.authorId = contributor.id, field.value = contributor[field.name])
 *                 : Object.keys(contributor).includes(field.name) && (field.value = contributor[field.name]),
 *         field)
 *
 * Every other field gets `value`; `orcid` gets `orcid` instead, because core
 * assumes the FieldOrcid component (the OAuth widget), which reads that prop.
 * A plain FieldText reads `value`, so the stored ORCID never reached the input:
 * it always reopened blank, and saving the contributor again then wrote the
 * blank back over the stored ORCID.
 *
 * This component extends FieldText and seeds `value` from `orcid` the first
 * time it is created, so the rest of the form machinery (validation, submit,
 * multilingual handling) stays untouched.
 */
(function () {
	'use strict';

	if (typeof pkp === 'undefined' || !pkp.registry) {
		return;
	}

	var fieldText = pkp.registry.getComponent('field-text');

	if (!fieldText) {
		// Bail out rather than register a broken component. The PHP side keeps
		// the field out of the form when it cannot be rendered.
		if (window.console && console.error) {
			console.error(
				'[orcidManualEntry] The core "field-text" component is missing; ' +
					'the manual ORCID field will not be registered.'
			);
		}
		return;
	}

	pkp.registry.registerComponent('field-orcid-manual', {
		name: 'FieldOrcidManual',
		extends: fieldText,
		props: {
			/**
			 * The stored ORCID, as handed over by
			 * ContributorsListPanel.openEditModal(). Absent in the "add
			 * contributor" form and in the submission wizard.
			 */
			orcid: {
				type: String,
				default: '',
			},
		},
		// Seeding has to happen in mounted(), not created(): created() runs while
		// FormGroup is still rendering, and a `change` emitted at that point is
		// swallowed instead of reaching the form state. FieldText has its own
		// mounted() hook, which Vue runs before this one — extends() merges the
		// hooks rather than replacing them.
		mounted: function () {
			// `value` is null only while the field has never been filled in:
			// the form config ships it as null and openEditModal() skips it for
			// this field name. Once the user types — or clears — the input, the
			// value is a string, and must be left exactly as it is so that
			// deleting an ORCID keeps working.
			if (this.value === null || typeof this.value === 'undefined') {
				this.$emit(
					'change',
					this.name,
					'value',
					this.orcid || '',
					this.localeKey
				);
			}
		},
	});
})();
