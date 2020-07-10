<?php

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

/**
 * Rollbackable entity form display component destination.
 *
 * Provides a rollbackable entity form display destination plugin per component
 * (field).
 *
 * @see \Drupal\migrate\Plugin\migrate\destination\PerComponentEntityFormDisplay
 *
 * @internal
 *
 * @MigrateDestination(
 *   id = "rollbackable_component_entity_form_display"
 * )
 */
final class RollbackablePerComponentEntityFormDisplay extends RollbackableComponentEntityDisplayBase {

  /**
   * {@inheritdoc}
   */
  const MODE_NAME = 'form_mode';

  /**
   * {@inheritdoc}
   */
  protected function getEntity($entity_type, $bundle, $form_mode) {
    return $this->entityDisplayRepository->getFormDisplay($entity_type, $bundle, $form_mode);
  }

}
