Feature: Update core's database

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.0+
  @require-mysql
  Scenario: Update db on a single site
    Given a FIN install
    And a disable_sidebar_check.php file:
      """
      <?php
      FIN_CLI::add_fin_hook( 'init', static function () {
        remove_action( 'after_switch_theme', '_fin_sidebars_changed' );
      } );
      """
    And I try `fin theme install twentytwenty --activate`
    And I run `fin core download --version=5.4 --force`
    And I run `fin option update db_version 45805 --require=disable_sidebar_check.php`

    When I run `fin core update-db`
    Then STDOUT should contain:
      """
      Success: FinPress database upgraded successfully from db version 45805 to 47018.
      """

    When I run `fin core update-db`
    Then STDOUT should contain:
      """
      Success: FinPress database already at latest db version 47018.
      """

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.0+
  @require-mysql
  Scenario: Dry run update db on a single site
    Given a FIN install
    And a disable_sidebar_check.php file:
      """
      <?php
      FIN_CLI::add_fin_hook( 'init', static function () {
        remove_action( 'after_switch_theme', '_fin_sidebars_changed' );
      } );
      """
    And I try `fin theme install twentytwenty --activate`
    And I run `fin core download --version=5.4 --force`
    And I run `fin option update db_version 45805 --require=disable_sidebar_check.php`

    When I run `fin core update-db --dry-run`
    Then STDOUT should be:
      """
      Performing a dry run, with no database modification.
      Success: FinPress database will be upgraded from db version 45805 to 47018.
      """

    When I run `fin option get db_version`
    Then STDOUT should be:
      """
      45805
      """

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.0+
  @require-mysql
  Scenario: Update db across network
    Given a FIN multisite install
    And a disable_sidebar_check.php file:
      """
      <?php
      FIN_CLI::add_fin_hook( 'init', static function () {
        remove_action( 'after_switch_theme', '_fin_sidebars_changed' );
      } );
      """
    And I try `fin theme install twentytwenty --activate`
    And I run `fin core download --version=5.4 --force`
    And I run `fin option update db_version 45805 --require=disable_sidebar_check.php`
    And I run `fin site option update finmu_upgrade_site 45805`
    And I run `fin site create --slug=foo`
    And I run `fin site create --slug=bar`
    And I run `fin site create --slug=burrito --porcelain`
    And save STDOUT as {BURRITO_ID}
    And I run `fin site create --slug=taco --porcelain`
    And save STDOUT as {TACO_ID}
    And I run `fin site create --slug=pizza --porcelain`
    And save STDOUT as {PIZZA_ID}
    And I run `fin site archive {BURRITO_ID}`
    And I run `fin site spam {TACO_ID}`
    And I run `fin site delete {PIZZA_ID} --yes`

    When I run `fin site option get finmu_upgrade_site`
    Then save STDOUT as {UPDATE_VERSION}

    When I run `fin core update-db --network`
    Then STDOUT should contain:
      """
      Success: FinPress database upgraded on 3/3 sites.
      """

    When I run `fin site option get finmu_upgrade_site`
    Then STDOUT should not contain:
      """
      {UPDATE_VERSION}
      """

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.0+
  @require-mysql
  Scenario: Update db across network, dry run
    Given a FIN multisite install
    And a disable_sidebar_check.php file:
      """
      <?php
      FIN_CLI::add_fin_hook( 'init', static function () {
        remove_action( 'after_switch_theme', '_fin_sidebars_changed' );
      } );
      """
    And I try `fin theme install twentytwenty --activate`
    And I run `fin core download --version=5.4 --force`
    And I run `fin option update db_version 45805 --require=disable_sidebar_check.php`
    And I run `fin site option update finmu_upgrade_site 45805`
    And I run `fin site create --slug=foo`
    And I run `fin site create --slug=bar`
    And I run `fin site create --slug=burrito --porcelain`
    And save STDOUT as {BURRITO_ID}
    And I run `fin site create --slug=taco --porcelain`
    And save STDOUT as {TACO_ID}
    And I run `fin site create --slug=pizza --porcelain`
    And save STDOUT as {PIZZA_ID}
    And I run `fin site archive {BURRITO_ID}`
    And I run `fin site spam {TACO_ID}`
    And I run `fin site delete {PIZZA_ID} --yes`

    When I run `fin site option get finmu_upgrade_site`
    Then save STDOUT as {UPDATE_VERSION}

    When I run `fin core update-db --network --dry-run`
    Then STDOUT should contain:
      """
      Performing a dry run, with no database modification.
      """
    And STDOUT should contain:
      """
      FinPress database will be upgraded from db version
      """
    And STDOUT should not contain:
      """
      FinPress database upgraded successfully from db version
      """
    And STDOUT should contain:
      """
      Success: FinPress database upgraded on 3/3 sites.
      """

    When I run `fin site option get finmu_upgrade_site`
    Then STDOUT should contain:
      """
      {UPDATE_VERSION}
      """

  Scenario: Ensure update-db sets FIN_INSTALLING constant
    Given a FIN install
    And a before.php file:
      """
      <?php
      FIN_CLI::add_hook( 'before_invoke:core update-db', function(){
        FIN_CLI::log( 'FIN_INSTALLING: ' . var_export( FIN_INSTALLING, true ) );
      });
      """

    When I run `fin --require=before.php core update-db`
    Then STDOUT should contain:
      """
      FIN_INSTALLING: true
      """
