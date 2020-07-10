<?php

declare(strict_types = 1);

namespace Drupal\acquia_migrate\EventSubscriber;

use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Exception\EntityValidationException;
use Drupal\migrate\Plugin\MigrationInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Performs entity validation after the entity has been saved.
 *
 * The "validate entity upon migration" infrastructure in Drupal core that
 * https://www.drupal.org/node/2745797 prevents entities from being saved
 * when there are validation errors. We want those saves to succeed, despite
 * validation errors, and then surface the validation errors. This empowers the
 * end user to choose whether to act on it or not.
 *
 * @todo verify whether we should exclude \Drupal\Core\Entity\Plugin\Validation\Constraint\ValidReferenceConstraint from validation — see https://www.drupal.org/project/drupal/issues/3095456#comment-13359633
 */
class PostEntitySaveValidator implements EventSubscriberInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The PostEntitySaveValidator constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      MigrateEvents::POST_ROW_SAVE => [
        'postRowSave',
      ],
    ];
  }

  /**
   * Validates saved entities; generates corresponding migration messages.
   *
   * @param \Drupal\migrate\Event\MigratePostRowSaveEvent $event
   *   The Event to process.
   */
  public function postRowSave(MigratePostRowSaveEvent $event) {
    $migration = $event->getMigration();
    if (!static::isEntityMigration($migration)) {
      return;
    }

    // For now, only content entities can be validated.
    // @see https://www.drupal.org/project/drupal/issues/2870878
    $entity_type_id = static::getEntityTypeId($migration);
    if (!$this->entityTypeManager->getDefinition($entity_type_id) instanceof ContentEntityType) {
      return;
    }

    // Fail explicitly when an assumption of this code is violated.
    if (count($event->getDestinationIdValues()) !== 1) {
      throw new \InvalidArgumentException('There should be exactly one destination ID for every entity migration row.');
    }

    // Load the entity.
    $entity_id = $event->getDestinationIdValues()[0];
    $entity = $this->entityTypeManager
      ->getStorage($entity_type_id)
      ->load($entity_id);

    // If any validation constraint violations occur, construct an exception
    // without throwing it. This allows us the entity to be saved while still
    // generating a migration message for the validation errors.
    $violations = $entity->validate();
    if (count($violations) > 0) {
      $exception = new EntityValidationException($violations);
      $migration->getIdMap()->saveMessage(
        $event->getRow()->getSourceIdValues(),
        // Flatten the results of FormattableMarkup::placeholderFormat().
        str_replace(['<em class="placeholder">', '</em>'], '', $exception->getMessage()),
        $exception->getLevel()
      );
    }
  }

  /**
   * Checks whether the given migration has an entity as a destination.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration for which a row was just saved.
   *
   * @return bool
   *   True if we are migrating entities.
   */
  protected static function isEntityMigration(MigrationInterface $migration) : bool {
    $destination_configuration = $migration->getDestinationConfiguration();
    return strpos($destination_configuration['plugin'], 'entity:') === 0;
  }

  /**
   * Gets the entity type ID of the entity destination.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration for which a row was just saved.
   *
   * @return string
   *   The entity type ID.
   */
  protected static function getEntityTypeId(MigrationInterface $migration) : string {
    assert(static::isEntityMigration($migration));
    $destination_configuration = $migration->getDestinationConfiguration();
    return substr($destination_configuration['plugin'], 7);
  }

}
