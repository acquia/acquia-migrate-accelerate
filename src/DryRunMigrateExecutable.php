<?php

namespace Drupal\acquia_migrate;

use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\Plugin\MigrationInterface;

/**
 * Dry-run variation of migrate executable: only does in-memory processing.
 *
 * @internal
 */
final class DryRunMigrateExecutable extends MigrateExecutable {

  /**
   * {@inheritdoc}
   */
  public function import() {
    throw new \LogicException('Dry runs don not allow importing.');
  }

  /**
   * {@inheritdoc}
   */
  public function rollback() {
    throw new \LogicException('Dry runs don not allow rolling back.');
  }

  /**
   * {@inheritdoc}
   */
  public function saveMessage($message, $level = MigrationInterface::MESSAGE_ERROR) {
    throw new \LogicException('Dry runs don not allow saving messages.');
  }

}
