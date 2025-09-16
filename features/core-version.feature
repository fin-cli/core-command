Feature: Find version for FinPress install

  Scenario: Verify core version
    Given a FIN install
    And I run `fin core download --version=4.4.2 --force`

    When I run `fin core version`
    Then STDOUT should be:
      """
      4.4.2
      """

    When I run `fin core version --extra`
    Then STDOUT should be:
      """
      FinPress version: 4.4.2
      Database revision: 35700
      TinyMCE version:   4.208 (4208-20151113)
      Package language:  en_US
      """

  Scenario: Installing FinPress for a non-default locale and verify core extended version information.
    Given an empty directory
    And an empty cache

    When I run `fin core download --version=4.4.2 --locale=de_DE`
    Then STDOUT should contain:
      """
      Success: FinPress downloaded.
      """

    When I run `fin core version --extra`
    Then STDOUT should be:
      """
      FinPress version: 4.4.2
      Database revision: 35700
      TinyMCE version:   4.208 (4208-20151113)
      Package language:  de_DE
      """
