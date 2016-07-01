<?php
/**
 *
 */

namespace DoSomething\MBC_UserImport;

use DoSomething\MB_Toolbox\MB_Configuration;
use DoSomething\StatHat\Client as StatHat;
use \Exception;
 
/**
 *
 */
class MBC_UserImport_Toolbox
{

    /**
   * Message Broker object for sending logging messages.
   *
   * @var object $mbLogging
   */
    private $mbLogging;

    /**
   * MailChimp objects for each of the accounts used by DoSomething.org.
   *
   * @var array $mailChimpObjects
   */
    private $mailChimpObjects;

    /**
   * Mobile Commons DoSomething.org US connection.
   *
   * @var object $mobileCommons
   */
    private $moblieCommons;

    /**
   * Collection of tools related to the Message Broker system.
   *
   * @var object $mbToolbox
   */
    private $mbToolbox;

    /**
   * Collection of tools related to the Message Broker system cURL functionality.
   *
   * @var object $mbToolboxCURL
   */
    private $mbToolboxCURL;

    /**
   * Connection to StatHat service for reporting monitoring counters.
   *
   * @var object $statHat
   */
    private $statHat;

    /**
   * Message Broker connection to RabbitMQ
   *
   * @var object
   */
    protected $messageBroker_transactionals;

    /**
   *
   */
    public function __construct()
    {

        $mbConfig = MB_Configuration::getInstance();
        $this->messageBroker_transactionals = $mbConfig->getProperty('messageBrokerTransactionals');
        $this->mbLogging = $mbConfig->getProperty('messageBrokerLogging');
        $this->mailChimpObjects = $mbConfig->getProperty('mbcURMailChimp_Objects');
        $this->mobileCommons = $mbConfig->getProperty('mobileCommons');
        $this->mbToolbox = $mbConfig->getProperty('mbToolbox');
        $this->mbToolboxCURL = $mbConfig->getProperty('mbToolboxCURL');
        $this->phoenixAPIConfig = $mbConfig->getProperty('ds_drupal_api_config');
        $this->statHat = $mbConfig->getProperty('statHat');
    }

    /**
   * User settings used to check for existing and create new user accounts in various
   * systems defined in process().
   *
   * @var object $importUser
   */
    private $importUser;

    /**
   * Common object for sources to access as a resource to process user data.
   *
   * @var object $mbcUserImportToolbox
   */
    private $mbcUserImportToolbox;

    /**
   * A value to identify which source is extending this base class.
   *
   * @var string $sourceName
   */
    protected $sourceName;

    /**
  * Check for the existence of email (Mailchimp) account.
  *
  * @param array $user
  *   Settings of user account to check against.
  * @param array $existingStatus
  *   Details of existing accounts for the user email address.
  */
    public function checkExistingEmail($user, &$existingStatus)
    {

        $mailchimpStatus = $this->mailChimpObjects['us']->memberInfo($user['email'], $user['mailchimp_list_id']);
        
        if (isset($mailchimpStatus['data']) && count($mailchimpStatus['data']) > 0) {
            echo($user['email'] . ' already a Mailchimp user.' . PHP_EOL);
            $existingStatus['email-status'] = 'Existing account';
            $existingStatus['email'] = $user['email'];
            $existingStatus['email-acquired'] = $mailchimpStatus['data'][0]['timestamp'];
            $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: checkExistingEmail: Existing MailChimp account', 1);
        }
        elseif ($mailchimpStatus == false) {
            $existingStatus['email-status'] = 'Mailchimp Error';
            $existingStatus['email'] = $user['email'];
            $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: checkExistingEmail: MailChimp error', 1);
        }

    }
  
    /**
  * Check for the existence of Drupal account.
  *
  * @param array $user
  *   Settings of user account to check against.
  * @param array $existingStatus
  *   Details of existing accounts for the user email address.
  */
    public function checkExistingDrupal($user, &$existingStatus)
    {

        $email = $user['email'];
        $mobile = isset($user['mobile']) ? $user['mobile'] : null;
        $drupalUID = $this->mbToolbox->lookupDrupalUser($email, $mobile);

        if ($drupalUID != 0) {
            $existingStatus['drupal-uid'] = $drupalUID;
            $existingStatus['drupal-email'] = $user['email'];
            $existingStatus['drupal-mobile'] = $user['mobile'];
            $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: checkExistingDrupal: Existing user', 1);
        }
    }

    /**
  * Check for the existence of email (Mailchimp) and SMS (Mobile Commons)
  * accounts.
  *
  * @param array  $user
  *   Settings of user account to check against.
  * @param string $target
  *   The type of account to check
  */
    public function checkExistingSMS($user, &$existingStatus)
    {

        $mobilecommonsStatus = (array) $this->mobileCommons->profiles_get(array('phone_number' => $user['mobile']));
        if (!isset($mobilecommonsStatus['error'])) {
            echo($user['mobile'] . ' already a Mobile Commons user.' . PHP_EOL);
            if (isset($mobilecommonsStatus['profile']->status)) {
                $existingStatus['mobile-error'] = (string)$mobilecommonsStatus['profile']->status;
                $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: checkExistingSMS: ' . $existingStatus['mobile-error'], 1);
                // opted_out_source
                $existingStatus['mobile-acquired'] = (string)$mobilecommonsStatus['profile']->created_at;
            }
            else {
                $existingStatus['mobile-error'] = 'Existing account';
                $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: checkExistingSMS: Existing account', 1);
            }
            $existingStatus['mobile'] = $user['mobile'];
        }
        else {
            $mobileCommonsError = $mobilecommonsStatus['error']->attributes()->{'message'};
            // via Mobile Commons API - "Invalid phone number" aka "number not found", the number is not from an existing user.
            if (!$mobileCommonsError == 'Invalid phone number') {
                echo 'Mobile Common Error: ' . $mobileCommonsError, PHP_EOL;
                $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: checkExistingSMS: Invalid phone number', 1);
            }
        }

    }

    /**
  * Check for the existence of email (Mailchimp) and SMS (Mobile Commons)
  * accounts.
  *
  * @param array $existing
  *   Values to submit for existing user log entry.
  */
    public function logExisting($existing, $importUser)
    {
    
        if (isset($existing['email']) 
            || isset($existing['drupal-uid']) 
            || isset($existing['mobile'])
        ) {

            $existing['origin'] = [
            'name' => $importUser['origin'],
            'processed' => time()
            ];
            $payload = serialize($existing);
            $this->mbLogging->publishMessage($payload);
            $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: logExisting', 1);
        }
    }
  
    /**
   *
   */
    public function addCommonPayload($user)
    {
    
        $payload['activity_timestamp'] = $user['activity_timestamp'];
        $payload['log-type'] = 'transactional';
        $payload['subscribed'] = 1;
        $payload['application_id'] = 'MUI';
        $payload['user_country'] = 'US';
        $payload['user_language'] = 'en';

        return $payload;
    }
  
    /**
   * Create the Drupal user based on user settings. email is a
   * required value.
   *
   * @param array $user
   *   Values that define the user being imported.
   */
    public function addDrupalUser($user)
    {

        $drupalUser = $this->mbToolbox->createDrupalUser($user);
        $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: addDrupalUser', 1);
    }
  
    /**
   * Send password reset email after welcome to DoSomething email is sent.
   *
   * @param object $user
   *   Drupal User properties.
   */
    public function sendPasswordResetEmail($user)
    {

        $firstName = $user->field_first_name->und[0]->value != null ? ucfirst($user->field_first_name->und[0]->value) : 'Doer';
        $passwordResetURL = $this->mbToolbox->getPasswordResetURL($user->uid);
        if ($passwordResetURL === null) {
            throw new Exception('Failed to generate password reset URL.');
        }

        $message['email'] = $user->mail;
        $message['merge_vars']['FNAME'] = $firstName;
        $message['merge_vars']['PASSWORD_RESET_LINK'] = $passwordResetURL;
        $message['merge_vars']['MEMBER_COUNT'] = $this->mbToolbox->getDSMemberCount();
        $message['activity'] = 'user_password-niche';
        $message['email_template'] = 'mb-userImport-niche_password_v1-0-0';

        $message['tags'][0] = 'user_password-niche';
        $message['log-type'] = 'transactional';

        $payload = serialize($message);
        $this->messageBroker_transactionals->publish($payload, 'user.password.transactional');
        $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: sendPasswordResetEmail', 1);
    }

    /*
    * Utility method - Converts full name to first and last name.
    *
    * @param string $fullName
    *   The full name to break a part based on spaces in the name.
    * @return array $nameBits
    *   The first and last names created from the supplied full name.
    */
    private function nameBits($fullName)
    {
        $names = explode(' ', $fullName);
        $nameBits['last_name'] = ucwords($names[count($names) - 1]);
        unset($names[count($names) - 1]);
        $nameBits['first_name'] = ucwords(implode(' ', $names));
        return $nameBits;
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
   * @param array   $drupalUID
   *   The user ID (UID) of the Drupal user account to create a campaign
   *   signup for.
   * @param string  $source
   *   The name of the import source
   * @param bool    $transactionals
   *   A flag to supress sending transaction messages related to the signup.
   *
   * @return bool
   *   Was the user signed up to the campaign.
   */
    public function campaignSignup($campaignNID, $drupalUID, $source, $transactionals = true)
    {

        $post = array(
        'source' => $source,
        'uid' => $drupalUID,
        'transactionals' => $transactionals
        );
        $curlUrl = $this->phoenixAPIConfig['host'];
        $port = $this->phoenixAPIConfig['port'];
        if ($port != 0) {
            $curlUrl .= ":$port";
        }
        $curlUrl .= '/api/v1/campaigns/' . $campaignNID . '/signup';
        $signUp = $this->mbToolboxCURL->curlPOSTauth($curlUrl, $post);

        // Results returned for campaign signup
        // User signed up, indicated by return sid (signup ID)
        if (is_array($signUp[0]) && $signUp[0][0] > 0) {
            $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: campaignSignup', 1);
            return true;
        }
        else {
            echo 'Drupal UID: ' . $drupalUID . ' may already be signed up for campaign ' . $campaignNID . ' or campaign is not accepting signups.' . $signUp[0][0], PHP_EOL;
            $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Toolbox: existing campaignSignup', 1);
            return false;
        }

    }

}
