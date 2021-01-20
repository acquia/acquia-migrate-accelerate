<?php

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

use Drupal\language\Plugin\migrate\destination\DefaultLangcode;

/**
 * Provides rollbackable default_langcode destination plugin.
 *
 * @see \Drupal\language\Plugin\migrate\destination\DefaultLangcode
 *
 * @internal
 *
 * @MigrateDestination(
 *   id = "rollbackable_default_langcode"
 * )
 */
class RollbackableDefaultLangcode extends DefaultLangcode implements RollbackableInterface {

  use RollbackableConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected $supportsRollback = TRUE;

}
