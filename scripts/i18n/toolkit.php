<?php
/**
 * Unified, modular i18n toolkit for PeakURL.
 *
 * Centralizes directory discovery, header management, string extraction,
 * catalog merging, and MO/JSON compilation.
 *
 * @package PeakURL\Scripts\I18n
 */

declare(strict_types=1);

namespace PeakURL\Scripts\I18n;

use FilesystemIterator;
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Languages\Language as GettextLanguage;
use Gettext\Loader\PoLoader;
use Gettext\Merge;
use Gettext\Translation;
use Gettext\Translations;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Class I18nToolkit
 *
 * Modular utility providing end-to-end POT generation, PO synchronization,
 * and compilation services for PeakURL.
 */
final class I18nToolkit {

	/**
	 * Project root directory path.
	 */
	private string $root_path;

	/**
	 * Languages directory path (content/languages).
	 */
	private string $languages_dir;

	/**
	 * Path to the canonical peakurl.pot template file.
	 */
	private string $template_path;

	/**
	 * Configuration array loaded from headers.json.
	 *
	 * @var array<string, string>
	 */
	private array $headers_config;

	/**
	 * Runtime version string (e.g. "1.0.0").
	 */
	private string $version;

	/**
	 * Initialize the i18n toolkit.
	 *
	 * @param string|null $root_path Optional custom root path.
	 */
	public function __construct( ?string $root_path = null ) {
		$this->root_path      = $root_path ?? dirname( __DIR__, 2 );
		$this->languages_dir  = $this->root_path . '/content/languages';
		$this->template_path  = $this->languages_dir . '/peakurl.pot';
		$this->version       = trim( (string) @file_get_contents( $this->root_path . '/.version' ) ) ?: '1.0.0';
		$this->headers_config = $this->load_headers_config();
	}

	/**
	 * Get the project root directory.
	 */
	public function get_root_path(): string {
		return $this->root_path;
	}

	/**
	 * Get the languages directory.
	 */
	public function get_languages_dir(): string {
		return $this->languages_dir;
	}

	/**
	 * Get the template path.
	 */
	public function get_template_path(): string {
		return $this->template_path;
	}

	/**
	 * Get the current version.
	 */
	public function get_version(): string {
		return $this->version;
	}

	/**
	 * Load headers configuration from headers.json.
	 *
	 * @return array<string, string>
	 */
	private function load_headers_config(): array {
		$candidate_paths = array(
			$this->root_path . '/scripts/i18n/headers.json',
			$this->root_path . '/scripts/headers.json',
		);

		foreach ( $candidate_paths as $file ) {
			if ( file_exists( $file ) ) {
				$data = json_decode( (string) file_get_contents( $file ), true );
				if ( is_array( $data ) ) {
					return $data;
				}
			}
		}

		return array(
			'Project-Id-Version'        => 'PeakURL',
			'Report-Msgid-Bugs-To'      => 'https://peakurl.org/contact',
			'Last-Translator'           => 'PeakURL Team <translate@peakurl.org>',
			'Language-Team'             => 'PeakURL Localization Team <translate@peakurl.org>',
			'MIME-Version'              => '1.0',
			'Content-Type'              => 'text/plain; charset=UTF-8',
			'Content-Transfer-Encoding' => '8bit',
			'X-Domain'                  => 'peakurl',
			'X-Generator'               => 'PeakURL i18n Tools',
		);
	}

	/**
	 * Apply complete, standardized headers to a Translations catalog.
	 *
	 * @param Translations      $catalog             The catalog to update.
	 * @param string|null       $locale              Optional locale identifier.
	 * @param string|null       $pot_creation_date   Optional POT creation timestamp.
	 * @param Translations|null $source_template     Optional source template for fallback date.
	 */
	public function apply_headers(
		Translations $catalog,
		?string $locale = null,
		?string $pot_creation_date = null,
		?Translations $source_template = null
	): void {
		$project_id = trim( (string) ( $this->headers_config['Project-Id-Version'] ?? 'PeakURL' ) );
		if ( '' !== $project_id && false === strpos( $project_id, $this->version ) ) {
			$project_id = sprintf( '%s %s', $project_id, $this->version );
		}

		$now_date = gmdate( 'Y-m-d H:iO' );

		if ( null === $pot_creation_date ) {
			if ( $source_template ) {
				$pot_creation_date = (string) $source_template->getHeaders()->get( 'POT-Creation-Date' );
			}
			if ( empty( $pot_creation_date ) ) {
				$pot_creation_date = (string) $catalog->getHeaders()->get( 'POT-Creation-Date' );
			}
			if ( empty( $pot_creation_date ) ) {
				$pot_creation_date = $now_date;
			}
		}

		$catalog->getHeaders()->set( 'Project-Id-Version', $project_id ?: $this->version );
		$catalog->getHeaders()->set( 'Report-Msgid-Bugs-To', (string) ( $this->headers_config['Report-Msgid-Bugs-To'] ?? 'https://peakurl.org/contact' ) );
		$catalog->getHeaders()->set( 'POT-Creation-Date', $pot_creation_date );
		$catalog->getHeaders()->set( 'PO-Revision-Date', $now_date );
		$catalog->getHeaders()->set( 'Last-Translator', (string) ( $this->headers_config['Last-Translator'] ?? 'PeakURL Team <translate@peakurl.org>' ) );
		$catalog->getHeaders()->set( 'Language-Team', (string) ( $this->headers_config['Language-Team'] ?? 'PeakURL Localization Team <translate@peakurl.org>' ) );
		$catalog->getHeaders()->set( 'MIME-Version', (string) ( $this->headers_config['MIME-Version'] ?? '1.0' ) );
		$catalog->getHeaders()->set( 'Content-Type', (string) ( $this->headers_config['Content-Type'] ?? 'text/plain; charset=UTF-8' ) );
		$catalog->getHeaders()->set( 'Content-Transfer-Encoding', (string) ( $this->headers_config['Content-Transfer-Encoding'] ?? '8bit' ) );
		$catalog->getHeaders()->set( 'X-Domain', (string) ( $this->headers_config['X-Domain'] ?? 'peakurl' ) );
		$catalog->getHeaders()->set( 'X-Generator', (string) ( $this->headers_config['X-Generator'] ?? 'PeakURL i18n Tools' ) );

		if ( null !== $locale ) {
			$catalog->getHeaders()->set( 'Language', $locale );
			$catalog->getHeaders()->set( 'Plural-Forms', $this->get_plural_forms( $locale ) );
		}
	}

	/**
	 * Resolve the Plural-Forms header formula for a given locale.
	 *
	 * @param string $locale Locale identifier.
	 * @return string
	 */
	public function get_plural_forms( string $locale ): string {
		$language = $this->get_language_info( $locale );

		if ( null !== $language ) {
			return sprintf(
				'nplurals=%d; plural=%s;',
				count( $language->categories ),
				$language->buildFormula( true ),
			);
		}

		return 'nplurals=2; plural=(n != 1);';
	}

	/**
	 * Resolve language metadata from GettextLanguage registry.
	 *
	 * @param string $locale Locale identifier.
	 * @return GettextLanguage|null
	 */
	public function get_language_info( string $locale ): ?GettextLanguage {
		$language = GettextLanguage::getById( $locale );

		if ( null !== $language ) {
			return $language;
		}

		$base_locale = strstr( $locale, '_', true );

		if ( false !== $base_locale && '' !== $base_locale ) {
			return GettextLanguage::getById( $base_locale );
		}

		return null;
	}

	/**
	 * Normalize a locale string (e.g. "es-es" -> "es_ES").
	 *
	 * @param string $locale Raw locale string.
	 * @return string
	 */
	public function normalize_locale_code( string $locale ): string {
		$locale = trim( str_replace( '-', '_', $locale ) );

		if ( '' === $locale ) {
			return '';
		}

		$parts = explode( '_', $locale );

		if ( empty( $parts ) ) {
			return '';
		}

		$parts[0] = strtolower( (string) $parts[0] );

		foreach ( $parts as $index => $part ) {
			if ( 0 === $index ) {
				continue;
			}

			$parts[ $index ] = strlen( $part ) <= 3
				? strtoupper( $part )
				: ucfirst( strtolower( $part ) );
		}

		return implode( '_', $parts );
	}

	/**
	 * Parse requested locales from CLI argument vector.
	 *
	 * @param array<int, string> $argv Raw CLI arguments.
	 * @return array<int, string>
	 */
	public function parse_requested_locales( array $argv ): array {
		$locales = array();

		foreach ( array_slice( $argv, 1 ) as $argument ) {
			if ( 0 === strpos( $argument, '--locale=' ) ) {
				$argument = substr( $argument, 9 );
			}

			if ( ! is_string( $argument ) || '' === trim( $argument ) ) {
				continue;
			}

			$normalized_locale = $this->normalize_locale_code( $argument );

			if ( '' !== $normalized_locale && ! in_array( $normalized_locale, $locales, true ) ) {
				$locales[] = $normalized_locale;
			}
		}

		return $locales;
	}

	/**
	 * Resolve target PO files in the languages directory.
	 *
	 * @param array<int, string> $requested_locales Optional requested locales filter.
	 * @return array<string, string> Map of [locale => absolute_po_path].
	 */
	public function get_targets( array $requested_locales = array() ): array {
		$targets = array();

		if ( ! empty( $requested_locales ) ) {
			foreach ( $requested_locales as $locale ) {
				$targets[ $locale ] = sprintf(
					'%s/peakurl-%s.po',
					$this->languages_dir,
					$locale,
				);
			}

			return $targets;
		}

		$po_paths = glob( $this->languages_dir . '/peakurl-*.po' ) ?: array();

		foreach ( $po_paths as $po_path ) {
			if (
				! is_string( $po_path ) ||
				'.pot' === substr( $po_path, -4 ) ||
				! preg_match( '/peakurl-([A-Za-z0-9_]+)\.po$/', $po_path, $matches )
			) {
				continue;
			}

			$targets[ (string) $matches[1] ] = $po_path;
		}

		return $targets;
	}

	/**
	 * Extract translatable strings from source files and generate peakurl.pot.
	 *
	 * @return int Total number of unique strings extracted.
	 */
	public function build_pot(): int {
		$scan_roots = array(
			$this->root_path . '/app',
			$this->root_path . '/site',
			$this->root_path . '/ui',
		);
		$skip_paths = array(
			'/vendor/',
			'/node_modules/',
			'/build/',
			'/release/',
			'/backup/',
			'/content/',
		);
		$extensions = array( 'php', 'js', 'ts', 'tsx' );

		$patterns = array(
			array(
				'type'    => 'singular',
				'pattern' => '/(?P<fn>__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*(?P<quote>[\'"])(?P<text>(?:\\\\.|(?!\k<quote>).)*)\k<quote>\s*(?:,\s*(?P<domain_quote>[\'"])(?P<domain>(?:\\\\.|(?!\k<domain_quote>).)*)\k<domain_quote>)?\s*,?\s*\)/s',
			),
			array(
				'type'    => 'context',
				'pattern' => '/(?P<fn>_x|_ex)\(\s*(?P<text_quote>[\'"])(?P<text>(?:\\\\.|(?!\k<text_quote>).)*)\k<text_quote>\s*,\s*(?P<context_quote>[\'"])(?P<context>(?:\\\\.|(?!\k<context_quote>).)*)\k<context_quote>\s*(?:,\s*(?P<domain_quote>[\'"])(?P<domain>(?:\\\\.|(?!\k<domain_quote>).)*)\k<domain_quote>)?\s*,?\s*\)/s',
			),
			array(
				'type'    => 'plural',
				'pattern' => '/(?P<fn>_n)\(\s*(?P<single_quote>[\'"])(?P<single>(?:\\\\.|(?!\k<single_quote>).)*)\k<single_quote>\s*,\s*(?P<plural_quote>[\'"])(?P<plural>(?:\\\\.|(?!\k<plural_quote>).)*)\k<plural_quote>\s*,.*?,\s*(?:(?P<domain_quote>[\'"])(?P<domain>(?:\\\\.|(?!\k<domain_quote>).)*)\k<domain_quote>\s*,?)?\s*\)/s',
			),
			array(
				'type'    => 'plural_context',
				'pattern' => '/(?P<fn>_nx)\(\s*(?P<single_quote>[\'"])(?P<single>(?:\\\\.|(?!\k<single_quote>).)*)\k<single_quote>\s*,\s*(?P<plural_quote>[\'"])(?P<plural>(?:\\\\.|(?!\k<plural_quote>).)*)\k<plural_quote>\s*,.*?,\s*(?P<context_quote>[\'"])(?P<context>(?:\\\\.|(?!\k<context_quote>).)*)\k<context_quote>\s*(?:,\s*(?P<domain_quote>[\'"])(?P<domain>(?:\\\\.|(?!\k<domain_quote>).)*)\k<domain_quote>)?\s*,?\s*\)/s',
			),
		);

		$translations = Translations::create( 'peakurl', 'en_US' );
		$translations->setDescription( 'PeakURL translation template.' );
		$this->apply_headers( $translations, 'en_US' );

		foreach ( $scan_roots as $scan_root ) {
			if ( ! is_dir( $scan_root ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$scan_root,
					FilesystemIterator::SKIP_DOTS,
				),
			);

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() ) {
					continue;
				}

				$path          = $file_info->getPathname();
				$relative_path = ltrim( str_replace( $this->root_path, '', $path ), '/' );
				$extension     = strtolower( (string) $file_info->getExtension() );

				if ( ! in_array( $extension, $extensions, true ) ) {
					continue;
				}

				$should_skip = false;
				foreach ( $skip_paths as $skip_path ) {
					if ( false !== strpos( $path, $skip_path ) ) {
						$should_skip = true;
						break;
					}
				}

				if ( $should_skip ) {
					continue;
				}

				$contents = file_get_contents( $path );
				if ( false === $contents || '' === $contents ) {
					continue;
				}

				foreach ( $patterns as $definition ) {
					$match_count = preg_match_all(
						$definition['pattern'],
						$contents,
						$matches,
						PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
					);

					if ( false === $match_count || 0 === $match_count ) {
						continue;
					}

					foreach ( $matches as $match ) {
						$domain = $match['domain'][0] ?? 'peakurl';

						if ( '' === $domain || 'peakurl' !== $domain ) {
							continue;
						}

						$line = 1 + substr_count(
							substr( $contents, 0, (int) $match[0][1] ),
							"\n",
						);

						switch ( $definition['type'] ) {
							case 'singular':
								$translation = Translation::create(
									null,
									stripcslashes( (string) $match['text'][0] ),
								);
								break;
							case 'context':
								$translation = Translation::create(
									stripcslashes( (string) $match['context'][0] ),
									stripcslashes( (string) $match['text'][0] ),
								);
								break;
							case 'plural':
								$translation = Translation::create(
									null,
									stripcslashes( (string) $match['single'][0] ),
								)->setPlural(
									stripcslashes( (string) $match['plural'][0] ),
								);
								break;
							case 'plural_context':
								$translation = Translation::create(
									stripcslashes( (string) $match['context'][0] ),
									stripcslashes( (string) $match['single'][0] ),
								)->setPlural(
									stripcslashes( (string) $match['plural'][0] ),
								);
								break;
							default:
								continue 2;
						}

						$translation->getReferences()->add( $relative_path, $line );
						$translations->addOrMerge( $translation );
					}
				}
			}
		}

		if ( ! is_dir( dirname( $this->template_path ) ) ) {
			mkdir( dirname( $this->template_path ), 0777, true );
		}

		( new PoGenerator() )->generateFile( $translations, $this->template_path );

		return count( $translations );
	}

	/**
	 * Synchronize PO catalogs with the POT template.
	 *
	 * @param array<int, string> $argv Raw CLI arguments.
	 * @return array{total: int, updated: int, created: int}
	 */
	public function update_po( array $argv = array() ): array {
		if ( ! file_exists( $this->template_path ) ) {
			throw new \RuntimeException(
				sprintf(
					"Missing POT template: %s\nRun npm run i18n:pot first.\n",
					$this->template_path,
				),
			);
		}

		$requested_locales = $this->parse_requested_locales( $argv );
		$targets           = $this->get_targets( $requested_locales );

		if ( empty( $targets ) ) {
			return array(
				'total'   => 0,
				'updated' => 0,
				'created' => 0,
			);
		}

		$loader    = new PoLoader();
		$generator = new PoGenerator();
		$template  = $loader->loadFile( $this->template_path );
		$template->setDomain( 'peakurl' );

		$updated = 0;
		$created = 0;

		$strategy = Merge::TRANSLATIONS_OURS
			| Merge::TRANSLATIONS_OVERRIDE
			| Merge::HEADERS_OURS
			| Merge::COMMENTS_THEIRS
			| Merge::EXTRACTED_COMMENTS_OURS
			| Merge::REFERENCES_OURS;

		foreach ( $targets as $locale => $po_path ) {
			$already_exists = file_exists( $po_path );

			if ( $already_exists ) {
				$existing = $loader->loadFile( $po_path );
				$catalog  = $template->mergeWith( $existing, $strategy );
				++$updated;
			} else {
				$catalog = clone $template;
				++$created;
			}

			$catalog->setDomain( 'peakurl' );
			$this->apply_headers( $catalog, $locale, null, $template );
			$generator->generateFile( $catalog, $po_path );
		}

		return array(
			'total'   => count( $targets ),
			'updated' => $updated,
			'created' => $created,
		);
	}

	/**
	 * Compile PO files into binary MO and optimized client JSON catalogs.
	 *
	 * @param array<int, string> $argv Raw CLI arguments.
	 * @return int Total number of language packs compiled.
	 */
	public function compile( array $argv = array() ): int {
		$requested_locales = $this->parse_requested_locales( $argv );
		$targets           = $this->get_targets( $requested_locales );
		$loader            = new PoLoader();
		$mo_generator      = new MoGenerator();
		$compiled          = 0;

		foreach ( $targets as $locale => $po_path ) {
			if ( ! file_exists( $po_path ) ) {
				continue;
			}

			$translations = $loader->loadFile( $po_path );
			$translations->setDomain( 'peakurl' );
			$this->apply_headers( $translations, $locale );

			$plural_forms  = (string) $translations->getHeaders()->get( 'Plural-Forms' );
			$revision_date = (string) $translations->getHeaders()->get( 'PO-Revision-Date' ) ?: gmdate( 'Y-m-d H:iO' );

			$messages = array(
				'' => array(
					'domain'       => 'peakurl',
					'lang'         => $locale,
					'plural-forms' => $plural_forms,
				),
			);

			foreach ( $translations as $translation ) {
				$original = (string) $translation->getOriginal();

				if ( '' === $original || $translation->isDisabled() ) {
					continue;
				}

				$key = null !== $translation->getContext()
					? (string) $translation->getContext() . "\004" . $original
					: $original;

				if ( null !== $translation->getPlural() ) {
					$values = array_values(
						array_filter(
							array_map(
								static fn( $value ): string => (string) $value,
								$translation->getPluralTranslations(),
							),
							static fn( string $value ): bool => '' !== $value,
						),
					);
				} else {
					$value  = (string) $translation->getTranslation();
					$values = '' !== $value ? array( $value ) : array();
				}

				if ( empty( $values ) ) {
					continue;
				}

				$messages[ $key ] = $values;
			}

			$mo_path   = str_replace( '.po', '.mo', $po_path );
			$json_path = str_replace( '.po', '.json', $po_path );

			$mo_generator->generateFile( $translations, $mo_path );
			file_put_contents(
				$json_path,
				json_encode(
					array(
						'translation-revision-date' => $revision_date,
						'generator'                 => (string) ( $this->headers_config['X-Generator'] ?? 'PeakURL i18n Tools' ),
						'domain'                    => 'peakurl',
						'locale_data'               => array(
							'messages' => $messages,
						),
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
				) . PHP_EOL,
			);
			++$compiled;
		}

		return $compiled;
	}
}
