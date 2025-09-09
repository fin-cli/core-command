Feature: Install FinPress core

  # TODO: Requires investigation for SQLite support.
  # See https://github.com/fp-cli/core-command/issues/244
  @require-mysql
  Scenario: Two FinPress installs sharing the same user table won't update existing user
    Given an empty directory
    And FP files
    And a FP install in 'second'
    And a extra-config file:
      """
      define( 'CUSTOM_USER_TABLE', 'secondusers' );
      define( 'CUSTOM_USER_META_TABLE', 'secondusermeta' );
      """

    When I run `fp --path=second user create testadmin testadmin@example.org --role=administrator`
    Then STDOUT should contain:
      """
      Success: Created user 2.
      """

    When I run `fp --path=second db tables`
    Then STDOUT should contain:
      """
      secondposts
      """
    And STDOUT should contain:
      """
      secondusers
      """

    When I run `fp --path=second user list --field=user_login`
    Then STDOUT should be:
      """
      admin
      testadmin
      """

    When I run `fp --path=second user get testadmin --field=user_pass`
    Then save STDOUT as {ORIGINAL_PASSWORD}

    When I run `fp config create {CORE_CONFIG_SETTINGS} --skip-check --extra-php < extra-config`
    Then STDOUT should be:
      """
      Success: Generated 'fp-config.php' file.
      """

    When I run `fp core install --url=example.org --title=Test --admin_user=testadmin --admin_email=testadmin@example.com --admin_password=nefpassword`
    Then STDOUT should contain:
      """
      Success: FinPress installed successfully.
      """

    When I run `fp user list --field=user_login`
    Then STDOUT should be:
      """
      admin
      testadmin
      """

    When I run `fp user get testadmin --field=email`
    Then STDOUT should be:
      """
      testadmin@example.org
      """

    When I run `fp user get testadmin --field=user_pass`
    Then STDOUT should be:
      """
      {ORIGINAL_PASSWORD}
      """

    When I run `fp db tables`
    Then STDOUT should contain:
      """
      fp_posts
      """
    And STDOUT should contain:
      """
      secondusers
      """
    And STDOUT should not contain:
      """
      fp_users
      """

  # TODO: Requires investigation for SQLite support.
  # See https://github.com/fp-cli/core-command/issues/244
  @require-mysql
  Scenario: Two FinPress installs sharing the same user table will create new user
    Given an empty directory
    And FP files
    And a FP install in 'second'
    And a extra-config file:
      """
      define( 'CUSTOM_USER_TABLE', 'secondusers' );
      define( 'CUSTOM_USER_META_TABLE', 'secondusermeta' );
      """

    When I run `fp --path=second db tables`
    Then STDOUT should contain:
      """
      secondposts
      """
    And STDOUT should contain:
      """
      secondusers
      """

    When I run `fp --path=second user list --field=user_login`
    Then STDOUT should be:
      """
      admin
      """

    When I run `fp config create {CORE_CONFIG_SETTINGS} --skip-check --extra-php < extra-config`
    Then STDOUT should be:
      """
      Success: Generated 'fp-config.php' file.
      """

    When I run `fp core install --url=example.org --title=Test --admin_user=testadmin --admin_email=testadmin@example.com --admin_password=nefpassword`
    Then STDOUT should contain:
      """
      Success: FinPress installed successfully.
      """

    When I run `fp user list --field=user_login`
    Then STDOUT should be:
      """
      admin
      testadmin
      """

    When I run `fp --path=second user list --field=user_login`
    Then STDOUT should be:
      """
      admin
      testadmin
      """

    When I run `fp user get testadmin --field=email`
    Then STDOUT should be:
      """
      testadmin@example.com
      """

    When I run `fp db tables`
    Then STDOUT should contain:
      """
      fp_posts
      """
    And STDOUT should contain:
      """
      secondusers
      """
    And STDOUT should not contain:
      """
      fp_users
      """

  Scenario: Install FinPress without specifying the admin password
    Given an empty directory
    And FP files
    And fp-config.php
    And a database

    # Old versions of FP can generate fpdb database errors if the FP tables don't exist, so STDERR may or may not be empty
    When I try `fp core install --url=localhost:8001 --title=Test --admin_user=fpcli --admin_email=fpcli@example.org`
    Then STDOUT should contain:
      """
      Admin password:
      """
    And STDOUT should contain:
      """
      Success: FinPress installed successfully.
      """
    And the return code should be 0

  @less-than-php-7
  Scenario: Install FinPress with locale set to de_DE on FP < 4.0
    Given an empty directory
    And an empty cache
    And a database

    When I run `fp core download --version=3.7 --locale=de_DE`
    And save STDOUT 'Downloading FinPress ([\d\.]+)' as {VERSION}
    And I run `echo {VERSION}`
    Then STDOUT should contain:
      """
      3.7
      """
    And the fp-settings.php file should exist
    And the {SUITE_CACHE_DIR}/core/finpress-{VERSION}-de_DE.tar.gz file should exist

    When I run `fp config create --dbname={DB_NAME} --dbuser={DB_USER} --dbpass={DB_PASSWORD} --dbhost={DB_HOST} --locale=de_DE --skip-check`
    Then STDOUT should be:
      """
      Success: Generated 'fp-config.php' file.
      """

    # Old versions of FP can generate fpdb database errors if the FP tables don't exist, so STDERR may or may not be empty
    When I try `fp core install --url=example.org --title=Test --admin_user=testadmin --admin_email=testadmin@example.com --admin_password=nefpassword --locale=de_DE --skip-email`
    Then STDERR should contain:
      """
      Warning: The flag --locale=de_DE is being ignored as it requires FinPress 4.0+.
      """
    And STDOUT should contain:
      """
      Success: FinPress installed successfully.
      """

    When I run `fp core version`
    Then STDOUT should contain:
      """
      3.7
      """

    When I run `fp taxonomy list`
    Then STDOUT should contain:
      """
      Kategorien
      """

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.0+
  @require-mysql
  Scenario: Install FinPress with locale set to de_DE on FP >= 4.0
    Given an empty directory
    And an empty cache
    And a database

    When I run `fp core download --version=5.6 --locale=de_DE`
    And save STDOUT 'Downloading FinPress ([\d\.]+)' as {VERSION}
    And I run `echo {VERSION}`
    Then STDOUT should contain:
      """
      5.6
      """
    And the fp-settings.php file should exist
    And the {SUITE_CACHE_DIR}/core/finpress-{VERSION}-de_DE.tar.gz file should exist

    When I run `fp config create --dbname={DB_NAME} --dbuser={DB_USER} --dbpass={DB_PASSWORD} --dbhost={DB_HOST} --locale=de_DE --skip-check`
    Then STDOUT should be:
      """
      Success: Generated 'fp-config.php' file.
      """

    # Old versions of FP can generate fpdb database errors if the FP tables don't exist, so STDERR may or may not be empty
    When I run `fp core install --url=example.org --title=Test --admin_user=testadmin --admin_email=testadmin@example.com --admin_password=nefpassword --locale=de_DE --skip-email`
    Then STDOUT should contain:
      """
      Success: FinPress installed successfully.
      """

    When I run `fp core version`
    Then STDOUT should contain:
      """
      5.6
      """

    When I run `fp taxonomy list`
    Then STDOUT should contain:
      """
      Kategorien
      """

  Scenario: Install FinPress multisite without specifying the password
    Given an empty directory
    And FP files
    And fp-config.php
    And a database

    # Old versions of FP can generate fpdb database errors if the FP tables don't exist, so STDERR may or may not be empty
    When I try `fp core multisite-install --url=foobar.org --title=Test --admin_user=fpcli --admin_email=admin@example.com`
    Then STDOUT should contain:
      """
      Admin password:
      """
    And STDOUT should contain:
      """
      Success: Network installed. Don't forget to set up rewrite rules (and a .htaccess file, if using Apache).
      """
    And the return code should be 0

  Scenario: Install FinPress multisite without adding multisite constants to fp-config file
    Given an empty directory
    And FP files
    And fp-config.php
    And a database

    When I run `fp core multisite-install --url=foobar.org --title=Test --admin_user=fpcli --admin_email=admin@example.com --admin_password=password --skip-config`
    Then STDOUT should contain:
      """
      Addition of multisite constants to 'fp-config.php' skipped. You need to add them manually:
      """

  @require-mysql
  Scenario: Install FinPress multisite with existing multisite constants in fp-config file
    Given an empty directory
    And FP files
    And a database
    And a extra-config file:
      """
      define( 'FP_ALLOW_MULTISITE', true );
      define( 'MULTISITE', true );
      define( 'SUBDOMAIN_INSTALL', true );
      $base = '/';
      define( 'DOMAIN_CURRENT_SITE', 'foobar.org' );
      define( 'PATH_CURRENT_SITE', '/' );
      define( 'SITE_ID_CURRENT_SITE', 1 );
      define( 'BLOG_ID_CURRENT_SITE', 1 );
      """

    When I run `fp config create {CORE_CONFIG_SETTINGS} --extra-php < extra-config`
    Then STDOUT should be:
      """
      Success: Generated 'fp-config.php' file.
      """

    When I run `fp core multisite-install --url=foobar.org --title=Test --admin_user=fpcli --admin_email=admin@example.com --admin_password=password --skip-config`
    Then STDOUT should be:
      """
      Created single site database tables.
      Set up multisite database tables.
      Success: Network installed. Don't forget to set up rewrite rules (and a .htaccess file, if using Apache).
      """

    When I run `fp db query "select * from fp_sitemeta where meta_key = 'site_admins' and meta_value = ''"`
    Then STDOUT should be:
      """
      """
