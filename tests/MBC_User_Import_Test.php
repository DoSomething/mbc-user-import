<?php
/**
 * Test coverage for mbc-user-import.php and mbc-user-import.config.inc. Starting
 * point for mbc-user-import application.
 */
namespace DoSomething\MBC_UserImport;

use DoSomething\MB_Toolbox\MB_Configuration;

define('CONFIG_PATH', __DIR__ . '/../messagebroker-config');
define('ENVIROMENT', 'test');

class MBC_User_Import_Test extends \PHPUnit_Framework_TestCase
{

    /**
     * @var MB_Configuration settings.
     */
    private $_mbConfig;

    /**
     * Common functionality to all tests. Load configuration settings and properties.
     */
    public function setUp()
    {
        require_once __DIR__ . '/../mbc-user-import.config.inc';
        $this->_mbConfig = MB_Configuration::getInstance();
    }

    /**
     * Ensure the ALLOWED_SOURCES constant has expected and unexpected serialize
     * values.
     *
     * @covers \DoSomething\MBC_UserImport\MBC_UserImport_Consumer::__construct
     * @uses   \DoSomething\MBC_UserImport\MBC_UserImport_Consumer
     */
    public function testAllowedSources()
    {

        $allowedSources = unserialize(ALLOWED_SOURCES);
        $this->assertEquals(true, in_array('AfterSchool', $allowedSources));
        $this->assertEquals(true, in_array('Niche', $allowedSources));
        $this->assertEquals(false, in_array('TeenLife', $allowedSources));
    }

    /**
     * Ensure mbConfig->getProperty returns a value.
     *
     * @covers \DoSomething\MBC_UserImport\MBC_UserImport_Consumer::__construct
     * @uses   \DoSomething\MBC_UserImport\MBC_UserImport_Consumer
     */
    public function testMBCUserImportConfigProperties()
    {
        $statHat = $this->_mbConfig->getProperty('statHat');
        $this->assertEquals(true, is_object($statHat));
        $rabbit_credentials = $this->_mbConfig->getProperty('rabbit_credentials');
        $this->assertEquals(true, is_array($rabbit_credentials));
        $mbRabbitMQManagementAPI = $this->_mbConfig->getProperty('mbRabbitMQManagementAPI');
        $this->assertEquals(true, is_object($mbRabbitMQManagementAPI));
        $ds_drupal_api_config = $this->_mbConfig->getProperty('ds_drupal_api_config');
        $this->assertEquals(true, is_array($ds_drupal_api_config));
        $northstar_config = $this->_mbConfig->getProperty('northstar_config');
        $this->assertEquals(true, is_array($northstar_config));
        $mobileCommons = $this->_mbConfig->getProperty('mobileCommons');
        $this->assertEquals(true, is_object($mobileCommons));
        $mailchimpAPIkeys = $this->_mbConfig->getProperty('mailchimpAPIkeys');
        $this->assertEquals(true, is_array($mailchimpAPIkeys));
        $mbToolbox = $this->_mbConfig->getProperty('mbToolbox');
        $this->assertEquals(true, is_object($mbToolbox));
        $mbToolboxCURL = $this->_mbConfig->getProperty('mbToolboxCURL');
        $this->assertEquals(true, is_object($mbToolboxCURL));
        $messageBroker = $this->_mbConfig->getProperty('messageBroker');
        // $this->assertEquals(true, is_object($messageBroker));
        $messageBrokerTransactionals = $this->_mbConfig->getProperty('messageBrokerTransactionals');
        // $this->assertEquals(true, is_object($messageBrokerTransactionals));
        $messageBrokerLogging = $this->_mbConfig->getProperty('messageBrokerLogging');
        // $this->assertEquals(true, is_object($messageBrokerLogging));
        $messageBroker_deadLetter = $this->_mbConfig->getProperty('messageBroker_deadLetter');
        // $this->assertEquals(true, is_object($messageBroker_deadLetter));

        // Each of the MailChimp accounts by country
        foreach($this->_mbConfig->getProperty('mbcURMailChimp_Objects') as $country => $mbcURMailChimp_Object) {
            $this->assertEquals(true, is_object($mbcURMailChimp_Object));
        }
    }

    /**
     * Ensure mbConfig->getProperty returns expected value types.
     *
     * @covers \DoSomething\MBC_UserImport\MBC_UserImport_Consumer::__construct
     * @uses   \DoSomething\MBC_UserImport\MBC_UserImport_Consumer
     */
    public function testMBCUserImportConfigPropertyTypes()
    {

    }
}
