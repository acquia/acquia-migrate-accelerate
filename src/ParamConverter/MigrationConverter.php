<?php

namespace Drupal\acquia_migrate\ParamConverter;

use Drupal\acquia_migrate\MigrationRepository;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Symfony\Component\Routing\Route;

/**
 * The "migration" parameter converter.
 *
 * @internal
 */
final class MigrationConverter implements ParamConverterInterface {

  /**
   * The migration repository.
   *
   * @var \Drupal\acquia_migrate\MigrationRepository
   */
  protected $migrationRepository;

  /**
   * Constructs a migration converter.
   *
   * @param \Drupal\acquia_migrate\MigrationRepository $migration_repository
   *   The migration repository.
   */
  public function __construct(MigrationRepository $migration_repository) {
    $this->migrationRepository = $migration_repository;
  }

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    return $this->migrationRepository->getMigration($value);
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return (!empty($definition['type']) && $definition['type'] == 'migration');
  }

}
