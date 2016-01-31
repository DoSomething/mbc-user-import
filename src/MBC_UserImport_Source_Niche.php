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
   * Constructor for MBC_UserImport_Source_Nice - extension of the base source class
   * that's specific to Niche.
   *
   * @param array $message
   *  The payload of the unseralized message being processed.
   */
  public function __construct($message) {

    parent::__construct($message);
    $this->sourceName = 'Niche';
  }

  /**
   * canProcess(): Test if message can be processed by consumer. Niche user imports must have at
   * least an email address.
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

    return true;
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
      $this->importUser['first_name'] = $nameBits['first_name'];
      $this->importUser['last_name'] = $nameBits['last_name'];
    }

    $firstName = isset($message['first_name']) && $message['first_name'] != '' ? $message['first_name'] : 'DS';
    $this->importUser['password'] = str_replace(' ', '', $firstName) . '-Doer' . rand(1, 1000);

    // Optional fields
    if (isset($message['birthdate']) && $message['birthdate'] != 0) {
      $this->importUser['birthdate_timestamp'] = strtotime($message['birthdate']);
    }
    if (isset($message['first_name']) && $message['first_name'] != '') {
      $this->importUser['merge_vars']['FNAME'] = $message['first_name'];
    }
    if (isset($message['last_name']) && $message['last_name'] != '') {
      $this->importUser['merge_vars']['LNAME'] = $message['last_name'];
    }
    if (isset($message['password']) && $message['password'] != '') {
      $this->importUser['merge_vars']['PASSWORD'] = $message['password'];
    }
    if (isset($message['address1']) && $message['address1'] != '') {
      $this->importUser['address1'] = $message['address1'];
    }
    if (isset($message['address2']) && $message['address2'] != '') {
      $this->importUser['address2'] = $message['address2'];
    }
    if (isset($message['city']) && $message['city'] != '') {
      $this->importUser['city'] = $message['city'];
    }
    if (isset($message['state']) && $message['state'] != '') {
      $this->importUser['state'] = $message['state'];
    }
    if (isset($message['zip']) && $message['zip'] != '') {
      $this->importUser['zip'] = $message['zip'];
    }
    if (isset($message['phone']) && $message['phone'] != '') {
      $this->importUser['mobile'] = $message['phone'];
    }
    if (isset($message['hs_gradyear']) && $message['hs_gradyear'] != 0) {
      $this->importUser['hs_gradyear'] = $message['hs_gradyear'];
    }
    if (isset($message['race']) && $message['race'] != '') {
      $this->importUser['race'] = $message['race'];
    }
    if (isset($message['religion']) && $message['religion'] != '') {
      $this->importUser['religion'] = $message['religion'];
    }
    if (isset($message['hs_name']) && $message['hs_name'] != '') {
      $this->importUser['hs_name'] = $message['hs_name'];
    }
    if (isset($message['college_name']) && $message['college_name'] != '') {
      $this->importUser['college_name'] = $message['college_name'];
    }
    if (isset($message['major_name']) && $message['major_name'] != '') {
      $this->importUser['major_name'] = $message['major_name'];
    }
    if (isset($message['degree_type']) && $message['degree_type'] != '') {
      $this->importUser['degree_type'] = $message['degree_type'];
    }
    if (isset($message['sat_math']) && $message['sat_math'] != 0) {
      $this->importUser['sat_math'] = $message['sat_math'];
    }
    if (isset($message['sat_verbal']) && $message['sat_verbal'] != 0) {
      $this->importUser['sat_verbal'] = $message['sat_verbal'];
    }
    if (isset($message['sat_writing']) && $message['sat_writing'] != 0) {
      $this->importUser['sat_writing'] = $message['sat_writing'];
    }
    if (isset($message['act_math']) && $message['act_math'] != 0) {
      $this->importUser['act_math'] = $message['act_math'];
    }
    if (isset($message['gpa']) && $message['gpa'] != 0) {
      $this->importUser['gpa'] = $message['gpa'];
    }
    if (isset($message['role']) && $message['role'] != '') {
      $this->importUser['role'] = $message['role'];
    }

  }

  /**
   * process(): Functional hum of class specific to the source. Defined steps specific
   * to Niche user import.
   */
  public function process() {
    
    $existing = NULL;
    $payload = $this->addCommonPayload($this->importUser);

    // Add welcome email details to payload
    $this->addWelcomeEmail($this->importUser, $payload);
    
    // Check for existing email account in MailChimp
    $existing['email'] = $this->mbcUserImportToolbox->checkExisting($this->importUser, 'email');
    if (empty($existing['email'])) {
      $this->addEmailSubscription($this->importUser, $payload);
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
    $this->addWelcomeSMS($this->importUser, $payload);
    
    // @todo: transition to using JSON formatted messages when all of the consumers are able to
    // detect the message format and process either seralized or JSON.
    $message = seralize($payload);
    $this->messageBroker->publishMessage($message, 'user.registration.transactional');
  }

  /**
   *
   */
  public function addWelcomeEmail($user, &$payload) {

    $payload['email'] = $user->email;
    $payload['email_template'] = 'mb-user-welcome-niche-com-v1-0-0-1';
    $payload['merge_vars'] = [
      'MEMBER_COUNT' => $this->memberCount, // TODO: lookup value in __construct
    ];
    $payload['tags'] = [
      0 => 'user_welcome-niche',
    ];
  }

  /**
   *
   */
  public function addCommonPayload($user) {

    $payload = $user;
    $this->mbcUserImportToolbox->addCommonPayload($payload);
    $payload['activity'] = 'user_welcome-niche';

    return $payload;
  }

  /**
   *
   */
  public function addEmailSubscription($user, &$payload) {

    $payload['mailchimp_list_id'] = 'f2fab1dfd4';
  }

  /**
   *
   */
  public function addWelcomeSMS($user, &$payload) {

    $payload['mobile_opt_in_path_id'] = 170071;
  }

  /**
   *
   */
  public function sendPasswordResetEmail() {

  }

}
