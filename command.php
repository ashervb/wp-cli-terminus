<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

use WP_CLI\Utils;

function terminus_exec($cmd) {
  $exit = 0; $output = '';
  exec($cmd . " 2>&1", $output, $exit);
  return array('output' => $output, 'code' => $exit);
}

/**
 * Overwrites local database with copy from pantheon
 *
 * ## OPTIONS
 *
 * [--site=<site>]
 * : The name of the site to copy the database from. Required.
 * [--env=<env>]
 * : The environment to clone from. Defaults to 'env'
 * [--url=<url>]
 * : The url to replace in the database after import. Will be automatically derived from WP settings if omitted
 * [--no-backup]
 * : Don't create a new pantheon backup before downloading. Use this if the database hasn't changed
 * ---
 *
 * ## EXAMPLES
 *
 *     wp terminus db --site=my-pantheon-site
 *
 * @when before_wp_load
 */
function terminus_db_command($args, $opts) {
  $site     = isset($opts['site']) ? $opts['site'] : null;
  $env      = isset($opts['env']) ? $opts['env'] : "dev";
  $backup   = isset($opts['backup']) ? $opts['backup'] : true;
  $url      = isset($opts['url']) ? $opts['url'] : WP_CLI::runcommand('option get siteurl', array('return' => 'all'))->stdout;
  $now      = time();

  if (!$site) {
    WP_CLI::error("Please supply the site option `--site=NAME`");
    return;
  }

  $filename = "$site-db-$now.sql.gz";

  $progress = \WP_CLI\Utils\make_progress_bar( 'Overwriting local database with Pantheon', $backup ? 8 : 7 );

  $progress->tick();

  if ($backup) {
    $ret = terminus_exec("terminus site backups create --site=$site --env=$env --element=db");
    $progress->tick();
    if ($ret['code'] != 0) {
      WP_CLI::error($ret['output'][0]);
      return;
    }
  }

  $ret = terminus_exec("terminus site backups get --site=$site --env=$env --element=database --to=/tmp/$filename --latest");
  $progress->tick();

  if ($ret['code'] != 0) {
    WP_CLI::error($ret['output'][0]);
    return;
  }


  if (file_exists("/tmp/$filename")) {
    WP_CLI::launch_self('db reset --yes');
    $progress->tick();

    WP_CLI::launch("gunzip /tmp/$filename");
    WP_CLI::launch_self("db import /tmp/" . basename($filename, '.gz'));
    $progress->tick();

    WP_CLI::launch("rm /tmp/" . basename($filename, '.gz'));
    $progress->tick();

    WP_CLI::runcommand("search-replace '$env-$site.pantheonsite.io' '$url'");
    $progress->tick();

    $progress->finish();
    WP_CLI::success("Finished overwriting database. (•_•) ( •_•)>⌐■-■ (⌐■_■)");
  } else {
    WP_CLI::error("File $filename does not exist. Exiting");
  }

};
WP_CLI::add_command( 'terminus db', 'terminus_db_command' );

/**
 * Copies files from pantheon
 *
 * ## OPTIONS
 *
 * [--site=<site>]
 * : The name of the site to copy the database from. Required.
 * [--env=<env>]
 * : The environment to clone from. Defaults to 'env'
 * [--url=<url>]
 * : The url to replace in the database after import. Will be automatically derived from WP settings if omitted
 * [--no-backup]
 * : Don't create a new pantheon backup before downloading. Use this if the database hasn't changed
 * ---
 *
 * ## EXAMPLES
 *
 *     wp terminus files --site=my-pantheon-site
 *
 * @when before_wp_load
 */
function terminus_files_command($args, $opts) {
  $site     = isset($opts['site']) ? $opts['site'] : null;
  $env      = isset($opts['env']) ? $opts['env'] : "dev";
  $backup   = isset($opts['backup']) ? $opts['backup'] : true;
  $now      = time();

  if (!$site) {
    WP_CLI::error("Please supply the site option `--site=NAME`");
    return;
  }

  $filename = "$site-files-$now.tar.gz";

  $progress = \WP_CLI\Utils\make_progress_bar( 'Copying files from Pantheon', $backup ? 5 : 4 );

  $progress->tick();

  if ($backup) {
    terminus_exec("terminus site backups create --site=$site --env=$env --element=files");
    $progress->tick();
  }

  terminus_exec("terminus site backups get --site=$site --env=$env --element=files --to=/tmp/$filename --latest");
  $progress->tick();

  if (file_exists("/tmp/$filename")) {
    WP_CLI::launch(`gzip -dc "/tmp/$filename" | tar xf - -C /tmp`);
    $progress->tick();

    foreach (glob("/tmp/files_$env/*") as $f) {
      if (is_dir($f)) {
        WP_CLI::launch("yes | cp -r $f wp-content/uploads/");
      }
    }
    WP_CLI::launch("rm -rf /tmp/files_$env/");
    WP_CLI::launch("rm /tmp/$filename");
    $progress->finish();
    WP_CLI::success("Finished copying files. (•_•) ( •_•)>⌐■-■ (⌐■_■)");
  }
};
WP_CLI::add_command( 'terminus files', 'terminus_files_command' );
