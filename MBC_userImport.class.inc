<?php

use DoSomething\MB_Toolbox\MB_Toolbox;
use DoSomething\MB_Toolbox\MB_Configuration;
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
   * Current DoSomething member count value.
   *
   * @var array
   */
  private $memberCount;

  /**
   * Campaign details.
   *
   * @var array
   */
  private $campaigns;

  /**
   * Setting from external services - Message Broker Toolbox.
   *
   * @var object
   */
  private $toolbox;

  /**
   * The number of queue entries to process in each session
   */
  const BATCH_SIZE = 5000;

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

    $this->toolbox = new MB_Toolbox($settings);
    $this->memberCount = $this->toolbox->getDSMemberCount();
    $this->statHat = new StatHat($settings['stathat_ez_key'], 'mbc-user-import:');
    $this->statHat->setIsProduction(isset($settings['use_stathat_tracking']) ? $settings['use_stathat_tracking'] : FALSE);

    $this->existingStatus = array();
    $this->campaigns = array();
  }

  /*
   * Consume entries in the MB_USER_IMPORT_QUEUE create entries in other
   * related user creation queues to trigger various related functionality -
   * UserAPI / mb-users record creation, entry in Mailchimp, send transactional
   * "welcome" email message via Mandrill.
   */
  public function produceUserImport() {

    $targetUsers = array();
    $deliveryTags = array();
    $status = FALSE;

    list($targetUsers, $deliveryTags) = $this->consumeUserImportQueue();

    if (count($targetUsers) > 0) {

      foreach ($targetUsers as $userCount => $user) {

        // skip processing empty entries
        if ($user != NULL) {

          // Needed for toolbox->createDrupalUser()
          $user->user_registration_source = $user->source;

          // Ensure first_name is defined for Drupal user creation
          if (isset($user->name) && !isset($user->first_name)) {
            $nameBits = $this->nameBits($user->name);
            $user->first_name = $nameBits['first_name'];
            $user->last_name = $nameBits['last_name'];
          }

          echo 'Source: ' . $user->source, PHP_EOL;
          switch ($user->source) {

            case 'niche':

              // Create Drupal user - https://github.com/DoSomething/dosomething/wiki/API#users
              list($drupalUser, $user->password) = $this->toolbox->createDrupalUser($user);

              if ((is_object($drupalUser[0]) && (!isset($drupalUser[0]->errorInfo))) || is_array($drupalUser[0])) {

                // With new user account details from the Drupal site:
                // - Send transaction to transactionalExchange for distribution to
                //   transactionalQueue, userAPIRegistrationQueue and userRegistrationQueue
                // - could return array rather than user object when user already exists
                //   based on supplied email. Existing Drupal accounts will be logged.
                $status = $this->userImportProducer_niche($user, $drupalUser[0]);

              }
              elseif (isset($drupalUser[0]->errorInfo)) {
                echo 'ERROR - Call to Drupal API to create niche Drupal user has failed - submitted: ' . print_r($user, TRUE), PHP_EOL;
                echo '$drupalUser: ' . print_r($drupalUser, TRUE), PHP_EOL;
                echo 'Database error: ' . print_r($drupalUser[0]->errorInfo, TRUE), PHP_EOL;
                $status = FALSE;
                echo '~~~~~~~~~', PHP_EOL;
              }

              break;

            case 'herCampus':
            case 'hercampus':

              // - [ ] Creation of Drupal user that they can access at a later date by resetting their password.
              // Create Drupal user - https://github.com/DoSomething/dosomething/wiki/API#users
              list($drupalUser, $user->password) = $this->toolbox->createDrupalUser($user);

              // New user produces object, array returned if existing user
              if (is_object($drupalUser[0]) && (!isset($drupalUser[0]->errorInfo))) {

                $drupalUID = $drupalUser[0]->uid;
                $userDetails = array(
                  'email' => $user->email,
                  'uid' => $drupalUID,
                  'subscribed' => 1,
                  'source' => $user->source,
                  'tags' => array(
                    0 => 'user_welcome-' . $user->source,
                  ),
                  'merge_vars' => array(
                    'MEMBER_COUNT' => $this->memberCount,
                    'FNAME' => $user->first_name,
                    'LNAME' => $user->last_name,
                    'PASSWORD' => $user->password,
                  )
                );

                // Send password reset transactional email. Don't do further processing
                // if failure to generate link
                $status = $this->temporaryPasswordTransactional($userDetails);
                $this->statHat->clearAddedStatNames();
                if ($status == TRUE) {
                  $this->statHat->addStatName('Sent user_password-herCampus');
                }
                else {
                  $this->statHat->addStatName('ERROR generating user_password-herCampus');
                  $drupalUID == FALSE;
                }
                $this->statHat->reportCount(1);
              }
              elseif (is_array($drupalUser[0]) && is_string($drupalUser[0][0])) {
                // Email xxx@gmail.com is registered to User uid 2305144.
                $drupalUID = substr($drupalUser[0][0], strpos($drupalUser[0][0], 'uid') + 4);
                $drupalUID = substr($drupalUID, 0, strlen($drupalUID) - 1);
                $user->uid = $drupalUID;
              }
              else {
                echo 'ERROR - Call to Drupal API to create Her Campus Drupal user has failed - submitted: ' . print_r($user, TRUE), PHP_EOL;
                if (isset($drupalUser[0]->errorInfo)) {
                  echo 'Database error: ' . print_r($drupalUser[0]->errorInfo, TRUE), PHP_EOL;
                }
                else {
                  echo '- $drupalUser: ' . print_r($drupalUser[0], TRUE), PHP_EOL;
                }

                // Skip ack_back, process again on next run
                $drupalUID = FALSE;
                $status = FALSE;
                echo '~~~~~~~~~', PHP_EOL;
              }

              if ($drupalUID != FALSE) {

                // Add to Teen for Jeans campaign: 1144
                // Comeback Clothes: 362
                $campaignNID = 362;
                list($campaignDetails, $signup) = $this->campaignSignup($campaignNID, $drupalUID, $user->source);

                // - [ ] An email from DoSomething.org thanking the user for signing
                // up to the campaign
                if (is_object($campaignDetails) && $signup == TRUE) {
                  $user->uid = $drupalUID;
                  $this->campaignSignupTransactional($user, $campaignDetails);
                }
                $status = $this->userImportProducer_herCampus($user, $drupalUser[0]);

              }
              else {
                echo 'drupalUID not defined, skipped campaign signup and userImportProducer_herCampus() processing', PHP_EOL;
              }

              break;

            case 'teenlife':

              // - [ ] Creation of Drupal user that they can access at a later date by resetting their password.
              // Create Drupal user - https://github.com/DoSomething/dosomething/wiki/API#users
              list($drupalUser, $user->password) = $this->toolbox->createDrupalUser($user);

              // New user produces object, array returned if existing user
              if (is_object($drupalUser[0]) && (!isset($drupalUser[0]->errorInfo))) {

                $drupalUID = $drupalUser[0]->uid;
                $userDetails = array(
                  'email' => $user->email,
                  'uid' => $drupalUID,
                  'subscribed' => 1,
                  'source' => $user->source,
                  'tags' => array(
                    0 => 'user_welcome-' . $user->source,
                  ),
                  'merge_vars' => array(
                    'MEMBER_COUNT' => $this->memberCount,
                    'FNAME' => $user->first_name,
                    'LNAME' => $user->last_name,
                    'PASSWORD' => $user->password,
                  )
                );

                // Send password reset transactional email. Don't do further processing
                // if failure to generate link
                $status = $this->temporaryPasswordTransactional($userDetails);
                $this->statHat->clearAddedStatNames();
                if ($status == TRUE) {
                  $this->statHat->addStatName('Sent user_password-teenlife');
                }
                else {
                  $this->statHat->addStatName('ERROR generating user_password-teenlife');
                  $drupalUID == FALSE;
                }
                $this->statHat->reportCount(1);
              }
              elseif (is_array($drupalUser[0]) && is_string($drupalUser[0][0])) {
                // Email xxx@gmail.com is registered to User uid 2305144.
                $drupalUID = substr($drupalUser[0][0], strpos($drupalUser[0][0], 'uid') + 4);
                $drupalUID = substr($drupalUID, 0, strlen($drupalUID) - 1);
                $user->uid = $drupalUID;
              }
              else {
                echo 'ERROR - Call to Drupal API to create Teen Life Drupal user has failed - submitted: ' . print_r($user, TRUE), PHP_EOL;
                if (isset($drupalUser[0]->errorInfo)) {
                  echo 'Database error: ' . print_r($drupalUser[0]->errorInfo, TRUE), PHP_EOL;
                }
                else {
                  echo '- $drupalUser: ' . print_r($drupalUser[0], TRUE), PHP_EOL;
                }

                // Skip ack_back, process again on next run
                $drupalUID = FALSE;
                $status = FALSE;
                echo '~~~~~~~~~', PHP_EOL;
              }

              if ($drupalUID != FALSE) {

                if ($user->member_source != 'teenlife_listing') {
                  if ($user->member_source == '1 in 3 of Us Campaign') {
                    $campaignNID = 5275;
                  }
                  elseif ($user->member_source == 'Backseat Adviser') {
                    $campaignNID = 2366;
                  }
                  elseif ($user->member_source == 'Science Sleuth') {
                    $campaignNID = 3478;
                  }
                  else {
                    echo 'Invalid member_source value in Teen Life import: ' . $user->member_source, PHP_EOL;
                    echo 'Invalid user object: ' . print_r($user), PHP_EOL;
                    break;
                  }
                  list($campaignDetails, $signup) = $this->campaignSignup($campaignNID, $drupalUID, $user->source);

                  // Add MailChimp and Mobile Commons details as currently not returned in Drupal API call for campaign signups.
                  if ($user->member_source == '1 in 3 of Us Campaign') {
                    $campaignDetails->mailchimp_group_name = '1In3OfUs2015';
                    $campaignDetails->mailchimp_grouping_id = 10641;
                    $campaignDetails->mc_opt_in_path_id = 178528;
                  }
                  elseif ($user->member_source == 'Backseat Adviser') {
                    $campaignDetails->mc_opt_in_path_id = 180046;
                  }
                  elseif ($user->member_source == 'Science Sleuth') {
                    // No mc_opt_in_path_id found in Mobile Commons - 23 July 2015
                  }

                  // An email from DoSomething.org thanking the user for signing up to the campaign
                  if ($signup == TRUE) {
                    $user->uid = $drupalUID;
                    $this->campaignSignupTransactional($user, $campaignDetails);
                  }
                }

                // Generate additional messages to trigger related functionality to
                // a user signing up for a campaign
                $status = $this->userImportProducer_teenlife($user, $drupalUser[0]);
              }
              else {
                echo 'drupalUID not defined, skipped campaign signup and userImportProducer_teenlife() processing', PHP_EOL;
              }

              break;

            case 'att-ichannel':

              // Create Drupal user - https://github.com/DoSomething/dosomething/wiki/API#users
              list($drupalUser, $user->password) = $this->toolbox->createDrupalUser($user);

              if ((is_object($drupalUser[0]) && (!isset($drupalUser[0]->errorInfo))) || is_array($drupalUser[0])) {

                // With new user account details from the Drupal site:
                // - Send transaction to transactionalExchange for distribution to
                //   transactionalQueue, userAPIRegistrationQueue and userRegistrationQueue
                // - could return array rather than user object when user already exists
                //   based on supplied email. Existing Drupal accounts will be logged.
                $status = $this->userImportProducer_att_ichannel($user, $drupalUser[0]);

              }
              elseif (!(isset($drupalUser[0][0]) && strpos($drupalUser[0][0], 'is registered') > 0)) {
                echo 'ERROR - Call to Drupal API to create AT&T-iChannel Drupal user has failed - submitted: ' . print_r($user, TRUE), PHP_EOL;
                if (isset($drupalUser[0]->errorInfo)) {
                  echo 'Database error: ' . print_r($drupalUser[0]->errorInfo, TRUE), PHP_EOL;
                }
                $status = FALSE;
                echo '~~~~~~~~~', PHP_EOL;
              }

              break;

            default:
              echo 'ERROR - Invalid source value - submitted: ' . print_r($user, TRUE), PHP_EOL;
              $status = FALSE;

          }

        }

        // Ack message
        if ($status == TRUE) {
          $this->channel->basic_ack($deliveryTags[$userCount]);
          $this->statHat->clearAddedStatNames();
          $this->statHat->addStatName('consumeCSVImport');
          $this->statHat->reportCount(1);
        }

      }

    }
    else {
      echo 'userImportQueue is empty.', PHP_EOL;
    }

    echo '------- mbc-user-import->produceUserImport() END: ' . date('D M j G:i:s T Y') . ' -------', PHP_EOL;
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
      if (is_object($userImportDetails)) {
        $deliveryTags[] = $userImportDetails->delivery_info['delivery_tag'];
        $targetUsers[$processedCount] = json_decode($userImportDetails->body);

        $userImportCount--;
        $processedCount++;
      }
      else {
        echo 'consumeUserImportQueue: ERROR - basic_get failed to get message from: ' . $this->config['queue'][0]['name'], PHP_EOL;
      }

    }

    if (count($targetUsers) > 0) {
      $this->statHat->clearAddedStatNames();
      $this->statHat->addStatName('consumeUserImportQueue');
      $this->statHat->reportCount($processedCount);
      return array($targetUsers, $deliveryTags);
    }
    else {
      echo '------- mbc-user-import MBC_UserImport->consumeUserImportQueue() - Queue is empty. -  ' . date('D M j G:i:s T Y') . ' -------', PHP_EOL;
    }

  }

  /**
   * Send transaction to transactionalExchange with user.registration.import
   * routing key for new users from "niche.com".
   *
   * @param array $user
   *   Details about the user based on the import data.
   * @param array $drupalUser
   *   Details about the Drupal user based on the account created from the API
   *   call to /api/v1/users.
   *
   * @return string $status
   *   Results for queue submission.
   */
  private function userImportProducer_niche($user, $drupalUser) {

    if (( isset($user->email) && isset($drupalUser->uid) && $drupalUser->uid > 0 ) ||
        ( ( is_array($drupalUser) && !isset($drupalUser[0]['errorInfo']) ) && isset($drupalUser[0]) && strpos($drupalUser[0], 'is registered') !== FALSE) ) {

      // Check for existing MailChimp and/or Mobile Commons accounts
      $this->checkExistingAccount($user, $drupalUser);

      // Config specific to producing a new user for the Message Broker userAPI
      $config = array();
      $configSource = __DIR__ . '/messagebroker-config/mb_config.json';
      $mb_config = new MB_Configuration($configSource, $this->settings);
      $transactionalExchange = $mb_config->exchangeSettings('transactionalExchange');

      $config['exchange'] = array(
        'name' => $transactionalExchange->name,
        'type' => $transactionalExchange->type,
        'passive' => $transactionalExchange->passive,
        'durable' => $transactionalExchange->durable,
        'auto_delete' => $transactionalExchange->auto_delete,
      );
      foreach ($transactionalExchange->queues->transactionalQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->transactionalQueue->name,
          'passive' => $transactionalExchange->queues->transactionalQueue->passive,
          'durable' =>  $transactionalExchange->queues->transactionalQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->transactionalQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->transactionalQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      foreach ($transactionalExchange->queues->userAPIRegistrationQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->userAPIRegistrationQueue->name,
          'passive' => $transactionalExchange->queues->userAPIRegistrationQueue->passive,
          'durable' =>  $transactionalExchange->queues->userAPIRegistrationQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->userAPIRegistrationQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->userAPIRegistrationQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      foreach ($transactionalExchange->queues->userRegistrationQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->userRegistrationQueue->name,
          'passive' => $transactionalExchange->queues->userRegistrationQueue->passive,
          'durable' =>  $transactionalExchange->queues->userRegistrationQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->userRegistrationQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->userRegistrationQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      foreach ($transactionalExchange->queues->mobileCommonsQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->mobileCommonsQueue->name,
          'passive' => $transactionalExchange->queues->mobileCommonsQueue->passive,
          'durable' =>  $transactionalExchange->queues->mobileCommonsQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->mobileCommonsQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->mobileCommonsQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      $config['routingKey'] = 'user.registration.transactional';
      $mbUserAPI = new MessageBroker($this->credentials, $config);

      // Required
      $userDetails = array(
        'email' => $user->email,
        'subscribed' => 1,
        'activity' => 'user_welcome-niche',
        'application_id' => 'MUI',
        'user_country' => 'US',
        'user_language' => 'en',
        'email_template' => 'mb-user-welcome-niche-com-v1-0-0-1',
        'mailchimp_list_id' => 'f2fab1dfd4',
        'mc_opt_in_path_id' => 170071,
        'source' => $user->source,
        'tags' => array(
          0 => 'user_welcome-' . $user->source,
        ),
        'merge_vars' => array(
          'MEMBER_COUNT' => $this->memberCount,
        )
      );

      if (isset($drupalUser->uid) && $drupalUser->uid != '') {
        $drupalUID = $drupalUser->uid;
        $userDetails['uid'] = $drupalUID;
      }
      elseif (isset($drupalUser[0])) {
        $drupalUID = substr($drupalUser[0], strpos($drupalUser[0], 'uid') + 4);
        $drupalUID = substr($drupalUID, 0, strlen($drupalUID) - 1);
        $userDetails['uid'] = $drupalUID;
      }
      if (isset($drupalUser->created) && $drupalUser->created) {
        $userDetails['activity_timestamp'] = $drupalUser->created;
      }
      else {
        $userDetails['activity_timestamp'] = time();
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
      if (!(is_array($drupalUser) && strpos($drupalUser[0], 'is registered') !== FALSE)) {
        $status = $this->temporaryPasswordTransactional($userDetails);
        $this->statHat->clearAddedStatNames();
        if ($status == TRUE) {
          $this->statHat->addStatName('Sent user_password-niche');
        }
        else {
          $this->statHat->addStatName('ERROR generating user_password-niche');
        }
        $this->statHat->reportCount(1);
      }
      else {
        $this->statHat->clearAddedStatNames();
        $this->statHat->addStatName('Existing DrupalUser');
        $this->statHat->reportCount(1);
      }
    }
    else {
      if (isset($drupalUser->errorInfo)) {
        echo 'Error processing user: ' . $drupalUser->errorInfo[2], PHP_EOL;
      }
      $status = FALSE;
    }

    return $status;
  }

  /**
   * Send transaction to transactionalExchange with user.registration.import
   * routing key for new users from "Her Campus".
   *
   * [ ] Addition of the email address to MailChimp for future email broadcasts
   * related to the campaign as well as a weekly DS newsletter
   *   mbc-registration-email
   * [ ] Storage of the optional phone number but not sending them a signup
   * SMS message,
   *
   * @param array $user
   *   Details about the user based on the import data.
   * @param array $drupalUser
   *   Details about the Drupal user based on the account created from the API
   *   call to /api/v1/users.
   *
   * @return string $status
   *   Results for queue submission.
   */
  private function userImportProducer_herCampus($user, $drupalUser) {

    if (( isset($user->email) && isset($drupalUser->uid) && $drupalUser->uid > 0 ) ||
        ( ( is_array($drupalUser) && !isset($drupalUser[0]['errorInfo']) ) && isset($drupalUser[0]) && strpos($drupalUser[0], 'is registered') !== FALSE) ) {

      // Check for existing MailChimp and/or Mobile Commons accounts
      $this->checkExistingAccount($user, $drupalUser);

      // Config specific to producing a new user for the Message Broker userAPI
      $config = array();
      $configSource = __DIR__ . '/messagebroker-config/mb_config.json';
      $mb_config = new MB_Configuration($configSource, $this->settings);
      $transactionalExchange = $mb_config->exchangeSettings('transactionalExchange');

      $config['exchange'] = array(
        'name' => $transactionalExchange->name,
        'type' => $transactionalExchange->type,
        'passive' => $transactionalExchange->passive,
        'durable' => $transactionalExchange->durable,
        'auto_delete' => $transactionalExchange->auto_delete,
      );
      foreach ($transactionalExchange->queues->userAPIRegistrationQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->userAPIRegistrationQueue->name,
          'passive' => $transactionalExchange->queues->userAPIRegistrationQueue->passive,
          'durable' =>  $transactionalExchange->queues->userAPIRegistrationQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->userAPIRegistrationQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->userAPIRegistrationQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      foreach ($transactionalExchange->queues->userRegistrationQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->userRegistrationQueue->name,
          'passive' => $transactionalExchange->queues->userRegistrationQueue->passive,
          'durable' =>  $transactionalExchange->queues->userRegistrationQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->userRegistrationQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->userRegistrationQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      foreach ($transactionalExchange->queues->mobileCommonsQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->mobileCommonsQueue->name,
          'passive' => $transactionalExchange->queues->mobileCommonsQueue->passive,
          'durable' =>  $transactionalExchange->queues->mobileCommonsQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->mobileCommonsQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->mobileCommonsQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      $config['routingKey'] = 'user.registration.import';
      $mbUserImport = new MessageBroker($this->credentials, $config);

      // Required
      $userDetails = array(
        'email' => $user->email,
        'subscribed' => 1,
        'activity' => 'user_import',
        'application_id' => 'MUI',
        'user_country' => 'US',
        'user_language' => 'en',
        'source' => $user->source,
        'mailchimp_list_id' => 'f2fab1dfd4',
        'mailchimp_group_name' => 'ComebackClothes2015',
        'mailchimp_grouping_id' => 10641,
        'mc_opt_in_path_id' => 175235
      );

      if (isset($user->first_name) && $user->first_name != '') {
        $userDetails['merge_vars']['FNAME'] = ucfirst($user->first_name);
      }
      if (isset($user->last_name) && $user->last_name != '') {
        $userDetails['merge_vars']['LNAME'] = ucfirst($user->last_name);
      }
      if (isset($user->uid)) {
        $nuserDetails['uid'] = $user->uid;
      }
      if (isset($user->birthdate_timestamp)) {
        $userDetails['birthdate_timestamp'] = $user->birthdate_timestamp;
      }
      if (isset($user->phone)) {
        $userDetails['mobile'] = $user->phone;
      }

      $payload = serialize($userDetails);
      $mbUserImport->publishMessage($payload);
      $status = TRUE;
    }
    else {
      $status = FALSE;
    }

    return $status;
  }

  /**
   * Send transaction to transactionalExchange with user.registration.import
   * routing key for new users from "Teen Life".
   *
   * [ ] Addition of the email address to MailChimp for future email broadcasts
   * related to the campaign as well as a weekly DS newsletter
   *   mbc-registration-email
   * [ ] Storage of the optional phone number but not sending them a signup
   * SMS message,
   *
   * @param array $user
   *   Details about the user based on the import data.
   * @param array $drupalUser
   *   Details about the Drupal user based on the account created from the API
   *   call to /api/v1/users.
   *
   * @return string $status
   *   Results for queue submission.
   */
  private function userImportProducer_teenlife($user, $drupalUser) {

    if (( isset($user->email) && isset($drupalUser->uid) && $drupalUser->uid > 0 ) ||
        ( ( is_array($drupalUser) && !isset($drupalUser[0]['errorInfo']) ) && isset($drupalUser[0]) && strpos($drupalUser[0], 'is registered') !== FALSE) ) {

      // Check for existing MailChimp and/or Mobile Commons accounts
      $this->checkExistingAccount($user, $drupalUser);

     // Config specific to producing a new user for the Message Broker userAPI
      $config = array();
      $configSource = __DIR__ . '/messagebroker-config/mb_config.json';
      $mb_config = new MB_Configuration($configSource, $this->settings);
      $transactionalExchange = $mb_config->exchangeSettings('transactionalExchange');

      $config['exchange'] = array(
        'name' => $transactionalExchange->name,
        'type' => $transactionalExchange->type,
        'passive' => $transactionalExchange->passive,
        'durable' => $transactionalExchange->durable,
        'auto_delete' => $transactionalExchange->auto_delete,
      );
      foreach ($transactionalExchange->queues->userAPIRegistrationQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->userAPIRegistrationQueue->name,
          'passive' => $transactionalExchange->queues->userAPIRegistrationQueue->passive,
          'durable' =>  $transactionalExchange->queues->userAPIRegistrationQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->userAPIRegistrationQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->userAPIRegistrationQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      foreach ($transactionalExchange->queues->userRegistrationQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->userRegistrationQueue->name,
          'passive' => $transactionalExchange->queues->userRegistrationQueue->passive,
          'durable' =>  $transactionalExchange->queues->userRegistrationQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->userRegistrationQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->userRegistrationQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      foreach ($transactionalExchange->queues->mobileCommonsQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->mobileCommonsQueue->name,
          'passive' => $transactionalExchange->queues->mobileCommonsQueue->passive,
          'durable' =>  $transactionalExchange->queues->mobileCommonsQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->mobileCommonsQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->mobileCommonsQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }

      // Required
      $userDetails = array(
        'email' => $user->email,
        'subscribed' => 1,
        'activity' => 'user_import',
        'application_id' => 'MUI',
        'user_country' => 'US',
        'user_language' => 'en',
        'source' => $user->source,
        'mailchimp_list_id' => 'f2fab1dfd4',
        'mailchimp_grouping_id' => 10641,
        'mc_opt_in_path_id' => 175235,
      );

      if ($user->member_source == 'Backseat Adviser') {
        $config['routingKey'] = 'user.registration.import';
      }
      elseif ($user->member_source == 'Science Sleuth') {
        $config['routingKey'] = 'user.registration.import';
      }
      else {
        $userDetails['email_template'] = 'mb-user-welcome-teenlife-com-v1-0-0';
        $userDetails['activity'] = 'user_welcome-teenlife';
        $userDetails['tags'] = array(
          0 => 'user_welcome-' . $user->source,
        );
        $userDetails['merge_vars'] = array(
          'MEMBER_COUNT' => $this->memberCount,
        );

        foreach ($transactionalExchange->queues->transactionalQueue->binding_patterns as $bindingCount => $bindingKey) {
          $config['queue'][$bindingCount] = array(
            'name' => $transactionalExchange->queues->transactionalQueue->name,
            'passive' => $transactionalExchange->queues->transactionalQueue->passive,
            'durable' =>  $transactionalExchange->queues->transactionalQueue->durable,
            'exclusive' =>  $transactionalExchange->queues->transactionalQueue->exclusive,
            'auto_delete' =>  $transactionalExchange->queues->transactionalQueue->auto_delete,
            'bindingKey' => $bindingKey,
          );
        }
        $config['routingKey'] = ' user.registration.transactional';
      }
      $mbUserImport = new MessageBroker($this->credentials, $config);

      if (isset($user->first_name) && $user->first_name != '') {
        $userDetails['merge_vars']['FNAME'] = ucfirst($user->first_name);
      }
      if (isset($user->last_name) && $user->last_name != '') {
        $userDetails['merge_vars']['LNAME'] = ucfirst($user->last_name);
      }
      if (isset($user->uid)) {
        $nuserDetails['uid'] = $user->uid;
      }
      if (isset($user->birthdate_timestamp)) {
        $userDetails['birthdate_timestamp'] = $user->birthdate_timestamp;
      }
      if (isset($user->phone)) {
        $userDetails['mobile'] = $user->phone;
      }

      $payload = serialize($userDetails);
      $mbUserImport->publishMessage($payload);
      $status = TRUE;
    }
    else {
      $status = FALSE;
    }

    return $status;
  }

  /**
   * Send transaction to transactionalExchange with user.registration.import
   * routing key for new users from "AT&T - iChannel".
   *
   * @param array $user
   *   Details about the user based on the import data.
   * @param array $drupalUser
   *   Details about the Drupal user based on the account created from the API
   *   call to /api/v1/users.
   *
   * @return string $status
   *   Results for queue submission.
   */
  private function userImportProducer_att_ichannel($user, $drupalUser) {

    if (( isset($user->email) && isset($drupalUser->uid) && $drupalUser->uid > 0 ) ||
        ( ( is_array($drupalUser) && !isset($drupalUser['errorInfo']) ) && isset($drupalUser[0]) && strpos($drupalUser[0], 'is registered') !== FALSE) ) {

      // Check for existing MailChimp and/or Mobile Commons accounts
      $this->checkExistingAccount($user, $drupalUser);

      // Config specific to producing a new user for the Message Broker userAPI
      $config = array();
      $configSource = __DIR__ . '/messagebroker-config/mb_config.json';
      $mb_config = new MB_Configuration($configSource, $this->settings);
      $transactionalExchange = $mb_config->exchangeSettings('transactionalExchange');

      $config['exchange'] = array(
        'name' => $transactionalExchange->name,
        'type' => $transactionalExchange->type,
        'passive' => $transactionalExchange->passive,
        'durable' => $transactionalExchange->durable,
        'auto_delete' => $transactionalExchange->auto_delete,
      );
      foreach ($transactionalExchange->queues->transactionalQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->transactionalQueue->name,
          'passive' => $transactionalExchange->queues->transactionalQueue->passive,
          'durable' =>  $transactionalExchange->queues->transactionalQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->transactionalQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->transactionalQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      foreach ($transactionalExchange->queues->userAPIRegistrationQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->userAPIRegistrationQueue->name,
          'passive' => $transactionalExchange->queues->userAPIRegistrationQueue->passive,
          'durable' =>  $transactionalExchange->queues->userAPIRegistrationQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->userAPIRegistrationQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->userAPIRegistrationQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      foreach ($transactionalExchange->queues->userRegistrationQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->userRegistrationQueue->name,
          'passive' => $transactionalExchange->queues->userRegistrationQueue->passive,
          'durable' =>  $transactionalExchange->queues->userRegistrationQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->userRegistrationQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->userRegistrationQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      foreach ($transactionalExchange->queues->mobileCommonsQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->mobileCommonsQueue->name,
          'passive' => $transactionalExchange->queues->mobileCommonsQueue->passive,
          'durable' =>  $transactionalExchange->queues->mobileCommonsQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->mobileCommonsQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->mobileCommonsQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      $config['routingKey'] = 'user.registration.transactional';
      $mbUserAPI = new MessageBroker($this->credentials, $config);

      // Required
      $userDetails = array(
        'email' => $user->email,
        'subscribed' => 1,
        'subscriptions' => array(
          'mailchimp' => 1
        ),
        'activity' => 'user_welcome-att-ichannel',
        'application_id' => 'MUI',
        'user_country' => 'US',
        'user_language' => 'en',
        'email_template' => 'mb-user-welcome-attichannel-v1-0-0',
        'mailchimp_list_id' => 'f2fab1dfd4',
        'mc_opt_in_path_id' => 177266,
        'source' => $user->source,
        'tags' => array(
          0 => 'user_welcome-' . $user->source,
        ),
        'merge_vars' => array(
          'MEMBER_COUNT' => $this->memberCount,
        )
      );

      if (isset($drupalUser->uid) && $drupalUser->uid != '') {
        $drupalUID = $drupalUser->uid;
        $userDetails['uid'] = $drupalUID;
      }
      elseif (isset($drupalUser[0])) {
        $drupalUID = substr($drupalUser[0], strpos($drupalUser[0], 'uid') + 4);
        $drupalUID = substr($drupalUID, 0, strlen($drupalUID) - 1);
        $userDetails['uid'] = $drupalUID;
      }
      if (isset($drupalUser->created) && $drupalUser->created) {
        $userDetails['activity_timestamp'] = $drupalUser->created;
      }
      else {
        $userDetails['activity_timestamp'] = time();
      }
      if (isset($user->birthdate) && $user->birthdate != 0) {
        $userDetails['birthdate_timestamp'] = strtotime($user->birthdate);
      }

      if (isset($user->first_name) && $user->first_name != '') {
        $userDetails['merge_vars']['FNAME'] = $user->first_name;
      }
      if (isset($user->last_name) && $user->last_name != '') {
        $userDetails['merge_vars']['LNAME'] = $user->last_name;
      };
      if (isset($user->password) && $user->password != '') {
        $userDetails['merge_vars']['PASSWORD'] = $user->password;
      }
      if (isset($user->phone) && $user->phone != '') {
        $userDetails['mobile'] = $user->phone;
      }
      $payload = serialize($userDetails);
      $mbUserAPI->publishMessage($payload);
      $status = TRUE;

      $this->statHat->clearAddedStatNames();
      $this->statHat->addStatName('Distribute user import - user.registration.transactional');
      $this->statHat->reportCount(1);

      // Create second transactional email message to inform the user of their new DoSomething account
      // with password reset link. Config specific to producing a new user for the Message Broker userAPI
      // Skip if the Drupal user account already existed
      if (!(is_array($drupalUser) && strpos($drupalUser[0], 'is registered') !== FALSE)) {
        $status = $this->temporaryPasswordTransactional($userDetails);
        $this->statHat->clearAddedStatNames();
        if ($status == TRUE) {
          $this->statHat->addStatName('Sent user_password-att_ichannel');
        }
        else {
          $this->statHat->addStatName('ERROR generating user_password-att_ichanne');
        }
        $this->statHat->reportCount(1);
      }
      else {
        $this->statHat->clearAddedStatNames();
        $this->statHat->addStatName('Existing DrupalUser');
        $this->statHat->reportCount(1);
      }

    }
    else {
      if (isset($drupalUser->errorInfo)) {
        echo 'Error processing user: ' . $drupalUser->errorInfo[2], PHP_EOL;
      }
      $status = FALSE;
    }

    return $status;
  }

  /**
   * Send transactional email to provide details on temporaty password assigned
   * to newly created Drupal user account.
   *
   * @param array $userDetails
   *   Payload details to be sent with trnsactional message.
   */
  private function temporaryPasswordTransactional($userDetails) {

    $passwordResetURL = $this->toolbox->getPasswordResetURL($userDetails['uid']);
    if ($passwordResetURL != NULL) {

      $config = array();
      $configSource = __DIR__ . '/messagebroker-config/mb_config.json';
      $mb_config = new MB_Configuration($configSource, $this->settings);
      $transactionalExchange = $mb_config->exchangeSettings('transactionalExchange');

      $config['exchange'] = array(
        'name' => $transactionalExchange->name,
        'type' => $transactionalExchange->type,
        'passive' => $transactionalExchange->passive,
        'durable' => $transactionalExchange->durable,
        'auto_delete' => $transactionalExchange->auto_delete,
      );
      foreach ($transactionalExchange->queues->transactionalQueue->binding_patterns as $bindingCount => $bindingKey) {
        $config['queue'][$bindingCount] = array(
          'name' => $transactionalExchange->queues->transactionalQueue->name,
          'passive' => $transactionalExchange->queues->transactionalQueue->passive,
          'durable' =>  $transactionalExchange->queues->transactionalQueue->durable,
          'exclusive' =>  $transactionalExchange->queues->transactionalQueue->exclusive,
          'auto_delete' =>  $transactionalExchange->queues->transactionalQueue->auto_delete,
          'bindingKey' => $bindingKey,
        );
      }
      $config['routingKey'] = 'user.password.transactional';

      $mbUserAPI = new MessageBroker($this->credentials, $config);

      $userDetails['merge_vars']['PASSWORD_RESET_LINK'] = $passwordResetURL;
      $userDetails['activity'] = 'user_password-' . $userDetails['source'];
      $userDetails['email_template'] = 'mb-user-signup-niche-com-v1-0-0-1';
      $userDetails['tags'][0] = 'user_password-' . $userDetails['source'];
      $payload = serialize($userDetails);
      $mbUserAPI->publishMessage($payload);

      return TRUE;
    }
    else {
      return FALSE;
    }

  }

  /**
   * Sign up user ID (UID) to campaign.
   *
   * https://github.com/DoSomething/dosomething/wiki/API#campaign-signup
   * https://www.dosomething.org/api/v1/campaigns/[nid]/signup
   *
   * @param integer $campaignNID
   *   The unique node ID from the Drupal site that identifies the target
   *   campaign to sign the user up to.
   * @param array $drupalUID
   *   The user ID (UID) of the Drupal user account to create a campaign
   *   signup for.
   * @param string $source
   *   The name of the import source
   *
   * @return string $status
   *   Results for transactional submission.
   */
  private function campaignSignup($campaignNID, $drupalUID, $source) {

    // Lookup campaign details
    $campaignDetails = $this->campaignLookup($campaignNID);

    if ( $campaignDetails != FALSE) {
      $post = array(
        'source' => $source . '_mb_import',
        'uid' => $drupalUID
      );
      $curlUrl = $this->settings['ds_drupal_api_host'];
      $port = $this->settings['ds_drupal_api_port'];
      if ($port != 0) {
        $curlUrl .= ":$port";
      }
      $curlUrl .= '/api/v1/campaigns/' . $campaignNID . '/signup';
      $signUp = $this->toolbox->curlPOSTauth($curlUrl, $post);
    }

    // Results found for campaign signup
    if (is_array($signUp[0]) && $signUp[0][0] > 0) {
      $signUp = TRUE;
    } // Invalid ID or closed campaign
    else {
      echo 'Drupal UID: ' . $drupalUID . ' may already be signed up for campaign ' . $campaignNID . ' or campaign is not accepting signups.' . $signUp[0][0], PHP_EOL;
      $signUp = FALSE;
    }

    return array($campaignDetails, $signUp);
  }

  /**
   * Send transactional email confirming the user has signed up for a campaign.
   *
   * @param int $campaignNID
   *   The target campaign node ID (NID) as defined in the Drupal site.
   *
   * @return string $status
   *   Results for transactional submission.
   */
  private function campaignLookup($campaignNID) {

    // Store campaign details to prevent flooding Drupal API
    if (!isset($this->campaigns[$campaignNID])) {

      // Lookup campaign details after successful signup
      // https://www.dosomething.org/api/v1/content/:nid
      $curlUrl = $this->settings['ds_drupal_api_host'];
      $port = $this->settings['ds_drupal_api_port'];
      if ($port != 0) {
        $curlUrl .= ":$port";
      }
      $curlUrl .= '/api/v1/content/' . $campaignNID;
      $campaignDetails = $this->toolbox->curlGET($curlUrl);
      if (!is_object($campaignDetails[0])) {
        echo 'ERROR - MBC_userImport->campaignSignup: Target campaign "' . $campaignNID . '" not found with /api/v1/content/' . $campaignNID . ' call. Error code: ' . $campaignDetails[1], PHP_EOL;
        $campaignDetails = FALSE;
      }
      else {
        $campaignDetails = $campaignDetails[0];
        $this->campaigns[$campaignNID] = $campaignDetails;
      }

    }
    else {
      $campaignDetails = $this->campaigns[$campaignNID];
    }

    return $campaignDetails;
  }

  /**
   * Send transactional email confirming the user has signed up for a campaign.
   *
   * @param array $userDetails
   *   Details about the user account that has signed up for a campaign.
   * @param array $campaignDetails
   *   .
   * @return string $status
   *   Results for transactional submission.
   */
  private function campaignSignupTransactional($userDetails, $campaignDetails) {

    $signupDetails = array(
      'activity' => 'campaign_signup',
      'application_id' => 'MUI',
      'user_country' => 'US',
      'user_language' => 'en',
      'email' => $userDetails->email,
      'uid' => $userDetails->uid,
      'subscribed' => 1,
      'event_id' => $campaignDetails->nid,
      'activity_timestamp' => time(),
      'application_id' => 200,
      'source' => $userDetails->source
    );

    if (isset($userDetails->birthdate) && !is_int($userDetails->birthdate)) {
      $signupDetails['birthdate_timestamp'] = strtotime($userDetails->birthdate);
    }

    if ($campaignDetails->type != 'sms_game') {
      $signupDetails['merge_vars'] = array(
        'MEMBER_COUNT' => $this->memberCount,
        'FNAME' => $userDetails->first_name,
        'CAMPAIGN_TITLE' => $campaignDetails->title,
        'CAMPAIGN_LINK' => 'http://www.dosomething.org/node/' . $campaignDetails->nid,
        'CALL_TO_ACTION' => $campaignDetails->call_to_action,
        'STEP_ONE' => $campaignDetails->pre_step_header,
        'STEP_TWO' => 'Snap a Pic', // dosomething_campaigns.module, ln 10, define('DOSOMETHING_CAMPAIGN_PIC_STEP_HEADER', 'Snap a Pic');
        'STEP_THREE' => $campaignDetails->post_step_header,
      );
      $signupDetails['email_tags'] = array(
        0 => $campaignDetails->nid,
        1 => 'campaign_signup-import-' . $userDetails->source,
      );
      $signupDetails['email_template'] = 'mb-campaign-signup';
      $signupDetails['mailchimp_group_name'] = $campaignDetails->mailchimp_group_name;
      $signupDetails['mailchimp_grouping_id'] = $campaignDetails->mailchimp_grouping_id;
    }
    elseif (isset($userDetails->mobile_number)) {
      $signupDetails['mobile_number'] = $userDetails->mobile_number;
      $signupDetails['merge_vars'] = array(
        'FNAME' => $userDetails->first_name,
        'LNAME' => $userDetails->last_name,
      );
      $signupDetails['zip'] = $userDetails->zip_code;
      $signupDetails['mc_opt_in_path_id'] = $campaignDetails->mc_opt_in_path_id;
    }

    $config = array();
    $configSource = __DIR__ . '/messagebroker-config/mb_config.json';
    $mb_config = new MB_Configuration($configSource, $this->settings);
    $transactionalExchange = $mb_config->exchangeSettings('transactionalExchange');

    $config['exchange'] = array(
      'name' => $transactionalExchange->name,
      'type' => $transactionalExchange->type,
      'passive' => $transactionalExchange->passive,
      'durable' => $transactionalExchange->durable,
      'auto_delete' => $transactionalExchange->auto_delete,
    );
    foreach ($transactionalExchange->queues->transactionalQueue->binding_patterns as $bindingCount => $bindingKey) {
      $config['queue'][$bindingCount] = array(
        'name' => $transactionalExchange->queues->transactionalQueue->name,
        'passive' => $transactionalExchange->queues->transactionalQueue->passive,
        'durable' =>  $transactionalExchange->queues->transactionalQueue->durable,
        'exclusive' =>  $transactionalExchange->queues->transactionalQueue->exclusive,
        'auto_delete' =>  $transactionalExchange->queues->transactionalQueue->auto_delete,
        'bindingKey' => $bindingKey,
      );
    }
    foreach ($transactionalExchange->queues->mailchimpCampaignSignupQueue->binding_patterns as $bindingCount => $bindingKey) {
      $config['queue'][$bindingCount] = array(
        'name' => $transactionalExchange->queues->mailchimpCampaignSignupQueue->name,
        'passive' => $transactionalExchange->queues->mailchimpCampaignSignupQueue->passive,
        'durable' =>  $transactionalExchange->queues->mailchimpCampaignSignupQueue->durable,
        'exclusive' =>  $transactionalExchange->queues->mailchimpCampaignSignupQueue->exclusive,
        'auto_delete' =>  $transactionalExchange->queues->mailchimpCampaignSignupQueue->auto_delete,
        'bindingKey' => $bindingKey,
      );
    }
    foreach ($transactionalExchange->queues->userAPICampaignActivityQueue->binding_patterns as $bindingCount => $bindingKey) {
      $config['queue'][$bindingCount] = array(
        'name' => $transactionalExchange->queues->userAPICampaignActivityQueue->name,
        'passive' => $transactionalExchange->queues->userAPICampaignActivityQueue->passive,
        'durable' =>  $transactionalExchange->queues->userAPICampaignActivityQueue->durable,
        'exclusive' =>  $transactionalExchange->queues->userAPICampaignActivityQueue->exclusive,
        'auto_delete' =>  $transactionalExchange->queues->userAPICampaignActivityQueue->auto_delete,
        'bindingKey' => $bindingKey,
      );
    }
    $config['routingKey'] = 'campaign.signup.transactional';

    $mbCampaignSignup = new MessageBroker($this->credentials, $config);

    $payload = serialize($signupDetails);
    $mbCampaignSignup->publishMessage($payload);
  }

 /*
  * Check for the existence of email (Mailchimp) and mobile (Mobile Commons)
  * accounts.
  *
  * @param array $user
  *   Information about a user to be imported into the DoSomething systems.
  * @param array $drupalUser
  *   An object or an array if a new Drupal user account was not created
  */
  private function checkExistingAccount($user, $drupalUser) {

    // Log existing Drupal user
    if (is_array($drupalUser)) {
      $drupalUIDPosition = strpos($drupalUser[0], 'User uid') + 9;
      $drupalUID =  substr($drupalUser[0], $drupalUIDPosition, strlen($drupalUser[0]) - $drupalUIDPosition - 1);
      $drupalEmail = substr($drupalUser[0], strpos($drupalUser[0], 'mail') + 5, (strpos($drupalUser[0], 'is') - 1) - (strpos($drupalUser[0], 'mail') + 5));
      $this->existingStatus['drupal-uid'] = $drupalUID;
      $this->existingStatus['drupal-email'] = $drupalEmail;
      echo('Already a Drupal user: ' . $drupalEmail . ' ('. $drupalUID . ') user.' . PHP_EOL);

      $this->statHat->clearAddedStatNames();
      $this->statHat->addStatName('Drupal - existing account');
      $this->statHat->reportCount(1);
    }

    $this->statHat->clearAddedStatNames();

    try {

      // http://apidocs.mailchimp.com/api/2.0/lists/member-info.php
      $MailChimp = new \Drewm\MailChimp($this->settings['mailchimp_apikey']);
      $mailchimpStatus = $MailChimp->call("/lists/member-info", array(
        'id' => $this->settings['mailchimp_list_id'],
        'emails' => array(
          0 => array(
            'email' => $user->email
          )
        )
      ));

      if (isset($mailchimpStatus['data']) && count($mailchimpStatus['data']) > 0) {
        echo($user->email . ' already a Mailchimp user.' . PHP_EOL);
        $this->existingStatus['email-status'] = 'Existing account';
        $this->existingStatus['email'] = $user->email;
        $this->existingStatus['email-acquired'] = $mailchimpStatus['data'][0]['timestamp'];
        $this->statHat->addStatName('Mailchimp - existing account');
        $this->statHat->reportCount(1);
      }
      elseif ($mailchimpStatus == FALSE) {
        $this->existingStatus['email-status'] = 'Mailchimp Error';
        $this->existingStatus['email'] = $user->email;
        $this->statHat->addStatName('Mailchimp - error');
        $this->statHat->reportCount(1);
      }

    }
    catch (Exception $e) {
      trigger_error('mbc-user-import ERROR - Failed to submit "/lists/member-info" to Mailchimp API for ' . $user->email . '.', E_USER_WARNING);
      $this->statHat->addStatName('checkExistingAccount Mailchimp /lists/member-info  error');
    }

    if (isset($user->phone)) {

      try {

        $this->statHat->clearAddedStatNames();
        // https://mobilecommons.zendesk.com/hc/en-us/articles/202052534-REST-API#ProfileSummary
        $config = array(
          'username' => $this->settings['mobile_commons_username'],
          'password' => $this->settings['mobile_commons_password'],
        );
        $MobileCommons = new MobileCommons($config);
        $mobilecommonsStatus = (array)$MobileCommons->profiles_get(array('phone_number' => $user->phone));
        if (!isset($mobilecommonsStatus['error'])) {
          echo($user->phone . ' already a Mobile Commons user.' . PHP_EOL);
          if (isset($mobilecommonsStatus['profile']->status)) {
            $this->existingStatus['mobile-error'] = (string)$mobilecommonsStatus['profile']->status;

            // opted_out_source

            $this->existingStatus['mobile-acquired'] = (string)$mobilecommonsStatus['profile']->created_at;
          }
          else {
            $this->existingStatus['mobile-error'] = 'Existing account';
          }
          $this->existingStatus['mobile'] = $user->phone;
          $this->statHat->addStatName('Mobile Commons - existing account');
          $this->statHat->reportCount(1);
        }
        else {
          $mobileCommonsError = $mobilecommonsStatus['error']->attributes()->{'message'};

          // via Mobile Commons API - "Invalid phone number" aka "number not found", the number is not from an existing user.
          if (!$mobileCommonsError == 'Invalid phone number') {
            echo 'Mobile Common Error: ' . $mobileCommonsError, PHP_EOL;
            $this->statHat->addStatName('Mobile Commons Error - ' . $mobileCommonsError);
            $this->statHat->reportCount(1);
          }
        }

      }
      catch (Exception $e) {
        trigger_error('mbc-user-import ERROR - Failed to submit "profiles_get" to Mobile Commons API for ' . $user->phone . '.', E_USER_WARNING);
        $this->statHat->addStatName('checkExistingAccount Mobile Commons profiles_get()  error');
      }
    }

    // Existing account details found, include log entry basics
    if (isset($this->existingStatus) && count($this->existingStatus) > 0) {
      // @todo: Add logic to support different log-type values when other
      // sources for users are used
      $this->existingStatus['log-type'] = 'user-import-' . $user->source;
      $this->existingStatus['log-timestamp'] = time();
      $this->logExistingAccounts($user->source, $user->source_file);
    }

    echo('------' . PHP_EOL . PHP_EOL);
  }

 /*
  * Log existing account (email and mobile). Create log entry as they're
  * encountered to ensure logging even if the application crashes.
  *
  * @param string $source
  *   The siurce of the user data.
  * @param string $origin
  *   THe file that the user import is generated from.
  */
  private function logExistingAccounts($source, $origin) {

    $configSource = __DIR__ . '/messagebroker-config/mb_config.json';
    $mbConfig = new MB_Configuration($configSource, $this->settings);
    $userImportExistingLoggingExchange = $mbConfig->exchangeSettings('directUserImportExistingLogging');

    $config = array(
      'exchange' => array(
        'name' => $userImportExistingLoggingExchange->name,
        'type' => $userImportExistingLoggingExchange->type,
        'passive' => $userImportExistingLoggingExchange->passive,
        'durable' => $userImportExistingLoggingExchange->durable,
        'auto_delete' => $userImportExistingLoggingExchange->auto_delete,
      ),
      'queue' => array(
        array(
          'name' => $userImportExistingLoggingExchange->queues->userImportExistingLoggingQueue->name,
          'passive' => $userImportExistingLoggingExchange->queues->userImportExistingLoggingQueue->passive,
          'durable' =>  $userImportExistingLoggingExchange->queues->userImportExistingLoggingQueue->durable,
          'exclusive' =>  $userImportExistingLoggingExchange->queues->userImportExistingLoggingQueue->exclusive,
          'auto_delete' =>  $userImportExistingLoggingExchange->queues->userImportExistingLoggingQueue->auto_delete,
          'bindingKey' => $userImportExistingLoggingExchange->queues->userImportExistingLoggingQueue->binding_key,
        ),
      ),
    );
    $config['routingKey'] = $userImportExistingLoggingExchange->queues->userImportExistingLoggingQueue->routing_key;

    $mbEistingUserImportLogging = new MessageBroker($this->credentials, $config);

    $this->existingStatus['source'] = $source;
    $this->existingStatus['origin'] = array(
      'name' => $origin,
      'processed' => time(),
    );

    $payload = serialize($this->existingStatus);
    $mbEistingUserImportLogging->publishMessage($payload);

    // Reset for next round
    $this->existingStatus = array();
  }

 /*
  * Utility method - Converts full name to first and last name.
  *
  * @param string $fullName
  *   The full name to break a part based on spaces in the name.
  * @return array $nameBits
  *   The first and last names created from the supplied full name.
  */
  private function nameBits($fullName) {

    $names = explode(' ', $fullName);
    $nameBits['last_name'] = ucwords($names[count($names) - 1]);
    unset($names[count($names) - 1]);
    $nameBits['first_name'] = ucwords(implode(' ', $names));

    return $nameBits;
  }

}
