<?php
/**
 * Message Broker configuration settings for mbc-user-import
 */

use DoSomething\MB_Toolbox\MB_Configuration;
use DoSomething\StatHat\Client as StatHat;
use DoSomething\MB_Toolbox\MB_MailChimp;
use DoSomething\MB_Toolbox\MB_RabbitMQManagementAPI;

// Load configuration settings common to the Message Broker system
// symlinks in the project directory point to the actual location of the files
require_once __DIR__ . '/messagebroker-config/mb-secure-config.inc';

define("ALLOWED_SOURCES", serialize([
  'Niche',
  'AfterSchool'
]));

$mbConfig = MB_Configuration::getInstance();

// StatHat
$mbConfig->setProperty('statHat', new StatHat([
  'ez_key' => getenv("STATHAT_EZKEY"),
  'debug' => getenv('DISABLE_STAT_TRACKING')
]));

// RabbitMQ
$mbConfig->setProperty('rabbit_credentials', array(
  'host' =>  getenv("RABBITMQ_HOST"),
  'port' => getenv("RABBITMQ_PORT"),
  'username' => getenv("RABBITMQ_USERNAME"),
  'password' => getenv("RABBITMQ_PASSWORD"),
  'vhost' => getenv("RABBITMQ_VHOST"),
));
$mbConfig->setProperty('mbRabbitMQManagementAPI', new MB_RabbitMQManagementAPI([
  'domain' => getenv("MB_RABBITMQ_MANAGEMENT_API_HOST"),
  'port' => getenv('MB_RABBITMQ_MANAGEMENT_API_PORT'),
  'vhost' => getenv('MB_RABBITMQ_MANAGEMENT_API_VHOST'),
  'username' => getenv('MB_RABBITMQ_MANAGEMENT_API_USERNAME'),
  'password' => getenv('MB_RABBITMQ_MANAGEMENT_API_PASSWORD')
]));

// Drupal API
$mbConfig->setProperty('ds_drupal_api_config', [
  'host' => getenv("DS_DRUPAL_API_HOST"),
  'port' => getenv('DS_DRUPAL_API_PORT'),
  'username' => getenv("DS_DRUPAL_API_USERNAME"),
  'password' => getenv('DS_DRUPAL_API_PASSWORD'),
]);

// SMS - Mobile Commons (United States and Canada)
// CA is for future use - currently only support interaction with US account
$mbConfig->setProperty('mobileCommons_config', array(
  'US' => array(
    'username' => getenv("MOBILE_COMMONS_US_USER"),
    'password' => getenv("MOBILE_COMMONS_US_PASSWORD"),
    'company_key' => getenv("MOBILE_COMMONS_US_COMPANY_KEY"),
  ),
  'CA' => array(
    'username' => getenv("MOBILE_COMMONS_CA_USER"),
    'password' => getenv("MOBILE_COMMONS_CA_PASSWORD"),
    'company_key' => getenv("MOBILE_COMMONS_CA_COMPANY_KEY"),
  )
));

// SMS - mGage
// Future use, MX and BR
$mobileConfig = $mbConfig->gatherSettings('mobile');
$mbConfig->setProperty('communicatePro_config', array(
  'settings' => $mobileConfig->mGage,
  'username' => getenv("COMMUNICATE_PRO_USER"),
  'password' => getenv("COMMUNICATE_PRO_PASSWORD")
));

// MailChimp
$mbConfig->setProperty('mailchimpAPIkeys', [
  'country' => [
    'global' => getenv("MAILCHIMP_APIKEY"),
    'us'     => getenv("MAILCHIMP_APIKEY"),
    'gb'     => getenv("MAILCHIMP_UK_APIKEY"),
    'uk'     => getenv("MAILCHIMP_UK_APIKEY"),
    'mx'     => getenv("MAILCHIMP_MX_APIKEY"),
    'br'     => getenv("MAILCHIMP_BR_APIKEY"),
  ]]
);
$mailchimpAPIKeys = $mbConfig->getProperty('mailchimpAPIkeys');
$mcObjects = [];
foreach ($mailchimpAPIKeys['country'] as $country => $key) {
  $mcObjects[$country] = new MB_MailChimp($key);
}
$mbConfig->setProperty('mbcURMailChimp_Objects', $mcObjects);

// Rabbit MQ connection to specific exchanges / queues
$mbRabbitConfig = $mbConfig->constructRabbitConfig('directUserImport', ['userImportQueue']);
$rabbitCredentials = $mbConfig->getProperty('rabbit_credentials');
$mbConfig->setProperty('messageBroker', new MessageBroker($rabbitCredentials, $mbRabbitConfig));

$mbRabbitConfig = $mbConfig->constructRabbitConfig('transactionalExchange', ['transactionalQueue', 'userAPIRegistrationQueue', 'userRegistrationQueue', 'mobileCommonsQueue']);
$mbConfig->setProperty('messageBrokerTransactionals', new MessageBroker($rabbitCredentials, $mbRabbitConfig));

$mbRabbitConfig = $mbConfig->constructRabbitConfig('directLoggingGateway', ['loggingGatewayQueue']);
$mbConfig->setProperty('messageBrokerLogging', new MessageBroker($rabbitCredentials, $mbRabbitConfig));

$mbRabbitConfig = $mbConfig->constructRabbitConfig('directDeadLetterExchange', ['deadLetterQueue']);
$mbConfig->setProperty('messageBroker_deadLetter', new MessageBroker($rabbitCredentials, $mbRabbitConfig));