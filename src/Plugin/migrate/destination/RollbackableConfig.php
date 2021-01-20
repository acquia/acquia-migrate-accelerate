<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

use Drupal\migrate\Plugin\migrate\destination\Config as DefaultConfigDestination;

/**
 * Provides rollbackable configuration destination plugin.
 *
 * @see \Drupal\migrate\Plugin\migrate\destination\Config
 *
 * @internal
 *
 * @MigrateDestination(
 *   id = "rollbackable_config"
 * )
 */
final class RollbackableConfig extends DefaultConfigDestination implements RollbackableInterface {

  use RollbackableConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected $supportsRollback = TRUE;

}
