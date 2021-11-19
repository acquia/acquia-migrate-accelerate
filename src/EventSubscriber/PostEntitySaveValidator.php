<?php

declare(strict_types = 1);

namespace Drupal\acquia_migrate\EventSubscriber;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Utility\Variable;
use Drupal\content_moderation\Plugin\Validation\Constraint\ModerationStateConstraint;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Exception\EntityValidationException;
use Drupal\migrate\Plugin\MigrationInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;

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
   * Skipped violations keyed by the constraint class.
   *
   * If every violation should be skipped of a constraint, then the value should
   * be TRUE. If violations should be skipped based on the message template, the
   * value should be an array of the message templates to skip.
   *
   * @var array
   */
  protected static $skippedViolations;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The acquia migrate logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The PostEntitySaveValidator constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
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
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$entity_type_id || !($definition instanceof ContentEntityType)) {
      return;
    }

    // Destinations can have more than one ID.
    // @see \Drupal\migrate\Plugin\migrate\destination\EntityContentComplete
    $destination_id_keys = $migration->getDestinationPlugin()->getIds();
    $destination_id_values = $event->getDestinationIdValues();
    // No need for checking that the number of the destination values and the
    // number of the destination keys are the same: if they don't match, the
    // entity could not have been saved.
    $destination_ids = array_combine(array_keys($destination_id_keys), array_values($destination_id_values));

    // Get the (known) identifier keys.
    $id_key = $definition->getKey('id');
    $revision_key = $definition->getKey('revision');
    $langcode_key = $definition->getKey('langcode');

    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $entity_id = $destination_ids[$id_key];
    $entity_revision_id = !empty($revision_key) ? $destination_ids[$revision_key] ?? NULL : NULL;
    $entity_langcode = !empty($langcode_key) ? $destination_ids[$langcode_key] ?? NULL : NULL;

    // Fail explicitly when the number of the currently known entity identifiers
    // is not the same as the number of the destination IDs.
    $identified_destination_ids = array_filter([
      $entity_id,
      $entity_revision_id,
      $entity_langcode,
    ]);
    if (count($destination_ids) !== count($identified_destination_ids)) {
      $destination_ids_with_keys = array_reduce(array_keys($destination_ids), function (array $carry, string $id_key) use ($destination_ids) {
        $carry[] = "{$id_key}:{$destination_ids[$id_key]}";
        return $carry;
      }, []);
      throw new \LogicException(sprintf('The number of the currently known entity identifiers is not the same as the number of the destination IDs in "%s" migration for the row with destination IDs %s.', $migration->id(), implode(';', $destination_ids_with_keys)));
    }

    if ($entity_revision_id) {
      assert($storage instanceof RevisionableStorageInterface);
    }
    // Load the entity: get the right revision, or if there are no revisions,
    // load the entity by its ID.
    $entity = $entity_revision_id
      ? $storage->loadRevision($entity_revision_id)
      : $storage->load($entity_id);

    // Get the translation if there is a language code destination ID.
    if ($entity_langcode) {
      assert($entity instanceof TranslatableInterface);
      $entity = $entity->getTranslation($entity_langcode);
    }

    // Assert that the loaded revision has the expected language when both
    // "$revision" and "$langcode" are present.
    if ($entity_revision_id && $entity_langcode) {
      assert($entity instanceof RevisionableInterface);
      $loaded_translation_langcode = $entity->language()->getId();
      // Even though RevisionableInterface::getLoadedRevisionId() says that it
      // returns an integer, this is not true.
      $loaded_revision_id = $entity->getLoadedRevisionId();
      if (
        // The loaded entity's language is different than the expected one.
        $loaded_translation_langcode !== $entity_langcode ||
        // The loaded entity revision is different than the expected one.
        (string) $loaded_revision_id !== (string) $entity_revision_id
      ) {
        $this->logger->log(RfcLogLevel::WARNING, 'The entity loaded for validation does not have the expected IDs. Expected entity IDs: "@expected-ids". Loaded entity IDs: "@loaded-ids".', [
          '@source-plugin-class' => Variable::export($destination_ids),
          '@dummy-query-trait-class' => Variable::export([
            $id_key => $entity->id(),
            $revision_key => $loaded_revision_id,
            $langcode_key => $loaded_translation_langcode,
          ]),
        ]);
        return;
      }
    }

    // Only validate the default revision. In principle, revisions are migrated
    // in ascending order, so this won't prevent validation in normal
    // circumstances.
    if (
      $entity instanceof RevisionableInterface &&
      !$entity->isDefaultRevision()
    ) {
      return;
    }

    // If any validation constraint violations occur, construct an exception
    // without throwing it. This allows us the entity to be saved while still
    // generating a migration message for the validation errors.
    $violations = $entity->validate();
    assert($violations instanceof EntityConstraintViolationListInterface);

    // We might have some false positives, let's clean up violations!
    $violations_without_false_positives = clone $violations;
    if ($violations->count()) {
      $messages_to_skip_per_constraint = $this->getSkippedViolations();
      foreach ($violations->getIterator() as $index => $violation) {
        assert($violation instanceof ConstraintViolation);
        $constraint_class = $violation->getConstraint()
          ? get_class($violation->getConstraint())
          : NULL;
        $templates_to_skip = $messages_to_skip_per_constraint[$constraint_class] ?? NULL;
        if (!$templates_to_skip) {
          continue;
        }

        $violation_template = $violation->getMessageTemplate();

        if (
          $templates_to_skip === TRUE ||
          (is_array($templates_to_skip) && in_array($violation_template, $templates_to_skip))
        ) {
          $violations_without_false_positives->remove($index);
        }
      }
    }

    if (count($violations_without_false_positives) > 0) {
      $exception = new EntityValidationException($violations_without_false_positives);
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
    $destination_plugin = $migration->getDestinationPlugin();
    // Destination plugin should be a derived plugin like "entity:user",
    // "entity_complete:node" or "entity_reference_revisions:paragraph".
    assert($destination_plugin instanceof PluginBase);
    $entity_destinations = [
      'entity',
      'entity_complete',
      'entity_reference_revisions',
    ];
    return in_array($destination_plugin->getBaseId(), $entity_destinations, TRUE);
  }

  /**
   * Gets the entity type ID of the entity destination.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration for which a row was just saved.
   *
   * @return string|null
   *   The destination entity's type ID.
   */
  protected static function getEntityTypeId(MigrationInterface $migration) : ?string {
    assert(static::isEntityMigration($migration));
    $destination_plugin = $migration->getDestinationPlugin();
    // Destination plugin should be a derived plugin like "entity_complete:node"
    // or "entity:user".
    assert($destination_plugin instanceof PluginBase);
    return $destination_plugin->getDerivativeId();
  }

  /**
   * Returns info about the entity violations which shouldn't be reported.
   *
   * @return array
   *   Info about the entity violations which shouldn't be reported.
   */
  private function getSkippedViolations() {
    if (!is_array(self::$skippedViolations)) {
      self::$skippedViolations = [];

      if (class_exists(ModerationStateConstraint::class)) {
        $moderation_state_constraint = new ModerationStateConstraint();
        self::$skippedViolations[ModerationStateConstraint::class] = [
          // Some of the moderation state specific violations are
          // false-positive:
          // \Drupal\content_moderation\ModerationInformation::getOriginalState()
          // always loads the most recent moderation state instead of the
          // previous one, meaning that the from_state -> to_state transition
          // validation works only if it's evaluated before the corresponding
          // content_moderation entity is saved – and it is useless after this
          // content_moderation state entity was updated with a new revision for
          // the actually saved (moderated) content entity revision.
          $moderation_state_constraint->message,
          // Invalid transition access always checks the current user - and if
          // we're executing migrations, that's someone else than the owner of
          // the actual entity revision.
          $moderation_state_constraint->invalidTransitionAccess,
        ];
      }
    }

    return self::$skippedViolations;
  }

}
