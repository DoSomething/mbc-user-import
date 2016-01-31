<?php
/**
 *
 */

namespace DoSomething\MBC_UserImport;

use DoSomething\StatHat\Client as StatHat;
use \Exception;
 
/**
 *
 */
class MBC_UserImport_Toolbox
{

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
   * Constructor for MBC_BaseConsumer - all consumer applications should extend this base class.
   *
   * @param array $message
   *  The payload of the unseralized message being processed.
   */
  public function __construct($message) {

    $this->mbcUserImportToolbox = new MBC_UserImport_Toolbox($message);
    $this->sourceName = 'Niche';
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
  public function checkExisting($user, $target) {

    switch ($target) {
      
      case "drupal":
        
        
        break;
      
      case "email":
        
        
        break;
      
      case "sms":
        
        break;
      
      default:
        echo "Unsupported target: " . $target, PHP_EOL;
        break;
    }

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
    $payload['source'] = $user->source;
    
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
