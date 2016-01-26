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
   * The number of queue entries to process in each session
   */
  const BATCH_SIZE = 5000;

  /**
   * Initial method triggered by blocked call in mbc-user-import.php.
   *
   * @param array $payload
   *   The contents of the queue entry message being processed.
   */
  private function consumeUserImportQueue($payload) {

    echo '------ mbc-user-import - MBC_UserImport_Consumer->consumeUserImportQueue() - ' . date('j D M Y G:i:s T') . ' START ------', PHP_EOL . PHP_EOL;

    parent::consumeQueue($payload);
    $this->logConsumption(['email', 'mobile']);

    if ($this->canProcess()) {

      try {

        $this->setter($this->message);
        $this->process();

      }
      catch(Exception $e) {
        echo '- Error processing message, send to deadLetterQueue: ' . date('j D M Y G:i:s T'), PHP_EOL;
        echo '- Error message: ' . $e->getMessage(), PHP_EOL;
        parent::deadLetter($this->message, 'MBC_UserImport_Consumer->consumeUserImportQueue() Error', $e->getMessage());
      }

    }
    else {
      echo '- failed canProcess(), removing from queue.', PHP_EOL;
      $this->messageBroker->sendAck($this->message['payload']);
    }

    // @todo: Throttle the number of consumers running. Based on the number of messages
    // waiting to be processed start / stop consumers. Make "reactive"!
    $queueStatus = parent::queueStatus('transactionalQueue');

    echo '------ mbc-user-import - MBC_UserImport_Consumer->consumeUserImportQueue() - ' . date('j D M Y G:i:s T') . ' END ------', PHP_EOL . PHP_EOL;

  }

  /**
   * Method to determine if message can / should be processed. Conditions based on minimum message
   * content requirements.
   *
   * @retun boolean
   */
  protected function canProcess() {

    return TRUE;
  }

  /**
   * Sets values for processing based on contents of message from consumed queue.
   *
   * @param array $message
   *  The payload of the message being processed.
   */
  protected function setter($message) {

  }

  /**
   * Method to process user import data.
   *
   * @param array $payload
   *   The contents of the queue entry
   */
  protected function process() {

  }

  /**
   * logConsumption(): Extended to log the status of processing a specific message
   * elements - email and mobile.
   *
   * @param array $targetNames
   */
  protected function logConsumption($targetNames = null) {

    if ($targetNames != null && is_array($targetNames)) {

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

    } else {
      echo 'Target names: ' . print_r($targetNames, true) . ' are not defined.', PHP_EOL;
    }

  }

}
