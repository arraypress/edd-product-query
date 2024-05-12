<?php
/**
 * Product Query Class
 *
 * Facilitates advanced querying and retrieval of EDD (Easy Digital Downloads) products using
 * a comprehensive array of parameters, such as sorting, filtering by categories or tags,
 * and applying complex meta queries. Designed to integrate seamlessly with WordPress environments,
 * it supports intricate queries involving numeric and string comparisons, taxonomy conditions,
 * and transient caching for optimized performance. Debugging features are included to aid in
 * development and troubleshooting. This class leverages WordPress's WP_Query mechanism
 * and extends its capabilities to suit the specific needs of EDD-based systems.
 *
 * Usage:
 * - Instantiate with custom arguments: `$product_query = new ProductQuery($args);`
 * - Retrieve products using: `$products = $product_query->get_results();`
 * - Apply dynamic taxonomy and meta filters.
 * - Debugging can be enabled by setting the 'debug' argument to true, which will output the query.
 *
 * The class is highly customizable with setters for nearly every parameter type it supports, from
 * taxonomy and meta keys to orderby configurations and transient lengths. This allows developers
 * to finely tune queries and potentially integrate custom behaviors or support for additional
 * data types.
 *
 * @package     ArrayPress/EDD-Product-Query
 * @copyright   Copyright (c) 2024, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\Utils\EDD;

defined( 'ABSPATH' ) || exit;

use EDD_Download;
use InvalidArgumentException;
use WP_Query;
use function array_map;
use function call_user_func;
use function count;
use function filter_var;
use function function_exists;
use function get_option;
use function get_posts;
use function get_transient;
use function intval;
use function is_numeric;
use function md5;
use function method_exists;
use function serialize;
use function set_transient;
use function taxonomy_exists;
use function term_exists;
use function wp_list_pluck;
use function wp_parse_args;
use const DAY_IN_SECONDS;
use const HOUR_IN_SECONDS;
use const MINUTE_IN_SECONDS;
use const MONTH_IN_SECONDS;
use const WEEK_IN_SECONDS;

if ( ! class_exists( __NAMESPACE__ . '\\Product_Query' ) ) :

	/**
	 * Product query class.
	 */
	class Product_Query {

		/**
		 * The query arguments for fetching products. Allows customization of
		 * query parameters such as sorting, filtering by category or tag, numeric
		 * and string comparisons, and more.
		 *
		 * @var array
		 */
		private array $query_args;

		/**
		 * Numeric comparison operators for filtering query results. Supports basic
		 * comparison operations including equality, inequality, greater than/less than,
		 * and range queries.
		 *
		 * @var array
		 */
		const NUMERIC_OPERATORS = [
			'=',
			'!=',
			'>',
			'>=',
			'<',
			'<=',
			'BETWEEN',
			'NOT BETWEEN'
		];

		/**
		 * String comparison operators for filtering query results based on string
		 * pattern matching. Includes operators for exact match, pattern matching,
		 * and inclusion within a set of values.
		 *
		 * @var array
		 */
		const STRING_OPERATORS = [
			'=',
			'!=',
			'LIKE',
			'NOT LIKE',
			'IN',
			'NOT IN',
			'REGEXP',
			'NOT REGEXP'
		];

		/**
		 * Supported numeric types for meta value comparisons. This facilitates
		 * accurate comparisons and sorting of numeric meta values in the database.
		 *
		 * @var array
		 */
		const SUPPORTED_NUMERIC_TYPES = [
			'NUMERIC',
			'DECIMAL',
			'SIGNED',
			'UNSIGNED'
		];

		/**
		 * Defines the valid logical relation types that can be used in queries.
		 *
		 * These relation types are utilized to specify how multiple conditions (e.g., in tax queries or meta queries)
		 * are combined. 'AND' signifies that all conditions must be met, whereas 'OR' means that any condition being
		 * met is sufficient for inclusion in the results.
		 *
		 * @var array
		 */
		const VALID_RELATIONS = [
			'AND',
			'OR'
		];

		/**
		 * Defines the valid operators for taxonomy queries in WordPress.
		 *
		 * These operators are used to refine how terms are matched within taxonomy queries:
		 * - 'IN' ensures that the post has any of the specified terms.
		 * - 'NOT IN' ensures that the post does not have any of the specified terms.
		 * - 'AND' ensures that the post has all of the specified terms.
		 *
		 * These operators allow for flexible and precise taxonomy-based filtering in queries.
		 *
		 * @var array
		 */
		const VALID_TAX_OPERATORS = [
			'IN',
			'NOT IN',
			'AND'
		];

		/**
		 * Mapping of human-readable duration strings to WordPress constants.
		 *
		 * @var array
		 */
		private array $time_mappings = [
			'minute' => MINUTE_IN_SECONDS,
			'hour'   => HOUR_IN_SECONDS,
			'day'    => DAY_IN_SECONDS,
			'week'   => WEEK_IN_SECONDS,
			'month'  => MONTH_IN_SECONDS,
		];

		/**
		 * Mapping of taxonomy slugs to their corresponding taxonomy names. This
		 * allows for easy translation between user-friendly taxonomy names and their
		 * database equivalents.
		 *
		 * @var array
		 */
		private array $taxonomy_mapping = [];

		/**
		 * Mapping of meta keys that indicate the existence of certain features or
		 * properties in a product, to their corresponding database meta keys.
		 *
		 * @var array
		 */
		private array $meta_exist_mapping = [
			'variable'    => '_variable_pricing',
			'multi'       => '_edd_price_options_mode',
			'files'       => 'edd_download_files',
			'tax_exempt'  => '_edd_download_tax_exclusive',
			'commissions' => '_edd_commisions_enabled',
			'licensing'   => '_edd_sl_enabled',
			'shipping'    => '_edd_enable_shipping',
			'notes'       => 'edd_product_notes'
		];

		/**
		 * Mapping for numeric meta data, facilitating comparisons and filtering
		 * based on numeric meta values such as price, sales, earnings, and ratings.
		 *
		 * @var array
		 */
		private array $meta_numeric_mapping = [
			'price'          => [
				'meta_key'    => 'edd_price',
				'compare_key' => 'price_compare',
				'type'        => 'DECIMAL',
			],
			'sales'          => [
				'meta_key'    => '_edd_download_sales',
				'compare_key' => 'sales_compare',
				'type'        => 'NUMERIC',
			],
			'earnings'       => [
				'meta_key'    => '_edd_download_earnings',
				'compare_key' => 'earnings_compare',
				'type'        => 'DECIMAL',
			],
			'rating'         => [
				'meta_key'    => '_edd_average_rating',
				'compare_key' => 'rating_compare',
				'type'        => 'DECIMAL',
			],
			'download_limit' => [
				'meta_key'    => '_edd_download_limit',
				'compare_key' => 'download_limit_compare',
				'type'        => 'NUMERIC',
			],
			'refund_window'  => [
				'meta_key'    => '_edd_refund_window',
				'compare_key' => 'refund_window_compare',
				'type'        => 'NUMERIC',
			],
		];

		/**
		 * Mapping for boolean meta value comparisons, allowing for filtering
		 * based on boolean conditions such as whether a product is recurring.
		 *
		 * @var array
		 */
		private array $meta_value_compare_mapping = [
			'recurring' => [
				'meta_key' => 'edd_recurring',
				'true'     => 'yes',
				'false'    => 'no',
			]
		];

		/**
		 * Mapping for operator-based meta value comparisons, useful for specific
		 * product type checks such as bundles or all access products.
		 *
		 * @var array
		 */
		private array $meta_operator_compare_mapping = [
			'bundles'    => [
				'meta_key'   => '_edd_product_type',
				'meta_value' => 'bundle'
			],
			'all_access' => [
				'meta_key'   => '_edd_product_type',
				'meta_value' => 'all_access'
			],
			'service'    => [
				'meta_key'   => '_edd_product_type',
				'meta_value' => 'service'
			]
		];

		/**
		 * Mapping for operator-based meta value comparisons, useful for specific
		 * product type checks such as bundles or all access products.
		 *
		 * @var array
		 */
		private array $meta_dynamic_value_compare_mapping = [
			'type' => '_edd_product_type',

		];

		/**
		 * Mapping for `orderby` parameters, translating user-friendly sorting
		 * criteria into their corresponding query parameters for database queries.
		 *
		 * @var array
		 */
		private array $orderby_mapping = [];

		/**
		 * An associative array mapping meta query types to callback methods responsible for preparing each type of meta query.
		 * This mapping enables the dynamic construction of meta queries based on different criteria, such as existence checks,
		 * numeric comparisons, operator-based comparisons, and value comparisons. Each callback is a method within this class
		 * that returns an array formatted for inclusion in a WordPress WP_Query meta_query.
		 *
		 * Callbacks should return arrays formatted according to the structure expected by WordPress for 'meta_query' arrays.
		 * This may include specifying a 'key', 'value', 'compare', and optionally a 'type' for the value comparison.
		 *
		 * @var array
		 */
		private array $meta_query_callbacks = [
			'user_provided'    => 'prepare_user_meta_queries',
			'exists'           => 'prepare_meta_exist_queries',
			'numeric'          => 'prepare_meta_numeric_queries',
			'operator_compare' => 'prepare_meta_operator_compare_queries',
			'value_compare'    => 'prepare_meta_value_compare_queries',
			'dynamic_compare'  => 'prepare_meta_dynamic_compare_queries'
		];

		/**
		 * Length of time to keep the results in the transient cache.
		 *
		 * @var int
		 */
		private int $transient_length;  // Default to 1 day.

		/**
		 * Enable or disable debugging output.
		 *
		 * @var bool
		 */
		private bool $debug = false;

		/**
		 * Constructs the product query object.
		 *
		 * Initializes the query with default parameters and overrides them with any provided arguments.
		 *
		 * @param array $args Custom arguments to override default query parameters.
		 */
		public function __construct( array $args = [], $transient_length = DAY_IN_SECONDS ) {
			$this->setup_taxonomies();
			$this->setup_orderby_mappings();
			$this->setup_query_args( $args );
			$this->setup_debug_mode();
			$this->setup_transient_length( $transient_length );
		}

		/** Property Setters **********************************************************/

		/**
		 * Default query arguments.
		 */
		private function setup_query_args( array $args = [] ): void {
			$defaults = [
				'number'           => get_option( 'posts_per_page', 10 ),
				'limit'            => 0,
				'offset'           => 0,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'status'           => 'publish',
				'meta_relation'    => 'AND',
				'tax_relation'     => 'OR',
				'category'         => [],
				'tag'              => [],
				'price'            => '',
				'price_compare'    => '=',
				'rating'           => '',
				'rating_compare'   => '=',
				'sales'            => '',
				'sales_compare'    => '=',
				'earnings'         => '',
				'earnings_compare' => '=',
				'fields'           => 'objects',
				'exclude'          => [],
				'include'          => [],
				'paginate'         => false,
				'suppress_filters' => true,
				'files'            => null,
				'tax_exempt'       => null,
				'commissions'      => null,
				'licensing'        => null,
				'recurring'        => null,
				'all_access'       => null,
				'bundles'          => null,
				'service'          => null,
				'variable'         => null,
				'multi'            => null,
				'shipping'         => null,
				'type'             => null,
				'debug'            => false,
				'date'             => '',
				'date_after'       => '',
				'date_before'      => '',
			];

			$this->query_args = wp_parse_args( $args, $defaults );
		}

		/**
		 * Initialize taxonomy mappings for the download post type.
		 */
		private function setup_taxonomies(): void {
			$taxonomies = get_taxonomies( [ 'object_type' => [ 'download' ] ], 'objects' );
			if ( $taxonomies ) {
				foreach ( $taxonomies as $taxonomy ) {
					$friendly_name                            = strtolower( str_replace( 'download_', '', $taxonomy->name ) );
					$this->taxonomy_mapping[ $friendly_name ] = strtolower( $taxonomy->name );
				}
			}
		}

		/**
		 * Dynamically generates the orderby mapping from numeric meta mappings.
		 * Integrates standard sorting fields such as title and ID with dynamically
		 * generated fields based on numeric metadata.
		 *
		 * @return void
		 */
		private function setup_orderby_mappings(): void {
			$mappings = [
				'title'    => 'title',
				'id'       => 'ID',
				'random'   => 'rand',
				'post__in' => 'post__in',
				'default'  => 'post_date'
			];

			foreach ( $this->meta_numeric_mapping as $key => $info ) {
				$mappings[ $key ] = [
					'meta_key' => $info['meta_key'],
					'orderby'  => 'meta_value_num'
				];
			}

			$this->orderby_mapping = $mappings;
		}

		/**
		 * Initializes debugging mode based on the query arguments.
		 *
		 * This method sets the debugging mode of the ProductQuery instance. It checks the 'debug'
		 * key within the query arguments and converts it to a boolean value which enables or disables
		 * debugging. Debugging typically involves outputting the query arguments, which can help
		 * with troubleshooting and ensuring that the query parameters are correctly interpreted and applied.
		 *
		 * If debugging is enabled, detailed query information can be output before executing the query,
		 * showing how query parameters have been constructed and merged, which can be crucial for
		 * debugging complex query interactions especially in a development environment.
		 *
		 * Usage:
		 * - Enable debugging by setting 'debug' to true in the query arguments.
		 * - Debugging is disabled by default and needs to be explicitly enabled.
		 *
		 * @return void
		 */
		private function setup_debug_mode(): void {
			if ( filter_var( $this->query_args['debug'], FILTER_VALIDATE_BOOLEAN ) ) {
				$this->debug = true;
			}
		}

		/**
		 * A helper method to set the length of time for which query results should be stored in the transient cache.
		 * This method serves as a more accessible or intuitive interface to the `set_transient_length` method.
		 * It accepts either a specific number of seconds or a string representing a predefined time period,
		 * such as 'minute', 'hour', 'day', 'week', or 'month'.
		 *
		 * @param mixed $transient_length The duration in seconds or a predefined string as defined in `$time_mappings`.
		 *                                Examples of valid strings include 'minute', 'hour', 'day', 'week', and 'month'.
		 *
		 * @throws InvalidArgumentException If the provided length is not valid according to the types or values expected.
		 */
		public function setup_transient_length( $transient_length ): void {
			$this->set_transient_length( $transient_length );
		}

		/** Property Setters **********************************************************/

		/**
		 * Sets the length of time for which query results should be stored in the transient cache.
		 * Accepts either a specific number of seconds or a string representing a time period (e.g., 'minute', 'hour').
		 *
		 * @param mixed $transient_length The duration in seconds or a predefined string.
		 *
		 * @throws InvalidArgumentException If the provided length is not valid.
		 */
		public function set_transient_length( $transient_length ): void {
			if ( is_string( $transient_length ) && isset( $this->time_mappings[ $transient_length ] ) ) {
				$this->transient_length = $this->time_mappings[ $transient_length ];
			} elseif ( is_numeric( $transient_length ) ) {
				$this->transient_length = $transient_length;
			} else {
				throw new InvalidArgumentException( "Invalid transient length specified." );
			}
		}

		/**
		 * Set custom taxonomy mappings.
		 *
		 * @param array $mappings Associative array mapping query keys to taxonomy types.
		 */
		public function set_taxonomy_mappings( array $mappings ): void {
			$this->taxonomy_mapping = $mappings;
		}

		/**
		 * Adds additional taxonomy mappings to the existing set. Ensures that there are no duplicate keys.
		 *
		 * @param array $mappings Key-value pairs representing new taxonomy mappings.
		 *
		 * @throws InvalidArgumentException If a key already exists in the current mappings.
		 */
		public function add_taxonomy_mappings( array $mappings ): void {
			foreach ( $mappings as $key => $value ) {
				if ( ! isset( $this->taxonomy_mapping[ $key ] ) ) {
					$this->taxonomy_mapping[ $key ] = $value;
				} else {
					throw new InvalidArgumentException( "Taxonomy key {$key} already exists." );
				}
			}
		}

		/**
		 * Set custom taxonomy mappings.
		 *
		 * @param array $mappings Associative array mapping query keys to taxonomy types.
		 */
		public function set_meta_exist_mappings( array $mappings ): void {
			$this->meta_exist_mapping = $mappings;
		}

		/**
		 * Adds additional mappings for meta keys that indicate the existence of certain properties.
		 * Checks for duplicates before adding to ensure no key overwrites.
		 *
		 * @param array $mappings Associative array of new mappings to add.
		 *
		 * @throws InvalidArgumentException If the key already exists in the existing mappings.
		 */
		public function add_meta_exist_mappings( array $mappings ): void {
			foreach ( $mappings as $key => $value ) {
				if ( isset( $this->meta_exist_mapping[ $key ] ) ) {
					throw new InvalidArgumentException( "Meta existence key '{$key}' already exists." );
				}
				$this->meta_exist_mapping[ $key ] = $value;
			}
		}

		/**
		 * Set custom meta numeric mappings.
		 *
		 * @param array $mappings Associative array mapping product attributes to meta keys and comparison types.
		 */
		public function set_meta_numeric_mappings( array $mappings ): void {
			$this->meta_numeric_mapping = $mappings;
		}

		/**
		 * Adds additional mappings for numeric meta data, facilitating comparisons based on numeric meta values.
		 * Validates the structure of the new mappings before adding them.
		 *
		 * @param array $mappings Associative array of new mappings to add.
		 *
		 * @throws InvalidArgumentException If the key already exists or if the mapping structure is incorrect.
		 */
		public function add_meta_numeric_mappings( array $mappings ): void {
			foreach ( $mappings as $key => $value ) {
				if ( ! isset( $value['meta_key'], $value['compare_key'], $value['type'] ) || isset( $this->meta_numeric_mapping[ $key ] ) ) {
					throw new InvalidArgumentException( "Invalid structure or duplicate key '{$key}' for numeric mapping." );
				}
				$this->meta_numeric_mapping[ $key ] = $value;
			}
		}

		/**
		 * Set custom meta value comparison mappings.
		 *
		 * @param array $mappings Associative array defining how to compare boolean meta values.
		 */
		public function set_meta_value_compare_mappings( array $mappings ): void {
			$this->meta_value_compare_mapping = $mappings;
		}

		/**
		 * Adds additional mappings for comparing meta values based on boolean conditions.
		 * Validates the absence of duplicate keys before adding.
		 *
		 * @param array $mappings Associative array of new mappings to add.
		 *
		 * @throws InvalidArgumentException If the key already exists in the existing mappings.
		 */
		public function add_meta_value_compare_mappings( array $mappings ): void {
			foreach ( $mappings as $key => $value ) {
				if ( isset( $this->meta_value_compare_mapping[ $key ] ) ) {
					throw new InvalidArgumentException( "Meta value comparison key '{$key}' already exists." );
				}
				$this->meta_value_compare_mapping[ $key ] = $value;
			}
		}

		/**
		 * Set custom meta operator comparison mappings.
		 *
		 * This allows specifying custom meta keys for operator-based comparisons,
		 * useful for filtering products based on specific criteria like product type.
		 *
		 * @param array $mappings Associative array defining meta keys and values for comparison.
		 */
		public function set_meta_operator_compare_mappings( array $mappings ): void {
			$this->meta_operator_compare_mapping = $mappings;
		}

		/**
		 * Adds additional mappings for operator-based meta value comparisons.
		 * Checks for duplicates and structural validity before adding.
		 *
		 * @param array $mappings Associative array of new mappings to add.
		 *
		 * @throws InvalidArgumentException If the key already exists or if the mapping is incorrect.
		 */
		public function add_meta_operator_compare_mappings( array $mappings ): void {
			foreach ( $mappings as $key => $info ) {
				if ( ! isset( $info['meta_key'], $info['meta_value'] ) || isset( $this->meta_operator_compare_mapping[ $key ] ) ) {
					throw new InvalidArgumentException( "Invalid structure or duplicate key '{$key}' for operator comparison mapping." );
				}
				$this->meta_operator_compare_mapping[ $key ] = $info;
			}
		}

		/**
		 * Set dynamic value mappings for specific product attributes.
		 *
		 * @param array $mappings Associative array defining dynamic value mappings.
		 */
		public function set_dynamic_value_mappings( array $mappings ): void {
			$this->meta_dynamic_value_compare_mapping = $mappings;
		}

		/**
		 * Adds dynamic value mappings to the existing mappings.
		 * Ensures that no duplicate keys are added to maintain the integrity of mappings.
		 *
		 * @param array $mappings Associative array of new dynamic value mappings to add.
		 *
		 * @throws InvalidArgumentException If a key already exists in the current mappings.
		 */
		public function add_dynamic_value_mappings( array $mappings ): void {
			foreach ( $mappings as $key => $value ) {
				if ( isset( $this->meta_dynamic_value_compare_mapping[ $key ] ) ) {
					throw new InvalidArgumentException( "Dynamic value key '{$key}' already exists." );
				}
				$this->meta_dynamic_value_compare_mapping[ $key ] = $value;
			}
		}

		/**
		 * Set custom order by mappings.
		 *
		 * Defines how sorting criteria are mapped to database query parameters,
		 * allowing for flexible ordering of query results based on product attributes.
		 *
		 * @param array $mappings Associative array mapping sorting criteria to query parameters.
		 */
		public function set_orderby_mappings( array $mappings ): void {
			$this->orderby_mapping = $mappings;
		}

		/**
		 * Adds a single orderby mapping to the existing orderby mappings.
		 * This method ensures that no duplicate keys are added, maintaining the integrity
		 * of sorting options.
		 *
		 * @param string $key     Key representing the field to sort by.
		 * @param array  $details Associative array detailing the sorting parameters.
		 *
		 * @throws InvalidArgumentException If a key already exists in the orderby mappings.
		 */
		public function add_orderby_mapping( string $key, array $details ): void {
			if ( ! isset( $this->orderby_mapping[ $key ] ) ) {
				$this->orderby_mapping[ $key ] = $details;
			} else {
				throw new InvalidArgumentException( "Orderby key '{$key}' already exists." );
			}
		}

		/**
		 * Update query arguments.
		 *
		 * Allows for the dynamic adjustment of query parameters, facilitating custom queries
		 * based on changing requirements.
		 *
		 * @param array $args Custom arguments to override or extend existing query parameters.
		 */
		public function set_query_args( array $args ): void {
			$this->query_args = wp_parse_args( $args, $this->query_args );
		}

		/** Results *******************************************************************/

		/**
		 * Get EDD products based on the provided query arguments.
		 *
		 * @param bool $use_cache Whether to use caching for the query results. Default true.
		 *
		 * @return mixed Array of EDD products based on the 'return' argument.
		 */
		public function get_results( bool $use_cache = true ) {
			// Generate a unique transient name based on the arguments for caching.
			$transient_name = 'edd_products_' . md5( serialize( $this->query_args ) );

			// Try to get the products from the transient cache if use_cache is true.
			$posts = $use_cache ? get_transient( $transient_name ) : false;

			if ( false === $posts ) {
				$query_args = [
					'post_type'        => 'download',
					'order'            => $this->query_args['order'],
					'posts_per_page'   => $this->query_args['number'],
					'post_status'      => $this->query_args['status'] ?? 'publish',
					'suppress_filters' => $this->query_args['suppress_filters'] ?? false
				];

				// Handle pagination.
				if ( $this->query_args['paginate'] ) {
					$query_args['offset']         = $this->query_args['offset'];
					$query_args['posts_per_page'] = $this->query_args['limit'];
				}

				// Include and exclude specific posts
				if ( ! empty( $this->query_args['include'] ) ) {
					$query_args['include'] = $this->query_args['include'];
				}
				if ( ! empty( $this->query_args['exclude'] ) ) {
					$query_args['exclude'] = $this->query_args['exclude'];
				}

				// Orderby handling
				$orderby = $this->prepare_orderby();
				if ( isset( $orderby['meta_key'] ) ) {
					$query_args['meta_key'] = $orderby['meta_key'];
					$query_args['orderby']  = $orderby['orderby'];
				} else {
					$query_args['orderby'] = $orderby;
				}

				// Prepare dynamic meta queries from methods
				$meta_query = $this->prepare_meta_queries();
				if ( ! empty( $meta_query ) ) {
					$query_args['meta_query'] = $meta_query;
				}

				// Handle taxonomy queries
				$tax_query = $this->prepare_taxonomy_queries();
				if ( ! empty( $tax_query ) ) {
					$query_args['tax_query'] = $tax_query;
				}

				// Add the date query if applicable
				$date_query = $this->prepare_date_query();
				if ( ! empty( $date_query ) ) {
					$query_args['date_query'] = $date_query['date_query'];
				}

				// Debug output if needed
				if ( $this->debug ) {
					echo '<pre>';
					var_dump( $query_args );
					echo '</pre>';
				}

				// Fetch posts based on constructed query
				$posts = get_posts( $query_args );

				// Set the products into the transient cache if caching is enabled
				if ( $use_cache ) {
					set_transient( $transient_name, $posts, $this->transient_length );
				}
			}

			$fields = strtolower( $this->query_args['fields'] );

			// Handle the return type.
			if ( 'objects' === $fields ) {
				return array_map( function ( $post ) {
					return new EDD_Download( $post->ID );
				}, $posts );
			} elseif ( 'ids' === $fields ) {
				return wp_list_pluck( $posts, 'ID' );
			}

			return $posts;
		}

		/** Prepare Queries ***********************************************************/

		/**
		 * Prepares a date query for WP_Query based on specified date parameters.
		 * This method constructs an array for the date_query parameter of WP_Query, allowing for complex date-based queries.
		 * It supports setting conditions for dates after a specific date, before a specific date, or on an exact date.
		 *
		 * @return array An associative array containing the 'date_query' parameter for WP_Query if any date conditions are specified, otherwise an empty array.
		 *
		 * Usage:
		 * - 'date_after': Specify a date to fetch posts after this date. Supports 'YYYY-MM-DD HH:MM:SS' format or relative formats.
		 * - 'date_before': Specify a date to fetch posts before this date. Also supports 'YYYY-MM-DD HH:MM:SS' format or relative formats.
		 * - 'date': Fetch posts on the exact year, month, and day specified.
		 *
		 * The 'inclusive' key for 'date_after' and 'date_before' controls whether the specified day is included in the query.
		 * Setting 'inclusive' to true includes the border date in the query results.
		 */
		private function prepare_date_query(): array {
			$date_query = [];

			// Check if date parameters are set and add them to the date_query array
			if ( ! empty( $this->query_args['date_after'] ) ) {
				$date_query[] = [
					'after'     => $this->query_args['date_after'], // 'YYYY-MM-DD HH:MM:SS' format or relative formats
					'inclusive' => true
				];
			}

			if ( ! empty( $this->query_args['date_before'] ) ) {
				$date_query[] = [
					'before'    => $this->query_args['date_before'],
					'inclusive' => false
				];
			}

			// You can add exact date matches or other conditions
			if ( ! empty( $this->query_args['date'] ) ) {
				$date_query[] = [
					'year'  => date( 'Y', strtotime( $this->query_args['date'] ) ),
					'month' => date( 'm', strtotime( $this->query_args['date'] ) ),
					'day'   => date( 'd', strtotime( $this->query_args['date'] ) )
				];
			}

			return ! empty( $date_query ) ? [ 'date_query' => $date_query ] : [];
		}

		/**
		 * Prepares taxonomy queries based on the taxonomy mappings and query arguments.
		 * This method checks for both standard taxonomy queries and those with specific
		 * modifiers like `__in` and `__not_in`.
		 *
		 * @return array Prepared taxonomy queries for inclusion in a WP_Query.
		 */
		private function prepare_taxonomy_queries(): array {
			if ( empty( $this->taxonomy_mapping ) ) {
				return [];
			}

			$tax_query = [];

			// Check for standard taxonomy and modified keys like category__in
			foreach ( $this->query_args as $key => $value ) {
				if ( strpos( $key, '__' ) !== false ) {
					list( $base, $modifier ) = explode( '__', $key );
					$base           = strtolower( $base );
					$taxonomy       = $this->taxonomy_mapping[ $base ] ?? null;
					$modifier_upper = $this->normalize_modifier( $modifier );
					if ( $taxonomy && $this->is_valid_tax_operator( $modifier_upper ) ) {
						$tax_query[] = $this->prepare_taxonomy_query( $value, $taxonomy, $modifier_upper );
					}
				} elseif ( isset( $this->taxonomy_mapping[ $key ] ) && ! empty( $this->taxonomy_mapping[ $key ] ) ) {
					$taxonomy    = $this->taxonomy_mapping[ $key ];
					$tax_query[] = $this->prepare_taxonomy_query( $value, $taxonomy );
				}
			}

			// Filter out any NULL entries from $tax_query
			$tax_query = array_filter( $tax_query );

			// Add relation if necessary
			if ( count( $tax_query ) > 1 ) {
				array_unshift( $tax_query, [ 'relation' => $this->prepare_relation( 'tax_relation', 'OR' ) ] );
			}

			return $tax_query;
		}

		/**
		 * Prepares and merges all meta query conditions based on registered callbacks.
		 *
		 * This method iterates through the $meta_query_callbacks array, checking if each callback
		 * refers to a class method or a global function. It first attempts to call it as a class
		 * method. If the method doesn't exist in the class, it falls back to checking for a global
		 * function. This allows for flexible callback definitions within the class.
		 *
		 * @return array An array containing all prepared meta query conditions, ready to be used in a query.
		 */
		public function prepare_meta_queries(): array {

			$meta_query = [];

			foreach ( $this->meta_query_callbacks as $callback ) {
				if ( method_exists( $this, $callback ) ) {
					$result = call_user_func( [ $this, $callback ] );
				} elseif ( function_exists( $callback ) ) {
					$result = call_user_func( $callback );
				} else {
					continue; // Skip to the next iteration.
				}

				if ( ! empty( $result ) ) {
					$meta_query[] = $result;
				}
			}

			// Filter out any NULL entries from $tax_query
			$meta_query = array_filter( $meta_query );

			// Add relation if necessary
			if ( count( $meta_query ) > 1 ) {
				array_unshift( $meta_query, [ 'relation' => $this->prepare_relation( 'meta_relation' ) ] );
			}

			return $meta_query;
		}

		/** Meta Query Callbacks ******************************************************/

		/**
		 * Prepares user-provided meta queries ensuring they are correctly formatted.
		 * This method should take the raw user meta queries and ensure they meet the WP_Query standards.
		 *
		 * @return array Formatted user-provided meta query array.
		 */
		protected function prepare_user_meta_queries(): array {
			return $this->query_args['meta_query'] ?? [];
		}

		/**
		 * Prepares meta queries for checking the existence of meta keys based on the defined mappings.
		 * Iterates through the meta_exist_mapping array, constructs a meta query for each mapping
		 * that checks for the existence (or non-existence) of a meta key.
		 *
		 * @return array An array of meta query conditions for existence checks, ready for inclusion in WP_Query.
		 */
		protected function prepare_meta_exist_queries(): array {
			if ( ! $this->meta_exist_mapping ) {
				return [];
			}

			// Initialize an array to hold the meta_query conditions.
			$meta_query = [];

			// Loop through the mapping to prepare each meta query.
			foreach ( $this->meta_exist_mapping as $query_key => $meta_key ) {
				$query = $this->prepare_meta_exists_query( $query_key, $meta_key );
				if ( $query !== null ) {
					$meta_query[] = $query;
				}
			}

			// Return the assembled meta_query array.
			return $meta_query;
		}

		/**
		 * Prepares numeric meta queries based on defined mappings and query arguments.
		 * Constructs meta queries for numeric comparisons (e.g., greater than, less than) using the
		 * mapped meta keys and comparison operators provided in the query arguments.
		 *
		 * @return array An array of numeric comparison meta query conditions.
		 */
		protected function prepare_meta_numeric_queries(): array {
			if ( ! $this->meta_numeric_mapping ) {
				return [];
			}

			// Initialize an array to hold the meta_query conditions.
			$meta_query = [];

			// Loop through the mapping to prepare each numeric meta query.
			foreach ( $this->meta_numeric_mapping as $key => $info ) {
				$query = $this->prepare_meta_numeric_query(
					$key,
					$info['meta_key'],
					$info['compare_key'],
					$info['type']
				);
				if ( $query !== null ) {
					$meta_query[] = $query;
				}
			}

			// Return the assembled meta_query array.
			return $meta_query;
		}

		/**
		 * Prepares meta queries for comparing meta values to specific values based on defined mappings.
		 * Useful for boolean checks or specific value matches. Constructs a meta query for each condition
		 * based on whether a meta value should be equal to a specific 'true' or 'false' value.
		 *
		 * @return array An array of meta query conditions for value comparison.
		 */
		protected function prepare_meta_value_compare_queries(): array {
			if ( ! $this->meta_value_compare_mapping ) {
				return [];
			}

			// Initialize an array to hold the meta_query conditions.
			$meta_query = [];

			// Loop through the mapping to prepare each numeric meta query.
			foreach ( $this->meta_value_compare_mapping as $key => $info ) {
				$query = $this->prepare_meta_value_compare_query(
					$key,
					$info['meta_key'],
					$info['true'],
					$info['false']
				);
				if ( $query !== null ) {
					$meta_query[] = $query;
				}
			}

			// Return the assembled meta_query array.
			return $meta_query;
		}

		/**
		 * Prepares meta queries that require specific comparison operators based on defined mappings.
		 * Each mapping defines a meta key and a specific value to compare against, using an operator like '=', '!=', etc.
		 * This allows for flexible and complex meta value comparisons.
		 *
		 * @return array An array of meta query conditions that utilize custom comparison operators.
		 */
		protected function prepare_meta_operator_compare_queries(): array {
			if ( ! $this->meta_operator_compare_mapping ) {
				return [];
			}

			// Initialize an array to hold the meta_query conditions.
			$meta_query = [];

			// Loop through the mapping to prepare each numeric meta query.
			foreach ( $this->meta_operator_compare_mapping as $key => $info ) {
				$query = $this->prepare_operator_compare_meta_query(
					$key,
					$info['meta_key'],
					$info['meta_value']
				);
				if ( $query !== null ) {
					$meta_query[] = $query;
				}
			}

			// Return the assembled meta_query array.
			return $meta_query;
		}

		/**
		 * Prepares meta queries for dynamic value comparisons based on defined mappings.
		 *
		 * @return array An array of meta query conditions for dynamic value comparisons.
		 */
		protected function prepare_meta_dynamic_compare_queries(): array {
			$meta_query = [];

			foreach ( $this->meta_dynamic_value_compare_mapping as $key => $meta_key ) {
				if ( ! empty( $this->query_args[ $key ] ) ) {
					$meta_query[] = [
						'key'     => $meta_key,
						'value'   => $this->query_args[ $key ],
						'compare' => '='
					];
				}
			}

			return $meta_query;
		}

		/** Prepare Taxonomy **********************************************************/

		/**
		 * Prepares an individual taxonomy query.
		 *
		 * @param mixed  $terms    Terms (IDs, slugs, or arrays thereof).
		 * @param string $taxonomy Taxonomy name.
		 * @param string $operator Optional. Defaults to 'IN'. Other values might be 'NOT IN', 'AND', etc.
		 *
		 * @return array|null The taxonomy query array or null if no valid terms are found.
		 */
		private function prepare_taxonomy_query( $terms, string $taxonomy, string $operator = 'IN' ): ?array {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return null;
			}

			$terms      = (array) $terms;
			$term_ids   = [];
			$term_slugs = [];

			foreach ( $terms as $term ) {
				if ( is_numeric( $term ) ) {
					$term_ids[] = intval( $term );
				} else {
					$term_slugs[] = $term;
				}
			}

			if ( empty( $term_ids ) && empty( $term_slugs ) ) {
				return null;
			}

			$query_parts = [];
			if ( ! empty( $term_ids ) ) {
				$query_parts[] = [
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_ids,
					'operator' => $operator
				];
			}
			if ( ! empty( $term_slugs ) ) {
				$query_parts[] = [
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $term_slugs,
					'operator' => $operator
				];
			}

			return count( $query_parts ) === 1 ? $query_parts[0] : $query_parts;
		}

		/** Prepare Meta **************************************************************/

		/**
		 * Prepares a component of the meta query based on a specific query argument.
		 *
		 * This method simplifies the addition of meta query conditions based on the presence
		 * of true/false/null values in the query arguments.
		 *
		 * @param string $arg_key  The key in the query_args array to check.
		 * @param string $meta_key The meta key to use in the meta query.
		 *
		 * @return array|null The meta query component or null if not applicable.
		 */
		protected function prepare_meta_exists_query( string $arg_key, string $meta_key ): ?array {
			if ( isset( $this->query_args[ $arg_key ] ) ) {
				$value = filter_var( $this->query_args[ $arg_key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( $value !== null ) {
					return [
						'key'     => $meta_key,
						'compare' => $value ? 'EXISTS' : 'NOT EXISTS',
					];
				}
			}

			return null;
		}

		/**
		 * Prepares a numeric component of the meta query based on a specific query argument.
		 *
		 * This method is tailored for numeric comparisons, allowing the specification of a comparison
		 * operator and handling various numeric types of the meta value.
		 *
		 * @param string $arg_key      The key in the query_args array for the numeric value.
		 * @param string $meta_key     The meta key to use in the meta query.
		 * @param string $operator_key The key in the query_args array for the comparison operator.
		 * @param string $type         The type of numeric value, e.g., 'DECIMAL', 'NUMERIC', 'SIGNED', 'UNSIGNED'.
		 *
		 * @return array|null The meta query component or null if the conditions are not met.
		 */
		protected function prepare_meta_numeric_query( string $arg_key, string $meta_key, string $operator_key, string $type ): ?array {
			$validated_type = $this->sanitize_numeric_type( $type );

			// Proceed only if the type is valid and the argument value is numeric.
			if ( $validated_type !== null && ! empty( $this->query_args[ $arg_key ] ) && is_numeric( $this->query_args[ $arg_key ] ) ) {
				$compare = $this->sanitize_operator( 'numeric', $this->query_args[ $operator_key ] );

				return [
					'key'     => $meta_key,
					'value'   => $this->query_args[ $arg_key ],
					'type'    => $validated_type,
					'compare' => $compare,
				];
			}

			return null;
		}

		/**
		 * Prepares a complex component of the meta query based on a specific query argument.
		 * This method allows for the specification of 'true' and 'false' values for the meta query.
		 * If one of the values is null, the method uses the non-null value directly.
		 *
		 * @param string $arg_key     The key in the query_args array to check.
		 * @param string $meta_key    The meta key to use in the meta query.
		 * @param mixed  $value_true  The value to use in the meta query if the argument is true or the single value if $value_false is null.
		 * @param mixed  $value_false The value to use in the meta query if the argument is false or null to use $value_true directly.
		 * @param string $compare     The comparison operator to use in the meta query.
		 *
		 * @return array|null The meta query component or null if the argument key is not set.
		 */
		protected function prepare_meta_value_compare_query( string $arg_key, string $meta_key, $value_true, $value_false, string $compare = '=' ): ?array {
			if ( isset( $this->query_args[ $arg_key ] ) ) {
				$value = filter_var( $this->query_args[ $arg_key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( $value !== null ) {
					return [
						'key'     => $meta_key,
						'value'   => $value ? $value_true : $value_false,
						'compare' => $compare,
					];
				}
			}

			return null;
		}

		/**
		 * Prepares a meta query component based on a specific query argument with enhanced comparison logic.
		 * This method constructs a meta query part using a specified argument to determine the comparison operator.
		 * If the argument evaluates to true, it uses $value_true as the comparison operator; if false, $value_false is used.
		 * The method is designed to provide flexibility in determining the comparison approach based on boolean logic.
		 *
		 * @param string $arg_key       The key in the query_args array to evaluate.
		 * @param string $meta_key      The meta key to use in the meta query for matching records.
		 * @param mixed  $meta_value    The value to compare against the records' meta values.
		 * @param string $compare_true  The comparison operator to use if the argument's value evaluates to true.
		 * @param string $compare_false The comparison operator to use if the argument's value evaluates to false or is null.
		 *
		 * @return array|null A meta query component tailored based on the argument's evaluation, or null if the argument key is not set or does not yield a meaningful comparison.
		 */
		protected function prepare_operator_compare_meta_query( string $arg_key, string $meta_key, $meta_value, string $compare_true = '=', string $compare_false = '!=' ): ?array {
			if ( isset( $this->query_args[ $arg_key ] ) && $this->is_valid_operator( $compare_true ) && $this->is_valid_operator( $compare_false ) ) {
				$value = filter_var( $this->query_args[ $arg_key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( $value !== null ) {
					return [
						'key'     => $meta_key,
						'value'   => $meta_value,
						'compare' => $value ? $compare_true : $compare_false
					];
				}
			}

			return null;
		}

		/** Prepare Orderby ***********************************************************/

		/**
		 * Prepares the orderby parameter for a WordPress query.
		 *
		 * This method maps a given orderby parameter to the corresponding WordPress query values,
		 * allowing for flexible ordering of query results based on predefined criteria.
		 *
		 * @return mixed The mapped orderby parameter, which can be a string or an associative array
		 *               specifying the orderby and meta_key for WordPress query.
		 */
		private function prepare_orderby() {
			$orderby = $this->query_args['orderby'] ?? 'default';

			return $this->orderby_mapping[ $orderby ] ?? 'post_date'; // Default to date if not specified or not found
		}

		/** Validation ****************************************************************/

		/**
		 * Checks if the provided operator is valid for comparisons.
		 *
		 * @param string $operator The comparison operator to validate.
		 *
		 * @return bool True if the operator is valid, false otherwise.
		 */
		private function is_valid_operator( string $operator ): bool {
			$valid_operators = self::NUMERIC_OPERATORS + self::STRING_OPERATORS;

			return in_array( $operator, $valid_operators, true );
		}

		/**
		 * Checks if the provided operator is valid for comparisons.
		 *
		 * @param string $operator The comparison operator to validate.
		 *
		 * @return bool True if the operator is valid, false otherwise.
		 */
		protected function is_valid_tax_operator( string $operator ): bool {
			return in_array( $operator, self::VALID_TAX_OPERATORS );
		}

		/** Sanitization **************************************************************/

		/**
		 * Validates the comparison operator based on the specified comparison type.
		 *
		 * This method checks if the provided operator is valid for the specified type of comparison
		 * (numeric or string) and returns a default operator if it's invalid. This ensures that only
		 * appropriate operators are used for different types of field comparisons.
		 *
		 * @param string $type     The type of comparison ('numeric' or 'string').
		 * @param string $operator The operator to validate.
		 *
		 * @return string The validated operator or a default operator if invalid.
		 */
		private function sanitize_operator( string $type, string $operator ): string {
			$valid_operators = ( $type === 'numeric' ) ? self::NUMERIC_OPERATORS : self::STRING_OPERATORS;

			return in_array( $operator, $valid_operators, true ) ? $operator : '=';
		}

		/**
		 * Validates the type of the numeric value for a meta query.
		 *
		 * @param string $type The type to validate.
		 *
		 * @return string|null Returns the validated type or null if the type is not supported.
		 */
		protected function sanitize_numeric_type( string $type ): ?string {
			if ( in_array( $type, self::SUPPORTED_NUMERIC_TYPES, true ) ) {
				return $type;
			}

			return null;
		}

		/** Helper ********************************************************************/

		/**
		 * Normalizes a taxonomy query modifier by replacing underscores with spaces
		 * and converting to uppercase to match against valid taxonomy operators.
		 *
		 * @param string $modifier The raw modifier from the query argument key.
		 *
		 * @return string The normalized modifier suitable for comparison against valid operators.
		 */
		protected function normalize_modifier( string $modifier ): string {
			return strtoupper( str_replace( '_', ' ', $modifier ) );
		}

		/**
		 * Prepares and validates the relation type for a specific key in the query arguments.
		 * It ensures that the specified relation is either 'AND' or 'OR'. If the relation type is not specified
		 * or is invalid, it defaults to the value provided by the 'default' parameter, which is 'AND' unless otherwise specified.
		 *
		 * @param string $key     The key in the query_args array to check for the relation type.
		 * @param string $default The default relation type to use if none is specified or if the specified type is invalid. Defaults to 'AND'.
		 *
		 * @return string Validated relation type ('AND' or 'OR').
		 */
		protected function prepare_relation( string $key, string $default = 'AND' ): string {
			if ( isset( $this->query_args[ $key ] ) && in_array( $this->query_args[ $key ], self::VALID_RELATIONS, true ) ) {
				return $this->query_args[ $key ];
			}

			return $default;
		}

	}

endif;