<?php
/**
 * Test coverage for mbc-user-import.php and mbc-user-import.config.inc. Starting
 * point for mbc-user-import application.
 */
namespace DoSomething\MBC_UserImport;

use DoSomething\MB_Toolbox\MB_Configuration;

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

        define('CONFIG_PATH', __DIR__ . '/../messagebroker-config');
        define('ENVIROMENT', 'test');
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