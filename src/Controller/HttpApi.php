<?php

namespace Drupal\acquia_migrate\Controller;

use Drupal\acquia_migrate\Batch\BatchUnknown;
use Drupal\acquia_migrate\Batch\MigrationBatchManager;
use Drupal\acquia_migrate\Exception\AcquiaMigrateHttpExceptionInterface;
use Drupal\acquia_migrate\Exception\BadRequestHttpException;
use Drupal\acquia_migrate\Exception\FailedAtomicOperationException;
use Drupal\acquia_migrate\Exception\InvalidFilterParameterException;
use Drupal\acquia_migrate\Exception\MissingQueryParameterException;
use Drupal\acquia_migrate\Exception\MultipleClientErrorsException;
use Drupal\acquia_migrate\Exception\QueryParameterNotAllowedException;
use Drupal\acquia_migrate\MessageAnalyzer;
use Drupal\acquia_migrate\Migration;
use Drupal\acquia_migrate\MigrationMappingManipulator;
use Drupal\acquia_migrate\MigrationMappingViewer;
use Drupal\acquia_migrate\MigrationPreviewer;
use Drupal\acquia_migrate\MigrationRepository;
use Drupal\acquia_migrate\Plugin\migrate\id_map\SqlWithCentralizedMessageStorage;
use Drupal\acquia_migrate\UriDefinitions;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\MigrationInterface;
use League\Uri\Components\Query;
use League\Uri\Contracts\QueryInterface;
use League\Uri\Uri;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the Acquia Migrate HTTP API.
 *
 * @internal
 */
final class HttpApi {

  use StringTranslationTrait;
  use HttpApiTrait;

  /**
   * The "entity validation" category of migration messages.
   */
  const MESSAGE_CATEGORY_ENTITY_VALIDATION = 'entity_validation';

  /**
   * The "other" category of migration messages. This is the default.
   */
  const MESSAGE_CATEGORY_OTHER = 'other';

  /**
   * Cache contexts to be added to all cacheable responses.
   *
   * @var string[]
   */
  protected static $defaultCacheContexts = [
    'url.query_args:sort',
    'url.query_args:filter',
    'url.query_args:fields',
    'url.query_args:include',
    'headers:Accept',
  ];

  /**
   * A mapping of allowed query string operators to DB operators.
   *
   * Unrecognized operators will be rejected.
   *
   * @var array[string]string
   */
  protected static $filterOperatorMap = [
    ':eq' => '=',
  ];

  /**
   * A mapping of messageCategory filters.
   *
   * @var array[string]string
   *
   * @see \Drupal\acquia_migrate\Controller\HttpApi::getFilterCategories()
   */
  protected $filterCategories;

  /**
   * Gets a mapping of messageCategory filters.
   *
   * @return array[string]TranslatableMarkup
   *   The filter categories.
   */
  protected function getFilterCategories() {
    if (!isset($this->filterCategories)) {
      $this->filterCategories = [
        HttpApi::MESSAGE_CATEGORY_ENTITY_VALIDATION => $this->t('Entity validation'),
        HttpApi::MESSAGE_CATEGORY_OTHER => $this->t('Other'),
      ];
    }
    return $this->filterCategories;
  }

  /**
   * A mapping of fields to an allowed set of values.
   *
   * @var array[string]mixed
   *
   * @see \Drupal\acquia_migrate\Controller\HttpApi::getFilterableFields()
   */
  protected $filterableFields;

  /**
   * Gets a mapping of fields to an allowed set of values.
   *
   * Each key represents a recognized, filterable field. The key's associated
   * value is an allowed set of filterable values. Unrecognized fields or values
   * will be rejected.
   *
   * @return array[string]mixed
   *   The mapping.
   */
  protected function getFilterableFields() {
    if (!isset($this->filterableFields)) {
      $this->filterableFields = [
        SqlWithCentralizedMessageStorage::COLUMN_CATEGORY => array_keys($this->getFilterCategories()),
        SqlWithCentralizedMessageStorage::COLUMN_SEVERITY => array_map('strval', array_keys(RfcLogLevel::getLevels())),
        SqlWithCentralizedMessageStorage::COLUMN_MIGRATION_ID => array_keys($this->loadMigrations()),
      ];
    }
    return $this->filterableFields;
  }

  /**
   * The migration repository.
   *
   * @var \Drupal\acquia_migrate\MigrationRepository
   */
  protected $repository;

  /**
   * A migration bath manager.
   *
   * @var \Drupal\acquia_migrate\Batch\MigrationBatchManager
   */
  protected $migrationBatchManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The migration previewer.
   *
   * @var \Drupal\acquia_migrate\MigrationPreviewer
   */
  protected $migrationPreviewer;

  /**
   * The migration mapping viewer.
   *
   * @var \Drupal\acquia_migrate\MigrationMappingViewer
   */
  protected $migrationMappingViewer;

  /**
   * The migration mapping manipulator.
   *
   * @var \Drupal\acquia_migrate\MigrationMappingManipulator
   */
  protected $migrationMappingManipulator;

  /**
   * The migration message analyzer.
   *
   * @var \Drupal\acquia_migrate\MessageAnalyzer
   */
  protected $messageAnalyzer;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * HttpApi constructor.
   *
   * @param \Drupal\acquia_migrate\MigrationRepository $repository
   *   The migration repository.
   * @param \Drupal\acquia_migrate\Batch\MigrationBatchManager $batch_manager
   *   A batch manager.
   * @param \Drupal\Core\Database\Connection $connection
   *   A Database connection to use for reading migration messages.
   * @param \Drupal\acquia_migrate\MigrationPreviewer $migration_previewer
   *   The migration previewer.
   * @param \Drupal\acquia_migrate\MigrationMappingViewer $migration_mapping_viewer
   *   The migration mapping viewer.
   * @param \Drupal\acquia_migrate\MigrationMappingManipulator $migration_mapping_manipulator
   *   The migration mapping manipulator.
   * @param \Drupal\acquia_migrate\MessageAnalyzer $message_analyzer
   *   The migration message analyzer.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(MigrationRepository $repository, MigrationBatchManager $batch_manager, Connection $connection, MigrationPreviewer $migration_previewer, MigrationMappingViewer $migration_mapping_viewer, MigrationMappingManipulator $migration_mapping_manipulator, MessageAnalyzer $message_analyzer, ConfigFactoryInterface $config_factory, StateInterface $state) {
    $this->repository = $repository;
    $this->migrationBatchManager = $batch_manager;
    $this->connection = $connection;
    $this->migrationPreviewer = $migration_previewer;
    $this->migrationMappingViewer = $migration_mapping_viewer;
    $this->migrationMappingManipulator = $migration_mapping_manipulator;
    $this->messageAnalyzer = $message_analyzer;
    $this->configFactory = $config_factory;
    $this->state = $state;
  }

  /**
   * Returns module-centric migrate information.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function moduleInformation(Request $request): JsonResponse {
    $this->validateRequest($request);
    // This key should be set during the initial installation of the site using
    // this module. The value should be the output of the ah-migrate-info
    // command. The migrate-quickstart command does this automatically.
    // @see https://github.com/acquia/ah-migrate-utils
    $initial_info = $this->state->get('acquia_migrate.initial_info', []);
    $resource_objects = array_map(function (array $module) {
      return [
        'type' => 'sourceModule',
        'id' => $module['name'],
        'attributes' => [
          'humanName' => $module['humanName'],
          'version' => $module['version'],
        ],
        'relationships' => [
          'replacementCandidates' => [
            'data' => [],
          ],
        ],
      ];
    }, $initial_info['sourceModules'] ?? []);
    return JsonResponse::create([
      'data' => $resource_objects,
      'links' => [
        'self' => [
          'href' => $request->getUri(),
        ],
      ],
    ], 200, static::$defaultResponseHeaders);
  }

  /**
   * Serves a listing of migrations.
   *
   * A "migration" in this sense corresponds to a row of the migration
   * dashboard.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to serve.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   *
   * @TODO Make sure to invalidate if cached whenever migration plugins are
   * rebuilt.
   * @see \Drupal\node\Plugin\migrate\D7NodeTranslation::generateFollowUpMigrations
   */
  public function migrationsCollection(Request $request): JsonResponse {
    $this->validateRequest($request);
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(static::$defaultCacheContexts);
    $migrations = $this->loadMigrations();
    if (!$this->repository->migrationsHaveBeenPreselected()) {
      $migrations = array_filter($migrations, function (Migration $migration) {
        return $migration->isPreselectable();
      });
    }
    $resource_objects = array_map(function (Migration $migration) use ($cacheability) {
      return Migration::toResourceObject($migration, $cacheability);
    }, $migrations);
    $data = array_map($this->getSparseFieldsetFunction($request), array_values($resource_objects));
    $document = [
      'data' => $data,
      'links' => [
        'self' => [
          'href' => $request->getUri(),
        ],
      ],
    ];
    $bulk_update_url = Url::fromRoute('acquia_migrate.api.migrations.bulk_update')
      ->setAbsolute()
      ->toString(TRUE);
    $cacheability->addCacheableDependency($bulk_update_url);
    if (!$this->repository->migrationsHaveBeenPreselected()) {
      $document['links']['preselect-migrations'] = [
        'href' => $bulk_update_url->getGeneratedUrl(),
        'title' => 'Pre-select migrations for initial import',
        'rel' => UriDefinitions::LINK_REL_PRESELECT_MIGRATIONS,
        'type' => 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"',
      ];
    }
    else {
      $document['links']['bulk-update-migrations'] = [
        'href' => $bulk_update_url->getGeneratedUrl(),
        'title' => 'Update migrations in bulk',
        'rel' => UriDefinitions::LINK_REL_BULK_UPDATE_MIGRATIONS,
        'type' => 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"',
      ];
      $total_import_count = array_reduce($resource_objects, function (int $sum, array $resource_object) : int {
        return $sum + $resource_object['attributes']['importedCount'];
      }, 0);
      // As soon as *some* imports have occurred, we can assume that the initial
      // migration has run. We check that heuristic first because we can
      // calculate that very efficiently.
      if ($total_import_count === 0) {
        // But the real proof that the initial import ran is whether the first
        // of the migration plugins that we are running as part of
        // MigrationBatchManager::createInitialMigrationBatch() actually
        // *started* to run.
        $first_migration_plugin = NULL;
        foreach ($migrations as $migration) {
          $non_data_migration_plugin_ids = array_diff($migration->getMigrationPluginIds(), $migration->getDataMigrationPluginIds());
          if (!empty($non_data_migration_plugin_ids)) {
            $first_migration_plugin_id = reset($non_data_migration_plugin_ids);
            $first_migration_plugin = $migration->getMigrationPluginInstances()[$first_migration_plugin_id];
            assert($first_migration_plugin instanceof MigrationInterface);
            break;
          }
        }
        if ($first_migration_plugin->getIdMap()->importedCount() === 0) {
          $initial_import_url = Url::fromRoute('acquia_migrate.api.migration.import.initial')
            ->setAbsolute()
            ->toString(TRUE);
          $cacheability->addCacheableDependency($initial_import_url);
          $document['links']['initial-import'] = [
            "href" => $initial_import_url->getGeneratedUrl(),
            "title" => "Initial import",
            "rel" => "https://github.com/acquia/acquia_migrate#link-rel-start-batch-process",
          ];
        }
      }
    }
    $total_message_count = $this->getTotalMigrationMessageCount();
    if ($total_message_count > 0) {
      $messages_route_url = Url::fromRoute('acquia_migrate.migrations.messages')
        ->setAbsolute()
        ->toString(TRUE);
      $cacheability->addCacheableDependency($messages_route_url);
      $document['links']['migration-messages'] = [
        'href' => $messages_route_url->getGeneratedUrl(),
        'rel' => UriDefinitions::LINK_REL_MIGRATION_MESSAGES,
        'type' => 'text/html',
        'title' => $this->t("Total errors: @messageCount", [
          '@messageCount' => $total_message_count,
        ]),
      ];
    }
    $total_entity_validation_message_count = $this->getTotalEntityValidationMigrationMessageCount();
    if ($total_entity_validation_message_count > 0) {
      $messages_route_url = Url::fromRoute('acquia_migrate.migrations.messages')
        ->setOption('query', [
          'filter' => implode(',', [
            ':eq',
            SqlWithCentralizedMessageStorage::COLUMN_CATEGORY,
            HttpApi::MESSAGE_CATEGORY_ENTITY_VALIDATION,
          ]),
        ])
        ->setAbsolute()
        ->toString(TRUE);
      $cacheability->addCacheableDependency($messages_route_url);
      $document['links']['migration-entity-validation-messages'] = [
        'href' => $messages_route_url->getGeneratedUrl(),
        'rel' => UriDefinitions::LINK_REL_MIGRATION_MESSAGES,
        'type' => 'text/html',
        'title' => $this->t("Total validation errors: @messageCount", [
          '@messageCount' => $total_entity_validation_message_count,
        ]),
      ];
    }
    $response = CacheableJsonResponse::create($document, 200, static::$defaultResponseHeaders);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Serves a single migration.
   *
   * A "migration" in this sense corresponds to a row of the migration
   * dashboard.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to serve.
   * @param \Drupal\acquia_migrate\Migration $migration
   *   The migration to serve.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   *
   * @TODO Make sure to invalidate if cached whenever migration plugins are
   * rebuilt.
   */
  public function migrationGet(Request $request, Migration $migration) {
    $this->validateRequest($request);
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(static::$defaultCacheContexts);
    $resource_object = Migration::toResourceObject($migration, $cacheability);
    $sparse = $this->getSparseFieldsetFunction($request);
    $response = CacheableJsonResponse::create([
      'data' => $sparse($resource_object),
      'links' => [
        'self' => [
          'href' => $request->getUri(),
        ],
      ],
    ], 200, static::$defaultResponseHeaders);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Serves a migration row preview.
   *
   * A "migration" in this sense corresponds to a row of the migration
   * dashboard.
   *
   * @param \Drupal\acquia_migrate\Migration $migration
   *   The migration for which to preview a row.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The client can request using the following URL query arguments:
   *   - byOffset, with a numeric value between 0 and the number of rows
   *   - byUrl, with a source URL provided — path aliases are supported.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function migrationRowPreview(Migration $migration, Request $request) : JsonResponse {
    $this->validateRequestHeaders($request);

    if (!$request->query->has('byOffset') && !$request->query->has('byUrl')) {
      return JsonResponse::create([
        'errors' => [
          [
            'code' => (string) 400,
            'status' => Response::$statusTexts[400],
            'detail' => 'Either the `byOffset` or `byUrl` URL query argument must be specified.',
          ],
        ],
      ], 400, static::$defaultResponseHeaders);
    }

    $selection_mode = $request->query->has('byOffset')
      ? MigrationPreviewer::ROW_SELECTION_BY_OFFSET
      : MigrationPreviewer::ROW_SELECTION_BY_URL;
    $selection_value = $selection_mode === MigrationPreviewer::ROW_SELECTION_BY_OFFSET
      ? $request->query->get('byOffset')
      : $request->query->get('byUrl');

    $preview_row = $this->migrationPreviewer->getRowToPreview($migration, $selection_mode, $selection_value);

    $resource_object = $this->migrationPreviewer->migrationRowToMigrationPreviewResourceObject($migration, $preview_row);
    if ($request->query->has('byOffset')) {
      $offset = (int) $request->query->get('byOffset');
      $url = Url::fromRoute('acquia_migrate.api.migration.preview')
        ->setRouteParameter('migration', $migration->id())
        ->setAbsolute();
      if ($offset > 0) {
        $url->setOption('query', ['byOffset' => $offset - 1]);
        $resource_object['links']['prev'] = [
          'href' => $url->toString(TRUE)->getGeneratedUrl(),
          'title' => $this->t('Preview previous row'),
        ];
      }
      if ($this->migrationPreviewer->getSensibleRowCount($migration) > $offset + 1) {
        $url->setOption('query', ['byOffset' => $offset + 1]);
        $resource_object['links']['next'] = [
          'href' => $url->toString(TRUE)->getGeneratedUrl(),
          'title' => $this->t('Preview next row'),
        ];
      }
    }
    return new JsonResponse([
      'data' => $resource_object,
    ], 200, static::$defaultResponseHeaders);
  }

  /**
   * Serves a content entity migration's field mapping.
   *
   * A "migration" in this sense corresponds to a row of the migration
   * dashboard.
   *
   * @param \Drupal\acquia_migrate\Migration $migration
   *   The migration for which to generate a
   *   migrationMappingForContentEntityType resource object.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function migrationMappingGet(Migration $migration, Request $request) : JsonResponse {
    $this->validateRequestHeaders($request);

    try {
      $data_migration_plugin = $this->migrationPreviewer->getDataMigrationPluginToPreview($migration);
      if ($data_migration_plugin === NULL) {
        throw new \InvalidArgumentException('This migration does not support field mappings.', 400);
      }

      $mapped_source_columns = [];
      $mapped_fields = $this->migrationMappingViewer->getMappedFields($data_migration_plugin, $mapped_source_columns);
      $destination_only_fields = $this->migrationMappingViewer->getDestinationOnlyFields($data_migration_plugin);
      $source_only_fields = $this->migrationMappingViewer->getSourceOnlyFields($mapped_source_columns, $data_migration_plugin);
    }
    catch (\InvalidArgumentException $e) {
      return JsonResponse::create([
        'errors' => [
          [
            'code' => (string) $e->getCode(),
            'status' => Response::$statusTexts[$e->getCode()],
            'detail' => $e->getMessage(),
          ],
        ],
      ], $e->getCode(), static::$defaultResponseHeaders);
    }

    $uri_template_suggestions = [
      'drop-source-field' => [],
      'revert-field-overrides' => [],
    ];
    if ($this->migrationMappingManipulator->isOverridden($data_migration_plugin)) {
      foreach (array_keys($mapped_fields) as $destination_field_name) {
        // @todo Support overriding of mappings from source fields to destination field properties.
        if (strpos($destination_field_name, '/') !== FALSE) {
          continue;
        }

        if ($this->migrationMappingManipulator->hasOverriddenProcessPipeline($data_migration_plugin, $destination_field_name)) {
          $uri_template_suggestions['revert-field-overrides'][] = [
            'label' => $mapped_fields[$destination_field_name]['destinationFieldLabel'],
            'value' => urlencode($destination_field_name),
          ];
        }
        else {
          $uri_template_suggestions['drop-source-field'][] = [
            'label' => $mapped_fields[$destination_field_name]['destinationFieldLabel'],
            'value' => urlencode($destination_field_name),
          ];
        }
      }
      foreach (array_keys($destination_only_fields) as $destination_field_name) {
        if ($this->migrationMappingManipulator->hasOverriddenProcessPipeline($data_migration_plugin, $destination_field_name)) {
          $uri_template_suggestions['revert-field-overrides'][] = [
            'label' => $destination_field_name,
            'value' => urlencode($destination_field_name),
          ];
        }
      }
    }

    $is_overridden = $this->migrationMappingManipulator->isOverridden($data_migration_plugin);
    $has_imported_data = $migration->getImportedCount() > 0;
    $dynamic_links = [];
    if ($has_imported_data) {
      if (!$is_overridden) {
        $dynamic_links['override'] = [
          'href' => 'https://github.com/acquia/acquia_migrate#application-concept-no-imported-data',
          'title' => 'Overriding a field mapping is not allowed when data has already been imported. Only after rolling back already migrated data you can override the mapping.',
          'rel' => UriDefinitions::LINK_REL_UNMET_REQUIREMENT,
        ];
      }
      else {
        $dynamic_links['revert'] = [
          'href' => 'https://github.com/acquia/acquia_migrate#application-concept-no-imported-data',
          'title' => 'Reverting a field mapping is not allowed when data has already been imported. Only after rolling back already migrated data you can revert the mapping.',
          'rel' => UriDefinitions::LINK_REL_UNMET_REQUIREMENT,
        ];
      }
    }
    else {
      if (!$is_overridden) {
        $dynamic_links['override'] = [
          'href' => $request->getUri(),
          'title' => 'Override',
          'rel' => UriDefinitions::LINK_REL_UPDATE_RESOURCE,
          'params' => [
            'confirm' => "I'm sure, I want to override this field mapping.",
            'data' => [
              'type' => 'migrationMappingForContentEntityType',
              'id' => $data_migration_plugin->id(),
              'attributes' => [
                'overridden' => TRUE,
              ],
            ],
          ],
        ];
      }
      else {
        if (!empty($uri_template_suggestions['drop-source-field'])) {
          $url = Url::fromRoute('acquia_migrate.api.migration.mapping.drop_source_field')
            ->setRouteParameter('migration', $migration->id())
            ->setRouteParameter('destination_field_name', '{destinationFieldName}');
          $dynamic_links['drop-source-field'] = [
            'title' => 'Skip source field',
            'rel' => UriDefinitions::LINK_REL_MAPPING_OVERRIDE_FIELD,
            'uri-template:href' => str_replace(urlencode('{destinationFieldName}'), '{destinationFieldName}', $url->setAbsolute()
              ->toString(TRUE)
              ->getGeneratedUrl()),
            'uri-template:suggestions' => [
              'label' => 'Destination field',
              'variable' => 'destinationFieldName',
              'cardinality' => 1,
              'options' => $uri_template_suggestions['drop-source-field'],
            ],
          ];
        }
        if (!empty($uri_template_suggestions['revert-field-overrides'])) {
          $url = Url::fromRoute('acquia_migrate.api.migration.mapping.revert_field_overrides')
            ->setRouteParameter('migration', $migration->id())
            ->setRouteParameter('destination_field_name', '{destinationFieldName}');
          $dynamic_links['revert-field-overrides'] = [
            'title' => 'Revert field overrides',
            'rel' => UriDefinitions::LINK_REL_MAPPING_OVERRIDE_FIELD,
            'uri-template:href' => str_replace(urlencode('{destinationFieldName}'), '{destinationFieldName}', $url->setAbsolute()
              ->toString(TRUE)
              ->getGeneratedUrl()),
            'uri-template:suggestions' => [
              'label' => 'Destination field',
              'variable' => 'destinationFieldName',
              'cardinality' => 1,
              'options' => $uri_template_suggestions['revert-field-overrides'],
            ],
          ];
        }
        $dynamic_links['revert'] = [
          'href' => $request->getUri(),
          'title' => 'Revert',
          'rel' => UriDefinitions::LINK_REL_UPDATE_RESOURCE,
          'params' => [
            'confirm' => "I'm sure, revert all my overrides.",
            'data' => [
              'type' => 'migrationMappingForContentEntityType',
              'id' => $data_migration_plugin->id(),
              'attributes' => [
                'overridden' => FALSE,
              ],
            ],
          ],
        ];
      }
    }

    return new JsonResponse([
      'data' => [
        'type' => 'migrationMappingForContentEntityType',
        'id' => $data_migration_plugin->id(),
        'attributes' => [
          'mappedFields' => $mapped_fields,
          'destinationOnlyFields' => $destination_only_fields,
          'sourceOnlyFields' => $source_only_fields,
          'overridden' => $is_overridden,
        ],
        'relationships' => [
          'sourceMigration' => [
            'data' => [
              'type' => 'migration',
              'id' => $migration->id(),
            ],
          ],
        ],
        'links' => [
          'self' => [
            'href' => $request->getUri(),
          ],
        ] + $dynamic_links,
      ],
    ], 200, array_merge(static::$defaultResponseHeaders, [
      'Content-Type' => 'application/vnd.api+json; ext="' . UriDefinitions::EXTENSION_URI_TEMPLATE . '"',
    ]));
  }

  /**
   * Drops a source field by destination field name from the migration mapping.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to serve.
   * @param \Drupal\acquia_migrate\Migration $migration
   *   The migration to patch.
   * @param string $destination_field_name
   *   A field name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function migrationMappingDropSourceField(Request $request, Migration $migration, string $destination_field_name) : JsonResponse {
    $this->validateRequestHeaders($request);

    try {
      $data_migration_plugin = $this->migrationPreviewer->getDataMigrationPluginToPreview($migration);
      if ($data_migration_plugin === NULL) {
        throw new \InvalidArgumentException('This migration does not support field mappings.', 400);
      }

      // Provide a clear error message when the overriding config entity has
      // not yet been created.
      // @see \Drupal\acquia_migrate\MigrationMappingManipulator::convertMigrationPluginInstanceToConfigEntity()
      if (!$this->migrationMappingManipulator->isOverridden($data_migration_plugin)) {
        throw new \InvalidArgumentException('This migration mapping needs to have its `overridden` attribute set to `true` first.', 400);
      }

      // Provide a clear error message when the overriding config entity is
      // already overriding the process pipeline for the specified destination
      // field name.
      if ($this->migrationMappingManipulator->hasOverriddenProcessPipeline($data_migration_plugin, $destination_field_name)) {
        throw new \InvalidArgumentException(sprintf('The process pipeline for the `%s` destination field has already been modified; revert those changes before dropping it.', $destination_field_name), 400);
      }

      $this->migrationMappingManipulator->dropSourceField($data_migration_plugin, $destination_field_name);

      return JsonResponse::create('', 204, static::$defaultResponseHeaders);
    }
    catch (\InvalidArgumentException $e) {
      return JsonResponse::create([
        'errors' => [
          [
            'code' => (string) $e->getCode(),
            'status' => Response::$statusTexts[$e->getCode()],
            'detail' => $e->getMessage(),
          ],
        ],
      ], $e->getCode(), static::$defaultResponseHeaders);
    }
  }

  /**
   * Reverts an overridden migration mapping field by destination field name.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to serve.
   * @param \Drupal\acquia_migrate\Migration $migration
   *   The migration to patch.
   * @param string $destination_field_name
   *   A field name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function migrationMappingRevertOverrides(Request $request, Migration $migration, string $destination_field_name) : JsonResponse {
    $this->validateRequestHeaders($request);

    try {
      $data_migration_plugin = $this->migrationPreviewer->getDataMigrationPluginToPreview($migration);
      if ($data_migration_plugin === NULL) {
        throw new \InvalidArgumentException('This migration does not support field mappings.', 400);
      }

      // Provide a clear error message when the overriding config entity has not
      // yet been created.
      // @see \Drupal\acquia_migrate\MigrationMappingManipulator::convertMigrationPluginInstanceToConfigEntity()
      if (!$this->migrationMappingManipulator->isOverridden($data_migration_plugin)) {
        throw new \InvalidArgumentException('This migration mapping needs to have its `overridden` attribute set to `true` first.', 400);
      }

      // Provide a clear error message when the overriding config entity is not
      // in fact overriding the process pipeline for the specified destination
      // field name.
      [$modified, $removed, $added] = $this->migrationMappingManipulator->getProcessPipelineOverrides($data_migration_plugin);
      $all_overrides = array_merge($modified, $removed, $added);
      if (!in_array($destination_field_name, $all_overrides, TRUE)) {
        throw new \InvalidArgumentException(sprintf('The process pipeline for the `%s` destination field has not been modified; there is nothing to do.', $destination_field_name), 400);
      }

      $this->migrationMappingManipulator->revertProcessPipelineOverride($data_migration_plugin, $destination_field_name);

      return JsonResponse::create('', 204, static::$defaultResponseHeaders);
    }
    catch (\InvalidArgumentException $e) {
      return JsonResponse::create([
        'errors' => [
          [
            'code' => (string) $e->getCode(),
            'status' => Response::$statusTexts[$e->getCode()],
            'detail' => $e->getMessage(),
          ],
        ],
      ], $e->getCode(), static::$defaultResponseHeaders);
    }
  }

  /**
   * Patches a single migration mapping.
   *
   * A "migration" in this sense corresponds to a row of the migration
   * dashboard, and the "migration mapping" corresponds to the primary data
   * migration plugin of that migration.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to serve.
   * @param \Drupal\acquia_migrate\Migration $migration
   *   The migration to patch.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function migrationMappingPatch(Request $request, Migration $migration) {
    $this->validateRequest($request);

    $document = Json::decode($request->getContent());
    $data = $document['data'] ?? FALSE;
    if (!$data) {
      throw new BadRequestHttpException('Request document is missing the data member.');
    }
    // @todo Allow PATCHing of more fields than only the "completed" and "skipped" attributes.
    if (isset($data['relationships']) || !empty(array_diff(array_keys($data['attributes']), ['overridden']))) {
      return JsonResponse::create([
        'errors' => [
          [
            'code' => (string) 403,
            'status' => Response::$statusTexts[403],
            'detail' => 'Only the `overridden` field can be updated.',
            'links' => [
              'via' => [
                'href' => $request->getUri(),
              ],
            ],
          ],
        ],
      ], 403, static::$defaultResponseHeaders);
    }

    foreach ($data['attributes'] as $attribute => $value) {
      assert(in_array($attribute, ['overridden'], TRUE));
      if (!is_bool($value)) {
        return JsonResponse::create([
          'errors' => [
            [
              'code' => (string) 403,
              'status' => Response::$statusTexts[403],
              'detail' => "Only boolean values are allowed for the `$attribute` attribute.",
              'links' => [
                'via' => [
                  'href' => $request->getUri(),
                ],
              ],
            ],
          ],
        ], 403, static::$defaultResponseHeaders);
      }

      try {
        $data_migration_plugin = $this->migrationPreviewer->getDataMigrationPluginToPreview($migration);
        if ($data_migration_plugin === NULL) {
          throw new \InvalidArgumentException('This migration does not support field mappings.', 400);
        }

        switch ($attribute) {
          case 'overridden':
            if ($value === TRUE) {
              $this->migrationMappingManipulator->convertMigrationPluginInstanceToConfigEntity($data_migration_plugin);
            }
            else {
              $this->migrationMappingManipulator->deleteConfigEntityForMigrationPluginInstance($data_migration_plugin);
            }
        }
      }
      catch (\InvalidArgumentException $e) {
        return JsonResponse::create([
          'errors' => [
            [
              'code' => (string) $e->getCode(),
              'status' => Response::$statusTexts[$e->getCode()],
              'detail' => $e->getMessage(),
            ],
          ],
        ], $e->getCode(), static::$defaultResponseHeaders);
      }
    }

    return JsonResponse::create('', 204, static::$defaultResponseHeaders);
  }

  /**
   * Patches a single migration.
   *
   * A "migration" in this sense corresponds to a row of the migration
   * dashboard.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to serve.
   * @param \Drupal\acquia_migrate\Migration $migration
   *   The migration to patch.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function migrationPatch(Request $request, Migration $migration) {
    $this->validateRequest($request);

    $document = Json::decode($request->getContent());
    $data = $document['data'] ?? FALSE;
    if (!$data) {
      throw new BadRequestHttpException('Request document is missing the data member.');
    }
    // @todo Allow PATCHing of more fields than only the "completed" and "skipped" attributes.
    if (isset($data['relationships']) || !empty(array_diff(array_keys($data['attributes']), ['completed', 'skipped']))) {
      return JsonResponse::create([
        'errors' => [
          [
            'code' => (string) 403,
            'status' => Response::$statusTexts[403],
            'detail' => 'Only the `completed` and `skipped` fields can be updated.',
            'links' => [
              'via' => [
                'href' => $request->getUri(),
              ],
            ],
          ],
        ],
      ], 403, static::$defaultResponseHeaders);
    }

    foreach ($data['attributes'] as $attribute => $value) {
      assert(in_array($attribute, ['completed', 'skipped'], TRUE));
      if (!is_bool($value)) {
        return JsonResponse::create([
          'errors' => [
            [
              'code' => (string) 403,
              'status' => Response::$statusTexts[403],
              'detail' => "Only boolean values are allowed for the `$attribute` attribute.",
              'links' => [
                'via' => [
                  'href' => $request->getUri(),
                ],
              ],
            ],
          ],
        ], 403, static::$defaultResponseHeaders);
      }
      $this->connection->update('acquia_migrate_migration_flags')
        ->fields([$attribute => (int) $value])
        ->condition('migration_id', $migration->id())
        ->execute();
    }

    return JsonResponse::create('', 204, static::$defaultResponseHeaders);
  }

  /**
   * Serves a request to update migrations in bulk.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function bulkUpdateMigrations(Request $request) : JsonResponse {
    $this->validateRequest($request, [], ['required' => ['https://jsonapi.org/ext/atomic']]);

    $request_document = Json::decode((string) $request->getContent());

    $operations = $request_document['atomic:operations'] ?? [];

    $migration_preselections = [];
    array_walk($operations, function (array $operation, int $operation_index) use (&$migration_preselections) {
      $op = $operation['op'];
      $data = $operation['data'];
      if ($op !== 'update') {
        throw new BadRequestHttpException(sprintf('The %s atomic operation is not supported by this resource.', $op));
      }
      if (($data['type'] ?? NULL) !== 'migration') {
        throw new BadRequestHttpException(sprintf('This resource only supports updates to migration resource types.'));
      }
      if (!is_string($data['id'] ?? NULL)) {
        throw new BadRequestHttpException(sprintf('An update operation\'s data must include an id member.'));
      }
      if (count(array_intersect_key($data['attributes'] ?? [], array_flip(['skipped', 'completed']))) !== 1) {
        throw new BadRequestHttpException(sprintf('This resource only supports skipping, unskipping, completing and un-completing migration resources.'));
      }
      try {
        $migration = $this->repository->getMigration($data['id']);
        $patch_url = Url::fromRoute('acquia_migrate.api.migration.patch', ['migration' => $migration->id()]);
        $patch_request = Request::create($patch_url->toString(), 'PATCH', [], [], [], [], Json::encode(['data' => $data]));
        $patch_request->headers->set('Accept', 'application/vnd.api+json');
        $patch_request->headers->set('Content-Type', 'application/vnd.api+json');
        $response = $this->migrationPatch($patch_request, $migration);
        $skipped = $data['attributes']['skipped'] ?? NULL;
        // Only the requests that modify the `skipped` attribute may count as a
        // preselection.
        if (!is_null($skipped)) {
          $migration_preselections[$migration->id()] = (bool) $skipped;
        }
        if ($response->getStatusCode() !== 204) {
          throw new FailedAtomicOperationException($operation_index, [
            [
              'code' => 500,
              'status' => Response::$statusTexts[500],
              'detail' => 'An operation failed to process as expected.',
            ],
          ]);
        };
      }
      catch (AcquiaMigrateHttpExceptionInterface $e) {
        $exception_response = $e->getHttpResponse();
        $response_document = Json::decode((string) $exception_response->getContent());
        throw new FailedAtomicOperationException($operation_index, $response_document['errors']);
      }
      catch (\Exception $e) {
        throw new FailedAtomicOperationException($operation_index, [
          [
            'code' => 500,
            'status' => Response::$statusTexts[500],
            'detail' => $e->getMessage(),
          ],
        ]);
      }
    });

    $grouped = array_reduce(array_keys($migration_preselections), function (array $grouped, string $id) use ($migration_preselections) {
      $group_name = $migration_preselections[$id] === TRUE ? 'skipped' : 'unskipped';
      $grouped[$group_name][] = $id;
      return $grouped;
    }, ['skipped' => [], 'unskipped' => []]);

    $num_affected = 0;
    foreach ($grouped as $choice => $preselection) {
      if (!empty($preselection)) {
        $num_affected += $this->connection->update('acquia_migrate_migration_flags')
          ->isNull('preselection')
          ->fields(['preselection' => (int) ($choice === 'unskipped')])
          ->condition('migration_id', $preselection, 'IN')
          ->execute();
      }
    }

    if ($num_affected > 0 && !$this->repository->migrationsHaveBeenPreselected()) {
      // Now that preselections have been made (they're only made once), restore
      // the standard front page setting so that the front page no longer points
      // to the list of steps that need to be completed before importing
      // content.
      $config = $this->configFactory->getEditable('system.site');
      $config->set('page.front', '/node');
      $config->save();
    }

    return JsonResponse::create(NULL, 204, array_merge(
      static::$defaultResponseHeaders,
      [
        'Content-Type' => 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"',
      ]
    ));
  }

  /**
   * Serves a listing of messages.
   *
   * @todo Pagination + filtering by sourceMigration, sourceMigrationPlugin, message and messageType will be implemented in https://backlog.acquia.com/browse/OCTO-2990.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to serve.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function messagesCollection(Request $request): JsonResponse {
    $this->validateRequest($request, [
      'optional' => ['filter'],
    ]);
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(static::$defaultCacheContexts);
    $query = $this->getQueryFromRequest($request);
    $data = array_map($this->getSparseFieldsetFunction($request), $this->loadMessages($query));
    $severity_levels = RfcLogLevel::getLevels();
    $categories = $this->getFilterCategories();
    $generated_messages_url = Url::fromRoute('acquia_migrate.api.messages.get')->setAbsolute()->toString(TRUE);
    $cacheability->addCacheableDependency($generated_messages_url);
    return (new CacheableJsonResponse([
      'data' => $data,
      'links' => [
        'self' => [
          'href' => $request->getUri(),
        ],
        'query' => [
          'href' => $generated_messages_url->getGeneratedUrl(),
          'uri-template:href' => $generated_messages_url->getGeneratedUrl() . '{?filter*}',
          'uri-template:suggestions' => [
            [
              'label' => $this->t('Migration'),
              'variable' => 'filter',
              'cardinality' => 1,
              'field' => SqlWithCentralizedMessageStorage::COLUMN_MIGRATION_ID,
              'operator' => ':eq',
              'options' => array_reduce($this->loadMigrations(), function (array $options, Migration $migration) {
                return array_merge($options, [
                  [
                    'label' => $migration->label(),
                    'value' => $migration->id(),
                  ],
                ]);
              }, []),
            ],
            [
              'label' => $this->t('Severity'),
              'variable' => 'filter',
              'cardinality' => 1,
              'operator' => ':eq',
              'field' => SqlWithCentralizedMessageStorage::COLUMN_SEVERITY,
              'options' => array_reduce(array_keys($severity_levels), function (array $values, int $level) use ($severity_levels) {
                return array_merge($values, [
                  [
                    'label' => (string) $severity_levels[$level],
                    'value' => "{$level}",
                  ],
                ]);
              }, []),
            ],
            [
              'label' => $this->t('Type'),
              'variable' => 'filter',
              'cardinality' => 1,
              'operator' => ':eq',
              'field' => SqlWithCentralizedMessageStorage::COLUMN_CATEGORY,
              'options' => array_reduce(array_keys($categories), function (array $values, string $category) use ($categories) {
                return array_merge($values, [
                  [
                    'label' => $categories[$category],
                    'value' => $category,
                  ],
                ]);
              }, []),
            ],
          ],
          'rel' => UriDefinitions::LINK_REL_QUERY,
        ],
      ],
    ], 200, array_merge(static::$defaultResponseHeaders, [
      'Content-Type' => 'application/vnd.api+json; ext="' . UriDefinitions::EXTENSION_URI_TEMPLATE . '"',
    ])))->addCacheableDependency($cacheability);
  }

  /**
   * Creates a batch and provides a link to follow to process that batch.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to serve.
   * @param string $migration_action
   *   The migration action to start processing. Either 'import', 'rollback', or
   *   'rollback-and-import'.
   *   This argument is defined as a route default.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function migrationStart(Request $request, string $migration_action) : JsonResponse {
    $this->validateRequest($request, [
      'required' => ['migrationId'],
    ]);
    $migration_id = $request->get('migrationId');
    // @todo: validate that the requested migration ID exists.
    $batch_url = $this->getMigrationProcessUrl($migration_id, $migration_action)->setAbsolute();
    return JsonResponse::create([
      'meta' => [
        'note' => 'The migration process has been started, follow the `next` link to continue processing it.',
      ],
      'links' => [
        'next' => [
          'href' => $batch_url->toString(),
        ],
      ],
    ], 303, array_merge(static::$defaultResponseHeaders, [
      'Location' => $batch_url->toString(),
    ]));
  }

  /**
   * Creates an "initial import" batch.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   *
   * @see ::migrationStart
   * @todo Remove or rewrite this once the "Content structure" screen is built.
   */
  public function migrationImportInitial() : JsonResponse {
    $batch_status = $this->migrationBatchManager->createInitialMigrationBatch();
    $batch_url = Url::fromRoute('acquia_migrate.api.migration.process', [
      'process_id' => $batch_status->getId(),
    ])->setAbsolute();
    return JsonResponse::create([
      'meta' => [
        'note' => 'The migration process has been started, follow the `next` link to continue processing it.',
      ],
      'links' => [
        'next' => [
          'href' => $batch_url->toString(),
        ],
      ],
    ], 303, array_merge(static::$defaultResponseHeaders, [
      'Location' => $batch_url->toString(),
    ]));
  }

  /**
   * Gets information for the requested migration process.
   *
   * This has the effect of progressing the underlying batch.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to serve.
   * @param int $process_id
   *   The process ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function migrationProcess(Request $request, int $process_id): JsonResponse {
    $this->validateRequest($request);
    $batch_status = $this->migrationBatchManager->isMigrationBatchOngoing($process_id);
    if ($batch_status instanceof BatchUnknown) {
      // @todo: should this be a cacheable response? If so, we'll need to mint and invalidate a cache tag for it.
      return JsonResponse::create(NULL, 404, static::$defaultResponseHeaders);
    }
    $data = [
      'type' => 'migrationProcess',
      'id' => (string) $process_id,
      'attributes' => [
        'progressRatio' => $batch_status->getProgress(),
      ],
    ];
    $self_url = Url::fromUri($request->getUri())->setAbsolute();
    $links = ['self' => ['href' => $self_url->toString()]];
    if ($batch_status->getProgress() < 1) {
      $links['next'] = ['href' => $self_url->toString()];
    }
    return JsonResponse::create([
      'data' => $data,
      'links' => $links,
    ], 200, static::$defaultResponseHeaders);
  }

  /**
   * Creates a batch for the given migration and returns a URL to follow.
   *
   * @param string $migration
   *   The migration ID for which a batch should be created and an initial URL
   *   provided.
   * @param string $action
   *   An action type. Either MigrationBatchManager::ACTION_IMPORT,
   *   MigrationBatchManager::ACTION_ROLLBACK, or
   *   MigrationBatchManager::ACTION_ROLLBACK_AND_IMPORT.
   *
   * @return \Drupal\Core\Url
   *   The first batch URL, following this URL should allow Drupal to start
   *   processing the batch.
   *
   * @see \Drupal\acquia_migrate\Batch\MigrationBatchManager::ACTION_IMPORT
   * @see \Drupal\acquia_migrate\Batch\MigrationBatchManager::ACTION_ROLLBACK
   * @see \Drupal\acquia_migrate\Batch\MigrationBatchManager::ACTION_ROLLBACK_AND_IMPORT
   */
  protected function getMigrationProcessUrl(string $migration, string $action): Url {
    $batch_status = $this->migrationBatchManager->createMigrationBatch($migration, $action);
    $batch_id = $batch_status->getId();
    return Url::fromRoute('acquia_migrate.api.migration.process', [
      'process_id' => $batch_id,
    ]);
  }

  /**
   * Loads all migrations as an array of resource objects.
   *
   * @return \Drupal\acquia_migrate\Migration[]
   *   An array of resource objects.
   */
  protected function loadMigrations() : array {
    return $this->repository->getMigrations();
  }

  /**
   * Loads all messages as an array of resource objects.
   *
   * @param \League\Uri\Contracts\QueryInterface $query
   *   The URL query.
   *
   * @return array
   *   An array of resource objects.
   */
  protected function loadMessages(QueryInterface $query): array {
    if (!$this->connection->schema()->tableExists(SqlWithCentralizedMessageStorage::CENTRALIZED_MESSAGE_TABLE)) {
      return [];
    }

    // We need the migrations just to associate labels.
    $migrations = $this->repository->getMigrations();

    $message_query = $this->connection->select(SqlWithCentralizedMessageStorage::CENTRALIZED_MESSAGE_TABLE, 'm');

    // Process any requested filters.
    foreach ($query->getAll('filter') as $parameter_value) {
      list($field, $value, $operator) = $this->parseFilterParameter($parameter_value);
      $message_query->condition($field, $value, static::$filterOperatorMap[$operator]);
    }

    $message_query
      ->fields('m', [
        SqlWithCentralizedMessageStorage::COLUMN_DATETIME,
        SqlWithCentralizedMessageStorage::COLUMN_MIGRATION_ID,
        SqlWithCentralizedMessageStorage::COLUMN_MIGRATION_PLUGIN_ID,
        SqlWithCentralizedMessageStorage::COLUMN_SOURCE_ID,
        SqlWithCentralizedMessageStorage::COLUMN_CATEGORY,
        SqlWithCentralizedMessageStorage::COLUMN_SEVERITY,
        'message',
        'msgid',
      ]);

    $results = $message_query->execute();

    $messages = [];
    foreach ($results as $message) {
      $datetime = (new \DateTime())
        ->setTimestamp($message->{SqlWithCentralizedMessageStorage::COLUMN_DATETIME})
        ->setTimezone(new \DateTimeZone('UTC'))
        ->format(\DateTime::RFC3339);

      $solution = $this->messageAnalyzer->getSolution(
        $message->{SqlWithCentralizedMessageStorage::COLUMN_MIGRATION_PLUGIN_ID},
        $message->message
      );

      $messages[] = [
        'type' => 'migrationMessage',
        'id' => $message->msgid,
        'attributes' => [
          'datetime' => $datetime,
          'severity' => $message->{SqlWithCentralizedMessageStorage::COLUMN_SEVERITY},
          'message' => $message->message,
          'solution' => $solution,
          'messageCategory' => $message->{SqlWithCentralizedMessageStorage::COLUMN_CATEGORY},
        ],
        'relationships' => [
          'sourceMigration' => [
            'data' => [
              'type' => 'migration',
              'id' => $message->{SqlWithCentralizedMessageStorage::COLUMN_MIGRATION_ID},
              'meta' => [
                'label' => $migrations[$message->{SqlWithCentralizedMessageStorage::COLUMN_MIGRATION_ID}]->label(),
              ],
            ],
          ],
          'sourceMigrationPlugin' => [
            'data' => [
              'type' => 'migrationPlugin',
              'id' => $message->{SqlWithCentralizedMessageStorage::COLUMN_MIGRATION_PLUGIN_ID},
            ],
          ],
        ],
        'links' => [
          'severity' => [
            'href' => '/',
            'rel' => UriDefinitions::LINK_REL_SYSLOG_SEVERITY,
            'title' => strtolower((string) RfcLogLevel::getLevels()[$message->severity]),
          ],
          'source' => static::generateSourceLinkObject($message),
        ],
      ];
    }

    return $messages;
  }

  /**
   * Generates a JSON:API link object for the "source" link based on source_id.
   *
   * @param object $message
   *   A migration message database record.
   *
   * @return array
   *   A JSON:API link object.
   */
  private static function generateSourceLinkObject($message) {
    $link_object = [
      'href' => '#not-yet-implemented',
      'meta' => [
        'source-identifiers' => [],
      ],
    ];
    $source_id_values = explode('|', $message->{SqlWithCentralizedMessageStorage::COLUMN_SOURCE_ID});
    foreach ($source_id_values as $source_id_value) {
      list($key, $value) = explode('=', $source_id_value);
      $link_object['meta']['source-identifiers'][$key] = $value;
    }
    return $link_object;
  }

  /**
   * Returns a function that applies a sparse fieldset to a resource object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   A request object.
   *
   * @return \Closure
   *   A function that takes a resource object array and removes fields
   *   according
   */
  protected function getSparseFieldsetFunction(Request $request): \Closure {
    $fields = [];
    foreach ($request->query->get('fields') ?? [] as $type => $fieldset) {
      $fields[$type] = array_map('trim', explode(',', $fieldset));
    }
    return function ($resource_object) use ($fields) {
      $type = $resource_object['type'];
      foreach (['attributes', 'relationships'] as $member) {
        if (isset($fields[$type]) && isset($resource_object[$member])) {
          $resource_object[$member] = array_intersect_key($resource_object[$member], array_flip($fields[$type]));
        }
        if (empty($resource_object[$member])) {
          unset($resource_object[$member]);
        }
      }
      return $resource_object;
    };
  }

  /**
   * Ensures that the incoming request is a valid one.
   *
   * Performs validation of basic JSON:API requirements and implementation
   * specific requirements, e.g. no query parameters other than `fields`.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   A request object.
   * @param array $valid_query_parameters
   *   (optional) An array that may contain the keys, 'optional' and 'required',
   *   and whose value is an indexed array of query parameter names.
   * @param array $valid_extensions
   *   (optional) See HttpApiTrait::validateRequestHeaders().
   *
   * @see \Drupal\acquia_migrate\Controller\HttpApiTrait::validateRequestHeaders()
   */
  protected function validateRequest(Request $request, array $valid_query_parameters = NULL, array $valid_extensions = NULL) {
    $this->validateRequestHeaders($request, $valid_extensions);
    $valid_query_parameters = NestedArray::mergeDeep([
      'optional' => ['fields'],
      'required' => [],
    ], $valid_query_parameters ?? []);
    $not_allowed_params = array_filter($request->query->keys(), function ($parameter) use ($valid_query_parameters) {
      return !in_array(
        $parameter,
        array_merge($valid_query_parameters['optional'], $valid_query_parameters['required']),
        TRUE
      );
    });
    if (count($not_allowed_params) > 1) {
      throw new MultipleClientErrorsException(array_map(function ($parameter) {
        return new QueryParameterNotAllowedException($parameter);
      }, $not_allowed_params));
    }
    elseif (count($not_allowed_params) === 1) {
      throw new QueryParameterNotAllowedException(array_pop($not_allowed_params));
    }
    $missing_query_parameters = array_diff($valid_query_parameters['required'], $request->query->keys());
    if (count($missing_query_parameters) > 1) {
      throw new MultipleClientErrorsException(array_map(function ($parameter) {
        return new MissingQueryParameterException($parameter);
      }, $missing_query_parameters));
    }
    elseif (count($missing_query_parameters) === 1) {
      throw new MissingQueryParameterException(array_pop($missing_query_parameters));
    }
    $query = $this->getQueryFromRequest($request);
    if ($query->has('filter')) {
      $exceptions = [];
      $filters = $query->getAll('filter');
      foreach ($filters as $parameter_value) {
        $arguments = explode(',', $parameter_value);
        if (count($arguments) < 3 || strpos($arguments[0], ':') !== 0) {
          $exceptions[] = new InvalidFilterParameterException('Invalid filter syntax. Expecting a filter in the form `filter=:op,field_name,value`', $parameter_value);
        }
        try {
          $this->parseFilterParameter($parameter_value);
        }
        catch (InvalidFilterParameterException $e) {
          $exceptions[] = $e;
        }
      }
      if (!empty($exceptions)) {
        throw new MultipleClientErrorsException($exceptions);
      }
    }
  }

  /**
   * Validates and parses filter parameters.
   *
   * @param string $parameter_value
   *   The `:eq,field_name,42` part in `filter=:eq,field_name,42`.
   *
   * @return string[]
   *   An 3-tuple array containing the filter field name, value, and operator,
   *   in that order.
   */
  protected function parseFilterParameter(string $parameter_value) {
    $parts = array_pad(explode(',', $parameter_value, 3), 3, NULL);
    $invalid_request_message = FALSE;
    if (!in_array($parts[0], array_keys(static::$filterOperatorMap), TRUE)) {
      $allowed_operators = array_keys(static::$filterOperatorMap);
      $invalid_request_message = "The `{$parts[0]}` operator is not supported. Allowed operators are: ";
      $invalid_request_message .= count($allowed_operators) > 2
        ? '`' . implode('`, `', array_slice($allowed_operators, 0, -1)) . '`, and `' . end($allowed_operators) . '`.'
        : '`' . implode('` and `', $allowed_operators) . '`.';
      throw new InvalidFilterParameterException($invalid_request_message, $parameter_value);
    }
    elseif (!in_array($parts[1], array_keys($this->getFilterableFields()), TRUE)) {
      $invalid_request_message = "The `{$parts[1]}` field is not a recognized, filterable field.";
      throw new InvalidFilterParameterException($invalid_request_message, $parameter_value);
    }
    elseif (!in_array($parts[2], $this->getFilterableFields()[$parts[1]], TRUE)) {
      $invalid_request_message = "The `{$parts[1]}` field cannot be filtered by the requested value, `{$parts[2]}`.";
      throw new InvalidFilterParameterException($invalid_request_message, $parameter_value);
    }
    return [$parts[1], $parts[2], $parts[0]];
  }

  /**
   * Gets a total message count across all migrations and migration plugins.
   *
   * @return int
   *   The message count.
   */
  protected function getTotalMigrationMessageCount() : int {
    // @codingStandardsIgnoreStart
    $connection = \Drupal::database();
    // @codingStandardsIgnoreEnd

    if (!$connection->schema()->tableExists(SqlWithCentralizedMessageStorage::CENTRALIZED_MESSAGE_TABLE)) {
      return 0;
    }

    return $connection->select(SqlWithCentralizedMessageStorage::CENTRALIZED_MESSAGE_TABLE)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Gets total entity validation message count across all migration (plugins).
   *
   * @return int
   *   The entity validation message count.
   */
  protected function getTotalEntityValidationMigrationMessageCount() : int {
    // @codingStandardsIgnoreStart
    $connection = \Drupal::database();
    // @codingStandardsIgnoreEnd

    if (!$connection->schema()->tableExists(SqlWithCentralizedMessageStorage::CENTRALIZED_MESSAGE_TABLE)) {
      return 0;
    }

    return $connection->select(SqlWithCentralizedMessageStorage::CENTRALIZED_MESSAGE_TABLE, 'm')
      ->condition('m.messageCategory', HttpApi::MESSAGE_CATEGORY_ENTITY_VALIDATION)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Gets a parse query object a request object.
   *
   * The Symfony-parsed query is unusable because it does not correctly parse
   * query strings generated by an expanded URI template. Specifically, the
   * query `Request::create('?q=foo&q=bar')->query->get('q')` returns `'bar'`
   * instead of `['foo', 'bar']`.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The Symfony request object.
   *
   * @return \League\Uri\Contracts\QueryInterface
   *   A parse query object.
   */
  protected function getQueryFromRequest(Request $request) : QueryInterface {
    return Query::createFromUri(Uri::createFromString($request->getRequestUri()));
  }

}
