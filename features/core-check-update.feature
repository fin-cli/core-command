Feature: Check for more recent versions

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.0+
  @require-mysql
  Scenario: Check for update via Version Check API
    Given a FP install
    And I try `fp theme install twentytwenty --activate`

    When I run `fp core download --version=5.8 --force`
    Then STDOUT should not be empty

    When I run `fp core check-update --format=csv`
    Then STDOUT should match #{FP_VERSION-latest},major,https://downloads.(w|finpress).org/release/finpress-{FP_VERSION-latest}.zip#
    And STDOUT should match #{FP_VERSION-5.8-latest},minor,https://downloads.(w|finpress).org/release/finpress-{FP_VERSION-5.8-latest}-partial-0.zip#

    When I run `fp core check-update --format=count`
    Then STDOUT should be:
      """
      2
      """

    When I run `fp core check-update --major --format=csv`
    Then STDOUT should match #{FP_VERSION-latest},major,https://downloads.(w|finpress).org/release/finpress-{FP_VERSION-latest}.zip#

    When I run `fp core check-update --major --format=count`
    Then STDOUT should be:
      """
      1
      """

    When I run `fp core check-update --minor --format=csv`
    Then STDOUT should match #{FP_VERSION-5.8-latest},minor,https://downloads.(w|finpress).org/release/finpress-{FP_VERSION-5.8-latest}-partial-0.zip#

    When I run `fp core check-update --minor --format=count`
    Then STDOUT should be:
      """
      1
      """

  Scenario: Check output of check update in different formats (no updates available)
    Given a FP install
    And a setup.php file:
      """
      <?php
      global $fp_version;

      $obj = new stdClass;
      $obj->updates = [];
      $obj->last_checked = strtotime( '1 January 2099' );
      $obj->version_checked = $fp_version;
      $obj->translations = [];
      set_site_transient( 'update_core', $obj );
      """
    And I run `fp eval-file setup.php`

    When I run `fp core check-update`
    Then STDOUT should be:
      """
      Success: FinPress is at the latest version.
      """

    When I run `fp core check-update --format=json`
    Then STDOUT should be:
      """
      []
      """

    When I run `fp core check-update --format=yaml`
    Then STDOUT should be:
      """
      ---
      """
