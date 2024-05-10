<?php
/**
 * Helper function to easily fetch EDD products based on custom query parameters.
 *
 * This function allows for flexible and powerful querying of EDD products, supporting a wide range of query parameters
 * for sorting, filtering, and retrieving products. It uses the Product_Query class to handle the query construction
 * and execution. Caching can be utilized to optimize performance, and debugging can be enabled for development purposes.
 *
 * @package     ArrayPress/EDD-Product-Query
 * @copyright   Copyright (c) 2024, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\Utils\EDD;

use Exception;

if ( ! function_exists( __NAMESPACE__ . '\\get_downloads' ) ) {
	/**
	 * Fetches EDD products based on specified query parameters.
	 *
	 * @param array         $query_args     Array of query parameters used to fetch EDD products.
	 * @param bool          $use_cache      Whether to use caching for the query results. Defaults to true.
	 * @param callable|null $error_callback A callback function for error handling.
	 *
	 * @return array|null Array of EDD products or null on failure.
	 */
	function get_downloads( array $query_args, bool $use_cache = true, ?callable $error_callback = null ): ?array {
		try {
			$product_query = new Product_Query( $query_args );

			return $product_query->get_results( $use_cache );
		} catch ( Exception $e ) {
			if ( $error_callback && is_callable( $error_callback ) ) {
				call_user_func( $error_callback, $e );
			}

			return null;
		}
	}
}