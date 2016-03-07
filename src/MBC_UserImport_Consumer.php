<?php
/**
 *
 */

namespace DoSomething\MBC_UserImport;

use DoSomething\StatHat\Client as StatHat;
use DoSomething\MB_Toolbox\MB_Toolbox_BaseConsumer;
use \Exception;

/**
 * MBC_UserImport class - functionality related to the Message Broker
 * producer mbc-user-import.
 */
class MBC_UserImport_Consumer extends MB_Toolbox_BaseConsumer
{

  /**
   * The amount of time for the application to sleep / wait when an exception is encountered.
   */
  const SLEEP = 10;

  /**
   * User settings to be used for general import message generation.
   *
   * @var array $user
   */
  private $user;

  /**
   *
   */
  public function __construct() {

    parent::__construct();
    $this->allowedSources = unserialize(ALLOWED_SOURCES);
  }

  /**
   * Initial method triggered by blocked call in mbc-user-import.php.
   *
   * @param array $payload
   *   The contents of the queue entry message being processed.
   */
  public function consumeUserImportQueue($payload) {

    echo '------ mbc-user-import - MBC_UserImport_Consumer->consumeUserImportQueue() - ' . date('j D M Y G:i:s T') . ' START ------', PHP_EOL . PHP_EOL;

    parent::consumeQueue($payload);

    try {

      if ($this->canProcess()) {

        $this->logConsumption(['email', 'mobile']);
        $this->setter($this->message);
        $this->process();
        $this->messageBroker->sendAck($this->message['payload']);
        $this->statHat->ezCount('mbc-user-import:MBC_UserImport_Consumer: processed', 1);
      }
      else {
        echo '- failed canProcess(), removing from queue.', PHP_EOL;
        $this->statHat->ezCount('mbc-user-import:MBC_UserImport_Consumer: skipping', 1);
        $this->messageBroker->sendAck($this->message['payload']);
      }

    }
    catch(Exception $e) {

      if (strpos($e->getMessage(), 'Failed to generate password') !== false) {
        echo '- Error message: ' . $e->getMessage() . ', retry in ' . self::SLEEP . ' seconds.', PHP_EOL;
        $this->statHat->ezCount('mbc-user-import:MBC_UserImport_Consumer: Exception: Failed to generate password', 1);
        sleep(self::SLEEP);
        $this->messageBroker->sendNack($this->message['payload']);
      }
      elseif (strpos($e->getMessage(), 'Failed to create Drupal user') !== false) {
        echo '- Error message: ' . $e->getMessage() . ', retry in ' . self::SLEEP . ' seconds.', PHP_EOL;
        $this->statHat->ezCount('mbc-user-import:MBC_UserImport_Consumer: Exception: Failed to create Drupal user', 1);
        sleep(self::SLEEP);
        $this->messageBroker->sendNack($this->message['payload']);
      }
      elseif (strpos($e->getMessage(), 'Error making curlGETauth request to https://www.dosomething.org/api/v1/users.json?parameters[email]=') !== false) {
        echo '- Error message: ' . $e->getMessage() . ', retry in ' . self::SLEEP . ' seconds.', PHP_EOL;
        $this->statHat->ezCount('mbc-user-import:MBC_UserImport_Consumer: Exception: Failed to lookup Drupal user, 302 returned', 1);
        sleep(self::SLEEP);
        $this->messageBroker->sendNack($this->message['payload']);
      }
      else {
        echo '- Error processing message, send to deadLetterQueue: ' . date('j D M Y G:i:s T'), PHP_EOL;
        echo '- Error message: ' . $e->getMessage(), PHP_EOL;
        $this->statHat->ezCount('mbc-user-import:MBC_UserImport_Consumer: Exception: deadLetter', 1);
        parent::deadLetter($this->message, 'MBC_UserImport_Consumer->consumeUserImportQueue() Error', $e->getMessage());
        $this->messageBroker->sendAck($this->message['payload']);
      }

    }

    // @todo: Throttle the number of consumers running. Based on the number of messages
    // waiting to be processed start / stop consumers. Make "reactive"!
    $queueStatus = parent::queueStatus('userImportQueue');

    echo '------ mbc-user-import - MBC_UserImport_Consumer->consumeUserImportQueue() - ' . date('j D M Y G:i:s T') . ' END ------', PHP_EOL . PHP_EOL;

  }

  /**
   * Method to determine if message can / should be processed. Conditions based on minimum message
   * content requirements.
   *
   * @retun boolean
   */
  protected function canProcess() {

    if (empty($this->message['source'])) {
      echo '- canProcess(), source not defined.', PHP_EOL;
      throw new Exception('Source not defined');
    }

    if (!(in_array($this->message['source'], $this->allowedSources))) {
      echo '- canProcess(), unsupported source: ' . $this->message['source'], PHP_EOL;
      throw new Exception('Unsupported source: '. $this->message['source']);
    }

    return true;
  }

  /**
   * Sets values for processing based on contents of message from consumed queue.
   *
   * @param array $message
   *  The payload of the message being processed.
   */
  protected function setter($message) {

    unset($message['original']);
    $this->user = $message;
  }

  /**
   * Method to process user import data.
   *
   * @param array $payload
   *   The contents of the queue entry
   */
  protected function process() {

    $sourceClass = __NAMESPACE__ . '\MBC_UserImport_Source_' . $this->user['source'];
    $userImportProcessor = new $sourceClass();

    if ($userImportProcessor->canProcess($this->user)) {
      $userImportProcessor->setter($this->user);
      $userImportProcessor->process();
    }

  }

  /**
   * logConsumption(): Extended to log the status of processing a specific message
   * elements - email and mobile.
   *
   * @param array $targetNames
   */
  protected function logConsumption($targetNames = null) {

    echo '** Consuming ';
    $targetNameFound = false;
    foreach ($targetNames as $targetName) {
      if (isset($this->message[$targetName])) {
        if ($targetNameFound) {
           echo ', ';
        }
        echo $targetName . ': ' . $this->message[$targetName];
        $targetNameFound = true;
      }
    }
    if ($targetNameFound) {
      echo ' from: ' .  $this->message['source'], PHP_EOL;
    }
    else {
      echo 'xx Target property not found in message.', PHP_EOL;
    }
  }

}
