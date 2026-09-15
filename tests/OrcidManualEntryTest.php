<?php

/**
 * @file plugins/generic/orcidManualEntry/tests/OrcidManualEntryTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OrcidManualEntryTest
 *
 * @brief ORCID normalization and validation, and the field added to the user forms.
 */

namespace APP\plugins\generic\orcidManualEntry\tests;

use APP\plugins\generic\orcidManualEntry\OrcidManualEntryPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\form\Form;
use PKP\tests\PKPTestCase;

#[CoversClass(OrcidManualEntryPlugin::class)]
class OrcidManualEntryTest extends PKPTestCase
{
    /**
     * Outside a web request there is no router, and the core validator and the
     * translations ask the request for its context. A page router without a
     * journal answers "no context", as the site does.
     */
    protected function withRouter(): void
    {
        $request = \APP\core\Application::get()->getRequest();
        if (!$request->getRouter()) {
            $router = new \APP\core\PageRouter();
            $router->setApplication(\APP\core\Application::get());
            $request->setRouter($router);
        }
    }

    public function testAnOrcidIsNormalizedToItsCanonicalUrl(): void
    {
        $this->assertSame('https://orcid.org/0000-0002-1825-0097', OrcidManualEntryPlugin::normalizeOrcid('0000-0002-1825-0097'));
        $this->assertSame('https://orcid.org/0000-0002-1825-0097', OrcidManualEntryPlugin::normalizeOrcid(' http://www.orcid.org/0000-0002-1825-0097/ '));
        $this->assertSame('https://orcid.org/0000-0001-5109-370X', OrcidManualEntryPlugin::normalizeOrcid('orcid.org/0000-0001-5109-370x'));
        $this->assertSame('https://sandbox.orcid.org/0000-0002-1825-0097', OrcidManualEntryPlugin::normalizeOrcid('https://sandbox.orcid.org/0000-0002-1825-0097'));
        $this->assertSame('', OrcidManualEntryPlugin::normalizeOrcid('   '));
        $this->assertSame('not an orcid', OrcidManualEntryPlugin::normalizeOrcid('not an orcid'), 'Unrecognized input is kept so that validation refuses it.');
    }

    public function testDuplicatesAreComparedByIdNotByUrl(): void
    {
        $this->assertSame('0000-0002-1825-0097', OrcidManualEntryPlugin::orcidId('https://sandbox.orcid.org/0000-0002-1825-0097'));
        $this->assertSame(OrcidManualEntryPlugin::orcidId('0000-0001-5109-370x'), OrcidManualEntryPlugin::orcidId('https://orcid.org/0000-0001-5109-370X'));
        $this->assertSame('', OrcidManualEntryPlugin::orcidId(''));
    }

    public function testTheFieldGoesAtTheTopOfTheRegistrationForm(): void
    {
        $page = '<div class="page"><form class="cmp_form register" id="register" method="post"><input type="hidden" name="csrfToken" value="x"></form></div>';
        $output = OrcidManualEntryPlugin::insertUserOrcidField($page, 'register', '<fieldset class="orcidManualEntry"></fieldset>');

        $this->assertStringContainsString('id="register" method="post"><fieldset class="orcidManualEntry"></fieldset><input type="hidden" name="csrfToken"', $output);
    }

    public function testTheFieldGoesAfterTheLastProfileFieldBeforeThePrivacyNote(): void
    {
        $form = '<form class="pkp_form" id="identityForm"><div class="section"><input id="preferredAvatarInitials-1"></div>'
            . '<p>privacy</p><p><span class="formRequired">*</span></p><div class="buttons"></div></form>';
        $output = OrcidManualEntryPlugin::insertUserOrcidField($form, 'identityForm', '<div class="orcidManualEntry"></div>');

        $this->assertStringContainsString('<input id="preferredAvatarInitials-1"></div><div class="orcidManualEntry"></div><p>privacy</p>', $output);
    }

    public function testOtherOutputAndAFormThatAlreadyHasTheFieldAreLeftAlone(): void
    {
        // The filter sees everything rendered after it is registered in the request.
        $block = '<div class="pkp_block">sidebar</div>';
        $this->assertSame($block, OrcidManualEntryPlugin::insertUserOrcidField($block, 'register', '<b>field</b>'));

        $withField = '<form id="register"><input type="text" name="orcid"></form>';
        $this->assertSame($withField, OrcidManualEntryPlugin::insertUserOrcidField($withField, 'register', '<b>field</b>'));
    }

    public function testTheRenderedFieldEscapesTheSubmittedValue(): void
    {
        $this->withRouter();
        $form = new class () extends Form {
            public function __construct()
            {
                // No template and no checks: only the data and the errors are used.
                $this->_data = [];
                $this->_errors = [];
            }
        };
        $form->setData('orcid', '"><script>alert(1)</script>');

        foreach ([true, false] as $frontend) {
            $html = OrcidManualEntryPlugin::renderUserOrcidField($form, $frontend);
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
            $this->assertStringContainsString('name="orcid"', $html);
            $this->assertStringContainsString('aria-describedby="orcidManualEntryDescription"', $html);
        }
    }

    public function testNoCoreTemplateIsReplacedAndNoOrcidReachesTheLog(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/OrcidManualEntryPlugin.php');
        $this->assertStringNotContainsString("Hook::add('TemplateResource::getFilename'", $source);
        $this->assertFalse(is_dir(dirname(__DIR__) . '/templates'), 'The plugin ships no copy of a core template.');

        foreach (explode("\n", $source) as $number => $line) {
            if (str_contains($line, 'error_log(')) {
                $next = implode("\n", array_slice(explode("\n", $source), $number, 4));
                $this->assertStringNotContainsString('getOrcid()', $next, 'Line ' . ($number + 1) . ' logs an ORCID.');
                $this->assertStringNotContainsString('$currentOrcid', $next, 'Line ' . ($number + 1) . ' logs an ORCID.');
            }
        }
    }

    public function testTheOutputFilterIsNamedSoOtherPluginsDoNotReplaceIt(): void
    {
        // Smarty names every closure filter "closure": an unnamed one and another plugin's replace each other.
        $source = (string) file_get_contents(dirname(__DIR__) . '/OrcidManualEntryPlugin.php');
        $filters = substr_count($source, "registerFilter('output'");
        $this->assertGreaterThan(0, $filters);
        $this->assertSame($filters, preg_match_all("/\\}, 'orcidManualEntry\\w+'\\);/", $source));
    }
}
