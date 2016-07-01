<?php
/**
 * Test coverage for MBC_UserImport_Consumer class.
 */
namespace DoSomething\MBC_UserImport;

// use DoSomething\MBC_UserImport\MBC_UserImport_Consumer;

class MBC_UserImport_ConsumerTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @covers            \DoSomething\MBC_UserImport\MBC_UserImport_Consumer::__construct
     * @uses              \DoSomething\MBC_UserImport\MBC_UserImport_Consumer
     */
    public function testAllowedSources()
    {

        define('CONFIG_PATH',  __DIR__ . '/../messagebroker-config');
        require_once __DIR__ . '/../mbc-user-import.config.inc';
        
        $allowedSources = unserialize(ALLOWED_SOURCES);
        $this->assertEquals(true, in_array('AfterSchool', $allowedSources));
        $this->assertEquals(true, in_array('Niche', $allowedSources));
        $this->assertEquals(false, in_array('TeenLife', $allowedSources));
    }
}
