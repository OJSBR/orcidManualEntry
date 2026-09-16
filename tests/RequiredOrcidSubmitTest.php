<?php

/**
 * @file plugins/generic/orcidManualEntry/tests/RequiredOrcidSubmitTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredOrcidSubmitTest
 *
 * @brief The submission cannot be completed while a contributor has no iD.
 *
 *        The core's own validation of the last step is run against a real
 *        database, which is what the wizard calls when the author presses
 *        Submit. The test creates and deletes its own submission, puts the
 *        settings it changed back, and skips itself where the installation
 *        looks like a live journal.
 */

namespace APP\plugins\generic\orcidManualEntry\tests;

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\orcidManualEntry\OrcidManualEntryPlugin;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPRequest;
use PKP\core\Registry;
use PKP\orcid\OrcidManager;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\tests\PKPTestCase;
use PKP\userGroup\UserGroup;

class RequiredOrcidSubmitTest extends PKPTestCase
{
    private const CONTEXT_ID = 1;
    private const MARKER = '[ORCID-TESTS]';
    private const PRODUCTION_LOOKS_LIKE = 100;
    private const ORCID = 'https://orcid.org/0000-0002-1825-0097';

    private array $createdSubmissions = [];
    /** The settings as they were before the test, to be put back. */
    private array $savedSettings = [];
    private ?OrcidManualEntryPlugin $plugin = null;

    /**
     * The plugin is registered once for the whole class: a hook added again in
     * the same process would answer twice, and every message would be doubled.
     */
    private static ?OrcidManualEntryPlugin $registered = null;
    private static bool $switchedOn = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Application::getContextDAO()->getById(self::CONTEXT_ID)) {
            $this->markTestSkipped('context ' . self::CONTEXT_ID . ' does not exist');
        }
        $published = DB::table('submissions')->where('status', Submission::STATUS_PUBLISHED)->count();
        if ($published > self::PRODUCTION_LOOKS_LIKE) {
            $this->markTestSkipped('this installation has ' . $published . ' published submissions: it looks like a live site');
        }

        $this->pinContext();
        $this->plugin = $this->loadPlugin();
    }

    protected function tearDown(): void
    {
        $nobody = null;
        Registry::set('user', $nobody);
        foreach ($this->savedSettings as $name => $value) {
            if ($value === null) {
                $this->plugin?->updateSetting(self::CONTEXT_ID, $name, 0, 'bool');
            } else {
                $this->plugin?->updateSetting(self::CONTEXT_ID, $name, $value, 'bool');
            }
        }
        foreach ($this->createdSubmissions as $id) {
            if ($submission = Repo::submission()->get($id)) {
                Repo::submission()->delete($submission);
            }
        }
        $this->savedSettings = [];
        $this->createdSubmissions = [];
        parent::tearDown();
    }

    /** There is no URL on the command line, so the context is pinned on the router. */
    private function pinContext(): void
    {
        $request = Application::get()->getRequest();
        $router = new class () extends PageRouter {
            public $pinned;

            public function getContext(PKPRequest $request, bool $forceReload = false): ?\PKP\context\Context
            {
                return $this->pinned;
            }
        };
        $router->setApplication(Application::get());
        $router->pinned = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $request->setRouter($router);
    }

    /**
     * The plugin with its listeners attached, as the application loads it. Where
     * it is installed but switched off, it is switched on for the test and put
     * back afterwards, so that the rule is checked and not skipped.
     */
    private function loadPlugin(): OrcidManualEntryPlugin
    {
        if (self::$registered) {
            return self::$registered;
        }

        // From disk, not from the database: a plugin that was never switched on
        // is not in the enabled list, and this test switches it on itself.
        PluginRegistry::loadCategory('generic', false, self::CONTEXT_ID);
        /** @var ?OrcidManualEntryPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'orcidmanualentryplugin');
        if (!$plugin) {
            $this->markTestSkipped('the plugin is not installed here');
        }
        if (OrcidManager::isEnabled(Application::getContextDAO()->getById(self::CONTEXT_ID))) {
            $this->markTestSkipped('ORCID OAuth is configured: the core owns the field and the plugin stays inert');
        }
        if (!$plugin->getEnabled(self::CONTEXT_ID)) {
            $plugin->updateSetting(self::CONTEXT_ID, 'enabled', 1, 'bool');
            self::$switchedOn = true;
            // Only now does register() attach the hooks.
            $plugin = new OrcidManualEntryPlugin();
            $plugin->register('generic', 'plugins/generic/orcidManualEntry', self::CONTEXT_ID);
        }

        return self::$registered = $plugin;
    }

    /** The journal is left switched off again if it was this test that switched it on. */
    public static function tearDownAfterClass(): void
    {
        if (self::$switchedOn && self::$registered) {
            self::$registered->updateSetting(self::CONTEXT_ID, 'enabled', 0, 'bool');
        }
        self::$registered = null;
        self::$switchedOn = false;
        parent::tearDownAfterClass();
    }

    /** Keeps a setting to put it back in tearDown(), then lets the test change it. */
    private function remember(string $name): void
    {
        if (!array_key_exists($name, $this->savedSettings)) {
            $this->savedSettings[$name] = $this->plugin?->getSetting(self::CONTEXT_ID, $name)
                ?? PluginRegistry::getPlugin('generic', 'orcidmanualentryplugin')?->getSetting(self::CONTEXT_ID, $name);
        }
    }

    private function requireOnSubmit(bool $required): void
    {
        $this->setFlag('requireOnSubmit', $required);
    }

    /** Changes a setting, keeping what it was to put it back in tearDown(). */
    private function setFlag(string $name, bool $value): void
    {
        $this->remember($name);
        $this->plugin->updateSetting(self::CONTEXT_ID, $name, $value ? 1 : 0, 'bool');
    }

    /** The first section of the context, or none where the application allows it. */
    private function firstSectionId(): ?int
    {
        $id = Repo::section()->getCollector()->filterByContextIds([self::CONTEXT_ID])->getIds()->first();

        return $id === null ? null : (int) $id;
    }

    /**
     * A submission waiting for its last step, with the given contributors, each
     * one [givenName, orcid].
     */
    private function submissionWithContributors(array $contributors): Submission
    {
        $context = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $userGroup = UserGroup::withContextIds([self::CONTEXT_ID])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
        $this->assertNotNull($userGroup, 'the context has no author role');

        $submission = Repo::submission()->newDataObject([
            'contextId' => self::CONTEXT_ID,
            'status' => Submission::STATUS_QUEUED,
            'submissionProgress' => 'review',
            'stageId' => WORKFLOW_STAGE_ID_SUBMISSION,
            'locale' => 'en',
        ]);
        $publication = Repo::publication()->newDataObject([
            'title' => ['en' => self::MARKER . ' the iD is required to submit'],
            // The core reads the section (the series, in OMP) before it validates
            // anything else, and each application names the field its own way.
            // A journal files the submission under a section; a press takes it
            // without a series.
            Application::getSectionIdPropName() => $this->firstSectionId(),
            'locale' => 'en',
            'status' => Submission::STATUS_QUEUED,
        ]);
        $submissionId = Repo::submission()->add($submission, $publication, $context);
        $this->createdSubmissions[] = $submissionId;

        $submission = Repo::submission()->get($submissionId);
        $publication = $submission->getCurrentPublication();

        $seq = 0;
        foreach ($contributors as [$givenName, $orcid]) {
            Repo::author()->add(Repo::author()->newDataObject([
                'publicationId' => $publication->getId(),
                'givenName' => ['en' => $givenName],
                'familyName' => ['en' => 'Contributor'],
                'userGroupId' => $userGroup->id,
                'seq' => $seq++,
                'includeInBrowse' => true,
                'email' => strtolower($givenName) . '.' . time() . '@example.invalid',
                'country' => 'BR',
                'orcid' => $orcid,
            ]));
        }

        return Repo::submission()->get($submissionId);
    }

    /**
     * What this plugin holds against the contributors, as one string.
     *
     * Every plugin writes under the same key of the core, so what another one
     * has to say about the same submission is left aside here — living beside
     * it is the point, and there is a test of its own for that.
     */
    private function contributorErrors(Submission $submission): string
    {
        $context = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $errors = Repo::submission()->validateSubmit($submission, $context);
        $ours = array_filter(
            (array) ($errors['contributors'] ?? []),
            fn ($message) => str_contains(mb_strtoupper((string) $message), 'ORCID')
        );

        return implode(' | ', $ours);
    }

    /** Everything the core answers about the contributors, whoever wrote it. */
    private function allContributorErrors(Submission $submission): string
    {
        $context = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $errors = Repo::submission()->validateSubmit($submission, $context);

        return implode(' | ', (array) ($errors['contributors'] ?? []));
    }

    public function testASubmissionIsRefusedWhileAContributorHasNoId(): void
    {
        $this->requireOnSubmit(true);
        $submission = $this->submissionWithContributors([['Ana', self::ORCID], ['Bruno', null]]);

        $errors = $this->contributorErrors($submission);

        $this->assertStringContainsString('Bruno', $errors, 'the contributor without an iD has to be named: ' . $errors);
        $this->assertStringNotContainsString('Ana', $errors, 'the contributor who has one must not be named');
        $this->assertStringNotContainsString('##', $errors, 'the message has to be translated');
    }

    public function testTheSubmissionGoesThroughOnceEveryContributorHasOne(): void
    {
        $this->requireOnSubmit(true);
        $submission = $this->submissionWithContributors([['Ana', self::ORCID], ['Bruno', 'https://orcid.org/0000-0001-5109-3700']]);

        $this->assertStringNotContainsString(
            'orcid',
            strtolower($this->contributorErrors($submission)),
            'nothing may be held against a submission whose contributors all have an iD'
        );
    }

    public function testTheEditorKeepsTheAutonomyToCompleteIt(): void
    {
        $this->requireOnSubmit(true);
        $this->setFlag('editorsExempt', true);
        $submission = $this->submissionWithContributors([['Dora', null]]);

        $manager = Repo::user()->getCollector()->filterByContextIds([self::CONTEXT_ID])->filterByRoleIds([Role::ROLE_ID_MANAGER])->limit(1)->getMany()->first();
        if (!$manager) {
            $this->markTestSkipped('this context has no journal manager to act as');
        }
        // Somebody who writes submissions and does not run the journal: many
        // accounts hold both roles, and those are exempt.
        $author = Repo::user()->getCollector()->filterByContextIds([self::CONTEXT_ID])->filterByRoleIds([Role::ROLE_ID_AUTHOR])->getMany()
            ->first(fn ($user) => !$user->hasRole(OrcidManualEntryPlugin::EXEMPT_ROLES, self::CONTEXT_ID));

        if ($author) {
            Registry::set('user', $author);
            $this->assertStringContainsString('Dora', $this->contributorErrors($submission), 'an author is held to the rule');
        }

        Registry::set('user', $manager);
        $this->assertStringNotContainsString(
            'Dora',
            $this->contributorErrors($submission),
            'a journal manager completes the submission anyway'
        );

        // Unless the journal took that autonomy away.
        $this->setFlag('editorsExempt', false);
        $this->assertStringContainsString('Dora', $this->contributorErrors($submission));
    }

    public function testItLivesBesideWhatAnotherPluginHoldsAgainstTheSameSubmission(): void
    {
        $other = PluginRegistry::getPlugin('generic', 'requiredauthormetadataplugin');
        if (!$other || !$other->getEnabled(self::CONTEXT_ID)) {
            $this->markTestSkipped('the requiredAuthorMetadata plugin is not enabled here');
        }
        $wereRequired = [
            'requireAffiliation' => $other->getSetting(self::CONTEXT_ID, 'requireAffiliation'),
            'requireOnSubmit' => $other->getSetting(self::CONTEXT_ID, 'requireOnSubmit'),
            'editorsExempt' => $other->getSetting(self::CONTEXT_ID, 'editorsExempt'),
        ];
        $other->updateSetting(self::CONTEXT_ID, 'requireAffiliation', 1, 'bool');
        $other->updateSetting(self::CONTEXT_ID, 'requireOnSubmit', 1, 'bool');
        $other->updateSetting(self::CONTEXT_ID, 'editorsExempt', 0, 'bool');

        try {
            $this->requireOnSubmit(true);
            $this->setFlag('editorsExempt', false);
            $submission = $this->submissionWithContributors([['Elena', null]]);

            $everything = $this->allContributorErrors($submission);

            // Both reasons reach the author, not whichever ran last.
            $this->assertStringContainsString('ORCID', $everything, 'the iD is still asked for: ' . $everything);
            $this->assertMatchesRegularExpression('/afilia|affilia/i', $everything, 'and so is the affiliation: ' . $everything);
        } finally {
            foreach ($wereRequired as $name => $value) {
                $other->updateSetting(self::CONTEXT_ID, $name, $value === null ? 0 : $value, 'bool');
            }
        }
    }

    public function testNothingIsRequiredWhileTheSettingIsOff(): void
    {
        $this->requireOnSubmit(false);
        $submission = $this->submissionWithContributors([['Ana', null], ['Bruno', null]]);

        $errors = strtolower($this->contributorErrors($submission));

        $this->assertStringNotContainsString('orcid', $errors, 'the rule only applies where the journal asked for it: ' . $errors);
    }
}
