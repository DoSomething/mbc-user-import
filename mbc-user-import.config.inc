<?php
/**
 * Message Broker configuration settings for mbc-user-import
 */

use DoSomething\MessageBroker\MessageBroker;
use DoSomething\MB_Toolbox\MB_Configuration;
use DoSomething\MB_Toolbox\MB_Toolbox;
use DoSomething\MB_Toolbox\MB_Toolbox_cURL;
use DoSomething\StatHat\Client as StatHat;
use DoSomething\MB_Toolbox\MB_RabbitMQManagementAPI;
use DoSomething\Gateway\Northstar;

use DoSomething\MBC_UserImport\MBC_UserImport_Northstar_OAuthBridge;


// Load configuration settings common to the Message Broker system
// symlinks in the project directory point to the actual location of the files
require_once CONFIG_PATH . '/mb-secure-config/' . ENVIRONMENT . '/mb-secure-config.inc';

define("ALLOWED_SOURCES", serialize([
  'Niche',
  'AfterSchool',
]));

$mbConfig = MB_Configuration::getInstance();

// General Settings
$mbConfig->setProperty('generalSettings', []);

// StatHat
$mbConfig->setProperty('statHat', new StatHat([
  'ez_key' => getenv("STATHAT_EZKEY"),
  'debug' => getenv('DISABLE_STAT_TRACKING'),
]));

// RabbitMQ
$mbConfig->setProperty('rabbit_credentials', [
  'host' =>  getenv("RABBITMQ_HOST"),
  'port' => getenv("RABBITMQ_PORT"),
  'username' => getenv("RABBITMQ_USERNAME"),
  'password' => getenv("RABBITMQ_PASSWORD"),
  'vhost' => getenv("RABBITMQ_VHOST"),
]);
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

$mbConfig->setProperty('mbToolbox', new MB_Toolbox());
$mbConfig->setProperty('mbToolboxCURL', new MB_Toolbox_cURL());

// Northstar
$northstar = new Northstar([
    'grant' => 'client_credentials', // Default OAuth grant to use: either 'authorization_code' or 'client_credentials'
    'url' => getenv("NORTHSTAR_API_HOST"), // the environment you want to connect to
    'bridge' => MBC_UserImport_Northstar_OAuthBridge::class, // class which handles saving/retrieving tokens

    // Then, configure client ID, client secret, and scopes per grant.
    'client_credentials' => [
        'client_id' => getenv("NORTHSTAR_API_ID"),
        'client_secret' => getenv('NORTHSTAR_API_KEY'),
        'scope' => ['user', 'admin'],
    ],
]);
$mbConfig->setProperty('northstar', $northstar);

// Rabbit MQ connection to specific exchanges / queues
$mbRabbitConfig = $mbConfig->constructRabbitConfig('directUserImport', ['userImportQueue']);
$rabbitCredentials = $mbConfig->getProperty('rabbit_credentials');
$mbConfig->setProperty('messageBroker', new MessageBroker($rabbitCredentials, $mbRabbitConfig));

$mbRabbitConfig = $mbConfig->constructRabbitConfig(
  'transactionalExchange',
  [
    'transactionalQueue',
  ]
);
$mbConfig->setProperty('messageBrokerTransactionals', new MessageBroker($rabbitCredentials, $mbRabbitConfig));

$mbRabbitConfig = $mbConfig->constructRabbitConfig('directLoggingGateway', ['loggingGatewayQueue']);
$mbConfig->setProperty('messageBrokerLogging', new MessageBroker($rabbitCredentials, $mbRabbitConfig));

$mbRabbitConfig = $mbConfig->constructRabbitConfig('directDeadLetterExchange', ['deadLetterQueue']);
$mbConfig->setProperty('messageBroker_deadLetter', new MessageBroker($rabbitCredentials, $mbRabbitConfig));
