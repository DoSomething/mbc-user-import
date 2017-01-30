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

  // Import source name.
  const SOURCE_NAME = 'niche';

  // Mailchimp List Id:
  // Do Something Members
  // https://us4.admin.mailchimp.com/lists/members/?id=71893
  const MAILCHIMP_LIST_ID = 'f2fab1dfd4';

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
    $this->sourceName = self::SOURCE_NAME;
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
      'source' => self::SOURCE_NAME,
    ];

    // Northstar fields basic mapping.
    $mapping = [
      // Northstar => Import
      'source_detail' => 'source_file',
      // Name:
      'first_name' => 'first_name',
      'last_name' => 'last_name',
      // Address:
      'addr_street1' => 'address1',
      'addr_street2' => 'address2',
      'addr_city' => 'city',
      'addr_state' => 'state',
    ];

    foreach ($mapping as $northstar_field => $import_field) {
      if (!empty($message[$import_field])) {
        $this->user[$northstar_field] = $message[$import_field];
      }
    }

    // Custom logic fields.
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
    // Country. Assume users are from the US.
    $this->user['country'] = !empty($message['country']) ? $message['country'] : 'US';
    // Zip. Handle both `zip` and `postal_code` import field names.
    if (!empty($message['zip'])) {
      $this->user['addr_zip'] = $message['zip'];
    } elseif (!empty($message['postal_code'])) {
      $this->user['addr_zip'] = $message['postal_code'];
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
    // User status variables.
    $userIsNew = false;
    $userIsNewToCampaign = false;

    // Lookup on Northstar by email.
    $identityByEmail = $this->northstar->getUser('email', $this->user['email']);
    if (!empty($this->user['mobile'])) {
      $identityByMobile = $this->northstar->getUser('mobile', $this->user['mobile']);
    } else {
      $identityByMobile = false;
    }

    // Process all possible cases and merge data based on identity load results:
    if (empty($identityByEmail) && empty($identityByMobile)) {
      // ****** New user ******
      $userIsNew = true;
      $userIsNewToCampaign = true;

      // Create Northstar and Phoenix accounts.
      self::log(
        'User not found, creating new user on Northstar: %s',
        json_encode($this->user)
      );
      $identity = $this->northstar->createUser($this->user);
    } elseif (!empty($identityByEmail) && empty($identityByMobile)) {
      // ****** Existing user: only email record exists ******
      $identity = &$identityByEmail;
      self::log(
        'User identified by email %s as %s',
        $this->user['email'],
        $identity->id
      );

      // Save mobile number to record loaded by email.
      if (!empty($this->user['mobile'])) {
        self::log(
          'Updating user %s mobile phone from "%s" to "%s"',
          $identity->id,
          ($identity->mobile ?: "NULL"),
          $this->user['mobile']
        );

        $params = ['mobile' => $this->user['mobile']];
        $identity = $this->northstar->updateUser($identity->id, $params);
      }
    } elseif (!empty($identityByMobile) && empty($identityByEmail)) {
      // ****** Existing user: only mobile record exists ******
      $identity = &$identityByMobile;
      self::log(
        'User identified by mobile %s as %s',
        $this->user['mobile'],
        $identity->id
      );

      // Save email to record loaded by mobile.
      self::log(
        'Updating user %s email from "%s" to "%s"',
        $identity->id,
        ($identity->email ?: "NULL"),
        $this->user['email']
      );
      $params = ['email' => $this->user['email']];
      $identity = $this->northstar->updateUser($identity->id, $params);
    } elseif ($identityByEmail->id !== $identityByMobile->id) {
      // ****** Existing users: loaded both by mobile and phone ******
      // We presume that user account with mobile number generally have
      // email address as well. For this reason we decided to use
      // identity loaded by mobile rather than by email.
      $identity = &$identityByMobile;

      self::log(
        'User identified by email %s as %s and by mobile %s as %s'
          . ' Selecting mobile identity',
        $this->user['mobile'],
        $identityByMobile->id,
        $this->user['email'],
        $identityByEmail->id
      );

    } elseif ($identityByEmail->id === $identityByMobile->id) {
      // ****** Existing user: same identity loaded both by mobile and phone ******
      $identity = &$identityByEmail;

      self::log(
        'User identified by mobile %s and email %s: %s',
        $this->user['mobile'],
        $this->user['email'],
        $identity->id
      );
    }

    // Something went very wrong.
    if (empty($identity)) {
      throw new Exception(
        'This will only execute when user identity logic is broken'
      );
    }

    // Northstar record has no phoenix id on it.
    if (empty($identity->drupal_id)) {
      throw new Exception(
        'MBC_UserImport_Source_Niche->process() - '
        . 'Northstar user identity has no drupal_id record.'
        . ' User input: ' . json_encode($this->user)
        . ', Northstar id: ' . $identity->id
      );
    }

    // Signup user to promo campaign if they already hasn't been subscribed.
    $signup = $this->mbcUserImportToolbox->campaignSignup(
      self::PHOENIX_SIGNUP,
      $identity->drupal_id,
      self::SOURCE_NAME
    );

    if ($signup === true) {
      // User has already been subscribed.
      self::log(
        'User %s (phoenix %s) has already been subscribed to campaign %s',
        $identity->id,
        $identity->drupal_id,
        self::PHOENIX_SIGNUP
      );
    } else {
      // New signup has been created.
      $userIsNewToCampaign = true;
      self::log(
        'New signup %s to %s created for %s (phoenix %s)',
        $signup,
        self::PHOENIX_SIGNUP,
        $identity->id,
        $identity->drupal_id
      );
    }

    // Build new payload to delegate further processing to common flow.
    $payload = $this->mbcUserImportToolbox->addCommonPayload();
    $payload['activity'] = 'user_welcome-niche';
    $payload['source'] = self::SOURCE_NAME;
    $payload['email'] = $identity->email;
    $payload['tags'][] = self::SOURCE_NAME;
    $payload['merge_vars'] = [
      'MEMBER_COUNT' => $this->memberCount,
      'FNAME' => $identity->first_name,
    ];

    if ($userIsNew) {
      // ****** New user, new subscription ******
      $payload['email_template'] = self::WELCOME_EMAIL_NEW_NEW;
      $payload['tags'][] = 'niche-new-new';
      // Generate new reset password link.
      $passwordResult = $this->northstar->post('v2/resets', ['id' => $identity->id]);
      if (empty($passwordResult['url'])) {
        throw new Exception("Can't get password reset for " . $identity->id);
      }
      $payload['merge_vars']['PASSWORD_RESET_LINK'] = $passwordResult['url'];
    } else {
      if ($userIsNewToCampaign) {
        // ****** Existing user, new subscription ******
        $payload['email_template'] = self::WELCOME_EMAIL_EXISTING_NEW;
        $payload['tags'][] = 'niche-existing-new';

        // Add new signup data.
        $payload['event_id'] = self::PHOENIX_SIGNUP;
        $payload['signup_id'] = $signup;
      } else {
        // ****** Existing user, existing subscription ******
        $payload['email_template'] = self::WELCOME_EMAIL_EXISTING_EXISTING;
        $payload['tags'][] = 'niche-existing-existing';
      }
    }
    $payload['tags'][] = $payload['email_template'];

    // Determine user's MailChimp subscription status, resubscribe if necessary.
    $mailchimpStatus = $this->mbcUserImportToolbox->getMailchimpStatus(
      $identity->email,
      self::MAILCHIMP_LIST_ID
    );
    if (!$mailchimpStatus) {
      // User has no account on MailChimp with us.
      $payload['mailchimp_list_id'] = self::MAILCHIMP_LIST_ID;
      self::log(
        'Will subscribe email %s to MailChimp list id %s',
        $identity->email,
        self::MAILCHIMP_LIST_ID
      );
    } elseif (!$mailchimpStatus['email-subscription-status']) {
      // User has unsubscribed.
      $payload['mailchimp_list_id'] = self::MAILCHIMP_LIST_ID;
      self::log(
        'User %s has unsubscribed from MailChimp list id %s'
        . ', will attempt to resubscribe them',
        $identity->email,
        self::MAILCHIMP_LIST_ID,
        $mailchimpStatus['email-acquired']
      );
    } else {
      // User is an active Mailchimp subscriber.
      self::log(
        'User %s is an active subscriber of MailChimp list id %s since %s',
        $identity->email,
        self::MAILCHIMP_LIST_ID,
        $mailchimpStatus['email-acquired']
      );
    }

    // Publish the payload.
    $this->messageBroker_transactionals->publish(
      serialize($payload),
      'user.registration.transactional'
    );
    self::log('Publishing payload: %s', json_encode($payload));
    $this->statHat->ezCount('mbc-user-import: MBC_UserImport_Source_Niche: process');

    // Determine user's membership.
    // User is considered DoSomething member when ONE of the following is true:
    // 1. User has a profile on Northstar [ OR ]
    $membership = !$userIsNew;

    // 2. User is our MailChimp subscriber [ OR ]
    $membership |= !$mailchimpStatus;

    // 3. User is our MobileCommons subscriber
    // This MobileCommons request is super ugly.
    // Keeping it for compatibility with AfterShool.
    $mocoStatus = [];
    $this->mbcUserImportToolbox->getMobileCommonsStatus($this->user, $mocoStatus);
    $membership |= !$mailchimpStatus;

    // If user is our member, we'll log that.
    if ($membership) {
      $payloadLog = [];
      $payloadLog['log-type'] = 'user-import-niche';
      $payloadLog['source'] = self::SOURCE_NAME;
      if ($mailchimpStatus) {
        $payloadLog = array_merge($payloadLog, $mailchimpStatus);
      }
      if ($mocoStatus) {
        $payloadLog = array_merge($payloadLog, $mocoStatus);
      }
      // Legacy.
      if (!$userIsNew) {
        $payloadLog['drupal-uid'] = $identity->drupal_id;
        $payloadLog['drupal-email'] = $identity->email;
        $payloadLog['drupal-mobile'] = $identity->mobile;
      }

      // Legacy. Second argument is just silly.
      $origin = !empty($this->user['source_detail']) ? $this->user['source_detail'] : 'undetermined';
      self::log(
        'User identified as DoSomething member, logging: %s',
        json_encode($payloadLog)
      );
      $this->mbcUserImportToolbox->logExisting($payloadLog, ['origin' => $origin]);
    }
  }

  /** Bad OOP is bad OOP */
  public function addEmailSubscriptionSettings($user, &$payload) {}
  public function addWelcomeEmailSettings($user, &$payload) {}
  public function addCommonPayload() {}
  public function addWelcomeSMSSettings($user, &$payload) {}
}
