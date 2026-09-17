<?php

/**
 * @file plugins/generic/orcidManualEntry/OrcidManualEntryPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OrcidManualEntryPlugin
 *
 * @brief Restores the typeable (manual) ORCID field in the author/contributor
 *        form, on the public user registration page and in the user profile,
 *        as in earlier OJS versions.
 *
 * From OJS 3.4/3.5 the former ORCID plugin is part of the core and the ORCID
 * field is read-only: it can only be filled through OAuth authentication
 * (FieldOrcid) and, when OAuth is not configured, the field is not shown at all.
 * The backend also rejects a manually entered ORCID in Repo::author()->validate(),
 * and the contributor edit endpoint drops the ORCID from the parameters before
 * saving.
 *
 * This plugin acts ONLY when ORCID OAuth is NOT configured for the context, and
 * only through hooks: no core template is replaced.
 *
 * a) Submission contributor (ContributorForm), clearing four barriers:
 *   1) Form::config::before  -> adds a typeable 'orcid' field;
 *   2) TemplateManager::display
 *                            -> publishes the 'field-orcid-manual' Vue component,
 *                               without which the stored value never comes back
 *                               to the edit form (see addFieldComponent());
 *   3) Author::validate      -> removes the "cannotUpdateAuthorOrcid" error (keeping
 *                               the core's own format/checksum validation) and
 *                               refuses an iD already used in the publication;
 *   4) Author::add::before /
 *      Author::edit          -> injects/normalizes the entered ORCID before it is
 *                               stored (the edit endpoint drops it from the
 *                               parameters, so it is put back here).
 *
 * b) Public registration (RegistrationForm) and user profile (IdentityForm),
 *    where the core hides the field and ignores the submitted value when OAuth
 *    is off:
 *   5) *form::display        -> adds the field to the rendered form with an
 *                               output filter (neither template has a hook);
 *   6) *form::Constructor    -> validates format/checksum of what was typed;
 *   7) *form::execute        -> stores the normalized ORCID on the user.
 *
 * The ORCID stored on the user is copied by the core itself to the authorship
 * metadata of a submission (Repo::author()->newAuthorFromUser()).
 */

namespace APP\plugins\generic\orcidManualEntry;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use PKP\components\forms\FieldText;
use PKP\components\forms\publication\ContributorForm;
use PKP\core\JSONMessage;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCustom;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\orcid\OrcidManager;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\template\PKPTemplateManager;
use PKP\user\form\IdentityForm;
use PKP\user\form\RegistrationForm;
use PKP\validation\ValidatorORCID;

class OrcidManualEntryPlugin extends GenericPlugin
{
    /**
     * Name of the Vue component registered by js/orcidManualEntry.js. It must
     * differ from 'field-text' because the component extends FieldText to also
     * read the 'orcid' prop (see addFieldComponent()).
     */
    public const FIELD_COMPONENT = 'field-orcid-manual';

    /**
     * The journal settings and what they do when nothing was saved yet: the
     * field is offered everywhere, as in earlier versions of this plugin, and
     * nothing is required until a journal asks for it.
     */
    public const DEFAULTS = [
        'showOnRegistration' => true,
        'requireOnRegistration' => false,
        'requireOnContributor' => false,
        'requireOnSubmit' => false,
        'editorsExempt' => true,
    ];

    /**
     * Who is left out while `editorsExempt` is on: the roles that decide about
     * the submission. An assistant follows the rule like everybody else. Public
     * registration is not covered — whoever registers holds no role yet.
     */
    public const EXEMPT_ROLES = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];

    /**
     * Templates that mount the ContributorsListPanel, where the field can be
     * shown: the dashboard (every workflow goes through it) and the submission
     * wizard.
     */
    public const TEMPLATES_WITH_CONTRIBUTORS = [
        'dashboard/editors.tpl',
        'submission/wizard.tpl',
    ];

    /** Id of the form each user form template renders, used to place the field. */
    public const USER_FORM_IDS = [
        'registrationform' => 'register',
        'identityform' => 'identityForm',
    ];

    /**
     * Normalized ORCID captured in Author::validate, reused by
     * Author::add::before / Author::edit within the SAME request.
     * null = clear the value; string = normalized URL.
     */
    private static ?string $pendingOrcid = null;

    /** Whether the current request carried the 'orcid' key in its payload. */
    private static bool $hasPending = false;

    /**
     * Register the plugin and, where it is enabled, its hooks.
     *
     * @param string $category
     * @param string $path
     * @param null|int $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        // Contributors, registration and profiles are always edited in a journal request.
        if (!$success || Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return $success;
        }

        // Submission contributor.
        Hook::add('Form::config::before', $this->addOrcidField(...));
        Hook::add('TemplateManager::display', $this->addFieldComponent(...));
        Hook::add('Author::validate', $this->allowManualOrcid(...));
        Hook::add('Author::add::before', $this->applyOrcidOnAdd(...));
        Hook::add('Author::edit', $this->applyOrcidOnEdit(...));

        // Completing the submission, where the journal may require every
        // contributor to have an iD.
        Hook::add('Submission::validateSubmit', $this->validateSubmit(...));

        // Public registration and user profile.
        Hook::add('registrationform::display', $this->addUserOrcidField(...));
        Hook::add('identityform::display', $this->addUserOrcidField(...));
        Hook::add('registrationform::Constructor', $this->addUserOrcidCheck(...));
        Hook::add('identityform::Constructor', $this->addUserOrcidCheck(...));
        Hook::add('registrationform::execute', $this->saveRegistrationOrcid(...));
        Hook::add('identityform::execute', $this->saveIdentityOrcid(...));

        return $success;
    }

    /**
     * Name shown in the plugins list.
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.orcidManualEntry.displayName');
    }

    /**
     * Description shown in the plugins list.
     */
    public function getDescription(): string
    {
        return __('plugins.generic.orcidManualEntry.description');
    }

    /**
     * Which of the two user forms this is, or null for anything else. Matched by
     * type and not by class name, so that a form extended by a theme or by
     * another plugin is still recognized.
     */
    private static function userFormKey(object $form): ?string
    {
        if ($form instanceof RegistrationForm) {
            return 'registrationform';
        }

        return $form instanceof IdentityForm ? 'identityform' : null;
    }

    /**
     * A journal setting, with its default while it was never saved.
     */
    public function getFlag(?int $contextId, string $name): bool
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            return false;
        }
        $value = $contextId === null ? null : $this->getSetting($contextId, $name);

        return $value === null || $value === '' ? self::DEFAULTS[$name] : (bool) $value;
    }

    /**
     * The same, for the journal of the request being answered.
     */
    public function currentFlag(string $name): bool
    {
        $context = Application::get()->getRequest()->getContext();

        return $this->getFlag($context?->getId(), $name);
    }

    /**
     * Whether the person making the request is left out of what the journal
     * requires. Being exempt never makes an invalid iD acceptable: it only
     * lifts the requirement to have one.
     */
    public function isExempt(?int $contextId): bool
    {
        if ($contextId === null || !$this->getFlag($contextId, 'editorsExempt')) {
            return false;
        }
        $user = Application::get()->getRequest()->getUser();

        return $user && $user->hasRole(self::EXEMPT_ROLES, $contextId);
    }

    /** The same, for the journal of the request being answered. */
    public function currentlyExempt(): bool
    {
        return $this->isExempt(Application::get()->getRequest()->getContext()?->getId());
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $router = $request->getRouter();

        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ] : [],
            parent::getActions($request, $actionArgs)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $context = $request->getContext();
        if (!$context) {
            return new JSONMessage(false);
        }

        $form = new OrcidManualEntrySettingsForm($this, $context);
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }
        $form->execute();

        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId());

        return new JSONMessage(true);
    }

    /**
     * Hook Submission::validateSubmit — a submission cannot be completed while a
     * contributor has no iD, when the journal asks for it.
     *
     * @param array $args [&$errors, $submission, $context]
     */
    public function validateSubmit(string $hookName, array $args): bool
    {
        // The hook hands over the context of the submission being completed,
        // which is what the rule has to be read from: a submission can also be
        // completed outside a request of its own journal.
        $context = $args[2] ?? Application::get()->getRequest()->getContext();
        if (OrcidManager::isEnabled($context)
            || !$this->getFlag($context?->getId(), 'requireOnSubmit')
            || $this->isExempt($context?->getId())
        ) {
            return Hook::CONTINUE;
        }

        $errors = &$args[0];
        $submission = $args[1] ?? null;
        $publication = $submission?->getCurrentPublication();
        if (!$publication) {
            return Hook::CONTINUE;
        }

        $missing = [];
        foreach (Repo::author()->getCollector()->filterByPublicationIds([$publication->getId()])->getMany() as $author) {
            if (self::normalizeOrcid($author->getData('orcid')) === '') {
                $missing[] = $author->getFullName(false) ?: __('common.none');
            }
        }

        if ($missing) {
            // The same key the core uses for its own contributor errors, so the
            // message shows inside the contributors panel of the review step
            // instead of only raising the wizard's generic warning.
            $errors['contributors'] ??= [];
            $errors['contributors'][] = __('plugins.generic.orcidManualEntry.error.requiredOnSubmit', ['names' => implode(', ', $missing)]);
        }

        return Hook::CONTINUE;
    }

    /**
     * The plugin only acts while ORCID OAuth is NOT configured. With OAuth on,
     * the core handles FieldOrcid and the verification flow, and this plugin
     * stays inert.
     */
    private function orcidOAuthActive(): bool
    {
        $context = Application::get()->getRequest()->getContext();
        return OrcidManager::isEnabled($context);
    }

    /**
     * Barrier 1: adds a typeable ORCID field to the ContributorForm.
     *
     * Form::config::before is fired through Hook::run, so the form arrives as
     * the second parameter of the callback (not inside an array).
     */
    public function addOrcidField($hookName, $form): bool
    {
        if (!$form instanceof ContributorForm) {
            return Hook::CONTINUE;
        }
        if ($this->orcidOAuthActive()) {
            return Hook::CONTINUE;
        }
        // Do not add it twice when the config is built more than once.
        if ($form->getField('orcid')) {
            return Hook::CONTINUE;
        }

        $form->addField(new FieldText('orcid', [
            // A FieldText that also reads the 'orcid' prop; see addFieldComponent().
            'component' => self::FIELD_COMPONENT,
            'label' => __('user.orcid'),
            'description' => __('plugins.generic.orcidManualEntry.field.description'),
            'isMultilingual' => false,
            // Where the journal requires it, the form marks it as it marks every
            // other required field of the application, and whoever is exempt
            // does not see a mark they are not held to.
            'isRequired' => $this->currentFlag('requireOnContributor') && !$this->currentlyExempt(),
        ]), [FIELD_POSITION_AFTER, 'url']);

        return Hook::CONTINUE;
    }

    /**
     * Barrier 2: publishes the Vue component of the field.
     *
     * ContributorsListPanel.openEditModal() copies the contributor fetched from
     * the API onto the form fields, but special-cases the field named 'orcid':
     * instead of `field.value = contributor.orcid` it sets
     * `field.orcid = contributor.orcid`, because the core assumes FieldOrcid (the
     * OAuth widget) is there and reads that prop. A plain FieldText reads
     * `value`, so the stored ORCID never reached the input: the form reopened
     * blank and the next "Save" wrote the blank over the stored ORCID.
     *
     * The component registered in js/orcidManualEntry.js extends FieldText and
     * seeds `value` from the `orcid` prop, which removes both symptoms.
     *
     * The script must load after js/build.js (registered by the core with
     * STYLE_SEQUENCE_LATE), so that 'field-text' is already registered, and
     * before pkp.registry.init() at the end of the page, which creates the Vue
     * app. STYLE_SEQUENCE_LAST gives exactly that window.
     *
     * @param array $args [$templateMgr, &$template, &$output]
     */
    public function addFieldComponent($hookName, $args): bool
    {
        if ($this->orcidOAuthActive()) {
            return Hook::CONTINUE;
        }

        $templateMgr = $args[0];
        $template = $args[1];

        if (!in_array($template, self::TEMPLATES_WITH_CONTRIBUTORS, true)) {
            return Hook::CONTINUE;
        }

        $baseUrl = Application::get()->getRequest()->getBaseUrl() . '/' . $this->getPluginPath();

        $templateMgr->addJavaScript(
            'orcidManualEntry',
            "{$baseUrl}/js/orcidManualEntry.js",
            [
                'priority' => PKPTemplateManager::STYLE_SEQUENCE_LAST,
                'contexts' => ['backend'],
            ]
        );

        return Hook::CONTINUE;
    }

    /**
     * Barrier 3: removes the "cannotUpdateAuthorOrcid" block the core adds whenever
     * 'orcid' is in the parameters, keeping the core's own format/checksum
     * validation. It also captures the normalized value for the saving steps.
     *
     * Author::validate is fired through Hook::call, so the arguments arrive as an
     * array in the second parameter; $args[0] is the $errors array (by reference)
     * and $args[2] the submitted $props.
     */
    public function allowManualOrcid($hookName, $args): bool
    {
        if ($this->orcidOAuthActive()) {
            return Hook::CONTINUE;
        }

        // Whoever runs the journal can be left out of the requirement.
        $required = $this->currentFlag('requireOnContributor') && !$this->currentlyExempt();
        $props = $args[2] ?? [];
        if (!array_key_exists('orcid', $props)) {
            self::$hasPending = false;
            self::$pendingOrcid = null;
            // Nothing was sent: with the iD required, a contributor kept without
            // one is refused, whatever asked for the save.
            if ($required && self::normalizeOrcid($args[1]?->getData('orcid') ?? '') === '') {
                $args[0]['orcid'] = [__('plugins.generic.orcidManualEntry.error.requiredOnContributor')];
            }
            return Hook::CONTINUE;
        }

        $normalized = self::normalizeOrcid($props['orcid']);
        self::$hasPending = true;
        self::$pendingOrcid = ($normalized === '') ? null : $normalized;

        if ($normalized === '') {
            if ($required) {
                $args[0]['orcid'] = [__('plugins.generic.orcidManualEntry.error.requiredOnContributor')];
                self::$hasPending = false;
                return Hook::CONTINUE;
            }
            // Empty field: no ORCID to store, drop any ORCID error.
            unset($args[0]['orcid']);
        } elseif (self::isValidOrcid($normalized)) {
            // Valid format. Before letting it through, refuse an iD that already
            // belongs to another contributor of the SAME publication: the core
            // does not check this, and two authors with the same ORCID go
            // unnoticed until the Crossref deposit, where they become one person.
            $holder = $this->duplicateOrcidHolder($args[1] ?? null, $props, $normalized);
            if ($holder !== null) {
                $args[0]['orcid'] = [__('plugins.generic.orcidManualEntry.error.duplicateOrcid', ['name' => $holder])];
                // Nothing to store: the saving steps must not put the value back.
                self::$hasPending = false;
                self::$pendingOrcid = null;
                return Hook::CONTINUE;
            }
            // Drop the core block and the format error on the raw value.
            unset($args[0]['orcid']);
        } else {
            // Invalid value: keep only the invalid ORCID message.
            $args[0]['orcid'] = [__('user.orcid.orcidInvalid')];
        }

        return Hook::CONTINUE;
    }

    /**
     * Barrier 4a: stores the ORCID when a contributor is ADDED.
     */
    public function applyOrcidOnAdd($hookName, $args): bool
    {
        if ($this->orcidOAuthActive() || !self::$hasPending) {
            return Hook::CONTINUE;
        }
        $author = $args[0];
        $author->setData('orcid', self::$pendingOrcid);
        self::$hasPending = false;

        return Hook::CONTINUE;
    }

    /**
     * Barrier 4b: stores the ORCID when a contributor is EDITED. The endpoint drops
     * the ORCID from the parameters before saving, so it is put back on the object
     * about to be persisted (the hook runs before the UPDATE).
     *
     * An empty field means "remove the ORCID", and must keep meaning that. Since
     * this is the plugin's destructive operation -- and it once fired by accident,
     * when the form reopened blank -- it is written to the error log, so that any
     * future regression of the Vue component is traceable instead of silent.
     *
     * $args[0] is the author about to be stored; $args[1] the author as it is now.
     */
    public function applyOrcidOnEdit($hookName, $args): bool
    {
        if ($this->orcidOAuthActive() || !self::$hasPending) {
            return Hook::CONTINUE;
        }
        $newAuthor = $args[0];
        $currentOrcid = $args[1]->getData('orcid');

        if (self::$pendingOrcid === null && !empty($currentOrcid)) {
            error_log(sprintf(
                '[orcidManualEntry] Removing the ORCID of contributor %d (the field was submitted empty).',
                (int) $newAuthor->getId()
            ));
        }

        $newAuthor->setData('orcid', self::$pendingOrcid);
        self::$hasPending = false;

        return Hook::CONTINUE;
    }

    //
    // Public registration and user profile
    //

    /**
     * Barrier 5: adds the ORCID field to the registration and profile forms.
     *
     * Both core templates show ORCID only under `{if $orcidEnabled}`, which the
     * forms set to false while OAuth is off, and what they include then is the
     * OAuth widget. Neither template has a hook, so the field is added to the
     * rendered form by an output filter: the core templates stay untouched and
     * `$orcidEnabled` stays false, so the OAuth widget is never drawn.
     *
     * The `*form::display` hook runs at the start of Form::fetch(), before the
     * form is rendered. $args[0] is the form; returning Hook::CONTINUE keeps the
     * normal fetch.
     */
    public function addUserOrcidField($hookName, $args): bool
    {
        if ($this->orcidOAuthActive()) {
            return Hook::CONTINUE;
        }

        $form = $args[0];
        $formKey = $form instanceof Form ? self::userFormKey($form) : null;
        if ($formKey === null) {
            return Hook::CONTINUE;
        }
        // A journal may keep the field out of the public registration page; the
        // profile always has it, or the person could never record their own iD.
        if ($formKey === 'registrationform' && !$this->currentFlag('showOnRegistration')) {
            return Hook::CONTINUE;
        }

        $templateMgr = PKPTemplateManager::getManager(Application::get()->getRequest());
        // Named: Smarty calls every closure filter "closure", so an unnamed one would replace, or be
        // replaced by, the output filter of another plugin in the same request.
        // Only the public registration page can require it: whoever edits their
        // own profile is not the person a journal can hold to a rule here.
        $required = $formKey === 'registrationform' && $this->currentFlag('requireOnRegistration');
        $templateMgr->registerFilter('output', function (string $output) use ($form, $formKey, $required): string {
            return self::insertUserOrcidField($output, self::USER_FORM_IDS[$formKey], self::renderUserOrcidField($form, $formKey === 'registrationform', $required));
        }, 'orcidManualEntryUserField');

        return Hook::CONTINUE;
    }

    /**
     * The markup of the field, in the style of the page it goes into: the reader
     * pages for registration, the form builder style for the profile.
     */
    public static function renderUserOrcidField(Form $form, bool $frontend, bool $required = false): string
    {
        $value = htmlspecialchars((string) $form->getData('orcid'), ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars(__('user.orcid'), ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars(__('plugins.generic.orcidManualEntry.field.description'), ENT_QUOTES, 'UTF-8');
        $errors = $form->getErrorsArray();
        $error = isset($errors['orcid']) ? '<span class="error">' . htmlspecialchars((string) $errors['orcid'], ENT_QUOTES, 'UTF-8') . '</span>' : '';
        $input = '<input type="text" name="orcid" id="orcidManualEntry" value="' . $value . '" maxlength="46" autocomplete="off"'
            . ($required ? ' required aria-required="true"' : '')
            . ' placeholder="https://orcid.org/0000-0002-1825-0097" aria-describedby="orcidManualEntryDescription"';

        if ($frontend) {
            // The mark the registration page puts on every field it requires.
            $mark = $required
                ? '<span class="required" aria-hidden="true">*</span><span class="pkp_screen_reader">'
                    . htmlspecialchars(__('common.required'), ENT_QUOTES, 'UTF-8') . '</span>'
                : '';

            return '<fieldset class="orcid orcidManualEntry"><legend>' . $label . '</legend><div class="fields"><div class="orcid"><label>'
                . '<span class="label">' . $label . $mark . '</span>' . $input . '></label>'
                . '<div class="description" id="orcidManualEntryDescription">' . $description . '</div>' . $error
                . '</div></div></fieldset>';
        }

        // The mark the forms of the administration put on a required field.
        $mark = $required ? '<span class="req">*</span>' : '';

        return '<div class="section orcidManualEntry">' . $error . '<div>' . $input . ' class="field text">'
            . '<label class="sub_label" for="orcidManualEntry">' . $label . $mark . '</label></div>'
            . '<label class="description" id="orcidManualEntryDescription">' . $description . '</label></div>';
    }

    /**
     * Put the field into the rendered form, once.
     *
     * Registration: right after the opening tag of form#register, where the core
     * would have put its ORCID widget. Profile: after the last field of
     * form#identityForm, before the privacy note that precedes the buttons.
     * Output that is not that form, or already has an ORCID input, is returned
     * unchanged, so the filter is harmless for anything else rendered in the
     * same request.
     */
    public static function insertUserOrcidField(string $output, string $formId, string $field): string
    {
        if (preg_match('/<input\b[^>]*\bname="orcid"/', $output)) {
            return $output;
        }

        if ($formId === 'register') {
            // The registration page belongs to the theme, and a theme is free to
            // write its own form: the id and the classes of the core may not be
            // there at all. What no theme can change is where the form posts to,
            // so that is what the field is anchored on.
            if (!preg_match('~<form\b[^>]*\baction="[^"]*/user/register[^"]*"[^>]*>~i', $output, $match, PREG_OFFSET_CAPTURE)) {
                return $output;
            }

            return substr_replace($output, $field, $match[0][1] + strlen($match[0][0]), 0);
        }

        // The profile is a page of the administration, which no theme rewrites.
        $formStart = strpos($output, 'id="' . $formId . '"');
        if ($formStart === false) {
            return $output;
        }

        $required = strpos($output, 'class="formRequired"', $formStart);
        if ($required === false) {
            return $output;
        }
        $at = strrpos(substr($output, 0, $required), '<p>');
        $avatar = strpos($output, 'preferredAvatarInitials', $formStart);
        if ($avatar !== false && ($privacy = strpos($output, '<p>', $avatar)) !== false && $privacy < $at) {
            $at = $privacy;
        }

        return $at === false ? $output : substr_replace($output, $field, $at, 0);
    }

    /**
     * Barrier 6: validates what was typed.
     *
     * The `*form::Constructor` hook runs at the end of Form::__construct(), when the
     * list of checks exists and before any validate(). The field is optional: an
     * empty value passes, a filled one must be a valid ORCID in format and check
     * digit.
     */
    public function addUserOrcidCheck($hookName, $args): bool
    {
        if ($this->orcidOAuthActive()) {
            return Hook::CONTINUE;
        }

        $form = $args[0];
        if (!$form instanceof Form) {
            return Hook::CONTINUE;
        }

        $registering = self::userFormKey($form) === 'registrationform';
        if ($registering && !$this->currentFlag('showOnRegistration')) {
            // The field is not on the page: nothing to validate, and requiring it
            // would make registration impossible.
            return Hook::CONTINUE;
        }
        if ($registering && $this->currentFlag('requireOnRegistration')) {
            $form->addCheck(new FormValidatorCustom(
                $form,
                'orcid',
                FormValidatorCustom::FORM_VALIDATOR_REQUIRED_VALUE,
                'plugins.generic.orcidManualEntry.error.requiredOnRegistration',
                fn ($orcid) => self::normalizeOrcid($orcid) !== ''
            ));
        }

        $form->addCheck(new FormValidatorCustom(
            $form,
            'orcid',
            'optional',
            'user.orcid.orcidInvalid',
            function ($orcid) {
                $normalized = self::normalizeOrcid($orcid);
                return $normalized === '' || self::isValidOrcid($normalized);
            }
        ));

        return Hook::CONTINUE;
    }

    /**
     * Barrier 7a: stores the ORCID on the newly registered user.
     *
     * RegistrationForm::readInputData() already reads 'orcid' from the POST, but
     * the core execute() only applies it when OrcidManager::isEnabled(). The
     * `registrationform::execute` hook runs inside Form::execute(), called by
     * RegistrationForm::execute() BEFORE Repo::user()->add(), and the user being
     * built is in the public $form->user property for exactly this kind of use.
     */
    public function saveRegistrationOrcid($hookName, $args): bool
    {
        if ($this->orcidOAuthActive()) {
            return Hook::CONTINUE;
        }

        $form = $args[0];
        if (!$form instanceof RegistrationForm || !isset($form->user)) {
            return Hook::CONTINUE;
        }

        $orcid = self::readSubmittedOrcid($form);
        if ($orcid === false) {
            return Hook::CONTINUE;
        }

        $form->user->setOrcid($orcid);
        $form->user->setOrcidVerified(false);

        return Hook::CONTINUE;
    }

    /**
     * Barrier 7b: stores the ORCID edited in the profile.
     *
     * IdentityForm::execute() never applies the ORCID: the only handling it gives
     * the field is "removeOrcidId", from the OAuth flow. The hook runs in
     * Form::execute(), called by BaseProfileForm::execute() right before
     * Repo::user()->edit($user) -- and it is that same user object
     * ($request->getUser()) that is written here.
     */
    public function saveIdentityOrcid($hookName, $args): bool
    {
        if ($this->orcidOAuthActive()) {
            return Hook::CONTINUE;
        }

        $form = $args[0];
        if (!$form instanceof IdentityForm) {
            return Hook::CONTINUE;
        }
        // A request to remove the OAuth token is handled by the core.
        if ($form->getData('removeOrcidId') === 'true') {
            return Hook::CONTINUE;
        }

        $orcid = self::readSubmittedOrcid($form);
        if ($orcid === false) {
            return Hook::CONTINUE;
        }

        $user = Application::get()->getRequest()->getUser();
        if (!$user) {
            return Hook::CONTINUE;
        }

        if ($orcid === null && !empty($user->getOrcid())) {
            error_log(sprintf(
                '[orcidManualEntry] Removing the ORCID of user %d (the field was submitted empty).',
                (int) $user->getId()
            ));
        }

        $user->setOrcid($orcid);
        $user->setOrcidVerified(false);

        return Hook::CONTINUE;
    }

    /**
     * Reads the ORCID submitted by the form and returns it normalized.
     *
     * @return string|null|false The canonical URL; null to clear the value; false
     *                           when the request did not carry the field -- then
     *                           nothing is touched, so that a POST without the
     *                           field never erases a stored ORCID.
     */
    private static function readSubmittedOrcid(Form $form)
    {
        $raw = $form->getData('orcid');
        if ($raw === null) {
            return false;
        }

        $normalized = self::normalizeOrcid($raw);
        if ($normalized === '') {
            return null;
        }

        // Validation already refused invalid values; this guard covers a form
        // executed without validate().
        return self::isValidOrcid($normalized) ? $normalized : false;
    }

    /**
     * Name of the contributor of the same publication who already uses this
     * ORCID, or null when there is none.
     *
     * The contributor being edited is skipped, or re-saving an author without
     * touching the ORCID would conflict with itself. When ADDING, $author is null
     * and the publication comes from the submitted parameters.
     *
     * The comparison is by iD (the 16 digits), not by URL: the same researcher
     * stored once as orcid.org and once as sandbox.orcid.org is still one person.
     */
    private function duplicateOrcidHolder($author, array $props, string $normalized): ?string
    {
        $publicationId = (int) ($props['publicationId'] ?? 0);
        if (!$publicationId && $author) {
            $publicationId = (int) $author->getData('publicationId');
        }
        $id = self::orcidId($normalized);
        if (!$publicationId || $id === '') {
            return null;
        }

        $currentId = $author ? (int) $author->getId() : 0;
        foreach (Repo::author()->getCollector()->filterByPublicationIds([$publicationId])->getMany() as $other) {
            if ($currentId && (int) $other->getId() === $currentId) {
                continue;
            }
            if (self::orcidId((string) $other->getData('orcid')) === $id) {
                return $other->getFullName();
            }
        }

        return null;
    }

    /**
     * The 16 digits of an ORCID (0000-0002-1825-0097), whether given as a bare iD
     * or as a production or sandbox URL. Empty string when there is no iD.
     */
    public static function orcidId(string $value): string
    {
        return preg_match('#(\d{4}-\d{4}-\d{4}-\d{3}[0-9Xx])#', $value, $m)
            ? strtoupper($m[1])
            : '';
    }

    /**
     * Normalizes the input to the canonical ORCID URL the core expects:
     * https://orcid.org/0000-0002-1825-0097 (or the sandbox domain). Accepts the
     * bare iD (16 digits) or the URL with or without protocol. Unrecognized values
     * are returned as they are, so that they fail validation.
     *
     */
    public static function normalizeOrcid($raw): string
    {
        $v = trim((string) $raw);
        if ($v === '') {
            return '';
        }

        // Bare iD: 0000-0002-1825-0097
        if (preg_match('#^(\d{4}-\d{4}-\d{4}-\d{3}[0-9Xx])$#', $v, $m)) {
            return OrcidManager::ORCID_URL . strtoupper($m[1]);
        }

        // URL (with or without protocol/www), orcid.org or sandbox.orcid.org
        if (preg_match('#^(?:https?://)?(?:www\.)?(sandbox\.)?orcid\.org/(\d{4}-\d{4}-\d{4}-\d{3}[0-9Xx])/?$#i', $v, $m)) {
            $host = $m[1] ? 'sandbox.orcid.org' : 'orcid.org';
            return 'https://' . $host . '/' . strtoupper($m[2]);
        }

        return $v;
    }

    /**
     * Validates the ORCID (format + ISNI check digit) with the core validator.
     */
    public static function isValidOrcid(string $orcidUrl): bool
    {
        return (new ValidatorORCID())->isValid($orcidUrl);
    }
}
