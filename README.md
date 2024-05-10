# Easy Digital Downloads Product Query Class

Enhance your Easy Digital Downloads plugin or theme with advanced querying capabilities. This PHP class allows you to perform intricate product queries using detailed parameters like categories, tags, price ranges, and custom meta data. It leverages WordPress's WP_Query to provide optimized, cacheable product listings.

## Features

- **Advanced Taxonomy Filtering**: Use categories and tags in various combinations to refine product searches.
- **Support for Meta Queries**: Complex queries on custom fields and EDD properties.
- **Boolean Flags**: Filter products based on boolean values such as whether a product is shippable or has variable pricing.
- **Transient Caching**: Improve performance by caching query results.
- **Debugging Mode**: Output the SQL query for analysis and debugging.
- 
## Minimum Requirements ##

* **PHP:** 7.4
* **WordPress:** 6.5.3
* **Easy Digital Downloads:** 3.2.12

## Installation ##

This is not a standalone plugin but a library to be included in your WordPress plugin or theme.

You can use Composer:

```bash
composer require arraypress/edd-product-query
```

```php
// Require the Composer autoloader to enable class autoloading.
require_once __DIR__ . '/vendor/autoload.php';

use function ArrayPress\Utils\EDD\get_downloads;
```
## Usage Examples

The following examples demonstrate how to use the `get_downloads` function to query EDD products with various criteria:

### Basic Usage

Retrieve products using simple taxonomy and property filters:

```php
// Use the helper function to get products with specific licensing options.
$products = get_downloads([
    'licensing' => true // Only products with licensing enabled
]);

// Retrieve all access products.
$products = get_downloads([
    'all_access' => true // Only products with all access enabled
]);

// Retrieve variable priced products.
$products = get_downloads([
    'variable' => true // Only products with variable pricing enabled
]);

// Retrieve multi-mode variable priced products.
$products = get_downloads([
    'multi' => true // Only products with multi-mode enabled
]);

// Retrieve shippable products.
$products = get_downloads([
    'shipping' => true // Only products with shipping enabled
]);

// Retrieve commissions products.
$products = get_downloads([
    'commissions' => true // Only products with commissions enabled
]);
```

### Taxonomy Filtering

The library supports all registered taxonomies and uses specific operators to refine the queries:

```php
// Query products within a single category.
$products = get_downloads([
    'category' => 'templates' // Products categorized under 'templates'
]);

// Query products belonging to multiple categories.
$products = get_downloads([
    'category' => [ 'templates', 'audio' ] // Products categorized under 'templates' or 'audio'
]);

// Exclude specific categories.
$products = get_downloads([
    'category__not_in' => [ 'freebies' ] // Products not in 'freebies' category
]);

// Products that must match all specified categories.
$products = get_downloads([
    'category__and' => [ 'templates', 'video' ] // Products must be in both 'templates' and 'video' categories
]);
```

### Numeric and Meta Field Queries

You can directly use numeric fields and comparisons without creating complex meta queries:

```php
// Query products priced above a certain amount using comparison operators.
$products = get_downloads([
    'price' => 20,
    'price_compare' => '>' // Products priced greater than 20
]);

// Fetch products with a rating above a specific value.
$products = get_downloads([
    'rating' => 4,
    'rating_compare' => '>=' // Products with a rating of 4 or more
]);
```

**Supported Numeric Comparison Operators:**
- `=` : Equal to
- `!=` : Not equal to
- `>` : Greater than
- `>=` : Greater than or equal to
- `<` : Less than
- `<=` : Less than or equal to
- `BETWEEN` : Between an array of two values
- `NOT BETWEEN` : Not between an array of two values

### Debugging

Enable debugging to view the constructed query arguments, aiding in development and troubleshooting:

```php
// Debugging a query to see the SQL statement.
$products = get_downloads([
    'debug' => true, // Enable debugging
    'category' => 'utilities' // Products in 'utilities' category
]);
```

## Contributions

Contributions to this library are highly appreciated. Raise issues on GitHub or submit pull requests for bug
fixes or new features. Share feedback and suggestions for improvements.

## License: GPLv2 or later

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.