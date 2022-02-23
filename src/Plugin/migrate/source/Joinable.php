<?php

namespace Drupal\acquia_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\DummyQueryTrait;
use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * To MacGyver or not to MacGyver.
 *
 * @MigrateSource(
 *   id = "joinable"
 * )
 *
 * @internal
 *
 * @see \Drupal\acquia_migrate\MacGyver::isJoinable()
 */
final class Joinable extends SqlBase {

  use DummyQueryTrait;

  /**
   * Checks if we can join against the map table.
   *
   * This function specifically catches issues when we're migrating with unique
   * sets of credentials for the source and destination database.
   *
   * @return bool
   *   TRUE if we can join against the map table otherwise FALSE.
   */
  public function sourceAndIdMapTablesAreJoinable() {
    return $this->mapJoinable();
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['foo' => ['type' => 'string']];
  }

}
