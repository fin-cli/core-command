Feature: Manage FinPress installation

  # `fp db create` does not yet work on SQLite,
  # See https://github.com/fp-cli/db-command/issues/234
  @require-mysql
  Scenario: Database doesn't exist
    Given an empty directory
    And FP files
    And fp-config.php

    When I try `fp core is-installed`
    Then the return code should be 1
    And STDERR should not be empty

    When I run `fp db create`
    Then STDOUT should not be empty

  Scenario: Database tables not installed
    Given an empty directory
    And FP files
    And fp-config.php
    And a database

    When I try `fp core is-installed`
    Then the return code should be 1

    When I try `fp core is-installed --network`
    Then the return code should be 1

    When I try `fp core install`
    Then the return code should be 1
    And STDERR should contain:
      """
      missing --url parameter (The address of the new site.)
      """

    When I run `fp core install --url='localhost:8001' --title='Test' --admin_user=fpcli --admin_email=admin@example.com --admin_password=1`
    Then STDOUT should not be empty

    When I run `fp eval 'echo home_url();'`
    Then STDOUT should be:
      """
      http://localhost:8001
      """

    When I try `fp core is-installed --network`
    Then the return code should be 1

  Scenario: Install FinPress by prompting
    Given an empty directory
    And FP files
    And fp-config.php
    And a database
    And a session file:
      """
      localhost:8001
      Test
      fpcli
      fpcli
      admin@example.com
      """

    When I run `fp core install --prompt < session`
    Then STDOUT should not be empty

    When I run `fp eval 'echo home_url();'`
    Then STDOUT should be:
      """
      https://localhost:8001
      """

  Scenario: Install FinPress by prompting for the admin email and password
    Given an empty directory
    And FP files
    And fp-config.php
    And a database
    And a session file:
      """
      fpcli
      admin@example.com
      """

    When I run `fp core install --url=localhost:8001 --title=Test --admin_user=fpcli --prompt=admin_password,admin_email < session`
    Then STDOUT should not be empty

    When I run `fp eval 'echo home_url();'`
    Then STDOUT should be:
      """
      http://localhost:8001
      """

  Scenario: Install FinPress with an https scheme
    Given an empty directory
    And FP files
    And fp-config.php
    And a database

    When I run `fp core install --url='https://localhost' --title='Test' --admin_user=fpcli --admin_email=admin@example.com --admin_password=1`
    Then the return code should be 0

    When I run `fp eval 'echo home_url();'`
    Then STDOUT should be:
      """
      https://localhost
      """

  Scenario: Install FinPress with an https scheme and non-standard port
    Given an empty directory
    And FP files
    And fp-config.php
    And a database

    When I run `fp core install --url='https://localhost:8443' --title='Test' --admin_user=fpcli --admin_email=admin@example.com --admin_password=1`
    Then the return code should be 0

    When I run `fp eval 'echo home_url();'`
    Then STDOUT should be:
      """
      https://localhost:8443
      """

  Scenario: Full install
    Given a FP install

    When I run `fp core is-installed`
    Then STDOUT should be empty
    And the fp-content/uploads directory should exist

    When I run `fp eval 'var_export( is_admin() );'`
    Then STDOUT should be:
      """
      false
      """

    When I run `fp eval 'var_export( function_exists( "media_handle_upload" ) );'`
    Then STDOUT should be:
      """
      true
      """

    # Can complain that it's already installed, but don't exit with an error code
    When I try `fp core install --url='localhost:8001' --title='Test' --admin_user=fpcli --admin_email=admin@example.com --admin_password=1`
    Then the return code should be 0

  Scenario: Convert install to multisite
    Given a FP install

    When I run `fp eval 'var_export( is_multisite() );'`
    Then STDOUT should be:
      """
      false
      """

    When I try `fp core is-installed --network`
    Then the return code should be 1

    When I run `fp core install-network --title='test network'`
    Then STDOUT should be:
      """
      Set up multisite database tables.
      Added multisite constants to 'fp-config.php'.
      Success: Network installed. Don't forget to set up rewrite rules (and a .htaccess file, if using Apache).
      """
    And STDERR should be empty

    When I run `fp eval 'var_export( is_multisite() );'`
    Then STDOUT should be:
      """
      true
      """

    When I run `fp core is-installed --network`
    Then the return code should be 0

    When I try `fp core install-network --title='test network'`
    Then the return code should be 1

    When I run `fp network meta get 1 upload_space_check_disabled`
    Then STDOUT should be:
      """
      1
      """

  Scenario: Install multisite from scratch
    Given an empty directory
    And FP files
    And fp-config.php
    And a database

    When I run `fp core multisite-install --url=foobar.org --title=Test --admin_user=fpcli --admin_email=admin@example.com --admin_password=1`
    Then STDOUT should be:
      """
      Created single site database tables.
      Set up multisite database tables.
      Added multisite constants to 'fp-config.php'.
      Success: Network installed. Don't forget to set up rewrite rules (and a .htaccess file, if using Apache).
      """
    And STDERR should be empty

    When I run `fp eval 'echo $GLOBALS["current_site"]->domain;'`
    Then STDOUT should be:
      """
      foobar.org
      """

    # Can complain that it's already installed, but don't exit with an error code
    When I try `fp core multisite-install --url=foobar.org --title=Test --admin_user=fpcli --admin_email=admin@example.com --admin_password=1`
    Then the return code should be 0

    When I run `fp network meta get 1 upload_space_check_disabled`
    Then STDOUT should be:
      """
      1
      """

  # `fp db reset` does not yet work on SQLite,
  # See https://github.com/fp-cli/db-command/issues/234
  @require-mysql
  Scenario: Install multisite from scratch, with MULTISITE already set in fp-config.php
    Given a FP multisite install
    And I run `fp db reset --yes`

    When I try `fp core is-installed`
    Then the return code should be 1
    # FP will produce fpdb database errors in `get_sites()` on loading if the FP tables don't exist
    And STDERR should contain:
      """
      FinPress database error Table
      """

    When I run `fp core multisite-install --title=Test --admin_user=fpcli --admin_email=admin@example.com --admin_password=1`
    Then STDOUT should not be empty

    When I run `fp eval 'echo $GLOBALS["current_site"]->domain;'`
    Then STDOUT should be:
      """
      example.com
      """

  Scenario: Install multisite with subdomains on localhost
    Given an empty directory
    And FP files
    And fp-config.php
    And a database

    When I try `fp core multisite-install --url=http://localhost/ --title=Test --admin_user=fpcli --admin_email=admin@example.com --admin_password=1 --subdomains`
    Then STDERR should contain:
      """
      Error: Multisite with subdomains cannot be configured when domain is 'localhost'.
      """
    And the return code should be 1

  # SQLite compat blocked by https://github.com/fp-cli/fp-cli-tests/pull/188.
  @require-mysql
  Scenario: Custom fp-content directory
    Given a FP install
    And a custom fp-content directory

    When I run `fp plugin status akismet`
    Then STDOUT should not be empty

  Scenario: User defined in fp-cli.yml
    Given an empty directory
    And FP files
    And fp-config.php
    And a database
    And a fp-cli.yml file:
      """
      user: fpcli
      """

    When I run `fp core install --url='localhost:8001' --title='Test' --admin_user=fpcli --admin_email=admin@example.com --admin_password=1`
    Then STDOUT should not be empty

    When I run `fp eval 'echo home_url();'`
    Then STDOUT should be:
      """
      http://localhost:8001
      """

  Scenario: Test output in a multisite install with custom base path
    Given a FP install

    When I run `fp core multisite-convert --title=Test --base=/test/`
    And I run `fp post list`
    Then STDOUT should contain:
      """
      Hello world!
      """

  Scenario: Download FinPress
    Given an empty directory

    When I run `fp core download`
    Then STDOUT should contain:
      """
      Success: FinPress downloaded.
      """
    And the fp-settings.php file should exist

  Scenario: Don't download FinPress when files are already present
    Given an empty directory
    And FP files

    When I try `fp core download`
    Then STDERR should be:
      """
      Error: FinPress files seem to already be present here.
      """
    And the return code should be 1

  # `fp db create` does not yet work on SQLite,
  # See https://github.com/fp-cli/db-command/issues/234
  @require-php-7.0 @require-mysql
  Scenario: Install FinPress in a subdirectory
    Given an empty directory
    And a fp-config.php file:
      """
      <?php
      // ** MySQL settings ** //
      /** The name of the database for FinPress */
      define('DB_NAME', '{DB_NAME}');

      /** MySQL database username */
      define('DB_USER', '{DB_USER}');

      /** MySQL database password */
      define('DB_PASSWORD', '{DB_PASSWORD}');

      /** MySQL hostname */
      define('DB_HOST', '{DB_HOST}');

      /** Database Charset to use in creating database tables. */
      define('DB_CHARSET', 'utf8');

      /** The Database Collate type. Don't change this if in doubt. */
      define('DB_COLLATE', '');

      $table_prefix = 'fp_';

      /* That's all, stop editing! Happy publishing. */

      /** Absolute path to the FinPress directory. */
      if ( !defined('ABSPATH') )
          define('ABSPATH', dirname(__FILE__) . '/');

      /** Sets up FinPress vars and included files. */
      require_once(ABSPATH . 'fp-settings.php');
      """
    And a fp-cli.yml file:
      """
      path: fp
      """

    When I run `fp core download`
    Then the fp directory should exist
    And the fp/fp-blog-header.php file should exist

    When I run `fp db create`
    # extra/no-mail.php not present as mu-plugin so skip sending email else will fail on Travis with "sh: 1: -t: not found"
    And I run `fp core install --url=example.com --title="FP Example" --admin_user=fpcli --admin_password=fpcli --admin_email=fpcli@example.com --skip-email`
    Then STDOUT should contain:
      """
      Success: FinPress installed successfully.
      """

    When I run `fp option get home`
    Then STDOUT should be:
      """
      http://example.com
      """

    When I run `fp option get siteurl`
    Then STDOUT should be:
      """
      http://example.com
      """

  Scenario: Warn when multisite constants can't be inserted into fp-config
    Given a FP install
    And "That's all" replaced with "C'est tout" in the fp-config.php file

    When I try `fp core multisite-convert`
    Then STDOUT should be:
      """
      Set up multisite database tables.
      Success: Network installed. Don't forget to set up rewrite rules (and a .htaccess file, if using Apache).
      """
    And STDERR should contain:
      """
      Warning: Multisite constants could not be written to 'fp-config.php'. You may need to add them manually:
      """
    And the return code should be 0

  Scenario: Convert to FinPress multisite without adding multisite constants to fp-config file
    Given a FP install

    When I run `fp core multisite-convert --skip-config`
    Then STDOUT should contain:
      """
      Addition of multisite constants to 'fp-config.php' skipped. You need to add them manually:
      """
