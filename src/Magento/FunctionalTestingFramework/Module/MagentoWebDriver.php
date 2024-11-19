<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\FunctionalTestingFramework\Module;

use Codeception\Lib\Actor\Shared\Pause;
use Codeception\Module\WebDriver;
use Codeception\Test\Descriptor;
use Codeception\TestInterface;
use Magento\FunctionalTestingFramework\Allure\AllureHelper;
use Facebook\WebDriver\Interactions\WebDriverActions;
use Codeception\Exception\ModuleConfigException;
use Codeception\Exception\ModuleException;
use Codeception\Util\Uri;
use Codeception\Lib\ModuleContainer;
use Magento\FunctionalTestingFramework\DataTransport\WebApiExecutor;
use Magento\FunctionalTestingFramework\DataTransport\Auth\WebApiAuth;
use Magento\FunctionalTestingFramework\DataTransport\Auth\Tfa\OTP;
use Magento\FunctionalTestingFramework\DataTransport\Protocol\CurlInterface;
use Magento\FunctionalTestingFramework\DataGenerator\Handlers\CredentialStore;
use Magento\FunctionalTestingFramework\Module\Util\ModuleUtils;
use Magento\FunctionalTestingFramework\Util\Path\UrlFormatter;
use Magento\FunctionalTestingFramework\Util\ConfigSanitizerUtil;
use Yandex\Allure\Adapter\AllureException;
use Magento\FunctionalTestingFramework\DataTransport\Protocol\CurlTransport;
use Yandex\Allure\Adapter\Support\AttachmentSupport;
use Magento\FunctionalTestingFramework\Exceptions\TestFrameworkException;
use Magento\FunctionalTestingFramework\DataGenerator\Handlers\PersistedObjectHandler;

/**
 * MagentoWebDriver module provides common Magento web actions through Selenium WebDriver.
 *
 * Configuration:
 *
 * ```
 * modules:
 *     enabled:
 *         - \Magento\FunctionalTestingFramework\Module\MagentoWebDriver
 *     config:
 *         \Magento\FunctionalTestingFramework\Module\MagentoWebDriver:
 *             url: magento_base_url
 *             backend_name: magento_backend_name
 *             username: admin_username
 *             password: admin_password
 *             browser: chrome
 * ```
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class MagentoWebDriver extends WebDriver
{
    use AttachmentSupport;
    use Pause {
        pause as codeceptPause;
    }

    const MAGENTO_CRON_INTERVAL = 60;
    const MAGENTO_CRON_COMMAND = 'cron:run';

    /**
     * List of known magento loading masks by selector
     *
     * @var array
     */
    protected $loadingMasksLocators = [
        '//div[contains(@class, "loading-mask")]',
        '//div[contains(@class, "admin_data-grid-loading-mask")]',
        '//div[contains(@class, "admin__data-grid-loading-mask")]',
        '//div[contains(@class, "admin__form-loading-mask")]',
        '//div[@data-role="spinner"]',
        '//div[contains(@class,"file-uploader-spinner")]',
        '//div[contains(@class,"image-uploader-spinner")]',
        '//div[contains(@class,"uploader")]//div[@class="file-row"]',
    ];

    /**
     * The module required fields, to be set in the suite .yml configuration file.
     *
     * @var array
     */
    protected $requiredFields = [
        'url',
        'backend_name',
        'username',
        'password',
        'browser',
    ];

    /**
     * Set all Locale variables to NULL.
     *
     * @var array $localeAll
     */
    protected static $localeAll = [
        LC_COLLATE => null,
        LC_CTYPE => null,
        LC_MONETARY => null,
        LC_NUMERIC => null,
        LC_TIME => null,
        LC_MESSAGES => null,
    ];

    /**
     * Current Test Interface
     *
     * @var TestInterface
     */
    private $current_test;

    /**
     * Png image filepath for current test
     *
     * @var string
     */
    private $pngReport;

    /**
     * Html filepath for current test
     *
     * @var string
     */
    private $htmlReport;

    /**
     * Array to store Javascript errors
     *
     * @var string[]
     */
    private $jsErrors = [];

    /**
     * Contains last execution times for Cron
     *
     * @var int[]
     */
    private $cronExecution = [];

    /**
     * Sanitizes config, then initializes using parent.
     *
     * @return void
     */
    public function _initialize()
    {
        $this->config = ConfigSanitizerUtil::sanitizeWebDriverConfig($this->config);
        parent::_initialize();
        $this->cleanJsError();
    }

    /**
     * Calls parent reset, then re-sanitizes config
     *
     * @return void
     */
    public function _resetConfig()
    {
        parent::_resetConfig();
        $this->config = ConfigSanitizerUtil::sanitizeWebDriverConfig($this->config);
        $this->cleanJsError();
    }

    /**
     * Remap parent::_after, called in TestContextExtension
     *
     * @param TestInterface $test
     * @return void
     */
    public function _runAfter(TestInterface $test)
    {
        parent::_after($test); // TODO: Change the autogenerated stub
    }

    /**
     * Override parent::_after to do nothing.
     *
     * @param TestInterface $test
     * @SuppressWarnings(PHPMD)
     * @return void
     */
    public function _after(TestInterface $test)
    {
        // DO NOT RESET SESSIONS
    }

    /**
     * Return ModuleContainer
     *
     * @return ModuleContainer
     */
    public function getModuleContainer()
    {
        return $this->moduleContainer;
    }

    /**
     * Returns URL of a host.
     *
     * @return mixed
     * @throws ModuleConfigException
     * @api
     */
    public function _getUrl()
    {
        if (!isset($this->config['url'])) {
            throw new ModuleConfigException(
                __CLASS__,
                "Module connection failure. The URL for client can't bre retrieved"
            );
        }

        return $this->config['url'];
    }

    /**
     * Uri of currently opened page.
     *
     * @return string
     * @throws ModuleException
     * @api
     */
    public function _getCurrentUri()
    {
        $url = $this->webDriver->getCurrentURL();
        if ($url === 'about:blank') {
            throw new ModuleException($this, 'Current url is blank, no page was opened');
        }

        return Uri::retrieveUri($url);
    }

    /**
     * Assert that the current webdriver url does not equal the expected string.
     *
     * @param string $url
     * @return void
     * @throws AllureException
     */
    public function dontSeeCurrentUrlEquals($url)
    {
        $actualUrl = $this->webDriver->getCurrentURL();
        $comparison = "Expected: $url\nActual: $actualUrl";
        AllureHelper::addAttachmentToCurrentStep($comparison, 'Comparison');
        $this->assertNotEquals($url, $actualUrl);
    }

    /**
     * Assert that the current webdriver url does not match the expected regex.
     *
     * @param string $regex
     * @return void
     * @throws AllureException
     */
    public function dontSeeCurrentUrlMatches($regex)
    {
        $actualUrl = $this->webDriver->getCurrentURL();
        $comparison = "Expected: $regex\nActual: $actualUrl";
        AllureHelper::addAttachmentToCurrentStep($comparison, 'Comparison');
        $this->assertNotRegExp($regex, $actualUrl);
    }

    /**
     * Assert that the current webdriver url does not contain the expected string.
     *
     * @param string $needle
     * @return void
     * @throws AllureException
     */
    public function dontSeeInCurrentUrl($needle)
    {
        $actualUrl = $this->webDriver->getCurrentURL();
        $comparison = "Expected: $needle\nActual: $actualUrl";
        AllureHelper::addAttachmentToCurrentStep($comparison, 'Comparison');
        $this->assertStringNotContainsString($needle, $actualUrl);
    }

    /**
     * Return the current webdriver url or return the first matching capture group.
     *
     * @param string|null $regex
     * @return string
     */
    public function grabFromCurrentUrl($regex = null)
    {
        $fullUrl = $this->webDriver->getCurrentURL();
        if (!$regex) {
            return $fullUrl;
        }
        $matches = [];
        $res = preg_match($regex, $fullUrl, $matches);
        if (!$res) {
            $this->fail("Couldn't match $regex in " . $fullUrl);
        }
        if (!isset($matches[1])) {
            $this->fail("Nothing to grab. A regex parameter with a capture group is required. Ex: '/(foo)(bar)/'");
        }

        return $matches[1];
    }

    /**
     * Assert that the current webdriver url equals the expected string.
     *
     * @param string $url
     * @return void
     * @throws AllureException
     */
    public function seeCurrentUrlEquals($url)
    {
        $actualUrl = $this->webDriver->getCurrentURL();
        $comparison = "Expected: $url\nActual: $actualUrl";
        AllureHelper::addAttachmentToCurrentStep($comparison, 'Comparison');
        $this->assertEquals($url, $actualUrl);
    }

    /**
     * Assert that the current webdriver url matches the expected regex.
     *
     * @param string $regex
     * @return void
     * @throws AllureException
     */
    public function seeCurrentUrlMatches($regex)
    {
        $actualUrl = $this->webDriver->getCurrentURL();
        $comparison = "Expected: $regex\nActual: $actualUrl";
        AllureHelper::addAttachmentToCurrentStep($comparison, 'Comparison');
        $this->assertRegExp($regex, $actualUrl);
    }

    /**
     * Assert that the current webdriver url contains the expected string.
     *
     * @param string $needle
     * @return void
     * @throws AllureException
     */
    public function seeInCurrentUrl($needle)
    {
        $actualUrl = $this->webDriver->getCurrentURL();
        $comparison = "Expected: $needle\nActual: $actualUrl";
        AllureHelper::addAttachmentToCurrentStep($comparison, 'Comparison');
        $this->assertStringContainsString(urldecode($needle), urldecode($actualUrl));
    }

    /**
     * Close admin notification popup windows.
     *
     * @return void
     */
    public function closeAdminNotification()
    {
        // Cheating here for the minute. Still working on the best method to deal with this issue.
        try {
            $this->executeJS("jQuery('.modal-popup').remove(); jQuery('.modals-overlay').remove();");
        } catch (\Exception $e) {
        }
    }

    /**
     * Search for and Select multiple options from a Magento Multi-Select drop down menu.
     * e.g. The drop down menu you use to assign Products to Categories.
     *
     * @param string  $select
     * @param array   $options
     * @param boolean $requireAction
     * @return void
     * @throws \Exception
     */
    public function searchAndMultiSelectOption($select, array $options, $requireAction = false)
    {
        $selectDropdown = $select . ' .action-select.admin__action-multiselect';
        $selectSearchText = $select
            . ' .admin__action-multiselect-search-wrap>input[data-role="advanced-select-text"]';
        $selectSearchResult = $select . ' .admin__action-multiselect-label>span';

        $this->waitForPageLoad();
        $this->waitForElementVisible($selectDropdown);
        $this->click($selectDropdown);

        $this->selectMultipleOptions($selectSearchText, $selectSearchResult, $options);

        if ($requireAction) {
            $selectAction = $select . ' button[class=action-default]';
            $this->waitForPageLoad();
            $this->click($selectAction);
        }
    }

    /**
     * Select multiple options from a drop down using a filter and text field to narrow results.
     *
     * @param string   $selectSearchTextField
     * @param string   $selectSearchResult
     * @param string[] $options
     * @return void
     * @throws \Exception
     */
    public function selectMultipleOptions($selectSearchTextField, $selectSearchResult, array $options)
    {
        foreach ($options as $option) {
            $this->waitForPageLoad();
            $this->fillField($selectSearchTextField, '');
            $this->waitForPageLoad();
            $this->fillField($selectSearchTextField, $option);
            $this->waitForPageLoad();
            $this->click($selectSearchResult);
        }
    }

    /**
     * Wait for all Ajax calls to finish.
     *
     * @param integer $timeout
     * @return void
     */
    public function waitForAjaxLoad($timeout = null)
    {
        $timeout = $timeout ?? $this->_getConfig()['pageload_timeout'];

        try {
            $this->waitForJS('return !!window.jQuery && window.jQuery.active == 0;', $timeout);
        } catch (\Exception $exceptione) {
            $this->debug("js never executed, performing {$timeout} second wait.");
            $this->wait($timeout);
        }
        $this->wait(1);
    }

    /**
     * Wait for all JavaScript to finish executing.
     *
     * @param integer $timeout
     * @return void
     * @throws \Exception
     */
    public function waitForPageLoad($timeout = null)
    {
        $timeout = $timeout ?? $this->_getConfig()['pageload_timeout'];

        $this->waitForJS('return document.readyState == "complete"', $timeout);
        $this->waitForAjaxLoad($timeout);
        $this->waitForLoadingMaskToDisappear($timeout);
    }

    /**
     * Wait for all visible loading masks to disappear. Gets all elements by mask selector, then loops over them.
     *
     * @param integer $timeout
     * @return void
     * @throws \Exception
     */
    public function waitForLoadingMaskToDisappear($timeout = null)
    {
        $timeout = $timeout ?? $this->_getConfig()['pageload_timeout'];
        
        foreach ($this->loadingMasksLocators as $maskLocator) {
            // Get count of elements found for looping.
            // Elements are NOT useful for interaction, as they cannot be fed to codeception actions.
            $loadingMaskElements = $this->_findElements($maskLocator);
            for ($i = 1; $i <= count($loadingMaskElements); $i++) {
                // Formatting and looping on i as we can't interact elements returned above
                // eg.  (//div[@data-role="spinner"])[1]
                $this->waitForElementNotVisible("({$maskLocator})[{$i}]", $timeout);
            }
        }
    }

    /**
     * Format input to specified currency in locale specified
     * @link https://php.net/manual/en/numberformatter.formatcurrency.php
     *
     * @param float  $value
     * @param string $locale
     * @param string $currency
     * @return string
     * @throws TestFrameworkException
     */
    public function formatCurrency(float $value, $locale, $currency)
    {
        $formatter = \NumberFormatter::create($locale, \NumberFormatter::CURRENCY);
        if ($formatter && !empty($formatter)) {
            $result = $formatter->formatCurrency($value, $currency);
            if ($result) {
                return $result;
            }
        }

        throw new TestFrameworkException('Invalid attributes used in formatCurrency.');
    }

    /**
     * Parse float number with thousands_sep.
     *
     * @param string $floatString
     * @return float
     */
    public function parseFloat($floatString)
    {
        $floatString = str_replace(',', '', $floatString);

        return floatval($floatString);
    }

    /**
     * @param integer $category
     * @param string  $locale
     * @return void
     */
    public function mSetLocale(int $category, $locale)
    {
        if (self::$localeAll[$category] === $locale) {
            return;
        }
        foreach (self::$localeAll as $c => $l) {
            self::$localeAll[$c] = setlocale($c, 0);
        }
        setlocale($category, $locale);
    }

    /**
     * Reset Locale setting.
     *
     * @return void
     */
    public function mResetLocale()
    {
        foreach (self::$localeAll as $c => $l) {
            if ($l !== null) {
                setlocale($c, $l);
                self::$localeAll[$c] = null;
            }
        }
    }

    /**
     * Scroll to the Top of the Page.
     *
     * @return void
     */
    public function scrollToTopOfPage()
    {
        $this->executeJS('window.scrollTo(0,0);');
    }

    /**
     * Takes given $command and executes it against bin/magento or custom exposed entrypoint. Returns command output.
     *
     * @param string  $command
     * @param integer $timeout
     * @param string  $arguments
     * @return string
     *
     * @throws TestFrameworkException
     */
    public function magentoCLI($command, $timeout = null, $arguments = null)
    {
        // Remove index.php if it's present in url
        $baseUrl = rtrim(
            str_replace('index.php', '', rtrim($this->config['url'], '/')),
            '/'
        );

        $apiURL = UrlFormatter::format(
            $baseUrl . '/' . ltrim(getenv('MAGENTO_CLI_COMMAND_PATH'), '/'),
            false
        );

        $executor = new CurlTransport();
        echo "api url".$apiURL;
        echo "token ".WebApiAuth::getAdminToken();
        echo "arguments" .$arguments;
        $executor->write(
            $apiURL,
            [
                'token' => WebApiAuth::getAdminToken(),
                getenv('MAGENTO_CLI_COMMAND_PARAMETER') => urlencode($command),
                'arguments' => $arguments,
                'timeout'   => $timeout,
            ],
            CurlInterface::POST,
            []
        );
        $response = $executor->read();
        $executor->close();

        $util = new ModuleUtils();
        $response = trim($util->utf8SafeControlCharacterTrim($response));
        return $response != "" ? $response : "CLI did not return output.";
    }

    /**
     * Executes Magento Cron keeping the interval (> 60 seconds between each run)
     *
     * @param string|null  $cronGroups
     * @param integer|null $timeout
     * @param string|null  $arguments
     * @return string
     */
    public function magentoCron($cronGroups = null, $timeout = null, $arguments = null)
    {
        $cronGroups = explode(' ', $cronGroups);
        return $this->executeCronjobs($cronGroups, $timeout, $arguments);
    }

    /**
     * Updates last execution time for Cron
     *
     * @param array $cronGroups
     * @return void
     */
    private function notifyCronFinished(array $cronGroups = [])
    {
        if (empty($cronGroups)) {
            $this->cronExecution['*'] = time();
        }

        foreach ($cronGroups as $group) {
            $this->cronExecution[$group] = time();
        }
    }

    /**
     * Returns last Cron execution time for specific cron or all crons
     *
     * @param array $cronGroups
     * @return integer
     */
    private function getLastCronExecution(array $cronGroups = [])
    {
        if (empty($this->cronExecution)) {
            return 0;
        }

        if (empty($cronGroups)) {
            return (int)max($this->cronExecution);
        }

        $cronGroups = array_merge($cronGroups, ['*']);

        return array_reduce($cronGroups, function ($lastExecution, $group) {
            if (isset($this->cronExecution[$group]) && $this->cronExecution[$group] > $lastExecution) {
                $lastExecution = $this->cronExecution[$group];
            }

            return (int)$lastExecution;
        }, 0);
    }

    /**
     * Returns time to wait for next run
     *
     * @param array   $cronGroups
     * @param integer $cronInterval
     * @return integer
     */
    private function getCronWait(array $cronGroups = [], int $cronInterval = self::MAGENTO_CRON_INTERVAL)
    {
        $nextRun = $this->getLastCronExecution($cronGroups) + $cronInterval;
        $toNextRun = $nextRun - time();

        return max(0, $toNextRun);
    }

    /**
     * Runs DELETE request to delete a Magento entity against the url given.
     *
     * @param string $url
     * @return string
     * @throws TestFrameworkException
     */
    public function deleteEntityByUrl($url)
    {
        $executor = new WebApiExecutor(null);
        $executor->write($url, [], CurlInterface::DELETE, []);
        $response = $executor->read();
        $executor->close();

        return $response;
    }

    /**
     * Conditional click for an area that should be visible
     *
     * @param string  $selector
     * @param string  $dependentSelector
     * @param boolean $visible
     * @return void
     * @throws \Exception
     */
    public function conditionalClick($selector, $dependentSelector, $visible)
    {
        $el = $this->_findElements($dependentSelector);
        if (sizeof($el) > 1) {
            throw new \Exception("more than one element matches selector " . $dependentSelector);
        }

        $clickCondition = null;
        if ($visible) {
            $clickCondition = !empty($el) && $el[0]->isDisplayed();
        } else {
            $clickCondition = empty($el) || !$el[0]->isDisplayed();
        }

        if ($clickCondition) {
            $this->click($selector);
        }
    }

    /**
     * Clear the given Text Field or Textarea
     *
     * @param string $selector
     * @return void
     */
    public function clearField($selector)
    {
        $this->fillField($selector, "");
    }

    /**
     * Assert that an element contains a given value for the specific attribute.
     *
     * @param string $selector
     * @param string $attribute
     * @param string $value
     * @return void
     */
    public function assertElementContainsAttribute($selector, $attribute, $value)
    {
        $attributes = $this->grabAttributeFrom($selector, $attribute);

        if (isset($value) && empty($value)) {
            // If an "attribute" is blank, "", or null we need to be able to assert that it's present.
            // When an "attribute" is blank or null it returns "true" so we assert that "true" is present.
            $this->assertEquals($attributes, 'true');
        } else {
            $this->assertStringContainsString($value, $attributes);
        }
    }

    /**
     * Sets current test to the given test, and resets test failure artifacts to null
     *
     * @param TestInterface $test
     * @return void
     */
    public function _before(TestInterface $test)
    {
        $this->current_test = $test;
        $this->htmlReport = null;
        $this->pngReport = null;

        parent::_before($test);
    }

    /**
     * Override for codeception's default dragAndDrop to include offset options.
     *
     * @param string  $source
     * @param string  $target
     * @param integer $xOffset
     * @param integer $yOffset
     * @return void
     */
    public function dragAndDrop($source, $target, $xOffset = null, $yOffset = null)
    {
        $snodes = $this->matchFirstOrFail($this->baseElement, $source);
        $tnodes = $this->matchFirstOrFail($this->baseElement, $target);
        $action = new WebDriverActions($this->webDriver);
        if ($xOffset !== null || $yOffset !== null) {
            $targetX = intval($tnodes->getLocation()->getX() + $xOffset);
            $targetY = intval($tnodes->getLocation()->getY() + $yOffset);
            $travelX = intval($targetX - $snodes->getLocation()->getX());
            $travelY = intval($targetY - $snodes->getLocation()->getY());
            $action->moveToElement($snodes);
            $action->clickAndHold($snodes);
            // Fix Start
            $action->moveByOffset(-1, -1);
            $action->moveByOffset(1, 1);
            // Fix End
            $action->moveByOffset($travelX, $travelY);
            $action->release()->perform();
        } else {
            $action->clickAndHold($snodes);
            // Fix Start
            $action->moveByOffset(-1, -1);
            $action->moveByOffset(1, 1);
            // Fix End
            $action->moveToElement($tnodes);
            $action->release($tnodes)->perform();
        }
    }
    
    /**
     * Simple rapid click as per given count number.
     *
     * @param string $selector
     * @param string $count
     * @return void
     * @throws \Exception
     */
    public function rapidClick($selector, $count)
    {
        for ($i = 0; $i < $count; $i++) {
            $this->click($selector);
        }
    }

  /**
   * Grabs a cookie attributes value.
   * You can set additional cookie params like `domain`, `path` in array passed as last argument.
   * If the cookie is set by an ajax request (XMLHttpRequest),
   * there might be some delay caused by the browser, so try `$I->wait(0.1)`.
   * @param  string $cookie
   * @param array  $params
   * @return mixed
   */
    public function grabCookieAttributes(string $cookie, array $params = []): array
    {
        $params['name'] = $cookie;
        $cookieArrays = $this->filterCookies($this->webDriver->manage()->getCookies(), $params);
        $cookieAttributes = [];
        if (is_array($cookieArrays)) { // Microsoft Edge returns null if there are no cookies...
            foreach ($cookieArrays as $cookieArray) {
                if ($cookieArray->getName() === $cookie) {
                    $cookieAttributes['name'] = $cookieArray->getValue();
                    $cookieAttributes['path'] = $cookieArray->getPath();
                    $cookieAttributes['domain'] = $cookieArray->getDomain();
                    $cookieAttributes['secure'] = $cookieArray->isSecure();
                    $cookieAttributes['httpOnly'] = $cookieArray->isHttpOnly();
                    $cookieAttributes['sameSite'] = $cookieArray->getSameSite();
                    $cookieAttributes['expiry']  = date('d/m/Y', $cookieArray->getExpiry());

                    return $cookieAttributes;
                }
            }
        }

        return $cookieAttributes;
    }

    /**
     * Function used to fill sensitive credentials with user data, data is decrypted immediately prior to fill to avoid
     * exposure in console or log.
     *
     * @param string $field
     * @param string $value
     * @return void
     * @throws TestFrameworkException
     */
    public function fillSecretField($field, $value)
    {
        // to protect any secrets from being printed to console the values are executed only at the webdriver level as a
        // decrypted value

        $decryptedValue = CredentialStore::getInstance()->decryptSecretValue($value);
        if ($decryptedValue === false) {
            throw new TestFrameworkException("\nFailed to decrypt value {$value} for field {$field}\n");
        }
        $this->fillField($field, $decryptedValue);
    }

    /**
     * Function used to create data that contains sensitive credentials in a <createData> <field> override.
     * The data is decrypted immediately prior to data creation to avoid exposure in console or log.
     *
     * @param string $command
     * @param null   $timeout
     * @param null   $arguments
     * @throws TestFrameworkException
     * @return string
     */
    public function magentoCLISecret($command, $timeout = null, $arguments = null)
    {
        // to protect any secrets from being printed to console the values are executed only at the webdriver level as a
        // decrypted value

        $decryptedCommand = CredentialStore::getInstance()->decryptAllSecretsInString($command);
        if ($decryptedCommand === false) {
            throw new TestFrameworkException("\nFailed to decrypt magentoCLI command {$command}\n");
        }
        return $this->magentoCLI($decryptedCommand, $timeout, $arguments);
    }

    /**
     * Override for _failed method in Codeception method. Adds png and html attachments to allure report
     * following parent execution of test failure processing.
     *
     * @param TestInterface $test
     * @param \Exception    $fail
     * @return void
     */
    public function _failed(TestInterface $test, $fail)
    {
        $this->debugWebDriverLogs($test);

        if ($this->pngReport === null && $this->htmlReport === null) {
            $this->saveScreenshot();
            if (getenv('ENABLE_PAUSE') === 'true') {
                $this->pause(true);
            }
        }

        if ($this->current_test === null) {
            throw new \RuntimeException("Suite condition failure: \n" . $fail->getMessage());
        }

        $this->addAttachment($this->pngReport, $test->getMetadata()->getName() . '.png', 'image/png');
        $this->addAttachment($this->htmlReport, $test->getMetadata()->getName() . '.html', 'text/html');

        $this->debug("Failure due to : {$fail->getMessage()}");
        $this->debug("Screenshot saved to {$this->pngReport}");
        $this->debug("Html saved to {$this->htmlReport}");
    }

    /**
     * Function which saves a screenshot of the current stat of the browser
     *
     * @return void
     */
    public function saveScreenshot()
    {
        $testDescription = "unknown." . uniqid();
        if ($this->current_test !== null) {
            $testDescription = Descriptor::getTestSignature($this->current_test);
        }

        $filename = preg_replace('~\W~', '.', $testDescription);
        $outputDir = codecept_output_dir();
        $this->_saveScreenshot($this->pngReport = $outputDir . mb_strcut($filename, 0, 245, 'utf-8') . '.fail.png');
        $this->_savePageSource($this->htmlReport = $outputDir . mb_strcut($filename, 0, 244, 'utf-8') . '.fail.html');
    }

    /**
     * Go to a page and wait for ajax requests to finish
     *
     * @param string $page
     * @return void
     * @throws \Exception
     */
    public function amOnPage($page)
    {
        (0 === strpos($page, 'http')) ? parent::amOnUrl($page) : parent::amOnPage($page);
        $this->waitForPageLoad();
    }

    /**
     * Clean Javascript errors in internal array
     *
     * @return void
     */
    public function cleanJsError()
    {
        $this->jsErrors = [];
    }

    /**
     * Save Javascript error message to internal array
     *
     * @param string $errMsg
     * @return void
     */
    public function setJsError($errMsg)
    {
        $this->jsErrors[] = $errMsg;
    }

    /**
     * Get all Javascript errors
     *
     * @return string
     */
    private function getJsErrors()
    {
        $errors = '';

        if (!empty($this->jsErrors)) {
            $errors = 'Errors in JavaScript:';
            foreach ($this->jsErrors as $jsError) {
                $errors .= "\n" . $jsError;
            }
        }

        return $errors;
    }

    /**
     * Verify that there is no JavaScript error in browser logs
     *
     * @return void
     */
    public function dontSeeJsError()
    {
        $this->assertEmpty($this->jsErrors, $this->getJsErrors());
    }

    /**
     * Takes a screenshot of the current window and saves it to `tests/_output/debug`.
     *
     * This function is copied over from the original Codeception WebDriver so that we still have visibility of
     * the screenshot filename to be passed to the AllureHelper.
     *
     * @param string $name
     * @return void
     * @throws AllureException
     */
    public function makeScreenshot($name = null)
    {
        if (empty($name)) {
            $name = uniqid(date("Y-m-d_H-i-s_"));
        }
        $debugDir = codecept_log_dir() . 'debug';
        if (!is_dir($debugDir)) {
            mkdir($debugDir, 0777);
        }
        $screenName = $debugDir . DIRECTORY_SEPARATOR . $name . '.png';
        $this->_saveScreenshot($screenName);
        $this->debug("Screenshot saved to $screenName");
        AllureHelper::addAttachmentToCurrentStep($screenName, 'Screenshot');
    }

    /**
     * Return OTP based on a shared secret
     *
     * @param string|null $secretsPath
     * @return string
     * @throws TestFrameworkException
     */
    public function getOTP($secretsPath = null)
    {
        return OTP::getOTP($secretsPath);
    }

    /**
     * Waits proper amount of time to perform Cron execution
     *
     * @param array   $cronGroups
     * @param integer $timeout
     * @param string  $arguments
     * @return string
     * @throws TestFrameworkException
     */
    private function executeCronjobs($cronGroups, $timeout, $arguments): string
    {
        $cronGroups = array_filter($cronGroups);

        $waitFor = $this->getCronWait($cronGroups);

        if ($waitFor) {
            $this->wait($waitFor);
        }

        $command = array_reduce($cronGroups, function ($command, $cronGroup) {
            $command .= ' --group=' . $cronGroup;
            return $command;
        }, self::MAGENTO_CRON_COMMAND);
        $timeStart = microtime(true);
        $cronResult = $this->magentoCLI($command, $timeout, $arguments);
        $timeEnd = microtime(true);

        $this->notifyCronFinished($cronGroups);

        return sprintf('%s (wait: %ss, execution: %ss)', $cronResult, $waitFor, round($timeEnd - $timeStart, 2));
    }

    /**
     * Switch to another frame on the page by name, ID, CSS or XPath.
     *
     * @param string|null $locator
     * @return void
     * @throws \Exception
     */
    public function switchToIFrame($locator = null)
    {
        try {
            parent::switchToIFrame($locator);
        } catch (\Exception $e) {
            $els = $this->_findElements("#$locator");
            if (!count($els)) {
                $this->debug('Failed to find locator by ID: ' . $e->getMessage());
                throw new \Exception("IFrame with $locator was not found.");
            }
            $this->webDriver->switchTo()->frame($els[0]);
        }
    }

    /**
     * Invoke Codeption pause()
     *
     * @param boolean $pauseOnFail
     * @return void
     */
    public function pause($pauseOnFail = false)
    {
        if (\Composer\InstalledVersions::isInstalled('hoa/console') === false) {
            $message = "<pause /> action is unavailable." . PHP_EOL;
            $message .= "Please install `hoa/console` via \"composer require hoa/console\"" . PHP_EOL;
            print($message);
            return;
        }
        if (!\Codeception\Util\Debug::isEnabled()) {
            return;
        }

        if ($pauseOnFail) {
            print(PHP_EOL . "Failure encountered. Pausing execution..." . PHP_EOL . PHP_EOL);
        }

        $this->codeceptPause();
    }
}
