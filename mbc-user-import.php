<?php
/**
 * mbc-user-import.php
 *
 * Consume queue entries in userImportQueue to import user data supplied by
 * niche.com of users interested in DoSomething scholarships.
 *
 * Each entry will result in:
 *   - User creation in the Drupal website
 *   - An entry in mb-users via userAPI
 *   - Mailchimp entry
 *   - Mandrill transactional signup email message
 */

date_default_timezone_set('America/New_York');

// Load up the Composer autoload magic
require_once __DIR__ . '/vendor/autoload.php';
use DoSomething\MB_Toolbox\MB_Configuration;

// Load configuration settings common to the Message Broker system
// symlinks in the project directory point to the actual location of the files
// Load configuration settings common to the Message Broker system
// symlinks in the project directory point to the actual location of the files
require_once __DIR__ . '/messagebroker-config/mb-secure-config.inc';
require_once __DIR__ . '/MBC_userImport.class.inc';

// Settings
$credentials = array(
  'host' =>  getenv("RABBITMQ_HOST"),
  'port' => getenv("RABBITMQ_PORT"),
  'username' => getenv("RABBITMQ_USERNAME"),
  'password' => getenv("RABBITMQ_PASSWORD"),
  'vhost' => getenv("RABBITMQ_VHOST"),
);
$settings = array(
  'mailchimp_apikey' => getenv("MAILCHIMP_APIKEY"),
  'mailchimp_list_id' => getenv("MAILCHIMP_LIST_ID"),
  'mobile_commons_username' => getenv("MOBILE_COMMONS_USER"),
  'mobile_commons_password' => getenv("MOBILE_COMMONS_PASSWORD"),
  'stathat_ez_key' => getenv("STATHAT_EZKEY"),
  'use_stathat_tracking' => getenv("USE_STAT_TRACKING"),
  'ds_drupal_api_host' => getenv("DS_DRUPAL_API_HOST"),
  'ds_drupal_api_port' => getenv("DS_DRUPAL_API_PORT"),
  'ds_drupal_api_username' => getenv("DS_DRUPAL_API_USERNAME"),
  'ds_drupal_api_password' => getenv("DS_DRUPAL_API_PASSWORD"),
);

$config = array();
$source = __DIR__ . '/messagebroker-config/mb_config.json';
$mb_config = new MB_Configuration($source, $settings);
$userImportExchange = $mb_config->exchangeSettings('directUserImport');

$config = array(
  'exchange' => array(
    'name' => $userImportExchange->name,
    'type' => $userImportExchange->type,
    'passive' => $userImportExchange->passive,
    'durable' => $userImportExchange->durable,
    'auto_delete' => $userImportExchange->auto_delete,
  ),
  'queue' => array(
    array(
      'name' => $userImportExchange->queues->userImportQueue->name,
      'passive' => $userImportExchange->queues->userImportQueue->passive,
      'durable' =>  $userImportExchange->queues->userImportQueue->durable,
      'exclusive' =>  $userImportExchange->queues->userImportQueue->exclusive,
      'auto_delete' =>  $userImportExchange->queues->userImportQueue->auto_delete,
      'bindingKey' => $userImportExchange->queues->userImportQueue->binding_key,
    ),
  ),
  'consume' => array(
    'consumer_tag' => $userImportExchange->queues->userImportQueue->consume->tag,
    'no_local' => $userImportExchange->queues->userImportQueue->consume->no_local,
    'no_ack' => $userImportExchange->queues->userImportQueue->consume->no_ack,
    'exclusive' => $userImportExchange->queues->userImportQueue->consume->exclusive,
    'nowait' => $userImportExchange->queues->userImportQueue->consume->nowait,
  ),
  'routingKey' => $userImportExchange->queues->userImportQueue->routing_key,
);


echo '------- mbc-user-import START: ' . date('D M j G:i:s T Y') . ' -------', PHP_EOL;

// Kick off
// Create entries in userImportQueue based on csv.
$mbcUserImport = new MBC_UserImport($credentials, $config, $settings);
$mbcUserImport->produceUserImport();

echo '------- mbc-user-import END: ' . date('D M j G:i:s T Y') . ' -------', PHP_EOL;
