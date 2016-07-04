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
            $this->allowedSources = unserialize(ALLOWED_SOURCES);
        }
        /**
         * Ensure that allowed sources can process.
         *
         * @covers \DoSomething\MBC_UserImport\MBC_UserImport_Consumer::canProcess
         * @uses   \DoSomething\MBC_UserImport\MBC_UserImport_Consumer
         */
        public function testCanProcess()
        {
            $message['source'] = 'Niche';
            $this->assertEquals(true, $this->mbcUserImportConsumer->canProcess($message));
            $message['source'] = 'AfterSchool';
            $this->assertEquals(true, $this->mbcUserImportConsumer->canProcess($message));
        }
        /**
         * Ensure setter() sets expected values.
         *
         * @covers \DoSomething\MBC_UserImport\MBC_UserImport_Consumer::setter
         * @uses   \DoSomething\MBC_UserImport\MBC_UserImport_Consumer
         */
        public function testSetter()
        {
            $message['source'] = 'Niche';
            $results = $this->mbcUserImportConsumer->setter($message);
            $this->assertEquals(true, $results['source'] == 'Niche');
            $message['origin'] = 'original';
            $results = $this->mbcUserImportConsumer->setter($message);
            $this->assertEquals(true, empty($results['original']));
        }
    }
