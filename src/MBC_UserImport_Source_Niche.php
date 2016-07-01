<?php
/**
 *
 */
 
namespace DoSomething\MBC_UserImport;

use DoSomething\MBC_UserImport\MBC_UserImport_BaseSource;
use \Exception;

class MBC_UserImport_Source_Niche extends MBC_UserImport_BaseSource
{

  const WELCOME_EMAIL_NEW_NEW = 'mb-niche-welcome_new-new_v1-1-1';
  const WELCOME_EMAIL_EXISTING_NEW = 'mb-niche-welcome_existing-new_v1-1-0';
  const WELCOME_EMAIL_EXISTING_EXISTING = 'mb-niche-welcome_existing-existing_v1-1-0';
  const MOBILE_COMMONS_SIGNUP = 207601; // Shower Song
  const PHOENIX_SIGNUP = 3590; // Shower Song

  /**
   * Constructor for MBC_UserImport_Source_Nice - extension of the base source class
   * that's specific to Niche.
   */
  public function __construct() {

    parent::__construct();
    $this->sourceName = 'Niche';
    $this->mbcUserImportToolbox = new MBC_UserImport_Toolbox();
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
      throw new Exception('canProcess(), failed FILTER_VALIDATE_EMAIL: ' . $message['email']);
    }
    elseif (isset($message['email'])) {
      $this->message['email'] = filter_var($message['email'], FILTER_VALIDATE_EMAIL);
    }

    if (isset($message['email']) && empty($message['mailchimp_list_id'])) {
      throw new Exception('mailchimp_list_id not set when email is set.');
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
      $this->importUser['user_registration_source'] = $message['source'];
    }
    else {
      $this->importUser['user_registration_source'] = 'Niche';
    }
    if (isset($message['source_file'])) {
      $this->importUser['origin'] = $message['source_file'];
    }
    if (isset($message['activity_timestamp'])) {
      $this->importUser['activity_timestamp'] = $message['activity_timestamp'];
    }

    if (isset($message['email'])) {
      $this->importUser['email'] = $message['email'];
    }
    if (isset($message['mailchimp_list_id'])) {
      $this->importUser['mailchimp_list_id'] = $message['mailchimp_list_id'];
    }

    if (isset($message['name']) && !isset($message['first_name'])) {
      $nameBits = $this->mbcUserImportToolbox->nameBits($message['name']);
      $this->importUser['first_name'] = $nameBits['first_name'];
      $this->importUser['last_name'] = $nameBits['last_name'];
    }

    $firstName = isset($message['first_name']) && $message['first_name'] != '' ? $message['first_name'] : 'DS';
    $this->importUser['password'] = str_replace(' ', '', $firstName) . '-Doer' . rand(1, 1000);

    // Optional fields
    if ($message['birthdate'] > time()) {
      echo '- WARNING: Invalid birthdate: ' . $message['birthdate'] . ' -> date(): ' . date('r', $message['birthdate']), PHP_EOL;
    }
    else {
      if (isset($message['birthdate']) && is_int($message['birthdate'])) {
        $this->importUser['birthdate_timestamp'] = $message['birthdate'];
      }
      elseif (isset($message['birthdate']) && ctype_digit($message['birthdate'])) {
        $this->importUser['birthdate_timestamp'] = (int) $message['birthdate'];
      }
      elseif (isset($message['birthdate']) && is_string($message['birthdate'])) {
        $this->importUser['birthdate_timestamp'] = strtotime($message['birthdate']);
      }
    }
    if (!empty($message['first_name'])) {
      $this->importUser['first_name'] = $message['first_name'];
    }
    if (!empty($message['first_name'])) {
      $this->importUser['merge_vars']['FNAME'] = $message['first_name'];
    }
    if (!empty($message['last_name'])) {
      $this->importUser['last_name'] = $message['last_name'];
    }
    if (!empty($message['last_name'])) {
      $this->importUser['merge_vars']['LNAME'] = $message['last_name'];
    }
    if (!empty($message['password'])) {
      $this->importUser['merge_vars']['PASSWORD'] = $message['password'];
    }
    if (!empty($message['address1'])) {
      $this->importUser['address1'] = $message['address1'];
    }
    if (!empty($message['address2'])) {
      $this->importUser['address2'] = $message['address2'];
    }
    if (!empty($message['city'])) {
      $this->importUser['city'] = $message['city'];
    }
    if (!empty($message['state'])) {
      $this->importUser['state'] = $message['state'];
    }
    if (!empty($message['country'])) {
      $this->importUser['country'] = $message['country'];
    }
    else {
      $this->importUser['country'] = 'US';
    }
    if (!empty($message['zip'])) {
      $this->importUser['zip'] = $message['zip'];
      $this->importUser['postal_code'] = $message['zip'];
    }
    elseif (!empty($message['postal_code'])) {
      $this->importUser['zip'] = $message['postal_code'];
      $this->importUser['postal_code'] = $message['postal_code'];
    }
    // Validate phone number based on the North American Numbering Plan
    // https://en.wikipedia.org/wiki/North_American_Numbering_Plan
    $regex = "/^(\d[\s-]?)?[\(\[\s-]{0,2}?\d{3}[\)\]\s-]{0,2}?\d{3}[\s-]?\d{4}$/i";
    if ((preg_match( $regex, $message['phone']))) {
      $this->importUser['mobile'] = $message['phone'];
    }
    if (isset($message['hs_gradyear']) && $message['hs_gradyear'] != 0) {
      $this->importUser['hs_gradyear'] = $message['hs_gradyear'];
    }
    if (!empty($message['race'])) {
      $this->importUser['race'] = $message['race'];
    }
    if (!empty($message['religion'])) {
      $this->importUser['religion'] = $message['religion'];
    }
    if (!empty($message['hs_name'])) {
      $this->importUser['hs_name'] = $message['hs_name'];
    }
    if (!empty($message['college_name'])) {
      $this->importUser['college_name'] = $message['college_name'];
    }
    if (!empty($message['major_name'])) {
      $this->importUser['major_name'] = $message['major_name'];
    }
    if (!empty($message['degree_type'])) {
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
    if (!empty($message['role'])) {
      $this->importUser['role'] = $message['role'];
    }

  }

  /**
   * process(): Functional hum of class specific to the source. Defined steps specific
   * to Niche user import.
   */
  public function process() {

    $payload = $this->addCommonPayload($this->importUser);
    $existing['log-type'] = 'user-import-niche';
    $existing['source'] = $payload['source'];

    // Add welcome email details to payload
    $this->addWelcomeEmailSettings($this->importUser, $payload);
    
    // Check for existing email account in MailChimp
    $this->mbcUserImportToolbox->checkExistingEmail($this->importUser, $existing);
    if (empty($existing['email'])) {
      $this->addEmailSubscriptionSettings($this->importUser, $payload);
    }
    
    // Drupal user
    $this->mbcUserImportToolbox->checkExistingDrupal($this->importUser, $existing);
    if (empty($existing['drupal-uid'])) {
      $northstarUser = $this->mbToolbox->createNorthstarUser((object) $this->importUser);

      $this->addImportUserInfo($northstarUser->data);
      $drupalUID = $northstarUser->data->drupal_id;
      $passwordResetURL = $this->mbToolbox->getPasswordResetURL($drupalUID);
      // #1, user_welcome, New/New
      $payload['email_template'] = self::WELCOME_EMAIL_NEW_NEW;
      $payload['tags'][0] = 'user-welcome-niche';
      $payload['tags'][1] = self::WELCOME_EMAIL_NEW_NEW;
      $payload['merge_vars']['PASSWORD_RESET_LINK'] = $passwordResetURL;
    }
    else {
      // Existing Drupal user. Set UID for campaign signup
      $drupalUID = $existing['drupal-uid'];
      // #2, current_user, Existing/New
      $payload['email_template'] = self::WELCOME_EMAIL_EXISTING_NEW;
      $payload['tags'][0] = 'current-user-welcome-niche';
      $payload['tags'][1] = self::WELCOME_EMAIL_EXISTING_NEW;
    }

    // Campaign signup
    $campaignNID = self::PHOENIX_SIGNUP;
    $campaignSignup = $this->mbcUserImportToolbox->campaignSignup($campaignNID, $drupalUID, 'niche', false);
    if (!$campaignSignup) {
      // User was not signed up to campaign because they're already signed up.
      // #3, current_signedup, Existing/Existing
      $payload['email_template'] = self::WELCOME_EMAIL_EXISTING_EXISTING;
      $payload['tags'][0] = 'current-signedup-user-welcome-niche';
      $payload['tags'][1] = self::WELCOME_EMAIL_EXISTING_EXISTING;
    }

    // Check for existing user account in Mobile Commons
    $this->mbcUserImportToolbox->checkExistingSMS($this->importUser, $existing);
    
    // Add SMS welcome details to payload - all users should be attempted to be added to
    // MOBILE_COMMONS_SIGNUP opt_id
    $this->addWelcomeSMSSettings($this->importUser, $payload);

    // @todo: transition to using JSON formatted messages when all of the consumers are able to
    // detect the message format and process either seralized or JSON.
    $message = serialize($payload);
    $this->messageBroker_transactionals->publish($message, 'user.registration.transactional');
    $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Source_Niche: process', 1);

    // Log existing users
    $this->mbcUserImportToolbox->logExisting($existing, $this->importUser);
  }

  /**
   * addWelcomeEmailSettings(): Initial settings related to initial welcome messages.
   *
   * @param array $user
   *   User settings
   * @param array $payload
   *   Settings for submission to service specific to the user and what they signed up for.
   */
  public function addWelcomeEmailSettings($user, &$payload) {

    $payload['email'] = $user['email'];
    $payload['merge_vars'] = [
      'MEMBER_COUNT' => $this->memberCount,
      'FNAME' => $user['first_name']
    ];
    $payload['tags'] = [
      0 => 'user_welcome-niche',
    ];
  }

  /**
   * addCommonPayload(): OPayload values common to all message for submission to all services and exchanges.
   *
   * @param array $user
   *   Values related to the user being processed.
   *
   * @return array $payload
   *   Constructed values for submission to exchanges for message distribution.
   */
  public function addCommonPayload($user) {

    $payload = $this->mbcUserImportToolbox->addCommonPayload($user);
    $payload['activity'] = 'user_welcome-niche';
    $payload['source'] = 'niche';

    return $payload;
  }

  /**
   * addEmailSubscriptionSettings(): Settings related to email services.
   *
   * @param array $user
   *   User settings
   * @param array $payload
   *   Settings for submission to service specific to the user and what they signed up for.
   */
  public function addEmailSubscriptionSettings($user, &$payload) {

    if (isset($user['mailchimp_list_id'])) {
      $payload['mailchimp_list_id'] = $user['mailchimp_list_id'];
    }
    else {
      $payload['mailchimp_list_id'] = 'f2fab1dfd4';
    }
  }

  /**
   * addWelcomeSMSSettings(): Settings related to SMS services.
   *
   * @param array $user
   *   User settings
   * @param array $payload
   *   Settings for submission to service specific to the user and what they signed up for.
   */
  public function addWelcomeSMSSettings($user, &$payload) {

    if (isset($user['mobile'])) {
      $payload['mobile'] = $user['mobile'];
      $payload['mobile_opt_in_path_id'] = self:: MOBILE_COMMONS_SIGNUP;
    }
  }

  /**
   * sendPasswordResetEmail(): Details about sending password reset email.
   */
  public function sendPasswordResetEmail() {

  }

  /**
   * addImportUserInfo() Details about the Drulal user created for the user import.
   *
   * @param object $drupalUser
   *   The user object created by the call to the Drupal API.
   */
  public function addImportUserInfo($drupalUser) {

    $this->importUser['uid'] = $drupalUser->drupal_id;
  }

}
