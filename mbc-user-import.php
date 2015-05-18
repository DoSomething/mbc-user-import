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

// Load configuration settings common to the Message Broker system
// symlinks in the project directory point to the actual location of the files
require __DIR__ . '/mb-secure-config.inc';
require __DIR__ . '/mb-config.inc';

require __DIR__ . '/MBC_userImport.class.inc';

// Settings
$credentials = array(
  'host' =>  getenv("RABBITMQ_HOST"),
  'port' => getenv("RABBITMQ_PORT"),
  'username' => getenv("RABBITMQ_USERNAME"),
  'password' => getenv("RABBITMQ_PASSWORD"),
  'vhost' => getenv("RABBITMQ_VHOST"),
);

$config = array(
  'exchange' => array(
    'name' => getenv("MB_USER_IMPORT_EXCHANGE"),
    'type' => getenv("MB_USER_IMPORT_EXCHANGE_TYPE"),
    'passive' => getenv("MB_USER_IMPORT_EXCHANGE_PASSIVE"),
    'durable' => getenv("MB_USER_IMPORT_EXCHANGE_DURABLE"),
    'auto_delete' => getenv("MB_USER_IMPORT_EXCHANGE_AUTO_DELETE"),
  ),
  'queue' => array(
    array(
      'name' => getenv("MB_USER_IMPORT_QUEUE"),
      'passive' => getenv("MB_USER_IMPORT_QUEUE_PASSIVE"),
      'durable' => getenv("MB_USER_IMPORT_QUEUE_DURABLE"),
      'exclusive' => getenv("MB_USER_IMPORT_QUEUE_EXCLUSIVE"),
      'auto_delete' => getenv("MB_USER_IMPORT_QUEUE_AUTO_DELETE"),
      'bindingKey' => getenv("MB_USER_IMPORT_QUEUE_BINDING_KEY"),
    ),
  ),
  'consume' => array(
    'consumer_tag' => getenv("MB_USER_IMPORT_CONSUME_TAG"),
    'no_local' => getenv("MB_USER_IMPORT_CONSUME_NO_LOCAL"),
    'no_ack' => getenv("MB_USER_IMPORT_CONSUME_NO_ACK"),
    'exclusive' => getenv("MB_USER_IMPORT_CONSUME_EXCLUSIVE"),
    'nowait' => getenv("MB_USER_IMPORT_CONSUME_NOWAIT"),
  ),
  'routingKey' => getenv("MB_USER_IMPORT_ROUTING_KEY"),
);
$settings = array(
  'mailchimp_apikey' => getenv("MAILCHIMP_APIKEY"),
  'mailchimp_list_id' => getenv("MAILCHIMP_LIST_ID"),
  'mobile_commons_username' => getenv("MOBILE_COMMONS_USER"),
  'mobile_commons_password' => getenv("MOBILE_COMMONS_PASSWORD"),
  'stathat_ez_key' => getenv("STATHAT_EZKEY"),
  'ds_drupal_api_host' => getenv("DS_DRUPAL_API_HOST"),
  'ds_drupal_api_port' => getenv("DS_DRUPAL_API_PORT"),
  'ds_drupal_api_username' => getenv("DS_DRUPAL_API_USERNAME"),
  'ds_drupal_api_password' => getenv("DS_DRUPAL_API_PASSWORD"),
);


echo '------- mbc-user-import START: ' . date('D M j G:i:s T Y') . ' -------', PHP_EOL;

$bla = FALSE;
if ($bla) {
  $bla = TRUE;
}

// Kick off
// Create entries in userImportQueue based on csv.
$mbcUserImport = new MBC_UserImport($credentials, $config, $settings);
$mbcUserImport->produceUserImport();

echo '------- mbc-user-import END: ' . date('D M j G:i:s T Y') . ' -------', PHP_EOL;
