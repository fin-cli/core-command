<?php

use Composer\Semver\Comparator;
use FIN_CLI\Extractor;
use FIN_CLI\Iterators\Table as TableIterator;
use FIN_CLI\Utils;
use FIN_CLI\Formatter;
use FIN_CLI\FinOrgApi;

/**
 * Downloads, installs, updates, and manages a FinPress installation.
 *
 * ## EXAMPLES
 *
 *     # Download FinPress core
 *     $ fin core download --locale=nl_NL
 *     Downloading FinPress 4.5.2 (nl_NL)...
 *     md5 hash verified: c5366d05b521831dd0b29dfc386e56a5
 *     Success: FinPress downloaded.
 *
 *     # Install FinPress
 *     $ fin core install --url=example.com --title=Example --admin_user=supervisor --admin_password=strongpassword --admin_email=info@example.com
 *     Success: FinPress installed successfully.
 *
 *     # Display the FinPress version
 *     $ fin core version
 *     4.5.2
 *
 * @package fin-cli
 */
class Core_Command extends FIN_CLI_Command {

	/**
	 * Checks for FinPress updates via Version Check API.
	 *
	 * Lists the most recent versions when there are updates available,
	 * or success message when up to date.
	 *
	 * ## OPTIONS
	 *
	 * [--minor]
	 * : Compare only the first two parts of the version number.
	 *
	 * [--major]
	 * : Compare only the first part of the version number.
	 *
	 * [--force-check]
	 * : Bypass the transient cache and force a fresh update check.
	 *
	 * [--field=<field>]
	 * : Prints the value of a single field for each update.
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific object fields. Defaults to version,update_type,package_url.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - count
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ fin core check-update
	 *     +---------+-------------+-------------------------------------------------------------+
	 *     | version | update_type | package_url                                                 |
	 *     +---------+-------------+-------------------------------------------------------------+
	 *     | 4.5.2   | major       | https://downloads.finpress.org/release/finpress-4.5.2.zip |
	 *     +---------+-------------+-------------------------------------------------------------+
	 *
	 * @subcommand check-update
	 *
	 * @param string[] $args Positional arguments. Unused.
	 * @param array{minor?: bool, major?: bool, 'force-check'?: bool, field?: string, format: string} $assoc_args Associative arguments.
	 */
	public function check_update( $args, $assoc_args ) {
		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$updates = $this->get_updates( $assoc_args );

		if ( $updates || 'table' !== $format ) {
			$updates   = array_reverse( $updates );
			$formatter = new Formatter(
				$assoc_args,
				[ 'version', 'update_type', 'package_url' ]
			);
			$formatter->display_items( $updates );
		} else {
			FIN_CLI::success( 'FinPress is at the latest version.' );
		}
	}

	/**
	 * Downloads core FinPress files.
	 *
	 * Downloads and extracts FinPress core files to the specified path. Uses
	 * current directory when no path is specified. Downloaded build is verified
	 * to have the correct md5 and then cached to the local filesystem.
	 * Subsequent uses of command will use the local cache if it still exists.
	 *
	 * ## OPTIONS
	 *
	 * [<download-url>]
	 * : Download directly from a provided URL instead of fetching the URL from the finpress.org servers.
	 *
	 * [--path=<path>]
	 * : Specify the path in which to install FinPress. Defaults to current
	 * directory.
	 *
	 * [--locale=<locale>]
	 * : Select which language you want to download.
	 *
	 * [--version=<version>]
	 * : Select which version you want to download. Accepts a version number, 'latest' or 'nightly'.
	 *
	 * [--skip-content]
	 * : Download FIN without the default themes and plugins.
	 *
	 * [--force]
	 * : Overwrites existing files, if present.
	 *
	 * [--insecure]
	 * : Retry download without certificate validation if TLS handshake fails. Note: This makes the request vulnerable to a MITM attack.
	 *
	 * [--extract]
	 * : Whether to extract the downloaded file. Defaults to true.
	 *
	 * ## EXAMPLES
	 *
	 *     $ fin core download --locale=nl_NL
	 *     Downloading FinPress 4.5.2 (nl_NL)...
	 *     md5 hash verified: c5366d05b521831dd0b29dfc386e56a5
	 *     Success: FinPress downloaded.
	 *
	 * @when before_fin_load
	 *
	 * @param array{0?: string} $args Positional arguments.
	 * @param array{path?: string, locale?: string, version?: string, 'skip-content'?: bool, force?: bool, insecure?: bool, extract?: bool} $assoc_args Associative arguments.
	 */
	public function download( $args, $assoc_args ) {
		/**
		 * @var string $download_dir
		 */
		$download_dir = ! empty( $assoc_args['path'] )
			? ( rtrim( $assoc_args['path'], '/\\' ) . '/' )
			: ABSPATH;

		// Check for files if FinPress already present or not.
		$finpress_present = is_readable( $download_dir . 'fin-load.php' )
			|| is_readable( $download_dir . 'fin-mail.php' )
			|| is_readable( $download_dir . 'fin-cron.php' )
			|| is_readable( $download_dir . 'fin-links-opml.php' );

		if ( $finpress_present && ! Utils\get_flag_value( $assoc_args, 'force' ) ) {
			FIN_CLI::error( 'FinPress files seem to already be present here.' );
		}

		if ( ! is_dir( $download_dir ) ) {
			if ( ! is_writable( dirname( $download_dir ) ) ) {
				FIN_CLI::error( "Insufficient permission to create directory '{$download_dir}'." );
			}

			FIN_CLI::log( "Creating directory '{$download_dir}'." );
			if ( ! @mkdir( $download_dir, 0777, true /*recursive*/ ) ) {
				$error = error_get_last();
				if ( $error ) {
					FIN_CLI::error( "Failed to create directory '{$download_dir}': {$error['message']}." );
				} else {
					FIN_CLI::error( "Failed to create directory '{$download_dir}'." );
				}
			}
		}

		if ( ! is_writable( $download_dir ) ) {
			FIN_CLI::error( "'{$download_dir}' is not writable by current user." );
		}

		$locale       = Utils\get_flag_value( $assoc_args, 'locale', 'en_US' );
		$skip_content = Utils\get_flag_value( $assoc_args, 'skip-content', false );
		$insecure     = Utils\get_flag_value( $assoc_args, 'insecure', false );
		$extract      = Utils\get_flag_value( $assoc_args, 'extract', true );

		if ( $skip_content && ! $extract ) {
			FIN_CLI::error( 'Cannot use both --skip-content and --no-extract at the same time.' );
		}

		$download_url = array_shift( $args );
		$from_url     = ! empty( $download_url );

		if ( $from_url ) {
			$version = null;
			if ( isset( $assoc_args['version'] ) ) {
				FIN_CLI::error( 'Version option is not available for URL downloads.' );
			}
			if ( $skip_content || 'en_US' !== $locale ) {
				FIN_CLI::error( 'Skip content and locale options are not available for URL downloads.' );
			}
		} elseif ( isset( $assoc_args['version'] ) && 'latest' !== $assoc_args['version'] ) {
			$version = $assoc_args['version'];
			if ( in_array( strtolower( $version ), [ 'trunk', 'nightly' ], true ) ) {
				$version = 'nightly';
			}

			// Nightly builds and skip content are only available in .zip format.
			$extension = ( ( 'nightly' === $version ) || $skip_content )
				? 'zip'
				: 'tar.gz';

			$download_url = $this->get_download_url( $version, $locale, $extension );
		} else {
			try {
				$offer = ( new FinOrgApi( [ 'insecure' => $insecure ] ) )
					->get_core_download_offer( $locale );
			} catch ( Exception $exception ) {
				FIN_CLI::error( $exception );
			}
			if ( ! $offer ) {
				FIN_CLI::error( "The requested locale ({$locale}) was not found." );
			}
			$version      = $offer['current'];
			$download_url = $offer['download'];
			if ( ! $skip_content ) {
				$download_url = str_replace( '.zip', '.tar.gz', $download_url );
			}
		}

		if ( 'nightly' === $version && 'en_US' !== $locale ) {
			FIN_CLI::error( 'Nightly builds are only available for the en_US locale.' );
		}

		$from_version = '';
		if ( file_exists( $download_dir . 'fin-includes/version.php' ) ) {
			$fin_details   = self::get_fin_details( $download_dir );
			$from_version = $fin_details['fin_version'];
		}

		if ( $from_url ) {
			FIN_CLI::log( "Downloading from {$download_url} ..." );
		} else {
			FIN_CLI::log( "Downloading FinPress {$version} ({$locale})..." );
		}

		$path_parts = pathinfo( $download_url );
		$extension  = 'tar.gz';
		if ( isset( $path_parts['extension'] ) && 'zip' === $path_parts['extension'] ) {
			$extension = 'zip';
			if ( $extract && ! class_exists( 'ZipArchive' ) ) {
				FIN_CLI::error( 'Extracting a zip file requires ZipArchive.' );
			}
		}

		if ( $skip_content && 'zip' !== $extension ) {
			FIN_CLI::error( 'Skip content is only available for ZIP files.' );
		}

		$cache = FIN_CLI::get_cache();
		if ( $from_url ) {
			$cache_file = null;
		} else {
			$cache_key  = "core/finpress-{$version}-{$locale}.{$extension}";
			$cache_file = $cache->has( $cache_key );
		}

		$bad_cache = false;

		if ( is_string( $cache_file ) ) {
			FIN_CLI::log( "Using cached file '{$cache_file}'..." );
			$skip_content_cache_file = $skip_content ? self::strip_content_dir( $cache_file ) : null;
			if ( $extract ) {
				try {
					Extractor::extract( $skip_content_cache_file ?: $cache_file, $download_dir );
				} catch ( Exception $exception ) {
					FIN_CLI::warning( 'Extraction failed, downloading a new copy...' );
					$bad_cache = true;
				}
			} else {
				copy( $cache_file, $download_dir . basename( $cache_file ) );
			}
		}

		if ( ! $cache_file || $bad_cache ) {
			// We need to use a temporary file because piping from cURL to tar is flaky
			// on MinGW (and probably in other environments too).
			$temp = Utils\get_temp_dir() . uniqid( 'fin_' ) . ".{$extension}";
			register_shutdown_function(
				function () use ( $temp ) {
					if ( file_exists( $temp ) ) {
						unlink( $temp );
					}
				}
			);

			$headers = [ 'Accept' => 'application/json' ];
			$options = [
				'timeout'  => 600,  // 10 minutes ought to be enough for everybody
				'filename' => $temp,
				'insecure' => $insecure,
			];

			/** @var \FinOrg\Requests\Response $response */
			$response = Utils\http_request( 'GET', $download_url, null, $headers, $options );

			if ( 404 === (int) $response->status_code ) {
				FIN_CLI::error( 'Release not found. Double-check locale or version.' );
			} elseif ( 20 !== (int) substr( (string) $response->status_code, 0, 2 ) ) {
				FIN_CLI::error( "Couldn't access download URL (HTTP code {$response->status_code})." );
			}

			if ( 'nightly' !== $version ) {
				unset( $options['filename'] );
				/** @var \FinOrg\Requests\Response $md5_response */
				$md5_response = Utils\http_request( 'GET', $download_url . '.md5', null, [], $options );
				if ( $md5_response->status_code >= 200 && $md5_response->status_code < 300 ) {
					$md5_file = md5_file( $temp );

					if ( $md5_file === $md5_response->body ) {
						FIN_CLI::log( 'md5 hash verified: ' . $md5_file );
					} else {
						FIN_CLI::error( "md5 hash for download ({$md5_file}) is different than the release hash ({$md5_response->body})." );
					}
				} else {
					FIN_CLI::warning( "Couldn't access md5 hash for release ({$download_url}.md5, HTTP code {$md5_response->status_code})." );
				}
			} else {
				FIN_CLI::warning( 'md5 hash checks are not available for nightly downloads.' );
			}

			$skip_content_temp = $skip_content ? self::strip_content_dir( $temp ) : null;
			if ( $extract ) {
				try {
					Extractor::extract( $skip_content_temp ?: $temp, $download_dir );
				} catch ( Exception $exception ) {
					FIN_CLI::error( "Couldn't extract FinPress archive. {$exception->getMessage()}" );
				}
			} else {
				copy( $temp, $download_dir . basename( $temp ) );
			}

			// Do not use the cache for nightly builds or for downloaded URLs
			// (the URL could be something like "latest.zip" or "nightly.zip").
			if ( ! $from_url && 'nightly' !== $version ) {
				$cache->import( $cache_key, $temp );
			}
		}

		if ( $finpress_present ) {
			$this->cleanup_extra_files( $from_version, $version, $locale, $insecure );
		}

		FIN_CLI::success( 'FinPress downloaded.' );
	}

	/**
	 * Checks if FinPress is installed.
	 *
	 * Determines whether FinPress is installed by checking if the standard
	 * database tables are installed. Doesn't produce output; uses exit codes
	 * to communicate whether FinPress is installed.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Check if this is a multisite installation.
	 *
	 * ## EXAMPLES
	 *
	 *     # Bash script for checking if FinPress is not installed.
	 *
	 *     if ! fin core is-installed 2>/dev/null; then
	 *         # FIN is not installed. Let's try installing it.
	 *         fin core install
	 *     fi
	 *
	 *     # Bash script for checking if FinPress is installed, with fallback.
	 *
	 *     if fin core is-installed 2>/dev/null; then
	 *         # FIN is installed. Let's do some things we should only do in a confirmed FIN environment.
	 *         fin core verify-checksums
	 *     else
	 *         # Fallback if FIN is not installed.
	 *         echo 'Hey Friend, you are in the wrong spot. Move in to your FinPress directory and try again.'
	 *     fi
	 *
	 * @subcommand is-installed
	 *
	 * @param string[] $args Positional arguments. Unused.
	 * @param array{network?: bool} $assoc_args Associative arguments.
	 */
	public function is_installed( $args, $assoc_args ) {
		if ( is_blog_installed() && ( ! Utils\get_flag_value( $assoc_args, 'network' ) || is_multisite() ) ) {
			FIN_CLI::halt( 0 );
		}

		FIN_CLI::halt( 1 );
	}

	/**
	 * Runs the standard FinPress installation process.
	 *
	 * Creates the FinPress tables in the database using the URL, title, and
	 * default admin user details provided. Performs the famous 5 minute install
	 * in seconds or less.
	 *
	 * Note: if you've installed FinPress in a subdirectory, then you'll need
	 * to `fin option update siteurl` after `fin core install`. For instance, if
	 * FinPress is installed in the `/fin` directory and your domain is example.com,
	 * then you'll need to run `fin option update siteurl http://example.com/fin` for
	 * your FinPress installation to function properly.
	 *
	 * Note: When using custom user tables (e.g. `CUSTOM_USER_TABLE`), the admin
	 * email and password are ignored if the user_login already exists. If the
	 * user_login doesn't exist, a new user will be created.
	 *
	 * ## OPTIONS
	 *
	 * --url=<url>
	 * : The address of the new site.
	 *
	 * --title=<site-title>
	 * : The title of the new site.
	 *
	 * --admin_user=<username>
	 * : The name of the admin user.
	 *
	 * [--admin_password=<password>]
	 * : The password for the admin user. Defaults to randomly generated string.
	 *
	 * --admin_email=<email>
	 * : The email address for the admin user.
	 *
	 * [--locale=<locale>]
	 * : The locale/language for the installation (e.g. `de_DE`). Default is `en_US`.
	 *
	 * [--skip-email]
	 * : Don't send an email notification to the new admin user.
	 *
	 * ## EXAMPLES
	 *
	 *     # Install FinPress in 5 seconds
	 *     $ fin core install --url=example.com --title=Example --admin_user=supervisor --admin_password=strongpassword --admin_email=info@example.com
	 *     Success: FinPress installed successfully.
	 *
	 *     # Install FinPress without disclosing admin_password to bash history
	 *     $ fin core install --url=example.com --title=Example --admin_user=supervisor --admin_email=info@example.com --prompt=admin_password < admin_password.txt
	 *
	 * @param string[] $args Positional arguments. Unused.
	 * @param array{url: string, title: string, admin_user: string, admin_password?: string, admin_email: string, locale?: string, 'skip-email'?: bool} $assoc_args Associative arguments.
	 */
	public function install( $args, $assoc_args ) {
		if ( $this->do_install( $assoc_args ) ) {
			FIN_CLI::success( 'FinPress installed successfully.' );
		} else {
			FIN_CLI::log( 'FinPress is already installed.' );
		}
	}

	/**
	 * Transforms an existing single-site installation into a multisite installation.
	 *
	 * Creates the multisite database tables, and adds the multisite constants
	 * to fin-config.php.
	 *
	 * For those using FinPress with Apache, remember to update the `.htaccess`
	 * file with the appropriate multisite rewrite rules.
	 *
	 * [Review the multisite documentation](https://finpress.org/support/article/create-a-network/)
	 * for more details about how multisite works.
	 *
	 * ## OPTIONS
	 *
	 * [--title=<network-title>]
	 * : The title of the new network.
	 *
	 * [--base=<url-path>]
	 * : Base path after the domain name that each site url will start with.
	 * ---
	 * default: /
	 * ---
	 *
	 * [--subdomains]
	 * : If passed, the network will use subdomains, instead of subdirectories. Doesn't work with 'localhost'.
	 *
	 * [--skip-config]
	 * : Don't add multisite constants to fin-config.php.
	 *
	 * ## EXAMPLES
	 *
	 *     $ fin core multisite-convert
	 *     Set up multisite database tables.
	 *     Added multisite constants to fin-config.php.
	 *     Success: Network installed. Don't forget to set up rewrite rules.
	 *
	 * @subcommand multisite-convert
	 * @alias install-network
	 *
	 * @param string[] $args Positional arguments. Unused.
	 * @param array{title?: string, base: string, subdomains?: bool, 'skip-config'?: bool} $assoc_args Associative arguments.
	 */
	public function multisite_convert( $args, $assoc_args ) {
		if ( is_multisite() ) {
			FIN_CLI::error( 'This already is a multisite installation.' );
		}

		$assoc_args = self::set_multisite_defaults( $assoc_args );
		if ( ! isset( $assoc_args['title'] ) ) {
			/**
			 * @var string $blogname
			 */
			$blogname = get_option( 'blogname' );

			// translators: placeholder is blog name
			$assoc_args['title'] = sprintf( _x( '%s Sites', 'Default network name' ), $blogname );
		}

		if ( $this->multisite_convert_( $assoc_args ) ) {
			FIN_CLI::success( "Network installed. Don't forget to set up rewrite rules (and a .htaccess file, if using Apache)." );
		}
	}

	/**
	 * Installs FinPress multisite from scratch.
	 *
	 * Creates the FinPress tables in the database using the URL, title, and
	 * default admin user details provided. Then, creates the multisite tables
	 * in the database and adds multisite constants to the fin-config.php.
	 *
	 * For those using FinPress with Apache, remember to update the `.htaccess`
	 * file with the appropriate multisite rewrite rules.
	 *
	 * ## OPTIONS
	 *
	 * [--url=<url>]
	 * : The address of the new site.
	 *
	 * [--base=<url-path>]
	 * : Base path after the domain name that each site url in the network will start with.
	 * ---
	 * default: /
	 * ---
	 *
	 * [--subdomains]
	 * : If passed, the network will use subdomains, instead of subdirectories. Doesn't work with 'localhost'.
	 *
	 * --title=<site-title>
	 * : The title of the new site.
	 *
	 * --admin_user=<username>
	 * : The name of the admin user.
	 * ---
	 * default: admin
	 * ---
	 *
	 * [--admin_password=<password>]
	 * : The password for the admin user. Defaults to randomly generated string.
	 *
	 * --admin_email=<email>
	 * : The email address for the admin user.
	 *
	 * [--skip-email]
	 * : Don't send an email notification to the new admin user.
	 *
	 * [--skip-config]
	 * : Don't add multisite constants to fin-config.php.
	 *
	 * ## EXAMPLES
	 *
	 *     $ fin core multisite-install --title="Welcome to the FinPress" \
	 *     > --admin_user="admin" --admin_password="password" \
	 *     > --admin_email="user@example.com"
	 *     Single site database tables already present.
	 *     Set up multisite database tables.
	 *     Added multisite constants to fin-config.php.
	 *     Success: Network installed. Don't forget to set up rewrite rules.
	 *
	 * @subcommand multisite-install
	 *
	 * @param string[] $args Positional arguments. Unused.
	 * @param array{url?: string, base: string, subdomains?: bool, title: string, admin_user: string, admin_password?: string, admin_email: string, 'skip-email'?: bool, 'skip-config'?: bool} $assoc_args Associative arguments.
	 */
	public function multisite_install( $args, $assoc_args ) {
		if ( $this->do_install( $assoc_args ) ) {
			FIN_CLI::log( 'Created single site database tables.' );
		} else {
			FIN_CLI::log( 'Single site database tables already present.' );
		}

		$assoc_args = self::set_multisite_defaults( $assoc_args );
		// translators: placeholder is user supplied title
		$assoc_args['title'] = sprintf( _x( '%s Sites', 'Default network name' ), $assoc_args['title'] );

		// Overwrite runtime args, to avoid mismatches.
		$consts_to_args = [
			'SUBDOMAIN_INSTALL'    => 'subdomains',
			'PATH_CURRENT_SITE'    => 'base',
			'SITE_ID_CURRENT_SITE' => 'site_id',
			'BLOG_ID_CURRENT_SITE' => 'blog_id',
		];

		foreach ( $consts_to_args as $const => $arg ) {
			if ( defined( $const ) ) {
				$assoc_args[ $arg ] = constant( $const );
			}
		}

		if ( ! $this->multisite_convert_( $assoc_args ) ) {
			return;
		}

		// Do the steps that were skipped by populate_network(),
		// which checks is_multisite().
		if ( is_multisite() ) {
			$site_user = get_user_by( 'email', $assoc_args['admin_email'] );
			self::add_site_admins( $site_user );
			$domain = self::get_clean_basedomain();
			self::create_initial_blog(
				$assoc_args['site_id'],
				$assoc_args['blog_id'],
				$domain,
				$assoc_args['base'],
				$assoc_args['subdomains'],
				$site_user
			);
		}

		FIN_CLI::success( "Network installed. Don't forget to set up rewrite rules (and a .htaccess file, if using Apache)." );
	}

	private static function set_multisite_defaults( $assoc_args ) {
		$defaults = [
			'subdomains' => false,
			'base'       => '/',
			'site_id'    => 1,
			'blog_id'    => 1,
		];

		return array_merge( $defaults, $assoc_args );
	}

	private function do_install( $assoc_args ) {
		if ( is_blog_installed() ) {
			return false;
		}

		if ( true === Utils\get_flag_value( $assoc_args, 'skip-email' ) ) {
			if ( ! function_exists( 'fin_new_blog_notification' ) ) {
				// @phpstan-ignore function.inner
				function fin_new_blog_notification() {
					// Silence is golden
				}
			}
			// FIN 4.9.0 - skip "Notice of Admin Email Change" email as well (https://core.trac.finpress.org/ticket/39117).
			add_filter( 'send_site_admin_email_change_email', '__return_false' );
		}

		require_once ABSPATH . 'fin-admin/includes/upgrade.php';

		$defaults = [
			'title'          => '',
			'admin_user'     => '',
			'admin_email'    => '',
			'admin_password' => '',
		];

		$defaults['locale'] = '';

		$args = fin_parse_args( $assoc_args, $defaults );

		// Support prompting for the `--url=<url>`,
		// which is normally a runtime argument
		if ( isset( $assoc_args['url'] ) ) {
			FIN_CLI::set_url( $assoc_args['url'] );
		}

		$public   = true;
		$password = $args['admin_password'];

		if ( ! is_email( $args['admin_email'] ) ) {
			FIN_CLI::error( "The '{$args['admin_email']}' email address is invalid." );
		}

		$result = fin_install(
			$args['title'],
			$args['admin_user'],
			$args['admin_email'],
			$public,
			'',
			$password,
			$args['locale']
		);

		if ( ! empty( $GLOBALS['findb']->last_error ) ) {
			FIN_CLI::error( 'Installation produced database errors, and may have partially or completely failed.' );
		}

		if ( empty( $args['admin_password'] ) ) {
			FIN_CLI::log( "Admin password: {$result['password']}" );
		}

		// Confirm the uploads directory exists
		$upload_dir = fin_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			FIN_CLI::warning( $upload_dir['error'] );
		}

		return true;
	}

	private function multisite_convert_( $assoc_args ) {
		global $findb;

		require_once ABSPATH . 'fin-admin/includes/upgrade.php';

		$domain = self::get_clean_basedomain();
		if ( 'localhost' === $domain && ! empty( $assoc_args['subdomains'] ) ) {
			FIN_CLI::error( "Multisite with subdomains cannot be configured when domain is 'localhost'." );
		}

		// need to register the multisite tables manually for some reason
		foreach ( $findb->tables( 'ms_global' ) as $table => $prefixed_table ) {
			$findb->$table = $prefixed_table;
		}

		install_network();

		/**
		 * @var string $admin_email
		 */
		$admin_email = get_option( 'admin_email' );

		$result = populate_network(
			$assoc_args['site_id'],
			$domain,
			$admin_email,
			$assoc_args['title'],
			$assoc_args['base'],
			$assoc_args['subdomains']
		);

		$site_id = $findb->get_var( "SELECT id FROM $findb->site" );
		$site_id = ( null === $site_id ) ? 1 : (int) $site_id;

		if ( true === $result ) {
			FIN_CLI::log( 'Set up multisite database tables.' );
		} else {
			switch ( $result->get_error_code() ) {

				case 'siteid_exists':
					FIN_CLI::log( $result->get_error_message() );
					return false;

				case 'no_wildcard_dns':
					FIN_CLI::warning( __( 'Wildcard DNS may not be configured correctly.' ) );
					break;

				default:
					FIN_CLI::error( $result );
			}
		}

		// delete_site_option() cleans the alloptions cache to prevent dupe option
		delete_site_option( 'upload_space_check_disabled' );
		update_site_option( 'upload_space_check_disabled', 1 );

		if ( ! is_multisite() ) {
			$subdomain_export = Utils\get_flag_value( $assoc_args, 'subdomains' ) ? 'true' : 'false';
			$ms_config        = <<<EOT
define( 'FIN_ALLOW_MULTISITE', true );
define( 'MULTISITE', true );
define( 'SUBDOMAIN_INSTALL', {$subdomain_export} );
\$base = '{$assoc_args['base']}';
define( 'DOMAIN_CURRENT_SITE', '{$domain}' );
define( 'PATH_CURRENT_SITE', '{$assoc_args['base']}' );
define( 'SITE_ID_CURRENT_SITE', {$site_id} );
define( 'BLOG_ID_CURRENT_SITE', 1 );
EOT;

			$fin_config_path = Utils\locate_fin_config();
			if ( true === Utils\get_flag_value( $assoc_args, 'skip-config' ) ) {
				FIN_CLI::log( "Addition of multisite constants to 'fin-config.php' skipped. You need to add them manually:\n{$ms_config}" );
			} elseif ( is_writable( $fin_config_path ) && self::modify_fin_config( $ms_config ) ) {
				FIN_CLI::log( "Added multisite constants to 'fin-config.php'." );
			} else {
				FIN_CLI::warning( "Multisite constants could not be written to 'fin-config.php'. You may need to add them manually:\n{$ms_config}" );
			}
		} else {
			/* Multisite constants are defined, therefore we already have an empty site_admins site meta.
			 *
			 * Code based on parts of delete_network_option. */
			$rows = $findb->get_results( "SELECT meta_id, site_id FROM {$findb->sitemeta} WHERE meta_key = 'site_admins' AND meta_value = ''" );

			foreach ( $rows as $row ) {
				fin_cache_delete( "{$row->site_id}:site_admins", 'site-options' );

				$findb->delete(
					$findb->sitemeta,
					[ 'meta_id' => $row->meta_id ]
				);
			}
		}

		return true;
	}

	// copied from populate_network()
	private static function create_initial_blog(
		$network_id,
		$blog_id,
		$domain,
		$path,
		$subdomain_install,
		$site_user
	) {
		global $findb, $current_site, $fin_rewrite;

		// phpcs:ignore FinPress.FIN.GlobalVariablesOverride.Prohibited -- This is meant to replace Core functionality.
		$current_site            = new stdClass();
		$current_site->domain    = $domain;
		$current_site->path      = $path;
		$current_site->site_name = ucfirst( $domain );
		$blog_data               = [
			'site_id'    => $network_id,
			'domain'     => $domain,
			'path'       => $path,
			'registered' => current_time( 'mysql' ),
		];
		$findb->insert( $findb->blogs, $blog_data );
		$current_site->blog_id = $findb->insert_id;
		$blog_id               = $findb->insert_id;
		update_user_meta( $site_user->ID, 'source_domain', $domain );
		update_user_meta( $site_user->ID, 'primary_blog', $blog_id );

		if ( $subdomain_install ) {
			$fin_rewrite->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		} else {
			$fin_rewrite->set_permalink_structure( '/blog/%year%/%monthnum%/%day%/%postname%/' );
		}

		flush_rewrite_rules();
	}

	// copied from populate_network()
	private static function add_site_admins( $site_user ) {
		$site_admins = [ $site_user->user_login ];
		$users       = get_users( [ 'fields' => [ 'ID', 'user_login' ] ] );
		if ( $users ) {
			foreach ( $users as $user ) {
				if ( is_super_admin( $user->ID )
					&& ! in_array( $user->user_login, $site_admins, true ) ) {
					$site_admins[] = $user->user_login;
				}
			}
		}

		update_site_option( 'site_admins', $site_admins );
	}

	private static function modify_fin_config( $content ) {
		$fin_config_path = Utils\locate_fin_config();

		$token           = "/* That's all, stop editing!";
		$config_contents = (string) file_get_contents( $fin_config_path );
		if ( false === strpos( $config_contents, $token ) ) {
			return false;
		}

		list( $before, $after ) = explode( $token, $config_contents );

		$content = trim( $content );

		file_put_contents(
			$fin_config_path,
			"{$before}\n\n{$content}\n\n{$token}{$after}"
		);

		return true;
	}

	private static function get_clean_basedomain() {
		/**
		 * @var string $siteurl
		 */
		$siteurl = get_option( 'siteurl' );
		$domain  = (string) preg_replace( '|https?://|', '', $siteurl );
		$slash   = strpos( $domain, '/' );
		if ( false !== $slash ) {
			$domain = substr( $domain, 0, $slash );
		}
		return $domain;
	}

	/**
	 * Displays the FinPress version.
	 *
	 * ## OPTIONS
	 *
	 * [--extra]
	 * : Show extended version information.
	 *
	 * ## EXAMPLES
	 *
	 *     # Display the FinPress version
	 *     $ fin core version
	 *     4.5.2
	 *
	 *     # Display FinPress version along with other information
	 *     $ fin core version --extra
	 *     FinPress version: 4.5.2
	 *     Database revision: 36686
	 *     TinyMCE version:   4.310 (4310-20160418)
	 *     Package language:  en_US
	 *
	 * @when before_fin_load
	 *
	 * @param string[] $args Positional arguments. Unused.
	 * @param array{extra?: bool} $assoc_args Associative arguments.
	 */
	public function version( $args = [], $assoc_args = [] ) {
		$details = self::get_fin_details();

		if ( ! Utils\get_flag_value( $assoc_args, 'extra' ) ) {
			FIN_CLI::line( $details['fin_version'] );
			return;
		}

		$match                   = [];
		$found_version           = preg_match( '/(\d)(\d+)-/', $details['tinymce_version'], $match );
		$human_readable_tiny_mce = $found_version ? "{$match[1]}.{$match[2]}" : '';

		echo Utils\mustache_render(
			self::get_template_path( 'versions.mustache' ),
			[
				'fin-version'    => $details['fin_version'],
				'db-version'    => $details['fin_db_version'],
				'local-package' => empty( $details['fin_local_package'] )
					? 'en_US'
					: $details['fin_local_package'],
				'mce-version'   => $human_readable_tiny_mce
					? "{$human_readable_tiny_mce} ({$details['tinymce_version']})"
					: $details['tinymce_version'],
			]
		);
	}

	/**
	 * Gets version information from `fin-includes/version.php`.
	 *
	 * @return array {
	 *     @type string $fin_version The FinPress version.
	 *     @type int $fin_db_version The FinPress DB revision.
	 *     @type string $tinymce_version The TinyMCE version.
	 *     @type string $fin_local_package The TinyMCE version.
	 * }
	 */
	private static function get_fin_details( $abspath = ABSPATH ) {
		$versions_path = $abspath . 'fin-includes/version.php';

		if ( ! is_readable( $versions_path ) ) {
			FIN_CLI::error(
				"This does not seem to be a FinPress installation.\n" .
				'Pass --path=`path/to/finpress` or run `fin core download`.'
			);
		}

		$version_content = (string) file_get_contents( $versions_path, false, null, 6, 2048 );

		$vars   = [ 'fin_version', 'fin_db_version', 'tinymce_version', 'fin_local_package' ];
		$result = [];

		foreach ( $vars as $var_name ) {
			$result[ $var_name ] = self::find_var( $var_name, $version_content );
		}

		return $result;
	}

	/**
	 * Gets the template path based on installation type.
	 */
	private static function get_template_path( $template ) {
		$command_root  = Utils\phar_safe_path( dirname( __DIR__ ) );
		$template_path = "{$command_root}/templates/{$template}";

		if ( ! file_exists( $template_path ) ) {
			FIN_CLI::error( "Couldn't find {$template}" );
		}

		return $template_path;
	}

	/**
	 * Searches for the value assigned to variable `$var_name` in PHP code `$code`.
	 *
	 * This is equivalent to matching the `\$VAR_NAME = ([^;]+)` regular expression and returning
	 * the first match either as a `string` or as an `integer` (depending if it's surrounded by
	 * quotes or not).
	 *
	 * @param string $var_name Variable name to search for.
	 * @param string $code PHP code to search in.
	 *
	 * @return string|null
	 */
	private static function find_var( $var_name, $code ) {
		$start = strpos( $code, '$' . $var_name . ' = ' );

		if ( ! $start ) {
			return null;
		}

		$start = $start + strlen( $var_name ) + 3;
		$end   = strpos( $code, ';', $start );

		$value = substr( $code, $start, $end - $start );

		return trim( $value, " '" );
	}

	/**
	 * Security copy of the core function with Requests - Gets the checksums for the given version of FinPress.
	 *
	 * @param string $version  Version string to query.
	 * @param string $locale   Locale to query.
	 * @param bool   $insecure Whether to retry without certificate validation on TLS handshake failure.
	 * @return string|array String message on failure. An array of checksums on success.
	 */
	private static function get_core_checksums( $version, $locale, $insecure ) {
		$fin_org_api = new FinOrgApi( [ 'insecure' => $insecure ] );

		try {
			/**
			 * @var array|false $checksums
			 */
			$checksums = $fin_org_api->get_core_checksums( $version, $locale );
		} catch ( Exception $exception ) {
			return $exception->getMessage();
		}

		if ( false === $checksums ) {
			return "Checksums not available for FinPress {$version}/{$locale}.";
		}

		return $checksums;
	}

	/**
	 * Updates FinPress to a newer version.
	 *
	 * Defaults to updating FinPress to the latest version.
	 *
	 * If you see "Error: Another update is currently in progress.", you may
	 * need to run `fin option delete core_updater.lock` after verifying another
	 * update isn't actually running.
	 *
	 * ## OPTIONS
	 *
	 * [<zip>]
	 * : Path to zip file to use, instead of downloading from finpress.org.
	 *
	 * [--minor]
	 * : Only perform updates for minor releases (e.g. update from FIN 4.3 to 4.3.3 instead of 4.4.2).
	 *
	 * [--version=<version>]
	 * : Update to a specific version, instead of to the latest version. Alternatively accepts 'nightly'.
	 *
	 * [--force]
	 * : Update even when installed FIN version is greater than the requested version.
	 *
	 * [--locale=<locale>]
	 * : Select which language you want to download.
	 *
	 * [--insecure]
	 * : Retry download without certificate validation if TLS handshake fails. Note: This makes the request vulnerable to a MITM attack.
	 *
	 * ## EXAMPLES
	 *
	 *     # Update FinPress
	 *     $ fin core update
	 *     Updating to version 4.5.2 (en_US)...
	 *     Downloading update from https://downloads.finpress.org/release/finpress-4.5.2-no-content.zip...
	 *     Unpacking the update...
	 *     Cleaning up files...
	 *     No files found that need cleaning up
	 *     Success: FinPress updated successfully.
	 *
	 *     # Update FinPress using zip file.
	 *     $ fin core update ../latest.zip
	 *     Starting update...
	 *     Unpacking the update...
	 *     Success: FinPress updated successfully.
	 *
	 *     # Update FinPress to 3.1 forcefully
	 *     $ fin core update --version=3.1 --force
	 *     Updating to version 3.1 (en_US)...
	 *     Downloading update from https://finpress.org/finpress-3.1.zip...
	 *     Unpacking the update...
	 *     Warning: Checksums not available for FinPress 3.1/en_US. Please cleanup files manually.
	 *     Success: FinPress updated successfully.
	 *
	 * @alias upgrade
	 *
	 * @param array{0?: string} $args Positional arguments.
	 * @param array{minor?: bool, version?: string, force?: bool, locale?: string, insecure?: bool} $assoc_args Associative arguments.
	 */
	public function update( $args, $assoc_args ) {
		global $fin_version;

		$update   = null;
		$upgrader = 'FIN_CLI\\Core\\CoreUpgrader';

		if ( 'trunk' === Utils\get_flag_value( $assoc_args, 'version' ) ) {
			$assoc_args['version'] = 'nightly';
		}

		if ( ! empty( $args[0] ) ) {

			// ZIP path or URL is given
			$upgrader = 'FIN_CLI\\Core\\NonDestructiveCoreUpgrader';
			$version  = Utils\get_flag_value( $assoc_args, 'version' );

			$update = (object) [
				'response' => 'upgrade',
				'current'  => $version,
				'download' => $args[0],
				'packages' => (object) [
					'partial'     => null,
					'new_bundled' => null,
					'no_content'  => null,
					'full'        => $args[0],
				],
				'version'  => $version,
				'locale'   => null,
			];

		} elseif ( empty( $assoc_args['version'] ) ) {

			// Update to next release
			fin_version_check();

			/**
			 * @var object{updates: array<object{version: string, locale: string}>} $from_api
			 */
			$from_api = get_site_transient( 'update_core' );

			if ( Utils\get_flag_value( $assoc_args, 'minor' ) ) {
				foreach ( $from_api->updates as $offer ) {
					$sem_ver = Utils\get_named_sem_ver( $offer->version, $fin_version );
					if ( ! $sem_ver || 'patch' !== $sem_ver ) {
						continue;
					}
					$update = $offer;
					break;
				}
				if ( empty( $update ) ) {
					FIN_CLI::success( 'FinPress is at the latest minor release.' );
					return;
				}
			} elseif ( ! empty( $from_api->updates ) ) {
				list( $update ) = $from_api->updates;
			}
		} elseif ( Utils\fin_version_compare( $assoc_args['version'], '<' )
			|| 'nightly' === $assoc_args['version']
			|| Utils\get_flag_value( $assoc_args, 'force' ) ) {

			// Specific version is given
			$version = $assoc_args['version'];

			/**
			 * @var string $locale
			 */
			$locale = Utils\get_flag_value( $assoc_args, 'locale', get_locale() );

			$new_package = $this->get_download_url( $version, $locale );

			$update = (object) [
				'response' => 'upgrade',
				'current'  => $assoc_args['version'],
				'download' => $new_package,
				'packages' => (object) [
					'partial'     => null,
					'new_bundled' => null,
					'no_content'  => null,
					'full'        => $new_package,
				],
				'version'  => $version,
				'locale'   => $locale,
			];

		}

		if ( ! empty( $update )
			&& ( $update->version !== $fin_version
				|| Utils\get_flag_value( $assoc_args, 'force' ) ) ) {

			require_once ABSPATH . 'fin-admin/includes/upgrade.php';

			if ( $update->version ) {
				FIN_CLI::log( "Updating to version {$update->version} ({$update->locale})..." );
			} else {
				FIN_CLI::log( 'Starting update...' );
			}

			$from_version = $fin_version;
			$insecure     = (bool) Utils\get_flag_value( $assoc_args, 'insecure', false );

			$GLOBALS['fincli_core_update_obj'] = $update;

			/**
			 * @var \FIN_CLI\Core\CoreUpgrader $fin_upgrader
			 */
			$fin_upgrader = Utils\get_upgrader( $upgrader, $insecure );
			$result      = $fin_upgrader->upgrade( $update );
			unset( $GLOBALS['fincli_core_update_obj'] );

			if ( is_fin_error( $result ) ) {
				$message = FIN_CLI::error_to_string( $result );
				if ( 'up_to_date' !== $result->get_error_code() ) {
					FIN_CLI::error( $message );
				} else {
					FIN_CLI::success( $message );
				}
			} else {

				$to_version = '';
				if ( file_exists( ABSPATH . 'fin-includes/version.php' ) ) {
					$fin_details = self::get_fin_details();
					$to_version = $fin_details['fin_version'];
				}

				/**
				 * @var string $locale
				 */
				$locale = Utils\get_flag_value( $assoc_args, 'locale', get_locale() );
				$this->cleanup_extra_files( $from_version, $to_version, $locale, $insecure );

				FIN_CLI::success( 'FinPress updated successfully.' );
			}
		} else {
			FIN_CLI::success( 'FinPress is up to date.' );
		}
	}

	/**
	 * Runs the FinPress database update procedure.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Update databases for all sites on a network
	 *
	 * [--dry-run]
	 * : Compare database versions without performing the update.
	 *
	 * ## EXAMPLES
	 *
	 *     # Update the FinPress database.
	 *     $ fin core update-db
	 *     Success: FinPress database upgraded successfully from db version 36686 to 35700.
	 *
	 *     # Update databases for all sites on a network.
	 *     $ fin core update-db --network
	 *     FinPress database upgraded successfully from db version 35700 to 29630 on example.com/
	 *     Success: FinPress database upgraded on 123/123 sites.
	 *
	 * @subcommand update-db
	 *
	 * @param string[] $args Positional arguments. Unused.
	 * @param array{network?: bool, 'dry-run'?: bool} $assoc_args Associative arguments.
	 */
	public function update_db( $args, $assoc_args ) {
		global $findb, $fin_db_version, $fin_current_db_version;

		$network = Utils\get_flag_value( $assoc_args, 'network' );
		if ( $network && ! is_multisite() ) {
			FIN_CLI::error( 'This is not a multisite installation.' );
		}

		$dry_run = Utils\get_flag_value( $assoc_args, 'dry-run' );
		if ( $dry_run ) {
			FIN_CLI::log( 'Performing a dry run, with no database modification.' );
		}

		if ( $network ) {
			$iterator_args = [
				'table' => $findb->blogs,
				'where' => [
					'spam'     => 0,
					'deleted'  => 0,
					'archived' => 0,
				],
			];
			$it            = new TableIterator( $iterator_args );
			$success       = 0;
			$total         = 0;
			$site_ids      = [];

			/**
			 * @var object{site_id: int, domain: string, path: string} $blog
			 */
			foreach ( $it as $blog ) {
				++$total;
				$site_ids[] = $blog->site_id;
				$url        = $blog->domain . $blog->path;
				$cmd        = "--url={$url} core update-db";
				if ( $dry_run ) {
					$cmd .= ' --dry-run';
				}

				/**
				 * @var object{stdout: string, stderr: string, return_code: int} $process
				 */
				$process = FIN_CLI::runcommand(
					$cmd,
					[
						'return'     => 'all',
						'exit_error' => false,
					]
				);
				if ( 0 === (int) $process->return_code ) {
					// See if we can parse the stdout
					if ( preg_match( '#Success: (.+)#', $process->stdout, $matches ) ) {
						$message = rtrim( $matches[1], '.' );
						$message = "{$message} on {$url}";
					} else {
						$message = "Database upgraded successfully on {$url}";
					}
					FIN_CLI::log( $message );
					++$success;
				} else {
					FIN_CLI::warning( "Database failed to upgrade on {$url}" );
				}
			}
			if ( ! $dry_run && $total && $success === $total ) {
				foreach ( array_unique( $site_ids ) as $site_id ) {
					update_metadata( 'site', $site_id, 'finmu_upgrade_site', $fin_db_version );
				}
			}
			FIN_CLI::success( "FinPress database upgraded on {$success}/{$total} sites." );
		} else {
			require_once ABSPATH . 'fin-admin/includes/upgrade.php';

			/**
			 * @var string $fin_current_db_version
			 */
			// phpcs:ignore FinPress.FIN.GlobalVariablesOverride.Prohibited -- Replacing FIN Core behavior is the goal here.
			$fin_current_db_version = __get_option( 'db_version' );
			// phpcs:ignore FinPress.FIN.GlobalVariablesOverride.Prohibited -- Replacing FIN Core behavior is the goal here.
			$fin_current_db_version = (int) $fin_current_db_version;

			if ( $fin_db_version !== $fin_current_db_version ) {
				if ( $dry_run ) {
					FIN_CLI::success( "FinPress database will be upgraded from db version {$fin_current_db_version} to {$fin_db_version}." );
				} else {
					// FIN upgrade isn't too fussy about generating MySQL warnings such as "Duplicate key name" during an upgrade so suppress.
					$findb->suppress_errors();

					// FIN upgrade expects `$_SERVER['HTTP_HOST']` to be set in `fin_guess_url()`, otherwise get PHP notice.
					if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
						$_SERVER['HTTP_HOST'] = 'example.com';
					}

					fin_upgrade();

					FIN_CLI::success( "FinPress database upgraded successfully from db version {$fin_current_db_version} to {$fin_db_version}." );
				}
			} else {
				FIN_CLI::success( "FinPress database already at latest db version {$fin_db_version}." );
			}
		}
	}

	/**
	 * Gets download url based on version, locale and desired file type.
	 *
	 * @param $version
	 * @param string $locale
	 * @param string $file_type
	 * @return string
	 */
	private function get_download_url( $version, $locale = 'en_US', $file_type = 'zip' ) {

		if ( 'nightly' === $version ) {
			if ( 'zip' === $file_type ) {
				return 'https://finpress.org/nightly-builds/finpress-latest.zip';
			} else {
				FIN_CLI::error( 'Nightly builds are only available in .zip format.' );
			}
		}

		$locale_subdomain = 'en_US' === $locale ? '' : substr( $locale, 0, 2 ) . '.';
		$locale_suffix    = 'en_US' === $locale ? '' : "-{$locale}";
		// Match 6.7.0 but not 6.0
		if ( substr_count( $version, '.' ) > 1 && substr( $version, -2 ) === '.0' ) {
			$version = substr( $version, 0, -2 );
		}

		return "https://{$locale_subdomain}finpress.org/finpress-{$version}{$locale_suffix}.{$file_type}";
	}

	/**
	 * Returns update information.
	 *
	 * @param array $assoc_args Associative array of arguments.
	 * @return array List of available updates , or an empty array if no updates are available.
	 */
	private function get_updates( $assoc_args ) {
		$force_check = Utils\get_flag_value( $assoc_args, 'force-check' );
		fin_version_check( [], $force_check );

		/**
		 * @var object{updates: array<object{version: string, locale: string, packages: object{partial?: string, full: string}}>}|false $from_api
		 */
		$from_api = get_site_transient( 'update_core' );
		if ( ! $from_api ) {
			return [];
		}

		$compare_version = str_replace( '-src', '', $GLOBALS['fin_version'] );

		$updates = [
			'major' => false,
			'minor' => false,
		];
		foreach ( $from_api->updates as $offer ) {

			$update_type = Utils\get_named_sem_ver( $offer->version, $compare_version );
			if ( ! $update_type ) {
				continue;
			}

			// FinPress follow its own versioning which is roughly equivalent to semver
			if ( 'minor' === $update_type ) {
				$update_type = 'major';
			} elseif ( 'patch' === $update_type ) {
				$update_type = 'minor';
			}

			if ( ! empty( $updates[ $update_type ] ) && ! Comparator::greaterThan( $offer->version, $updates[ $update_type ]['version'] ) ) {
				continue;
			}

			$updates[ $update_type ] = [
				'version'     => $offer->version,
				'update_type' => $update_type,
				'package_url' => ! empty( $offer->packages->partial ) ? $offer->packages->partial : $offer->packages->full,
			];
		}

		foreach ( $updates as $type => $value ) {
			if ( empty( $value ) ) {
				unset( $updates[ $type ] );
			}
		}

		foreach ( [ 'major', 'minor' ] as $type ) {
			if ( true === Utils\get_flag_value( $assoc_args, $type ) ) {
				return ! empty( $updates[ $type ] )
					? [ $updates[ $type ] ]
					: [];
			}
		}
		return array_values( $updates );
	}

	/**
	 * Clean up extra files.
	 *
	 * @param string $version_from Starting version that the installation was updated from.
	 * @param string $version_to   Target version that the installation is updated to.
	 * @param string $locale       Locale of the installation.
	 * @param bool   $insecure     Whether to retry without certificate validation on TLS handshake failure.
	 */
	private function cleanup_extra_files( $version_from, $version_to, $locale, $insecure ) {
		if ( ! $version_from || ! $version_to ) {
			FIN_CLI::warning( 'Failed to find FinPress version. Please cleanup files manually.' );
			return;
		}

		$old_checksums = self::get_core_checksums( $version_from, $locale ?: 'en_US', $insecure );
		if ( ! is_array( $old_checksums ) ) {
			FIN_CLI::warning( "{$old_checksums} Please cleanup files manually." );
			return;
		}

		$new_checksums = self::get_core_checksums( $version_to, $locale ?: 'en_US', $insecure );
		if ( ! is_array( $new_checksums ) ) {
			FIN_CLI::warning( "{$new_checksums} Please cleanup files manually." );

			return;
		}

		// Compare the files from the old version and the new version in a case-insensitive manner,
		// to prevent files being incorrectly deleted on systems with case-insensitive filesystems
		// when core changes the case of filenames.
		// The main logic for this was taken from the Joomla project and adapted for FIN.
		// See: https://github.com/joomla/joomla-cms/blob/bb5368c7ef9c20270e6e9fcc4b364cd0849082a5/administrator/components/com_admin/script.php#L8158

		$old_filepaths = array_keys( $old_checksums );
		$new_filepaths = array_keys( $new_checksums );

		$new_filepaths = array_combine( array_map( 'strtolower', $new_filepaths ), $new_filepaths );

		$old_filepaths_to_check = array_diff( $old_filepaths, $new_filepaths );

		foreach ( $old_filepaths_to_check as $old_filepath_to_check ) {
			$old_realpath = realpath( ABSPATH . $old_filepath_to_check );

			// On Unix without incorrectly cased file.
			if ( false === $old_realpath ) {
				continue;
			}

			$lowercase_old_filepath_to_check = strtolower( $old_filepath_to_check );

			if ( ! array_key_exists( $lowercase_old_filepath_to_check, $new_filepaths ) ) {
				$files_to_remove[] = $old_filepath_to_check;
				continue;
			}

			// We are now left with only the files that are similar from old to new except for their case.

			$old_basename      = basename( $old_realpath );
			$new_filepath      = $new_filepaths[ $lowercase_old_filepath_to_check ];
			$expected_basename = basename( $new_filepath );
			$new_realpath      = (string) realpath( ABSPATH . $new_filepath );
			$new_basename      = basename( $new_realpath );

			// On Windows or Unix with only the incorrectly cased file.
			if ( $new_basename !== $expected_basename ) {
				FIN_CLI::debug( "Renaming file '{$old_filepath_to_check}' => '{$new_filepath}'", 'core' );

				rename( ABSPATH . $old_filepath_to_check, ABSPATH . $old_filepath_to_check . '.tmp' );
				rename( ABSPATH . $old_filepath_to_check . '.tmp', ABSPATH . $new_filepath );

				continue;
			}

			// There might still be an incorrectly cased file on other OS than Windows.
			if ( basename( $old_filepath_to_check ) === $old_basename ) {
				// Check if case-insensitive file system, eg on OSX.
				if ( fileinode( $old_realpath ) === fileinode( $new_realpath ) ) {
					$files = scandir( dirname( $new_realpath ) ) ?: [];

					// Check deeper because even realpath or glob might not return the actual case.
					if ( ! in_array( $expected_basename, $files, true ) ) {
						FIN_CLI::debug( "Renaming file '{$old_filepath_to_check}' => '{$new_filepath}'", 'core' );

						rename( ABSPATH . $old_filepath_to_check, ABSPATH . $old_filepath_to_check . '.tmp' );
						rename( ABSPATH . $old_filepath_to_check . '.tmp', ABSPATH . $new_filepath );
					}
				} else {
					// On Unix with both files: Delete the incorrectly cased file.
					$files_to_remove[] = $old_filepath_to_check;
				}
			}
		}

		if ( ! empty( $files_to_remove ) ) {
			FIN_CLI::log( 'Cleaning up files...' );

			$count = 0;
			foreach ( $files_to_remove as $file ) {

				// fin-content should be considered user data
				if ( 0 === stripos( $file, 'fin-content' ) ) {
					continue;
				}

				if ( file_exists( ABSPATH . $file ) ) {
					unlink( ABSPATH . $file );
					FIN_CLI::log( "File removed: {$file}" );
					++$count;
				}
			}

			if ( $count ) {
				FIN_CLI::log( number_format( $count ) . ' files cleaned up.' );
			} else {
				FIN_CLI::log( 'No files found that need cleaning up.' );
			}
		}
	}

	private static function strip_content_dir( $zip_file ) {
		$new_zip_file = Utils\get_temp_dir() . uniqid( 'fin_' ) . '.zip';
		register_shutdown_function(
			function () use ( $new_zip_file ) {
				if ( file_exists( $new_zip_file ) ) {
					unlink( $new_zip_file );
				}
			}
		);
		// Duplicate file to avoid modifying the original, which could be cache.
		if ( ! copy( $zip_file, $new_zip_file ) ) {
			FIN_CLI::error( 'Failed to copy ZIP file.' );
		}
		$zip = new ZipArchive();
		$res = $zip->open( $new_zip_file );
		if ( true === $res ) {
			// phpcs:ignore FinPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$info = $zip->statIndex( $i );
				if ( ! $info ) {
					continue;
				}

				// Strip all files in fin-content/themes and fin-content/plugins
				// but leave the directories and index.php files intact.
				if ( in_array(
					$info['name'],
					array(
						'finpress/fin-content/plugins/',
						'finpress/fin-content/plugins/index.php',
						'finpress/fin-content/themes/',
						'finpress/fin-content/themes/index.php',
					),
					true
				) ) {
					continue;
				}

				if ( 0 === stripos( $info['name'], 'finpress/fin-content/themes/' ) || 0 === stripos( $info['name'], 'finpress/fin-content/plugins/' ) ) {
					$zip->deleteIndex( $i );
				}
			}
			$zip->close();
			return $new_zip_file;
		} else {
			FIN_CLI::error( 'ZipArchive failed to open ZIP file.' );
		}
	}
}
