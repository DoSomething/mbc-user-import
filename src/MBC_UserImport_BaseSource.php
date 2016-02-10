<?php
/**
 *
 */

namespace DoSomething\MBC_UserImport;

use DoSomething\StatHat\Client as StatHat;
use DoSomething\MB_Toolbox\MB_Toolbox;
use DoSomething\MB_Toolbox\MB_Configuration;

/*
 * MBC_UserAPICampaignActivity.class.in: Used to process the transactionalQueue
 * entries that match the campaign.*.* binding.
 */
abstract class MBC_UserImport_BaseSource
{

  /**
   * Values for user import message to be distributed to various applications within the
   * Message Broker system.
   *
   * @var array $importUser
   */
  protected $importUser;

  /**
   * Singleton instance of MB_Configuration application settings and service objects
   *
   * @var object
   */
  protected $mbConfig;

  /**
   * Message Broker connection to RabbitMQ
   *
   * @var object
   */
  protected $messageBroker;

  /**
   * Message Broker connection to RabbitMQ
   *
   * @var object
   */
  protected $messageBroker_transactionals;

  /**
   * Message Broker connection to RabbitMQ for Dead Letter messages.
   *
   * @var object
   */
  protected $messageBroker_deadLetter;

  /**
   * StatHat object for logging of activity
   *
   * @var object
   */
  protected $statHat;

  /**
   * The number of DoSomething users.
   *
   * @var string
   */
  public $memberCount;

  /**
   * The name of the user data source being processed.
   *
   * @var string
   */
  public $sourceName;

  /**
   * The collection of common methods used by source classes.
   *
   * @var object
   */
  public $mbcUserImportToolbox;

  /**
   * Constructor for MBC_UserImport_BaseSource - all source classes should extend this base class.
   */
  public function __construct() {

    $this->mbConfig = MB_Configuration::getInstance();
    $this->messageBroker = $this->mbConfig->getProperty('messageBroker');
    $this->messageBroker_transactionals = $this->mbConfig->getProperty('messageBrokerTransactionals');
    $this->messageBroker_deadLetter = $this->mbConfig->getProperty('messageBroker_deadLetter');
    $this->statHat = $this->mbConfig->getProperty('statHat');
    $this->mbToolbox = $this->mbConfig->getProperty('mbToolbox');
    $this->memberCount = $this->mbToolbox->getDSMemberCount();
  }

  /**
   * Method to determine if message can be processed. Tests based on requirements of the source.
   *
   * @param array $message
   *  The payload of the message being processed.
   *
   * @retun boolean
   */
  abstract public function canProcess($message);

  /**
   * Sets values for processing based on contents of message from consumed queue.
   *
   * @param array $message
   *  The payload of the message being processed.
   */
  abstract public function setter($message);

  /**
   * Process message from consumed queue.
   */
  abstract public function process();

  /**
   * Settings specific to welcome email messages
   *
   * @param array $user
   *   Setting specific to the user being imported.
   *
   * @return array $payload
   *   Adjusted based on email and user settings.
   */
  abstract public function addWelcomeEmailSettings($user, &$payload);

  /**
   * Settings specific to email subscriptions (MailChimp lists).
   *
   * @param array $user
   *   Setting specific to the user being imported.
   *
   * @return array $payload
   *   Adjusted based on email and user settings.
   */
  abstract public function addEmailSubscriptionSettings($user, &$payload);

  /**
   * Settings specific to SMS welcome message.
   *
   * @param array $user
   *   Setting specific to the user being imported.
   *
   * @return array $payload
   *   Adjusted based on email and user settings.
   */
  abstract public function addWelcomeSMSSettings($user, &$payload);

}
