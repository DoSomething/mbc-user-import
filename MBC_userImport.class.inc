<?php

use DoSomething\MBStatTracker\StatHat;

/**
 * MBC_UserImport class - functionality related to the Message Broker
 * producer mbc-user-import.
 */
class MBC_UserImport
{
  
  /**
   * Message Broker object that details the connection to RabbitMQ.
   *
   * @var object
   */
  private $messageBroker;
  
  /**
   * Details of the channel connection in use by RabbitMQ.
   *
   * @var object
   */
  private $channel;

  /**
   * Collection of configuration settings.
   *
   * @var array
   */
  private $config;

  /**
   * Collection of secret connection settings.
   *
   * @var array
   */
  private $credentials;

  /**
   * Setting from external services - Mailchimp.
   *
   * @var array
   */
  private $statHat;
  
  /**
   * The number of queue entries to process in each session
   */
  const BATCH_SIZE = 500;

  /**
   * Constructor for MBC_UserImport
   *
   * @param array $credentials
   *   Secret settings from mb-secure-config.inc
   *
   * @param array $config
   *   Configuration settings from mb-config.inc
   */
  public function __construct($credentials, $config, $settings) {

    $this->config = $config;
    $this->credentials = $credentials;

    // Setup RabbitMQ connection
    $this->messageBroker = new MessageBroker($credentials, $config);
    
    $connection = $this->messageBroker->connection;
    $this->channel = $connection->channel();

    $this->statHat = new StatHat($settings['stathat_ez_key'], 'mbp-user-import:');
    $this->statHat->setIsProduction(FALSE);
  }

  /*
   * Consume entries in the MB_USER_IMPORT_QUEUE create entries in other
   * related user creation queues to trigger various related functionality -
   * UserAPI / mb-users record creation, entry in Mailchimp, send transactional
   * "welcome" email message via Mandrill.
   */
  public function produceUserImport() {

    echo '------- mbc-user-import->produceUserImport() START: ' . date('D M j G:i:s T Y') . ' -------', "\n";

    list($targetUsers, $deliveryTags) = $this->consumeUserImportQueue();
    
    foreach ($targetUsers as $userCount => $user) {
      
      // Create Drupal user - https://github.com/DoSomething/dosomething/wiki/API#users
      $newUserDetails = $this->createDrupalUser($user);
    
      // With new user account details from Drupal site:
      // - Add entry to mbc-userAPI-registration
      // - Add entry to newRegistrationQueue to add new user to Mailchimp
      // - Add entry to transactionalQueue to trigger sending welcome email via
      //    Mandrill using a template specific to a new user via import.
      // Ack message

      $this->channel->basic_ack($deliveryTags[$userCount]);

    }

    echo '------- mbc-user-import->produceUserImport() END: ' . date('D M j G:i:s T Y') . ' -------', "\n";
  }
  
  /**
   * Collect a batch of user submissions to prowler from the related RabbitMQ
   * queue based on produced entries by mbp-user-import.
   *
   * @return array
   *   An array of the status of the job
   */
  private function consumeUserImportQueue() {

    // Get the status details of the queue by requesting a declare
    list($this->channel, $status) = $this->messageBroker->setupQueue($this->config['queue'][0]['name'], $this->channel);
    $userImportCount = $status[1];

    $userImportDetails = '';
    $deliveryTags = array();
    $targetUsers = array();
    $processedCount = 0;

    while ($userImportCount > 0 && $processedCount < self::BATCH_SIZE) {

      $userImportDetails = $this->channel->basic_get($this->config['queue'][0]['name']);
      $deliveryTags[] = $userImportDetails->delivery_info['delivery_tag'];
      $targetUsers[$processedCount] = json_decode($userImportDetails->body);

      $userImportCount--;
      $processedCount++;
    }

    if (count($targetUsers) > 0) {
      $this->statHat->clearAddedStatNames();
      $this->statHat->addStatName('consumeUserImportQueue');
      $this->statHat->reportCount($processedCount);
      return array($targetUsers, $deliveryTags);
    }
    else {
      echo '------- mbc-digest-email MBC_UserDigest->consumeUserDigestQueue() - Queue is empty. -  ' . date('D M j G:i:s T Y') . ' -------', "\n";
    }

  }
  
  
  /**
   * Collect a batch of user submissions to prowler from the related RabbitMQ
   * queue based on produced entries by mbp-user-import.
   *
   * @return array
   *   An array of the status of the job
   */
  private function createDrupalUser($user) {
    
    
  }

}
