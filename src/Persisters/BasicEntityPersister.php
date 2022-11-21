<?php

declare(strict_types=1);

/*
 * This file is part of the GraphAware Neo4j PHP OGM package.
 *
 * (c) GraphAware Ltd <info@graphaware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GraphAware\Neo4j\OGM\Persisters;

use Laudis\Neo4j\Databags\Statement;
use GraphAware\Neo4j\OGM\EntityManager;
use GraphAware\Neo4j\OGM\Metadata\NodeEntityMetadata;
use GraphAware\Neo4j\OGM\Util\DirectionUtils;

class BasicEntityPersister
{
    protected string $paramStyle;

    public function __construct(
        protected string $className,
        protected NodeEntityMetadata $classMetadata,
        protected EntityManager $entityManager
    ) {
        $this->paramStyle = $this->entityManager->isV4() ? '$%s' : '{%s}';
    }

    public function load(array $criteria, array $orderBy = null): ?object
    {
        $stmt = $this->getMatchCypher($criteria, $orderBy);
        $result = $this->entityManager->getDatabaseDriver()->run($stmt->getText(), $stmt->getParameters());

        if ($result->count() > 1) {
            throw new \LogicException(sprintf('Expected only 1 record, got %d', $result->count()));
        }

        $hydrator = $this->entityManager->getEntityHydrator($this->className);
        $entities = $hydrator->hydrateAll($result);

        return count($entities) === 1 ? $entities[0] : null;
    }

    public function loadOneById($id): ?object
    {
        $stmt = $this->getMatchOneByIdCypher($id);
        $result = $this->entityManager->getDatabaseDriver()->run($stmt->getText(), $stmt->getParameters());
        $hydrator = $this->entityManager->getEntityHydrator($this->className);
        $entities = $hydrator->hydrateAll($result);

        return count($entities) === 1 ? $entities[0] : null;
    }

    public function loadAll(array $criteria = [], array $orderBy = null, int $limit = null, int $offset = null): array
    {
        $stmt = $this->getMatchCypher($criteria, $orderBy, $limit, $offset);
        $result = $this->entityManager->getDatabaseDriver()->run($stmt->getText(), $stmt->getParameters());

        $hydrator = $this->entityManager->getEntityHydrator($this->className);

        return $hydrator->hydrateAll($result);
    }

    public function getSimpleRelationship($alias, $sourceEntity)
    {
        $stmt = $this->getSimpleRelationshipStatement($alias, $sourceEntity);
        $result = $this->entityManager->getDatabaseDriver()->run($stmt->getText(), $stmt->getParameters());
        $hydrator = $this->entityManager->getEntityHydrator($this->className);

        $hydrator->hydrateSimpleRelationship($alias, $result, $sourceEntity);
    }

    public function getSimpleRelationshipCollection($alias, $sourceEntity)
    {
        $stmt = $this->getSimpleRelationshipCollectionStatement($alias, $sourceEntity);
        $result = $this->entityManager->getDatabaseDriver()->run($stmt->getText(), $stmt->getParameters());
        $hydrator = $this->entityManager->getEntityHydrator($this->className);

        $hydrator->hydrateSimpleRelationshipCollection($alias, $result, $sourceEntity);
    }

    public function getRelationshipEntity($alias, $sourceEntity)
    {
        $stmt = $this->getRelationshipEntityStatement($alias, $sourceEntity);
        $result = $this->entityManager->getDatabaseDriver()->run($stmt->getText(), $stmt->getParameters());
        if ($result->count() > 1) {
            throw new \RuntimeException(sprintf('Expected 1 result, got %d', $result->count()));
        }
        $hydrator = $this->entityManager->getEntityHydrator($this->className);

        $hydrator->hydrateRelationshipEntity($alias, $result, $sourceEntity);
    }

    public function getRelationshipEntityCollection($alias, $sourceEntity)
    {
        $stmt = $this->getRelationshipEntityStatement($alias, $sourceEntity);
        $result = $this->entityManager->getDatabaseDriver()->run($stmt->getText(), $stmt->getParameters());
        $hydrator = $this->entityManager->getEntityHydrator($this->className);

        $hydrator->hydrateRelationshipEntity($alias, $result, $sourceEntity);
    }

    public function getCountForRelationship($alias, $sourceEntity)
    {
        $stmt = $this->getDegreeStatement($alias, $sourceEntity);
        $result = $this->entityManager->getDatabaseDriver()->run($stmt->getText(), $stmt->getParameters());

        return $result->first()->get($alias);
    }

    public function getMatchCypher(
        array $criteria = [],
        array $orderBy = null,
        int $limit = null,
        int $offset = null
    ): Statement {
        $identifier = $this->classMetadata->getEntityAlias();
        $cypher = sprintf('MATCH (%s:%s) ', $identifier, $this->classMetadata->getLabel());

        $filter_cursor = 0;
        $params = [];
        foreach ($criteria as $key => $criterion) {
            $key = (string) $key;
            $clause = $filter_cursor === 0 ? 'WHERE' : 'AND';
            $cypher .= sprintf("%s %s.%s = \$$key ", $clause, $identifier, $key);
            $params[$key] = $criterion;
            ++$filter_cursor;
        }

        $cypher .= sprintf('RETURN %s', $identifier);

        if (is_array($orderBy) && count($orderBy) > 0) {
            $cypher .= PHP_EOL;
            $i = 0;
            foreach ($orderBy as $property => $order) {
                $cypher .= $i === 0 ? 'ORDER BY ' : ', ';
                $cypher .= sprintf('%s.%s %s', $identifier, $property, $order);
                ++$i;
            }
        }

        if (is_int($offset) && is_int($limit)) {
            $cypher .= PHP_EOL;
            $cypher .= sprintf('SKIP %d', $offset);
        }

        if (is_int($limit)) {
            $cypher .= PHP_EOL;
            $cypher .= sprintf('LIMIT %d', $limit);
        }

        return Statement::create($cypher, $params);
    }

    private function getSimpleRelationshipStatement($alias, $sourceEntity): Statement
    {
        [$cypher, $targetAlias, $relationshipMeta, $sourceEntityId] =
            $this->prepareDateForStatement($alias, $sourceEntity);
        $cypher .= 'RETURN ' . $targetAlias;

        $params = ['id' => (int) $sourceEntityId];

        return Statement::create($cypher, $params);
    }

    private function getRelationshipEntityStatement($alias, $sourceEntity): Statement
    {
        $relationshipMeta = $this->classMetadata->getRelationship($alias);
        $relAlias = $relationshipMeta->getAlias();
        $targetMetadata = $this->entityManager->getClassMetadataFor($relationshipMeta->getRelationshipEntityClass());
        $targetAlias = $targetMetadata->getEntityAlias();
        $sourceEntityId = $this->classMetadata->getIdValue($sourceEntity);
        $relationshipType = $relationshipMeta->getType();

        $isIncoming = $relationshipMeta->getDirection() === DirectionUtils::INCOMING ? '<' : '';
        $isOutgoing = $relationshipMeta->getDirection() === DirectionUtils::OUTGOING ? '>' : '';

        $cypher = sprintf(
            "MATCH (n) WHERE id(n) = \$id MATCH (n)%s(%s) RETURN {target: %s(%s), re: %s} AS %s",
            sprintf('%s-[%s:`%s`]-%s', $isIncoming, $relAlias, $relationshipType, $isOutgoing),
            $targetAlias,
            $isIncoming ? 'startNode' : 'endNode',
            $relAlias,
            $relAlias,
            $relAlias
        );

        $params = ['id' => $sourceEntityId];

        return Statement::create($cypher, $params);
    }

    private function getSimpleRelationshipCollectionStatement($alias, $sourceEntity): Statement
    {
        [$cypher, $targetAlias, $relationshipMeta, $sourceEntityId] =
            $this->prepareDateForStatement($alias, $sourceEntity);
        $cypher .= 'RETURN ' . $targetAlias . ' AS ' . $targetAlias . ' ';

        if ($relationshipMeta->hasOrderBy()) {
            $cypher .= 'ORDER BY ' . $targetAlias . '.' . $relationshipMeta->getOrderByProperty() . ' '
                . $relationshipMeta->getOrder();
        }

        $params = ['id' => $sourceEntityId];

        return Statement::create($cypher, $params);
    }

    private function getMatchOneByIdCypher($id): Statement
    {
        $identifier = $this->classMetadata->getEntityAlias();
        $cypher = sprintf(
            "MATCH (%s:`%s`)  WHERE id(%s) = \$id RETURN %s",
            $identifier,
            $this->classMetadata->getLabel(),
            $identifier,
            $identifier
        );

        $params = ['id' => (int) $id];

        return Statement::create($cypher, $params);
    }

    private function getDegreeStatement($alias, $sourceEntity): Statement
    {
        $relationshipMeta = $this->classMetadata->getRelationship($alias);
        $targetClassLabel = '';
        if ($relationshipMeta->isRelationshipEntity() === false && $relationshipMeta->isTargetEntity() === true) {
            $targetMetadata = $this->entityManager->getClassMetadataFor($relationshipMeta->getTargetEntity());
            if ($targetMetadata->getLabel() != null) {
                $targetClassLabel = ':' . $targetMetadata->getLabel();
            }
        }
        $sourceEntityId = $this->classMetadata->getIdValue($sourceEntity);
        $relationshipType = $relationshipMeta->getType();

        $isIncoming = $relationshipMeta->getDirection() === DirectionUtils::INCOMING ? '<' : '';
        $isOutgoing = $relationshipMeta->getDirection() === DirectionUtils::OUTGOING ? '>' : '';

        $relPattern = sprintf('%s-[:`%s`]-%s', $isIncoming, $relationshipType, $isOutgoing);

        $cypher  = sprintf("MATCH (n) WHERE id(n) = \$id ");
        $cypher .= 'RETURN size([(n)' . $relPattern . '(' . $targetClassLabel . ') | n]) ';
        $cypher .= 'AS ' . $alias;

        return Statement::create($cypher, ['id' => $sourceEntityId]);
    }

    private function prepareDateForStatement($alias, $sourceEntity): array
    {
        $relationshipMeta = $this->classMetadata->getRelationship($alias);
        $targetMetadata = $this->entityManager->getClassMetadataFor($relationshipMeta->getTargetEntity());
        $targetAlias = $targetMetadata->getEntityAlias();

        $cypher = sprintf(
            "MATCH (n) WHERE id(n) = \$id MATCH (n)%s(%s:%s) ",
            sprintf(
                '%s-[%s:`%s`]-%s',
                $relationshipMeta->getDirection() === DirectionUtils::INCOMING ? '<' : '',
                $relationshipMeta->getAlias(),
                $relationshipMeta->getType(),
                $relationshipMeta->getDirection() === DirectionUtils::OUTGOING ? '>' : ''
            ),
            $targetAlias,
            $targetMetadata->getLabel()
        );

        return [
            $cypher,
            $targetAlias,
            $relationshipMeta,
            $this->classMetadata->getIdValue($sourceEntity)
        ];
    }
}
