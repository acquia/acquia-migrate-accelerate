<?php

namespace Drupal\acquia_migrate\Controller;

use Drupal\acquia_migrate\MigrationRepository;
use Drupal\acquia_migrate\SourceDatabase;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Presents a getting started page for new users.
 *
 * @internal
 */
final class GetStarted extends ControllerBase {

  /**
   * The Acquia Migrate: Accelerate migration repository.
   *
   * @var \Drupal\acquia_migrate\MigrationRepository
   */
  protected $migrationRepository;

  /**
   * GetStarted constructor.
   *
   * @param \Drupal\acquia_migrate\MigrationRepository $migration_repository
   *   The Acquia Migrate migration repository.
   */
  public function __construct(MigrationRepository $migration_repository) {
    $this->migrationRepository = $migration_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('acquia_migrate.migration_repository'));
  }

  /**
   * Return a page render array.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array or redirect.
   */
  public function build() {
    $current_url = Url::fromRoute('<current>')->toString();
    $preselect_url = Url::fromRoute('acquia_migrate.migrations.preselect');
    $dashboard_url = Url::fromRoute('acquia_migrate.migrations.dashboard');
    $steps = [];
    $steps['authenticate'] = [
      'completed' => $this->currentUser()->isAuthenticated(),
      'active' => !$this->currentUser()->isAuthenticated(),
      'content' => [
        'label' => [
          '#type' => 'link',
          '#title' => $this->t('Log in.'),
          '#url' => Url::fromRoute('user.login', [], [
            'query' => ['destination' => $current_url],
          ]),
          '#attributes' => [
            'class' => 'text-primary',
          ],
        ],
        'description' => [
          '#markup' => $this->t('You can log in with the credentials generated for you when this site was installed for the first time.'),
        ],
      ],
    ];
    $steps['configure'] = [
      'completed' => SourceDatabase::isConnected(),
      'active' => !SourceDatabase::isConnected(),
      'content' => [
        'label' => [
          '#type' => 'link',
          '#title' => $this->t('Configure your source database.'),
          '#url' => Url::fromUri('https://github.com/acquia/acquia_migrate#specifying-source-database-and-files'),
          '#attributes' => [
            'class' => 'text-primary',
            'target' => '_blank',
          ],
        ],
        'description' => [
          '#markup' => $this->t("Follow the link above for instructions on how to configure your source site's database in this site's <code>settings.php</code> file."),
        ],
      ],
    ];
    $steps['preselect'] = [
      'completed' => $this->migrationRepository->migrationsHaveBeenPreselected(),
      'active' => end($steps)['completed'] && !$this->migrationRepository->migrationsHaveBeenPreselected(),
      'content' => [
        'label' => [
          '#type' => 'link',
          '#title' => $this->t('Choose which data to import from your source site.'),
          '#url' => $preselect_url,
          '#attributes' => [
            'class' => 'text-primary',
          ],
        ],
        'description' => [
          '#markup' => '<em>Acquia Migrate: Accelerate</em> will automatically import all of your sources site\'s content types and fields. On this page, you\'ll be able to choose which parts of your source site that you want to migrate into your new Drupal 9 site. Don\'t worry, you can still choose to bring over anything later that you skip now.',
        ],
      ],
    ];
    $steps['import_content'] = [
      'completed' => FALSE,
      'active' => end($steps)['completed'],
      'content' => [
        'label' => [
          '#type' => 'link',
          '#title' => $this->t('Import your content.'),
          '#url' => $dashboard_url,
          '#attributes' => [
            'class' => 'text-primary',
          ],
        ],
        'description' => [
          '#markup' => $this->t("Once here, you'll begin the process of importing your source site's content. If you decide that you no longer want to import a migration that you selected in the previous step, you can mark it skipped. Migrations that have nothing left to import are marked as completed."),
        ],
      ],
    ];
    $unlink_inactive_labels = function (array $step) : array {
      if (!$step['active']) {
        $step['content']['label'] = [
          '#markup' => $step['content']['label']['#title'],
        ];
      }
      return $step;
    };
    $checklist = [
      '#type' => 'inline_template',
      '#template' => '<ol>{% for step in steps %}<li><h4>{% if step.completed %}<del>{% endif %}{{step.content.label}}{% if step.completed %}</del>{% endif %}</h4><p>{{step.content.description}}</p>{% endfor %}</ol>',
      '#context' => [
        'steps' => array_map($unlink_inactive_labels, $steps),
      ],
    ];
    $build = [
      '#type' => 'page',
      'content' => [
        '#type' => 'inline_template',
        '#template' => '<div class="d-flex line-items-center justify-content-center"><div class="card my-4"><div class="card-header"><h1 class="card-header-title">{{title}}</h1></div><div class="card-body">{{checklist}}</div></div>',
        '#context' => [
          'title' => $this->t('Welcome to <em>Acquia Migrate: Accelerate</em>'),
          'checklist' => $checklist,
        ],
      ],
      '#attached' => [
        'library' => [
          'acquia_migrate/styles',
        ],
      ],
    ];
    return $build;
  }

}
