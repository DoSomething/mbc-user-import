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

use DoSomething\MBC_UserImport\MBC_UserImport_BaseSource;
use \Exception;

/**
 * Class MBC_UserImport_Source_AfterSchool: Functionality specific to processing
 * users the source After School app.
 *
 * @category PHP
 * @package  MBC_UserImport
 * @author   DeeZone <dlee@dosomething.org>
 * @license  MIT: https://en.wikipedia.org/wiki/MIT_License
 * @version  "Release: <package_version>"
 * @link     https://github.com/DoSomething/mbc-user-import/blob/master/src
 *           /MBC_UserImport_Source_AfterSchool.php
 */
class MBC_UserImport_Source_MobileappAndroid extends MBC_UserImport_BaseSource
{
    
    /**
     * Extension of the base source class and construct details specific to After
     * School.
     */
    public function __construct()
    {
        
        parent::__construct();
        $this->sourceName = 'MobileApp_Android';
        $this->mbcUserImportToolbox = new MBC_UserImport_Toolbox();
    }

    /**
     * Test if message can be processed by consumer. Niche user imports must have at
     * least an email address.
     *
     * @param array $message The message contents to test if it can be processed.
     *
     * @return boolean Can the message be processed?
     *
     * @throws Exception
     */
    public function canProcess($message)
    {
    
        if (empty($message['mobile'])) {
             echo '- canProcess(), mobile not set.', PHP_EOL;
             parent::reportErrorPayload();
             throw new Exception('canProcess(), mobile number not set.');
        }

        // Validate phone number based on the North American Numbering Plan
        // https://en.wikipedia.org/wiki/North_American_Numbering_Plan
        $regex
            = "/^(\d[\s-]?)?[\(\[\s-]{0,2}?\d{3}[\)\]\s-]{0,2}?\d{3}[\s-]?\d{4}$/i";
        if (!(preg_match($regex, $message['mobile']))) {
            echo '** canProcess(): Invalid phone number based on North American ' .
              'Numbering Plan standard: ' .  $message['mobile'], PHP_EOL;
            throw new Exception(
                'canProcess(), Invalid phone number based on ' .
                'North American Numbering Plan standard.'
            );
        }

        if (isset($message['mobile']) && empty($message['mobile_opt_in_path_id'])) {
            echo 'mobile_opt_in_path_id not set when mobile is set.', PHP_EOL;
            throw new Exception(
                'canProcess(), mobile_opt_in_path_id not set when ' .
                'mobile is set.'
            );
        }

        return true;
    }

    /**
     * Assign values from message to class propertry for processing.
     *
     * @param array $message The values from the message consumed from the queue are
     *                       assigned to the importUser proerty for processing..
     *
     * @return null
     */
    public function setter($message)
    {
    
        $this->importUser['email'] = $message['email'];
        $this->importUser['mailchimp_list_id'] = $message['mailchimp_list_id'];
        
        if (isset($message['source'])) {
            $this->importUser['user_registration_source'] = $message['source'];
        } else {
            $this->importUser['user_registration_source'] = 'MobileApp-IOS';
        }
        if (isset($message['activity_timestamp'])) {
            $this->importUser['activity_timestamp'] = $message['activity_timestamp'];
        }
        
        if (isset($message['mobile'])) {
            $this->importUser['mobile'] = $message['mobile'];
        }

        if (isset($message['first_name'])) {
            $this->importUser['first_name'] = $message['first_name'];
        }
        
    }

    /**
     * Defined steps to perform specific to After School user import.
     *
     * @return null
     */
    public function process()
    {

        $payload = $this->addCommonPayload($this->importUser);
        $existing['log-type'] = 'user-import-mobileapp-ios';
        $existing['source'] = $payload['source'];

        // @todo: transition to using JSON formatted messages when all of the
        // consumers are able to detect the message format and process either
        // seralized or JSON.
        $message = serialize($payload);
        $this->messageBroker_transactionals->publish(
            $message,
            'user.registration.mobile'
        );
        $this->statHat->ezCount(
            'mbc-user-import: MBC_UserImport_Source_MobileappIos: process',
            1
        );
    }

    /**
     * Add common settings to message payload based on After School user imports
     * requirments.
     *
     * @param array $user Current user data values.
     *
     * @return array $payload Update payload values formatted for distribution to
     * consumers in the Message Broker system.
     */
    public function addCommonPayload($user)
    {

        $payload['activity'] = 'user_welcome-mobileapp-ios';
        $payload['source'] = 'mobileapp-ios';

        return $payload;
    }

    /**
     * NOT USED as Mobile Application as Source
     * Settings specific to welcome email messages
     *
     * @param array $user    Setting specific to the user being imported.
     * @param array $payload Existing based on email and user settings.
     *
     * @return array &$payload Adjust based on email and user settings.
     */
    public function addWelcomeEmailSettings($user, &$payload)
    {
    }

    /**
     * Settings specific to email subscriptions (MailChimp lists).
     *
     * @param array $user    Setting specific to the user being imported.
     * @param array $payload Existing based on email and user settings.
     *
     * @return array &$payload Adjusted based on email and user settings.
     */
    public function addEmailSubscriptionSettings($user, &$payload)
    {

    }

    /**
     * NOT USED as Mobile Application as Source
     * Add settings to message payload that are specific to SMS.
     *
     * @param array $user    Settings specific to the user data being imported.
     * @param array $payload Existing based on email and user settings.
     *
     * @return array &$payload Values formatted for submission to SMS API.
     */
    public function addWelcomeSMSSettings($user, &$payload)
    {
    }
}
