<?php

/**
 * @file
 * Contains \DrupalProject\composer\ScriptHandler.
 */

namespace DrupalProject\composer;

use Composer\Script\Event;
use Composer\Semver\Comparator;
use Drupal\Core\Site\Settings;
use DrupalFinder\DrupalFinder;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\PathUtil\Path;

class ScriptHandler {

  public static function createRequiredFiles(Event $event) {
    $fs = new Filesystem();
    $drupalFinder = new DrupalFinder();
    $drupalFinder->locateRoot(getcwd());
    $drupalRoot = $drupalFinder->getDrupalRoot();

    $dirs = [
      'modules',
      'profiles',
      'themes',
      'libraries',
    ];

    // Required for unit testing
    foreach ($dirs as $dir) {
      if (!$fs->exists($drupalRoot . '/'. $dir)) {
        $fs->mkdir($drupalRoot . '/'. $dir);
      }
      $fs->touch($drupalRoot . '/'. $dir . '/.gitkeep');
    }

    // Prepare the settings file for installation
    $settings_file = $drupalRoot . '/sites/default/settings.php';
    $default_settings_file = $drupalRoot . '/sites/default/default.settings.php';
    if (!$fs->exists($settings_file) && $fs->exists($default_settings_file)) {
      $fs->copy($default_settings_file, $settings_file);
      $content = file_get_contents($settings_file);
      
      // Add database connection.
      $database_connection = <<<EOT
\$databases['default']['default'] = [
  'driver' => getenv('DB_DRIVER'),
  'host' => getenv('DB_HOST'),
  'port' => getenv('DB_PORT'),
  'database' => getenv('DB_NAME'),
  'username' => getenv('DB_USER'),
  'password' => getenv('DB_PASSWORD'),
  'prefix' => getenv('DB_PREFIX'),
  'collation' => getenv('DB_COLLATION'),
];
EOT;
      $content = str_replace('$databases = [];', $database_connection, $content);

      // Uncomment settings variables.
      $commented_settings = [
        'config_sync_directory',
        'file_public_path',
        'file_private_path',
        'file_temp_path',
      ];
      foreach ($commented_settings as $commented_setting) {
        $content = preg_replace('/(^\#\s)(\$settings\[\'' . $commented_setting . '\'\])/m', '$2', $content);
      }

      // Uncomment settings.local.php include.
      $content = preg_replace('/(^\#\n)?(^\#\s)(.*settings\.local\.php.*\n)(^\#\s)(.*\n)(^\#\s)(\})/m', '$3$5$7', $content);

      // Replace hash salt with getenv() function.
      $content = preg_replace('/(^\$settings\[\'hash_salt\'\])(\s=\s\'\')/m', '$1 = getenv(\'DRUPAL_HASH_SALT\')', $content);

      if (file_put_contents($settings_file, $content) === FALSE) {
        $event->getIO()->writeError('<error>Failed to modify ' . $settings_file . ' file. Verify the file permissions.</error>.');
        exit(1);
      }

      require_once $drupalRoot . '/core/includes/bootstrap.inc';
      require_once $drupalRoot . '/core/includes/install.inc';
      new Settings([]);
      $settings['settings']['config_sync_directory'] = (object) [
        'value' => Path::makeRelative($drupalFinder->getComposerRoot() . '/config/sync', $drupalRoot),
        'required' => TRUE,
      ];
      $settings['settings']['file_public_path'] = (object) [
        'value' => Path::makeRelative($drupalFinder->getComposerRoot() . '/sites/default/files/', $drupalRoot),
        'required' => TRUE,
      ];
      $settings['settings']['file_private_path'] = (object) [
        'value' => Path::makeRelative($drupalFinder->getComposerRoot() . '/files', $drupalRoot),
        'required' => TRUE,
      ];
      $settings['settings']['hash_salt'] = (object) [
        'value' => "FUNC[getenv('DRUPAL_HASH_SALT')]",
        'required' => TRUE,
      ];

      drupal_rewrite_settings($settings, $drupalRoot . '/sites/default/settings.php');
      $fs->chmod($drupalRoot . '/sites/default/settings.php', 0666);
      $fs->remove($default_settings_file);
      $event->getIO()->write("Created a sites/default/settings.php file with chmod 0666");
    }

    // Prepare the settings file for installation
    $services_file = $drupalRoot . '/sites/default/services.yml';
    $default_services_file = $drupalRoot . '/sites/default/default.services.yml';
    if (!$fs->exists($services_file) && $fs->exists($default_services_file)) {
      $fs->copy($default_services_file, $services_file);
      $fs->remove($default_services_file);
    }

    // Create the public files directory with chmod 0777
    if (!$fs->exists($drupalRoot . '/sites/default/files')) {
      $oldmask = umask(0);
      $fs->mkdir($drupalRoot . '/sites/default/files', 0777);
      umask($oldmask);
      $event->getIO()->write("Created a sites/default/files directory with chmod 0777");
    }

    // Create the files directory with chmod 0777
    if (!$fs->exists($drupalRoot . '/../files')) {
      $oldmask = umask(0);
      $fs->mkdir($drupalRoot . '/../files', 0777);
      umask($oldmask);
      $event->getIO()->write("Created a ../files directory with chmod 0777");
    }
  }

  /**
   * Checks if the installed version of Composer is compatible.
   *
   * Composer 1.0.0 and higher consider a `composer install` without having a
   * lock file present as equal to `composer update`. We do not ship with a lock
   * file to avoid merge conflicts downstream, meaning that if a project is
   * installed with an older version of Composer the scaffolding of Drupal will
   * not be triggered. We check this here instead of in drupal-scaffold to be
   * able to give immediate feedback to the end user, rather than failing the
   * installation after going through the lengthy process of compiling and
   * downloading the Composer dependencies.
   *
   * @see https://github.com/composer/composer/pull/5035
   */
  public static function checkComposerVersion(Event $event) {
    $composer = $event->getComposer();
    $io = $event->getIO();

    $version = $composer::VERSION;

    // The dev-channel of composer uses the git revision as version number,
    // try to the branch alias instead.
    if (preg_match('/^[0-9a-f]{40}$/i', $version)) {
      $version = $composer::BRANCH_ALIAS_VERSION;
    }

    // If Composer is installed through git we have no easy way to determine if
    // it is new enough, just display a warning.
    if ($version === '@package_version@' || $version === '@package_branch_alias_version@') {
      $io->writeError('<warning>You are running a development version of Composer. If you experience problems, please update Composer to the latest stable version.</warning>');
    }
    elseif (Comparator::lessThan($version, '1.0.0')) {
      $io->writeError('<error>Drupal-project requires Composer version 1.0.0 or higher. Please update your Composer before continuing</error>.');
      exit(1);
    }
  }

}
