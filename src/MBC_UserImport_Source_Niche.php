<?php
/**
 * A consumer application for a user import system. Various source types define
 * specifics as to how the user data should be injested from various sources including
 * CSV files or services. User data is distributed to other consumers in the Quicksilver
 * system for further processing as well as triggering transactional messaging related to
 * welcoming the user to DoSomething.org.
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

use DoSomething\MBC_UserImport\MBC_UserImport_BaseSource;
use \Exception;

/**
 * Class MBC_UserImport_Source_Niche: Functionality specific to processing users
 * the source niche.com.
 *
 * @category PHP
 * @package  MBC_UserImport
 * @author   DeeZone <dlee@dosomething.org>
 * @license  MIT: https://en.wikipedia.org/wiki/MIT_License
 * @version  "Release: <package_version>"
 * @link     https://github.com/DoSomething/mbc-user-import/blob/master/src
 *           /MBC_UserImport_Source_Niche.php
 */
class MBC_UserImport_Source_Niche extends MBC_UserImport_BaseSource
{
  // Mandrill email templates.
  const WELCOME_EMAIL_NEW_NEW = 'mb-niche-welcome_new-new_v1-9-0';
  const WELCOME_EMAIL_EXISTING_NEW = 'mb-niche-welcome_existing-new_v1-9-0';
  const WELCOME_EMAIL_EXISTING_EXISTING = 'mb-niche-welcome_existing-existing_v1-9-0';

  // Off.
  const MOBILE_COMMONS_SIGNUP = false;

  // New Year, New US
  // https://www.dosomething.org/us/campaigns/new-year-new-us
  const PHOENIX_SIGNUP = 3616;

  /**
   * Northstar-compatible user
   *
   * @var array
   */
  private $user;

  /**
   * Constructor for MBC_UserImport_Source_Nice - extension of the base source
   * class that's specific to Niche.
   */
  public function __construct()
  {
    parent::__construct();
    $this->sourceName = 'Niche';
    $this->mbcUserImportToolbox = new MBC_UserImport_Toolbox();
  }

  /**
   * Test if message can be processed by consumer.
   *
   * @param array $message The message contents to test if it can be processed.
   *
   * @return boolean Can the message be processed.
   *
   * @throws Exception
   */
  public function canProcess($message)
  {

    if (empty($message['email'])) {
      echo '- canProcess(), email not set.', PHP_EOL;
      parent::reportErrorPayload();
      return false;
    }

    if (filter_var($message['email'], FILTER_VALIDATE_EMAIL) === false) {
      echo '- canProcess(), failed FILTER_VALIDATE_EMAIL: '
        . $message['email'], PHP_EOL;
      parent::reportErrorPayload();
      throw new Exception(
        'canProcess(), failed FILTER_VALIDATE_EMAIL: '
        . $message['email']
      );
    } elseif (isset($message['email'])) {
      $this->message['email'] = filter_var(
        $message['email'],
        FILTER_VALIDATE_EMAIL
      );
    }

    if (isset($message['email']) && empty($message['mailchimp_list_id'])) {
      throw new Exception('mailchimp_list_id not set when email is set.');
    }

    return true;
  }

  /**
   * Assign values from message to class propertry for processing.
   *
   * @param array $message The values from the message consumed from the queue.
   *
   * @return null
   */
  public function setter($message)
  {
    // Required minimum.
    $this->user = [
      'email'  => $message['email'],
      'source' => 'niche',
    ];

    // Mobile.
    if (!empty($message['phone'])) {
      // Validate phone number based on the North American Numbering Plan
      // https://en.wikipedia.org/wiki/North_American_Numbering_Plan
      $pattern = "/^(\d[\s-]?)?[\(\[\s-]{0,2}?\d{3}[\)\]\s-]{0,2}?\d{3}[\s-]?\d{4}$/i";
      if (preg_match($pattern, $message['phone']) !== false) {
        $this->user['mobile'] = $message['phone'];
      }
    }

    // Birthday.
    if (!empty($message['birthday'])) {
      if (is_int($message['birthdate']) || ctype_digit($message['birthdate'])) {
        $this->user['birthdate'] = (int) $message['birthdate'];
      } else {
        $this->user['birthdate'] = strtotime($message['birthdate']);
      }
    }

    // First name.
    if (!empty($message['first_name'])) {
      $this->user['first_name'] = $message['first_name'];
    }

    // Last name.
    if (!empty($message['last_name'])) {
      $this->user['last_name'] = $message['last_name'];
    }

    // Address.
    if (!empty($message['address1'])) {
      $this->user['addr_street1'] = $message['address1'];
    }
    if (!empty($message['address2'])) {
      $this->user['addr_street2'] = $message['address2'];
    }
    if (!empty($message['city'])) {
      $this->user['addr_city'] = $message['city'];
    }
    if (!empty($message['state'])) {
      $this->user['addr_state'] = $message['state'];
    }
    if (!empty($message['zip'])) {
      $this->user['addr_zip'] = $message['zip'];
    } elseif (!empty($message['postal_code'])) {
      $this->user['addr_zip'] = $message['postal_code'];
    }

    // Country.
    if (!empty($message['country'])) {
      $this->user['country'] = $message['country'];
    } else {
      // Assume users are from the US.
      $this->user['country'] = 'US';
    }
  }

  /**
   * Functional hum of class specific to the source. Defined steps specific to
   * Niche user import.
   *
   * @return null
   */
  public function process()
  {
    // Shortcuts.
    $northstar = &$this->northstar;
    $input = &$this->user;

    // User status variables.
    $userIsNew = false;
    $userIsNewToCampaign = false;

    // Lookup on Northstar by email.
    $identityByEmail = $northstar->getUser('email', $input['email']);
    if (!empty($input['mobile'])) {
      $identityByMobile = $northstar->getUser('mobile', $input['mobile']);
    } else {
      $identityByMobile = false;
    }

    // Process all possible cases and merge data based on identity load results:
    if (empty($identityByEmail) && empty($identityByMobile)) {
      // ****** New user ******
      $userIsNew = true;
      $userIsNewToCampaign = true;

      // Create Norhtstar and Phoenix accounts.
      $this->log(
        'User not found, creating new user on Northstar: %s',
        json_encode($input)
      );
      $identity = $northstar->createUser($input);
    } elseif (!empty($identityByEmail) && empty($identityByMobile)) {
      // ****** Existing user: only email record exists ******
      $identity = &$identityByEmail;
      $this->log(
        'User identified by email %s as %s',
        $input['email'],
        $identity->id
      );

      // Save mobile number to record loaded by email.
      if (!empty($input['mobile'])) {
        $this->log(
          'Updating user %s mobile phone from "%s" to "%s"',
          $identity->id,
          ($identity->mobile ?: "NULL"),
          $input['mobile']
        );

        $params = ['mobile' => $input['mobile']];
        $identity = $northstar->updateUser($identity->id, $params);
      }
    } elseif (!empty($identityByMobile) && empty($identityByEmail)) {
      // ****** Existing user: only mobile record exists ******
      $identity = &$identityByMobile;
      $this->log(
        'User identified by mobile %s as %s',
        $input['mobile'],
        $identity->id
      );

      // Save email to record loaded by mobile.
      $this->log(
        'Updating user %s email from "%s" to "%s"',
        $identity->id,
        ($identity->email ?: "NULL"),
        $input['email']
      );
      $params = ['email' => $input['email']];
      $identity = $northstar->updateUser($identity->id, $params);
    } elseif ($identityByEmail->id !== $identityByMobile->id) {
      // ****** Existing users: loaded both by mobile and phone ******
      // We presume that user account with mobile number generaly have
      // email address as well. For this reason we decided to use
      // identity loaded by mobile rather than by email.
      $identity = &$identityByMobile;

      $this->log(
        'User identified by email %s as %s and by mobile %s as %s.'
          . ' Selecting mobile identity.',
        $input['mobile'],
        $identityByMobile->id,
        $input['email'],
        $identityByEmail->id
      );

    } elseif ($identityByEmail->id === $identityByMobile->id) {
      // ****** Existing user: same identity loaded both by mobile and phone ******
      $identity = &$identityByEmail;
    }

    // Something went very wrong.
    if (empty($identity)) {
      throw new Exception(
        'This will only execute when user identity logic is broken.'
      );
    }

    var_dump($identity->id); die();




    print 'User is new: ';
    var_dump($userIsNew);
    print 'User is not subscribed to campaign: ';
    var_dump($userIsNewToCampaign);

    var_dump($input, $identity); die();

  
    // $payload = $this->addCommonPayload($this->importUser);
    // $existing['log-type'] = 'user-import-niche';
    // $existing['source'] = $payload['source'];

    // // Add welcome email details to payload
    // $this->addWelcomeEmailSettings($this->importUser, $payload);

    // // Check for existing email account in MailChimp
    // $subscribed = $this->mbcUserImportToolbox->checkExistingEmail(
    //   $this->importUser,
    //   $existing
    // );
    // if (!$subscribed) {
    //   $this->addEmailSubscriptionSettings($this->importUser, $payload);
    // }

    // // Drupal user
    // $this->mbcUserImportToolbox->checkExistingDrupal(
    //   $this->importUser,
    //   $existing
    // );
    // if (empty($existing['drupal-uid'])) {
    //   $importUser = (object) $this->importUser;
    //   // Set user registration source.
    //   $importUser->source = 'niche';

    //   // Lookup user on Northstar.
    //   $northstarUser = $this->mbToolbox->lookupNorthstarUser($importUser);
    //   if ($northstarUser && !empty($northstarUser->drupal_id)) {
    //     // User is missing from phoenix, but present on Northstar.
    //     // Sync credentials:
    //     $importUser->email = $northstarUser->email;
    //     $importUser->mobile = $northstarUser->mobile;
    //   }

    //   // Trigger user creation on Northstar, will force-create user
    //   // user on Phoenix.
    //   $northstarUser = $this->mbToolbox->createNorthstarUser($importUser);

    //   if (empty($northstarUser->drupal_id)) {
    //     throw new Exception(
    //       'MBC_UserImport_Source_Niche->process() - No Drupal Id provided by Northstar.'
    //       . ' Response: ' . var_export($northstarUser, true)
    //     );
    //   }

    //   $this->addImportUserInfo($northstarUser);
    //   $drupalUID = $northstarUser->drupal_id;
    //   $fbirthdateResetURL = $this->mbToolbox->getPasswordResetURL($drupalUID);
    //   // #1, user_welcome, New/New
    //   $payload['email_template'] = self::WELCOME_EMAIL_NEW_NEW;
    //   $payload['tags'][0] = 'user-welcome-niche';
    //   $payload['tags'][1] = self::WELCOME_EMAIL_NEW_NEW;
    //   $payload['merge_vars']['PASSWORD_RESET_LINK'] = $passwordResetURL;
    // } else {
    //   // Existing Drupal user. Set UID for campaign signup
    //   $drupalUID = $existing['drupal-uid'];
    //   // #2, current_user, Existing/New
    //   $payload['email_template'] = self::WELCOME_EMAIL_EXISTING_NEW;
    //   $payload['tags'][0] = 'current-user-welcome-niche';
    //   $payload['tags'][1] = self::WELCOME_EMAIL_EXISTING_NEW;
    // }

    // // Campaign signup
    // $campaignNID = self::PHOENIX_SIGNUP;
    // $campaignSignup = $this->mbcUserImportToolbox->campaignSignup(
    //   $campaignNID,
    //   $drupalUID,
    //   'niche',
    //   false
    // );

    // if (!$campaignSignup) {
    //   // User was not signed up to campaign because they're already signed up.
    //   // #3, current_signedup, Existing/Existing
    //   $payload['email_template'] = self::WELCOME_EMAIL_EXISTING_EXISTING;
    //   $payload['tags'][0] = 'current-signedup-user-welcome-niche';
    //   $payload['tags'][1] = self::WELCOME_EMAIL_EXISTING_EXISTING;
    // } else {
    //   $payload['event_id'] = $campaignNID;
    //   $payload['signup_id'] = $campaignSignup;
    // }

    // // Check for existing user account in Mobile Commons
    // $this->mbcUserImportToolbox->checkExistingSMS($this->importUser, $existing);

    // // @todo: transition to using JSON formatted messages when all of the
    // // consumers are able to
    // // detect the message format and process either seralized or JSON.
    // $message = serialize($payload);
    // $this->messageBroker_transactionals->publish(
    //   $message,
    //   'user.registration.transactional'
    // );
    // $this->statHat->ezCount(
    //   'mbc-user-import: MBC_UserImport_Source_Niche: process',
    //   1
    // );

    // // Log existing users
    // $this->mbcUserImportToolbox->logExisting($existing, $this->importUser);
  }

  /**
   * Initial settings related to initial welcome messages.
   *
   * @param array $user    User settings.
   * @param array $payload Service values specific to the user.
   *
   * @return array $payload Service values specific to the user.
   */
  public function addWelcomeEmailSettings($user, &$payload)
  {

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
   * Payload values common to all message for submission to all services and
   * exchanges.
   *
   * @param array $user Values related to the user being processed.
   *
   * @return array $payload Values for message distribution.
   */
  public function addCommonPayload($user)
  {

    $payload = $this->mbcUserImportToolbox->addCommonPayload($user);
    $payload['activity'] = 'user_welcome-niche';
    $payload['source'] = 'niche';

    return $payload;
  }

  /**
   * Settings related to email services.
   *
   * @param array $user    User settings
   * @param array $payload Settings for submission to service.
   *
   * @return array $payload Settings for submission to service.
   */
  public function addEmailSubscriptionSettings($user, &$payload)
  {

    if (isset($user['mailchimp_list_id'])) {
      $payload['mailchimp_list_id'] = $user['mailchimp_list_id'];
    } else {
      $payload['mailchimp_list_id'] = 'f2fab1dfd4';
    }
  }

  /**
   * Bad OOP is bad OOP
   */
  public function addWelcomeSMSSettings($user, &$payload) {}

  /**
   * Details about sending password reset email.
   *
   * @return null
   */
  public function sendPasswordResetEmail()
  {
  }

  /**
   * Details about the Drulal user created for the user import.
   *
   * @param object $drupalUser The user object created by Drupal API.
   *
   * @return null
   */
  public function addImportUserInfo($drupalUser)
  {

    $this->importUser['uid'] = $drupalUser->drupal_id;
  }
}
