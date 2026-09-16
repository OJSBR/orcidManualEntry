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
        PluginRegistry::loadCategory('generic', true, self::CONTEXT_ID);
        /** @var ?OrcidManualEntryPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'orcidmanualentryplugin');
        if (!$plugin) {
            $this->markTestSkipped('the plugin is not installed here');
        }
        if (OrcidManager::isEnabled(Application::getContextDAO()->getById(self::CONTEXT_ID))) {
            $this->markTestSkipped('ORCID OAuth is configured: the core owns the field and the plugin stays inert');
        }
        if (!$plugin->getEnabled(self::CONTEXT_ID)) {
            $this->remember('enabled');
            $plugin->updateSetting(self::CONTEXT_ID, 'enabled', 1, 'bool');
            // Only now does register() attach the hooks.
            $plugin = new OrcidManualEntryPlugin();
            $plugin->register('generic', 'plugins/generic/orcidManualEntry', self::CONTEXT_ID);
        }
        return $plugin;
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
        $this->remember('requireOnSubmit');
        $this->plugin->updateSetting(self::CONTEXT_ID, 'requireOnSubmit', $required ? 1 : 0, 'bool');
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

    /** What the core answers the wizard, as one string. */
    private function contributorErrors(Submission $submission): string
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

    public function testNothingIsRequiredWhileTheSettingIsOff(): void
    {
        $this->requireOnSubmit(false);
        $submission = $this->submissionWithContributors([['Ana', null], ['Bruno', null]]);

        $errors = strtolower($this->contributorErrors($submission));

        $this->assertStringNotContainsString('orcid', $errors, 'the rule only applies where the journal asked for it: ' . $errors);
    }
}
