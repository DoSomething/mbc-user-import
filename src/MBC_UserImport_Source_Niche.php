<?php
/**
 *
 */
 
namespace DoSomething\MBC_UserImport;

use DoSomething\MBC_UserImport\MBC_UserImport_BaseSource;
use DoSomething\MBC_UserImport\MBC_UserImport_Toolbox;
use \Exception;

class MBC_UserImport_Source_Niche extends MBC_UserImport_BaseSource
{
  
  /**
   * User settings used to check for existing and create new user accounts in various
   * systems defined in process().
   *
   * @var object $importUser
   */
  private $importUser;
  
  /**
   * Common class for sources to access as a resopuce to process user data.
   *
   * @var object $mbcUserImportToolbox
   */
  private $mbcUserImportToolbox;

  /**
   * Constructor for MBC_BaseConsumer - all consumer applications should extend this base class.
   *
   * @param array $message
   *  The payload of the unseralized message being processed.
   */
  public function __construct($message) {

    parent::__construct($message);
    $this->mbcUserImportToolbox = new MBC_UserImport_Toolbox($message);
    $this->sourceName = 'Niche';
  }

  /**
   * canProcess(): Test if message can be processed by consumer.
   *
   * @param array
   *   The message contents to test if it can be processed.
   */
  public function canProcess($message) {
    
   if (empty($this->message['email'])) {
      echo '- canProcess(), email not set.', PHP_EOL;
      parent::reportErrorPayload();
      return false;
    }

   if (filter_var($this->message['email'], FILTER_VALIDATE_EMAIL) === false) {
      echo '- canProcess(), failed FILTER_VALIDATE_EMAIL: ' . $this->message['email'], PHP_EOL;
      parent::reportErrorPayload();
      return false;
    }
    elseif (isset($this->message['email'])) {
      $this->message['email'] = filter_var($this->message['email'], FILTER_VALIDATE_EMAIL);
    }

  }

  /**
   * setter(): Assign values from message to class propertry for processing.
   *
   * @param array
   *   The values from the message consumed from the queue.
   */
  public function setter($message) {
    
    if (isset($this->message['source'])) {
      $this->importUser->user_registration_source = $this->message['source'];
    }
    else {
      $this->importUser->user_registration_source = 'Niche';
    }
    
    if (isset($this->message['email'])) {
      $this->importUser->email = $this->message['email'];
    }

  }

  /**
   * process(): Process validated and processed message from queue.
   */
  public function process() {
    
    // Check for existing account
    $existing = $this->mbcUserImportToolbox->checkExisting();
    
    // create Drupal user
    if (empty($existing['drupal'])) {
      $this->drupalUID = $this->mbcUserImportToolbox->createDrupalUser($this->message);
    }
    else {
      $this->drupalUID = $existing['drupal'];
    }

    // send welcome email
    $this->mbcUserImportToolbox->sendWelcomeEmail();
    
    // send welcome SMS
    if (!(empty($existing['mobile']))) {
      $this->mbcUserImportToolbox->sendWelcomeSMS();
    }
    else {
      
    }
    
    // send password reset email
    $this->mbcUserImportToolbox->sendPasswordResetEmail();

  }

}
