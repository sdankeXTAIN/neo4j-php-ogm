# GraphAware Neo4j PHP OGM

## Object Graph Mapper for Neo4j in PHP

[![Build Status](https://travis-ci.org/autoprotect-group/neo4j-php-ogm.svg?branch=master)](https://travis-ci.org/autoprotect-group/neo4j-php-ogm)
[![Latest Stable Version](https://poser.pugx.org/autoprotect-group/neo4j-php-ogm/v/stable.svg)](https://packagist.org/packages/autoprotect-group/neo4j-php-ogm)
[![Latest Unstable Version](https://poser.pugx.org/autoprotect-group/neo4j-php-ogm/v/unstable)](https://packagist.org/packages/autoprotect-group/neo4j-php-ogm)
[![Total Downloads](https://poser.pugx.org/autoprotect-group/neo4j-php-ogm/downloads)](https://packagist.org/packages/autoprotect-group/neo4j-php-ogm)
[![License](https://poser.pugx.org/autoprotect-group/neo4j-php-ogm/license)](https://packagist.org/packages/autoprotect-group/neo4j-php-ogm)

**Current Release** : `^2.0`

## Installation

for this version in composer.json

```cli
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/sdankeXTAIN/neo4j-php-ogm.git"
        }
    ],

    "require": {
        "autoprotect-group/neo4j-php-ogm": "2.x-dev as 2.1.6"
    }
```

Install with composer

```cli
composer require autoprotect-group/neo4j-php-ogm
```

## Documentation

The new documentation is available on [ReadTheDocs](http://neo4j-php-ogm.readthedocs.io/en/latest/).

Some parts from the [old documentation](docs/reference/01-intro.md) might be still missing.

- <b>Important changes outside of documentation:</b>
  - for one to many or many to many relations in entities graphaware collections will cause an error when using them
    - the class LazyCollection has to be used and initialized instead
    - example for using in entities:
    - ```
      /**
       * @OGM\Relationship(type="CONNECTIONNAME", direction="OUTGOING", collection=true, mappedBy="otherEntitiesProperty", targetEntity="TargetEntityClass")
       */
      protected ?LazyCollection $propertyName;

      public function __construct()
      {
         $this->propertyName = new LazyCollection(null, null, null);
      }
      ```

## Getting Help

For questions, please open a new thread on [StackOverflow](https://stackoverflow.com) with the `graphaware`, `neo4j` and `neo4j-php-ogm` tags.

For issues, please raise a Github issue in the repository.

## License

The library is released under the MIT License, refer to the LICENSE file bundled with this package.
