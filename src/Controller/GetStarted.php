<?php

namespace Drupal\acquia_migrate\Controller;

use Drupal\acquia_migrate\MigrationRepository;
use Drupal\acquia_migrate\SourceDatabase;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Site\Settings;
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
          '#url' => Url::fromUri('https://packagist.org/packages/acquia/acquia-migrate-accelerate#user-content-specifying-source-database-and-files'),
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
    $source_public_files_path = Settings::get('migrate_source_base_path');
    $source_private_files_path = Settings::get('migrate_source_private_file_path');
    $files_configured = !is_null($source_public_files_path) && file_exists($source_public_files_path) && (is_null($source_private_files_path) || file_exists($source_private_files_path));
    $steps['configure-files'] = [
      'completed' => $files_configured,
      'active' => !$files_configured,
      'content' => [
        'label' => [
          '#type' => 'link',
          '#title' => $this->t('Configure your files directory.'),
          '#url' => Url::fromUri('https://packagist.org/packages/acquia/acquia-migrate-accelerate#user-content-specifying-source-database-and-files'),
          '#attributes' => [
            'class' => 'text-primary',
            'target' => '_blank',
          ],
        ],
        'description' => [
          '#markup' => $this->t("Follow the link above for instructions on how to configure your source site's public files directory in this site's <code>settings.php</code> file. (And optionally the private files directory.)"),
        ],
      ],
    ];

    $expected_file_public_path = !SourceDatabase::isConnected() ? FALSE : unserialize(SourceDatabase::getConnection()
      ->select('variable')
      ->fields(NULL, ['value'])
      ->condition('name', 'file_public_path', 'LIKE')
      ->execute()
      ->fetchField());
    if ($expected_file_public_path && $expected_file_public_path !== 'sites/default/files') {
      $expected_file_public_path_exists = $expected_file_public_path !== FALSE && $files_configured && file_exists($expected_file_public_path) && is_writable($expected_file_public_path);
      $steps['create-destination-files-directory'] = [
        'completed' => $expected_file_public_path_exists,
        'active' => !$expected_file_public_path_exists,
        'content' => [
          'label' => $this->t('Create matching files directory'),
          'description' => [
            '#markup' => $expected_file_public_path_exists
            ? $this->t("The source site uses a non-default directory for serving publicly accessible files. <code>@absolute-path</code> exists, and is writable.", ['@absolute-path' => getcwd() . '/' . $expected_file_public_path])
            : $this->t("The source site uses a non-default directory for serving publicly accessible files. Ensure the <code>@absolute-path</code> directory exists, and is writable.", ['@absolute-path' => getcwd() . '/' . $expected_file_public_path]),
          ],
        ],
      ];
    }

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
      if (!$step['active'] && is_array($step['content']['label']) && $step['content']['label']['#type'] === 'link') {
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
      '#template' => 'page',
      '#title' => $this->t('Welcome to <em>Acquia Migrate: Accelerate</em>'),
      'content' => [
        '#type' => 'inline_template',
        '#template' => '{{checklist}}',
        '#context' => [
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

  /**
   * Return a page render array.
   *
   * @return array
   *   A render array.
   */
  public function acquiaSiteStudio() {
    // @codingStandardsIgnoreStart
    // @todo https://backlog.acquia.com/browse/OCTO-3674 — instrument with Amplitude: track how much time is spent reading this page, video plays and sandbox button clicks
    $build = [
      '#markup' => Markup::create(<<<HTML
<style>
#acquia-site-studio .hero {
    display: flex;
    margin-bottom: 1.5rem;
}
/* Match margins for Claro's .page-content. */
@media screen and (min-width: 38em) {
  #acquia-site-studio .hero {
    margin-bottom: 2rem;
  }
}
#acquia-site-studio .hero iframe {
    max-width: 560px;
    max-height: 315px;
}
#acquia-site-studio .hero article {
    flex: 1;
    margin: 0 3em;
}
#acquia-site-studio .hero article h2 {
    margin-top: 0;
}
#acquia-site-studio .resources {
    text-align: center;
}
#acquia-site-studio .resources .resource-cards {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  grid-auto-rows: minmax(100px, auto);
  padding: 0 2em;
}
#acquia-site-studio .resources .resource-cards .resource-card {
    box-shadow: 0px 3px 10px grey;
    margin: 1em;
    padding: 1em;
    text-align: left;
    color: inherit;
    text-decoration: none;
}
#acquia-site-studio .resources .resource-cards .resource-card:hover {
    box-shadow: 0px 3px 10px black;
}
#acquia-site-studio .resources .resource-cards .resource-card img {
    box-shadow: 0px 3px 5px grey;
    transform: scale(0.95);
    transition: all .2s ease-in-out;
    display: block;
    margin-left: auto;
    margin-right: auto;
}
#acquia-site-studio .resources .resource-cards .resource-card img.transparent {
    box-shadow: none;
}
#acquia-site-studio .resources .resource-cards .resource-card:hover img {
  transform: scale(1.05);
}
@media screen and (min-width: 85.375rem) {
    #acquia-site-studio .resources .resource-cards .resource-card {
        display: flex;
    }
    #acquia-site-studio .resources .resource-cards .resource-card img {
        align-self: center;
        flex-grow: unset;
        max-width: 30%;
        margin-left: 0;
        margin-right: 1em;
    }
}

#acquia-site-studio .resources .resource-cards .resource-card article h3 {
    font-size: 1.2rem;
}
#acquia-site-studio .resources .resource-cards .resource-card article p {
    color: gray
}
#acquia-site-studio .resources .resource-cards .resource-card article .read-more {
    color: #767676;
    font-weight: bold;
    float: right;
}
#acquia-site-studio .resources .resource-cards .resource-card:hover article .read-more {
    text-decoration: underline;
}
#acquia-site-studio .resources .resource-cards .resource-card article .read-more::after {
    content: ' ›'
}
</style>
<div id="acquia-site-studio">
    <div class="hero">
        <iframe width="560" height="315" src="https://www.youtube.com/embed/gHZfN014W8I" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
        <article>
            <h2>Accelerated appearance using Site Studio!</h2>
            <p><a href="https://www.acquia.com/products/drupal-cloud/site-studio">Acquia Site Studio</a> is a low-code solution for building
            and editing Drupal sites. It dramatically improves the process for developers, designers and marketers. Developers create components
            and make them accessible to non-technical users in a drag and drop interface. Designers and marketers can quickly use those components
            to create or modify pages — without touching any code.</p>
            <p>When migrating from Drupal 7 to Drupal 9, a new theme must be created by front-end developers anyway, so why not use this opportunity
            to empower your marketing team to build better-looking landing pages faster than ever before?</p>
            <p>Best of all: this is <strong>already included</strong> in your Acquia Cloud subscription 🚀 Reach out to your account manager to learn more.</p>
            <!-- @todo Point to -->
            <a href="https://www.acquia.com/products/drupal-cloud/site-studio" class="button button--primary">Try the Site Studio Sandbox!</a>
        </article>
    </div>
    <!-- Use Claro's .content-header, for consistent styling. -->
    <div class="resources content-header">
        <h2>Check out these resources!</h2>
        <div class="resource-cards">
          <a class="resource-card" href="https://www.acquia.com/resources/ebooks/ultimate-guide-acquia-site-studio?utm_source=acquiamigrate-accelerate&utm_medium=referral&utm_campaign=WS_WW_AcquiaMigrate-Accelerate&utm_term=link_ebook_ultimate_guide">
            <img src="https://www.acquia.com/sites/acquia.com/files/styles/medium/public/images/2019-11/GettyImages-1138450928_0.jpeg?itok=sxcQ3LWY" width="220" height="147">
            <article>
              <h3>E-book: The Ultimate Guide to Acquia Site Studio</h3>
              <p>Building enterprise-grade websites with the functionality, scalability and branding you require often requires a lot of time, money and highly skilled people. On the other hand, consumer site-building tools let people with little technical acumen build impressive websites fairly easily, but often the sites are off-brand, unable to scale and unendorsed by IT teams.</p>
              <span class="read-more">Read more</span>
            </article>
          </a>
          <a class="resource-card" href="https://www.acquia.com/resources/ebooks/component-based-design-acquia-site-studio?utm_source=acquiamigrate-accelerate&utm_medium=referral&utm_campaign=WS_WW_AcquiaMigrate-Accelerate&utm_term=link_ebook_component_based_design">
            <img src="https://www.acquia.com/sites/acquia.com/files/styles/medium/public/images/2019-10/gettyimages-1028691020.jpg?itok=7UHJ74C-" width="220" height="152">
            <article>
              <h3>E-book: Component-Based Design with Acquia Site Studio</h3>
              <p>Component-based design systems are now considered by designers, developers, product owners and project managers as the most effective way to develop and manage design, especially at scale. Rather than designing page by page, you break a design down into smaller component parts. Individually these can be quite simple, but when combined together they create something more meaningful and useful.
<br>
At the heart of component design is the philosophy: Create once, use many.</p>
              <span class="read-more">Read more</span>
            </article>
          </a>
          <a class="resource-card" href="https://www.acquia.com/blog/create-component-based-design-system-drupal-acquia-site-studio?utm_source=acquiamigrate-accelerate&utm_medium=referral&utm_campaign=WS_WW_AcquiaMigrate-Accelerate&utm_term=link_blog_component_based_design">
            <img src="https://www.acquia.com/sites/acquia.com/files/styles/medium/public/images/2019-09/editingform.jpg?itok=AUP2Evhv" width="168" height="220">
            <article>
              <h3>Blog: Create a Component-based Design System in Drupal with Acquia Site Studio</h3>
              <p>Component-based design systems have evolved quickly over the past few years. They’re now considered by designers, developers, product owners and project managers as the most effective way to develop and manage design, especially at scale. In this article, I’m going to show you how a component-based design system can be implemented within Drupal using Acquia Site Studio.</p>
              <span class="read-more">Read more</span>
            </article>
          </a>
          <a class="resource-card" href="https://www.acquia.com/blog/how-overcome-site-sprawl-and-build-brand-compliant-sites-scale?utm_source=acquiamigrate-accelerate&utm_medium=referral&utm_campaign=WS_WW_AcquiaMigrate-Accelerate&utm_term=link_blog_overcome_sprawl">
            <img src="https://www.acquia.com/sites/acquia.com/files/styles/medium/public/images/2020-05/webscale.jpg?itok=MGINNJKT" width="220" height="147">
            <article>
              <h3>Blog: How to Overcome Site Sprawl and Build Brand-Compliant Sites At Scale</h3>
              <p>Before I joined Acquia’s emerging products team, I worked on our Professional Services team, leading engagements with some of our largest customers building out massive web platforms on Acquia Site Factory. These are predominantly enterprise customers that are building and managing many websites — upwards of 200 on average — and they need to do this quickly, consistently, and cost-efficiently.</p>
              <span class="read-more">Read more</span>
            </article>
          </a>
          <a class="resource-card" href="https://sitestudiodocs.acquia.com/6.3/user-guide?utm_source=acquiamigrate-accelerate&utm_medium=referral&utm_campaign=WS_WW_AcquiaMigrate-Accelerate&utm_term=link_docs_user_guide">
            <img class="transparent" src="https://sitestudiodocs.acquia.com/sites/default/files/site-studio-images/graphics/site-studio-documentation.png" width="220">
            <article>
              <h3>Documentation: User Guide</h3>
              <p>Welcome to the Acquia Site Studio user guide. The pages within this provide information about Acquia Site Studio functionality, including step-by-step instructions and details of each function.</p>
              <span class="read-more">Read more</span>
            </article>
          </a>
          <a class="resource-card" href="https://customers.acquiaacademy.com/learn/public/learning_plan/view/30/acquia-site-studio-site-builder-6x-onboarding?utm_source=acquiamigrate-accelerate&utm_medium=referral&utm_campaign=WS_WW_AcquiaMigrate-Accelerate&utm_term=link_academy">
            <img class="transparent" src="https://sitestudiodocs.acquia.com/sites/default/files/site-studio-images/graphics/site-studio-documentation.png" width="220">
            <article>
              <h3>Academy: Acquia Site Studio 6.x Onboarding</h3>
              <p>This learning plan includes everything you need to know to get up and running using Acquia  Site Studio 6.x within your organization.</p>
              <span class="read-more">Read more</span>
            </article>
          </a>
          <a class="resource-card" href="https://www.acquia.com/blog/migrating-drupal-8-acquia-site-studio-your-secret-weapon?utm_source=acquiamigrate-accelerate&utm_medium=referral&utm_campaign=WS_WW_AcquiaMigrate-Accelerate&utm_term=link_blog_migration_secret_weapon">
            <img src="https://www.acquia.com/sites/acquia.com/files/styles/medium/public/images/2020-03/muscle.jpg?itok=nAJ9uDop" width="220" height="147">
            <article>
              <h3>Blog: Migrating to Drupal 8? Acquia Site Studio is Your Secret Weapon</h3>
              <p>Migrating to Drupal 8 can feel like an enormous task for any organization, even those with skilled developers and leaders who understand technology. At Acquia, we understand the time and resources required to upgrade will change your day-to-day operations and limit your ability to focus on revenue-driving tasks.</p>
              <span class="read-more">Read more</span>
            </article>
          </a>
        </div>
        <!-- <a href="#" class="button button--primary">View all</a> -->
    </div>
</div>
HTML)
    ];
    return $build;
    // @codingStandardsIgnoreEnd
  }

}
