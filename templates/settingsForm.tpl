{**
 * plugins/generic/orcidManualEntry/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Where the typed ORCID is asked for, and where it is required.
 *}
<script type="text/javascript">
	$(function() {ldelim}
		$('#orcidManualEntrySettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form
	class="pkp_form"
	id="orcidManualEntrySettingsForm"
	method="POST"
	action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="orcidManualEntrySettingsNotification"}

	<p class="pkp_help">{translate key="plugins.generic.orcidManualEntry.settings.intro"}</p>

	{fbvFormArea id="orcidManualEntryWhere" title="plugins.generic.orcidManualEntry.settings.area.where"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="showOnRegistration" name="showOnRegistration" checked=$showOnRegistration label="plugins.generic.orcidManualEntry.settings.showOnRegistration"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="orcidManualEntryRequired" title="plugins.generic.orcidManualEntry.settings.area.required" description="plugins.generic.orcidManualEntry.settings.area.required.description"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="requireOnRegistration" name="requireOnRegistration" checked=$requireOnRegistration label="plugins.generic.orcidManualEntry.settings.requireOnRegistration"}
			{fbvElement type="checkbox" id="requireOnContributor" name="requireOnContributor" checked=$requireOnContributor label="plugins.generic.orcidManualEntry.settings.requireOnContributor"}
			{fbvElement type="checkbox" id="requireOnSubmit" name="requireOnSubmit" checked=$requireOnSubmit label="plugins.generic.orcidManualEntry.settings.requireOnSubmit"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
