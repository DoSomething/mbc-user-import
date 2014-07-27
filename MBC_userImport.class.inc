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

    $this->statHat = new StatHat($settings['stathat_ez_key'], 'mbp-user-import:');
    $this->statHat->setIsProduction(TRUE);
  }

  /*
   * Consume entries in the MB_USER_IMPORT_QUEUE create entries in other
   * related user creation queues to trigger various related functionality -
   * UserAPI / mb-users record creation, entry in Mailchimp, send transactional
   * "welcome" email message via Mandrill.
   */
  public function produceUserImport() {

    echo '------- mbc-user-import->produceUserImport() START: ' . date('D M j G:i:s T Y') . ' -------', "\n";
    
    // Read entry from MB_USER_IMPORT_QUEUE
    
    // Create Drupal user via cURL (service) call based on user details in the
    // queue entry
    
    // With new user account details from Drupal site:
    // - Add entry to mbc-userAPI-registration
    // - Add entry to newRegistrationQueue to add new user to Mailchimp
    // - Add entry to transactionalQueue to trigger sending welcome email via
    //    Mandrill using a template specific to a new user via import.
    

    echo '------- mbc-user-import->produceUserImport() END: ' . date('D M j G:i:s T Y') . ' -------', "\n";
  }

}
