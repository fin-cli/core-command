Feature: Download WordPress

  Scenario: Empty dir
    Given an empty directory
    And an empty cache

    When I try `fp core is-installed`
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: This does not seem to be a WordPress install
      """
    And STDOUT should be empty

    When I run `fp core download`
    And save STDOUT 'Downloading WordPress ([\d\.]+)' as {VERSION}
    Then the fp-settings.php file should exist
    And the {SUITE_CACHE_DIR}/core/wordpress-{VERSION}-en_US.tar.gz file should exist

    When I run `mkdir inner`
    And I run `cd inner && fp core download`
    Then the inner/fp-settings.php file should exist

    When I try `fp core download --path=inner`
    Then STDERR should be:
      """
      Error: WordPress files seem to already be present here.
      """
    And the return code should be 1

    When I try `WP_CLI_STRICT_ARGS_MODE=1 fp core download --path=inner`
    Then STDERR should be:
      """
      Error: WordPress files seem to already be present here.
      """
    And the return code should be 1

    # test core tarball cache
    When I run `fp core download --force`
    Then the fp-settings.php file should exist
    And STDOUT should contain:
      """
      Using cached file '{SUITE_CACHE_DIR}/core/wordpress-{VERSION}-en_US.tar.gz'...
      """

  Scenario: Localized install
    Given an empty directory
    And an empty cache
    When I run `fp core download --version=4.4.2 --locale=de_DE`
    And save STDOUT 'Downloading WordPress ([\d\.]+)' as {VERSION}
    Then the fp-settings.php file should exist
    And the {SUITE_CACHE_DIR}/core/wordpress-{VERSION}-de_DE.tar.gz file should exist

  Scenario: Catch download of non-existent WP version
    Given an empty directory

    When I try `fp core download --version=1.0.3 --force`
    Then STDERR should contain:
      """
      Error: Release not found.
      """
    And the return code should be 1

  Scenario: Core download from a URL
    Given an empty directory
    And an empty cache

    When I run `fp core download https://wordpress.org/wordpress-4.9.12.zip`
    Then the fp-settings.php file should exist
    And the {SUITE_CACHE_DIR}/core directory should not exist
    And STDOUT should contain:
      """
      Downloading from https://wordpress.org/wordpress-4.9.12.zip ...
      md5 hash verified: 702c94bc3aa8a37091f9fb075d57d847
      Success: WordPress downloaded.
      """

  Scenario: Verify release hash when downloading new version
    Given an empty directory
    And an empty cache

    When I run `fp core download --version=4.4.1`
    Then STDOUT should contain:
      """
      md5 hash verified: 1907d1dbdac7a009d89224a516496b8d
      Success: WordPress downloaded.
      """

  Scenario: Core download to a directory specified by `--path` in custom command
    Given a WP install
    And a download-command.php file:
      """
      <?php
      class Download_Command extends WP_CLI_Command {
          public function __invoke() {
              WP_CLI::run_command( array( 'core', 'download' ), array( 'path' => 'src/' ) );
          }
      }
      WP_CLI::add_command( 'custom-download', 'Download_Command' );
      """

    When I run `fp --require=download-command.php custom-download`
    Then STDOUT should not be empty
    And the src directory should contain:
      """
      fp-load.php
      """

    When I try `fp --require=download-command.php custom-download`
    Then STDERR should be:
      """
      Error: WordPress files seem to already be present here.
      """
    And the return code should be 1

  Scenario: Make sure files are cleaned up
    Given an empty directory

    When I run `fp core download --version=4.4`
    Then the fp-includes/rest-api.php file should exist
    And the fp-includes/class-fp-comment.php file should exist
    And STDERR should not contain:
      """
      Warning: Failed to find WordPress version. Please cleanup files manually.
      """

    When I run `fp core download --version=4.3.2 --force`
    Then the fp-includes/rest-api.php file should not exist
    And the fp-includes/class-fp-comment.php file should not exist
    And STDOUT should not contain:
      """
      File removed: fp-content
      """

  Scenario: Installing nightly
    Given an empty directory
    And an empty cache

    When I try `fp core download --version=nightly`
    Then the fp-settings.php file should exist
    And the {SUITE_CACHE_DIR}/core/wordpress-nightly-en_US.zip file should not exist
    And STDOUT should contain:
      """
      Downloading WordPress nightly (en_US)...
      """
    And STDERR should contain:
      """
      Warning: md5 hash checks are not available for nightly downloads.
      """
    And STDOUT should contain:
      """
      Success: WordPress downloaded.
      """
    And the return code should be 0

    # we shouldn't cache nightly builds
    When I try `fp core download --version=nightly --force`
    Then the fp-settings.php file should exist
    And STDOUT should not contain:
      """
      Using cached file '{SUITE_CACHE_DIR}/core/wordpress-nightly-en_US.zip'...
      """
    And STDERR should contain:
      """
      Warning: md5 hash checks are not available for nightly downloads.
      """
    And STDOUT should contain:
      """
      Success: WordPress downloaded.
      """
    And the return code should be 0

  Scenario: Installing nightly over an existing install
    Given an empty directory
    And an empty cache
    When I run `fp core download --version=4.5.3`
    Then the fp-settings.php file should exist
    When I try `fp core download --version=nightly --force`
    Then STDERR should not contain:
      """
      Failed to find WordPress version
      """
    And STDERR should contain:
      """
      Warning: Checksums not available for WordPress nightly/en_US. Please cleanup files manually.
      """
    And STDOUT should contain:
      """
      Success: WordPress downloaded.
      """
    And the return code should be 0

  Scenario: Installing a version over nightly
    Given an empty directory
    And an empty cache
    When I try `fp core download --version=nightly`
    Then the fp-settings.php file should exist
    And STDERR should not contain:
      """
      Warning: Failed to find WordPress version. Please cleanup files manually.
      """
    And STDOUT should contain:
      """
      Success: WordPress downloaded.
      """
    And the return code should be 0

    When I run `fp core download --version=4.3.2 --force`
    Then the fp-includes/rest-api.php file should not exist
    And the fp-includes/class-fp-comment.php file should not exist
    And STDOUT should not contain:
      """
      File removed: fp-content
      """

  Scenario: Trunk is an alias for nightly
    Given an empty directory
    And an empty cache
    When I try `fp core download --version=trunk`
    Then the fp-settings.php file should exist
    And STDOUT should contain:
      """
      Downloading WordPress nightly (en_US)...
      """
    And STDERR should contain:
      """
      Warning: md5 hash checks are not available for nightly downloads.
      """
    And STDOUT should contain:
      """
      Success: WordPress downloaded.
      """
    And the return code should be 0

  Scenario: Installing nightly for a non-default locale
    Given an empty directory
    And an empty cache

    When I try `fp core download --version=nightly --locale=de_DE`
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Nightly builds are only available for the en_US locale.
      """

  Scenario: Installing a release candidate or beta version
    Given an empty directory
    And an empty cache

    # Test with incorrect case.
    When I try `fp core download --version=4.6-rc2`
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Release not found.
      """

    When I run `fp core download --version=4.6-RC2`
    Then the fp-settings.php file should exist
    And STDOUT should contain:
      """
      Downloading WordPress 4.6-RC2 (en_US)...
      md5 hash verified: 90c93a15092b2d5d4c960ec1fc183e07
      Success: WordPress downloaded.
      """

  Scenario: Using --version=latest should produce a cache key of the version number, not 'latest'
    Given an empty directory
    And an empty cache

    When I run `fp core download --version=latest`
    Then STDOUT should contain:
      """
      Success: WordPress downloaded.
      """

    When I run `fp core version`
    Then save STDOUT as {VERSION}
    And the {SUITE_CACHE_DIR}/core/wordpress-latest-en_US.tar.gz file should not exist
    And the {SUITE_CACHE_DIR}/core/wordpress-{VERSION}-en_US.tar.gz file should exist

  Scenario: Fail if path can't be created
    Given an empty directory
    And a non-directory-path file:
      """
      """

    When I try `fp core download --path=non-directory-path`
    Then STDERR should contain:
      """
      Failed to create directory
      """
    And STDERR should contain:
      """
      /non-directory-path/
      """
    And the return code should be 1

    When I try `WP_CLI_STRICT_ARGS_MODE=1 fp core download --path=non-directory-path`
    Then STDERR should contain:
      """
      Failed to create directory
      """
    And STDERR should contain:
      """
      non-directory-path/
      """
    And the return code should be 1

    When I try `WP_CLI_STRICT_ARGS_MODE=1 fp core download --path=non-directory-path\\`
    Then STDERR should contain:
      """
      Failed to create directory
      """
    And STDERR should contain:
      """
      non-directory-path/
      """
    And the return code should be 1

    When I try `fp core download --path=/root-level-directory`
    Then STDERR should contain:
      """
      Insufficient permission to create directory
      """
    And STDERR should contain:
      """
      /root-level-directory/
      """
    And the return code should be 1

    When I try `WP_CLI_STRICT_ARGS_MODE=1 fp core download --path=/root-level-directory`
    Then STDERR should contain:
      """
      Insufficient permission to create directory
      """
    And STDERR should contain:
      """
      /root-level-directory/
      """
    And the return code should be 1

  Scenario: Core download without the full fp-content/plugins dir
    Given an empty directory

    When I run `fp core download --skip-content`
    Then STDOUT should contain:
      """
      Success: WordPress downloaded.
      """
    And the fp-includes directory should exist
    And the fp-content/plugins directory should exist
    And the fp-content/plugins directory should be:
      """
      index.php
      """
    And the fp-includes/js/tinymce/plugins directory should exist

  Scenario: Core download without the full fp-content/themes dir
    Given an empty directory

    When I run `fp core download --skip-content`
    Then STDOUT should contain:
      """
      Success: WordPress downloaded.
      """
    And the fp-includes directory should exist
    And the fp-content/themes directory should exist
    And the fp-content/themes directory should be:
      """
      index.php
      """
    And the fp-includes/js/tinymce/themes directory should exist

  Scenario: Core download without the full fp-content/plugins dir should work non US locale
    Given an empty directory

    When I run `fp core download --skip-content --version=4.9.11 --locale=nl_NL`
    Then STDOUT should contain:
      """
      Success: WordPress downloaded.
      """
    And the fp-includes directory should exist
    And the fp-content/plugins directory should exist
    And the fp-content/plugins directory should be:
      """
      index.php
      """
    And the fp-includes/js/tinymce/plugins directory should exist

  Scenario: Core download without the full fp-content/themes dir should work non US locale
    Given an empty directory

    When I run `fp core download --skip-content --version=4.9.11 --locale=nl_NL`
    Then STDOUT should contain:
      """
      Success: WordPress downloaded.
      """
    And the fp-includes directory should exist
    And the fp-content/themes directory should exist
    And the fp-content/themes directory should be:
      """
      index.php
      """
    And the fp-includes/js/tinymce/themes directory should exist

  Scenario: Core download without the full fp-content/plugins dir should work if a version is set
    Given an empty directory

    When I try `fp core download --skip-content --version=4.7`
    Then STDOUT should contain:
      """
      Success: WordPress downloaded.
      """
    And the fp-includes directory should exist
    And the fp-content/plugins directory should exist
    And the fp-content/plugins directory should be:
      """
      index.php
      """
    And the fp-content/themes directory should exist
    And the fp-content/themes directory should be:
      """
      index.php
      """
    And the fp-includes/js/tinymce/themes directory should exist
    And the fp-includes/js/tinymce/plugins directory should exist

  Scenario: Core download without extract parameter should unzip the download file
    Given an empty directory

    When I run `fp core download --version=4.5 --locale=de_DE`
    Then the fp-content directory should exist
    And the wordpress-4.5-de_DE.tar.gz file should not exist

  Scenario: Core download with extract parameter should unzip the download file
    Given an empty directory

    When I run `fp core download --version=4.5 --locale=de_DE --extract`
    Then the fp-content directory should exist
    And the wordpress-4.5-de_DE.tar.gz file should not exist

  Scenario: Core download with extract parameter should unzip the download file (already cached)
    Given an empty directory

    When I run `fp core download --version=4.5 --locale=de_DE --extract`
    And I run `rm -rf *`
    And I run `fp core download --version=4.5 --locale=de_DE --extract`
    Then the fp-content directory should exist
    And the wordpress-4.5-de_DE.tar.gz file should not exist

  Scenario: Core download with no-extract should not unzip the download file
    Given an empty directory

    When I run `fp core download --version=4.5 --locale=de_DE --no-extract`
    Then the fp-content directory should not exist
    And the wordpress-4.5-de_DE.tar.gz file should exist

  Scenario: Core download with no-extract should not unzip the download file (already cached)
    Given an empty directory

    When I run `fp core download --version=4.5 --locale=de_DE --no-extract`
    And I run `rm -rf wordpress-4.5-de_DE.tar.gz`
    And I run `fp core download --version=4.5 --locale=de_DE --no-extract`
    Then the fp-content directory should not exist
    And the wordpress-4.5-de_DE.tar.gz file should exist

  Scenario: Error when using both --skip-content and --no-extract
    Given an empty directory

    When I try `fp core download --skip-content --no-extract`
    Then STDERR should contain:
      """
      Error: Cannot use both --skip-content and --no-extract at the same time.
      """
    And the return code should be 1

  Scenario: Allow installing major version with trailing zero
    Given an empty directory

    When I run `fp core download --version=6.7.0`
    Then STDOUT should contain:
      """
      Success:
      """

