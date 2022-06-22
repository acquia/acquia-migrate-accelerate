<?php

namespace Drupal\acquia_migrate\Batch;

use Drupal\migrate\Event\MigrateIdMapMessageEvent;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_drupal_ui\Batch\MigrateUpgradeImportBatch;

/**
 * Extended MigrateUpgradeImportBatch to support CLI tool.
 */
class AcquiaMigrateUpgradeImportBatch extends MigrateUpgradeImportBatch {

  /**
   * Holds a callable function.
   *
   * @var callable
   */
  public static $callable = NULL;

  /**
   * Will be set if a drush call is made.
   *
   * @var string
   */
  public static $isDrush = '';

  /**
   * Will be set if a drush call is made.
   *
   * @var float
   */
  public static $lastUpdationTime;

  /**
   * {@inheritdoc}
   */
  public static function onPostRowSave(MigratePostRowSaveEvent $event) {
    if (self::isDrush()) {
      assert(is_callable(static::$callable));
      call_user_func(static::$callable);
    }
    else {
      parent::onPostRowSave($event);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function onIdMapMessage(MigrateIdMapMessageEvent $event) {
    if ($event->getLevel() == MigrationInterface::MESSAGE_NOTICE || $event->getLevel() == MigrationInterface::MESSAGE_INFORMATIONAL) {
      $type = 'status';
    }
    else {
      $type = 'error';
    }

    if (self::isDrush()) {
      assert(is_callable(static::$callable));
      call_user_func(static::$callable);
    }
    $source_id_string = implode(',', $event->getSourceIdValues());
    $message = t('Source ID @source_id: @message',
      ['@source_id' => $source_id_string, '@message' => $event->getMessage()]);
    static::$messages->display($message, $type);
  }

  /**
   * Helper to check if it is run from Drush.
   */
  public static function isDrush() {
    if (!empty(self::$isDrush)) {
      return TRUE;
    }
    return FALSE;
  }

}
