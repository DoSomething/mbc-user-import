mbc-user-import
===============

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
