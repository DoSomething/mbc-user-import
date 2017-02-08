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

use \Mailchimp\MailchimpAPIException;

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
   * MailChimp lists API.
   *
   * @var \Mailchimp\MailchimpLists
   *
   * @see https://github.com/thinkshout/mailchimp-api-php/blob/master/src/MailchimpLists.php
   */
  protected $mailchimpLists;

  /**
   * Mobile Commons DoSomething.org US connection.
   *
   * @var object $mobileCommons
   */
  protected $moblieCommons;

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
    $this->mailchimpLists = $mbConfig->getProperty('mailchimpLists');
    $this->mobileCommons = $mbConfig->getProperty('mobileCommons');
    $this->mbToolbox = $mbConfig->getProperty('mbToolbox');
    $this->mbToolboxCURL = $mbConfig->getProperty('mbToolboxCURL');
    $this->phoenixAPIConfig = $mbConfig->getProperty('ds_drupal_api_config');
    $this->statHat = $mbConfig->getProperty('statHat');
  }

  /**
   * Check for the existence of email (Mailchimp) account.
   */
  public function getMailchimpStatus($email, $listId)
  {
    $result = [];
    try {
      $memberInfo = $this->mailchimpLists->getMemberInfo($listId, $email);
    } catch (MailchimpAPIException $e) {
      if ($e->getCode() === 404) {
        // Not found = new record.
        return false;
      }

      // Unknown error, exit.
      $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: ' .
        'getMailchimpStatus: MailChimp error');
      throw $e;
    }

    $result['email-subscription-status'] = $memberInfo->status !== 'unsubscribed';
    $result['email-status'] = 'Existing account';
    $result['email'] = $email;
    $result['email-acquired'] = date("Y-m-d H:i:s", strtotime($memberInfo->last_changed));
    $this->statHat->ezCount(
      'mbc-user-import: MBC_UserImport_Toolbox: ' .
      'getMailchimpStatus: Existing MailChimp account',
      1
    );
    return $result;
  }

  /**
   * Check for the existence of email (Mailchimp) and SMS (Mobile Commons)
   * accounts.
   *
   * @param array $user           Settings of user account to check against.
   * @param array $existingStatus Details of existing accounts for the user email
   *                               address.
   *
   * @return array $existingStatus Details of existing accounts for the user email
   *                               address.
   */
  public function getMobileCommonsStatus($user, &$existingStatus)
  {

    if (empty($user['mobile'])) {
      echo 'Phone number isn\'t set.' . PHP_EOL;
      return false;
    }
    $mobilecommonsStatus
      = (array) $this->mobileCommons->profiles_get(
        ['phone_number' => $user['mobile']]
      );
    if (!isset($mobilecommonsStatus['error'])) {
      echo($user['mobile'] . ' already a Mobile Commons user.' . PHP_EOL);
      if (isset($mobilecommonsStatus['profile']->status)) {
        $existingStatus['mobile-error']
          = (string)$mobilecommonsStatus['profile']->status;
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Toolbox: ' .
          'getMobileCommonsStatus: ' . $existingStatus['mobile-error'],
          1
        );
        // opted_out_source
        $existingStatus['mobile-acquired']
          = (string)$mobilecommonsStatus['profile']->created_at;
      } else {
        $existingStatus['mobile-error'] = 'Existing account';
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Toolbox: ' .
          'getMobileCommonsStatus: Existing account',
          1
        );
      }
      $existingStatus['mobile'] = $user['mobile'];
    } else {
      $mobileCommonsError
        = $mobilecommonsStatus['error']->attributes()->{'message'};
      // via Mobile Commons API - "Invalid phone number" aka "number not
      // found", the number is not from an existing user.
      if (!$mobileCommonsError == 'Invalid phone number') {
        echo 'Mobile Common Error: ' . $mobileCommonsError, PHP_EOL;
        $this->statHat->ezCount(
          'mbc-user-import: MBC_UserImport_Toolbox: ' .
          'getMobileCommonsStatus: Invalid phone number',
          1
        );
      }
    }
  }

  /**
   * Check for the existence of email (Mailchimp) and SMS (Mobile Commons)
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
    $curlUrl .= '/api/v1/signups?user='. $userId . '&campaigns=' . $id . '&count=1';

    // Execute the request.
    list($response, $code) = $this->mbToolboxCURL->curlGETauth($curlUrl, $post);

    $result = isset($response->data[0]->id) ? $response->data[0]->id : false;
    return $result;

    if ($code != 200) {
      $error = "Can't signup user " .  $userId . " to " . $id . ": " . $result;
      throw new Exception($error);
    }

    if (is_numeric($result)) {
      // New signup: signup id will be returned.
      $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: campaignSignup');
      return $result;
    } elseif ($result === false) {
      // User has already been subscribed.
      $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: existing campaignSignup');
      return true;
    } else {
      $error = "Can't parse signup response: user " .  $userId . " to " . $id;
      throw new Exception($error);
    }
  }
}
