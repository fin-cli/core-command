Feature: Check for more recent versions

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.0+
  @require-mysql
  Scenario: Check for update via Version Check API
    Given a FIN install
    And I try `fin theme install twentytwenty --activate`

    When I run `fin core download --version=5.8 --force`
    Then STDOUT should not be empty

    When I run `fin core check-update --format=csv`
    Then STDOUT should match #{FIN_VERSION-latest},major,https://downloads.(w|finpress).org/release/finpress-{FIN_VERSION-latest}.zip#
    And STDOUT should match #{FIN_VERSION-5.8-latest},minor,https://downloads.(w|finpress).org/release/finpress-{FIN_VERSION-5.8-latest}-partial-0.zip#

    When I run `fin core check-update --format=count`
    Then STDOUT should be:
      """
      2
      """

    When I run `fin core check-update --major --format=csv`
    Then STDOUT should match #{FIN_VERSION-latest},major,https://downloads.(w|finpress).org/release/finpress-{FIN_VERSION-latest}.zip#

    When I run `fin core check-update --major --format=count`
    Then STDOUT should be:
      """
      1
      """

    When I run `fin core check-update --minor --format=csv`
    Then STDOUT should match #{FIN_VERSION-5.8-latest},minor,https://downloads.(w|finpress).org/release/finpress-{FIN_VERSION-5.8-latest}-partial-0.zip#

    When I run `fin core check-update --minor --format=count`
    Then STDOUT should be:
      """
      1
      """

  Scenario: Check output of check update in different formats (no updates available)
    Given a FIN install
    And a setup.php file:
      """
      <?php
      global $fin_version;

      $obj = new stdClass;
      $obj->updates = [];
      $obj->last_checked = strtotime( '1 January 2099' );
      $obj->version_checked = $fin_version;
      $obj->translations = [];
      set_site_transient( 'update_core', $obj );
      """
    And I run `fin eval-file setup.php`

    When I run `fin core check-update`
    Then STDOUT should be:
      """
      Success: FinPress is at the latest version.
      """

    When I run `fin core check-update --format=json`
    Then STDOUT should be:
      """
      []
      """

    When I run `fin core check-update --format=yaml`
    Then STDOUT should be:
      """
      ---
      """
