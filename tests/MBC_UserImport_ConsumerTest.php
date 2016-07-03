<?php
/**
 * Test coverage for MBC_UserImport_Consumer class.
 */
namespace DoSomething\MBC_UserImport;

class MBC_UserImport_ConsumerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var object MBC_UserImport_Consumer.
     */
    public $mbcUserImportConsumer;

    /**
     * Common functionality to all tests. Load configuration settings and properties.
     */
    public function setUp()
    {
        require_once __DIR__ . '/../mbc-user-import.config.inc';
        $this->mbcUserImportConsumer = new MBC_UserImport_Consumer();
    }

    /**
     * .
     *
     * @covers \DoSomething\MBC_UserImport\MBC_UserImport_Consumer::canProcess
     * @uses   \DoSomething\MBC_UserImport\MBC_UserImport_Consumer
     */
    public function testCanProcess()
    {

        $this->_allowedSources = unserialize(ALLOWED_SOURCES);
        $this->message['source'] = 'Niche';

        // $this->assertEquals(true, $this->mbcUserImportConsumer->canProcess());
    }
}
