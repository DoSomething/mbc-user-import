<?php
/**
 * A consumer application for a user import system. Various source types define
 * specifics as to how the user data should be injested from CSV files. User data is
 * distributed to other consumers in the Quicksilver system for further processing
 * as well as triggering transactional messaging related to welcoming the user to
 * DoSomething.org.
 *
 * PHP version 5
 *
 * @category PHP
 * @package  MBC_UserImport
 * @author   DeeZone <dlee@dosomething.org>
 * @license  MIT: https://en.wikipedia.org/wiki/MIT_License
 * @version  GIT: <git_id>
 * @link     https://github.com/DoSomething/mbc-user-import
 */

namespace DoSomething\MBC_UserImport;

use DoSomething\MB_Toolbox\MB_Configuration;
use DoSomething\StatHat\Client as StatHat;
use \Exception;

/**
 * A collection of common helper methods used throughout the MBC_UserImport
 * application.
 *
 * @category PHP
 * @package  MBC_UserImport
 * @author   DeeZone <dlee@dosomething.org>
 * @license  MIT: https://en.wikipedia.org/wiki/MIT_License
 * @version  "Release: <package_version>"
 * @link     https://github.com/DoSomething/mbc-user-import/blob/master/src
 *           /MBC_UserImport_Toolbox.php
 */
class MBC_UserImport_Toolbox
{

  /**
   * Message Broker object for sending logging messages.
   *
   * @var object $mbLogging
   */
  protected $mbLogging;

  /**
   * Collection of tools related to the Message Broker system.
   *
   * @var object $mbToolbox
   */
  protected $mbToolbox;

  /**
   * Collection of tools related to the Message Broker system cURL functionality.
   *
   * @var object $mbToolboxCURL
   */
  protected $mbToolboxCURL;

  /**
   * Connection to StatHat service for reporting monitoring counters.
   *
   * @var object $statHat
   */
  protected $statHat;

  /**
   * Message Broker connection to RabbitMQ
   *
   * @var object
   */
  protected $messageBroker_transactionals;

  /**
   * Common object for sources to access as a resource to process user data.
   *
   * @var object $mbcUserImportToolbox
   */
  protected $mbcUserImportToolbox;

  /**
   * A value to identify which source is extending this base class.
   *
   * @var string $sourceName
   */
  protected $sourceName;

  /**
   * Gather configuration objects for use throughout class.
   */
  public function __construct()
  {

    $mbConfig = MB_Configuration::getInstance();
    $this->messageBroker_transactionals
      = $mbConfig->getProperty('messageBrokerTransactionals');
    $this->mbLogging = $mbConfig->getProperty('messageBrokerLogging');
    $this->mbToolbox = $mbConfig->getProperty('mbToolbox');
    $this->mbToolboxCURL = $mbConfig->getProperty('mbToolboxCURL');
    $this->phoenixAPIConfig = $mbConfig->getProperty('ds_drupal_api_config');
    $this->statHat = $mbConfig->getProperty('statHat');
  }

  /**
   * Check for the existence of and SMS (Mobile Commons)
   * accounts.
   *
   * @param array $existing   Values to submit for existing user log entry.
   * @param array $importUser Values specific to the user.
   *
   * @return null
   */
  public function logExisting($existing, $importUser)
  {

    if (isset($existing['email']) || isset($existing['drupal-uid']) || isset($existing['mobile'])) {
      $existing['origin'] = [
      'name' => $importUser['origin'],
      'processed' => time()
      ];
      $payload = serialize($existing);
      $this->mbLogging->publish($payload);
      $this->statHat->ezCount(
        'mbc-user-import: MBC_UserImport_Toolbox: logExisting',
        1
      );
    }
  }

  /**
   * Add common values to all message payload settings.
   *
   * @param array $user Currect values specific to the user data being imported.
   *
   * @return array $payload Common settings for message payload.
   */
  public function addCommonPayload($user = [])
  {

    $time = !empty($user['activity_timestamp']) ? $user['activity_timestamp'] : time();
    $payload['activity_timestamp'] = $time;
    $payload['log-type'] = 'transactional';
    $payload['subscribed'] = 1;
    $payload['application_id'] = 'MUI';
    $payload['user_country'] = 'US';
    $payload['user_language'] = 'en';

    return $payload;
  }

  /**
   * Check if users is subscribed for a campaign
   */
  public function checkSignup($userId, $campaignId) {
    $curlUrl = $this->phoenixAPIConfig['host'];
    if (!empty($this->phoenixAPIConfig['port'])) {
      $curlUrl .= ":" . $this->phoenixAPIConfig['port'];
    }
    $curlUrl .= '/api/v1/signups?user=' . $userId . '&campaigns=' . $campaignId;

    // Execute the request.
    list($response, $code) = $this->mbToolboxCURL->curlGETauth($curlUrl);

    if ($code != 200) {
      $error = "Can't check signup of user " .  $userId . " to " . $id . ": " . $result;
      echo $error . PHP_EOL;
      return false;
    }

    if (empty($response->data) || empty($response->data[0]) || empty($response->data[0]->id)) {
      return false;
    }

    $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: existing campaignSignup');

    return $response->data[0]->id;
  }

  /**
   * Sign up user ID (UID) to campaign.
   *
   * - https://github.com/DoSomething/dosomething/wiki/API#campaign-signup
   * - https://www.dosomething.org/api/v1/campaigns/[nid]/signup
   *
   * @param integer $campaignNID    Drupal node ID of campaign.
   * @param array   $drupalUID      User ID (UID) to signup to campaign.
   * @param string  $source         The name of the import source
   * @param bool    $transactionals Supress sending transaction messages.
   *
   * @return bool|int Signup id or true on existing subscription
   */
  public function campaignSignup($id, $userId, $source, $transactionals = false) {
    $post = [
      'source' => $source,
      'uid' => $userId,
      'transactionals' => false,
    ];
    $curlUrl = $this->phoenixAPIConfig['host'];
    if (!empty($this->phoenixAPIConfig['port'])) {
      $curlUrl .= ":" . $this->phoenixAPIConfig['port'];
    }
    $curlUrl .= '/api/v1/campaigns/' . $id . '/signup';

    // Execute the request.
    list($response, $code) = $this->mbToolboxCURL->curlPOSTauth($curlUrl, $post);
    $result = isset($response[0]) ? $response[0] : 'Unknown';

    if ($code != 200) {
      $error = "Can't signup user " .  $userId . " to " . $id . ": " . $result;
      throw new Exception($error);
    }

    if (is_numeric($result)) {
      // New signup: signup id will be returned.
      $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: campaignSignup');
      return $result;
    } else {
      $error = "Can't parse signup response: user " .  $userId . " to " . $id;
      throw new Exception($error);
    }
  }
}
