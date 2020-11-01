<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate;

use Acquia\DrupalEnvironmentDetector\AcquiaDrupalEnvironmentDetector;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Prevents acquia_migrate module from being uninstalled while on an AM:A env.
 */
class UninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function validate($module) {
    $reasons = [];
    if ($module === 'acquia_migrate') {
      if (AcquiaDrupalEnvironmentDetector::isAhEnv() && !AcquiaDrupalEnvironmentDetector::isAhStageEnv() && !AcquiaDrupalEnvironmentDetector::isAhDevEnv() && !AcquiaDrupalEnvironmentDetector::isAhProdEnv()) {
        $reasons[] = $this->t('To uninstall the Acquia Migrate: Accelerate module, you must first promote it from the Migrate environment to the Stage or Dev environment.');
      }
    }
    return $reasons;
  }

}
