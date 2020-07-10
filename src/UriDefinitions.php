<?php

namespace Drupal\acquia_migrate;

/**
 * Provides link relations for the HTTP API.
 *
 * @internal
 */
final class UriDefinitions {

  /**
   * The link relation URI for querying a context collection resource.
   *
   * @const string
   *
   * @see https://github.com/acquia/acquia_migrate/wiki/Internal-Documentation#query
   */
  const LINK_REL_QUERY = 'https://github.com/acquia/acquia_migrate#link-rel-query';

  /**
   * The link relation URI for messages related to a migration.
   *
   * @const string
   *
   * @see https://github.com/acquia/acquia_migrate/wiki/Internal-Documentation#migration-messages
   */
  const LINK_REL_MIGRATION_MESSAGES = 'https://github.com/acquia/acquia_migrate#link-rel-migration-messages';

  /**
   * The link relation URI for the mapping powering a migration.
   *
   * @const string
   *
   * @see https://github.com/acquia/acquia_migrate/wiki/Internal-Documentation#migration-mapping
   */
  const LINK_REL_MAPPING = 'https://github.com/acquia/acquia_migrate#link-rel-migration-mapping';

  /**
   * The link relation URI for overriding a field in a migration's mapping.
   *
   * @const string
   *
   * @see https://github.com/acquia/acquia_migrate/wiki/Developer-docs#migration-mapping-override-field
   */
  const LINK_REL_MAPPING_OVERRIDE_FIELD = 'https://github.com/acquia/acquia_migrate#link-rel-migration-mapping-override-field';

  /**
   * The link relation URI for updating migration in bulk.
   *
   * @const string
   *
   * @see https://github.com/acquia/acquia_migrate/wiki/Internal-Documentation#bulk-update-migrations
   */
  const LINK_REL_BULK_UPDATE_MIGRATIONS = 'https://github.com/acquia/acquia_migrate#link-rel-bulk-update-migrations';

  /**
   * The link relation URI for pre-selecting migrations for initial import.
   *
   * This is a specialization of the bulk update link relation as its presence
   * has additional significance. That is, it indicates that the user agent must
   * first choose which migrations to import initially before proceeding.
   *
   * @const string
   *
   * @see https://github.com/acquia/acquia_migrate/wiki/Internal-Documentation#preselect-migrations
   */
  const LINK_REL_PRESELECT_MIGRATIONS = 'https://github.com/acquia/acquia_migrate#link-rel-preselect-migrations';

  /**
   * The link relation URI for starting a batch process.
   *
   * @const string
   *
   * @see https://github.com/acquia/acquia_migrate/wiki/Internal-Documentation#start-batch-process
   */
  const LINK_REL_START_BATCH_PROCESS = 'https://github.com/acquia/acquia_migrate#link-rel-start-batch-process';

  /**
   * The link relation URI for the syslog severity as defined in RFC5424.
   *
   * @const string
   *
   * @see https://github.com/acquia/acquia_migrate/wiki/Internal-Documentation#syslog-severity
   * @see https://tools.ietf.org/html/rfc5424
   */
  const LINK_REL_SYSLOG_SEVERITY = 'https://github.com/acquia/acquia_migrate#link-rel-syslog-severity';

  /**
   * The link relation URI for updating a JSON:API resource.
   *
   * @const string
   *
   * @see https://github.com/acquia/acquia_migrate/wiki/Internal-Documentation#update-resource
   */
  const LINK_REL_UPDATE_RESOURCE = 'https://github.com/acquia/acquia_migrate#link-rel-update-resource';

  /**
   * The link relation URI for previewing a migration row.
   *
   * @const string
   *
   * @see https://github.com/acquia/acquia_migrate/wiki/Internal-Documentation#preview-migration-row
   */
  const LINK_REL_PREVIEW = 'https://github.com/acquia/acquia_migrate#link-rel-preview-migration-row';

  /**
   * The link relation URI for an unmet requirement.
   *
   * @const string
   *
   * @see https://github.com/acquia/acquia_migrate/wiki/Internal-Documentation#unmet-requirement
   */
  const LINK_REL_UNMET_REQUIREMENT = 'https://github.com/acquia/acquia_migrate#link-rel-unmet-requirement';

  /**
   * The extension URI for a JSON:API URI Template extension.
   *
   * @const string
   *
   * @see https://jsonapi.org/format/1.1/#extensions
   * @see https://tools.ietf.org/html/rfc6570
   */
  const EXTENSION_URI_TEMPLATE = 'https://github.com/acquia/acquia_migrate#jsonapi-extension-uri-template';

}
