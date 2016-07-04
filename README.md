### User Import System

- [Introduction](https://github.com/DoSomething/mbp-user-import/wiki)
- [Architecture](https://github.com/DoSomething/mbp-user-import/wiki/2.-Architecture)
- [Setup](https://github.com/DoSomething/mbp-user-import/wiki/3.-Setup)
- [Operation](https://github.com/DoSomething/mbp-user-import/wiki/4.-Operation)
- [Monitoring](https://github.com/DoSomething/mbp-user-import/wiki/5.-Monitoring)
- [Problems / Solutions](https://github.com/DoSomething/mbp-user-import/wiki/7.-Problems-%5C--Solutions)

#### 1. [mbp-user-import](https://github.com/DoSomething/mbp-user-import)

An application (producer) in the Quicksilver (Message Broker) system.
Imports user data from CVS formatted files that create message entries
in the `userImportQueue`.

#### 2. [mbc-user-import](https://github.com/DoSomething/mbc-user-import)

An application (consumer) in the Quicksilver (Message Broker) system.
Processes user data import messages in the `userImportQueue`.

#### 3. [mbp-logging-reports](https://github.com/DoSomething/Quicksilver-PHP/tree/master/mbp-logging-reports)

Generate reports of the on going user import process. Reports are sent
through email and Slack.

---

## mbc-user-import

A consumer for the userImport queue that will be used to import
user accounts via inpoort SCV files. Detection of the source is within
the message payloads. Based on the user data and the source activities
are processed:

- Drupal user creation
- Mobile Commons submission
- MailChimp submission
- submission to mb-user-api
- Campaign signup (Drupal site)

Each user submission is checked for existing user accounts in Drupal,
MailChimp and Mobile Commons. Existing detected accounts are logged in
mb-logging.

### Installation

**Production**
- `$ composer install --no-dev`

**Development**
- `*composer install --dev`

### Update

- `$ composer update`

### Test Coverage

**Run all tests**
- `$ ./vendor/bin/phpunit --verbose tests`

or
- `$ npm test`

or
- `$ gulp test`

### PHP CodeSniffer

- `php ./vendor/bin/phpcs --standard=ruleset.xml --colors -s mbc-user-import.php mbc-user-import.config.inc src bin tests`
Listing of all coding volations by file.

- `php ./vendor/bin/phpcbf --standard=ruleset.xml --colors mbc-user-import.php mbc-user-import.config.inc src bin tests`
Automated processing of files to adjust to meeting coding standards.

**References**:
Advanced-Usage
- https://github.com/squizlabs/PHP_CodeSniffer/wiki/Advanced-Usage
Annotated ruleset.xml
- https://pear.php.net/manual/en/package.php.php-codesniffer.annotated-ruleset.php


### Watch Files

Runs PHPUnit tests and basic PHP Lint in a watchful state.

- `gulp`
