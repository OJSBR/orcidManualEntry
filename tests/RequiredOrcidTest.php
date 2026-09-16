<?php

/**
 * @file plugins/generic/orcidManualEntry/tests/RequiredOrcidTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredOrcidTest
 *
 * @brief The settings that ask for the iD and the three places where a journal
 *        can require it: registering, saving a contributor and completing a
 *        submission.
 */

namespace APP\plugins\generic\orcidManualEntry\tests;

use APP\core\Application;
use APP\core\PageRouter;
use APP\plugins\generic\orcidManualEntry\OrcidManualEntryPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\form\Form;
use PKP\tests\PKPTestCase;

#[CoversClass(OrcidManualEntryPlugin::class)]
class RequiredOrcidTest extends PKPTestCase
{
    /** A valid iD and its check digit, used throughout. */
    private const ORCID = 'https://orcid.org/0000-0002-1825-0097';

    protected function setUp(): void
    {
        parent::setUp();
        $request = Application::get()->getRequest();
        if (!$request->getRouter()) {
            $router = new PageRouter();
            $router->setApplication(Application::get());
            $request->setRouter($router);
        }
    }

    /**
     * A plugin with the given settings, never touching the database and always
     * acting (ORCID OAuth off, which is the only state it works in).
     */
    protected function plugin(array $settings = []): OrcidManualEntryPlugin
    {
        return new class ($settings) extends OrcidManualEntryPlugin {
            public function __construct(private array $settings)
            {
                parent::__construct();
            }

            public function getFlag(?int $contextId, string $name): bool
            {
                return array_key_exists($name, $this->settings)
                    ? (bool) $this->settings[$name]
                    : (self::DEFAULTS[$name] ?? false);
            }

            public function currentFlag(string $name): bool
            {
                return $this->getFlag(null, $name);
            }
        };
    }

    /**
     * A submission whose current publication has the given authors, each one
     * [name, orcid].
     */
    protected function submissionWithAuthors(array $authors): Submission
    {
        $publication = new Publication();
        $publication->setId(501);

        $submission = new class () extends Submission {
            public ?Publication $current = null;

            public function getCurrentPublication(): ?Publication
            {
                return $this->current;
            }
        };
        $submission->setId(77);
        $submission->current = $publication;

        return $submission;
    }

    public function testASettingThatWasNeverSavedFallsBackToItsDefault(): void
    {
        $defaults = OrcidManualEntryPlugin::DEFAULTS;

        // The field is offered where it always was, and nothing is required
        // until the journal asks for it.
        $this->assertTrue($defaults['showOnRegistration']);
        $this->assertFalse($defaults['requireOnRegistration']);
        $this->assertFalse($defaults['requireOnContributor']);
        $this->assertFalse($defaults['requireOnSubmit']);

        $plugin = $this->plugin(['requireOnSubmit' => true]);
        $this->assertTrue($plugin->currentFlag('requireOnSubmit'));
        $this->assertTrue($plugin->currentFlag('showOnRegistration'), 'a setting left alone keeps its default');
        $this->assertFalse($plugin->currentFlag('nonsense'), 'an unknown setting is never true');
    }

    public function testRegisteringAsksForTheIdOnlyWhereTheJournalRequiresIt(): void
    {
        // The hook tells the pages apart by type, so a subclass is still the
        // registration form.
        $registration = new class () extends \PKP\user\form\RegistrationForm {
            public array $checks = [];

            public function __construct()
            {
            }

            public function addCheck($check)
            {
                $this->checks[] = $check;
            }
        };

        $this->plugin(['requireOnRegistration' => false])->addUserOrcidCheck('registrationform::Constructor', [$registration]);
        $optional = count($registration->checks);
        $this->assertGreaterThan(0, $optional, 'the format is always checked');

        $registration->checks = [];
        $this->plugin(['requireOnRegistration' => true])->addUserOrcidCheck('registrationform::Constructor', [$registration]);
        $this->assertSame($optional + 1, count($registration->checks), 'requiring the iD adds a check of its own');

        // With the field kept off the registration page there is nothing to require.
        $registration->checks = [];
        $this->plugin(['showOnRegistration' => false, 'requireOnRegistration' => true])->addUserOrcidCheck('registrationform::Constructor', [$registration]);
        $this->assertSame([], $registration->checks);
    }

    public function testSavingAContributorWithoutAnIdIsRefusedOnlyWhereItIsRequired(): void
    {
        $errors = [];
        $this->plugin(['requireOnContributor' => false])->allowManualOrcid('Author::validate', [&$errors, null, ['orcid' => '']]);
        $this->assertSame([], $errors, 'an empty iD is accepted while it is optional');

        $errors = [];
        $this->plugin(['requireOnContributor' => true])->allowManualOrcid('Author::validate', [&$errors, null, ['orcid' => '   ']]);
        $this->assertArrayHasKey('orcid', $errors, 'an empty iD is refused while it is required');

        // A valid iD passes in both cases.
        $errors = [];
        $this->plugin(['requireOnContributor' => true])->allowManualOrcid('Author::validate', [&$errors, null, ['orcid' => self::ORCID]]);
        $this->assertSame([], $errors);

        // And what is not an iD is still refused for what it is.
        $errors = [];
        $this->plugin(['requireOnContributor' => true])->allowManualOrcid('Author::validate', [&$errors, null, ['orcid' => '0000-0000-0000-0000']]);
        $this->assertArrayHasKey('orcid', $errors);
    }

    public function testAContributorSavedWithoutSendingTheFieldIsRefusedWhenItIsRequired(): void
    {
        $author = new \APP\author\Author();
        $author->setData('orcid', null);

        // The contributor endpoint drops the iD from the parameters: a save that
        // carries no 'orcid' key must still be refused when the author has none.
        $errors = [];
        $this->plugin(['requireOnContributor' => true])->allowManualOrcid('Author::validate', [&$errors, $author, ['givenName' => ['en' => 'Ana']]]);
        $this->assertArrayHasKey('orcid', $errors);

        // An author who already has one is left alone.
        $author->setData('orcid', self::ORCID);
        $errors = [];
        $this->plugin(['requireOnContributor' => true])->allowManualOrcid('Author::validate', [&$errors, $author, ['givenName' => ['en' => 'Ana']]]);
        $this->assertSame([], $errors);
    }

    public function testTheSubmitValidationOnlyRunsWhereTheJournalRequiresIt(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/OrcidManualEntryPlugin.php');

        // The rule is hung on the core's own validation of the last step, so the
        // wizard shows it where it shows its own errors.
        $this->assertStringContainsString("Hook::add('Submission::validateSubmit'", $source);
        // The rule is read from the context the hook hands over, not from the
        // journal of whatever request happens to be running.
        $this->assertStringContainsString("\$context = \$args[2] ??", $source);
        $this->assertStringContainsString("!\$this->getFlag(\$context?->getId(), 'requireOnSubmit')", $source);
        // Nothing is checked while ORCID OAuth is configured: the core owns the
        // field then.
        $this->assertStringContainsString('OrcidManager::isEnabled($context) || !$this->getFlag(', $source);

        // Without a publication there is nothing to check and nothing to break.
        $errors = [];
        $submission = $this->submissionWithAuthors([]);
        $submission->current = null;
        $this->plugin(['requireOnSubmit' => true])->validateSubmit('Submission::validateSubmit', [&$errors, $submission, null]);
        $this->assertSame([], $errors);
    }

    public function testTheErrorNamesEveryContributorWhoHasNoId(): void
    {
        // The command line has no locale loaded, so the message itself is read
        // from the file the journal will see.
        $english = (string) file_get_contents(dirname(__DIR__) . '/locale/en/locale.po');
        $this->assertStringContainsString('plugins.generic.orcidManualEntry.error.requiredOnSubmit', $english);
        $this->assertMatchesRegularExpression(
            '/error\.requiredOnSubmit"\nmsgstr "[^"]*\{\$names\}/',
            $english,
            'the message has to name who is missing an iD'
        );

        // And the plugin fills that placeholder with the contributors it found.
        $source = (string) file_get_contents(dirname(__DIR__) . '/OrcidManualEntryPlugin.php');
        $this->assertStringContainsString("'names' => implode(', ', \$missing)", $source);
        $this->assertStringContainsString("\$author->getFullName(false)", $source);
    }
}
