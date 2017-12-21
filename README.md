[![Build Status](https://travis-ci.org/grasmash/expander.svg?branch=master)](https://travis-ci.org/grasmash/expander) [![Packagist](https://img.shields.io/packagist/v/grasmash/expander.svg)](https://packagist.org/packages/grasmash/expander)
[![Total Downloads](https://poser.pugx.org/grasmash/expander/downloads)](https://packagist.org/packages/grasmash/expander) [![Coverage Status](https://coveralls.io/repos/github/grasmash/expander/badge.svg?branch=master)](https://coveralls.io/github/grasmash/expander?branch=master)

This tool expands property references in PHP arrays. For example implementation, see Yaml Expander.

### Installation

    composer require grasmash/expander

### Example usage:

Property references use dot notation to indicate array keys, and must be wrapped in `${}`.

Expansion logic:

```php
<?php

$array = [
    'type' => 'book',
    'book' => [
        'title' => 'Dune',
        'author' => 'Frank Herbert',
        'copyright' => '${book.author} 1965',
        'protaganist' => '${characters.0.name}',
        'media' => [
            0 => 'hardcover',
        ],
        'nested-reference' => '${book.sequel}',
    ],
    'characters' => [
        0 => [
            'name' => 'Paul Atreides',
            'occupation' => 'Kwisatz Haderach',
            'aliases' => [
                0 => 'Usul',
                1 => 'Muad\'Dib',
                2 => 'The Preacher',
            ],
        ],
        1 => [
            'name' => 'Duncan Idaho',
            'occupation' => 'Swordmaster',
        ],
    ],
    'summary' => '${book.title} by ${book.author}',
    'publisher' => '${not.real.property}',
    'sequels' => '${book.sequel}, and others.',
    'available-products' => '${book.media.1}, ${book.media.0}',
    'product-name' => '${${type}.title}',
    'boolean-value' => true,
    'null-value' => NULL,
    'inline-array' => [
        0 => 'one',
        1 => 'two',
        2 => 'three',
    ],
    'expand-array' => '${inline-array}',
    'env-test' => '${env.test}',
];

// Parse an array, expanding internal property references.
$expanded = \Grasmash\Expander\Expander::expandArrayProperties($array);
print_r($expanded);

// Parse an array, expanding references using both internal and supplementary values.
$reference_properties = ['book' => ['publication-year' => 1965]];
$expanded = \Grasmash\Expander\Expander::expandArrayProperties($array, $reference_properties);
print_r($expanded);
````

Resultant array:

```php
<?php

array (
  'type' => 'book',
  'book' => 
  array (
    'title' => 'Dune',
    'author' => 'Frank Herbert',
    'copyright' => 'Frank Herbert 1965',
    'protaganist' => 'Paul Atreides',
    'media' => 
    array (
      0 => 'hardcover',
    ),
  ),
  'characters' => 
  array (
    0 => 
    array (
      'name' => 'Paul Atreides',
      'occupation' => 'Kwisatz Haderach',
      'aliases' => 
      array (
        0 => 'Usul',
        1 => 'Muad\'Dib',
        2 => 'The Preacher',
      ),
    ),
    1 => 
    array (
      'name' => 'Duncan Idaho',
      'occupation' => 'Swordmaster',
    ),
  ),
  'summary' => 'Dune by Frank Herbert',
  'product-name' => 'Dune',
);
```
