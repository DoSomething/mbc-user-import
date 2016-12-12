<?php
/**
 * A consumer application for a user import system. Various source types define
 * specifics as to how the user data should be injested from CSV files. User data is
 * distributed to other consumers in the Quicksilver system for further processing
 * as well as triggering transactional messaging related to welcoming the user to
 * DoSomething.org.
 *
 * PHP version 5
 *
 * @category PHP
 * @package  MBC_UserImport
 * @author   DeeZone <dlee@dosomething.org>
 * @license  MIT: https://en.wikipedia.org/wiki/MIT_License
 * @version  GIT: <git_id>
 * @link     https://github.com/DoSomething/mbc-user-import
 */
namespace DoSomething\MBC_UserImport;

use DoSomething\MB_Toolbox\MB_Toolbox_BaseConsumer;
use \Exception;

/**
 * Consumer functionality related to the Quicksilver / Message Broker application
 * mbc-user-import. Messages are processed as transactions from the userImportQueue
 * which results in distribution as new messages sent to exchangesnfor further
 * processing as well as triggering transactional messaging - email and SMS.
 *
 * @category PHP
 * @package  MBC_UserImport
 * @author   DeeZone <dlee@dosomething.org>
 * @license  MIT: https://en.wikipedia.org/wiki/MIT_License
 * @version  "Release: <package_version>"
 * @link     https://github.com/DoSomething/mbc-user-import/blob/master/src
 *           /MBC_UserImport_Consumer.php
 */
class MBC_UserImport_Consumer extends MB_Toolbox_BaseConsumer
{

  /**
   * The amount of time for the application to sleep / wait when an exception is
   * encountered.
   */
  const SLEEP = 10;

  /**
   * User settings to be used for general import message generation.
   *
   * @var array $user
   */
  protected $user;

  /**
   * Supported source values
   *
   * @var array $allowedSources
   */
  private $allowedSources;

  /**
   * Settings common to consumer of user import data.
   */
  public function __construct()
  {

    parent::__construct();
    $this->allowedSources = unserialize(ALLOWED_SOURCES);
  }
  /**
   * Initial method triggered by blocked call in mbc-user-import.php.
   *
   * @param array $payload The contents of the queue entry message being processed.
   *
   * @return null
   */
  public function consumeUserImportQueue($payload)
  {

    echo '------ mbc-user-import - MBC_UserImport_Consumer->consumeUserImportQueue() - ' .
      date('j D M Y G:i:s T') . ' START ------', PHP_EOL . PHP_EOL;

    parent::consumeQueue($payload);

    try {
      if ($this->canProcess($this->message)) {
        $this->logConsumption(['email', 'mobile']);
        $this->setter($this->message);
        $processParams = [
          'user'   => $this->user,
          'source' => $this->user['source']
        ];
        $this->process($processParams);
        // $this->messageBroker->sendAck($this->message['payload']);
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Consumer: processed',
          1
        );
      } else {
        echo '- failed canProcess(), removing from queue.', PHP_EOL;
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Consumer: skipping',
          1
        );
        $this->messageBroker->sendAck($this->message['payload']);
      }
    } catch (Exception $e) {
      if (strpos($e->getMessage(), 'Failed to generate password') !== false) {
        echo '- Error message: ' . $e->getMessage() . ', retry in ' . self::SLEEP . ' seconds.', PHP_EOL;
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Consumer: Exception: Failed to generate password',
          1
        );
        sleep(self::SLEEP);
        $this->messageBroker->sendNack($this->message['payload']);
      } elseif (strpos($e->getMessage(), 'Failed to create Drupal user') !== false) {
        echo '- Error message: ' . $e->getMessage() . ', retry in ' . self::SLEEP . ' seconds.', PHP_EOL;
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Consumer: Exception: Failed to create Drupal user',
          1
        );
        sleep(self::SLEEP);
        $this->messageBroker->sendNack($this->message['payload']);
      } elseif (strpos(
        $e->getMessage(),
        'Error making curlGETauth request ' .
        'to https://www.dosomething.org/api/v1/users.json?parameters[email]='
      ) !== false
      ) {
        echo '- Error message: ' . $e->getMessage() . ', retry in ' . self::SLEEP . ' seconds.', PHP_EOL;
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Consumer: Exception: Failed ' .
          'to lookup Drupal user, 302 returned',
          1
        );
        sleep(self::SLEEP);
        $this->messageBroker->sendNack($this->message['payload']);
      } elseif (strpos($e->getMessage(), 'Connection timed out') !== false) {
        echo '- Error message: ' . $e->getMessage() . ', retry in ' . self::SLEEP . ' seconds.', PHP_EOL;
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Consumer: Exception: Mobile Commons timeout',
          1
        );
        sleep(self::SLEEP);
        $this->messageBroker->sendNack($this->message['payload']);
      } elseif (strpos($e->getMessage(), 'is registered to User') !== false) {
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Consumer: Exception: Existing mobile / new email',
          1
        );
        $this->messageBroker->sendAck($this->message['payload']);
      } elseif (strpos($e->getMessage(), 'is not a valid phone number') !== false) {
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Consumer: Exception: Existing mobile / new email',
          1
        );
        $this->messageBroker->sendAck($this->message['payload']);
      } elseif (strpos($e->getMessage(), 'Bad response - HTTP Code:503') !== false) {
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Consumer: Exception: Bad response - 503',
          1
        );
        $this->messageBroker->sendAck($this->message['payload']);
      } else {
        echo '- Error processing message, send to deadLetterQueue: ' . date('j D M Y G:i:s T'), PHP_EOL;
        echo '- Error message: ' . $e->getMessage(), PHP_EOL;
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Consumer: Exception: deadLetter',
          1
        );

        $msg = 'MBC_UserImport_Consumer->consumeUserImportQueue(): ';
        parent::deadLetter($this->message, $msg, $e);
        $this->messageBroker->sendAck($this->message['payload']);
      }
    }

    // @todo: Throttle the number of consumers running. Based on the number of
    // messages waiting to be processed start / stop consumers. Make "reactive"!
    // $queueStatus = parent::queueStatus('userImportQueue');
    echo '------ mbc-user-import - MBC_UserImport_Consumer->consumeUserImportQueue() - ' .
      date('j D M Y G:i:s T') . ' END ------', PHP_EOL . PHP_EOL;
  }

  /**
   * Determine if message can / should be processed. Conditions based on minimum
   * message content requirements.
   *
   * @throws Exception
   *
   * @return boolean
   */
  public function canProcess($message)
  {

    if (empty($message['source'])) {
      echo '- canProcess(), source not defined.', PHP_EOL;
      throw new Exception('Source not defined');
    }

    if (!(in_array($message['source'], $this->allowedSources))) {
      echo '- canProcess(), unsupported source: ' . $message['source'], PHP_EOL;
      throw new Exception('Unsupported source: '. $message['source']);
    }

    return true;
  }

  /**
   * Sets values for processing based on contents of message from consumed queue.
   *
   * @param array $message The payload of the message being processed.
   *
   * @return array $this->user Used for test coverage.
   */
  public function setter($message)
  {

    unset($message['original']);
    $this->user = $message;

    // For test coverage
    return $this->user;
  }

  /**
   * Process user import data based on values prepared in setter(). Processing
   * based on Source class selected by data source value.
   *
   * @param array $params Generic parameter for passing in all values used by method to perform logic that
   *                      results in processing.
   *
   * @return string $sourceClass The name of the class to process the imported user values.
   */
  public function process($params)
  {
    $source = $this->normalizeSource($params['source']);
    $sourceClass = __NAMESPACE__ . '\MBC_UserImport_Source_' . $source;
    $userImportProcessor = new $sourceClass();
    if (isset($params['user']) && $userImportProcessor->canProcess($params['user'])) {
      $userImportProcessor->setter($params['user']);
      $userImportProcessor->process();
    }

    return $sourceClass;
  }

  /**
   * Process source value to normalized value that can be used as part of class name.
   *
   * @param string $source Current source value.
   *
   * @return string
   */
  private function normalizeSource($source)
  {

    $sourceNames = explode('_', $source);
    $classWords = [];
    foreach ($sourceNames as $name) {
      $classWords[] = ucfirst($name);
    }
    $source = implode('', $classWords);

    return $source;
  }

  /**
   * Extended to log the status of processing a specific message
   * elements - email and mobile.
   *
   * @param array $targetNames The names of the key message values being consumed.
   *
   * @return null
   */
  protected function logConsumption($targetNames = null)
  {

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
    } else {
      echo 'xx Target property not found in message.', PHP_EOL;
    }
  }
}
