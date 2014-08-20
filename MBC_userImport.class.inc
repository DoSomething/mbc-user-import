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
   * Collection of secret connection settings.
   *
   * @var array
   */
  private $credentials;

  /**
   * Collection of configuration settings.
   *
   * @var array
   */
  private $config;

  /**
   * Collection of settings.
   *
   * @var array
   */
  private $settings;

  /**
   * Setting from external services - Mailchimp.
   *
   * @var array
   */
  private $statHat;

  /**
   * Keep track of the existing email and mobile accounts.
   *
   * @var array
   */
  private $existingStatus;

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
   *   Configuration from mb-config.inc
   *
   * @param array $settings
   *   Configuration settings from mb-config.inc
   */
  public function __construct($credentials, $config, $settings) {

    $this->credentials = $credentials;
    $this->config = $config;
    $this->settings = $settings;

    // Setup RabbitMQ connection
    $this->messageBroker = new MessageBroker($credentials, $config);
    
    $connection = $this->messageBroker->connection;
    $this->channel = $connection->channel();

    $this->statHat = new StatHat($settings['stathat_ez_key'], 'mbc-user-import:');
    $this->statHat->setIsProduction(TRUE);

    $this->existingStatus = array();
  }

  /*
   * Consume entries in the MB_USER_IMPORT_QUEUE create entries in other
   * related user creation queues to trigger various related functionality -
   * UserAPI / mb-users record creation, entry in Mailchimp, send transactional
   * "welcome" email message via Mandrill.
   */
  public function produceUserImport() {

    echo '------- mbc-user-import->produceUserImport() START: ' . date('D M j G:i:s T Y') . ' -------', "\n";

    $targetUsers = array();
    $deliveryTags = array();
    list($targetUsers, $deliveryTags) = $this->consumeUserImportQueue();

    if (count ($targetUsers) > 0) {

      foreach ($targetUsers as $userCount => $user) {

        // Create Drupal user - https://github.com/DoSomething/dosomething/wiki/API#users
        list($drupalUser, $user->password) = $this->createDrupalUser($user);

        // With new user account details from the Drupal site:
        // - Send transaction to transactionalExchange for distribution to
        // transactionalQueue, userAPIRegistrationQueue and userRegistrationQueue
        // - could return error when user already exists based on supplied email
        $status = $this->userImportProducer($user, $drupalUser);

        // Ack message
        if ($status == TRUE) {
          $this->channel->basic_ack($deliveryTags[$userCount]);
        }

      }

    }
    else {
      echo 'userImportQueue is empty.', "\n";
    }

    // Log existing email and mobile accounts
    $this->logExistingAccounts();

    echo '------- mbc-user-import->produceUserImport() END: ' . date('D M j G:i:s T Y') . ' -------', "\n";
  }

  /**
   * Collect a batch of user submissions to prowler from the related RabbitMQ
   * queue based on produced entries by mbp-user-import.
   *
   * @return array $targetUsers
   *   Details of the new user accounts to be processed.
   *
   * @return array $deliveryTags
   *   Rabbit delivery tag IDs used for ack backs when processing of each queue
   *   entry is complete.
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
   * Send request to Drupal /api/v1/users end point to create a new user
   * account.
   *
   * @param array $user
   *   Details about the user to create Drupal account for.
   *
   * @return array
   *   Details of the new user account.
   */
  private function createDrupalUser($user) {

    $password = $user->first_name . '-Doer' . rand(1, 1000);
    $post = array(
      'email' => $user->email,
      'password' => $password,
      'first_name' => $user->first_name,
      "user_registration_source" => 'niche-import-service',
    );
    if (isset($user->birthdate) && strpos($user->birthdate, '/') > 0) {
      $post['birthdate'] = date('Y-m-d', strtotime($user->birthdate));
    }
    elseif (isset($user->birthdate) && is_int($user->birthdate)) {
      $post['birthdate'] = date('Y-m-d', $user->birthdate);
    }
    if (isset($user->last_name)) {
      $post['last_name'] = $user->last_name;
    }

    $ch = curl_init();
    $drupalAPIUrl = getenv('DS_DRUPAL_API_HOST');
    $port = getenv('DS_DRUPAL_API_PORT');
    if ($port != 0) {
      $drupalAPIUrl .= ':' . $port;
    }
    $drupalAPIUrl .= '/api/v1/users';

    curl_setopt($ch, CURLOPT_URL, $drupalAPIUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER,
      array(
        'Content-type: application/json',
        'Accept: application/json'
      )
    );
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch,CURLOPT_TIMEOUT, 20);
    $jsonResult = curl_exec($ch);
    $result = json_decode($jsonResult);
    curl_close($ch);

    return array($result, $password);
  }

  /**
   * Send transaction to transactionalExchange with user.registration.import
   * routing key.
   *
   * @param array $user
   *   Details about the user based on the import data.
   *
   * @param array $drupalUser
   *   Details about the Drupal user based on the account created from the API
   *   call to /api/v1/users.
   *
   * @return string $status
   *   Results for queue submission.
   */
  private function userImportProducer($user, $drupalUser) {

    if ((isset($user->email) && isset($drupalUser->uid) && $drupalUser->uid > 0) ||
       (isset($drupalUser->errorInfo[2]) && strpos($drupalUser->errorInfo[2], 'Duplicate entry') !== FALSE)) {

      // Check for existing accounts
      $this->checkExistingAccount($user);

      // Config specific to producing a new user for the Message Broker userAPI
      $config = array(
        'exchange' => array(
          'name' => getenv("MB_TRANSACTIONAL_EXCHANGE"),
          'type' => getenv("MB_TRANSACTIONAL_EXCHANGE_TYPE"),
          'passive' => getenv("MB_TRANSACTIONAL_EXCHANGE_PASSIVE"),
          'durable' => getenv("MB_TRANSACTIONAL_EXCHANGE_DURABLE"),
          'auto_delete' => getenv("MB_TRANSACTIONAL_EXCHANGE_AUTO_DELETE"),
        ),
        'queue' => array(
          'userAPIRegistration' => array(
            'name' => getenv("MB_USER_API_REGISTRATION_QUEUE"),
            'passive' => getenv("MB_USER_API_REGISTRATION_QUEUE_PASSIVE"),
            'durable' => getenv("MB_USER_API_REGISTRATION_QUEUE_DURABLE"),
            'exclusive' => getenv("MB_USER_API_REGISTRATION_QUEUE_EXCLUSIVE"),
            'auto_delete' => getenv("MB_USER_API_REGISTRATION_QUEUE_AUTO_DELETE"),
            'bindingKey' => getenv("MB_USER_API_REGISTRATION_QUEUE_TOPIC_MB_TRANSACTIONAL_EXCHANGE_PATTERN"),
          ),
          'registrations' => array(
            'name' => getenv("MB_USER_REGISTRATION_QUEUE"),
            'passive' => getenv("MB_USER_REGISTRATION_QUEUE_PASSIVE"),
            'durable' => getenv("MB_USER_REGISTRATION_QUEUE_DURABLE"),
            'exclusive' => getenv("MB_USER_REGISTRATION_QUEUE_EXCLUSIVE"),
            'auto_delete' => getenv("MB_USER_REGISTRATION_QUEUE_AUTO_DELETE"),
            'bindingKey' => getenv("MB_USER_REGISTRATION_QUEUE_TOPIC_MB_TRANSACTIONAL_EXCHANGE_PATTERN"),
          ),
          'transactional' => array(
            'name' => getenv("MB_TRANSACTIONAL_QUEUE"),
            'passive' => getenv("MB_TRANSACTIONAL_QUEUE_PASSIVE"),
            'durable' => getenv("MB_TRANSACTIONAL_QUEUE_DURABLE"),
            'exclusive' => getenv("MB_TRANSACTIONAL_QUEUE_EXCLUSIVE"),
            'auto_delete' => getenv("MB_TRANSACTIONAL_QUEUE_AUTO_DELETE"),
            'bindingKey' => getenv("MB_TRANSACTIONAL_QUEUE_TOPIC_MB_TRANSACTIONAL_EXCHANGE_PATTERN"),
          ),
          'mobileCommons' => array(
            'name' => getenv("MB_MOBILE_COMMONS_QUEUE"),
            'passive' => getenv("MB_MOBILE_COMMONS_QUEUE_PASSIVE"),
            'durable' => getenv("MB_MOBILE_COMMONS_QUEUE_DURABLE"),
            'exclusive' => getenv("MB_MOBILE_COMMONS_QUEUE_EXCLUSIVE"),
            'auto_delete' => getenv("MB_MOBILE_COMMONS_QUEUE_AUTO_DELETE"),
            'bindingKey' => getenv("MB_MOBILE_COMMONS_QUEUE_TOPIC_MB_TRANSACTIONAL_EXCHANGE_PATTERN"),
          ),
        ),
//        'routingKey' => 'user.registration.transactional',
        'routingKey' => 'user.registration.import',
      );
      $mbUserAPI = new MessageBroker($this->credentials, $config);

      // Currently supported
      $userDetails = array(
        'email' => $user->email,
        'subscribed' => 1,
        'activity_timestamp' => $drupalUser->created,
        'activity' => 'user_welcome-niche',
      );

      if (isset($drupalUser->uid) && $drupalUser->uid != '') {
        $userDetails['uid'] = $drupalUser->uid;
      }
      if (isset($user->birthdate) && $user->birthdate != 0) {
        $userDetails['birthdate_timestamp'] = strtotime($user->birthdate);
      }
      if (isset($user->first_name) && $user->first_name != '') {
        $userDetails['merge_vars']['FNAME'] = $user->first_name;
      }
      if (isset($user->last_name) && $user->last_name != '') {
        $userDetails['merge_vars']['LNAME'] = $user->last_name;
      }
      if (isset($user->password) && $user->password != '') {
        $userDetails['merge_vars']['PASSWORD'] = $user->password;
      }
      if (isset($user->address1) && $user->address1 != '') {
        $userDetails['address1'] = $user->address1;
      }
      if (isset($user->address2) && $user->address2 != '') {
        $userDetails['address2'] = $user->address2;
      }
      if (isset($user->city) && $user->city != '') {
        $userDetails['city'] = $user->city;
      }
      if (isset($user->state) && $user->state != '') {
        $userDetails['state'] = $user->state;
      }
      if (isset($user->zip) && $user->zip != '') {
        $userDetails['zip'] = $user->zip;
      }
      if (isset($user->phone) && $user->phone != '') {
        $userDetails['mobile'] = $user->phone;
      }
      if (isset($user->hs_gradyear) && $user->hs_gradyear != 0) {
        $userDetails['hs_gradyear'] = $user->hs_gradyear;
      }
      if (isset($user->race) && $user->race != '') {
        $userDetails['race'] = $user->race;
      }
      if (isset($user->religion) && $user->religion != '') {
        $userDetails['religion'] = $user->religion;
      }
      if (isset($user->hs_name) && $user->hs_name != '') {
        $userDetails['hs_name'] = $user->hs_name;
      }
      if (isset($user->college_name) && $user->college_name != '') {
        $userDetails['college_name'] = $user->college_name;
      }
      if (isset($user->major_name) && $user->major_name != '') {
        $userDetails['major_name'] = $user->major_name;
      }
      if (isset($user->degree_type) && $user->degree_type != '') {
        $userDetails['degree_type'] = $user->degree_type;
      }
      if (isset($user->sat_math) && $user->sat_math != 0) {
        $userDetails['sat_math'] = $user->sat_math;
      }
      if (isset($user->sat_verbal) && $user->sat_verbal != 0) {
        $userDetails['sat_verbal'] = $user->sat_verbal;
      }
      if (isset($user->sat_writing) && $user->sat_writing != 0) {
        $userDetails['sat_writing'] = $user->sat_writing;
      }
      if (isset($user->act_math) && $user->act_math != 0) {
        $userDetails['act_math'] = $user->act_math;
      }
      if (isset($user->gpa) && $user->gpa != 0) {
        $userDetails['gpa'] = $user->gpa;
      }
      if (isset($user->role) && $user->role != '') {
        $userDetails['role'] = $user->role;
      }

      $payload = serialize($userDetails);
      $mbUserAPI->publishMessage($payload);
      $status = TRUE;

      $this->statHat->clearAddedStatNames();
      $this->statHat->addStatName('Distribute user import - user.registration.transactional');
      $this->statHat->reportCount(1);

      // Create second transactional email message to inform the user of their temp password
      // Config specific to producing a new user for the Message Broker userAPI

      // Skip if the Drupal user account already existed
      if (!(isset($drupalUser->errorInfo[2]) && strpos($drupalUser->errorInfo[2], 'Duplicate entry') !== FALSE)) {

        $config = array(
          'exchange' => array(
            'name' => getenv("MB_TRANSACTIONAL_EXCHANGE"),
            'type' => getenv("MB_TRANSACTIONAL_EXCHANGE_TYPE"),
            'passive' => getenv("MB_TRANSACTIONAL_EXCHANGE_PASSIVE"),
            'durable' => getenv("MB_TRANSACTIONAL_EXCHANGE_DURABLE"),
            'auto_delete' => getenv("MB_TRANSACTIONAL_EXCHANGE_AUTO_DELETE"),
          ),
          'queue' => array(
            'transactional' => array(
              'name' => getenv("MB_TRANSACTIONAL_QUEUE"),
              'passive' => getenv("MB_TRANSACTIONAL_QUEUE_PASSIVE"),
              'durable' => getenv("MB_TRANSACTIONAL_QUEUE_DURABLE"),
              'exclusive' => getenv("MB_TRANSACTIONAL_QUEUE_EXCLUSIVE"),
              'auto_delete' => getenv("MB_TRANSACTIONAL_QUEUE_AUTO_DELETE"),
              'bindingKey' => getenv("MB_TRANSACTIONAL_QUEUE_TOPIC_MB_TRANSACTIONAL_EXCHANGE_PATTERN"),
            ),
          ),
          'routingKey' => 'user.import.transactional',
        );
        $mbUserAPI = new MessageBroker($this->credentials, $config);

        $userDetails['activity'] = 'user_password-niche';
        $payload = serialize($userDetails);
        $mbUserAPI->publishMessage($payload);

        $this->statHat->clearAddedStatNames();
        $this->statHat->addStatName('Sent user_password-niche');
        $this->statHat->reportCount(1);

      }
    }
    else {
      $status = FALSE;
    }

    return $status;
  }

 /*
  * Check for the existance of email (Mailchimp) and mobile (Mobile Commons)
  * accounts.
  *
  * @param array $user
  *   Information about a user to be imported into the DoSomething systems.
  */
  private function checkExistingAccount($user) {

    // http://apidocs.mailchimp.com/api/2.0/lists/member-info.php
    $MailChimp = new \Drewm\MailChimp($this->settings['mailchimp_apikey']);
    $mailchimpStatus = $MailChimp->call("/lists/member-info", array(
      'id' => $this->settings['mailchimp_list_id'],
      'emails' => array(
        array(
          'email' => $user->email
        ),
      )
    ));
    if ($mailchimpStatus['success_count'] == 1) {
      $existingStatus = array(
        'email-status' => 'Existing account',
        'email' => $user->email,
        'acquired' => $mailchimpStatus['data'][0]['timestamp']
      );
    }
    elseif ($mailchimpStatus == FALSE) {
      $existingStatus = array(
        'email-status' => 'Error',
        'email' => $user->email,
        'email-acquired' => $mailchimpStatus['data'][0]['timestamp']
      );
    }

    // https://mobilecommons.zendesk.com/hc/en-us/articles/202052534-REST-API#ProfileSummary
    $config = array(
      'username' => $this->settings['mobile_commons_username'],
      'password' => $this->settings['mobile_commons_password'],
    );
    $MobileCommons = new MobileCommons($config);
    $mobilecommonsStatus = (array)$MobileCommons->profiles_get(array('phone_number' => $user->phone));
    if (!isset($mobilecommonsStatus['error'])) {
      $existingStatus['mobile-error'] = 'Existing account';
      $existingStatus['mobile'] = $user->phone;
    }

    if (isset($existingStatus['email']) || isset($existingStatus['mobile'])) {
      $this->existingStatus[] = $existingStatus;
    }
  }

 /*
  * Log all existing accounts (email and mobile).
  */
  private function logExistingAccounts() {

    $config = array(
      'exchange' => array(
        'name' => getenv("MB_USER_IMPORT_LOGGING_EXCHANGE"),
        'type' => getenv("MB_USER_IMPORT_LOGGING_EXCHANGE_TYPE"),
        'passive' => getenv("MB_USER_IMPORT_LOGGING_EXCHANGE_PASSIVE"),
        'durable' => getenv("MB_USER_IMPORT_LOGGING_EXCHANGE_DURABLE"),
        'auto_delete' => getenv("MB_USER_IMPORT_LOGGING_EXCHANGE_AUTO_DELETE"),
      ),
      'queue' => array(
        'user_import' => array(
          'name' => getenv("MB_USER_IMPORT_LOGGING_QUEUE"),
          'passive' => getenv("MB_USER_IMPORT_LOGGING_QUEUE_PASSIVE"),
          'durable' => getenv("MB_USER_IMPORT_LOGGING_QUEUE_DURABLE"),
          'exclusive' => getenv("MB_USER_IMPORT_LOGGING_QUEUE_EXCLUSIVE"),
          'auto_delete' => getenv("MB_USER_IMPORT_LOGGING_QUEUE_AUTO_DELETE"),
          'bindingKey' => getenv("MB_USER_IMPORT_LOGGING_QUEUE_TOPIC_MB_TRANSACTIONAL_EXCHANGE_PATTERN"),
        ),
      ),
    );
    $mbUserImportLogging = new MessageBroker($this->credentials, $config);

    foreach($this->existingStatus as $existingAccount) {
      $existingAccount['logged'] = time();
      $payload = serialize($existingAccount);
      $mbUserImportLogging->publishMessage($payload);
    }

  }

}
