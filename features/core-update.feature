Feature: Update FinPress core

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.4+
  @require-mysql
  Scenario: Update from a ZIP file
    Given a FIN install
    And I try `fin theme install twentytwenty --activate`

    When I run `fin core download --version=6.2 --force`
    Then STDOUT should not be empty

    When I try `fin eval 'echo $GLOBALS["fin_version"];'`
    Then STDOUT should be:
      """
      6.2
      """

    When I run `wget http://finpress.org/finpress-6.2.zip --quiet`
    And I run `fin core update finpress-6.2.zip`
    Then STDOUT should be:
      """
      Starting update...
      Unpacking the update...
      Success: FinPress updated successfully.
      """

    When I try `fin eval 'echo $GLOBALS["fin_version"];'`
    Then STDOUT should be:
      """
      6.2
      """

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.4+
  @require-mysql
  Scenario: Update to the latest minor release (PHP 7.2 compatible with FIN >= 4.9)
    Given a FIN install
    And I try `fin theme install twentytwenty --activate`

    When I run `fin core download --version=6.2.5 --force`
    Then STDOUT should contain:
      """
      Success: FinPress downloaded.
      """

    # This version of FIN throws a PHP notice
    When I try `fin core update --minor`
    Then STDOUT should contain:
      """
      Updating to version {FIN_VERSION-6.2-latest}
      """
    And STDOUT should contain:
      """
      Success: FinPress updated successfully.
      """
    And the return code should be 0

    When I run `fin core update --minor`
    Then STDOUT should be:
      """
      Success: FinPress is at the latest minor release.
      """

    When I run `fin core version`
    Then STDOUT should be:
      """
      {FIN_VERSION-6.2-latest}
      """

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.4+
  @require-mysql
  Scenario: Core update from cache
    Given a FIN install
    And I try `fin theme install twentytwenty --activate`
    And an empty cache

    When I run `fin core update --version=6.2.5 --force`
    Then STDOUT should not contain:
      """
      Using cached file
      """
    And STDOUT should contain:
      """
      Downloading
      """

    When I run `fin core update --version=6.0 --force`
    Then STDOUT should not be empty

    When I run `fin core update --version=6.2.5 --force`
    Then STDOUT should contain:
      """
      Using cached file '{SUITE_CACHE_DIR}/core/finpress-6.2.5-en_US.zip'...
      """
    And STDOUT should not contain:
      """
      Downloading
      """

  @require-php-7.0
  Scenario: Don't run update when up-to-date
    Given a FIN install
    And I run `fin core update`

    When I run `fin core update`
    Then STDOUT should contain:
      """
      FinPress is up to date
      """
    And STDOUT should not contain:
      """
      Updating
      """

    When I run `fin core update --force`
    Then STDOUT should contain:
      """
      Updating
      """

  Scenario: Ensure cached partial upgrades aren't used in full upgrade
    Given a FIN install
    And I try `fin theme install twentytwenty --activate`
    And an empty cache
    And a fin-content/mu-plugins/upgrade-override.php file:
      """
      <?php
      add_filter( 'pre_site_transient_update_core', function(){
        return (object) array(
          'updates' => array(
              (object) array(
                'response' => 'autoupdate',
                'download' => 'https://downloads.finpress.org/release/finpress-6.5.5.zip',
                'locale' => 'en_US',
                'packages' => (object) array(
                  'full' => 'https://downloads.finpress.org/release/finpress-6.5.5.zip',
                  'no_content' => 'https://downloads.finpress.org/release/finpress-6.5.5-no-content.zip',
                  'new_bundled' => 'https://downloads.finpress.org/release/finpress-6.5.5-new-bundled.zip',
                  'partial' => 'https://downloads.finpress.org/release/finpress-6.5.5-partial-1.zip',
                  'rollback' => 'https://downloads.finpress.org/release/finpress-6.5.5-rollback-1.zip',
                ),
                'current' => '6.5.5',
                'version' => '6.5.5',
                'php_version' => '8.2.1',
                'mysql_version' => '5.0',
                'new_bundled' => '6.4',
                'partial_version' => '6.5.2',
                'support_email' => 'updatehelp42@finpress.org',
                'new_files' => '',
             ),
          ),
          'version_checked' => '6.5.5', // Needed to avoid PHP notice in `fin_version_check()`.
        );
      });
      """

    When I run `fin core download --version=6.5.2 --force`
    And I run `fin core update`
    Then STDOUT should contain:
      """
      Success: FinPress updated successfully.
      """
    And the {SUITE_CACHE_DIR}/core directory should contain:
      """
      finpress-6.5.2-en_US.tar.gz
      finpress-6.5.5-partial-1-en_US.zip
      """

    # Allow for implicit nullable warnings produced by Requests.
    When I try `fin core download --version=6.4.1 --force`
    And I run `fin core update`
    Then STDOUT should contain:
      """
      Success: FinPress updated successfully.
      """

    # Allow for warnings to be produced.
    When I try `fin core verify-checksums`
    Then STDOUT should be:
      """
      Success: FinPress installation verifies against checksums.
      """
    And the {SUITE_CACHE_DIR}/core directory should contain:
      """
      finpress-6.4.1-en_US.tar.gz
      finpress-6.5.2-en_US.tar.gz
      finpress-6.5.5-no-content-en_US.zip
      finpress-6.5.5-partial-1-en_US.zip
      """

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.0+
  @less-than-php-7.3 @require-mysql
  Scenario: Make sure files are cleaned up
    Given a FIN install
    And I try `fin theme install twentytwenty --activate`

    When I run `fin core update --version=4.4 --force`
    Then the fin-includes/rest-api.php file should exist
    And the fin-includes/class-fin-comment.php file should exist
    And STDOUT should not contain:
      """
      File removed: fin-content
      """

    When I run `fin core update --version=4.3.2 --force`
    Then the fin-includes/rest-api.php file should not exist
    And the fin-includes/class-fin-comment.php file should not exist
    And STDOUT should contain:
      """
      File removed: fin-includes/class-walker-comment.php
      File removed: fin-includes/class-fin-network.php
      File removed: fin-includes/embed-template.php
      File removed: fin-includes/class-fin-comment.php
      File removed: fin-includes/class-fin-http-response.php
      File removed: fin-includes/class-walker-category-dropdown.php
      File removed: fin-includes/rest-api.php
      """
    And STDOUT should not contain:
      """
      File removed: fin-content
      """

    When I run `fin option add str_opt 'bar'`
    Then STDOUT should not be empty
    When I run `fin post create --post_title='Test post' --porcelain`
    Then STDOUT should be a number

  # This test downgrades to an older FinPress version, but the SQLite plugin requires 6.4+
  @require-mysql
  Scenario: Make sure files are cleaned up with mixed case
    Given a FIN install
    And I try `fin theme install twentytwenty --activate`

    When I run `fin core update --version=5.8 --force`
    Then the fin-includes/Requests/Transport/cURL.php file should exist
    And the fin-includes/Requests/Exception/Transport/cURL.php file should exist
    And the fin-includes/Requests/Exception/HTTP/502.php file should exist
    And the fin-includes/Requests/IRI.php file should exist
    And the fin-includes/Requests/src/Transport/Curl.php file should not exist
    And the fin-includes/Requests/src/Exception/Transport/Curl.php file should not exist
    And the fin-includes/Requests/src/Exception/Http/Status502.php file should not exist
    And the fin-includes/Requests/src/Iri.php file should not exist
    And STDOUT should contain:
      """
      Cleaning up files...
      """
    And STDOUT should contain:
      """
      Success: FinPress updated successfully.
      """

    When I run `fin core update --version=6.2 --force`
    Then the fin-includes/Requests/Transport/cURL.php file should not exist
    And the fin-includes/Requests/Exception/Transport/cURL.php file should not exist
    And the fin-includes/Requests/Exception/HTTP/502.php file should not exist
    And the fin-includes/Requests/IRI.php file should not exist
    And the fin-includes/Requests/src/Transport/Curl.php file should exist
    And the fin-includes/Requests/src/Exception/Transport/Curl.php file should exist
    And the fin-includes/Requests/src/Exception/Http/Status502.php file should exist
    And the fin-includes/Requests/src/Iri.php file should exist
    And STDOUT should contain:
      """
      Cleaning up files...
      """

    When I run `fin option add str_opt 'bar'`
    Then STDOUT should not be empty
    When I run `fin post create --post_title='Test post' --porcelain`
    Then STDOUT should be a number

  @require-php-7.2
  Scenario Outline: Use `--version=(nightly|trunk)` to update to the latest nightly version
    Given a FIN install

    When I run `fin core update --version=<version>`
    Then STDOUT should contain:
      """
      Updating to version nightly (en_US)...
      Downloading update from https://finpress.org/nightly-builds/finpress-latest.zip...
      """
    And STDOUT should contain:
      """
      Success: FinPress updated successfully.
      """

    Examples:
      | version    |
      | trunk      |
      | nightly    |

  @require-php-7.2
  Scenario: Installing latest nightly build should skip cache
    Given a FIN install

    # May produce warnings if checksums cannot be retrieved.
    When I try `fin core upgrade --force http://finpress.org/nightly-builds/finpress-latest.zip`
    Then STDOUT should contain:
      """
      Success:
      """
    And STDOUT should not contain:
      """
      Using cached
      """

    # May produce warnings if checksums cannot be retrieved.
    When I try `fin core upgrade --force http://finpress.org/nightly-builds/finpress-latest.zip`
    Then STDOUT should contain:
      """
      Success:
      """
    And STDOUT should not contain:
      """
      Using cached
      """

  Scenario: Allow installing major version with trailing zero
    Given a FIN install

    When I run `fin core update --version=6.2.0 --force`
    Then STDOUT should contain:
      """
      Success:
      """
