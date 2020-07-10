# Acquia Migrate: Accelerate

## What's this then?

This is a Drupal 9 module that provides a set of tools for accelerating a Drupal 7 → Drupal 9 migration.

### Features:
- Provides a React-based UI for performing Drupal 7 → 9 migrations
- Migration Dashboard provides an overview of overall data migration progress
- Supports Import/Rollback/Rollback and Import of migrations
- Is smart about dependencies: dependencies must be imported first
- Preview displays incoming content prior to importing
- Messages pane allows viewing/filtering migration messages
- Catches entity validation errors in addition to migration errors
- and so much more! 😊

## Specifying source database and files
Note: This step will no longer be required once the environment is generated from Acquia Cloud.

You only need to set the private file path if applicable.

Open your Drupal 9 site's `sites/default/settings.php`, create a new `$databases['migrate']` entry (the key must be named `migrate`!), and specify the Drupal 7 source database. Also specify the **base path** for your Drupal 7 site (so that `sites/default/files` is a subdirectory). Like so:

```
    $databases['migrate']['default'] = array (
      'database' => 'my_d7_site_database',
      'username' => 'root',
      'password' => 'root',
      'prefix' => '',
      'host' => 'localhost',
      'port' => '3306',
      'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
      'driver' => 'mysql',
    );
    // The directory specified here must contain the directory specified in the
    // "file_public_path" Drupal 7 variable. Usually: "sites/default/files".
    $settings['migrate_source_base_path'] = '/web/vhosts/my-d7-site.com';
    // The directory specified here must contain the directory specified in the
    // "file_private_path" Drupal 7 variable. Usually outside the web root.
    $settings['migrate_source_private_file_path'] = '/somewhere/private';
```

## Troubleshooting

### I go to `/upgrade/migrations` I get "An unrecognized error occurred." What gives?!

This is normally caused by Drupal issuing a 500 error. Go to `/admin/reports/dblog` and see if that holds any clues. Another common troubleshooting step is to clear the cache. (Navigate to `/admin/config/development/performance` or run `drush cr`)

### I'm getting a ton of "can't find files" errors when attempting to migrate Public files. HALP!

Remember that public files need the _base_ path to the files directory (in other words, the _parent_ directory of where the `/files` path resides), not the files directory itself.

### I found something not listed here, or I have other feedback!
Please file a Zendesk ticket against the 'Beta Programs' ticket type.

## License

Use of _Acquia Migrate: Accelerate_ is subject to the terms in the included `LICENSE.txt` and the included beta agreement.
