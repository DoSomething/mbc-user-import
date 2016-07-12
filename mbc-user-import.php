<?php
/**
 * mbc-user-import.php
 *
 * Consume queue entries in userImportQueue to import user data supplied by
 * co-marketing partners of users interested in DoSomething.
 *
 * Based on the user source, each entry will result in a combination of:
 *   - User creation in the Drupal website
 *   - An entry in mb-users database via mb-userAPI
 *   - Mailchimp entry
 *   - Mandrill transactional signup email message
 *   - SMS transactional via Mobile Commons (US / Canada) or mGage (future - MX / BR)
 */

use DoSomething\MBC_UserImport\MBC_UserImport_Consumer;

date_default_timezone_set('America/New_York');
define('CONFIG_PATH', __DIR__ . '/messagebroker-config');

// Manage $_enviroment setting
if (isset($_GET['enviroment']) && allowedEnviroment($_GET['enviroment'])) {
    define('ENVIROMENT', $_GET['enviroment']);
} elseif (isset($argv[1])&& allowedEnviroment($argv[1])) {
    define('ENVIROMENT', $argv[1]);
} elseif (allowedEnviroment('local')) {
    define('ENVIROMENT', 'local');
}

// The number of messages for the consumer to reserve with each callback
// See consumeMwessage for further details.
// Necessary for parallel processing when more than one consumer is running on the same queue.
define('QOS_SIZE', 1);

// Load up the Composer autoload magic
require_once __DIR__ . '/vendor/autoload.php';

// Load configuration settings specific to this application
require_once __DIR__ . '/mbc-user-import.config.inc';

// Kick off - block, wait for messages in queue
echo '------- mbc-user-import START: ' . date('j D M Y G:i:s T') . ' -------', PHP_EOL;
$mb = $mbConfig->getProperty('messageBroker');
$mb->consumeMessage([new MBC_UserImport_Consumer(), 'consumeUserImportQueue'], QOS_SIZE);
echo '------- mbc-user-import END: ' . date('j D M Y G:i:s T') . ' -------', PHP_EOL;

/**
 * Test if enviroment setting is a supported value.
 *
 * @param string $setting Requested enviroment setting.
 *
 * @return boolean
 */
function allowedEnviroment($setting)
{

    $allowedEnviroments = [
        'local',
        'dev',
        'prod'
    ];

    if (in_array($setting, $allowedEnviroments)) {
        return true;
    }

    return false;
}
