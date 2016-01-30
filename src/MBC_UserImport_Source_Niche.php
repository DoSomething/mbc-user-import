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
    
   if (empty($message['email'])) {
      echo '- canProcess(), email not set.', PHP_EOL;
      parent::reportErrorPayload();
      return false;
    }

   if (filter_var($message['email'], FILTER_VALIDATE_EMAIL) === false) {
      echo '- canProcess(), failed FILTER_VALIDATE_EMAIL: ' . $message['email'], PHP_EOL;
      parent::reportErrorPayload();
      return false;
    }
    elseif (isset($message['email'])) {
      $this->message['email'] = filter_var($message['email'], FILTER_VALIDATE_EMAIL);
    }

  }

  /**
   * setter(): Assign values from message to class propertry for processing.
   *
   * @param array
   *   The values from the message consumed from the queue.
   */
  public function setter($message) {
    
    if (isset($message['source'])) {
      $this->importUser->user_registration_source = $message['source'];
    }
    else {
      $this->importUser->user_registration_source = 'Niche';
    }
    
    if (isset($message['email'])) {
      $this->importUser->email = $message['email'];
    }

    if (isset($message['name']) && !isset($message['first_name'])) {
      $nameBits = $this->mbcUserImportToolbox->nameBits($message['name']);
      $this->importUser->first_name = $nameBits['first_name'];
      $this->importUser->last_name = $nameBits['last_name'];
    }

    $firstName = isset($message['first_name']) && $message['first_name'] != '' ? $message['first_name'] : 'DS';
    $this->importUser->password = str_replace(' ', '', $firstName) . '-Doer' . rand(1, 1000);






     if (isset($user->birthdate) && $user->birthdate != 0) {
        $userDetails['birthdate_timestamp'] = strtotime($user->birthdate);
      }
      if (isset($user->first_name) && $user->first_name != '') {
        $userDetails['merge_vars']['FNAME'] = $user->first_name;
      }
      if (isset($user->last_name) && $user->last_name != '') {
        $userDetails['merge_vars']['LNAME'] = $user->last_name;
      }
      if (isset($user->password) && $user->password != '') {
        $userDetails['merge_vars']['PASSWORD'] = $user->password;
      }
      if (isset($user->address1) && $user->address1 != '') {
        $userDetails['address1'] = $user->address1;
      }
      if (isset($user->address2) && $user->address2 != '') {
        $userDetails['address2'] = $user->address2;
      }
      if (isset($user->city) && $user->city != '') {
        $userDetails['city'] = $user->city;
      }
      if (isset($user->state) && $user->state != '') {
        $userDetails['state'] = $user->state;
      }
      if (isset($user->zip) && $user->zip != '') {
        $userDetails['zip'] = $user->zip;
      }
      if (isset($user->phone) && $user->phone != '') {
        $userDetails['mobile'] = $user->phone;
      }
      if (isset($user->hs_gradyear) && $user->hs_gradyear != 0) {
        $userDetails['hs_gradyear'] = $user->hs_gradyear;
      }
      if (isset($user->race) && $user->race != '') {
        $userDetails['race'] = $user->race;
      }
      if (isset($user->religion) && $user->religion != '') {
        $userDetails['religion'] = $user->religion;
      }
      if (isset($user->hs_name) && $user->hs_name != '') {
        $userDetails['hs_name'] = $user->hs_name;
      }
      if (isset($user->college_name) && $user->college_name != '') {
        $userDetails['college_name'] = $user->college_name;
      }
      if (isset($user->major_name) && $user->major_name != '') {
        $userDetails['major_name'] = $user->major_name;
      }
      if (isset($user->degree_type) && $user->degree_type != '') {
        $userDetails['degree_type'] = $user->degree_type;
      }
      if (isset($user->sat_math) && $user->sat_math != 0) {
        $userDetails['sat_math'] = $user->sat_math;
      }
      if (isset($user->sat_verbal) && $user->sat_verbal != 0) {
        $userDetails['sat_verbal'] = $user->sat_verbal;
      }
      if (isset($user->sat_writing) && $user->sat_writing != 0) {
        $userDetails['sat_writing'] = $user->sat_writing;
      }
      if (isset($user->act_math) && $user->act_math != 0) {
        $userDetails['act_math'] = $user->act_math;
      }
      if (isset($user->gpa) && $user->gpa != 0) {
        $userDetails['gpa'] = $user->gpa;
      }
      if (isset($user->role) && $user->role != '') {
        $userDetails['role'] = $user->role;
      }






  }

  /**
   * process(): Process validated and processed message from queue.
   *
   * Assume email exists?
   */
  public function process() {
    
    $existing = NULL;
    $payload = NULL;
    
    // Add welcome email details to payload
    $this->mbcUserImportToolbox->addWelcomeEmail($this->importUser, $payload);
    
    // Check for existing email account in MailChimp
    $existing['email'] = $this->mbcUserImportToolbox->checkExisting($this->importUser, 'email');
    if (empty($existing['email'])) {
      $this->mbcUserImportToolbox->addEmailSubscription($this->importUser, $payload);
    }
    
    // Drupal user
    $existing['drupal'] = $this->mbcUserImportToolbox->checkExisting($this->importUser, 'drupal');
    if (empty($existing['drupal'])) {
      $this->drupalUID = $this->mbcUserImportToolbox->createDrupalUser($this->importUser);
      $this->mbcUserImportToolbox->sendPasswordResetEmail($this->importUser);
    }

    // Check for existing user account in Mobile Commons
    $existing['sms'] = $this->mbcUserImportToolbox->checkExisting($this->importUser, 'sms');
    
    // Add SMS welcome details to payload
    $this->mbcUserImportToolbox->addWelcomeSMS($this->importUser, $payload);
    
    // @todo: transition to using JSON formatted messages when all of the consumers are able to
    // detect the message format and process either seralized or JSON.
    $message = seralize($payload);
    $this->messageBroker->publishMessage($message, 'user.registration.transactional');
  }

}
