<?php

namespace Drupal\acquia_migrate;

use Drupal\acquia_migrate\Exception\ImplementationException;
use Drupal\acquia_migrate\Exception\MissingSourceDatabaseException;
use Drupal\acquia_migrate\Exception\RowNotFoundException;
use Drupal\acquia_migrate\Exception\RowPreviewException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use Drupal\migrate\Row;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serves the Acquia Migrate Previews.
 *
 * @internal
 */
final class MigrationPreviewer {

  /**
   * The offset-based migration row selection.
   *
   * @var int
   */
  const ROW_SELECTION_BY_OFFSET = 0x01;

  /**
   * The source site URL-based migration row selection (path aliases supported).
   *
   * @var int
   */
  const ROW_SELECTION_BY_URL = 0x10;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The HTTP kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * MigrationPreviewer constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   *   The HTTP kernel.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, HttpKernelInterface $http_kernel, EventDispatcherInterface $event_dispatcher) {
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->httpKernel = $http_kernel;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * Checks whether the given migration is previewable.
   *
   * @param \Drupal\acquia_migrate\Migration $migration
   *   A migration.
   *
   * @return bool
   *   Whether the given migration is previewable.
   *
   * @see \Drupal\acquia_migrate\Migration::getAvailableLinkUrls()
   * @todo Remove when \Drupal\acquia_migrate\Migration::getAvailableActionUrls() is refactored away.
   */
  public function isPreviewableMigration(Migration $migration) : bool {
    return $this->getDataMigrationPluginToPreview($migration) !== NULL;
  }

  /**
   * Maps a Migration object to JSON:API resource object array.
   *
   * @param \Drupal\acquia_migrate\Migration $migration
   *   A migration.
   * @param \Drupal\migrate\Row $preview_row
   *   A row in this migration.
   *
   * @return array
   *   A JSON:API resource object array.
   *
   * @throws \InvalidArgumentException
   */
  public function migrationRowToMigrationPreviewResourceObject(Migration $migration, Row $preview_row) : array {
    $migration_plugin = $this->getDataMigrationPluginToPreview($migration);
    if ($migration_plugin === NULL) {
      throw new RowPreviewException('This migration does not support previews.');
    }

    // Process the row (to populate the row's "destination" fields) so we can
    // build a preview for it.
    $executable = new DryRunMigrateExecutable($migration_plugin);
    $executable->processRow($preview_row);
    $raw_mapping = [];
    $source = $preview_row->getSource();
    foreach (array_keys($migration_plugin->getProcessPlugins()) as $destination_field_name) {
      $source_field_name = static::getSourceFieldName($destination_field_name, $migration_plugin);
      // Some fields may be mapped but may not exist in the source. For example,
      // all node migrations assume translations, but on D7 sites without the
      // content_translation contrib module, the `source_langcode` field does
      // not exist.
      if (!isset($source[$source_field_name])) {
        continue;
      }
      $source_value = $source[$source_field_name];
      $destination_value = $preview_row->getDestinationProperty($destination_field_name);

      $raw_mapping[] = [
        'sourceFieldName' => $source_field_name,
        'destinationFieldName' => $destination_field_name,
        'sourceValue' => $source_value,
        'destinationValue' => $destination_value,
        'sourceValueSimplified' => static::simplifyRawEntityFieldValue($source_value),
        'destinationValueSimplified' => static::simplifyRawEntityFieldValue($destination_value),
      ];
    }

    $entity_type = $this->getDestinationEntityTypeForMigrationPlugin($migration_plugin);
    $build_entity = $this->buildPreview($preview_row, $entity_type, $migration_plugin->getDestinationConfiguration());
    $html_response = $this->renderToMinimalHtmlResponse($build_entity);
    $filtered_html_response = $this->filterResponse(new Request(), $html_response);

    return [
      'type' => 'migrationPreview',
      'id' => 'ephemeral',
      'attributes' => [
        'raw' => $raw_mapping,
        // @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/iframe#attr-srcdoc
        'html' => $filtered_html_response->getContent(),
      ],
      'relationships' => [
        'sourceMigration' => [
          'data' => [
            'type' => 'migration',
            'id' => $migration->id(),
          ],
        ],
      ],
    ];
  }

  /**
   * Gets the data migration plugin for which to generate a preview.
   *
   * @return \Drupal\migrate\Plugin\Migration|null
   *   The data migration plugin for which to generate a preview, if any.
   */
  public function getDataMigrationPluginToPreview(Migration $migration) : ?MigrationPlugin {
    $migration_plugins = $migration->getMigrationPluginInstances();
    foreach ($migration->getDataMigrationPluginIds() as $plugin_id) {
      if ($this->migrationPluginHasContentEntityDestination($migration_plugins[$plugin_id])) {
        return $migration_plugins[$plugin_id];
      }
    }

    return NULL;
  }

  /**
   * Checks whether the given migration plugin has a content entity destination.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration plugin to check.
   *
   * @return bool
   *   Whether the given migration plugin has an entity destination.
   */
  private function migrationPluginHasContentEntityDestination(MigrationPlugin $migration_plugin) {
    $destination_plugin_id = $migration_plugin->getDestinationConfiguration()['plugin'];

    $is_entity_destination = strpos($destination_plugin_id, 'entity_complete:') === 0
      || strpos($destination_plugin_id, 'entity:') === 0
      || strpos($destination_plugin_id, 'entity_revision:') === 0;
    if (!$is_entity_destination) {
      return FALSE;
    }

    list(, $entity_type_id) = explode(':', $destination_plugin_id);
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

    return $entity_type instanceof ContentEntityType;
  }

  /**
   * The number of sensible rows.
   *
   * To determine when no `next` link should be provided.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration for which to retrieve the row to preview.
   *
   * @return int
   *   The sensible row count.
   *
   * @see static::ROW_SELECTION_BY_OFFSET
   * @see ::getSensibleSourceQuery()
   */
  public function getSensibleRowCount(Migration $migration) : int {
    assert($this->isPreviewableMigration($migration));

    $data_migration_plugin = $this->getDataMigrationPluginToPreview($migration);

    // The query() method exists on:
    // - \Drupal\migrate_drupal\Plugin\migrate\source\d7\FieldableEntity
    // - \Drupal\migrate_drupal\Plugin\migrate\source\ContentEntity
    // … but is not declared on \Drupal\migrate\Plugin\MigrateSourceInterface.
    assert(method_exists($data_migration_plugin->getSourcePlugin(), 'query'));

    $source = $data_migration_plugin->getSourcePlugin();
    $entity_type = $this->getDestinationEntityTypeForMigrationPlugin($data_migration_plugin);
    $source_query = static::getSensibleSourceQuery($source->query(), $entity_type);

    return $source_query->countQuery()->execute()->fetchField();
  }

  /**
   * Gets the migration plugin's source row to generate a preview for.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration for which to retrieve the row to preview.
   * @param int $selection_mode
   *   One of ROW_SELECTION_BY_OFFSET or ROW_SELECTION_BY_URL.
   * @param string $selection_value
   *   The value associated with the selection mode.
   *
   * @return \Drupal\migrate\Row
   *   The row to preview.
   */
  public function getRowToPreview(Migration $migration, int $selection_mode, string $selection_value) : Row {
    assert(in_array($selection_mode, [static::ROW_SELECTION_BY_OFFSET, static::ROW_SELECTION_BY_URL], TRUE));

    $data_migration_plugin = $this->getDataMigrationPluginToPreview($migration);
    if ($data_migration_plugin === NULL) {
      throw new RowPreviewException('This migration does not support previews.');
    }
    // The query() method exists on:
    // - \Drupal\migrate_drupal\Plugin\migrate\source\d7\FieldableEntity
    // - \Drupal\migrate_drupal\Plugin\migrate\source\ContentEntity
    // … but is not declared on \Drupal\migrate\Plugin\MigrateSourceInterface.
    assert(method_exists($data_migration_plugin->getSourcePlugin(), 'query'));

    if (!$migration->allDependencyRowsProcessed()) {
      throw new RowPreviewException(sprintf('Not all rows in dependent migrations (%s) have been processed yet.', implode(', ', $migration->getDependencies())));
    }

    $source = $data_migration_plugin->getSourcePlugin();
    $entity_type = $this->getDestinationEntityTypeForMigrationPlugin($data_migration_plugin);
    $source_query = static::getSensibleSourceQuery($source->query(), $entity_type);

    // Get the the migration source row for which a preview is requested.
    if ($selection_mode === static::ROW_SELECTION_BY_OFFSET) {
      $results = $source_query->execute()->fetchAll();
      $offset = (int) $selection_value;
      if (!isset($results[$offset])) {
        throw new RowNotFoundException('Requested row not found.');
      }
      $row_data = $results[$offset];
    }
    elseif ($selection_mode === static::ROW_SELECTION_BY_URL) {
      // TRICKY: we don't use the URL pattern from the source site, because that
      // would require hardcoding them for every possible entity type. Instead,
      // we assume that the canonical URL pattern for a given entity type
      // remains unchanged between Drupal versions. This assumption allows us to
      // read the Drupal 8 canonical URL pattern, and use that instead.
      $url = parse_url($selection_value, PHP_URL_PATH);
      $url_pattern = str_replace('{' . $entity_type->id() . '}', '', $entity_type->getLinkTemplate('canonical'));
      if (strpos($url, $url_pattern) === 0) {
        $requested_entity_id = substr($url, strlen($url_pattern));
      }
      else {
        if (!SourceDatabase::isConnected()) {
          throw new MissingSourceDatabaseException();
        }
        $connection = SourceDatabase::getConnection();
        $source_url = $connection->select('url_alias', 'u')
          ->fields('u', ['source'])
          ->condition('alias', trim($url, '/'))
          ->execute()
          ->fetchField();
        if ($source_url === FALSE) {
          throw new RowNotFoundException(sprintf('No entity of type %s found for the URL `%s`.', $entity_type->id(), $url));
        }
        $requested_entity_id = substr("/$source_url", strlen($url_pattern));
      }

      // Restrict query to only the requested entity.
      $id_column_name = $entity_type->getKey('id');
      $base_table_alias = static::getAliasForTable($entity_type->getBaseTable(), $source_query->getTables());
      $source_query = $source_query->condition("$base_table_alias.$id_column_name", $requested_entity_id);

      $results = $source_query->execute()->fetchAll();
      if (empty($results)) {
        throw new RowNotFoundException('Requested row not found.');
      }

      $row_data = $results[0];
    }

    // @see \Drupal\migrate\Plugin\migrate\source\SourcePluginBase::next()
    $preview_row = new Row($row_data, $source->getIds());
    $source->prepareRow($preview_row);

    return $preview_row;
  }

  /**
   * Gets the sensible source query (only the default revision of entities).
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query as returned by the source plugin.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type that is being queried.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The updated query.
   *
   * @see \Drupal\migrate_drupal\Plugin\migrate\source\ContentEntity::query()
   */
  private static function getSensibleSourceQuery(SelectInterface $query, EntityTypeInterface $entity_type) {
    $tables = $query->getTables();

    // If the entity type is revisionable, restrict query to default revision.
    // This ensures we don't show every revision of every entity: we want
    // different offsets to point to different entities.
    $base_table_alias = static::getAliasForTable($entity_type->getBaseTable(), $tables);
    if ($entity_type->isRevisionable()) {
      $revision_column_name = $entity_type->getKey('revision');
      $revision_table_alias = static::getAliasForTable($entity_type->getRevisionTable(), $tables);
      if ($revision_table_alias !== NULL) {
        $query = $query->where("$revision_table_alias.$revision_column_name = $base_table_alias.$revision_column_name");
      }
    }

    return $query;
  }

  /**
   * Gets the alias used for a table in a particular SELECT query.
   *
   * @param string $table_name
   *   The table name to get the alias for.
   * @param array $all_query_tables
   *   An array of tables involved in a SELECT query.
   *
   * @return string|null
   *   The alias, if it was found.
   *
   * @see \Drupal\Core\Database\Query\Select
   */
  private static function getAliasForTable(string $table_name, array $all_query_tables) {
    foreach ($all_query_tables as $details) {
      if ($details['table'] == $table_name) {
        return $details['alias'];
      }
    }
  }

  /**
   * Filters the given response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request for which a response is being sent.
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response to filter.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The filtered response.
   *
   * @see \Symfony\Component\HttpKernel\HttpKernel::filterResponse()
   */
  private function filterResponse(Request $request, Response $response) {
    $event = new ResponseEvent($this->httpKernel, $request, HttpKernelInterface::SUB_REQUEST, $response);
    $this->eventDispatcher->dispatch(KernelEvents::RESPONSE, $event);
    $filtered_response = $event->getResponse();
    return $filtered_response;
  }

  /**
   * Gets the destination entity type for a given migration plugin.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration plugin to get the entity type for.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface
   *   The corresponding entity type.
   *
   * @throws \Drupal\acquia_migrate\Exception\ImplementationException
   *   Thrown if the given migration plugin's destination is misconfigured.
   */
  private function getDestinationEntityTypeForMigrationPlugin(MigrationPlugin $migration_plugin) : EntityTypeInterface {
    $destination_configuration = $migration_plugin->getDestinationConfiguration();
    list(, $entity_type_id) = explode(':', $destination_configuration['plugin']);
    try {
      return $this->entityTypeManager->getDefinition($entity_type_id);
    }
    catch (PluginNotFoundException $e) {
      throw new ImplementationException(sprintf('The %s migration plugin\'s destination entity type does not exist.', $migration_plugin->id()));
    }
  }

  /**
   * Renders a render array into a minimal HTML response.
   *
   * A minimal HTML response is:
   * - with only the main region
   * - without toolbar, Quick Edit etc (by only invoking the system module's
   *   hook_page_attachments(), to ensure the negotiated theme's assets are
   *   attached)
   * - without care for cacheability or placeholdering: this HTML response is
   *   designed to be uncacheable.
   *
   * @param array $render_array
   *   The render array to render into a HTML response.
   *
   * @return \Drupal\Core\Render\HtmlResponse
   *   The resulting HTML response.
   */
  private function renderToMinimalHtmlResponse(array $render_array) : HtmlResponse {
    $html = [
      '#type' => 'html',
      'page' => [
        '#type' => 'page',
        // @see \Drupal\Core\Render\Plugin\DisplayVariant\SimplePageVariant::build()
        'content' => [
          '#theme_wrappers' => ['region'],
          '#region' => 'content',
          'main_content' => $render_array,
        ],
      ],
    ];
    system_page_attachments($html['page']);

    $render_context = new RenderContext();
    $content = $this->renderer->executeInRenderContext($render_context, function () use (&$html) {
      return $this->renderer->renderRoot($html);
    });

    $response = new HtmlResponse($content, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
    ]);
    $response->setAttachments($html['#attached']);

    return $response;
  }

  /**
   * Simplifies a raw entity field value.
   *
   * @param mixed $value
   *   A field value.
   * @param bool $is_root_call
   *   (internal) Whether this is the root (non-recursive) call to this method.
   *
   * @return mixed
   *   The corresponding simplified field value.
   */
  private static function simplifyRawEntityFieldValue($value, bool $is_root_call = TRUE) {
    // @codingStandardsIgnoreStart
    // Special case single-delta, single-property array values.
    // e.g. don't generate markup like:
    //   0:
    //     value:
    //           <p>Hello world!</p>
    // and instead generate:
    //   <p>Hello world!</p>
    // @codingStandardsIgnoreEnd
    if (is_array($value)) {
      if ($is_root_call && count($value) === 1 && is_array(reset($value)) && count(reset($value)) === 1) {
        $v = reset($value);
        $first_nested_value = reset($v);
        return static::simplifyRawEntityFieldValue($first_nested_value, FALSE);
      }
    }

    return $value;
  }

  /**
   * Gets the source field name corresponding to a given destination field name.
   *
   * @param string $destination_field_name
   *   A destination field name.
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration plugin instance for which to look this up.
   *
   * @return string|null
   *   The corresponding source field name if it exists, NULL otherwise.
   */
  public static function getSourceFieldName(string $destination_field_name, MigrationPlugin $migration_plugin) : ?string {
    $normalized_process_pipeline = $migration_plugin->getProcess();
    return array_reduce($normalized_process_pipeline[$destination_field_name], function (?string $carry, array $process_configuration) {
      if (isset($carry)) {
        return $carry;
      }
      if ($process_configuration['source']) {
        $source = $process_configuration['source'];
        // @todo Change this from a heuristic (if an array, pick the last thing) to something that is actually guaranteed to work!
        $source = is_array($source) ? end($source) : $source;
        return str_replace('@', '', $source);
      }
      return NULL;
    }, NULL);
  }

  /**
   * Builds a render array to preview the entity in the given row.
   *
   * @param \Drupal\migrate\Row $row
   *   A row from the migration plugin's source.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The destination entity type to migrate this row into.
   * @param array $destination_configuration
   *   The configuration for the destination.
   *
   * @return array
   *   A render array previewing the given source row and the given destination
   *   entity type.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function buildPreview(Row $row, EntityTypeInterface $entity_type, array $destination_configuration) : array {
    if ($entity_type->hasLinkTemplate('canonical')) {
      $stub_entity = $this->generatePreviewEntity($row, $entity_type, $destination_configuration);
      $build = $this->entityTypeManager
        ->getViewBuilder($entity_type->id())
        ->view($stub_entity);
      $build['#title'] = $stub_entity->label();
    }
    else {
      $build = [
        '#prefix' => Markup::create('<div style="text-align: center">'),
        '#plain_text' => $this->t('N/A'),
        '#suffix' => '</div>',
      ];
    }

    // Don't render cache previews.
    unset($build['#cache']);

    return $build;
  }

  /**
   * Generates an (ephemeral/unsaved) entity from the given row for previewing.
   *
   * @param \Drupal\migrate\Row $row
   *   A row from the migration plugin's source.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The destination entity type to migrate this row into.
   * @param array $destination_configuration
   *   The configuration for the destination.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The preview entity generated from the given row for the given destination
   *   entity type.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function generatePreviewEntity(Row $row, EntityTypeInterface $entity_type, array $destination_configuration) : ContentEntityInterface {
    $bundle_key = $entity_type->getKey('bundle');
    if ($bundle_key && !empty($destination_configuration['default_bundle'])) {
      $row->setDestinationProperty($bundle_key, $destination_configuration['default_bundle']);
    }

    $entity = $this->entityTypeManager
      ->getStorage($entity_type->id())
      ->create(
        $row->getDestination()
      )
      ->enforceIsNew();

    // @see \Drupal\node\NodeViewBuilder::buildComponents()
    $entity->in_preview = TRUE;

    return $entity;
  }

}
