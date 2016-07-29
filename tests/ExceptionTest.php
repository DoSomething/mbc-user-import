<?php
/**
 * Test coverage for Exceptions in MBC_UserImport application.
 */

namespace DoSomething\MBC_UserImport;

use DoSomething\MessageBroker\MessageBroker;
use DoSomething\MB_Toolbox\MB_Configuration;

define('ENVIRONMENT', 'local');
define('CONFIG_PATH', __DIR__ . '/../messagebroker-config');

/**
 * Class ExceptionTest
 *
 * @package DoSomething\MBC_UserImport
 */
class ExceptionTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var object MBC_UserImport_Consumer.
     */
    public $mbcUserImportConsumer;

    /**
     * @var array allowedSources.
     */
    public $allowedSources;

    /**
     * Common functionality to all tests. Load configuration settings and properties.
     */
    public function setUp()
    {
        require_once __DIR__ . '/../mbc-user-import.config.inc';
        $this->mbcUserImportConsumer = new MBC_UserImport_Consumer();
        $this->allowedSources = unserialize(ALLOWED_SOURCES);
    }

    /**
     * @covers \DoSomething\MBC_UserImport\MBC_UserImport_Consumer::canProcess
     * @uses   \DoSomething\MBC_UserImport\MBC_UserImport_Consumer
     * @expectedException Exception
     */
    public function testException()
    {
        // Empty source
        $message = [];
        $this->mbcUserImportConsumer->canProcess($message);

        // Unsupported source
        $message['source'] = 'TeenLife';
        $this->mbcUserImportConsumer->canProcess($message);
    }
}
