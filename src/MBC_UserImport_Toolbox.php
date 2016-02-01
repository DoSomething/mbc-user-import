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
   * MailChimp objects for each of the accounts used by DoSomething.org.
   *
   * @var array $mailChimpObjects
   */
  private $mailChimpObjects;

  /**
   *
   */
  public function __construct() {

    $mbConfig = MB_Configuration::getInstance();
    $this->mailChimpObjects = $mbConfig->getProperty('mbcURMailChimp_Objects');
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
  * Check for the existence of email (Mailchimp) and SMS (Mobile Commons)
  * accounts.
  *
  * @param array $user
  *   Settings of user account to check against.
  * @param string $target
  *   The type of account to check
  */
  public function checkExisting($user, $target) {

    switch ($target) {
      
      case "email":
        
        $MailChimp = $this->mailChimpObjects['us'];
        $mailchimpStatus = $MailChimp->memberInfo($user['email'], $user['mailchimp_list_id']);
        
        if (isset($mailchimpStatus['data']) && count($mailchimpStatus['data']) > 0) {
          echo($user['email'] . ' already a Mailchimp user.' . PHP_EOL);
          $existingStatus['email-status'] = 'Existing account';
          $existingStatus['email'] = $user['email'];
          $existingStatus['email-acquired'] = $mailchimpStatus['data'][0]['timestamp'];
        }
        elseif ($mailchimpStatus == false) {
          $existingStatus['email-status'] = 'Mailchimp Error';
          $existingStatus['email'] = $user['email'];
        }

        break;
      
    case "drupal":

        break;
      
      case "sms":
        
        break;
      
      default:
        echo "Unsupported target: " . $target, PHP_EOL;
        break;
    }

    return $existingStatus;
  }
  
  /**
  * Check for the existence of email (Mailchimp) and SMS (Mobile Commons)
  * accounts.
  *
  * @param array $user
  *   Settings of user account to check against.
  * @param string $target
  *   The type of account to check
  */
  public function logExisting($user) {
    
  }
  
  /**
   *
   */
  public function addCommonPayload($payload) {
    
    $payload['activity_timestamp'] = '';
    $payload['log-type'] = 'transactional';
    $payload['subscribed'] = 1;
    $payload['application_id'] = 'MUI';
    $payload['user_country'] = 'US';
    $payload['user_language'] = 'en';

    return $payload;
  }
  
  /**
   *
   */
  public function addDrupalUser($user) {
    list($drupalUser, $user->password) = $this->toolbox->createDrupalUser($user);
  }
  
  /**
   *
   */
  public function sendPasswordResetEmail($user) {

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
