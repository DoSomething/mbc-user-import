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
  'routingKey' => getenv("MB_USER_IMPORT_ROUTING_KEY"),
);
$settings = array(
  'stathat_ez_key' => getenv("STATHAT_EZKEY"),
);


echo '------- mbc-user-import START: ' . date('D M j G:i:s T Y') . ' -------', "\n";

// Kick off
// Create entries in userImportQueue based on csv.
$mbcUserImport = new MBC_UserImport($credentials, $config, $settings);
$mbcUserImport->produceUsermport();

echo '------- mbc-user-import END: ' . date('D M j G:i:s T Y') . ' -------', "\n";
